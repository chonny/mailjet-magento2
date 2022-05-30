<?php

namespace Mailjet\Mailjet\Model\Api;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Encryption\Encryptor;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\View\Asset\Repository;
use Magento\Store\Model\StoreManagerInterface;
use Mailjet\Mailjet\Api\ConfigRepositoryInterface;
use Mailjet\Mailjet\Api\Data\ConfigInterface;
use Mailjet\Mailjet\Helper\MailjetAPI as MailjetAPI;
use Mailjet\Mailjet\Helper\Data as dataHelper;
use Mailjet\Mailjet\Helper\MailjetAPIFactory;

class Connection
{
    /**
     * @var MailjetAPIFactory
     */
    protected $mailjetAPIFactory;

    /**
     * @var Encryptor
     */
    protected $encryptor;

    /**
     * @var ConfigRepositoryInterface
     */
    protected $configRepository;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var dataHelper
     */
    protected $dataHelper;

    /**
     * @var Repository
     */
    protected $assetRepository;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var MailjetAPI[]
     */
    protected $connections = [];

    /**
     * Connection constructor.
     *
     * @param MailjetAPIFactory $mailjetAPIFactory
     * @param Encryptor $encryptor
     * @param ConfigRepositoryInterface $configRepository
     * @param StoreManagerInterface $storeManager
     * @param dataHelper $dataHelper
     * @param Repository $assetRepository
     * @param Filesystem $filesystem
     */
    public function __construct(
        MailjetAPIFactory         $mailjetAPIFactory,
        Encryptor                 $encryptor,
        ConfigRepositoryInterface $configRepository,
        StoreManagerInterface     $storeManager,
        dataHelper                $dataHelper,
        Repository                $assetRepository,
        Filesystem                $filesystem
    ) {
        $this->mailjetAPIFactory = $mailjetAPIFactory;
        $this->encryptor = $encryptor;
        $this->configRepository = $configRepository;
        $this->storeManager = $storeManager;
        $this->dataHelper = $dataHelper;
        $this->assetRepository = $assetRepository;
        $this->filesystem = $filesystem;
    }

    /**
     * Get Encryptor
     *
     * @return Encryptor
     */
    public function getEncryptor()
    {
        return $this->encryptor;
    }

    /**
     * Get Store Connection
     *
     * @param ConfigInterface|null $config
     * @param string|null $apiKey
     * @param string|null $secretKey
     * @return MailjetAPI|null
     * @throws NoSuchEntityException
     */
    public function getConnection($config = null, $apiKey = null, $secretKey = null)
    {
        if (!($apiKey && $secretKey)) {
            $store = $this->storeManager->getStore();

            if (!$config) {
                $config = $this->configRepository->getByStoreId($store->getId());
            }

            if ($config->getId()) {
                $apiKey = $config->getApiKey();
                $secretKey = $this->encryptor->decrypt($config->getSecretKey());
            } else {
                $apiKey = $this->dataHelper->getConfigValue(
                    dataHelper::CONFIG_PATH_ACCOUNT_API_KEY,
                    $store->getId()
                );
                $secretKey = $this->encryptor->decrypt(
                    $this->dataHelper->getConfigValue(dataHelper::CONFIG_PATH_ACCOUNT_SECRET_KEY, $store->getId())
                );
            }
        }

        if (!$apiKey || !$secretKey) {
            $apiKey = '';
            $secretKey = '';
        }

        if (empty($this->connections[hash('sha256', $apiKey . $secretKey)])) {
            $newConnection = $this->mailjetAPIFactory->create();
            $newConnection->setCredentials($apiKey, $secretKey);

            $this->connections[hash('sha256', $apiKey . $secretKey)] = $newConnection;

            return $newConnection;
        } else {
            return $this->connections[hash('sha256', $apiKey . $secretKey)];
        }
    }

    /**
     * Setup Events
     *
     * @param ConfigInterface|null $config
     * @return Void
     */
    public function setupEvents($config = null)
    {
        if (!empty($config)) {
            $configs = [$config];
        } else {
            $configs = $this->configRepository->getUniqueEventConfigs();
        }

        foreach ($configs as $config) {
            $connection = $this->getConnection($config);
            $webhooks = $connection->getWebhooks();
            $events = dataHelper::REST_API_EVENTS;

            foreach ($webhooks as $webhook) {
                if (in_array($webhook[MailjetAPI::EVENT_TYPE], $events)) {
                    $url = $this->dataHelper->getRestApiUrl($webhook[MailjetAPI::EVENT_TYPE]);
                    $status = $this->dataHelper->getEventStatus($config, $webhook[MailjetAPI::EVENT_TYPE]);

                    if (!$status) {
                        $connection->deleteWebhook($webhook[MailjetAPI::ID]);
                    } elseif ($webhook[MailjetAPI::URL] != $url) {
                        $data = [
                            'event_type' => $webhook[MailjetAPI::EVENT_TYPE],
                            'status' => MailjetAPI::WEBHOOK_STATUS['alive'],
                            'url' => $url,
                        ];

                        $connection->updateWebhook($data);
                    }

                    $events = array_diff($events, [$webhook[MailjetAPI::EVENT_TYPE]]);
                }
            }

            foreach ($events as $event) {
                $status = $this->dataHelper->getEventStatus($config, $event);

                if ($status) {
                    $data = [
                        'event_type' => $event,
                        'status' => MailjetAPI::WEBHOOK_STATUS['alive'],
                        'url' => $this->dataHelper->getRestApiUrl($event),
                    ];

                    $connection->createWebhook($data);
                }
            }
        }
    }

