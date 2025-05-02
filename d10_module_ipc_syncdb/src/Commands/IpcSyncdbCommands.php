<?php

namespace Drupal\ipc_syncdb\Commands;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ipc_syncdb\LicenseImporter;
use Drupal\ipc_syncdb\ProductImporter;
use Drush\Commands\DrushCommands;

/**
 * A Drush command file.
 *
 * See these files for an example of injecting Drupal services:
 *   - http://cgit.drupalcode.org/devel/tree/src/Commands/DevelCommands.php
 *   - http://cgit.drupalcode.org/devel/tree/drush.services.yml.
 *
 * TODO: https://ipcinc.atlassian.net/browse/IEC-2114
 */
class IpcSyncdbCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The inline form manager.
   *
   * @var \Drupal\ipc_syncdb\ProductImporter
   */
  protected $productImporter;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The license importer.
   *
   * @var \Drupal\ipc_syncdb\LicenseImporter
   */
  protected $licenseImporter;

  /**
   * Constructs a new IpcSyncdbCommands object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\ipc_syncdb\ProductImporter $productImporter
   *   The product importer.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection to use.
   * @param \Drupal\ipc_syncdb\LicenseImporter $license_importer
   *   The license importer.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ProductImporter $productImporter, Connection $connection, LicenseImporter $license_importer) {
    parent::__construct();

    $this->entityTypeManager = $entity_type_manager;
    $this->productImporter = $productImporter;
    $this->connection = $connection;
    $this->licenseImporter = $license_importer;
  }

  /**
   * Run Product Sync polling routine to enqueue products for import.
   *
   * @todo Refactor name of this command to be ipc_syncdb:full-product-sync.
   *
   * @usage ipc_syncdb:product-sync
   *
   * @command ipc_syncdb:product-sync
   * @aliases product-sync
   */
  public function runProductSync() {
    $this->productImporter->enqueueAllProductsFromSyncDb();
    $this->logger()->success(dt('Product Sync polling complete, products with changes to import have been added to IPC Product Sync queue.'));
  }

  /**
   * Delete all existing products from the Drupal db.
   *
   * @param array $options
   *   Associative array of options. Values come from cli, aliases, config, etc.
   *
   * @option delete-orders-first
   *   Delete all existing orders prior to deleting products.
   * @usage ipc_syncdb:delete-products
   * @usage ipc_syncdb:delete-products --delete-orders-first
   *
   * @command ipc_syncdb:delete-products
   * @aliases delete-products
   */
  public function deleteProducts(array $options = ['delete-orders-first' => FALSE]) {
    if ($options['delete-orders-first']) {
      if ($this->io()->confirm(dt("Are you sure you want to delete all existing orders and products?"))) {
        $this->logger()->notice(dt('Deleting all existing orders and products.'));
        $this->productImporter->deleteAllExistingProducts(TRUE);
        $this->logger()->success(dt('Delete operation completed successfully.'));
      }
    }
    else {
      if ($this->io()->confirm(dt("Are you sure you want to delete all existing products?"))) {
        $this->logger()->notice(dt('Deleting all existing products.'));
        $this->productImporter->deleteAllExistingProducts();
        $this->logger()->success(dt('Delete operation completed successfully.'));
      }
    }
  }

  /**
   * Set 'Published' status for all products/variations based on SyncDB values.
   *
   * Sets status to Published for all product variations for which the SyncDB
   * values 'Display in Web Site' = Yes && 'Inactive' = No.
   * Sets status to Published for all products that have at least one variation
   * that is published.
   *
   * @command ipc_syncdb:product-sync:set-published-status
   */
  public function setPublishedStatusForProductsAndVariations() {
    if ($this->io()->confirm(dt("Set Published status for all current products/variations based on values in SyncDB?"))) {
      $this->logger()->notice(dt('Executing: Product Sync - Set Published Status.'));
      $this->productImporter->setPublishedStatusForProductsAndVariations();
      $this->logger()->success(dt('Execution complete.'));
    }
  }

  /**
   * Trigger Order Sync polling routine.
   *
   * @param array $options
   *   Associative array of options. Values come from cli, aliases, config, etc.
   *
   * @option modified-on-after
   *   The modifiedOnAfter value for the API call.
   * @option transaction-type-id
   *   transaction-type-id value for the API call.
   * @usage trigger-order-sync
   * @usage trigger-order-sync --modified-on-after="2021-05-23T16:17:36"
   *
   * @command ipc_syncdb:order-sync:trigger-polling-routine
   * @aliases trigger-order-sync
   */
  public function triggerOrderSyncPollingRoutine(array $options = [
  'modified-on-after' => '',
    'transaction-type-id' => 'all'
]) {
    /* @phpstan-ignore-next-line */
    \Drupal::service('ipc_syncdb.transaction_manager')
      ->pollForChangesToTransactions([
        'modified_on_after' => $options['modified-on-after'],
        'transaction_type_id' => $options['transaction-type-id'],
      ]);
  }

  /**
   * Trigger Product Sync polling routine.
   *
   * @command ipc_syncdb:product-sync:trigger-polling-routine
   * @aliases trigger-product-sync
   */
  public function triggerProductSyncPollingRoutine() {
    /* @phpstan-ignore-next-line */
    \Drupal::service('ipc_syncdb.product_importer')->pollForChangesToProducts();
  }

  /**
   * Trigger User Sync polling routine.
   *
   * @command ipc_syncdb:user-sync:trigger-polling-routine
   * @aliases trigger-user-sync
   */
  public function triggerUserSyncPollingRoutine() {
    /* @phpstan-ignore-next-line */
    \Drupal::service('ipc_syncdb.user_importer')->pollForChangesToUsers();
  }

  /**
   * Trigger Company Sync polling routine.
   *
   * @command ipc_syncdb:company-sync:trigger-polling-routine
   * @aliases trigger-company-sync
   */
  public function triggerCompanySyncPollingRoutine() {
    /* @phpstan-ignore-next-line */
    \Drupal::service('ipc_syncdb.company_importer')->pollForChangesToCompanies();
  }

  /**
   * Trigger PostTransaction for a specific order.
   *
   * @param int $order_number
   *   The order number.
   *
   * @command ipc_syncdb:post-transaction
   * @aliases post-transaction
   *
   * @usage post-transaction 100
   */
  public function triggerPostTransaction(int $order_number) {
    $order = $this->entityTypeManager->getStorage('commerce_order')
      ->load($order_number);
    if (!$order instanceof OrderInterface) {
      $this->logger()
        ->warning(dt('Sorry we can not load the order by this ID - $order_number', ['@order_number' => $order_number]));
      return;
    }
    /* @phpstan-ignore-next-line */
    \Drupal::service('ipc_syncdb.transaction_manager')->postTransaction($order);
  }

  /**
   * Trigger License Sync polling routine.
   *
   * @command ipc_syncdb:license-sync:trigger-polling-routine
   * @aliases trigger-license-sync
   */
  public function triggerLicenseSyncPollingRoutine() {
    /* @phpstan-ignore-next-line */
    \Drupal::service('ipc_syncdb.license_importer')->pollForChangesToLicenses();
  }

  /**
   * Update Order numbers.
   *
   * @command ipc_syncdb:update-order-numbers
   * @aliases update-order-numbers
   */
  public function updateOrderNumbers() {

    $count = $this->connection
      ->update('commerce_order')
      ->expression('order_number', 'CONCAT(:prefix, order_number)', [':prefix' => 'CF-'])
      ->condition('type', ['default', 'quote_purchase'], 'IN')
      ->isNotNull('order_number')
      ->condition('order_number', 'CF-%', 'NOT LIKE')
      ->execute();

    drupal_flush_all_caches();

  }

  /**
   * Update Quote numbers.
   *
   * @command ipc_syncdb:update-quote-numbers
   * @aliases update-quote-numbers
   */
  public function updateQuoteNumbers() {
    $count = $this->connection
      ->update('commerce_invoice_field_data')
      ->expression('invoice_number', 'CONCAT(:prefix, invoice_number)', [':prefix' => 'QT-'])
      ->condition('type', 'quote')
      ->isNotNull('invoice_number')
      ->condition('invoice_number', 'QT-%', 'NOT LIKE')
      ->execute();

    drupal_flush_all_caches();
  }

  /**
   * Update commerce files active licenses with netsuite_order_number.
   *
   * @command ipc_syncdb:update-license-netsuite-order-number
   * @aliases update-license-netsuite-order-number
   */
  public function updateLicenseNetsuiteOrderNumber() {
    $license_storage = $this->entityTypeManager->getStorage('commerce_license');
    $license_ids = $license_storage->getQuery()
      ->condition('type', ['commerce_file', 'commerce_drm_file'], 'IN')
      ->condition('state', 'active')
      ->exists('originating_ns_transaction_id')
      ->accessCheck(FALSE)
      ->execute();

    if (empty($license_ids)) {
      return;
    }

    foreach ($license_ids as $license_id) {
      $license = $license_storage->load($license_id);
      /* @phpstan-ignore-next-line */
      $transaction_id = $license->originating_ns_transaction_id->value;
      $result = $this->licenseImporter->updateDigitalDownloadTransaction($transaction_id, $license_id);
    }
  }

}
