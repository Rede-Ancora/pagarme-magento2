<?php

/**
 * Class AbstractHelper
 *
 * @author      Open Source Team
 * @copyright   2021 Pagar.me (https://pagar.me)
 * @license     https://pagar.me Copyright
 *
 * @link        https://pagar.me
 */

namespace Pagarme\Pagarme\Helper\Marketplace;

use Magento\Framework\App\ObjectManager as MagentoObjectManager;
use Magento\Framework\Module\Manager as MagentoModuleManager;
use Magento\Framework\Exception\NotFoundException;
use Pagarme\Core\Kernel\Services\MoneyService;
use Pagarme\Core\Marketplace\Services\RecipientService;
use Pagarme\Pagarme\Helper\Marketplace\Handlers\SplitRemainderHandler;
use Pagarme\Pagarme\Helper\Marketplace\Handlers\ExtrasAndDiscountsHandler;
// use Webkul\Marketplace\Helper\Payment;
use RedeAncora\Marketplace\Helper\Payment;
use Pagarme\Pagarme\Concrete\Magento2CoreSetup;
use Psr\Log\LoggerInterface;

class WebkulHelper
{
    private const MODULE_MARKETPLACE_NAME = 'Webkul_Marketplace';

    /**
     * @var mixed
     */
    private $webkulPaymentHelper;

    /**
     * @var Payment
     */
    private $_marketplaceHelper;

    /**
     * @var array
     */
    private $_productIds = [];

    /**
     * @var MagentoObjectManager
     */
    private $objectManager;

    /**
     * @var RecipientService
     */
    private $recipientService;

    /**
     * @var bool
     */
    private $enabled = false;

    /**
     * @var SplitRemainderHandler
     */
    private $splitRemainderHandler;

    /**
     * @var ExtrasAndDiscountsHandler
     */
    private $extrasAndDiscountsHandler;

    /**
     * @var MoneyService
     */
    private $moneyService;

    public function __construct()
    {
        $moduleConfig = Magento2CoreSetup::getModuleConfiguration();
        $this->objectManager = MagentoObjectManager::getInstance();

        $marketplaceEnabled = $moduleConfig
            ->getMarketplaceConfig()
            ->isEnabled();

        if (!$marketplaceEnabled) {
            return;
        }

        // if ($this->isWebkulMarketplaceModuleDisabled()) {
        //     return;
        // }

        $this->splitRemainderHandler = new SplitRemainderHandler();
        $this->extrasAndDiscountsHandler = new ExtrasAndDiscountsHandler();
        // $this->webkulPaymentHelper = $this->objectManager->get(Payment::class);
        $this->_marketplaceHelper = $this->objectManager->get(Payment::class);
        $this->logger = $this->objectManager->get(LoggerInterface::class);

        $this->setEnabled(true);

        $this->recipientService = new RecipientService();
        $this->moneyService = new MoneyService();
    }

    private function isWebkulMarketplaceModuleDisabled()
    {
        $moduleManager = $this->objectManager->get(MagentoModuleManager::class);

        return !$moduleManager->isEnabled(self::MODULE_MARKETPLACE_NAME);
    }

    public function isEnabled()
    {
        return $this->enabled;
    }

    public function setEnabled($enabled)
    {
        $this->enabled = $enabled;
    }

    private function getSellerAndCommissions($itemPrice, $productId)
    {
        $sellerDetail = ['id' => $this->_productIds[$productId]['seller_id'], 'commission' => $this->_productIds[$productId]['commission']];
        
        $sellerId = $sellerDetail['id'];

        if (empty($sellerId)) {
            return [];
        }

        $marketplacePercentageCommission = $sellerDetail['commission'] / 100;
        $sellerPercentageCommission = 1 - $marketplacePercentageCommission;

        $marketplaceCommission =
            (float) number_format($itemPrice * $marketplacePercentageCommission, 2, '.', '');

        $sellerCommission =
            (float) number_format($itemPrice * $sellerPercentageCommission, 2, '.', '');

        try {
            $recipient = $this->recipientService->findRecipient(
                $sellerId
            );
        } catch (\Exception $exception) {
            throw new NotFoundException(__($exception->getMessage()));
        }

        return [
            "marketplaceCommission" => $marketplaceCommission,
            "commission" => $sellerCommission,
            "pagarmeId" => $recipient['pagarme_id'],
            "webkulSellerId" => $sellerId
        ];
    }

    private function getTotalPaid($corePlatformOrderDecorator)
    {
        $payments = $corePlatformOrderDecorator->getPaymentMethodCollection();
        $totalPaid = 0;

        foreach ($payments as $payment) {
            $totalPaid += $payment->getAmount();
        }

        return $totalPaid;
    }

