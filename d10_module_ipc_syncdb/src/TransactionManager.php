<?php

namespace Drupal\ipc_syncdb;

use Drupal\advancedqueue\Job;
use Drupal\commerce_invoice\Entity\Invoice;
use Drupal\commerce_invoice\Entity\InvoiceInterface;
use Drupal\commerce_invoice\Entity\InvoiceItem;
use Drupal\commerce_invoice\Entity\InvoiceItemInterface;
use Drupal\commerce_invoice\Entity\InvoiceType;
use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\AdjustmentTransformerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\PaymentOrderUpdater;
use Drupal\commerce_paypal\Plugin\Commerce\PaymentGateway\PayflowInterface;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\State\State;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\ipc_commerce_avatax\Plugin\Commerce\TaxType\IpcAvatax;
use Drupal\ipc_syncdb\Enums\LineItemCategory;
use Drupal\ipc_syncdb\Enums\SalesOrderTerms;
use Drupal\ipc_syncdb\Enums\TransactionStatuses;
use Drupal\ipc_syncdb\Enums\TransactionTypes;
use Drupal\ipc_syncdb\Logger\TransactionManagerLoggerInterface;
use Drupal\ipc_syncdb\Traits\TransactionAssignToGroupTrait;
use Drupal\ipc_syncdb\Traits\TransactionCustomerTrait;
use Drupal\ipc_syncdb\Traits\TransactionDateTrait;
use Drupal\ipc_syncdb\Traits\TransactionProfileTrait;
use Drupal\ipcsync\Api;
use Drupal\ipcsync\Utilities\Transaction;
use Drupal\profile\Entity\Profile;
use Psr\Log\LoggerInterface;

/**
 * Handles IPCTransactionAPI integration for transactions and orders.
 */
class TransactionManager {

  use StringTranslationTrait;
  use TransactionCustomerTrait;
  use TransactionDateTrait;
  use TransactionProfileTrait;
  use TransactionAssignToGroupTrait;

  /**
   * Price level: nonmember.
   */
  public const NONMEMBER_PRICE_LEVEL = 1;

  /**
   * Price level: member.
   */
  public const MEMBER_PRICE_LEVEL = 2;

  /**
   * Price level: distributor.
   */
  public const DISTRIBUTOR_PRICE_LEVEL = 3;

  /**
   * Bill to tier: 1.
   */
  public const BILL_TO_TIER_ONE = 1;

  /**
   * Bill to tier: 3.
   */
  public const BILL_TO_TIER_THREE = 3;

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
   * The State service.
   *
   * @var \Drupal\Core\State\State
   */
  protected $state;

  /**
   * The adjustment transformer.
   *
   * @var \Drupal\commerce_order\AdjustmentTransformerInterface
   */
  protected $adjustmentTransformer;

  /**
   * The user importer.
   *
   * @var \Drupal\ipc_syncdb\UserImporter
   */
  protected $userImporter;

  /**
   * The company importer.
   *
   * @var \Drupal\ipc_syncdb\CompanyImporter
   */
  protected $companyImporter;

  /**
   * The payment order updater service.
   *
   * @var \Drupal\commerce_payment\PaymentOrderUpdater
   */
  protected $paymentOrderUpdater;

  /**
   * Service for logging Query.
   *
   * @var \Drupal\ipc_syncdb\Logger\TransactionManagerLoggerInterface
   */
  protected $transactionManagerLogger;

  /**
   * The price splitter service.
   *
   * @var \Drupal\ipc_syncdb\InvoicePriceSplitterInterface
   */
  protected $invoicePriceSplitter;

  /**
   * The product importer.
   *
   * @var \Drupal\ipc_syncdb\ProductImporter
   */
  protected $productImporter;

  /**
   * Constructs a new TransactionSubscriber object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config Factory service.
   * @param \Drupal\Core\State\State $state
   *   The State service.
   * @param \Drupal\commerce_order\AdjustmentTransformerInterface $adjustment_transformer
   *   The adjustment transformer.
   * @param \Drupal\ipc_syncdb\UserImporter $user_importer
   *   The user importer.
   * @param \Drupal\ipc_syncdb\CompanyImporter $company_importer
   *   The company importer.
   * @param \Drupal\commerce_payment\PaymentOrderUpdater $payment_order_updater
   *   The payment order updater service.
   * @param \Drupal\ipc_syncdb\Logger\TransactionManagerLoggerInterface $transaction_manager_logger
   *   Service for logging queue.
   * @param \Drupal\ipc_syncdb\InvoicePriceSplitterInterface $invoice_price_splitter
   *   The invoice price splitter service.
   * @param \Drupal\ipc_syncdb\ProductImporter $product_importer
   *   The product importer.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, DateFormatterInterface $date_formatter, MessengerInterface $messenger, LoggerInterface $logger, ConfigFactoryInterface $config_factory, State $state, AdjustmentTransformerInterface $adjustment_transformer, UserImporter $user_importer, CompanyImporter $company_importer, PaymentOrderUpdater $payment_order_updater, TransactionManagerLoggerInterface $transaction_manager_logger, InvoicePriceSplitterInterface $invoice_price_splitter, ProductImporter $product_importer) {
    $this->entityTypeManager = $entity_type_manager;
    $this->dateFormatter = $date_formatter;
    $this->messenger = $messenger;
    $this->logger = $logger;
    $this->configFactory = $config_factory;
    $this->state = $state;
    $this->adjustmentTransformer = $adjustment_transformer;
    $this->userImporter = $user_importer;
    $this->companyImporter = $company_importer;
    $this->paymentOrderUpdater = $payment_order_updater;
    $this->transactionManagerLogger = $transaction_manager_logger;
    $this->invoicePriceSplitter = $invoice_price_splitter;
    $this->productImporter = $product_importer;
  }

  /**
   * Does a postTransaction API call to IPC Transaction Api.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\commerce_invoice_payment\Entity\Invoice|null $invoice
   *   The invoice, which exists for Quote transactions.
   * @param null|string $job_id
   *   The current job id.
   *
   * @return string
   *   Result.
   */
  public function postTransaction(OrderInterface $order, Invoice $invoice = NULL, string $job_id = NULL): string {
    $config = $this->configFactory->get('ipc_syncdb.settings');
    $status = 'failure';
    if (!$config->get('export_orders_to_syncdb')) {
      return $status;
    }
    if ($job_id === NULL) {
      $job_id = 'order-id-' . $order->id();
    }
    $this->transactionManagerLogger->setMessage('post', $job_id, 'Checking if everything is good with order\'s owner.');
    if (!$this->checkThatUserHasCustomerId($order)) {
      // If the customer is a user and the user does not have a SyncDB ID
      // the transaction will be skipped and re-added to the queue.
      $user = $order->getCustomer();
      $this->transactionManagerLogger->setMessage('post', $job_id, "The customer {$user->get('mail')->value} is a user who does not have a value set for SyncDB ID.");
      // Enqueue the user for import so that SyncDB ID can be pulled in.
      $this->userImporter->enqueueUserForImportByEmail($user->get('mail')->value);
      return 'skipped_user_customer_id';
    }
    $this->transactionManagerLogger->setMessage('post', $job_id, 'Starting to work on json for Post Transaction.');
    $json = $this->createJsonForPostTransaction($order, $invoice);
    $this->transactionManagerLogger->setMessage('post', $job_id, "Json created - <pre>{$json}</pre>");
    if ($json === 'skip_post_transaction_for_quote_without_ns_id') {
      $this->transactionManagerLogger->setMessage('post', $job_id, 'Quote does not have SyncDB ID.');
      return 'skipped_quote_without_ns_id';
    }
    $requestParams = new \stdClass();
    try {
      $this->transactionManagerLogger->setMessage('post', $job_id, 'Submitting the POST request.');
      $response = Transaction::postTransaction($requestParams, $json, $order->id());
      $this->transactionManagerLogger->setMessage('post', $job_id, "Response message - {$response['responseInfo']['responseMessage']}.");
      if ($response['responseInfo']['responseMessage'] === 'Success') {
        $order_state = $order->getState()->getValue()['value'];
        // If order state quoted this mean that we created only Quote.
        if ($order_state === 'quoted') {
          $invoice->set('syncdb_id', $response['transactionId']);
          $invoice->save();
          $this->transactionManagerLogger->setMessage('post', $job_id, "Updating SyncDB - {$response['transactionId']} For Quote - {$invoice->id()}.");
        }
        else {
          $order->set('syncdb_id', $response['transactionId']);
          $order->save();
          $this->transactionManagerLogger->setMessage('post', $job_id, "Updating SyncDB - {$response['transactionId']} For Order - {$order->id()}.");
        }
        $status = 'success';
      }
    }
    catch (\Exception $e) {
      $this->transactionManagerLogger->setMessage('post', $job_id, "Something goes wrong - {$e->getMessage()}.");
      $this->logger->info($this->transactionManagerLogger->getMessage('post', $job_id));
      $this->transactionManagerLogger->deleteMessage('post', $job_id);
      $this->messenger->addError($this->t('There was an Exception when attempting postTransaction API Call.'));
      $error_message = $e->getMessage();
      $this->logger->error($this->t('There was an Exception when attempting postTransaction API Call. The status code is @responseCode @responseMessage.', [
        '@responseCode' => $e->getCode(),
        '@responseMessage' => $error_message,
      ]));
    }

    return $status;
  }

  /**
   * Helper function to get JSON containing data for transaction.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\commerce_invoice_payment\Entity\Invoice|null $invoice
   *   The invoice, which exists for Quote transactions.
   *
   * @return false|string
   *   The JSON-encoded string containing data.
   */
  public function getJsonForPostTransaction(OrderInterface $order, Invoice $invoice = NULL) {
    return $this->createJsonForPostTransaction($order, $invoice);
  }

