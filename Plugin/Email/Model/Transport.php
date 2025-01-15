<?php

namespace Mailjet\Mailjet\Plugin\Email\Model;

use Magento\Framework\Exception\MailException;
use Magento\Store\Model\ScopeInterface;
use Mailjet\Mailjet\Helper\Data;
use Magento\Email\Model\Transport as ModelTransport;
use Laminas\Mail\Message;

class Transport
{
    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var \Mailjet\Mailjet\Model\Framework\Mail\TransportFactory
     */
    private $transportFactory;

    /**
     * @var Data
     */
    private $dataHelper;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param \Magento\Framework\App\Config\ScopeConfigInterface     $scopeConfig
     * @param \Mailjet\Mailjet\Model\Framework\Mail\TransportFactory $transportFactory
     * @param Data                                                   $dataHelper
     * @param \Magento\Store\Model\StoreManagerInterface             $storeManager
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Mailjet\Mailjet\Model\Framework\Mail\TransportFactory $transportFactory,
        Data $dataHelper,
        \Magento\Store\Model\StoreManagerInterface $storeManager
    ) {
        $this->scopeConfig      = $scopeConfig;
        $this->transportFactory = $transportFactory;
        $this->dataHelper       = $dataHelper;
        $this->storeManager     = $storeManager;
    }

    /**
     * Around Send Message.
     *
     * @param \Magento\Email\Model\Transport $subject
     * @param callable                       $proceed
     */
    public function aroundSendMessage(\Magento\Email\Model\Transport $subject, callable $proceed)
    {
        $storeId = $this->storeManager->getStore()->getStoreId();

        if ($this->dataHelper->getConfigValue(Data::CONFIG_PATH_ACCOUNT_SMTP_ACTIVE, $storeId)
            && $this->dataHelper->getConfigValue(Data::CONFIG_PATH_ACCOUNT_ACTIVE, $storeId)
        ) {
            $smtp = $this->transportFactory->create();
            $config = $this->dataHelper->getSmtpConfigs($storeId);
            $isSetReturnPath = $this->scopeConfig->getValue(
                ModelTransport::XML_PATH_SENDING_SET_RETURN_PATH,
                ScopeInterface::SCOPE_STORE
            );
            $returnPathValue = $this->scopeConfig->getValue(
                ModelTransport::XML_PATH_SENDING_RETURN_PATH_EMAIL,
                ScopeInterface::SCOPE_STORE
            );

            try {
                $messageObject = Message::fromString(
                    $subject->getMessage()->getRawMessage()
                )->setEncoding('utf-8');
                if (2 === $isSetReturnPath && $returnPathValue) {
                    $messageObject->setSender($returnPathValue);
                } elseif (1 === $isSetReturnPath && $messageObject->getFrom()->count()) {
                    $fromAddressList = $messageObject->getFrom();
                    $fromAddressList->rewind();
                    $messageObject->setSender($fromAddressList->current()->getEmail());
                }

                $smtp->sendSmtpMessage($messageObject, $config);
            } catch (\Exception $e) {
                throw new MailException(new \Magento\Framework\Phrase($e->getMessage()), $e);
            }
        } else {
            $proceed();
        }
    }
}
