<?php

namespace Drupal\tmgmt_crowdin\Plugin\tmgmt\Translator;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\tmgmt\ContinuousTranslatorInterface;
use Drupal\tmgmt\Entity\RemoteMapping;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\JobItemInterface;
use Drupal\tmgmt\TMGMTException;
use Drupal\tmgmt\Translator\AvailableResult;
use Drupal\tmgmt\TranslatorInterface;
use Drupal\tmgmt\TranslatorPluginBase;
use Drupal\tmgmt_crowdin\Plugin\tmgmt_file\Format\WebXML;
use Drupal\tmgmt_file\Format\FormatManager;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Crowdin translation plugin controller.
 *
 * @TranslatorPlugin(
 *   id = "crowdin",
 *   label = @Translation("Crowdin"),
 *   description = @Translation("Agile localization for tech companies"),
 *   logo = "icons/crowdin.svg",
 *   ui = "Drupal\tmgmt_crowdin\CrowdinTranslatorUi",
 * )
 */
class CrowdinTranslator extends TranslatorPluginBase implements ContainerFactoryPluginInterface, ContinuousTranslatorInterface
{
    use StringTranslationTrait;

    public const TOKEN_MASK = '**********';
    public const TOKEN_KEY = 'personal_token';

    public const FILE_TRANSLATED_EVENT = 'file.translated';
    public const FILE_APPROVED_EVENT = 'file.approved';

    public const FORMAT_FILE_NAME = 'Job_%d_JobItem_%d_%s_%s.xml';

    private const PRIMARY_PROJECT_PROTOCOL = 'https';
    private const PRIMARY_PROJECT_DOMAIN = 'api.crowdin.com/api/v2/';

    private const ROOT_DIRECTORY_NAME = 'Drupal Connector';

    /** @var ClientInterface $client */
    protected $client;

    /** @var FormatManager $formatManager */
    protected $formatManager;

    protected $supportedRemoteLanguages = [];

    public function __construct(
        ClientInterface $client,
        FormatManager $formatManager,
        array $configuration,
        string $pluginId,
        array $pluginDefinition
    ) {
        parent::__construct($configuration, $pluginId, $pluginDefinition);
        $this->client = $client;
        $this->formatManager = $formatManager;
    }

    public static function create(
        ContainerInterface $container,
        array $configuration,
        $plugin_id,
        $plugin_definition
    ): CrowdinTranslator {
        /** @var ClientInterface $client */
        $client = $container->get('http_client');
        /** @var FormatManager $formatManager */
        $formatManager = $container->get('plugin.manager.tmgmt_file.format');

        return new static($client, $formatManager, $configuration, $plugin_id, $plugin_definition);
    }

    public function defaultSettings(): array
    {
        $defaults = parent::defaultSettings();

        $defaults[self::TOKEN_KEY] = $this->getCrowdinData(self::TOKEN_KEY) ? self::TOKEN_MASK : '';
        $defaults['project_id'] = $this->getProjectId() ?? '';
        $defaults['domain'] = $this->getCrowdinData('domain') ?? '';

        $defaults['xliff_cdata'] = true;

        return $defaults;
    }

    public function getSupportedRemoteLanguages(TranslatorInterface $translator)
    {
        try {
            $supported_languages = $this->request($translator, 'languages', ['limit' => 500]);

            foreach ($supported_languages['data'] as $languageData) {
                $language = $languageData['data'];
                $this->supportedRemoteLanguages[$language['id']] = $language['name'];
            }
            return $this->supportedRemoteLanguages;
        } catch (\Exception $e) {
            return [];
        }
    }

    public function requestTranslation(JobInterface $job): void
    {
        $this->requestJobItemsTranslation($job->getItems());
        if (!$job->isRejected()) {
            $job->submitted('Job has been successfully submitted for translation.');
        }
    }

    public function checkAvailable(TranslatorInterface $translator): AvailableResult
    {
        if ($translator->getSetting(self::TOKEN_KEY)) {
            return AvailableResult::yes();
        }

        return AvailableResult::no(
            $this->t(
                '@translator is not available. Make sure it is properly <a href=:configured>configured</a>.',
                [
                    '@translator' => $translator->label(),
                    ':configured' => $translator->toUrl()->toString(),
                ]
            )
        );
    }