    /**
     * Setup Properties
     *
     * @param ConfigInterface|null $config
     * @return Void
     */
    public function setupProperties($config = null)
    {
        if (!empty($config)) {
            $configs = [$config];
        } else {
            $configs = $this->configRepository->getUniqueEcommerceConfigs();
        }

        foreach ($configs as $config) {
            if ($config->getEcommerceData()) {
                $connection = $this->getConnection($config);
                $mailjetProperties = $connection->getProperties();

                foreach (dataHelper::REST_API_CONTACT_PROPERTIES as $property) {
                    $hasProperty = false;

                    foreach ($mailjetProperties as $mailjetProperty) {
                        if ($mailjetProperty[MailjetAPI::NAME] == $property['name']) {
                            $hasProperty = true;
                            break;
                        }
                    }

                    if (!$hasProperty) {
                        $connection->createProperty($property);
                    }
                }
            }
        }
    }

    /**
     * Setup Segments
     *
     * @param ConfigInterface|null $config
     * @return Void
     */
    public function setupSegments($config = null)
    {
        if (!empty($config)) {
            $configs = [$config];
        } else {
            $configs = $this->configRepository->getUniqueEcommerceConfigs();
        }

        foreach ($configs as $config) {
            if ($config->getEcommerceData()) {
                $connection = $this->getConnection($config);
                $mailjetProperties = $connection->getSegments();

                foreach (dataHelper::REST_API_SEGMENTS as $segment) {
                    $hasSegment = false;

                    foreach ($mailjetProperties as $mailjetProperty) {
                        if ($mailjetProperty[MailjetAPI::EXPRESSION] == $segment['expression']) {
                            $hasSegment = true;
                            break;
                        }
                    }

                    if (!$hasSegment) {
                        $connection->createSegment($segment);
                    }
                }
            }
        }
    }

