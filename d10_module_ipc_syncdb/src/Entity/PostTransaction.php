<?php

namespace Drupal\ipc_syncdb\Entity;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ipc_syncdb\Logger\TransactionManagerLoggerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Post transaction abstract class.
 */
abstract class PostTransaction {

  /**
   * The Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  /**
   * The current Entity ID.
   */
  protected ?string $entityID;

  /**
   * The current Job ID.
   */
  protected ?string $jobID;

  /**
   * Service for logging Query.
   */
  protected TransactionManagerLoggerInterface $transactionManagerLogger;

  /**
   * The post transaction constructor.
   *
   * @param string $entity_id
   *   The Entity ID.
   * @param string $job_id
   *   The job id.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\ipc_syncdb\Logger\TransactionManagerLoggerInterface $transaction_manager_logger
   *   Service for logging queue.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter.
   */
  public function __construct(string $entity_id, string $job_id, EntityTypeManagerInterface $entity_type_manager, LoggerInterface $logger, TransactionManagerLoggerInterface $transaction_manager_logger, DateFormatterInterface $date_formatter) {
    $this->entityID = $entity_id;
    $this->jobID = $job_id;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
    $this->transactionManagerLogger = $transaction_manager_logger;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * Create method for this class.
   *
   * @param string $entity_id
   *   Entity ID.
   * @param string $job_id
   *   The current job ID.
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   Dependency injection container.
   *
   * @return static
   *   Instance of this class.
   */
  public static function create(string $entity_id, string $job_id, ContainerInterface $container) {
    return new static(
      $entity_id,
      $job_id,
      $container->get('entity_type.manager'),
      $container->get('logger.channel.ipc_syncdb'),
      $container->get('ipc_syncdb.get_transaction_manager_logger'),
      $container->get('date.formatter')
    );
  }

  /**
   * Helper function to prepare json for sending to the API.
   *
   * @return string
   *   The json data.
   *
   * @throws \JsonException
   * @throws \RuntimeException
   */
  abstract public function prepareObject(): string;

  /**
   * Helper function to prepare default values for the object.
   */
  abstract protected function setDefaults(): void;

}
