<?php

namespace Drupal\ipc_syncdb\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\ipc_administrative_notifications\Event\AdministrativeNotificationsEvents;
use Drupal\ipc_syncdb\Entity\PostPayment;
use Drupal\ipc_syncdb\Utilities\IPCTransactionSync;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the job type for exporting payments to the Sync DB.
 *
 * @AdvancedQueueJobType(
 *   id = "ipc_syncdb_payment_post_transaction",
 *   label = @Translation("Export 'Payment' to SyncDB after an order is placed"),
 * )
 */
class SyncDbInvoicePaymentOrderPostTransaction extends SyncDbJobTypeBase {

  /**
   * Service for logging Query.
   *
   * @var \Drupal\ipc_syncdb\Logger\TransactionManagerLoggerInterface
   */
  protected $transactionManagerLogger;

  /**
   * The container.
   *
   * @var \Symfony\Component\DependencyInjection\ContainerInterface
   */
  protected $container;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->transactionManagerLogger = $container->get('ipc_syncdb.get_transaction_manager_logger');
    $instance->container = $container;
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function process(Job $job) {
    $payload = $job->getPayload();
    $payload_json = json_encode($payload);
    $job_id = $job->getId();
    $this->transactionManagerLogger->initLogger('post_payment', $job_id);
    if (empty($payload['order_id'])) {
      $message = $this->t('Payload order_id is missing.');
      return $this->failure($job, $message, AdministrativeNotificationsEvents::INVOICE_PAYMENT_SYNC, 'job', $job_id, $payload_json);
    }
    $order_id = $payload['order_id'];
    // Here in payload we need only Order with related invoices.
    $this->transactionManagerLogger->setMessage('post_payment', $job_id, 'Starting the process ...');
    $post_payment = PostPayment::create($order_id, $job_id, $this->container);
    try {
      $result = $post_payment->prepareObject();
    }
    catch (\Exception $exception) {
      $this->logger->info($this->transactionManagerLogger->getMessage('post_payment', $job_id));
      $this->transactionManagerLogger->deleteMessage('post_payment', $job_id);
      $message = $exception->getMessage();
      return $this->failure($job, $message, AdministrativeNotificationsEvents::INVOICE_PAYMENT_SYNC, 'job', $job_id, $payload_json);
    }
    try {
      $this->transactionManagerLogger->setMessage('post_payment', $job_id, 'Submitting the POST request.');
      $response = IPCTransactionSync::postPayment(new \stdClass(), $result, $order_id);
      $this->transactionManagerLogger->setMessage('post_payment', $job_id, "Response message - {$response['responseInfo']['responseMessage']}.");
      if ($response['responseInfo']['responseMessage'] === 'Success') {
        $post_payment->setSyncDbId($response['transactionId']);
        $this->transactionManagerLogger->setMessage('post_payment', $job_id, "Updating SyncDB - {$response['transactionId']} For Order - {$order_id}.");
        $this->logger->info($this->transactionManagerLogger->getMessage('post_payment', $job_id));
        $this->transactionManagerLogger->deleteMessage('post_payment', $job_id);

        return $this->success($job, 'Payment posted.');
      }
      $message = $this->t('Unexpected error posting payment.');
      return $this->failure($job, $message, AdministrativeNotificationsEvents::INVOICE_PAYMENT_SYNC, 'job', $job_id, $payload_json);
    }
    catch (\Exception | GuzzleException $e) {
      $this->transactionManagerLogger->setMessage('post_payment', $job_id, "Something goes wrong - {$e->getMessage()}.");
      $this->logger->info($this->transactionManagerLogger->getMessage('post_payment', $job_id));
      $this->transactionManagerLogger->deleteMessage('post_payment', $job_id);
      $this->messenger->addError($this->t('There was an Exception when attempting postTransaction API Call.'));
      $error_message = $e->getMessage();
      $this->logger->error($this->t('There was an Exception when attempting postPayment API Call. The status code is @responseCode @responseMessage.', [
        '@responseCode' => $e->getCode(),
        '@responseMessage' => $error_message,
      ]));
      $message = $e->getMessage();
      return $this->failure($job, $message, AdministrativeNotificationsEvents::INVOICE_PAYMENT_SYNC, 'job', $job_id, $payload_json);
    }
  }

}
