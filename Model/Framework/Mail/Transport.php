<?php

namespace Mailjet\Mailjet\Model\Framework\Mail;

use Magento\Framework\Exception\MailException;
use Magento\Framework\Mail\EmailMessageInterface;
use Magento\Framework\Phrase;
use Mailjet\Mailjet\Helper\Data;
use Laminas\Mail\Message;
use Laminas\Mail\Transport\Smtp;
use Laminas\Mail\Transport\SmtpOptions;

class Transport
{
    /**
     * @var Data
     */
    protected $dataHelper;

    /**
     * Transport model construct
     *
     * @param Data $dataHelper
     */
    public function __construct(
        Data $dataHelper
    ) {
        $this->dataHelper = $dataHelper;
    }

    /**
     * Send smtp message
     *
     * @param EmailMessageInterface|Message $message
     * @param array $config
     *
     * @return bool
     *
     * @throws MailException
     */
    public function sendSmtpMessage($message, $config)
    {
        if (!($message instanceof Message)) {
            $message = Message::fromString($message->getRawMessage());
        }

        //set config
        $options = new SmtpOptions(
            [
            // 'name' => '',
            'host' => $config['host'],
            'port' => $config['port'],
            ]
        );

        $options->setConnectionClass('login');

        $options->setConnectionConfig(
            [
                'username' => $config['username'],
                'password' => $config['password'],
                'ssl'      => $config['ssl'],
            ]
        );

        try {
            $transport = new Smtp();
            $transport->setOptions($options);
            $transport->send($message);

            return true;
        } catch (\Exception $e) {
            throw new MailException(
                new Phrase($e->getMessage()),
                $e
            );
        }
    }
}