  /**
   * Helper function to create JSON containing data for transaction.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\commerce_invoice_payment\Entity\Invoice|null $invoice
   *   The invoice, which exists for Quote transactions.
   *
   * @return false|string
   *   The JSON-encoded string containing data.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  protected function createJsonForPostTransaction(OrderInterface $order, Invoice $invoice = NULL) {
    if ($invoice) {
      $external_id = $invoice->id();
      $external_transaction_number = $invoice->getInvoiceNumber();
    }
    else {
      $external_id = $order->id();
      $external_transaction_number = $order->getOrderNumber();
    }
    $payment_gateway = $order->get('payment_gateway')->getValue();
    $payment_gateway = !empty($payment_gateway) ? $payment_gateway[0]['target_id'] : NULL;
    $order_state = $order->getState()->getValue()['value'];

    $required_fields = [
      'externalId' => $external_id,
      'externalTransactionNumber' => $external_transaction_number,
      // Netsuite ID of the customer company (entity).
      'nsCustomerId' => $this->getCustomerId($order),
      // Netsuite ID of the end user.
      'nsEndUserId' => $this->getEndUserId($order),
      // The date/time of the transaction.
      'transDate' => $this->getTransactionDate($order),
      // The exchange rate is 1 for all USD transactions.
      'currency' => 'US Dollar',
      'exchangeRate' => 1,
      'billingAddress' => $this->getBillingAddress($order),
      'shippingAddress' => $this->getShippingAddress($order),
      'lineItems' => $this->getLineItems($order),
      'billToTierId' => $this->getBillToTierId($order),
    ];

    if ($order->getSubtotalPrice() !== NULL) {
      $subtotal_price = $order->getSubtotalPrice();

      $order_items = $order->getItems();
      foreach ($order_items as $order_item) {
        foreach ($order_item->getAdjustments(['ipc_discount']) as $adjustment) {
          $subtotal_price = $subtotal_price->add($adjustment->getAmount());
        }
      }

      $required_fields['subtotal'] = Calculator::trim($subtotal_price->getNumber());
    }

    if ($order->getTotalPrice() !== NULL) {
      $required_fields['total'] = Calculator::trim($order->getTotalPrice()->getNumber());
    }

    $this->addShippingHandlingFields($required_fields, $order);
    $this->addCustomsFields($required_fields, $order);
    $this->addTaxTotalField($required_fields, $order);
    $this->addCopyToAddressBookFields($required_fields, $order);

    if ($this->getPriceLevel($order) === self::DISTRIBUTOR_PRICE_LEVEL) {
      $required_fields['nsDistributorId'] = $this->getCustomerId($order);
    }

    // Here we need to check if order status is quoted.
    if ($order_state === 'quoted') {
      // Add fields specific to Quotes.
      $required_fields['type'] = 'estimate';
      $required_fields['orderStatus'] = 'Pending Approval';
      if ($po_number_reference = $order->get('your_reference')->getValue()) {
        $required_fields['otherRefNum'] = $po_number_reference[0]['value'];
      }
    }
    // Check if order bundle is Quote Purchase.
    if (
      $order->bundle() === 'quote_purchase' &&
      $order->hasField('created_from') &&
      !$order->get('created_from')->isEmpty()
    ) {
      // Get quote related to order.
      $quote = $order->get('created_from')->entity;
      if (
        $quote instanceof InvoiceInterface &&
        !$quote->get('netsuite_id')->isEmpty()
      ) {
        $required_fields['createdFromTransactionId'] = $quote->get('syncdb_id')->value;
        $required_fields['nsCreatedFromTransactionId'] = $quote->get('netsuite_id')->value;
      }
      else {
        return 'skip_post_transaction_for_quote_without_ns_id';
      }
    }

    if ($payment_gateway === 'ipc_purchase_orders') {
      // Add fields specific to orders paid by Purchase Order.
      $this->addPurchaseOrderFields($required_fields, $order);
    }
    elseif ($order->getTotalPrice()->isZero() || !empty($payment_gateway)) {
      // Add fields specific to Credit Card orders.
      $this->addCcPaymentFields($required_fields, $order);
    }
    $required_fields['customerIsUser'] = !$this->getOrderCustomerPrimaryCompany($order);

    return Json::encode($required_fields);
  }

  /**
   * Add fields related to shipping and handling.
   *
   * @param array $fields
   *   Associative array of fields.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function addShippingHandlingFields(array &$fields, OrderInterface $order): void {
    // Fix 'ValidatePostAddresses:Shipping Address NOT Found'.
    if (empty($fields['shippingAddress'])) {
      $fields['shippingAddress'] = $fields['billingAddress'];
    }

    if (
      $order->hasField('shipments') &&
      $shipments = $order->get('shipments')->referencedEntities()
    ) {
      $shipment = reset($shipments);

      if ($shipment->getOriginalAmount()) {
        $fields['shipCost'] = $shipment->getOriginalAmount()->getNumber() ? Calculator::trim($shipment->getOriginalAmount()->getNumber()) : '0';

        /** @var \Drupal\commerce_order\Adjustment $adjustment */
        foreach ($shipment->getAdjustments() as $adjustment) {
          $type = $adjustment->getType();
          $label = $adjustment->getLabel();
          $adjustment_amount = $adjustment->getAmount()->getNumber() ? Calculator::trim($adjustment->getAmount()->getNumber()) : '0';
          $tax_rate_percentage = $adjustment->getPercentage();
          $tax_rate = $tax_rate_percentage ? Calculator::trim(Calculator::multiply($tax_rate_percentage, 100)) : '0';

          if ($type === 'handling_fee') {
            $fields['handlingCost'] = $adjustment_amount;
          }
          if ($type === 'tax') {
            if ($label === IpcAvatax::HANDLING_FEE_TAX_ADJ_LABEL) {
              $fields['handlingTaxAmount'] = $adjustment_amount;
              $fields['handlingTaxRate'] = $tax_rate;
            }
            else {
              $fields['shipTaxAmount'] = $adjustment_amount;
              $fields['shipTaxRate'] = $tax_rate;
            }
          }
        }

        $shipping_method = $shipment->getShippingMethod();
        $ns_shipping_method_id = $shipping_method->get('ns_shipping_method_id')->getValue();
        if (isset($ns_shipping_method_id[0]['value'])) {
          $fields['nsShipMethodId'] = $ns_shipping_method_id[0]['value'];
        }

