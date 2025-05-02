<?php

namespace Drupal\ipc_syncdb\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\ipc_administrative_notifications\Event\AdministrativeNotificationsEvents;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the job type for importing download license data from Sync DB.
 *
 * @AdvancedQueueJobType(
 *   id = "syncdb_license_sync",
 *   label = @Translation("Sync DB License Sync"),
 * )
 */
class SyncDbLicenseSync extends SyncDbJobTypeBase {

  /**
   * The license importer.
   *
   * @var \Drupal\ipc_syncdb\LicenseImporter
   */
  protected $licenseImporter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->licenseImporter = $container->get('ipc_syncdb.license_importer');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function process(Job $job) {
    $payload = $job->getPayload();
    $result = $this->licenseImporter->importDigitalDownloadTransaction($payload['transaction_id']);
    if ($result === 'success' || $result === 'skipped') {
      return $this->success($job, '');
    }
    $message = $this->t('License not synced successfully.');
    return $this->failure($job, $message, AdministrativeNotificationsEvents::LICENSE_SYNC, 'transaction', $payload['transaction_id'], json_encode($job->getPayload()));
  }

}
