<?php

namespace Drupal\ipc_syncdb\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;
use Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\ipc_administrative_notifications\Entity\AdministrativeNotification;
use Drupal\ipc_administrative_notifications\Event\AdministrativeNotificationEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the base class for IPC SyncDb job types.
 */
abstract class SyncDbJobTypeBase extends JobTypeBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The key value store.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueFactoryInterface
   */
  protected $keyValueFactory;

  /**
   * The event dispatcher.
   *
   * @var \Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher
   */
  protected $eventDispatcher;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a new SyncDbProductImport object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity query factory.
   * @param \Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher $event_dispatcher
   *   The event dispatcher.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $key_value_factory
   *   The key value factory.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, ContainerAwareEventDispatcher $event_dispatcher, LoggerInterface $logger, KeyValueFactoryInterface $key_value_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityTypeManager = $entity_type_manager;
    $this->eventDispatcher = $event_dispatcher;
    $this->logger = $logger;
    $this->keyValueFactory = $key_value_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('event_dispatcher'),
      $container->get('logger.channel.ipc_syncdb'),
      $container->get('keyvalue'),
    );
  }

  /**
   * Get the number of retries.
   *
   * @param string $queue_id
   *   The queue id.
   *
   * @return int
   *   The number of retries.
   */
  public function getNotificationNumberOfRetries(string $queue_id): int {
    return $this->keyValueFactory->get($queue_id)->get('number_of_retries') ?? 4;
  }

  /**
   * Get the retry delay.
   *
   * @param string $queue_id
   *   The queue id.
   *
   * @return int
   *   The retry delay.
   */
  public function getNotificationRetryDelay(string $queue_id): int {
    return $this->keyValueFactory->get($queue_id)->get('retry_delay') ?? 1800;
  }

  /**
   * Dispatch the commerce email event.
   *
   * @param string $event_name
   *   The event name.
   * @param string|null $entity_type
   *   The entity type.
   * @param string|null $entity_id
   *   The entity id.
   * @param string $message
   *   The message.
   * @param string|null $payload
   *   The payload.
   */
  public function dispatch(string $event_name, ?string $entity_type, ?string $entity_id, string $message, string $payload = NULL): void {
    $notification = AdministrativeNotification::create([
      'name' => $this->getPluginId(),
      'related_entity_type' => $entity_type ?? '',
      'related_entity_id' => $entity_id ?? '',
      'message' => $message,
      'payload' => $payload,
    ]);
    $event = new AdministrativeNotificationEvent($notification);
    $this->eventDispatcher->dispatch($event, $event_name);
  }

  /**
   * Return the job failure result. Send a notification, if appropriate.
   *
   * @param \Drupal\advancedqueue\Job $job
   *   The job.
   * @param string $message
   *   The message.
   * @param string|null $event_name
   *   The event name.
   * @param string|null $entity_type
   *   The entity type.
   * @param string|null $entity_id
   *   The entity id.
   * @param string|null $payload
   *   The payload.
   *
   * @return \Drupal\advancedqueue\JobResult
   *   The job result.
   */
  public function failure(Job $job, string $message, ?string $event_name = NULL, ?string $entity_type = NULL, ?string $entity_id = NULL, ?string $payload = NULL): JobResult {
    $number_of_retries = $this->getNotificationNumberOfRetries($job->getQueueId());
    $retry_delay = $this->getNotificationRetryDelay($job->getQueueId());
    if (((int) $job->getNumRetries()) === $number_of_retries) {
      $this->dispatch($event_name, $entity_type, $entity_id, $message, $payload);
    }
    return JobResult::failure($message, $number_of_retries, $retry_delay);
  }

  /**
   * Return the job success result.
   *
   * We are currently just wrapping the JobResult::failure static, but have
   * added this function to be consistent with the related failure function
   * and to allow for centralized notification/logging/etc... on success.
   *
   * @param \Drupal\advancedqueue\Job $job
   *   The job.
   * @param string $message
   *   The message.
   *
   * @return \Drupal\advancedqueue\JobResult
   *   The job result.
   */
  public function success(Job $job, string $message): JobResult {
    return JobResult::success($message);
  }

}