    /**
     * Setup Templates
     *
     * @param ConfigInterface|null $config
     * @param int|null $storeId
     * @return void
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function setupTemplates($config = null, $storeId = null)
    {
        if (!empty($config)) {
            $configs = [$config];
        } elseif (!empty($storeId)) {
            $config = $this->configRepository->getByStoreId($storeId);
            if ($config->getId()) {
                $configs = [$config];
            } else {
                $configs = [];
            }
        } else {
            $configs = $this->configRepository->getUniqueEcommerceConfigs();
        }

        foreach ($configs as $config) {
            $connection = $this->getConnection($config);

            foreach (dataHelper::REST_API_TEMPLATES as $template) {
                if (!$this->dataHelper->getConfigValue($template['config'], $config->getStoreId())
                    || !$connection->getTemplate(
                        $this->dataHelper->getConfigValue($template['config'], $config->getStoreId())
                    )) {
                    $newTemplateData = [
                        'author' => 'Magento 2 default templates',
                        'categories' => ['basic'],
                        'copyright' => '',
                        'description' => '',
                        'edit_mode' => MailjetAPI::TEMPLATE_EDIT_MODE['drag_and_drop_builder'],
                        'is_starred' => false,
                        'is_text_part_generation_enabled' => true,
                        'locale' => 'en_US',
                        'name' => $template['name'],
                        'owner_type' => MailjetAPI::TEMPLATE_OWNER_TYPE['apikey'],
                        'presets' => '',
                        'purposes' => [$template['purpose']],
                    ];

                    $mjTemplate = $connection->createTemplate($newTemplateData);
                    $senders = $connection->getSenders();

                    if (!empty($mjTemplate[0]) && !empty($senders[0])) {
                        $mjTemplate = $mjTemplate[0];
                        $sender = $senders[0];

                        $mjml = $this->assetRepository->createAsset($template['json_file'], [])->getContent();
                        $html = $this->assetRepository->createAsset($template['html_file'], [])->getContent();
                        $headers = [
                            MailjetAPI::SENDER_NAME => !empty($sender[MailjetAPI::NAME]) ?
                                $sender[MailjetAPI::NAME] : $template['subject'],
                            MailjetAPI::SENDER_EMAIL => $sender[MailjetAPI::EMAIL],
                            MailjetAPI::FROM => $sender[MailjetAPI::EMAIL],
                            MailjetAPI::SUBJECT => $template['subject'],
                            MailjetAPI::REPLY_TO => $sender[MailjetAPI::EMAIL]
                        ];

                        $templateContents = [
                            'headers' => $headers,
                            'html' => $html,
                            'mjml_content' => json_decode($mjml, true),
                            'text' => '',
                        ];

                        $connection->addTemplateContent($mjTemplate[MailjetAPI::ID], $templateContents);

                        $this->dataHelper->saveConfigValue(
                            $template['config'],
                            $mjTemplate[MailjetAPI::ID],
                            $config->getStoreId()
                        );
                        $this->dataHelper->saveConfigValue(
                            $template['config'],
                            $mjTemplate[MailjetAPI::ID],
                            0,
                            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
                        );
                    }
                }
            }
        }
    }

    /**
     * Import templates to Mailjet
     *
     * @param Int $storeId
     * @return Void
     * @throws LocalizedException
     */
    public function importTemplates($storeId)
    {
        $config = $this->configRepository->getByStoreId($storeId);

        $connection = $this->getConnection($config);

        foreach (dataHelper::REST_API_TEMPLATES as $template) {
            $templateId = $this->dataHelper->getConfigValue($template['config'], $config->getStoreId());
            $mjTemplate = $connection->getTemplate($templateId);
            $update = true;

            if (!$templateId || !$mjTemplate) {
                $update = false;

                $newTemplateData = [
                    'author' => 'Magento 2 default templates',
                    'categories' => ['basic'],
                    'copyright' => '',
                    'description' => '',
                    'edit_mode' => MailjetAPI::TEMPLATE_EDIT_MODE['drag_and_drop_builder'],
                    'is_starred' => false,
                    'is_text_part_generation_enabled' => true,
                    'locale' => 'en_US',
                    'name' => $template['name'],
                    'owner_type' => MailjetAPI::TEMPLATE_OWNER_TYPE['apikey'],
                    'presets' => '',
                    'purposes' => [$template['purpose']],
                ];

                $mjTemplate = $connection->createTemplate($newTemplateData);
            }

            $senders = $connection->getSenders();

            if (!empty($mjTemplate[0]) && !empty($senders[0])) {
                $mjTemplate = $mjTemplate[0];
                $sender = $senders[0];

                $mjml = $this->assetRepository->createAsset($template['json_file'], [])->getContent();
                $html = $this->assetRepository->createAsset($template['html_file'], [])->getContent();
                $headers = [
                    MailjetAPI::SENDER_NAME => $sender[MailjetAPI::NAME],
                    MailjetAPI::SENDER_EMAIL => $sender[MailjetAPI::EMAIL],
                    MailjetAPI::FROM => $sender[MailjetAPI::EMAIL],
                    MailjetAPI::SUBJECT => $template['subject'],
                    MailjetAPI::REPLY_TO => $sender[MailjetAPI::EMAIL]
                ];

                $templateContents = [
                    'headers' => $headers,
                    'html' => $html,
                    'mjml_content' => json_decode($mjml, true),
                    'text' => '',
                ];

                if ($update) {
                    $connection->updateTemplateContent($mjTemplate[MailjetAPI::ID], $templateContents);
                } else {
                    $connection->addTemplateContent($mjTemplate[MailjetAPI::ID], $templateContents);
                }
            }
        }
    }

    /**
     * Save as default templates
     *
     * @param int $storeId
     * @return Void
     * @throws LocalizedException
     */
    public function saveAsDefaultTemplates($storeId)
    {
        $config = $this->configRepository->getByStoreId($storeId);

        $connection = $this->getConnection($config);
        $writeDir = $this->filesystem->getDirectoryWrite(DirectoryList::ROOT);

        foreach (dataHelper::REST_API_TEMPLATES as $template) {
            if ($this->dataHelper->getConfigValue($template['config'], $config->getStoreId())) {
                $content = $connection->getTemplateContent($this->dataHelper->getConfigValue(
                    $template['config'],
                    $config->getStoreId()
                ));

                if ($content) {
                    try {
                        $json_file_path = $this->assetRepository->createAsset($template['json_file'], [])
                            ->getSourceFile();
                    } catch (\Magento\Framework\View\Asset\File\NotFoundException $e) {
                        throw new LocalizedException(
                            new \Magento\Framework\Phrase('File missing: ' . $template['json_file'])
                        );
                    }

                    try {
                        $html_file_path = $this->assetRepository->createAsset($template['html_file'], [])
                            ->getSourceFile();
                    } catch (\Magento\Framework\View\Asset\File\NotFoundException $e) {
                        throw new LocalizedException(
                            new \Magento\Framework\Phrase('File missing: ' . $template['html_file'])
                        );
                    }

                    $writeDir->writeFile($json_file_path, json_encode($content[0][MailjetAPI::MJML_CONTENT]));
                    $writeDir->writeFile($html_file_path, $content[0][MailjetAPI::HTML_PART]);
                }
            }
        }
    }
}
