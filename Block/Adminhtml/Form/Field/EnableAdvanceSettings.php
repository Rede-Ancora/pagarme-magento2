<?php

namespace Pagarme\Pagarme\Block\Adminhtml\Form\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use Pagarme\Pagarme\Gateway\Transaction\Base\Config\ConfigInterface;

class EnableAdvanceSettings extends \Magento\Config\Block\System\Config\Form\Field
{
    /**
     * @var ConfigInterface
     */
    protected $config;

    public function __construct(
        Context $context,
        ConfigInterface $config,
        array $data = [],
        ?SecureHtmlRenderer $secureRenderer = null)
    {
        parent::__construct($context, $data, $secureRenderer);
        $this->config = $config;
    }

    public function render(AbstractElement $element)
    {
        if (!empty($this->config->getAccountId())) {
            return "";
        }
        return parent::render($element);
    }
}
