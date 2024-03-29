<?php

namespace Drupal\ipc_syncdb\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\ipc_syncdb\TransactionManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the job type for importing transaction (order) data from Sync DB.
 *
 * @AdvancedQueueJobType(
 *   id = "ipc_syncdb_order_get_transaction",
 *   label = @Translation("Updates orders on the site using data from SyncDB"),
 * )
 */
class SyncDbOrderGetTransaction extends JobTypeBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
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
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Service for making API calls to SyncDb.
   *
   * @var \Drupal\ipc_syncdb\TransactionManager
   */
  protected $transactionManager;

  /**
   * Constructs a new SyncDbOrderGetTransaction object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity query factory.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config Factory service.
   * @param \Drupal\ipc_syncdb\TransactionManager $transaction_manager
   *   Service for making API calls to SyncDb.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, DateFormatterInterface $date_formatter, MessengerInterface $messenger, LoggerInterface $logger, ConfigFactoryInterface $config_factory, TransactionManager $transaction_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityTypeManager = $entity_type_manager;
    $this->dateFormatter = $date_formatter;
    $this->messenger = $messenger;
    $this->logger = $logger;
    $this->configFactory = $config_factory;
    $this->transactionManager = $transaction_manager;
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
      $container->get('date.formatter'),
      $container->get('messenger'),
      $container->get('logger.channel.ipc_syncdb'),
      $container->get('config.factory'),
      $container->get('ipc_syncdb.transaction_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process(Job $job) {
    $order_id = $job->getPayload()['order_id'];
    $transaction_id = $job->getPayload()['transaction_id'];
    $status = $this->transactionManager->processUpdateForTransaction($order_id, $transaction_id);
    if ($status == 'success') {
      return JobResult::success();
    }
    else {
      return JobResult::failure('Transaction was not synced', 31, 86400);
    }
  }

}
