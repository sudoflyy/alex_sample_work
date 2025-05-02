<?php

namespace Drupal\ipc_syncdb\EventSubscriber;

use Drupal\advancedqueue\Job;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event Subscriber for Commerce events related to Transactions.
 */
class OrderSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * Constructs a new TransactionSubscriber object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config Factory service.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection to use.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory, Connection $connection) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->connection = $connection;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events = [
      'commerce_order.place.post_transition' => [
        'enqueueOrderToSendTransaction',
        -200,
      ],
      'commerce_invoice.confirm.post_transition' => [
        'enqueueInvoiceToSendTransaction',
        -200,
      ],
    ];
    return $events;
  }

  /**
   * Creates a job for an order in the IPC Transaction Sync queue.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The event we subscribed to.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function enqueueOrderToSendTransaction(WorkflowTransitionEvent $event): void {
    $config = $this->configFactory->get('ipc_syncdb.settings');
    if (!$config->get('export_orders_to_syncdb')) {
      return;
    }
    $order = $event->getEntity();
    // Here we have new type of order "Invoice Payment".
    $job_name = ($order->bundle() === 'invoice_payment') ? 'ipc_syncdb_payment_post_transaction' : 'ipc_syncdb_order_post_transaction';
    $queue_machine_name = ($order->bundle() === 'invoice_payment') ? 'ipc_payment_post_sync' : 'ipc_transaction_sync';
    $payload = [
      'order_id' => $order->id(),
    ];
    $order_sync_job = Job::create($job_name, $payload);
    if (!$this->jobExists($queue_machine_name, $payload)) {
      $queue_storage = $this->entityTypeManager->getStorage('advancedqueue_queue');
      /** @var \Drupal\advancedqueue\Entity\QueueInterface $queue */
      $queue = $queue_storage->load($queue_machine_name);

      $queue->enqueueJob($order_sync_job, 60);
    }
  }

  /**
   * Creates a job for a quote invoice in the IPC Transaction Sync queue.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The event we subscribed to.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function enqueueInvoiceToSendTransaction(WorkflowTransitionEvent $event): void {
    $config = $this->configFactory->get('ipc_syncdb.settings');
    if (!$config->get('export_orders_to_syncdb')) {
      return;
    }
    /** @var \Drupal\commerce_invoice\Entity\Invoice $invoice */
    $invoice = $event->getEntity();
    $orders = $invoice->getOrders();
    // We have a situation when quote was moved from Paid to Open state,
    // we do not need to add this quote to the queue.
    $syncdb_id = $invoice->get('syncdb_id')->value ?? 0;
    if ($invoice->bundle() === 'quote' && !empty($orders) && empty($syncdb_id)) {
      $queue_id = 'ipc_transaction_sync';
      $payload = [
        'invoice_id' => $invoice->id(),
      ];
      $invoice_sync_job = Job::create('ipc_syncdb_order_post_transaction', $payload);
      if (!$this->jobExists($queue_id, $payload)) {
        $queue_storage = $this->entityTypeManager->getStorage('advancedqueue_queue');
        /** @var \Drupal\advancedqueue\Entity\QueueInterface $queue */
        $queue = $queue_storage->load($queue_id);
        $queue->enqueueJob($invoice_sync_job, 60);
      }
    }
  }

  /**
   * Find if a job exists.
   *
   * Note: this presumes that payloads for this queue are idempotent. If this
   * is NOT the case for a particular queue, it would need to use alternate
   * logic. (Or this helper function would need to be extended to allow
   * for more parameters.)
   *
   * @param string $queue_id
   *   The queue.
   * @param array $payload
   *   The job payload.
   *
   * @return bool
   *   Whether a job with this payload already exists.
   */
  public function jobExists(string $queue_id, array $payload): bool {
    $query = 'SELECT COUNT(*) FROM {advancedqueue} WHERE queue_id = :queue_id AND payload = :payload';
    $params = [
      ':queue_id' => $queue_id,
      ':payload' => json_encode($payload),
    ];
    $count = (int) $this->connection->query($query, $params)->fetchField();
    return $count > 0;
  }

}
