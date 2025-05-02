<?php

namespace Drupal\ipc_syncdb\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\ipc_administrative_notifications\Event\AdministrativeNotificationsEvents;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the job type for importing product data from Sync DB.
 *
 * @AdvancedQueueJobType(
 *   id = "ipc_syncdb_order_post_transaction",
 *   label = @Translation("Export orders to SyncDB after an order is placed"),
 * )
 */
class SyncDbOrderPostTransaction extends SyncDbJobTypeBase {

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

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
   * Service for logging Query.
   *
   * @var \Drupal\ipc_syncdb\Logger\TransactionManagerLoggerInterface
   */
  protected $transactionManagerLogger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->dateFormatter = $container->get('date.formatter');
    $instance->configFactory = $container->get('config.factory');
    $instance->transactionManager = $container->get('ipc_syncdb.transaction_manager');
    $instance->transactionManagerLogger = $container->get('ipc_syncdb.get_transaction_manager_logger');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function process(Job $job) {
    $job_id = $job->getId();
    $this->transactionManagerLogger->initLogger('post', $job_id);
    try {
      $payload = $job->getPayload();
      $payload_json = json_encode($payload);
      $order = NULL;
      $invoice = NULL;
      $this->transactionManagerLogger->setMessage('post', $job_id, $this->t('Starting the process ...'));
      if (isset($payload['invoice_id']) && $invoice_id = $payload['invoice_id']) {
        // Prepare to send Post Transaction for Quote.
        /** @var \Drupal\commerce_invoice_payment\Entity\Invoice $invoice */
        $invoice = $this->entityTypeManager->getStorage('commerce_invoice')->load($invoice_id);
        if ($invoice) {
          $this->transactionManagerLogger->setMessage('post', $job_id, "Loading the Invoice by ID - {$invoice_id}.");
          /** @var \Drupal\commerce_order\Entity\OrderInterface[] $orders */
          $orders = $invoice->getOrders();
          if ($orders) {
            $order = reset($orders);
          }
        }
      }
      elseif (isset($payload['order_id']) && $order_id = $payload['order_id']) {
        // Prepare to send Post Transaction for Order.
        /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
        $order = $this->entityTypeManager->getStorage('commerce_order')->load($order_id);
      }

      if (!$order instanceof OrderInterface) {
        $this->transactionManagerLogger->setMessage('post', $job_id, "Failed to load the Order.");
        $this->logger->info($this->transactionManagerLogger->getMessage('post', $job_id));
        $this->transactionManagerLogger->deleteMessage('post', $job_id);
        $message = $this->t('Post Transaction Sync: Tried to load an Order that does not exist in Drupal.');
        return $this->failure($job, $message, AdministrativeNotificationsEvents::TRANSACTIONS_POST_SYNC, 'job', $job_id, $payload_json);
      }
      $this->transactionManagerLogger->setMessage('post', $job->getId(), "Loading Order with ID - {$order->id()}.");

      // Perform Post Transaction API call.
      $result = $this->transactionManager->postTransaction($order, $invoice, $job_id);
      $this->logger->info($this->transactionManagerLogger->getMessage('post', $job_id));
      $this->transactionManagerLogger->deleteMessage('post', $job_id);
      switch ($result) {
        case 'success':
          return $this->success($job, $this->t('Post Transaction Sync: Transaction was synced'));

        case 'skipped_user_customer_id':
          $message = $this->t('Post Transaction Sync: Transaction skipped for order - The customer is a user who does not have a value set for SyncDB ID');
          return $this->failure($job, $message, AdministrativeNotificationsEvents::TRANSACTIONS_POST_SYNC, 'job', $job_id, $payload_json);

        case 'skipped_quote_without_ns_id':
          $message = $this->t('Post Transaction Sync: Transaction skipped for order - The quote does not have a netsuite_id for "Quote Purchased');
          return $this->failure($job, $message, AdministrativeNotificationsEvents::TRANSACTIONS_POST_SYNC, 'job', $job_id, $payload_json);

        // Consider all other statuses a failure.
        default:
          $message = $this->t('Post Transaction Sync: Transaction was not synced due to unhandled reason.');
          return $this->failure($job, $message, AdministrativeNotificationsEvents::TRANSACTIONS_POST_SYNC, 'job', $job_id, $payload_json);
      }
    }
    catch (\Exception $exception) {
      $this->logger->info($this->transactionManagerLogger->getMessage('post', $job_id));
      $this->transactionManagerLogger->deleteMessage('post', $job_id);
      $message = $exception->getMessage();
      return $this->failure($job, $message, AdministrativeNotificationsEvents::TRANSACTIONS_POST_SYNC, 'job', $job_id, $payload_json);
    }
  }

}
