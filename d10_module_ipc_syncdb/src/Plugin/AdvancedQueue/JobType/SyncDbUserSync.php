<?php

namespace Drupal\ipc_syncdb\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\ipc_administrative_notifications\Event\AdministrativeNotificationsEvents;
use GuzzleHttp\Exception\ServerException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides the job type for importing user data from Sync DB.
 *
 * @AdvancedQueueJobType(
 *   id = "syncdb_user_sync",
 *   label = @Translation("Sync DB User Sync"),
 * )
 */
class SyncDbUserSync extends SyncDbJobTypeBase {

  /**
   * The user importer.
   *
   * @var \Drupal\ipc_syncdb\UserImporter
   */
  protected $userImporter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->userImporter = $container->get('ipc_syncdb.user_importer');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function process(Job $job) {
    $user_storage = $this->entityTypeManager->getStorage('user');
    $payload = $job->getPayload();
    $payload_json = json_encode($payload);
    $message = $this->t('User not synced successfully.');
    if (isset($payload['user_id']) && $user_id = $payload['user_id']) {
      // Import user by User ID.
      try {
        $this->userImporter->importUser($user_id);
      }
      catch (\Exception $exception) {
        $message = $this->t('Unhandled Exception: @message', ['@message' => $exception->getMessage()]);
        if ($exception instanceof ServerException && $exception->getCode() === Response::HTTP_GATEWAY_TIMEOUT) {
          $message = $this->t('Server Exception: @message', ['@message' => $exception->getMessage()]);
        }
        return $this->failure($job, $message, AdministrativeNotificationsEvents::USER_SYNC, 'user', $user_id, $payload_json);
      }
      if (!$user_storage->loadByProperties(['syncdb_id' => $user_id])) {
        return $this->failure($job, $message, AdministrativeNotificationsEvents::USER_SYNC, 'user', $user_id, $payload_json);
      }
    }
    elseif (isset($payload['user_email']) && $user_email = $payload['user_email']) {
      // Import user by email.
      try {
        $this->userImporter->importUserByEmail($user_email);
      }
      catch (\Exception $exception) {
        $message = $this->t('Unhandled Exception: @message', ['@message' => $exception->getMessage()]);
        if ($exception instanceof ServerException && $exception->getCode() === Response::HTTP_GATEWAY_TIMEOUT) {
          $message = $this->t('Server Exception: @message', ['@message' => $exception->getMessage()]);
        }
        return $this->failure($job, $message, AdministrativeNotificationsEvents::USER_SYNC, 'user', $user_email, $payload_json);
      }

      if (!$user_storage->loadByProperties(['mail' => $user_email])) {
        return $this->failure($job, $message, AdministrativeNotificationsEvents::USER_SYNC, 'user', $user_email, $payload_json);
      }
    }

    return $this->success($job, '');
  }

}
