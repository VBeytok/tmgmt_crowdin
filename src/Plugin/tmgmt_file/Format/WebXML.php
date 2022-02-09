<?php

namespace Drupal\tmgmt_crowdin\Plugin\tmgmt_file\Format;

use Drupal\Core\Messenger\MessengerTrait;
use Drupal\tmgmt\Data;
use Drupal\tmgmt\Entity\Job;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\JobItemInterface;
use Drupal\tmgmt_file\Format\FormatInterface;

/**
 * Export into Webxml.
 *
 * @FormatPlugin(
 *   id = "webxml",
 *   label = @Translation("WEBXML")
 * )
 */
class WebXML extends \XMLWriter implements FormatInterface
{
    use MessengerTrait;

    /** @var Job */
    protected $job;

    protected $importedXML;
    protected $importedTransUnits;

    protected $configuration;

    public function __construct(array $configuration, $plugin_id, $plugin_definition)
    {
        $this->configuration = $configuration;
    }

    protected function addItem(JobItemInterface $item): void
    {
        $this->startElement('JobItem');
        $this->writeAttribute('id', $item->id());

        $data = \Drupal::service('tmgmt.data')->filterTranslatable($item->getData());
        foreach ($data as $key => $element) {
            $this->addTranslationUnit($key, $element, $item);
        }
        $this->endElement();
    }

    protected function addTranslationUnit($key, $element, JobItemInterface $item): void
    {
        /** @var Data $data_service */
        $data_service = \Drupal::service('tmgmt.data');

        $element_name = preg_replace(
            '/[^a-zA-Z0-9_-]+/',
            '',
            ucwords(implode('', $element['#parent_label']) ?: $key)
        );

        $key = $item->id() . $data_service::TMGMT_ARRAY_DELIMITER . $key;

        $this->startElement($element_name);
        $this->writeAttribute('id', $key);
        $this->writeAttribute('resname', $key);

        //TODO: check if need add text with translation in Drupal
        $this->writeData($element['#text']);

        $this->endElement();
    }

    protected function writeData($text): bool
    {
        if ($this->job->getSetting('xliff_cdata')) {
            return $this->writeCdata(trim($text));
        }

        return $this->text($text);
    }

    public function export(JobInterface $job, $conditions = []): string
    {
        $this->job = $job;

        $this->openMemory();
        $this->setIndent(true);
        $this->setIndentString('  ');
        $this->startDocument('1.0', 'UTF-8');

        $this->startElement('content');
        $this->writeAttribute('source-language', $job->getRemoteSourceLanguage());
        $this->writeAttribute('target-language', $job->getRemoteTargetLanguage());
        $this->writeAttribute('date', date('Y-m-d\Th:m:i\Z'));
        $this->writeAttribute('tool-id', 'tmgmt');
        $this->writeAttribute('job-id', $job->id());

        foreach ($job->getItems($conditions) as $item) {
            $this->addItem($item);
        }

        $this->endElement();
        $this->endDocument();
        return $this->outputMemory();
    }

    public function import($imported_file, $is_file = TRUE): ?array
    {
        if ($this->getImportedXML($imported_file, $is_file)) {
            return \Drupal::service('tmgmt.data')->unflatten($this->getImportedTargets());
        }

        return null;
    }

    public function validateImport($imported_file, $is_file = true): ?Job
    {
        $xml = $this->getImportedXML($imported_file, $is_file);
        if ($xml === null) {
            $this->messenger()->addError(t('The imported file is not a valid XML.'));
            return null;
        }

        $attributes = $xml->attributes();

        if ($attributes) {
            $attributes = reset($attributes);
        } else {
            $this->messenger()->addError(t('The imported file is missing required XML attributes.'));
            return null;
        }

        // Check if the job has a valid job reference.
        if (!isset($attributes['job-id'])) {
            $this->messenger()->addError(t('The imported file does not contain a job reference.'));
            return null;
        }

        // Attempt to load the job if none passed.
        $job = (Job::load((int)$attributes['job-id']));
        if (empty($job)) {
            $this->messenger()->addError(
                t('The imported file job id @file_tjid is not available.', ['@file_tjid' => $attributes['job-id']])
            );
            return null;
        }

        // Compare source language.
        if (!isset($attributes['source-language']) || $job->getRemoteSourceLanguage() != $attributes['source-language']) {
            $job->addMessage(
                'The imported file source language @file_language does not match the job source language @job_language.',
                [
                    '@file_language' => empty($attributes['source-language'])
                        ? t('none')
                        : $attributes['source-language'],
                    '@job_language' => $job->getRemoteSourceLanguage(),
                ],
                'error'
            );
            return null;
        }

        // Compare target language.
        if (!isset($attributes['target-language']) || $job->getRemoteTargetLanguage() != $attributes['target-language']) {
            $job->addMessage(
                'The imported file target language @file_language does not match the job target language @job_language.',
                [
                    '@file_language' => empty($attributes['target-language'])
                        ? t('none')
                        : $attributes['target-language'],
                    '@job_language' => $job->getRemoteTargetLanguage(),
                ],
                'error'
            );
            return null;
        }

        $targets = $this->getImportedTargets();

        if (empty($targets)) {
            $job->addMessage('The imported file seems to be missing translation.', 'error');
            return null;
        }

        // Validation successful.
        return $job;
    }

    protected function getImportedXML(string $imported_file, bool $is_file = true): ?\SimpleXMLElement
    {
        if (empty($this->importedXML)) {
            if ($is_file) {
                $imported_file = file_get_contents($imported_file);
            }

            $this->importedXML = simplexml_load_string($imported_file);
            if ($this->importedXML === false) {
                $this->messenger()->addError(t('The imported file is not a valid XML.'));
                return null;
            }
        }

        return $this->importedXML;
    }

    protected function getImportedTargets(): ?array
    {
        if (empty($this->importedXML)) {
            return null;
        }

        if (empty($this->importedTransUnits)) {
            foreach ($this->importedXML->JobItem->children() as $unit) {
                $this->importedTransUnits[(string)$unit['id']]['#text'] = (string)$unit;
            }
        }

        return $this->importedTransUnits;
    }
}