    /**
     * @param JobItemInterface[] $job_items
     * @throws \Drupal\Component\Plugin\Exception\PluginException
     */
    public function requestJobItemsTranslation(array $job_items): void
    {
        $first_item = reset($job_items);
        $job = $first_item->getJob();

        try {
            $this->formatManager->clearCachedDefinitions();
            $xliff = $this->formatManager->createInstance('webxml');

            $translator = $job->getTranslator();

            $project = $this->getProject($translator);
            $excluded_target_languages = array_diff(
                $project['data']['targetLanguageIds'],
                [$job->getRemoteTargetLanguage()]
            );

            $root_directory = $this->createRootDirectory($translator);
            $name = ($job->label() ?: 'Job') . ' (' . $job->id() . ')';
            $job_directory = $this->createJobDirectory($translator, $root_directory['data']['id'], $name);

            foreach ($job_items as $job_item) {
                $job_item_id = $job_item->id();
                $file_title = $job_item->label();

                $xliff_content = $xliff->export($job, ['tjiid' => ['value' => $job_item_id]]);

                $file_name = sprintf(
                    self::FORMAT_FILE_NAME,
                    $job->id(),
                    $job_item_id,
                    $job->getSourceLangcode(),
                    $job->getTargetLangcode()
                );

                $file = $this->addFile(
                    $translator,
                    $job_directory['data']['id'],
                    $file_name,
                    $file_title,
                    $xliff_content,
                    $excluded_target_languages
                );
                $job_item->active();

                $job_item->addRemoteMapping(
                    null,
                    $file['data']['id'],
                    ['remote_identifier_2' => $job_directory['data']['id']]
                );
            }

            $this->requestFileWebhook($translator);
        } catch (TMGMTException $e) {
            $job->rejected(
                'Job has been rejected with following error: @error',
                ['@error' => $e->getMessage()],
                'error'
            );
        }
    }

    public function abortTranslation(JobInterface $job): bool
    {
        $folder_id = null;
        $translator = $job->getTranslator();

        /** @var \Drupal\tmgmt\Entity\RemoteMapping $remote */
        foreach ($job->getRemoteMappings() as $remote) {
            $job_item = $remote->getJobItem();
            $folder_id = $remote->getRemoteIdentifier2();

            try {
                $job_item->setState(
                    JobItemInterface::STATE_ABORTED,
                    'The translation of <a href=":source_url">@source</a> has been aborted by the user.',
                    [
                        '@source' => $job_item->getSourceLabel(),
                        ':source_url' => $job_item->getSourceUrl()
                            ? $job_item->getSourceUrl()->toString()
                            : (string)$job_item->getJob()->toUrl()
                    ]
                );
            } catch (TMGMTException $e) {
                $job_item->addMessage(
                    'Failed to abort <a href=":source_url">@source</a> item. @error',
                    [
                        '@source' => $job_item->getSourceLabel(),
                        ':source_url' => $job_item->getSourceUrl() ? $job_item->getSourceUrl()->toString()
                            : (string)$job_item->getJob()->toUrl(),
                        '@error' => $e->getMessage(),
                    ],
                    'error'
                );
            }
        }

        if ($folder_id) {
            $this->removeJobDirectory($translator, $folder_id);
        }

        try {
            if ($job->isAbortable()) {
                $job->setState(JobInterface::STATE_ABORTED, 'Translation job has been aborted.');

                return true;
            }
        } catch (TMGMTException $e) {
            $job->addMessage('Failed to abort translation job. @error', ['@error' => $e->getMessage()], 'error');
        }

        return false;
    }

    public function setCrowdinData(string $key, ?string $value): void
    {
        \Drupal::configFactory()
            ->getEditable('crowdin.settings')
            ->set($key, $value)
            ->save();
    }

    public function getCrowdinData(string $key): ?string
    {
        return \Drupal::configFactory()->get('crowdin.settings')->get($key);
    }

    public function getProject(TranslatorInterface $translator): array
    {
        return $this->request($translator, "projects/{$this->getProjectId()}");
    }

    public function getUser(TranslatorInterface $translator): array
    {
        try {
            return $this->request($translator, 'user');
        } catch (TMGMTException $e) {
            return [];
        }
    }

