<?php

namespace Drupal\tmgmt_crowdin\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\tmgmt\Entity\Job;
use Drupal\tmgmt\Entity\JobItem;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\JobItemInterface;
use Drupal\tmgmt\TMGMTException;
use Drupal\tmgmt\TranslatorInterface;
use Drupal\tmgmt_crowdin\Plugin\tmgmt\Translator\CrowdinTranslator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CrowdinWebhookController extends ControllerBase
{

    /** @var LoggerInterface */
    protected $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public static function create(ContainerInterface $container)
    {
        return new static($container->get('logger.factory')->get('tmgmt_crowdin'));
    }

    public function process(Request $request): JsonResponse
    {
        $response = ['success' => true, 'translations_updated' => false];

        $data = Json::decode($request->getContent());

        $file_path = explode('/',$data['file']);
        [$job_id, $job_item_id] = sscanf(end($file_path), CrowdinTranslator::FORMAT_FILE_NAME);

        if (!$job_id && !$job_item_id) {
            return new JsonResponse($response, Response::HTTP_OK);
        }

        /** @var JobInterface $job */
        $job = Job::load($job_id);
        /** @var JobItemInterface $job_item */
        $job_item = JobItem::load($job_item_id);

        if ($job->isAborted() || $job_item->isAborted()) {
            $this->logger->warning(
                'The job (%id) you receive translation from is not active. Please contact your Crowdin manager.',
                ['%id' => $job->id()]
            );

            $job->addMessage(
                'The job id (%item_id) you receive translation from is not active. Please contact your Crowdin manager.',
                ['%item_id' => $job_item->id()],
                'warning'
            );

            return new JsonResponse(null, Response::HTTP_NOT_FOUND);
        }

        /** @var TranslatorInterface $translator */
        $translator = $job->getTranslator();
        /** @var CrowdinTranslator $crowdin_translator */
        $crowdin_translator = $translator->getPlugin();

        $project = $crowdin_translator->getProject($translator);

        if ($project['data']['exportApprovedOnly'] && $data['event'] !== CrowdinTranslator::FILE_APPROVED_EVENT) {
            return new JsonResponse($response, Response::HTTP_OK);
        }

        $file_id = $data['file_id'];
        $target_language = $data['language'];

        try {
            if ($crowdin_translator->updateTranslation($job_item, $project, $file_id, $target_language)) {
                return new JsonResponse(['success' => true, 'translations_updated' => true], Response::HTTP_OK);
            }
        } catch (TMGMTException $e) {
            $job_item->addMessage($e->getMessage());
        } catch (\Exception $e) {
            watchdog_exception('tmgmt_crowdin', $e);
            return new JsonResponse(NULL, Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse(['success' => true, 'translations_updated' => false], Response::HTTP_OK);
    }

}
