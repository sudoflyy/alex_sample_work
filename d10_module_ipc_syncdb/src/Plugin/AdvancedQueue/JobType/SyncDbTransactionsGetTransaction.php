<?php

namespace Drupal\ipc_syncdb\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\ipc_administrative_notifications\Event\AdministrativeNotificationsEvents;
use Drupal\ipc_syncdb\Enums\TransactionTypes;
use Drupal\ipcsync\Utilities\Transaction;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the job type for importing transaction (order) data from Sync DB.
 *
 * @AdvancedQueueJobType(
 *   id = "ipc_syncdb_transactions_get_transaction",
 *   label = @Translation("Updates transactions on the site using data from
 *   SyncDB"),
 * )
 */
class SyncDbTransactionsGetTransaction extends SyncDbJobTypeBase {

  /**
   * Order source Drupal eCommerce.
   */
  const ORDER_SOURCE_DRUPAL_ECOMMERCE = 7;

  /**
   * Service for making API calls to SyncDb.
   *
   * @var \Drupal\ipc_syncdb\TransactionManager
   */
  protected $transactionManager;

  /**
   * Service for making API calls to SyncDb.
   *
   * @var \Drupal\commerce_payment\PaymentOrderUpdaterInterface
   */
  protected $paymentOrderUpdater;

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
    $instance->transactionManager = $container->get('ipc_syncdb.transaction_manager');
    $instance->paymentOrderUpdater = $container->get('commerce_payment.order_updater');
    $instance->transactionManagerLogger = $container->get('ipc_syncdb.get_transaction_manager_logger');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function process(Job $job) {
    $payload = $job->getPayload();
    $payload_json = json_encode($payload);
    $transaction_id = $payload['transaction_id'];
    $job_id = $job->getId();
    $this->transactionManagerLogger->initLogger('get', $job_id);
    try {
      $this->transactionManagerLogger->setMessage('get', $job_id, $this->t('Starting the process ...'));
      $this->transactionManagerLogger->setMessage('get', $job_id, $this->t('Trying to get transaction by ID - @transaction_id', ['@transaction_id' => $transaction_id]));
      $requestVariables = new \stdClass();
      $requestVariables->transactionId = $transaction_id;
      $transaction = Transaction::getTransaction($requestVariables);
      // Here we need to check if we have something broken in the response.
      if (empty($transaction['responseInfo'])) {
        $this->transactionManagerLogger->setMessage('get', $job_id, $this->t('Response message is empty.'));
        $this->logger->info($this->transactionManagerLogger->getMessage('get', $job_id));
        $this->transactionManagerLogger->deleteMessage('get', $job_id);
        $message = $this->t('Transaction Sync: Transaction was not synced due to empty "responseInfo" in the response.');
        return $this->failure($job, $message, AdministrativeNotificationsEvents::TRANSACTIONS_GET_SYNC, 'transaction', $transaction_id, $payload_json);
      }
      if (empty($transaction['transaction'])) {
        $this->transactionManagerLogger->setMessage('get', $job_id, $this->t('Transaction is empty.'));
        $this->logger->info($this->transactionManagerLogger->getMessage('get', $job_id));
        $this->transactionManagerLogger->deleteMessage('get', $job_id);
        $message = $this->t('Transaction Sync: Transaction was not synced due to empty "transaction" in the response.');
        return $this->failure($job, $message, AdministrativeNotificationsEvents::TRANSACTIONS_GET_SYNC, 'transaction', $transaction_id, $payload_json);
      }
      if ($transaction['responseInfo']['responseMessage'] !== 'Success') {
        $this->transactionManagerLogger->setMessage('get', $job_id, $this->t('Response message - @message.', ['@message' => $transaction["responseInfo"]["responseMessage"]]));
        $this->logger->info($this->transactionManagerLogger->getMessage('get', $job_id));
        $this->transactionManagerLogger->deleteMessage('get', $job_id);
        $message = $this->t('Transaction Sync: Transaction was not synced due to "responseMessage" - @message in the response.', ['@message' => $transaction['responseInfo']['responseMessage']]);
        return $this->failure($job, $message, AdministrativeNotificationsEvents::TRANSACTIONS_GET_SYNC, 'transaction', $transaction_id, $payload_json);
      }

      $transaction_type = $transaction['transaction']['transactionType']['transactionTypeId'] ?: '';
      switch ($transaction_type) {
        case TransactionTypes::SALES_ORDER:
          if ($transaction['transaction']['orderSource']['orderSourceId'] != self::ORDER_SOURCE_DRUPAL_ECOMMERCE) {
            $this->transactionManagerLogger->setMessage('get', $job_id, $this->t('Failed due to reason: Order Source is not Drupal eCommerce.'));
            $this->logger->info($this->transactionManagerLogger->getMessage('get', $job_id));
            $this->transactionManagerLogger->deleteMessage('get', $job_id);
            $result = $this->success($job, $this->t('Transaction Sync: Order source is not Drupal eCommerce.'));
          }
          elseif (!is_numeric($transaction['transaction']['externalId'] ?? NULL)) {
            $this->transactionManagerLogger->setMessage('get', $job_id, $this->t('Failed due to reason: non-numeric externalId - @external_id.', ['@external_id' => $transaction['transaction']['externalId']]));
            $this->logger->info($this->transactionManagerLogger->getMessage('get', $job_id));
            $this->transactionManagerLogger->deleteMessage('get', $job_id);
            $message = $this->t('Transaction Sync: ExternalId is not numeric or empty.');
            return $this->failure($job, $message, AdministrativeNotificationsEvents::TRANSACTIONS_GET_SYNC, 'transaction', $transaction_id, $payload_json);
          }
          else {
            $success_message = $this->transactionManager->processUpdateForOrder($transaction['transaction'], $job_id);
            $result = $this->success($job, $success_message);
            $this->logger->info($this->transactionManagerLogger->getMessage('get', $job_id));
            $this->transactionManagerLogger->deleteMessage('get', $job_id);
          }
          break;

        case TransactionTypes::QUOTES:
          $success_message = $this->transactionManager->processUpdateForQuote($transaction['transaction'], $job_id);
          $result = $this->success($job, $success_message);
          $this->logger->info($this->transactionManagerLogger->getMessage('get', $job_id));
          $this->transactionManagerLogger->deleteMessage('get', $job_id);
          break;

        case TransactionTypes::INVOICE:
          // Here we need to check if this import is historical.
          if (isset($payload['historical_import'])) {
            $transaction['transaction']['historical_import'] = TRUE;
          }
          $success_message = $this->transactionManager->processUpdateForInvoice($transaction['transaction'], $job_id);
          $result = $this->success($job, $success_message);
          $this->logger->info($this->transactionManagerLogger->getMessage('get', $job_id));
          $this->transactionManagerLogger->deleteMessage('get', $job_id);
          break;

        default:
          $this->transactionManagerLogger->setMessage('get', $job_id, $this->t('Transaction was skipped. Transaction type - @type', ['@type' => $transaction['transaction']['transactionType']['transactionType']]));
          $this->logger->info($this->transactionManagerLogger->getMessage('get', $job_id));
          $this->transactionManagerLogger->deleteMessage('get', $job_id);
          $result = $this->success($job, $this->t('Transaction was skipped. Transaction type - @type', ['@type' => $transaction['transaction']['transactionType']['transactionType']]));
      }
      if ($result->getState() === JOB::STATE_FAILURE) {
        $message = $result->getMessage();
        $result = $this->failure($job, $message, AdministrativeNotificationsEvents::TRANSACTIONS_GET_SYNC, 'transaction', $transaction_id, $payload_json);
      }
      return $result;
    }
    catch (\Throwable $throwable) {
      $message = $this->t('Encountered exception when attempting to update transaction with Transaction ID: @tid. Message: @message.', [
        '@tid' => $transaction_id,
        '@message' => $throwable->getMessage(),
      ]);
      $this->transactionManagerLogger->setMessage('get', $job_id, $message);
      $this->logger->info($this->transactionManagerLogger->getMessage('get', $job_id));
      $this->transactionManagerLogger->deleteMessage('get', $job_id);
      return $this->failure($job, $message, AdministrativeNotificationsEvents::TRANSACTIONS_GET_SYNC, 'transaction', $transaction_id, $payload_json);
    }
  }

}