    public function fetchTranslations(JobInterface $job): void
    {
        try {
            $target_language = $job->getRemoteTargetLanguage();
            $translator = $job->getTranslator();
            $project = $this->getProject($translator);

            /** @var \Drupal\tmgmt\Entity\RemoteMapping[] $remotes */
            $remotes = RemoteMapping::loadByLocalData($job->id());
            $translated = 0;

            // Check for if there are completed translations.
            foreach ($remotes as $remote) {
                $job_item = $remote->getJobItem();
                $file_id = $remote->getRemoteIdentifier1();

                if ($this->updateTranslation($job_item, $project, $file_id, $target_language)) {
                    $translated++;
                }
            }

            if (!$translated) {
                $this->messenger()->addWarning($this->t('No job item has been translated yet.'));
            } else {
                $untranslated = count($remotes) - $translated;
                if ($untranslated > 0) {
                    $job->addMessage(
                        'Fetched translations for @translated job items, @untranslated items are not translated yet.',
                        [
                            '@translated' => $translated,
                            '@untranslated' => $untranslated,
                        ]
                    );
                } else {
                    $job->addMessage('Fetched translations for @translated job items.', ['@translated' => $translated]);
                }
                tmgmt_write_request_messages($job);
            }
        } catch (TMGMTException $e) {
            $this->messenger()->addError($this->t(
                'Job has been rejected with following error: @error',
                ['@error' => $e->getMessage()]
            ));
        } catch (\Exception $e) {
            watchdog_exception('tmgmt_crowdin', $e);
        }
    }

    public function updateTranslation(
        JobItemInterface $job_item,
        array $project,
        int $file_id,
        string $target_language
    ): bool {
        $translator = $job_item->getTranslator();

        $file_progress = $this->getFileProgress($translator, $file_id);
        $target_language_progress = null;

        foreach ($file_progress['data'] as $language_progress) {
            if ($language_progress['data']['languageId'] === $target_language) {
                $target_language_progress = $language_progress['data'];
                break;
            }
        }

        if (!$target_language_progress) {
            throw new TMGMTException("Crowdin target language '{$target_language}' not exist");
        }

        if (
            ($project['data']['exportApprovedOnly'] && $target_language_progress['approvalProgress'] === 100)
            || (!$project['data']['exportApprovedOnly'] && $target_language_progress['translationProgress'] === 100)
        ) {
            $this->importTranslation($translator, $job_item, $file_id, $target_language);
            return true;
        }

        return false;
    }

    private function getApiUrl(?string $domain): string
    {
        $primary_domain = self::PRIMARY_PROJECT_DOMAIN;

        if ($domain) {
            $primary_domain = $domain . '.' . $primary_domain;
        }

        return sprintf('%s://%s', self::PRIMARY_PROJECT_PROTOCOL, $primary_domain);
    }

    private function getProjectId(): ?string
    {
        return $this->getCrowdinData('project_id');
    }

    private function getFile(TranslatorInterface $translator, $file_id, $target_language): array
    {
        return $this->request(
            $translator,
            "projects/{$this->getProjectId()}/translations/builds/files/{$file_id}",
            ['json' => ['targetLanguageId' => $target_language]],
            'POST'
        );
    }

    private function getFileProgress(TranslatorInterface $translator, $file_id): array
    {
        return $this->request(
            $translator,
            "projects/{$this->getProjectId()}/files/{$file_id}/languages/progress",
            ['limit' => 500]
        );
    }

