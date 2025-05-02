<?php

namespace Drupal\ipc_syncdb\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\ipc_administrative_notifications\Event\AdministrativeNotificationsEvents;
use GuzzleHttp\Exception\ServerException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides the job type for importing company data from Sync DB.
 *
 * @AdvancedQueueJobType(
 *   id = "syncdb_company_sync",
 *   label = @Translation("Sync DB Company Sync"),
 * )
 */
class SyncDbCompanySync extends SyncDbJobTypeBase {

  /**
   * The company importer.
   *
   * @var \Drupal\ipc_syncdb\CompanyImporter
   */
  protected $companyImporter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->companyImporter = $container->get('ipc_syncdb.company_importer');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function process(Job $job) {
    $company_id = $job->getPayload()['company_id'];
    $payload_json = json_encode($job->getPayload());

    try {
      $this->companyImporter->importCompany($company_id);
    }
    catch (\Exception $exception) {
      $message = $this->t('Unhandled Exception: @message', ['@message' => $exception->getMessage()]);
      if ($exception instanceof ServerException && $exception->getCode() === Response::HTTP_GATEWAY_TIMEOUT) {
        $message = $this->t('Server Exception: @message', ['@message' => $exception->getMessage()]);
      }
      return $this->failure($job, $message, AdministrativeNotificationsEvents::COMPANY_SYNC, 'group', $company_id, $payload_json);
    }

    $storage = $this->entityTypeManager->getStorage('group');
    if (!$storage->loadByProperties(['syncdb_account_number' => $company_id])) {
      $message = $this->t('Company not synced successfully.');
      return $this->failure($job, $message, AdministrativeNotificationsEvents::COMPANY_SYNC, 'group', $company_id, $payload_json);
    }
    return $this->success($job, '');
  }

}