        $shipping_account_number = $shipment->get('shipping_account_number')->getValue();
        if (isset($shipping_account_number[0]['value'])) {
          $fields['shippingAccount'] = $shipping_account_number[0]['value'];
        }
      }
      else {
        $this->logger->warning('ORDER SYNC FAIL - get shipment amount');
      }
    }
  }

  /**
   * Gets the Purchase Order fields.
   *
   * @param array $fields
   *   Associative array of fields.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   */
  protected function addPurchaseOrderFields(array &$fields, OrderInterface $order): void {
    $fields['type'] = 'purchaseorder';
    $fields['orderStatus'] = 'Pending Approval';

    $payment_method_value = $order->get('payment_method')->getValue();
    $payment_method_id = $payment_method_value[0]['target_id'];
    $payment_method_storage = $this->entityTypeManager->getStorage('commerce_payment_method');
    /** @var  \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method */
    $payment_method = $payment_method_storage->load($payment_method_id);

    $po_number = $payment_method->get('po_number')->getValue();
    $po_number = $po_number[0]['value'];
    $po_number = strlen($po_number) > 45 ? substr($po_number, 0, 45) : $po_number;
    $po_billing_email = $payment_method->get('po_billing_email')->getValue();
    $po_billing_email = $po_billing_email[0]['value'];
    $po_additional_info = $payment_method->get('po_additional_info')->getValue();
    $po_additional_info = $po_additional_info[0]['value'];

    $po_file_value = $payment_method->get('po_file')->getValue();
    $po_file_id = $po_file_value[0]['target_id'];
    $file_storage = $this->entityTypeManager->getStorage('file');
    $po_file = $file_storage->load($po_file_id);
    $po_filename = $po_file->getFilename();

    $fields = array_merge($fields, [
      'otherRefNum' => $po_number,
      'purchaseOrderFileName' => $po_filename,
      'billingContactEmail' => $po_billing_email,
      'billingInstructions' => $po_additional_info,
      'termsId' => $this->getTermsIdField($order),
    ]);
  }

  /**
   * Add fields related to international shipping and customs information.
   *
   * @param array $fields
   *   Associative array of fields.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   */
  protected function addCustomsFields(array &$fields, OrderInterface $order): void {
    if (
      $order->hasField('shipments') &&
      $shipments = $order->get('shipments')->referencedEntities()
    ) {
      /** @var \Drupal\commerce_shipping\Entity\Shipment $shipment */
      $shipment = reset($shipments);

      if (!$shipment->get('customs_note')->isEmpty()) {
        $fields['includeEPOnlyNote'] = (bool) $shipment->get('customs_note')->value;
      }
      if (!$shipment->get('customs_include_tax_id')->isEmpty()) {
        $fields['includeVATTaxFiscalId'] = (bool) $shipment->get('customs_include_tax_id')->value;
      }
      if (!$shipment->get('customs_tax_id')->isEmpty()) {
        $fields['vatTaxFiscalId'] = $shipment->get('customs_tax_id')->value;
      }
    }
  }

  /**
   * Add Credit Card payment fields to the request.
   *
   * @param array $fields
   *   Associative array of fields.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function addCcPaymentFields(array &$fields, OrderInterface $order): void {
    $fields['type'] = 'creditcardorder';
    $fields['orderStatus'] = 'Pending Fulfillment';

    $other_ref_num = $order->hasField('your_reference') ? $order->get('your_reference')->value : NULL;
    $fields['otherRefNum'] = strlen($other_ref_num) > 45 ? substr($other_ref_num, 0, 45) : $other_ref_num;

    // @todo Add support for gift certificate (it will change the $cc_paid_amount).
    $cc_paid_amount = Calculator::trim($order->getTotalPrice()->getNumber());
    $fields['ccPaidAmount'] = $cc_paid_amount;

    /** @var \Drupal\commerce_payment\PaymentStorageInterface $order_payment_storage */
    $order_payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $order_payments = $order_payment_storage->loadMultipleByOrder($order);
    if (!empty($order_payments)) {
      /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
      $payment = reset($order_payments);
      $remote_id = $payment->getRemoteId();
      if ($payment->getPaymentGateway() instanceof PayflowInterface) {
        // See Drupal\commerce_paypal\Plugin\Commerce\PaymentGateway\Payflow::getAuthorizationCode.
        $remote_id = (strpos($remote_id, '|') !== FALSE) ? explode('|', $remote_id)[1] : $remote_id;
      }
      $fields = array_merge($fields, [
        'ccPaymentAuth' => $remote_id,
        'ccPaymentAmount' => Calculator::trim($payment->getAmount()->getNumber()),
        'ccPaymentHold' => FALSE,
      ]);
    }
  }

  /**
   * Add fields related to shipping and handling.
   *
   * @param array $fields
   *   Associative array of fields.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   */
  protected function addTaxTotalField(array &$fields, OrderInterface $order): void {
    $fields['taxTotal'] = 0;
    $tax_amount = new Price('0', 'USD');
    $adjustments = $order->collectAdjustments(['tax', 'rdf']);
    if ($adjustments) {
      $combined_adjustments = $this->adjustmentTransformer->combineAdjustments($adjustments);
      foreach ($combined_adjustments as $adjustment) {
        $tax_amount = $tax_amount->add($adjustment->getAmount());
      }

      $fields['taxTotal'] = Calculator::round($tax_amount->getNumber(), 2);
    }
  }

  /**
   * Add fields related to copy_to_address_book fields for profiles.
   *
   * @param array $fields
   *   Associative array of fields.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   */
  protected function addCopyToAddressBookFields(array &$fields, OrderInterface $order): void {
    $billing_profile = $order->getBillingProfile();
    if ($billing_profile && $billing_profile->getData('address_book_profile_id')) {
      $fields['addBillingAddressToCustomer'] = TRUE;
    }
    $profiles = $order->collectProfiles();
    if (!empty($profiles['shipping'])) {
      $shipping_profile = $profiles['shipping'];
      if ($shipping_profile && $shipping_profile->getData('address_book_profile_id')) {
        $fields['addShippingAddressToCustomer'] = TRUE;
      }
    }
  }

  /**
   * Compute the value for the field - termsId.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   */
  protected function getTermsIdField(OrderInterface $order): string {
    if ($group = $this->getOrderCustomerPrimaryCompany($order)) {
      if (
        $group->hasField('payment_terms') &&
        !$group->get('payment_terms')->isEmpty()
      ) {
        return SalesOrderTerms::getApiSalesOrderTerm($group->get('payment_terms')->value);
      }
    }

    return '';
  }

  /**
   * Gets priceLevelId.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return int
   *   Value for 'priceLevelId' in lineItem.
   */
  protected function getPriceLevel(OrderInterface $order): int {
    $user = $order->getCustomer();
    $mapping = [
      'nonmember' => self::NONMEMBER_PRICE_LEVEL,
      'member' => self::MEMBER_PRICE_LEVEL,
      'distributor' => self::DISTRIBUTOR_PRICE_LEVEL,
    ];
    if ($company_group = $this->getOrderCustomerPrimaryCompany($order)) {
      $price_level_field = $company_group->get('price_level');
    }
    else {
      $price_level_field = $user->get('price_level');
    }
    if (!$price_level_field->isEmpty()) {
      return $mapping[$price_level_field->value];
    }

    return 1;
  }

  /**
   * Returns mapping for the address fields.
   *
   * @return array
   *   SyncDB field name => Drupal address field
   */
  protected function getAddressFieldsMapping(): array {
    return [
      'address1' => 'address_line1',
      'address2' => 'address_line2',
      'city' => 'locality',
      'countryOrRegion' => 'country_code',
      'postalCode' => 'postal_code',
      'stateOrProvince' => 'administrative_area',
      'addressee' => 'organization',
    ];
  }

  /**
   * Gets billingAddress.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array|null
   *   Value for 'billingAddress' in PostTransaction.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  protected function getBillingAddress(OrderInterface $order): ?array {
    $billing_profile = $order->getBillingProfile();
    if (!$billing_profile || $billing_profile->get('address')->isEmpty()) {
      return NULL;
    }
    $billing_address = [];
    $address = $billing_profile->get('address')->first()->getValue();
    foreach ($this->getAddressFieldsMapping() as $sync_db_field => $profile_address_field) {
      $billing_address[$sync_db_field] = $address[$profile_address_field] ?? '';
    }
    $billing_address['defaultBillingAddress'] = $billing_profile->isDefault();
    $phone_number = $billing_profile->get('phone_number')->getValue();
    if (isset($phone_number[0]['value'])) {
      $billing_address['phone'] = $phone_number[0]['value'];
    }

    $address_attention_to = $billing_profile->get('address_attention_to')
      ->getValue();
    if (isset($address_attention_to[0]['value'])) {
      $billing_address['attention'] = $address_attention_to[0]['value'];
    }

    return $billing_address;
  }

  /**
   * Gets shippingAddress.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array
   *   Value for 'shippingAddress' in PostTransaction.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  protected function getShippingAddress(OrderInterface $order): array {
    $profiles = $order->collectProfiles();
    if (empty($profiles['shipping'])) {
      return [];
    }
    $shipping_profile = $profiles['shipping'];
    if ($shipping_profile->get('address')->isEmpty()) {
      return [];
    }
    $shipping_address = [];
    $address = $shipping_profile->get('address')->first()->getValue();
    foreach ($this->getAddressFieldsMapping() as $sync_db_field => $profile_address_field) {
      $shipping_address[$sync_db_field] = $address[$profile_address_field] ?? '';
    }
    $shipping_address['defaultShippingAddress'] = $shipping_profile->isDefault();

    $phone_number = $shipping_profile->get('phone_number')->getValue();
    if (isset($phone_number[0]['value'])) {
      $shipping_address['phone'] = $phone_number[0]['value'];
    }

    $address_attention_to = $shipping_profile->get('address_attention_to')->getValue();
    if (isset($address_attention_to[0]['value'])) {
      $shipping_address['attention'] = $address_attention_to[0]['value'];
    }

    return $shipping_address;
  }

  /**
   * Gets lineItems.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array
   *   Value for 'lineItems' in PostTransaction.
   */
  protected function getLineItems(OrderInterface $order): array {
    $line_items = [];
    $price_level = $this->getPriceLevel($order);

    $line_seq = 1;
    $transaction_line_items = [];
    foreach ($order->getItems() as $item) {
      if ($purchased_entity = $item->getPurchasedEntity()) {
        $syncdb_product_id = !$purchased_entity->get('syncdb_id')
          ->isEmpty() ? $purchased_entity->get('syncdb_id')->value : '';
        $ns_product_id = !$purchased_entity->get('netsuite_id')
          ->isEmpty() ? $purchased_entity->get('netsuite_id')->value : '';
        $product_number = !$purchased_entity->get('sku')
          ->isEmpty() ? $purchased_entity->get('sku')->value : '';
      }
      else {
        $syncdb_product_id = !$item->get('product_id')
          ->isEmpty() ? $item->get('product_id')->value : '';
        $ns_product_id = !$item->get('ns_product_id')
          ->isEmpty() ? $item->get('ns_product_id')->value : '';
        $product_number = !$item->get('product_number')
          ->isEmpty() ? $item->get('product_number')->value : '';
      }

      if (empty($syncdb_product_id) || empty($ns_product_id)) {
        continue;
      }

      /** @var \Drupal\commerce_price\Price $tax_price */
      $tax_price = NULL;
      $tax_rate = 0;

      foreach ($item->getAdjustments(['tax', 'rdf']) as $adjustment) {

        $tax_price = $tax_price ? $tax_price->add($adjustment->getAmount()) : $adjustment->getAmount();

        // Since we are only expecting a single sales tax adjustment,
        // this code works to get the taxRate for the line item.
        $tax_rate_percentage = $adjustment->getPercentage();
        $tax_rate = $tax_rate_percentage ? Calculator::multiply($tax_rate_percentage, 100) : 0;
      }
      $tax_amount = $tax_price ? Calculator::trim($tax_price->getNumber()) : 0;
      // Special rule for discount item.
      $rate = $item->getUnitPrice()->getNumber();
      if (
        $item->hasField('discount_item') &&
        $item->hasField('discount_rate') &&
        $item->get('discount_item')->value &&
        !empty($item->get('discount_rate')->value)
      ) {
        $rate = $item->get('discount_rate')->value;
      }

      $line_items[] = [
        'externalLineId' => $item->id(),
        'externalLineSeq' => $line_seq,
        'nsProductId' => $ns_product_id,
        'productId' => $syncdb_product_id,
        'productNumber' => $product_number,
        'quantity' => (int) $item->getQuantity(),
        'rate' => Calculator::trim($rate),
        'amount' => Calculator::trim($item->getTotalPrice()->getNumber()),
        'nsPriceLevelId' => $price_level,
        'priceLevelId' => $price_level,
        'taxAmount' => $tax_amount,
        'taxRate' => Calculator::trim($tax_rate),
      ];

      // Increase sequence manually.
      $line_seq++;

      foreach ($item->getAdjustments([
        'ipc_discount',
        'ipc_transaction_discount',
      ]) as $adjustment) {
        $source_id = $adjustment->getSourceId();
        $adjustment_data = unserialize($source_id, ['allowed_classes' => FALSE]);

        if ($adjustment->getType() === 'ipc_discount') {
          $line_items[] = [
            'externalLineId' => NULL,
            'externalLineSeq' => $line_seq,
            'nsProductId' => $adjustment_data['nsProductId'],
            'productId' => $adjustment_data['productId'],
            'productNumber' => $adjustment_data['productNumber'],
            'quantity' => (int) $adjustment_data['quantity'],
            'rate' => Calculator::trim($adjustment_data['rate']),
            'amount' => Calculator::trim($adjustment_data['amount']),
            'nsPriceLevelId' => $price_level,
            'priceLevelId' => $price_level,
            'taxAmount' => NULL,
            'taxRate' => NULL,
            'discountType' => $adjustment_data['discountType'],
            'transactionDiscount' => $adjustment_data['transactionDiscount'],
          ];
          // Increase sequence manually.
          $line_seq++;
        }
        else {
          if (!empty($transaction_line_items[$adjustment_data['nsProductId']])) {
            continue;
          }
          $transaction_line_items[$adjustment_data['nsProductId']] = [
            'externalLineId' => NULL,
            'nsProductId' => $adjustment_data['nsProductId'],
            'productId' => $adjustment_data['productId'],
            'productNumber' => $adjustment_data['productNumber'],
            'quantity' => (int) $adjustment_data['quantity'],
            'rate' => Calculator::trim($adjustment_data['rate']),
            'amount' => Calculator::trim($adjustment_data['amount']),
            'nsPriceLevelId' => $price_level,
            'priceLevelId' => $price_level,
            'taxAmount' => NULL,
            'taxRate' => NULL,
            'discountType' => $adjustment_data['discountType'],
            'transactionDiscount' => $adjustment_data['transactionDiscount'],
            'nsPromotionId' => $adjustment_data['promotion']['nsPromotionId'] ?? NULL,
          ];
        }
      }
    }

    // Add Transaction Line Items and Promotion Items.
    foreach ($transaction_line_items as $transaction_line_item) {
      $transaction_line_item['externalLineSeq'] = $line_seq++;
      $line_items[] = $transaction_line_item;
    }

    return $line_items;
  }

  /**
   * Gets billToTierId.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return int
   *   Value for 'billToTierId' in PostTransaction.
   */
  protected function getBillToTierId(OrderInterface $order): int {
    return ($this->getPriceLevel($order) === self::DISTRIBUTOR_PRICE_LEVEL) ? self::BILL_TO_TIER_THREE : self::BILL_TO_TIER_ONE;
  }

  /**
   * Gets transaction by it's ID from SyncDB.
   *
   * @param string $transaction_id
   *   Transaction ID.
   *
   * @return array
   *   Response.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function getTransaction(string $transaction_id): array {
    $url = Api::getApiEndpoint('IPCTransactionAPI') . '/transaction/GetTransaction?transactionId=' . $transaction_id;
    return Api::connectApi('GET', $url);
  }

  /**
   * Returns mapping of SyncDB transaction status and order state.
   *
   * @return array
   *   Associative array 'SyncDB transaction status' => 'commerce order status'.
   */
  protected function getTransactionOrderStatusMapping(): array {
    return [
      'Pending Approval' => 'pending',
      'Pending Billing' => 'fulfillment',
      'Pending Fulfillment' => 'fulfillment',
      'Pending Billing/Partially Fulfilled' => 'fulfillment',
      'Partially Fulfilled' => 'fulfillment',
      'Billed' => 'completed',
      'Closed' => 'canceled',
      'Cancelled' => 'canceled',
    ];
  }

  /**
   * Query SyncDB for all orders that have been updated since last run.
   *
   * @param array $params
   *   Modified on after param.
   *
   * @return array
   *   Return nothing or array.
   */
  public function getUpdatedTransactionIdsFromSyncDb(array $params): array {
    $config = $this->configFactory->get('ipc_syncdb.settings');
    if (!$config->get('import_orders_from_syncdb')) {
      return [];
    }
    $run_time = ApiHelper::getRunTimeDateTimeString();
    $transactions_to_update = [];

    $requestVariables = new \stdClass();
    if (!empty($params['modified_on_after'])) {
      $modifiedOnAfter = $params['modified_on_after'];
    }
    else {
      $last_run_time = $this->state->get('ipcsync_transaction_get_sync_last_run');
      $modifiedOnAfter = $last_run_time ?: ApiHelper::POLLING_ROUTINE_START_TIME;
    }
    $requestVariables->modifiedOnAfter = $modifiedOnAfter;

    $transaction_types = $params['transaction_type_id'] !== 'all' ? [$params['transaction_type_id']] : TransactionTypes::supportedTransactionTypes();
    foreach ($transaction_types as $transaction_type_id) {
      $requestedPage = 1;
      $requestVariables->requestedPage = $requestedPage;
      $requestVariables->transactionTypeId = $transaction_type_id;

      $transactions = Transaction::getTransactionList($requestVariables);
      while (!empty($transactions['transactionList'])) {
        foreach ($transactions['transactionList'] as $transaction) {
          $transactions_to_update[] = [
            'entity_id' => $transaction['externalId'],
            'transaction_id' => $transaction['transactionId'],
          ];
        }
        $requestedPage++;
        $requestVariables->requestedPage = $requestedPage;
        $transactions = Transaction::getTransactionList($requestVariables);
      }
    }

    $this->state->set('ipcsync_transaction_get_sync_last_run', $run_time);

    return $transactions_to_update;
  }

  /**
   * Poll for changes to orders in the Sync DB via IPCTransactionAPI.
   *
   * @param array $params
   *   Array of params.
   */
  public function pollForChangesToTransactions(array $params): void {
    $queue_storage = $this->entityTypeManager->getStorage('advancedqueue_queue');
    /** @var \Drupal\advancedqueue\Entity\QueueInterface $queue */
    $queue = $queue_storage->load('ipc_transaction_get_sync');

    $transactions_to_update = $this->getUpdatedTransactionIdsFromSyncDb($params);
    foreach ($transactions_to_update as $transaction) {
      $job_payload = [
        'entity_id' => $transaction['entity_id'],
        'transaction_id' => $transaction['transaction_id'],
      ];
      if (!empty($params['historical_import'])) {
        $job_payload['historical_import'] = $params['historical_import'];
      }
      $transactions_sync_job = Job::create('ipc_syncdb_transactions_get_transaction', $job_payload);
      $queue->enqueueJob($transactions_sync_job);
    }
  }

  /**
   * Process update for an order.
   *
   * @param array $transaction
   *   Transaction data from the API.
   * @param null|string $job_id
   *   Teh Job id for logger.
   *
   * @return string
   *   The success message.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function processUpdateForOrder(array $transaction, string $job_id = NULL): string {
    // We do not here the Type of the order,
    // because it can be a quote purchase or default.
    $this->transactionManagerLogger->setMessage('get', $job_id, "Trying to load the Order with ID - {$transaction['transactionId']}.");
    $orders = $this->entityTypeManager->getStorage('commerce_order')
      ->loadByProperties([
        'syncdb_id' => $transaction['transactionId'],
      ]);

    if (empty($orders)) {
      $this->transactionManagerLogger->setMessage('get', $job_id, "Failed to load the Order with ID - {$transaction['transactionId']}.");
      throw new \RuntimeException("Transaction Sync: We tried to load an order by ID, but we couldn't find it. SyncDB ID = {$transaction['transactionId']}");
    }

    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = reset($orders);
    if ($this->paymentOrderUpdater->needsUpdate($order)) {
      $this->transactionManagerLogger->setMessage('get', $job_id, "Order ID - {$order->id()} is waiting for payment update.");
      throw new \RuntimeException("Transaction Sync: Order ID - {$order->id()} is waiting for payment update.");
    }

    $this->transactionManagerLogger->setMessage('get', $job_id, 'Setting the Order fields ...');
    $this->setSalesOrderFields($order, $transaction);
    $this->transactionManagerLogger->setMessage('get', $job_id, 'Updating the Order state ...');
    $transaction_status_id = $transaction['transactionStatus']['transactionStatusId'];
    $this->applyTransitionForOrder($order, $transaction_status_id, $job_id);
    $this->transactionManagerLogger->setMessage('get', $job_id, 'Saving the order ...');
    $order->save();
    return "Transaction Sync: Order ID - {$order->id()} was synced.";
  }

  /**
   * Updates Order fields using data retrieved from IPCTransactionAPI.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param array $transaction
   *   Transaction data from the API.
   */
  protected function setSalesOrderFields(OrderInterface $order, array $transaction): void {
    $type = $order->bundle();
    if ($type === 'default' || $type === 'quote_purchase') {
      $order->set('netsuite_order_number', $transaction['documentNumber']);
    }
  }

  /**
   * Creates/updates an invoice using data retrieved from IPCTransactionAPI.
   *
   * @param array $transaction
   *   Transaction data from the API.
   * @param null|string $job_id
   *   The job id for logger.
   *
   * @return string
   *   Return success message.
   */
  public function processUpdateForInvoice(array $transaction, string $job_id = NULL): string {
    $this->transactionManagerLogger->setMessage('get', $job_id, "Trying to load the Invoice with SyncDB ID - {$transaction['transactionId']}");
    $commerce_invoice_storage = $this->entityTypeManager->getStorage('commerce_invoice');

    // Attempt to load existing Invoice by SyncDB ID.
    /** @var \Drupal\commerce_invoice\Entity\InvoiceInterface[] $invoices */
    $invoices = $commerce_invoice_storage->loadByProperties([
      'type' => 'default',
      'syncdb_id' => $transaction['transactionId'],
    ]);

    $invoice = NULL;
    // Skip sync if invoice not exist and available_in_customer_center = false.
    if (!empty($invoices)) {
      $invoice = reset($invoices);
    }
    else {
      if (!$transaction['availableInCustomerCenter']) {
        $this->transactionManagerLogger->setMessage('get', $job_id, 'The Invoice is not available in customer center.');

        return "Transaction Sync: Invoice with SyncDB ID - {$transaction['transactionId']} was not synced due to empty \"available in customer center \"";
      }
    }

    // Than we need to validate line items.
    $this->lineItemsValidation($transaction['transactionId'], $transaction['lineItems'], $job_id);

    if (empty($invoices)) {
      $this->transactionManagerLogger->setMessage('get', $job_id, 'We do not have the Invoice. Trying to create a new invoice.');

      // Historical import or simple condition.
      $historical_import = TRUE;
      if (
        isset($transaction['historical_import']) &&
        $transaction['transactionStatus']['transactionStatusId'] != TransactionStatuses::INVOICE_OPEN
      ) {
        $historical_import = FALSE;
      }

      if ($historical_import) {
        // Invoice does not exist - create the Invoice.
        $invoice = Invoice::create([
          'type' => 'default',
          'store_id' => 1,
          'syncdb_id' => $transaction['transactionId'],
        ]);
        $invoice->save();

        $this->transactionManagerLogger->setMessage('get', $job_id, "Creating an invoice with ID - {$invoice->id()}.");
      }
      else {
        $this->transactionManagerLogger->setMessage('get', $job_id, 'The Invoice is not available in customer center.');
        return "Transaction Sync: Invoice with SyncDB ID - {$transaction['transactionId']} was not synced because Invoice not in an Open state";
      }
    }

    if ($invoice) {

      // Set total paid.
      $total = new Price($transaction['total'], 'USD');
      $open_balance = new Price($transaction['openBalance'], 'USD');
      $total_paid = $total->subtract($open_balance);
      $invoice->setTotalPaid($total_paid);

      $this->transactionManagerLogger->setMessage('get', $job_id, "Setting the invoice's fields ...");
      $this->setInvoiceFields($invoice, $transaction, $job_id);
      $this->transactionManagerLogger->setMessage('get', $job_id, $this->t("Setting the invoice's line items ..."));
      $this->setInvoiceLineItems($invoice, $transaction, $job_id);
      $this->transactionManagerLogger->setMessage('get', $job_id, $this->t('Saving the invoice ...'));
      $invoice->save();
      $this->transactionManagerLogger->setMessage('get', $job_id, $this->t('Setting relationship for the invoice ...'));
      $this->setRelationshipsForInvoice($invoice, $transaction);
      $this->transactionManagerLogger->setMessage('get', $job_id, $this->t('Setting adjustments for the invoice ...'));
      $this->setAdjustmentsForInvoice($invoice, $transaction);
      $this->transactionManagerLogger->setMessage('get', $job_id, $this->t('Invoice Sync Success - ID: @drupal_id, SyncDB ID: @sync_db_id - Imported Invoice with Drupal ID @drupal_id for Transaction with SyncDB ID: @sync_db_id.', [
        '@drupal_id' => $invoice->id(),
        '@sync_db_id' => $transaction['transactionId'],
      ]));
      return "Transaction Sync: Invoice ID {$invoice->id()} was synced";
    }

    $this->transactionManagerLogger->setMessage('get', $job_id, $this->t('Invoice Sync Failure - Failed to import Invoice for Transaction with SyncDB ID: @sync_db_id.', [
      '@sync_db_id' => $transaction['transactionId'],
    ]));

    throw new \RuntimeException("Invoice Sync Failure - Failed to import Invoice for Transaction with SyncDB ID: {$transaction['transactionId']}");
  }

  /**
   * Custom validation for the line items during the sync.
   *
   * @param string $transaction_id
   *   The sync_db_id.
   * @param array $line_items
   *   The array of line items.
   * @param string $job_id
   *   The current job_id.
   */
  protected function lineItemsValidation(string $transaction_id, array $line_items, string $job_id): void {
    $failure_indicator = FALSE;
    $messages = [];
    if (empty($line_items)) {
      $failure_indicator = TRUE;
      $messages[] = new FormattableMarkup('Transaction Sync Failure - Failed to import Transaction with SyncDB ID: @sync_db_id. Transaction line items are empty.', [
        '@sync_db_id' => $transaction_id,
      ]);
    }

    $filtered_items = array_filter($line_items, static fn($line_item) => $line_item['category']['categoryId'] == LineItemCategory::PRODUCT);
    if (empty($filtered_items)) {
      $failure_indicator = TRUE;
      $messages[] = new FormattableMarkup('Transaction Sync Failure - Transaction with SyncDB ID: @sync_db_id was not synced because we do not have any product associated with it.', [
        '@sync_db_id' => $transaction_id,
      ]);
    }
    else {
      foreach ($filtered_items as $item) {
        if (empty($item['product'])) {
          $failure_indicator = TRUE;
          $messages[] = new FormattableMarkup('Transaction Sync Failure - Transaction with SyncDB ID: @sync_db_id was not synced because product related to @line_id does not exist in syncDB.', [
            '@sync_db_id' => $transaction_id,
            '@line_id' => $item['lineId'],
          ]);
        }
      }
    }

    if ($failure_indicator && !empty($messages)) {
      $message = implode('<br>', $messages);
      $this->transactionManagerLogger->setMessage('get', $job_id, $message);

      throw new \RuntimeException($message);
    }
  }

  /**
   * Creates/updates a quote using data retrieved from IPCTransactionAPI.
   *
   * @param array $transaction
   *   Transaction data from the API.
   * @param null|string $job_id
   *   The job id for logger.
   *
   * @return string
   *   Return the success message.
   */
  public function processUpdateForQuote(array $transaction, string $job_id = NULL): string {
    $this->transactionManagerLogger->setMessage('get', $job_id, "Trying to load the Quote with SyncDB ID - {$transaction['transactionId']}");
    $commerce_invoice_storage = $this->entityTypeManager->getStorage('commerce_invoice');

    $quote = NULL;
    // Load the Quote by externalId, if it is provided.
    $quotes = $commerce_invoice_storage
      ->loadByProperties([
        'type' => 'quote',
        'syncdb_id' => $transaction['transactionId'],
      ]);
    // Skip sync if quote not exist and available_in_customer_center = false.
    if (!empty($quotes)) {
      $quote = reset($quotes);
    }
    else {
      if (!$transaction['availableInCustomerCenter']) {
        $this->transactionManagerLogger->setMessage('get', $job_id, 'The Quote is not available in customer center.');

        return "Transaction Sync: Quote with SyncDB ID - {$transaction['transactionId']} was not synced due to \"available in customer center\" set to \"false\"";
      }
    }

    $this->lineItemsValidation($transaction['transactionId'], $transaction['lineItems'], $job_id);

    if (empty($quotes)) {
      $this->transactionManagerLogger->setMessage('get', $job_id, 'We do not have the Quote. Trying to create a new quote.');

      // Quote does not exist - create the Quote.
      $quote = Invoice::create([
        'type' => 'quote',
        'store_id' => 1,
        'syncdb_id' => $transaction['transactionId'],
      ]);
      $quote->save();

      // Transition to the Pending (Open) state.
      $transition_id = 'confirm';
      $quote->getState()->applyTransitionById($transition_id);
      $this->transactionManagerLogger->setMessage('get', $job_id, "Creating a quote with ID - {$quote->id()}.");
    }

    if ($quote instanceof InvoiceInterface) {
      $this->transactionManagerLogger->setMessage('get', $job_id, "Setting the quote's fields ...");
      // Here we have a situation when Quote was purchased before first sync,
      // after the sync it was returned to the paid Open state, and it was
      // possible to purchase the Quote twice, because of this we need to write
      // here condition. "If the Quote Paid,
      // and have relationship to Order with type Quote purchase, also quote
      // should not have netsuite_id, if we meet all these conditions,
      // we need to skip state transition".
      if ($quote->get('netsuite_id')->isEmpty()) {
        // Let's try to get the order.
        $storage_handler = $this->entityTypeManager->getStorage('commerce_order');
        $quote_purchase_order = $storage_handler->loadByProperties([
          'type' => 'quote_purchase',
          'created_from' => $quote->id(),
        ]);
        if ($quote_purchase_order) {
          /** @var \Drupal\commerce_order\Entity\OrderInterface $quote_purchase_order */
          $quote_purchase_order = reset($quote_purchase_order);
          if ($quote_purchase_order->getState()->getId() !== 'canceled') {
            $transaction['skip_state_transition'] = TRUE;
          }
        }
      }
      $this->setInvoiceFields($quote, $transaction, $job_id);
      $this->transactionManagerLogger->setMessage('get', $job_id, "Setting the quote's line items ...");
      $this->setInvoiceLineItems($quote, $transaction, $job_id);
      $this->transactionManagerLogger->setMessage('get', $job_id, 'Saving the quote ...');
      $quote->save();
      $this->transactionManagerLogger->setMessage('get', $job_id, 'Setting relationship for the quote ...');
      $this->setRelationshipsForInvoice($quote, $transaction);
      $this->transactionManagerLogger->setMessage('get', $job_id, 'Setting adjustments for the quote ...');
      $this->setAdjustmentsForInvoice($quote, $transaction);
      $this->transactionManagerLogger->setMessage('get', $job_id, $this->t('Quote Sync Success - ID: @drupal_id, SyncDB ID: @sync_db_id - Imported Quote (Invoice) with Drupal ID @drupal_id for Transaction with SyncDB ID: @sync_db_id.', [
        '@drupal_id' => $quote->id(),
        '@sync_db_id' => $transaction['transactionId'],
      ]));
      return "Transaction Sync: Quote ID {$quote->id()} was synced";
    }

    $this->transactionManagerLogger->setMessage('get', $job_id, $this->t('Quote Sync Failure - Failed to import Quote for Transaction with SyncDB ID: @sync_db_id.', [
      '@sync_db_id' => $transaction['transactionId'],
    ]));
    throw new \RuntimeException("Quote Sync Failure - Failed to import Quote for Transaction with SyncDB ID: {$transaction['transactionId']}");
  }

  /**
   * Adds adjustments for tax, shipping and handling to the invoice.
   *
   * @param \Drupal\commerce_invoice\Entity\Invoice $invoice
   *   The invoice.
   * @param array $transaction
   *   Transaction data from the API.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function setAdjustmentsForInvoice(Invoice $invoice, array $transaction): void {
    $existing_tax_adjustments = $invoice->collectAdjustments(['tax']);

    if ($transaction['taxTotal']) {
      $tax_total = Calculator::round($transaction['taxTotal'], 2);
      $line_items_tax_total = 0;

      foreach ($existing_tax_adjustments as $existing_tax_adjustment) {
        $source_id = $existing_tax_adjustment->getSourceId();
        if ($source_id === 'avatax|avatax') {
          $invoice->removeAdjustment($existing_tax_adjustment);
        }
        elseif ($source_id === 'syncdb') {
          $line_item_tax_amount = $existing_tax_adjustment->getAmount();
          $line_item_tax = Calculator::round($line_item_tax_amount->getNumber(), 2);
          $line_items_tax_total += $line_item_tax;
        }
      }

      if (Calculator::compare($line_items_tax_total, $tax_total) !== 0) {
        // Create tax adjustment for the difference between the line items tax
        // total and order tax total to account for shipping & handling taxes.
        $adj_amount = Calculator::subtract($tax_total, $line_items_tax_total);
        $tax_adjustment = new Adjustment([
          'type' => 'tax',
          'label' => $this->t('Estimated Tax'),
          'amount' => new Price($adj_amount, 'USD'),
          'source_id' => 'syncdb',
        ]);
        $invoice->addAdjustment($tax_adjustment);
      }
    }
    else {
      // Add an adjustment for any taxes added to the Quote in Netsuite.
      $existing_ns_tax_total = 0;
      foreach ($existing_tax_adjustments as $existing_tax_adjustment) {
        $source_id = $existing_tax_adjustment->getSourceId();
        if ($source_id === 'netsuite') {
          $line_item_tax_amount = $existing_tax_adjustment->getAmount();
          $line_item_tax = Calculator::round($line_item_tax_amount->getNumber(), 2);
          $existing_ns_tax_total += $line_item_tax;
        }
      }

      $imported_ns_tax_total = 0;
      foreach ($transaction['lineItems'] as $line_item) {
        if (!$line_item['product'] && $line_item['taxAmount']) {
          $tax_amount = Calculator::round($line_item['taxAmount'], 2);
          $imported_ns_tax_total += $tax_amount;
        }
      }

      // If the existing tax total does not match the imported tax total, remove
      // all adjustments with source_id = 'netsuite' and create a new adjustment
      // for the tax amount coming from the API.
      if (Calculator::compare($existing_ns_tax_total, $imported_ns_tax_total) !== 0) {
        foreach ($existing_tax_adjustments as $existing_tax_adjustment) {
          $source_id = $existing_tax_adjustment->getSourceId();
          if ($source_id === 'netsuite') {
            $invoice->removeAdjustment($existing_tax_adjustment);
          }
        }
        $adj_amount = Calculator::round($imported_ns_tax_total, 2);
        $tax_adjustment = new Adjustment([
          'type' => 'tax',
          'label' => $this->t('Estimated Tax'),
          'amount' => new Price($adj_amount, 'USD'),
          'source_id' => 'netsuite',
        ]);
        $invoice->addAdjustment($tax_adjustment);
      }
    }

    $can_be_fulfilled = $this->determineCanBeFulfilled($transaction);
    if ($can_be_fulfilled) {
      $adjustments = [
        'shipping' => [
          'label' => $this->t('Shipping'),
          'type' => 'shipping',
          'transaction' => 'shippingCost',
        ],
        'handling' => [
          'label' => $this->t('Handling fee'),
          'type' => 'handling_fee',
          'transaction' => 'handlingCost',
        ],
      ];
      foreach ($adjustments as $adjustment) {
        $this->setShippingHandlingAdjustmentsForInvoice($adjustment, $invoice, $transaction);
      }
    }
    else {
      $this->removeShippingHandlingAdjustments($invoice);
    }

    $invoice->save();

    // Add discounts and promotions.
    /** @var \Drupal\Core\Field\EntityReferenceFieldItemList $invoice_item_list */
    $invoice_item_list = $invoice->get('invoice_items');

    /** @var \Drupal\commerce_invoice\Entity\InvoiceItemInterface[] $invoice_items */
    $invoice_items = $invoice_item_list->referencedEntities();
    $invoice_items = array_filter($invoice_items, static fn($invoice_item) => $invoice_item->getInvoiceId() == $invoice->id());

    // An array of transaction level discounts/promotions.
    $transaction_items = array_filter($transaction['lineItems'], static fn($line_item) => $line_item['product'] && !empty($line_item['transactionDiscount']));

    // Import of Promotion Items or Import of Transaction Line Items.
    if (count($invoice_items) > 0) {
      // Add any new items.
      foreach ($transaction_items as $transaction_item) {
        $is_discount = $transaction_item['product']['productType']['productTypeId'] == LineItemCategory::DISCOUNT;

        if ($is_discount) {
          $invoice_item_total_price = $transaction_item['amount'] ?: 0;
          $invoice_item_total_price = new Price($invoice_item_total_price, 'USD');

          $percentage = NULL;
          if ($transaction_item['discountType'] === '%') {
            $percentage = (string) ($transaction_item['rate'] / 100);
          }

          // Split the amount between invoice items.
          $amounts = $this->invoicePriceSplitter->split($invoice, $invoice_item_total_price, $percentage);

          foreach ($invoice_items as $invoice_item) {
            if (isset($amounts[$invoice_item->id()])) {

              $to_serialize = [
                'nsProductId' => $transaction_item['product']['nsProductId'],
                'productNumber' => $transaction_item['product']['productNumber'],
                'displayName' => $transaction_item['product']['displayName'],
                'transactionDiscount' => $transaction_item['transactionDiscount'],
                'rate' => $transaction_item['rate'],
                'amount' => $transaction_item['amount'],
                'discountType' => $transaction_item['discountType'],
                'productId' => $transaction_item['product']['productId'],
                'quantity' => $transaction_item['quantity'],
              ];

              // Prepare data for promotion.
              if (!empty($transaction_item['promotion'])) {
                $to_serialize['promotion'] = [
                  'nsPromotionId' => $transaction_item['promotion']['nsPromotionId'],
                  'couponCode' => $transaction_item['promotion']['couponCode'],
                  'promotion' => $transaction_item['promotion']['promotion'],
                ];
              }

              $custom_adjustment = new Adjustment([
                'type' => 'ipc_transaction_discount',
                'label' => $transaction_item['product']['displayName'],
                'amount' => $amounts[$invoice_item->id()],
                'percentage' => $percentage,
                'source_id' => serialize($to_serialize),
                'included' => FALSE,
                'locked' => TRUE,
              ]);

              $invoice_item->addAdjustment($custom_adjustment);
              $invoice_item->save();
            }
          }

          $invoice->save();
        }
      }
    }

  }

  /**
   * Removes unneeded adjustments for shipping and handling.
   *
   * @param \Drupal\commerce_invoice\Entity\Invoice $invoice
   *   The invoice.
   */
  protected function removeShippingHandlingAdjustments(Invoice $invoice): void {
    $existing_shipping_adjustments = $invoice->collectAdjustments(['shipping']);
    foreach ($existing_shipping_adjustments as $existing_shipping_adjustment) {
      $invoice->removeAdjustment($existing_shipping_adjustment);
    }
    $existing_handling_adjustments = $invoice->collectAdjustments(['handling_fee']);
    foreach ($existing_handling_adjustments as $existing_handling_adjustment) {
      $invoice->removeAdjustment($existing_handling_adjustment);
    }
  }

  /**
   * Removes all type adjustments.
   *
   * @param \Drupal\commerce_invoice\Entity\Invoice $invoice
   *   The invoice.
   */
  protected function removeAllAdjustments(Invoice $invoice): void {
    $existing_custom_adjustments = $invoice->collectAdjustments([
      'ipc_discount',
      'ipc_transaction_discount',
    ]);
    foreach ($existing_custom_adjustments as $existing_custom_adjustment) {
      $invoice->removeAdjustment($existing_custom_adjustment);
    }
  }

  /**
   * Determine if quote can be fulfilled (is shippable).
   *
   * @param array $transaction
   *   Transaction data from the API.
   */
  protected function determineCanBeFulfilled(array $transaction): bool {
    foreach ($transaction['lineItems'] as $line_item) {
      if ($line_item['product'] && $line_item['product']['canBeFulfilled']) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Adds adjustments to the invoice.
   *
   * @param array $adjustment
   *   Array with values.
   * @param \Drupal\commerce_invoice\Entity\Invoice $invoice
   *   The invoice.
   * @param array $transaction
   *   Transaction data from the API.
   */
  protected function setShippingHandlingAdjustmentsForInvoice(array $adjustment, Invoice $invoice, array $transaction): void {
    // Check whether or not adjustment needs to be created.
    $cost = Calculator::round($transaction[$adjustment['transaction']], 2);
    $existing_adjustments = $invoice->collectAdjustments([$adjustment['type']]);
    $create_adjustment = TRUE;
    if ($existing_adjustments) {
      $combined_adjustments = $this->adjustmentTransformer->combineAdjustments($existing_adjustments);
      $combined_adjustment = reset($combined_adjustments);
      /** @var \Drupal\commerce_price\Price $combined_amount */
      $combined_amount = $combined_adjustment->getAmount();
      $existing_total = Calculator::round($combined_amount->getNumber(), 2);
      if ($existing_total === $cost) {
        // No need to create a adjustment if the total matches.
        $create_adjustment = FALSE;
      }
      else {
        // Remove all existing adjustments if the total doesn't match.
        foreach ($existing_adjustments as $existing_adjustment) {
          $invoice->removeAdjustment($existing_adjustment);
        }
      }
    }
    // Create new adjustment.
    if ($create_adjustment) {
      $new_adjustment = new Adjustment([
        'type' => $adjustment['type'],
        'label' => $adjustment['label'],
        'amount' => new Price($cost, 'USD'),
      ]);
      $invoice->addAdjustment($new_adjustment);
    }
  }

  /**
   * Assigns the Invoice to the appropriate user or company.
   *
   * @param \Drupal\commerce_invoice\Entity\Invoice $invoice
   *   The invoice.
   * @param array $transaction
   *   Transaction data from the API.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function setRelationshipsForInvoice(Invoice $invoice, array $transaction): void {
    if ($transaction['customer']['user']) {
      // Assign the Quote to a user.
      if ($user = $this->userImporter->importUserByEmail($transaction['customer']['user']['email'])) {
        $invoice->set('uid', $user->id());
      }
      else {
        $this->logger->error($this->t('Invoice/Quote Sync Error - Transaction ID @tid - Error importing Invoice (@type) with Drupal ID @drupal_id - Unable to import the associated user with email: @email', [
          '@tid' => $transaction['transactionId'],
          '@drupal_id' => $invoice->id(),
          '@email' => $transaction['customer']['user']['email'],
          '@type' => $invoice->bundle(),
        ]));
        return;
      }
    }
    elseif ($transaction['customer']['company']) {
      // Assign the Quote to a company.
      if ($company = $this->companyImporter->importCompany($transaction['customer']['company']['accountId'])) {
        $this->assignToGroup($invoice, $company);
        if ($transaction['endUser']['user']) {
          // If endUser is an individual, import the user.
          if (
            !empty($transaction['endUser']['user']['email']) &&
            $user = $this->userImporter->importUserByEmail($transaction['endUser']['user']['email'])
          ) {
            // Assign the Quote to the End User.
            $invoice->set('uid', $user->id());
          }
          else {
            $this->logger->error($this->t('Invoice/Quote Sync Error - Transaction ID @tid - Error importing Invoice (@type) with Drupal ID @drupal_id - Unable to import the associated End User with email: @email', [
              '@tid' => $transaction['transactionId'],
              '@drupal_id' => $invoice->id(),
              '@email' => $transaction['endUser']['user']['email'],
              '@type' => $invoice->bundle(),
            ]));
            return;
          }
        }
        elseif ($transaction['endUser']['company']) {
          $invoice->set('uid', 0);
        }
      }
      else {
        $this->logger->error($this->t('Invoice/Quote Sync Error - Transaction ID @tid - Error importing Invoice (@type) with Drupal ID @drupal_id - Unable to import the associated company with AccountId: @account_id', [
          '@tid' => $transaction['transactionId'],
          '@drupal_id' => $invoice->id(),
          '@account_id' => $transaction['customer']['company']['accountId'],
          '@type' => $invoice->bundle(),
        ]));
        return;
      }
    }
    $invoice->save();
  }

  /**
   * Sets the Invoice fields with values retrieved via IPCTransactionAPI.
   *
   * @param \Drupal\commerce_invoice\Entity\Invoice $invoice
   *   The Invoice.
   * @param array $transaction
   *   Transaction data from the API.
   * @param string|null $job_id
   *   The job ID.
   */
  protected function setInvoiceFields(Invoice $invoice, array $transaction, string $job_id = NULL): void {
    $invoice->set('netsuite_id', $transaction['nsTransactionId']);
    $invoice->set('netsuite_status', $transaction['transactionStatus']['transactionStatusId']);
    $invoice->set('available_in_customer_center', $transaction['availableInCustomerCenter']);
    if (isset($transaction['salesOrderTerms'])) {
      $invoice->set('terms', SalesOrderTerms::getDrupalSalesOrderTerm($transaction['salesOrderTerms']['salesOrderTermsId']));
    }
    $invoice->set('customs_note', $transaction['includeEPOnlyNote']);
    $invoice->set('customs_include_tax_id', $transaction['includeVATTaxFiscalId']);
    $invoice->set('customs_tax_id', $transaction['vatTaxFiscalId']);
    $invoice->set('shipping_account_number', $transaction['shippingAccount']);
    $invoice->set('po_number', $transaction['otherReferenceNumber']);
    $invoice->set('reference_number', $transaction['documentNumber']);

    $delivery_method = !empty($transaction['shippingAccount']) ? 'account' : 'standart';
    $invoice->set('delivery_method', $delivery_method);

    if (!empty($transaction['shippingMethod']['shippingMethod'])) {
      $invoice->set('shipping_method', $transaction['shippingMethod']['shippingMethod']);
    }

    // Create profile for shipping information.
    if (!empty($transaction['shippingAddress'])) {
      $shipping_profile = $this->createOrUpdateProfile($transaction['shippingAddress']);
      $invoice->set('shipping_information', ['target_id' => $shipping_profile->id()]);
    }

    // Create profile for billing information.
    if (!empty($transaction['billingAddress'])) {
      $billing_profile = $this->createOrUpdateProfile($transaction['billingAddress']);
      $invoice->set('billing_profile', $billing_profile);
    }

    if ($transaction['customer']['user']) {
      $invoice->set('netsuite_account_number', $transaction['customer']['user']['contactNumber']);
    }
    elseif ($transaction['customer']['company']) {
      $invoice->set('netsuite_account_number', $transaction['customer']['company']['accountNumber']);
    }

    $transaction_status_id = $transaction['transactionStatus']['transactionStatusId'];
    // Here we need to set different values for default/quote type.
    if ($invoice->bundle() === 'default') {
      if ($invoice->hasField('netsuite_order_number')) {
        $invoice->set('netsuite_order_number', $transaction['externalTransactionNumber']);
      }

      if ($invoice->hasField('invoice_status')) {
        $invoice->set('invoice_status', $transaction_status_id);
      }

      // Here we need to apply transition.
      $this->applyTransitionForInvoice($invoice, $transaction_status_id, $job_id);
      $transaction_date = DrupalDateTime::createFromFormat('Y-m-d\TH:i:s\Z', $transaction['transactionDate']);
      $invoice->set('invoice_date', $transaction_date->getTimestamp());
      // Here we have a problem, that at some point,
      // "Invoice number" is not created automatically.
      // And we need to check this,
      // if invoice number empty we need to generate it.
      if ($transaction_status_id == TransactionStatuses::INVOICE_PAID_IN_FULL && empty($invoice->getInvoiceNumber())) {
        $invoice_type = InvoiceType::load($invoice->bundle());
        /** @var \Drupal\commerce_number_pattern\Entity\NumberPatternInterface $number_pattern */
        $number_pattern = $invoice_type->getNumberPattern();
        if ($number_pattern) {
          $invoice_number = $number_pattern->getPlugin()->generate($invoice);
          $invoice->setInvoiceNumber($invoice_number);
        }
      }
    }
    elseif ($invoice->bundle() === 'quote') {
      // Here we need to apply transition.
      if (!isset($transaction['skip_state_transition'])) {
        $this->applyTransitionForQuote($invoice, $transaction_status_id, $job_id);
      }

      if ($invoice->hasField('quote_expiration_date') && $transaction['quoteExpirationDate']) {
        $quote_expiration_date = substr($transaction['quoteExpirationDate'], 0, 10);
        $invoice->set('quote_expiration_date', $quote_expiration_date);
      }
      if ($invoice->hasField('quote_status') && isset($transaction['quoteStatus']['quoteStatusId'])) {
        $invoice->set('quote_status', $transaction['quoteStatus']['quoteStatusId']);
      }
    }
  }

  /**
   * Sets the invoice line items using data retrieved via IPCTransactionAPI.
   *
   * @param \Drupal\commerce_invoice\Entity\Invoice $invoice
   *   The invoice.
   * @param array $transaction
   *   Transaction data from the API.
   * @param string $job_id
   *   The job id.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function setInvoiceLineItems(Invoice $invoice, array $transaction, $job_id = ''): void {
    /** @var \Drupal\Core\Field\EntityReferenceFieldItemList $invoice_item_list */
    $invoice_item_list = $invoice->get('invoice_items');
    /** @var \Drupal\commerce_invoice\Entity\InvoiceItemInterface[] $invoice_items */
    $invoice_items = $invoice_item_list->referencedEntities();
    $invoice_items = array_filter($invoice_items, static fn($invoice_item) => $invoice_item->getInvoiceId() == $invoice->id());
    // An array of just product items from the transaction.
    $transaction_items = array_filter($transaction['lineItems'], static fn($line_item) => $line_item['product']);

    if (count($invoice_items) > 0) {
      // Remove all custom adjustments.
      $this->removeAllAdjustments($invoice);
      $invoice->save();

      // Remove all invoice items.
      foreach ($invoice_items as $invoice_item) {
        $invoice->removeItem($invoice_item);
        $invoice_item->delete();
      }
      $invoice->save();
    }

    $parentNsProductId = 0;
    $new_invoice_item = NULL;
    foreach ($transaction_items as $transaction_item) {
      $is_discount = $transaction_item['product']['productType']['productTypeId'] == LineItemCategory::DISCOUNT;

      // Skip Promotion Items or Transaction Line Items.
      // We will add them after we complete invoice items list.
      if (!empty($transaction_item['transactionDiscount'])) {
        continue;
      }

      // Import of Discount Line Items.
      if ($is_discount && ($new_invoice_item !== NULL)) {

        $invoice_item_total_price = $transaction_item['amount'] ?: 0;
        $invoice_item_total_price = new Price($invoice_item_total_price, 'USD');

        $percentage = NULL;
        if ($transaction_item['discountType'] === '%') {
          $percentage = (string) $transaction_item['rate'];
        }

        $to_serialize = [
          'nsProductId' => $transaction_item['product']['nsProductId'],
          'productNumber' => $transaction_item['product']['productNumber'],
          'displayName' => $transaction_item['product']['displayName'],
          'transactionDiscount' => $transaction_item['transactionDiscount'],
          'rate' => $transaction_item['rate'],
          'amount' => $transaction_item['amount'],
          'discountType' => $transaction_item['discountType'],
          'parent' => $parentNsProductId,
          'productId' => $transaction_item['product']['productId'],
          'quantity' => $transaction_item['quantity'],
          // This line was added to prevent combining discounts.
          'id' => $new_invoice_item->id(),
        ];

        $custom_adjustment = new Adjustment([
          'type' => 'ipc_discount',
          'label' => $transaction_item['product']['displayName'],
          'amount' => $invoice_item_total_price,
          'percentage' => $percentage,
          'source_id' => serialize($to_serialize),
          'included' => FALSE,
          'locked' => TRUE,
        ]);

        $new_invoice_item->addAdjustment($custom_adjustment);
        $new_invoice_item->save();
      }
      else {
        $parentNsProductId = $transaction_item['product']['nsProductId'];

        try {
          // Import product.
          $this->productImporter->importProduct($transaction_item['product']['productId']);

        }
        catch (\Exception $e) {
          $this->transactionManagerLogger->setMessage('get', $job_id, $this->t('Encountered exception when attempting to import transaction with Transaction ID: @transaction_id. Message: Transaction Sync Failure - Transaction was not synced because  productId: @product_id cannot be imported to Drupal Catalog.', [
            '@transaction_id' => $transaction['transactionId'],
            '@product_id' => $transaction_item['product']['productId'],
          ]));
          $message = $this->t('Transaction Sync Failure - Transaction was not synced because  productId: @product_id cannot be imported to Drupal Catalog.', [
            '@product_id' => $transaction_item['product']['productId'],
          ]);
          throw new \Exception($message);
        }

        /** @var \Drupal\commerce_invoice\Entity\InvoiceItemInterface $new_invoice_item */
        $new_invoice_item = InvoiceItem::create([
          'type' => $invoice->bundle() === 'quote' ? 'quote' : 'commerce_invoice',
          'invoice_id' => $invoice->id(),
          'product_variation' => $this->findProductVariationBySku($transaction_item['product']['productNumber']),
        ]);

        $this->setInvoiceLineItemFields($new_invoice_item, $transaction_item);
        $new_invoice_item->save();
        $invoice->addItem($new_invoice_item);
      }

    }
    $invoice->save();
  }

  /**
   * Returns product variation with SKU that matches the given product number.
   *
   * @param string $product_number
   *   The product number to use for matching.
   */
  protected function findProductVariationBySku(string $product_number): ?array {
    $variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $entities = $variation_storage->loadByProperties([
      'sku' => $product_number,
    ]);
    if ($entities) {
      $variation = reset($entities);
      return ['target_id' => $variation->id()];
    }
    return NULL;
  }

  /**
   * Sets the Quote Line Item fields using data from IPCTransactionAPI.
   *
   * @param \Drupal\commerce_invoice\Entity\InvoiceItemInterface $invoice_item
   *   The Invoice Item.
   * @param array $line_item
   *   Line Item data from the API.
   */
  protected function setInvoiceLineItemFields(InvoiceItemInterface $invoice_item, array $line_item): void {
    $is_discount_or_gift_item = $line_item['product']['productType']['productTypeId'] == LineItemCategory::GIFT_CERT;
    // If the total (tax inclusive) price of the line item doesn't match,
    // clear all the adjustments and then add them back.
    if (!$invoice_item->get('total_price')->isEmpty()) {
      /** @var \Drupal\commerce_price\Plugin\Field\FieldType\PriceItem $total_price_item */
      $total_price_item = $invoice_item->get('total_price')->first();
      $total_price = $total_price_item->toPrice()->getNumber();
      if (Calculator::compare($total_price, $line_item['amount']) !== 0) {
        foreach ($invoice_item->getAdjustments(['tax']) as $adjustment) {
          $invoice_item->removeAdjustment($adjustment);
        }
      }
    }
    // Add adjustment if it has not already been added.
    if ($line_item['taxAmount']) {
      $tax_adjustment_added = FALSE;
      foreach ($invoice_item->getAdjustments(['tax']) as $adjustment) {
        $adjustment_tax_amount = Calculator::trim($adjustment->getAmount()->getNumber());
        if ($adjustment_tax_amount === Calculator::trim($line_item['taxAmount'])) {
          $tax_adjustment_added = TRUE;
        }
      }
      if (!$tax_adjustment_added) {
        $adjustment = new Adjustment([
          'type' => 'tax',
          'label' => $this->t('Estimated Tax'),
          'amount' => new Price($line_item['taxAmount'], 'USD'),
          'source_id' => 'syncdb',
        ]);
        $invoice_item->addAdjustment($adjustment);
      }
    }

    $invoice_item->set('syncdb_id', $line_item['lineId']);
    $invoice_item->set('netsuite_id', $line_item['nsLineId']);
    $invoice_item->set('product_id', $line_item['product']['productId']);
    $invoice_item->set('ns_product_id', $line_item['product']['nsProductId']);
    $invoice_item->set('product_number', $line_item['product']['productNumber']);
    $invoice_item->set('title', $line_item['product']['description']);
    $invoice_item->set('display_name', $line_item['product']['displayName']);
    $invoice_item->set('description', $line_item['product']['description']);
    $invoice_item->set('transaction_discount', $line_item['transactionDiscount']);
    $invoice_item->set('line_item_sequence', $line_item['lineSeq']);
    $invoice_item->set('discount_item', $is_discount_or_gift_item);
    $invoice_item_quantity = $is_discount_or_gift_item ? 1 : $line_item['quantity'];
    $invoice_item->set('quantity', $invoice_item_quantity);
    $invoice_item_unit_price = $line_item['rate'] ?: 0;
    $invoice_item_total_price = $line_item['amount'] ?: 0;
    $invoice_item_unit_price = new Price($invoice_item_unit_price, 'USD');
    $invoice_item_total_price = new Price($invoice_item_total_price, 'USD');
    if ($is_discount_or_gift_item) {
      if ($invoice_item->hasField('discount_rate') && $line_item['discountType'] === '%') {
        $invoice_item->set('discount_rate', $invoice_item_unit_price);
      }
      $invoice_item_unit_price = $invoice_item_total_price;
    }
    $invoice_item->set('unit_price', $invoice_item_unit_price);
    $invoice_item->set('total_price', $invoice_item_total_price);
  }

  /**
   * Creates or updates a profile with data obtained from SyncDB.
   *
   * @param array $address_from_response
   *   The address data from the Api response.
   *
   * @return \Drupal\profile\Entity\Profile
   *   The profile.
   */
  public function createOrUpdateProfile(array $address_from_response) {
    $profile = $this->getProfileIfProfileExists($address_from_response);
    if (!$profile) {
      $profile = Profile::create([
        'type' => 'customer',
        'syncdb_id' => $address_from_response['addressId'],
      ]);
      $profile->save();
    }
    $this->setProfileFields($profile, $address_from_response);
    return $profile;
  }

  /**
   * Returns profile if profile that matches target identifiers exists.
   *
   * @param array $address_from_response
   *   The address data from the Api response.
   */
  public function getProfileIfProfileExists(array $address_from_response) {
    $storage = $this->entityTypeManager->getStorage('profile');
    $profiles = $storage->loadByProperties([
      'type' => 'customer',
      'syncdb_id' => $address_from_response['addressId'],
    ]);
    if ($profiles) {
      $profile = reset($profiles);
      return $profile;
    }
    return FALSE;
  }

  /**
   * Checks if customer is a user that there is a value to post for customerId.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   */
  protected function checkThatUserHasCustomerId(OrderInterface $order): bool {
    if ($this->getOrderCustomerPrimaryCompany($order) === NULL) {
      $user = $order->getCustomer();
      if ($user->get('syncdb_id')->isEmpty()) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Helper function to help transition for the Invoice.
   *
   * @param \Drupal\commerce_invoice\Entity\InvoiceInterface $invoice
   *   The invoice entity.
   * @param string $transaction_status_id
   *   The Transaction status from API.
   * @param string $job_id
   *   Current job id.
   */
  private function applyTransitionForInvoice(InvoiceInterface $invoice, string $transaction_status_id, string $job_id): void {
    $current_invoice_state = $invoice->getState()->getId();
    $invoice_id = $invoice->id();

    switch ($transaction_status_id) {
      case TransactionStatuses::INVOICE_OPEN:
        if (in_array($current_invoice_state, ['draft', 'paid'])) {
          $invoice->getState()->applyTransitionById('pending');
          $this->transactionManagerLogger->setMessage('get', $job_id, "Invoice Sync:: (Invoice ID) {$invoice_id} - Transitioning to Pending state.");
        }
        elseif ($current_invoice_state !== 'pending') {
          $this->transactionManagerLogger->setMessage('get', $job_id, "Invalid state transition. (Invoice ID) {$invoice_id} cannot transition to Pending state, as it is not currently in Draft or Paid state.");
        }
        break;

      case TransactionStatuses::INVOICE_PAID_IN_FULL:
        if ($current_invoice_state === 'draft') {
          $invoice->getState()->applyTransitionById('pending');
          $invoice->getState()->applyTransitionById('pay');
          $this->transactionManagerLogger->setMessage('get', $job_id, "Invoice Sync:: (Invoice ID) {$invoice_id} - Transitioning to Pending and then to Paid in Full state.");
        }
        elseif ($current_invoice_state === 'pending') {
          $invoice->getState()->applyTransitionById('pay');
          $this->transactionManagerLogger->setMessage('get', $job_id, "Invoice Sync:: (Invoice ID) {$invoice_id} - Transitioning to Paid in Full state.");
        }
        elseif ($current_invoice_state != 'paid') {
          $this->transactionManagerLogger->setMessage('get', $job_id, "Invalid state transition. (Invoice ID) {$invoice_id} cannot transition to Paid in Full state, as it is not currently in Pending state.");
        }
        break;

      case TransactionStatuses::INVOICE_PENDING_APPROVAL:
        if ($current_invoice_state === 'canceled') {
          $invoice->getState()->applyTransitionById('draft');
          $this->transactionManagerLogger->setMessage('get', $job_id, "Invoice Sync:: (Invoice ID) {$invoice_id} - Transitioning to Draft state.");
        }
        elseif ($current_invoice_state !== 'draft') {
          $this->transactionManagerLogger->setMessage('get', $job_id, "Invalid state transition. (Invoice ID) {$invoice_id} cannot transition to Draft state, as it is not currently in Canceled state.");
        }
        break;

      case TransactionStatuses::INVOICE_REJECTED:
      case TransactionStatuses::INVOICE_VOIDED:
        if (in_array($current_invoice_state, ['draft', 'pending', 'paid'])) {
          $invoice->getState()->applyTransitionById('cancel');
          $this->transactionManagerLogger->setMessage('get', $job_id, "Invoice Sync:: (Invoice ID) {$invoice_id} - Transitioning to Cancel state.");
        }
        break;
    }
  }

  /**
   * Helper function to help transition for the Quote.
   *
   * @param \Drupal\commerce_invoice\Entity\InvoiceInterface $invoice
   *   The invoice entity.
   * @param string $transaction_status_id
   *   The Transaction status from API.
   * @param string $job_id
   *   Current job id.
   */
  private function applyTransitionForQuote(InvoiceInterface $invoice, string $transaction_status_id, string $job_id): void {
    $current_invoice_state = $invoice->getState()->getId();
    $invoice_id = $invoice->id();

    switch ($transaction_status_id) {
      case TransactionStatuses::QUOTE_OPEN:
        if (in_array($current_invoice_state, [
          'draft',
          'paid',
          'canceled',
          'expired',
        ])) {
          $invoice->getState()->applyTransitionById('confirm');
          $this->transactionManagerLogger->setMessage('get', $job_id, "Quote Sync:: (Quote ID) {$invoice_id} - Transitioning to Open state.");
        }
        elseif ($current_invoice_state !== 'pending') {
          $this->transactionManagerLogger->setMessage('get', $job_id, "Invalid state transition. (Quote ID) {$invoice_id} cannot transition to Open state, as it is now not in Draft or Expired state.");
        }
        break;

      case TransactionStatuses::QUOTE_PROCESSED:
        if ($current_invoice_state === 'draft') {
          $invoice->getState()->applyTransitionById('confirm');
          $invoice->getState()->applyTransitionById('pay');
          $this->transactionManagerLogger->setMessage('get', $job_id, "Quote Sync:: (Quote ID) {$invoice_id} - Transitioning to Open and then Paid state.");
        }
        elseif (in_array($current_invoice_state, ['pending', 'expired'])) {
          $invoice->getState()->applyTransitionById('pay');
          $this->transactionManagerLogger->setMessage('get', $job_id, "Quote Sync:: (Quote ID) {$invoice_id} - Transitioning to Paid state.");
        }
        else {
          $this->transactionManagerLogger->setMessage('get', $job_id, "Invalid state transition. (Quote ID) {$invoice_id} cannot transition to Paid state.");
        }
        break;

      case TransactionStatuses::QUOTE_CLOSED:
      case TransactionStatuses::QUOTE_VOIDED:
        if (in_array($current_invoice_state, ['draft', 'pending', 'expired'])) {
          $invoice->getState()->applyTransitionById('cancel');
          $this->transactionManagerLogger->setMessage('get', $job_id, "Quote Sync:: (Quote ID) {$invoice_id} - Transitioning to Cancel state.");
        }
        elseif ($current_invoice_state !== 'canceled') {
          $this->transactionManagerLogger->setMessage('get', $job_id, "Invalid state transition. (Quote ID) {$invoice_id} cannot transition to Cancel state.");
        }
        break;

      case TransactionStatuses::QUOTE_EXPIRED:
        if ($current_invoice_state === 'pending') {
          $invoice->getState()->applyTransitionById('expired');
          $this->transactionManagerLogger->setMessage('get', $job_id, "Quote Sync:: (Quote ID) {$invoice_id} - Transitioning to Cancel state.");
        }
        elseif ($current_invoice_state !== 'expired') {
          $this->transactionManagerLogger->setMessage('get', $job_id, "Invalid state transition. (Quote ID) {$invoice_id} cannot transition to Expired state, as it is not in Open state.");
        }
        break;
    }
  }

  /**
   * Helper function to help transition for the Order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The invoice entity.
   * @param string $transaction_status_id
   *   The Transaction status from API.
   * @param string $job_id
   *   Current job id.
   */
  private function applyTransitionForOrder(OrderInterface $order, string $transaction_status_id, string $job_id): void {
    $order->set('netsuite_status', $transaction_status_id);
    $current_order_state = $order->getState()->getId();
    $order_id = $order->id();

    switch ($transaction_status_id) {
      case TransactionStatuses::ORDER_PENDING_APPROVAL:
        if ($current_order_state === 'draft') {
          $order->getState()->applyTransitionById('place');
          $this->transactionManagerLogger->setMessage('get', $job_id, "Order Sync:: (Order ID) {$order_id} - Transitioning to Pending state.");
        }
        elseif ($current_order_state !== 'pending') {
          $this->transactionManagerLogger->setMessage('get', $job_id, "Invalid state transition. (Order ID) {$order_id} cannot transition to Pending state, as it is not currently in Draft state.");
        }

        break;

      case TransactionStatuses::ORDER_PENDING_BILLING:
      case TransactionStatuses::ORDER_PENDING_FULFILLMENT:
      case TransactionStatuses::ORDER_PARTIALLY_FULFILLED:
      case TransactionStatuses::ORDER_PENDING_BILLING_PARTIALLY_FULFILLED:
        if ($current_order_state === 'pending') {
          $order->getState()->applyTransitionById('validate');
          $this->transactionManagerLogger->setMessage('get', $job_id, "Order Sync:: (Order ID) {$order_id} - Transitioning to Fulfillment state.");
        }
        elseif ($current_order_state !== 'fulfillment') {
          $this->transactionManagerLogger->setMessage('get', $job_id, "Invalid state transition. (Order ID) {$order_id} cannot transition to Fulfillment state, as it is not currently in Pending state.");
        }
        break;

      case TransactionStatuses::ORDER_BILLED:
        if ($current_order_state === 'fulfillment') {
          $order->getState()->applyTransitionById('fulfill');
          $this->transactionManagerLogger->setMessage('get', $job_id, "Order Sync:: (Order ID) {$order_id} - Transitioning to Completed state.");
        }
        elseif ($current_order_state === 'pending') {
          $order->getState()->applyTransitionById('validate');
          $this->transactionManagerLogger->setMessage('get', $job_id, "Order Sync:: (Order ID) {$order_id} - Transitioning to Fulfillment state.");
          $order->getState()->applyTransitionById('fulfill');
          $this->transactionManagerLogger->setMessage('get', $job_id, "Order Sync:: (Order ID) {$order_id} - Transitioning to Completed state.");
        }
        elseif ($current_order_state !== 'completed') {
          $this->transactionManagerLogger->setMessage('get', $job_id, "Invalid state transition. (Order ID) {$order_id} cannot transition to Completed state, as it is not currently in Fulfillment state.");
        }
        break;

      case TransactionStatuses::ORDER_CANCELLED:
      case TransactionStatuses::ORDER_CLOSED:
        if (in_array($current_order_state, [
          'pending',
          'fulfillment',
          'completed',
        ])) {
          $order->getState()->applyTransitionById('cancel');
          $this->transactionManagerLogger->setMessage('get', $job_id, "Order Sync:: (Order ID) {$order_id} - Transitioning to Cancel state.");
        }
        elseif ($current_order_state !== 'canceled') {
          $this->transactionManagerLogger->setMessage('get', $job_id, "Invalid state transition. (Order ID) {$order_id} cannot transition to Cancel state, as it is not currently in Fulfillment or Completed state.");
        }
        break;
    }
  }

}