    private function request(
        TranslatorInterface $translator,
        string $path,
        array $params = [],
        string $method = 'GET'
    ): ?array {
        $token = $translator->getSetting(self::TOKEN_KEY) === self::TOKEN_MASK
            ? $this->getCrowdinData(self::TOKEN_KEY)
            : $translator->getSetting(self::TOKEN_KEY);

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ]
        ];

        if ($method === 'GET') {
            $options['query'] = $params;
        } else {
            $options = array_replace_recursive($options, $params);
        }

        $client = new Client(['base_uri' => $this->getApiUrl($this->getCrowdinData('domain'))]);

        try {
            $response = $client->request($method, $path, $options);
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            throw new TMGMTException(
                'Unable to connect to crowdin service due to following error: @error',
                ['@error' => $response->getReasonPhrase()],
                $response->getStatusCode()
            );
        }

        if ($response->getStatusCode() >= Response::HTTP_BAD_REQUEST) {
            throw new TMGMTException(
                'Crowdin service returned validation error: #%code %error',
                [
                    '%code' => $response->getStatusCode(),
                    '%error' => $response->getReasonPhrase(),
                ]
            );
        }

        return Json::decode($response->getBody()->getContents());
    }

    private function requestFileWebhook(TranslatorInterface $translator): void
    {
        $crowdin_translator = $translator->getPlugin();

        if (!$crowdin_translator->getCrowdinData('webhook_id')) {
            $params = [
                'json' => [
                    'name' => 'Files Webhooks for Drupal Connector',
                    'url' => Url::fromRoute('tmgmt_crowdin.file_webhook')->setAbsolute()->toString(),
                    'events' => [self::FILE_TRANSLATED_EVENT, self::FILE_APPROVED_EVENT],
                    'requestType' => 'POST'
                ]
            ];

            $webhook = $this->request($translator, "projects/{$this->getProjectId()}/webhooks", $params, 'POST');

            $crowdin_translator->setCrowdinData('webhook_id', $webhook['data']['id']);
        }
    }

    private function cancelFileWebhook(TranslatorInterface $translator): void
    {
        //TODO
    }

    private function importTranslation(
        TranslatorInterface $translator,
        JobItemInterface $job_item,
        $file_id,
        $target_language
    ): void {
        /** @var WebXML $webxml */
        $webxml = $this->formatManager->createInstance('webxml');

        $translation = $this->getFile($translator, $file_id, $target_language);
        $validated_job = $webxml->validateImport($translation['data']['url']);

        if (!$validated_job) {
            throw new TMGMTException('Failed to validate remote translation, import aborted.');
        }

        if ((int)$validated_job->id() !== (int)$job_item->getJob()->id()) {
            throw new TMGMTException(
                'The remote translation (File ID: @file_id, Job ID: @target_job_id) does not match the current job ID @job_id.',
                [
                    '@file_id' => $file_id,
                    '@target_job_job' => $validated_job->id(),
                    '@job_id' => $job_item->getJob()->id(),
                ],
                'error'
            );
        }

        $data = $webxml->import($translation['data']['url']);

        if ($data) {
            $job_item->getJob()->addTranslatedData($data, NULL, TMGMT_DATA_ITEM_STATE_TRANSLATED);
            $job_item->addMessage('The translation has been received.');
        } else {
            throw new TMGMTException(
                'Could not process received translation data for the target file @file_id.',
                ['@file_id' => $file_id]
            );
        }
    }

    private function createRootDirectory(TranslatorInterface $translator): array
    {
        $root_directory = $this->request(
            $translator,
            "projects/{$this->getProjectId()}/directories",
            ['filter' => self::ROOT_DIRECTORY_NAME]
        );

        if ($root_directory['data']) {
            return reset($root_directory['data']);
        }

        $params = [
            'json' => [
                'name' => self::ROOT_DIRECTORY_NAME
            ]
        ];

        return $this->request($translator, "projects/{$this->getProjectId()}/directories", $params, 'POST');
    }

    private function createJobDirectory(TranslatorInterface $translator, int $root_directory_id, string $name): array
    {
        $params = [
            'json' => [
                'name' => $name,
                'directoryId' => $root_directory_id
            ]
        ];

        $directory = [];

        try {
            $directory = $this->request($translator, "projects/{$this->getProjectId()}/directories", $params, 'POST');
        } catch (TMGMTException $e) {
            if ($e->getCode() === 400) {
                $directories = $this->request(
                    $translator,
                    "projects/{$this->getProjectId()}/directories",
                    ['filter' => $name]
                );

                if ($directories['data']) {
                    $directory = reset($directories['data']);
                }
            }

            if (!$directory) {
                throw $e;
            }
        }

        return $directory;
    }

    private function removeJobDirectory(TranslatorInterface $translator, int $job_directory_id): void
    {
        $this->request($translator, "projects/{$this->getProjectId()}/directories/{$job_directory_id}", [], 'DELETE');
    }

    private function addFile(
        TranslatorInterface $translator,
        $directory_id,
        $file_name,
        $file_title,
        $xliff_content,
        $excluded_target_languages
    ): array {
        $storages_params = [
            'headers' => [
                'Content-Type' => 'octet-stream',
                'Crowdin-API-FileName' => $file_name
            ],
            'body' => $xliff_content
        ];

        $storage_response = $this->request($translator, 'storages', $storages_params, 'POST');

        $files_params = [
            'json' => [
                'storageId' => $storage_response['data']['id'],
                'name' => $storage_response['data']['fileName'],
                'title' => $file_title,
                'directoryId' => $directory_id,
                'excludedTargetLanguages' => $excluded_target_languages,
                'type' => 'webxml'
            ]
        ];

        return $this->request($translator, "projects/{$this->getProjectId()}/files", $files_params, 'POST');
    }
}
