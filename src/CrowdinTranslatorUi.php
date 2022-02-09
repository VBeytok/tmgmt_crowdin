<?php

namespace Drupal\tmgmt_crowdin;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\TranslatorInterface;
use Drupal\tmgmt\TranslatorPluginUiBase;
use Drupal\tmgmt_crowdin\Plugin\tmgmt\Translator\CrowdinTranslator;

class CrowdinTranslatorUi extends TranslatorPluginUiBase
{
    use StringTranslationTrait;

    public function buildConfigurationForm(array $form, FormStateInterface $form_state): array
    {
        $form = parent::buildConfigurationForm($form, $form_state);

        /** @var TranslatorInterface $translator */
        $translator = $form_state->getFormObject()->getEntity();

        $form[CrowdinTranslator::TOKEN_KEY] = [
            '#type' => 'textfield',
            '#required' => true,
            '#title' => $this->t('Personal Access Token'),
            '#default_value' => $translator->getSetting(CrowdinTranslator::TOKEN_KEY) ? CrowdinTranslator::TOKEN_MASK : '',
            '#description' => $this->t(
                'Please enter your Personal Access Token or check how to create one <a href="@url">Crowdin Support</a> or <a href="@url_enterprise">Crowdin Enterprise Support</a>',
                [
                    '@url' => 'https://support.crowdin.com/account-settings/#api',
                    '@url_enterprise' => 'https://support.crowdin.com/enterprise/personal-access-tokens/',
                ]
            )
        ];

        $form['project_id'] = [
            '#type' => 'textfield',
            '#required' => true,
            '#title' => $this->t('Project Id'),
            '#default_value' => $translator->getSetting('project_id'),
        ];

        $form['domain'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Enterprise Domain'),
            '#default_value' => $translator->getSetting('domain'),
            '#description' => $this->t('Please enter your Enterprise Domain'),
        ];

        $form += parent::addConnectButton();

        return $form;
    }

    public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void
    {
        parent::validateConfigurationForm($form, $form_state);

        /** @var TranslatorInterface $translator */
        $translator = $form_state->getFormObject()->getEntity();
        /** @var CrowdinTranslator $crowdinTranslator */
        $crowdinTranslator = $translator->getPlugin();

        $crowdinTranslator->setCrowdinData('project_id', $translator->getSetting('project_id'));
        $crowdinTranslator->setCrowdinData('domain', $translator->getSetting('domain'));

        if ($translator->getSetting(CrowdinTranslator::TOKEN_KEY) && $crowdinTranslator->getUser($translator)) {
            $token = $translator->getSetting(CrowdinTranslator::TOKEN_KEY) === CrowdinTranslator::TOKEN_MASK
                ? $crowdinTranslator->getCrowdinData(CrowdinTranslator::TOKEN_KEY)
                : $translator->getSetting(CrowdinTranslator::TOKEN_KEY);

            $crowdinTranslator->setCrowdinData(CrowdinTranslator::TOKEN_KEY, $token);

            return;
        }

        $crowdinTranslator->setCrowdinData(CrowdinTranslator::TOKEN_KEY, null);

        $form_state->setError($form['plugin_wrapper']['settings'][CrowdinTranslator::TOKEN_KEY], t('Personal Access Token is not valid.'));
    }

    public function checkoutInfo(JobInterface $job): array
    {
        $form = [];

        if ($job->isActive()) {
            $form['actions']['pull'] = [
                '#type' => 'submit',
                '#value' => $this->t('Fetch translations'),
                '#submit' => [[$this, 'submitFetchTranslations']],
                '#weight' => -10,
            ];
        }

        return $form;
    }

    public function submitFetchTranslations(array $form, FormStateInterface $form_state): void
    {
        /** @var \Drupal\tmgmt\Entity\Job $job */
        $job = $form_state->getFormObject()->getEntity();

        /** @var CrowdinTranslator $crowdin_plugin */
        $crowdin_plugin = $job->getTranslator()->getPlugin();
        $crowdin_plugin->fetchTranslations($job);
    }
}