    private function getProductTotal($items)
    {
        $total = 0;
        foreach ($items as $item) {
            $total += $this->moneyService->floatToCents(
                $item->getRowTotal()
            );
        }

        return $total;
    }

    private function addCommissionsToSplitData($sellerAndCommisions, &$splitData)
    {
        $sellerId = $sellerAndCommisions['webkulSellerId'];

        if (array_key_exists($sellerId, $splitData['sellers'])) {
            $splitData['sellers'][$sellerId]['commission']
                += $sellerAndCommisions['commission'];
            $splitData['marketplace']['totalCommission']
                += $sellerAndCommisions['marketplaceCommission'];

            return;
        }

        $splitData['sellers'][$sellerId] = $sellerAndCommisions;
        $splitData['marketplace']['totalCommission']
            += $sellerAndCommisions['marketplaceCommission'];
    }


    private function forceIntegerValues(&$splitData)
    {
        $splitData['marketplace']['totalCommission'] = intval(
            $splitData['marketplace']['totalCommission']
        );
        foreach ($splitData['sellers'] as $key => &$seller) {
            $seller['commission'] = intval($seller['commission']);
        }

        return $splitData;
    }

    private function handleExtrasAndDiscounts($platformOrderDecorator, &$splitData)
    {
        $items = $platformOrderDecorator->getPlatformOrder()->getAllItems();
        $totalPaid = $this->getTotalPaid($platformOrderDecorator);
        $productTotal = $this->getProductTotal($items);

        $extraOrDiscountTotal = $this
            ->extrasAndDiscountsHandler
            ->calculateExtraOrDiscount(
                $totalPaid,
                $productTotal
            );

        if (empty($extraOrDiscountTotal) && $extraOrDiscountTotal != 0) {
            return $splitData;
        }

        return $this->extrasAndDiscountsHandler->setExtraOrDiscountToResponsible(
            $extraOrDiscountTotal,
            $splitData
        );
    }

    public function getSplitDataFromOrder($corePlatformOrderDecorator)
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $platformOrder = $corePlatformOrderDecorator->getPlatformOrder();
        $orderItems = $platformOrder->getAllItems();
        $splitData['sellers'] = [];
        $splitData['marketplace']['totalCommission'] = 0;
        $totalPaidProductWithoutSeller = 0;

        $productIds = [];
        foreach ($orderItems as $product) {
            $productIds[] = $product->getProductId();
        }

        $output = $this->_marketplaceHelper->transformArr($productIds);

        $sellerComissionItemsPayload = [];
        $productItemsPayload = [];

        foreach ($orderItems as $orderItem) {

            $productInstance = $orderItem->getProduct();

            if ($productInstance->getTypeId() == 'simple') {
                $omnikId = $output[$productInstance->getId()]['omnik_id'];
                $sellerComissionItemsPayload[$output[$productInstance->getId()]['omnik_id']] = $this->_marketplaceHelper->getSellerComissionItemPayload($omnikId);
                $productItemsPayload[$output[$productInstance->getId()]['omnik_id']][] = $this->_marketplaceHelper->getProductItemPayload($orderItem);
            }
        }

        $sellerComissionPayload = $this->_marketplaceHelper->getSellerComissionPayload($sellerComissionItemsPayload, $productItemsPayload);
        $sellerComissionPayload = $this->_marketplaceHelper->setFreightData($sellerComissionPayload);
        $sellerComissionPayload = $this->_marketplaceHelper->sumTotalItems($sellerComissionPayload);        
        $commissionResponse = $this->_marketplaceHelper->getCommissions($sellerComissionPayload);
        $setCommissions = $this->_marketplaceHelper->setSellerCommission($commissionResponse, $output);

        $this->_productIds = $setCommissions;

        foreach ($orderItems as $item) {
            $productId = $item->getProductId();
            $itemPrice = $this->moneyService->floatToCents(
                $item->getRowTotal()
            );

            $sellerAndCommisions = $this->getSellerAndCommissions(
                $itemPrice,
                $productId
            );

            if (empty($sellerAndCommisions)) {
                $totalPaidProductWithoutSeller += $itemPrice;
                continue;
            }

            $this->addCommissionsToSplitData(
                $sellerAndCommisions,
                $splitData
            );

        }

        if (empty($splitData['sellers'])) {
            return null;
        }
        $splitData['marketplace']['totalCommission']
        += $totalPaidProductWithoutSeller;

        $splitData = $this->handleExtrasAndDiscounts(
            $corePlatformOrderDecorator,
            $splitData
        );

        return $this->forceIntegerValues($splitData);
    }
}
