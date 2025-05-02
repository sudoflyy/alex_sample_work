<?php

namespace Drupal\ipc_syncdb\Entity;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\PaymentOrderUpdaterInterface;
use Drupal\commerce_paypal\Plugin\Commerce\PaymentGateway\PayflowInterface;
use Drupal\commerce_price\Calculator;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\ipc_syncdb\Logger\TransactionManagerLoggerInterface;
use Drupal\ipc_syncdb\Traits\TransactionCustomerTrait;
use Drupal\ipc_syncdb\Traits\TransactionDateTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The post payment class.
 */
class PostPayment extends PostTransaction {

  use TransactionCustomerTrait;

  use TransactionDateTrait;

  use StringTranslationTrait;

  /**
   * The payment order updater service.
   */
  protected PaymentOrderUpdaterInterface $paymentOrderUpdater;

  /**
   * The order entity.
   */
  protected ?OrderInterface $order;

  /**
   * Array for the Post call to the API.
   */
  protected array $postObject;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    string $entity_id,
    string $job_id,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerInterface $logger,
    TransactionManagerLoggerInterface $transaction_manager_logger,
    DateFormatterInterface $date_formatter,
    PaymentOrderUpdaterInterface $payment_order_updater
  ) {
    parent::__construct($entity_id, $job_id, $entity_type_manager, $logger, $transaction_manager_logger, $date_formatter);
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $this->entityTypeManager->getStorage('commerce_order')->load($this->entityID);
    $this->order = $order;
    $this->paymentOrderUpdater = $payment_order_updater;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(string $entity_id, string $job_id, ContainerInterface $container) {
    return new static(
      $entity_id,
      $job_id,
      $container->get('entity_type.manager'),
      $container->get('logger.channel.ipc_syncdb'),
      $container->get('ipc_syncdb.get_transaction_manager_logger'),
      $container->get('date.formatter'),
      $container->get('commerce_payment.order_updater')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function prepareObject(): string {
    $this->transactionManagerLogger->setMessage('post_payment', $this->jobID, "Loading the 'Payment Invoice' Order by ID - {$this->entityID}.");
    // Check if we have needed order and if this order of needed type.
    if ($this->order instanceof OrderInterface && $this->order->bundle() !== 'invoice_payment') {
      $message = $this->t("Sorry, the order does not exist or it is not of type 'Invoice Payment'");
      $this->transactionManagerLogger->setMessage('post_payment', $this->jobID, $message);
      $this->logger->info($this->transactionManagerLogger->getMessage('post_payment', $this->jobID));
      $this->transactionManagerLogger->deleteMessage('post_payment', $this->jobID);
      throw new \RuntimeException($message);
    }
    // We need to check if this order isPaid and we have info about payment.
    // If not we need to requeue this item.
    if ($this->paymentOrderUpdater->needsUpdate($this->order)) {
      $this->logger->info($this->transactionManagerLogger->getMessage('post_payment', $this->jobID));
      $this->transactionManagerLogger->deleteMessage('post_payment', $this->jobID);

      throw new \RuntimeException("Order ID - {$this->entityID} is waiting for payment update.");
    }

    $this->transactionManagerLogger->setMessage('post_payment', $this->jobID, "Preparing default values for the Post call.");
    $this->setDefaults();
    $this->transactionManagerLogger->setMessage('post_payment', $this->jobID, 'Default Json for this Order:' . Json::encode($this->postObject));
    $this->transactionManagerLogger->setMessage('post_payment', $this->jobID, "Preparing Invoices ...");
    $this->prepareInvoices();
    $this->transactionManagerLogger->setMessage('post_payment', $this->jobID, 'Added Invocies to the Json:' . Json::encode($this->postObject['applyTo']));
    $this->transactionManagerLogger->setMessage('post_payment', $this->jobID, "Preparing Payment info ...");
    $this->preparePaymentAuth();
    $result_json = json_encode($this->postObject, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    $this->transactionManagerLogger->setMessage('post_payment', $this->jobID, "Prepared Json - {$result_json}");
    return $result_json;
  }

  /**
   * {@inheritdoc}
   */
  protected function setDefaults(): void {
    $this->postObject = [
      'type' => 'payment',
      'externalId' => $this->order->id(),
      'externalTransactionNumber' => $this->order->getOrderNumber(),
      'nsCustomerId' => $this->getCustomerId($this->order),
      'nsEndUserId' => $this->getEndUserId($this->order),
      'customerIsUser' => !$this->getOrderCustomerPrimaryCompany($this->order),
      'customerId' => NULL,
      'endUserId' => NULL,
      'transDate' => $this->getTransactionDate($this->order),
      'currency' => 'US Dollar',
      'exchangeRate' => 1,
      'status' => 'Deposited',
      'otherRefNum' => $this->order->get('your_reference')->value,
      'total' => $this->order->getTotalPrice() ? Calculator::trim($this->order->getTotalPrice()
        ->getNumber()) : 0,
      'ccPaymentAuth' => '',
      'ccPaidAmount' => Calculator::trim($this->order->getTotalPrice()
        ->getNumber()),
      'applyTo' => [],
    ];
  }

  /**
   * Helper function to set SyncDB ID.
   *
   * @param string $syncdb_id
   *   The syncdb_id from api.
   */
  public function setSyncDbId(string $syncdb_id): void {
    $this->order->set('syncdb_id', $syncdb_id);
    $this->order->save();
  }

  /**
   * Helper function to prepare Invocies.
   */
  protected function prepareInvoices(): void {
    $order_items = $this->order->getItems();
    foreach ($order_items as $item) {
      /** @var \Drupal\commerce_invoice_payment\Entity\Invoice $invoice */
      $invoice = $item->getPurchasedEntity();
      $invoice_data = [
        'transactionId' => $invoice->get('syncdb_id')->value ?? '',
        'nsTransactionId' => $invoice->get('netsuite_id')->value ?? '',
        'externalTransactionNumber' => $invoice->get('netsuite_order_number')->value ?? '',
        'documentNumber' => $invoice->get('reference_number')->value ?? '',
        'amount' => Calculator::trim($invoice->getTotalPrice()->getNumber()),
      ];
      $this->postObject['applyTo'][] = $invoice_data;
    }
  }

  /**
   * Helper function to prepare info about payment.
   */
  protected function preparePaymentAuth(): void {
    /** @var \Drupal\commerce_payment\PaymentStorageInterface $commerce_payment_storage */
    $commerce_payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $order_payments = $commerce_payment_storage->loadMultipleByOrder($this->order);
    if (!empty($order_payments)) {
      /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
      $payment = reset($order_payments);
      $remote_id = $payment->getRemoteId();
      if ($payment->getPaymentGateway() instanceof PayflowInterface) {
        $remote_id = (strpos($remote_id, '|') !== FALSE) ? explode('|', $remote_id)[1] : $remote_id;
      }
      $this->postObject['ccPaymentAuth'] = $remote_id;
    }
  }

}
