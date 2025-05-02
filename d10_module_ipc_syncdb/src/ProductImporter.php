<?php

namespace Drupal\ipc_syncdb;

use Drupal\advancedqueue\Job;
use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_pricelist\Entity\PriceListItem;
use Drupal\commerce_pricelist\Entity\PriceListItemInterface;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\FileInterface;
use Drupal\ipc_syncdb\Enums\KeywordGroupCodes;
use Drupal\ipc_syncdb\Utilities\ImportProductResult;
use Drupal\ipcsync\Utilities\ProductSync;
use Drupal\taxonomy\Entity\Term;
use Psr\Log\LoggerInterface;

/**
 * Imports products from Sync DB via IPCTransactionApi.
 */
class ProductImporter {

  use StringTranslationTrait;

  // These are constants representing error conditions for Product Importer.
  public const PRODUCTFORMAT_MISSING = 'productFormat field not set';

  public const PRODUCT_TYPE_NOT_FOUND = 'unable to map productFormat value to a product type';

  public const VARIATION_TYPE_NOT_FOUND = 'unable to map productFormat value to a variation type';

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  public $entityTypeManager;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The state store.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The cart manager.
   *
   * @var \Drupal\commerce_cart\CartManagerInterface
   */
  protected $cartManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs a new ProductImporter object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity query factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\commerce_cart\CartManagerInterface $cart_manager
   *   The cart manager.
   * @param \Drupal\Core\Database\Connection $connection
   *   A database connection.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, MessengerInterface $messenger, LoggerInterface $logger, StateInterface $state, ConfigFactoryInterface $config_factory, CartManagerInterface $cart_manager, Connection $connection, AccountInterface $current_user) {
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
    $this->logger = $logger;
    $this->state = $state;
    $this->configFactory = $config_factory;
    $this->cartManager = $cart_manager;
    $this->connection = $connection;
    $this->currentUser = $current_user;
  }

  /**
   * Imports product with the specified SyncDB ID via IPCTransactionAPI.
   *
   * @param int $sync_db_id
   *   The SyncDB ID of the product.
   * @param bool $skip_import_related_products
   *   Product which imports as a related product.
   *
   * @return \Drupal\ipc_syncdb\Utilities\ImportProductResult
   *   The import product result instance.
   */
  public function importProduct(int $sync_db_id, bool $skip_import_related_products = FALSE): ImportProductResult {
    $import_product_result = new ImportProductResult('skipped');
    $message = '';
    // Get the product data from the API.
    $requestVariables = new \stdClass();
    $requestVariables->productId = $sync_db_id;
    $response = ProductSync::getProductByProductId($requestVariables);
    $product_from_response = $response['product'];

    if (isset($product_from_response['type']['productType']) && $product_from_response['type']['productType'] === 'Non-Inventory Item'
    && isset($product_from_response['subType']['productSubType']) && $product_from_response['subType']['productSubType'] === 'For Sale'
    && !isset($product_from_response['benefit']['benefit'])
    ) {
      $product_from_response['format']['productFormat'] = 'Non-Inventory Item';
    }

    // Exit if productFormat value is not set, as it is necessary for import.
    if (!isset($product_from_response['format']['productFormat'])) {
      $this->generateProductImportErrorMessage(self::PRODUCTFORMAT_MISSING, $sync_db_id);

      if (isset($product_from_response['subType']['productSubType']) && $product_from_response['subType']['productSubType'] !== 'For Sale') {
        $message = $this->t('Non-Inventory Product cannot be synced because subType is not `For Sale`.');
      }
      if (isset($product_from_response['benefit']['benefit'])) {
        $message = $this->t('Non-Inventory Product cannot be synced because benefit is not null.');
      }

      return new ImportProductResult('skipped', $message);
    }

    // Exit if unable to map productFormat value to a product type.
    $product_type = $this->determineProductType($product_from_response['format']['productFormat']);
    if (!$product_type) {
      $this->generateProductImportErrorMessage(self::PRODUCT_TYPE_NOT_FOUND, $sync_db_id, $product_from_response['format']['productFormat']);
      return $import_product_result;
    }

    // Exit if unable to map productFormat value to a product variation type.
    $product_variation_type = $this->determineProductVariationType($product_from_response['format']['productFormat'], $product_from_response['formatCode']);
    if (!$product_variation_type) {
      $this->generateProductImportErrorMessage(self::VARIATION_TYPE_NOT_FOUND, $sync_db_id, $product_from_response['format']['productFormat']);
      return $import_product_result;
    }

    // Create/update the product and the product variation.
    $product = $this->createOrUpdateProduct($product_type, $product_from_response);
    $this->ensureVariationIsAssignedToCorrectProduct($product_from_response, $product);
    $variation = $this->createOrUpdateProductVariation($product->getVariations(), $product_variation_type, $product_from_response);
    $product->addVariation($variation);
    $product->set('status', $this->isProductPublished($product));
    $product->save();

    $this->updatePriceLists($variation, $product_from_response);
    $this->removeDiscontinuedProductFromCarts($variation, $product_from_response);
    $this->displayProductImportSuccessMessage($product, $variation);
    // Here we need to add relationship to products.
    // During this import we will create or Update the Product from the
    // "relatedProducts" section
    // I added new variable to prevent infinite recursion.
    if (
      !$skip_import_related_products &&
      in_array($variation->bundle(), ['digital_document', 'kit', 'service', 'non_inventory']) &&
      !empty($product_from_response['relatedProducts'])
    ) {
      $products_ids = [];
      foreach ($product_from_response['relatedProducts'] as $related_product) {
        // At first here we need to Create/or Update the Product,
        // but it's very expensive to import everything,
        // so let's try to find it in the db first.
        /** @var \Drupal\commerce_product\ProductVariationStorageInterface $product_variation_storage */
        $product_variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');
        $result_variation = $product_variation_storage->loadBySku($related_product['productNumber']);
        if ($result_variation instanceof ProductVariationInterface) {
          $products_ids[] = ['target_id' => $result_variation->getProductId()];
        }
        else {
          $import_product_result = $this->importProduct($related_product['productId'], TRUE);
          $result_product = $import_product_result->getProduct();
          if ($result_product instanceof ProductInterface) {
            $products_ids[] = ['target_id' => $result_product->id()];
          }
        }
      }

      $product->set('related_products', $products_ids);
      $product->save();
    }

    return new ImportProductResult('saved', $message, $product);
  }

  /**
   * Creates or updates a product with data obtained from SyncDB.
   *
   * @param string $product_type
   *   The product type of the product.
   * @param array $product_from_response
   *   The product data from the Api response.
   *
   * @return \Drupal\commerce_product\Entity\Product
   *   The product.
   */
  public function createOrUpdateProduct(string $product_type, array $product_from_response) {
    $newly_created = FALSE;

    $product = $this->getProductIfProductExists(
      $product_type,
      $product_from_response['language']['language'],
      $product_from_response['programPrefix'],
      $product_from_response['programNumber'],
      $product_from_response['ipcProductType'],
      $product_from_response['revision'],
      $product_from_response['productId'],
      $product_from_response['amendmentDetails'],
      $product_from_response['addendumType']
    );
    if (!$product) {
      /** @var \Drupal\commerce_store\StoreStorageInterface $store_storage */
      $store_storage = $this->entityTypeManager->getStorage('commerce_store');
      $default_store = $store_storage->loadDefault();
      $product = Product::create([
        'type' => $product_type,
        'stores' => [$default_store],
      ]);
      $product->save();
      $newly_created = TRUE;
    }
    $this->setProductFields($product_type, $product, $product_from_response, $newly_created);
    return $product;
  }

  /**
   * Sets the product fields with values retrieved via api call.
   *
   * @param string $product_type
   *   The product type.
   * @param \Drupal\commerce_product\Entity\Product $product
   *   The product.
   * @param array $product_from_response
   *   The product from the Api response.
   * @param bool $newly_created
   *   Whether or not the product is newly-created by current import process.
   */
  protected function setProductFields(string $product_type, Product &$product, array $product_from_response, bool $newly_created = FALSE): void {
    switch ($product_type) {
      case 'document':
        $this->setCoreFieldsCommonToAllProducts($product, $product_from_response, $newly_created);
        $product->set('isbn', $product_from_response['isbn']);
        $product->set('pages', $product_from_response['numberOfPages']);
        $product->set('table_of_contents', $product_from_response['tableOfContentsURL']);
        if ($product_from_response['publishedYear']) {
          $this->setTaxonomyTermField('year', $product, 'years', $product_from_response['publishedYear']);
        }
        if (isset($product_from_response['revision']['revision'])) {
          $this->setTaxonomyTermField('ipc_revision', $product, 'ipc_revisions', $product_from_response['revision']['revision']);
        }
        $published_date = substr((string) $product_from_response['publishedDate'], 0, 10);
        $product->set('published_date', $published_date);
        $product->set('ansi_approved', $product_from_response['ansiApproved']);
        $product->set('dod_adopted', $product_from_response['dodAdopted']);
        $product->set('sample_pages_url', $product_from_response['samplePagesURL']);
        $product->set('toc_url', $product_from_response['tableOfContentsURL']);
        if ($product_from_response['laterRevision']['productId']) {
          $this->setLaterRevisionField($product, $product_from_response);
        }
        $product_variation_type = $this->determineProductVariationType($product_from_response['format']['productFormat'], $product_from_response['formatCode']);
        if (isset($product_from_response['searchKeywords']) && $product_variation_type === 'digital_document') {
          $product->set('search_keywords', $product_from_response['searchKeywords']);
        }

        $product->save();
        break;

      case 'service':
        $this->setCoreFieldsCommonToAllProducts($product, $product_from_response, $newly_created);

        if (isset($product_from_response['searchKeywords'])) {
          $product->set('search_keywords', $product_from_response['searchKeywords']);
        }
        $product->save();
        break;

      case 'kit':
        $this->setCoreFieldsCommonToAllProducts($product, $product_from_response, $newly_created);
        if ($product_from_response['publishedYear']) {
          $this->setTaxonomyTermField('year', $product, 'years', $product_from_response['publishedYear']);
        }
        if (isset($product_from_response['revision']['revision'])) {
          $this->setTaxonomyTermField('ipc_revision', $product, 'ipc_revisions', $product_from_response['revision']['revision']);
        }
        $published_date = substr((string) $product_from_response['publishedDate'], 0, 10);
        $product->set('published_date', $published_date);
        $product->set('ansi_approved', $product_from_response['ansiApproved']);
        $product->set('dod_adopted', $product_from_response['dodAdopted']);
        if ($product_from_response['laterRevision']['productId']) {
          $this->setLaterRevisionField($product, $product_from_response);
        }
        if (isset($product_from_response['searchKeywords'])) {
          $product->set('search_keywords', $product_from_response['searchKeywords']);
        }
        $product->save();
        break;

      case 'non_inventory':
        $product->setOwnerId(0);
        $product->setTitle($product_from_response['description']);
        $is_published = $newly_created ? FALSE : $product->get('status')->value;
        $product->set('status', $is_published);
        $product->save();

        break;

    }
  }

  /**
   * Creates or updates a variation with data obtained from SyncDB.
   *
   * @param array $variations
   *   The existing product variations to check against.
   * @param string $product_variation_type
   *   The product variation type.
   * @param array $product_from_response
   *   The product data from the Api response.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariation
   *   The product variation.
   */
  public function createOrUpdateProductVariation(array $variations, string $product_variation_type, array $product_from_response) {
    $newly_created = FALSE;
    $variation = $this->getVariationIfVariationExists(
      $variations,
      $product_variation_type,
      $product_from_response['productId'],
    );
    if (!$variation) {
      $variation = ProductVariation::create([
        'type' => $product_variation_type,
      ]);
      $variation->save();
      $newly_created = TRUE;
    }
    $this->setProductVariationFields($product_variation_type, $variation, $product_from_response, $newly_created);
    return $variation;
  }

  /**
   * Sets the product variation fields with values retrieved via api call.
   *
   * @param string $variation_type
   *   The product variation type.
   * @param \Drupal\commerce_product\Entity\ProductVariation $variation
   *   The product variation.
   * @param array $product_from_response
   *   The product from the Api response.
   * @param bool $newly_created
   *   Whether or not the product is newly-created by current import process.
   */
  protected function setProductVariationFields(string $variation_type, ProductVariation &$variation, array $product_from_response, bool $newly_created = FALSE): void {
    switch ($variation_type) {
      case 'physical_document':
        $this->setCoreFieldsCommonToAllVariations($variation, $product_from_response, $newly_created);
        $this->setProductVariationWeightField($variation, $product_from_response);
        $release_date = substr((string) $product_from_response['releaseDate'], 0, 10);
        $variation->set('release_date', $release_date);
        $this->setProductVariationFormatField($variation, $product_from_response);
        $this->setProductVariationProductFormatField($variation, $product_from_response);
        $variation->set('dropshipped', $product_from_response['dropShipProduct']);
        $variation->set('stock_level', $product_from_response['quantityAvailable']);
        $variation->save();
        break;

      case 'digital_document':
        $this->setCoreFieldsCommonToAllVariations($variation, $product_from_response, $newly_created);
        $release_date = substr((string) $product_from_response['releaseDate'], 0, 10);
        $variation->set('release_date', $release_date);
        $this->setProductVariationFormatField($variation, $product_from_response);
        $variation->set('drm', $product_from_response['drm']);
        $this->setProductVariationProductFormatField($variation, $product_from_response);
        $this->setProductVariationItemTypeField($variation, $product_from_response);
        $this->setProductVariationLicenseFields($variation, $product_from_response);
        if ($product_from_response['minimumQuantity']) {
          $variation->set('minimum_order_quantity', $product_from_response['minimumQuantity']);
        }
        if ($product_from_response['drmSourceFile']) {
          $variation->set('drm_source_file', $product_from_response['drmSourceFile']);
        }

        $variation->save();
        break;

      case 'multi_device_license':
        $this->setCoreFieldsCommonToAllVariations($variation, $product_from_response, $newly_created);
        $release_date = substr((string)$product_from_response['releaseDate'], 0, 10);
        $variation->set('release_date', $release_date);
        $this->setProductVariationFormatField($variation, $product_from_response);
        $variation->set('drm', $product_from_response['drm']);
        $this->setProductVariationProductFormatField($variation, $product_from_response);
        $this->setProductVariationItemTypeField($variation, $product_from_response);
        if ($product_from_response['minimumQuantity']) {
          $variation->set('minimum_order_quantity', $product_from_response['minimumQuantity']);
        }
        $variation->save();
        break;

      case 'service':
        $this->setCoreFieldsCommonToAllVariations($variation, $product_from_response, $newly_created);
        $variation->set('minimum_order_quantity', $product_from_response['minimumQuantity']);
        $variation->save();
        break;

      case 'kit':
        $this->setCoreFieldsCommonToAllVariations($variation, $product_from_response, $newly_created);
        $this->setProductVariationWeightField($variation, $product_from_response);
        if ($product_from_response['productComponents']) {
          $this->setKitProductsField($variation, $product_from_response);
        }
        $variation->save();
        break;

      case 'non_inventory':
        $this->setCoreFieldsCommonToAllVariations($variation, $product_from_response, $newly_created);
        $variation->save();
        break;

    }
  }

  /**
   * Enqueues all Product IDs from SyncDB for data import.
   *
   * @param bool $delete_existing
   *   Delete all existing products prior to enqueueing.
   */
  public function enqueueAllProductsFromSyncDb(bool $delete_existing = FALSE): void {
    if ($delete_existing) {
      $this->deleteAllExistingProducts();
    }
    $queue_storage = $this->entityTypeManager->getStorage('advancedqueue_queue');
    /** @var \Drupal\advancedqueue\Entity\QueueInterface $queue */
    $queue = $queue_storage->load('ipc_product_sync');
    $all_product_ids = $this->getAllProductIdsFromSyncDb();
    foreach ($all_product_ids as $product_id) {
      $product_import_job = Job::create('syncdb_product_sync', [
        'product_id' => $product_id,
      ]);
      $queue->enqueueJob($product_import_job);
    }
  }

  /**
   * Poll for changes to products in the Sync DB via IPCTransactionAPI.
   */
  public function pollForChangesToProducts(): void {
    $queue_storage = $this->entityTypeManager->getStorage('advancedqueue_queue');
    /** @var \Drupal\advancedqueue\Entity\QueueInterface $queue */
    $queue = $queue_storage->load('ipc_product_sync');
    $product_ids = $this->getUpdatedProductIdsFromSyncDb();
    foreach ($product_ids as $product_id) {
      $product_import_job = Job::create('syncdb_product_sync', [
        'product_id' => $product_id,
      ]);
      $queue->enqueueJob($product_import_job);
    }
  }

  /**
   * Delete all existing products and product variations.
   *
   * @param bool $delete_orders
   *   Delete all existing orders prior to enqueueing products.
   */
  public function deleteAllExistingProducts(bool $delete_orders = FALSE): void {
    // Delete orders.
    if ($delete_orders) {
      $results_orders = $this->connection->select('commerce_order', 'co')
        ->fields('co', ['order_id'])
        ->execute()->fetchCol();
      if ($results_orders) {
        $this->processDeleteOrdersBatch($results_orders);
      }
    }
    // Delete product variations.
    $results_variations = $this->connection->select('commerce_product_variation', 'cpv')
      ->fields('cpv', ['variation_id'])
      ->execute()->fetchCol();
    if ($results_variations) {
      $this->processDeleteVariationsBatch($results_variations);
    }
    // Delete products.
    $results_products = $this->connection->select('commerce_product', 'cp')
      ->fields('cp', ['product_id'])
      ->execute()->fetchCol();
    if ($results_products) {
      $this->processDeleteProductsBatch($results_products);
    }
    drush_backend_batch_process();
  }

  /**
   * Set 'Published' status for all products/variations based on SyncDB values.
   */
  public function setPublishedStatusForProductsAndVariations(): void {
    // Set 'Published' status for all product variations.
    $results_variations = $this->connection->select('commerce_product_variation', 'cpv')
      ->fields('cpv', ['variation_id'])
      ->execute()->fetchCol();
    if ($results_variations) {
      $batch = [
        'title' => $this->t('Set Published status for all product variations'),
        'operations' => [],
        'init_message' => $this->t('Commencing execution of Set Published Status'),
        'progress_message' => $this->t('Processed @current out of @total.'),
        'error_message' => $this->t('An error occurred during processing'),
        'finished' => '\Drupal\ipc_syncdb\ProductImporter::batchFinishedCallback',
      ];
      foreach ($results_variations as $id) {
        $batch['operations'][] = [
          '\Drupal\ipc_syncdb\ProductImporter::setPublishedStatusForVariationCallback',
          [$id],
        ];
      }
      batch_set($batch);
    }
    drush_backend_batch_process();
  }

  /**
   * Callback to set 'Published' status for a product variation.
   *
   * Currently this function also sets the status for the product
   * that is associated with the variation.
   *
   * @param int $id
   *   The id of the entity to delete.
   * @param object $context
   *   The batch operation context.
   */
  public function setPublishedStatusForVariationCallback(int $id, object &$context): void {
    $storage_handler = $this->entityTypeManager
      ->getStorage('commerce_product_variation');
    /** @var \Drupal\commerce_product\Entity\ProductVariation $variation */
    $variation = $storage_handler->load($id);
    $sync_db_id = $variation->get('syncdb_id')->value;

    if ($sync_db_id) {
      $requestVariables = new \stdClass();
      $requestVariables->productId = $sync_db_id;
      $response = ProductSync::getProductByProductId($requestVariables);
      $product_from_response = $response['product'];

      $display_in_website = $product_from_response['displayInWebsite'];
      $inactive = $product_from_response['inActive'];
      $discontinued_item = $product_from_response['discontinuedItem'];
      if ($display_in_website && !$inactive && !$discontinued_item) {
        $variation->set('status', TRUE);
        $variation->save();
        $product_id = $variation->getProductId();
        $product_storage = $this->entityTypeManager
          ->getStorage('commerce_product');
        /** @var \Drupal\commerce_product\Entity\Product $product */
        $product = $product_storage->load($product_id);
        $product->set('status', TRUE);
        $product->save();
      }
    }
  }

  /**
   * Creates batch for processing delete operations for orders.
   *
   * @param array $ids
   *   The ids of the orders to process.
   */
  protected function processDeleteOrdersBatch(array $ids): void {
    $batch = [
      'title' => $this->t('Delete existing orders'),
      'operations' => [],
      'init_message' => $this->t('Commencing'),
      'progress_message' => $this->t('Processed @current out of @total.'),
      'error_message' => $this->t('An error occurred during processing'),
      'finished' => '\Drupal\ipc_syncdb\ProductImporter::batchFinishedCallback',
    ];
    foreach ($ids as $id) {
      $batch['operations'][] = [
        '\Drupal\ipc_syncdb\ProductImporter::deleteOrderCallback',
        [$id],
      ];
    }
    batch_set($batch);
  }

  /**
   * Creates batch for processing delete operations for product variations.
   *
   * @param array $ids
   *   The ids of the product variations to process.
   */
  protected function processDeleteVariationsBatch(array $ids): void {
    $batch = [
      'title' => $this->t('Delete existing product variations'),
      'operations' => [],
      'init_message' => $this->t('Commencing'),
      'progress_message' => $this->t('Processed @current out of @total.'),
      'error_message' => $this->t('An error occurred during processing'),
      'finished' => '\Drupal\ipc_syncdb\ProductImporter::batchFinishedCallback',
    ];
    foreach ($ids as $id) {
      $batch['operations'][] = [
        '\Drupal\ipc_syncdb\ProductImporter::deleteVariationCallback',
        [$id],
      ];
    }
    batch_set($batch);
  }

  /**
   * Creates batch for processing delete operations for products.
   *
   * @param array $ids
   *   The ids of the products to process.
   */
  protected function processDeleteProductsBatch(array $ids): void {
    $batch = [
      'title' => $this->t('Delete existing products'),
      'operations' => [],
      'init_message' => $this->t('Commencing'),
      'progress_message' => $this->t('Processed @current out of @total.'),
      'error_message' => $this->t('An error occurred during processing'),
      'finished' => '\Drupal\ipc_syncdb\ProductImporter::batchFinishedCallback',
    ];
    foreach ($ids as $id) {
      $batch['operations'][] = [
        '\Drupal\ipc_syncdb\ProductImporter::deleteProductCallback',
        [$id],
      ];
    }
    batch_set($batch);
  }

  /**
   * Callback for Delete Orders batch operation.
   *
   * @param int $id
   *   The id of the entity to delete.
   * @param object $context
   *   The batch operation context.
   */
  public function deleteOrderCallback(int $id, object &$context): void {
    $storage_handler = $this->entityTypeManager
      ->getStorage('commerce_order');
    $entity = $storage_handler->load($id);
    $storage_handler->delete([$entity]);
  }

  /**
   * Callback for Delete Product Variations batch operation.
   *
   * @param int $id
   *   The id of the entity to delete.
   * @param object $context
   *   The batch operation context.
   */
  public function deleteVariationCallback(int $id, object &$context): void {
    $storage_handler = $this->entityTypeManager
      ->getStorage('commerce_product_variation');
    $entity = $storage_handler->load($id);
    $storage_handler->delete([$entity]);
  }

  /**
   * Callback for Delete Products batch operation.
   *
   * @param int $id
   *   The id of the entity to delete.
   * @param object $context
   *   The batch operation context.
   */
  public function deleteProductCallback(int $id, object &$context): void {
    $storage_handler = $this->entityTypeManager
      ->getStorage('commerce_product');
    $entity = $storage_handler->load($id);
    $storage_handler->delete([$entity]);
  }

  /**
   * Finished Callback for batch operations.
   *
   * @param bool $success
   *   Indicate that the batch API tasks were all completed successfully.
   * @param array $results
   *   An array of all the results that were updated in update_do_one().
   * @param array $operations
   *   A list of the operations that had not been completed by the batch API.
   */
  public function batchFinishedCallback($success, array $results, array $operations): void {
    if (!$success) {
      $this->logger->error($this->t('Error encountered during batch processing. <br><b>Results:</b> <pre><code>@results</code></pre> <b>Operations:</b> <pre><code>@operations</code></pre>', [
        '@results' => print_r($results, TRUE),
        '@operations' => print_r($operations, TRUE),
      ]));
    }
  }

  /**
   * Get the product IDs for all products that have been updated since last run.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function getUpdatedProductIdsFromSyncDb() {
    $run_time = ApiHelper::getRunTimeDateTimeString();
    $all_ids = [];

    $requestVariables = new \stdClass();
    $requestedPage = 1;
    $requestVariables->requestedPage = $requestedPage;

    $last_run_time = $this->state->get('ipc_product_sync_product_importer_last_run');
    $modifiedOnAfter = $last_run_time ?: ApiHelper::POLLING_ROUTINE_START_TIME;
    $requestVariables->modifiedOnAfter = $modifiedOnAfter;
    $response = ProductSync::getProductList($requestVariables);
    $productList = $response['productList'];

    while ($productList) {
      $ids = array_column($productList, 'productId');
      $all_ids = array_merge($all_ids, $ids);
      $requestedPage++;
      $requestVariables->requestedPage = $requestedPage;
      $response = ProductSync::getProductList($requestVariables);
      $productList = $response['productList'];
    }

    $this->state->set('ipc_product_sync_product_importer_last_run', $run_time);
    return $all_ids;
  }

  /**
   * Get the product IDs for all products from SyncDB via IPCTransactionAPI.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function getAllProductIdsFromSyncDb() {
    $all_ids = [];
    $requestedPage = 1;
    $requestVariables = new \stdClass();
    $requestVariables->requestedPage = $requestedPage;
    $response = ProductSync::getProductList($requestVariables);
    $productList = $response['productList'];

    while ($productList) {
      $ids = array_column($productList, 'productId');
      $all_ids = array_merge($all_ids, $ids);
      $requestedPage++;
      $requestVariables->requestedPage = $requestedPage;
      $response = ProductSync::getProductList($requestVariables);
      $productList = $response['productList'];
    }

    return $all_ids;
  }

  /**
   * Determines product type of the imported product.
   *
   * @param string $product_format
   *   The value of the productFormat field returned from the api call.
   */
  public function determineProductType($product_format) {
    switch ($product_format) {
      case 'CD':
      case 'DVD':
      case 'Hard Copy':
      case 'Download':
      case 'Download Item':
        return 'document';

      case 'Kit/Bundle':
        return 'kit';

      case 'Subscription':
      case 'Exam Funds':
      case 'Other':
        return 'service';

      case 'Non-Inventory Item':
        return 'non_inventory';

      default:
        return NULL;
    }
  }

  /**
   * Determines product variation type of the imported product.
   *
   * @param string $product_format
   *   The value of the productFormat field returned from the api call.
   * @param string $format_code
   *   The value of the productFormat field returned from the api call.
   */
  protected function determineProductVariationType($product_format, $format_code) {
    switch ($product_format) {
      case 'CD':
      case 'DVD':
      case 'Hard Copy':
        return 'physical_document';

      case 'Download Item':
        return 'digital_document';

      case 'Download':
        if ($format_code === 'MDL') {
          return 'multi_device_license';
        }
        else {
          return 'digital_document';
        }

      case 'Kit/Bundle':
        return 'kit';

      case 'Subscription':
      case 'Exam Funds':
      case 'Other':
        return 'service';

      case 'Non-Inventory Item':
        return 'non_inventory';

      default:
        return NULL;
    }
  }

  /**
   * Returns product if product that matches target identifiers exists.
   *
   * @param string $product_type
   *   The product type of the product.
   * @param string $language
   *   The value of the language field returned from the api call.
   * @param string $programPrefix
   *   Progeram prefix of the product
   * @param string $programNumber
   *   Program number of the product
   * @param string $ipcProductType
   *   Product type type of the product
   * @param string $ipc_revision
   *   The value of the ipc revision field returned from the api call.
   * @param string $syncdb_id
   *   Optional SyncDB ID to look for on a product's variation.
   * @param string $amendment_details
   *   Addendum details of the product
   * @param string $addendum_type
   *   Addendum type of the product
   */
  protected function getProductIfProductExists($product_type, $language, $programPrefix = '',
    $programNumber = '', $ipcProductType = '', $ipc_revision = '', $syncdb_id = '', $amendment_details = '', $addendum_type = '') {
    $product_storage = $this->entityTypeManager->getStorage('commerce_product');
    $language_tid = $language ? $this->getTaxonomyTermTid('languages', $language) : NULL;

    $ipc_revision_tid = isset($ipc_revision['revision']) ? $this->getTaxonomyTermTid('ipc_revisions', $ipc_revision['revision']) : NULL;
    $addendum_type_tid = $addendum_type['addendumType'] ? $this->getTaxonomyTermTid('ipc_addendum_types', $addendum_type['addendumType']) : NULL;

    // Build an entity query that can properly accommodate empty field values.
    $query = $product_storage->getQuery();
    $query
      ->condition('type', $product_type)
      ->sort('product_id', 'DESC')
      ->accessCheck(FALSE);

    // Service and non inventory products are matched via SyncDB ID on a child variation, because
    // they do not currently have all the same matching field values set as the
    // other product types. Additionally, we know each Service and non inventory product will
    // have only a single variation.
    if (in_array($product_type, ['service', 'non_inventory'])) {
      // If we did not get a SyncDB ID, return FALSE now.
      if (empty($syncdb_id)) {
        return FALSE;
      }

      $query->condition('variations.entity:commerce_product_variation.syncdb_id', $syncdb_id);
    }

    // Only documents and kits currently match on the field values.
    if (in_array($product_type, ['document', 'kit'])) {
      // All product types should have a language value set. Only Document and
      // Kit products will have the others set at the moment.
      if (
        isset($programPrefix) && !empty($programPrefix['programPrefix']) &&
        isset($programNumber) && !empty($programNumber['programNumber'])
      ) {
        $dn_parent_name = $programPrefix['programPrefix'] . '-' . $programNumber['programNumber'];

        if (isset($ipcProductType) && !empty($ipcProductType['ipcProductType'])) {
          $dn_parent_name = $dn_parent_name . ' ' . $ipcProductType['ipcProductType'];
        }
      }

      $ipc_document_number_tid = isset($dn_parent_name) ? $this->getTaxonomyTermTid('ipc_document_numbers', $dn_parent_name) : NULL;
      if ($ipc_document_number_tid === NULL) {
        $query->notExists('ipc_document_number');
      }
      else {
        $query->condition('ipc_document_number.target_id', $ipc_document_number_tid);
      }

      if (empty($ipc_revision_tid)) {
        $query->notExists('ipc_revision');
      }
      else {
        $query->condition('ipc_revision.target_id', $ipc_revision_tid);
      }

      if ($language_tid === NULL) {
        $query->notExists('language');
      }
      else {
        $query->condition('language.target_id', $language_tid);
      }

      if (empty($amendment_details)) {
        $query->notExists('amendment_details');
      }
      else {
        $query->condition('amendment_details', $amendment_details);
      }

      if ($addendum_type_tid === NULL) {
        $query->notExists('addendum_type');
      }
      else {
        $query->condition('addendum_type.target_id', $addendum_type_tid);
      }

    }

    $result = $query->execute();
    $product_ids = array_values($result);

    if ($product_ids) {
      $product_id = reset($product_ids);
      return $product_storage->load($product_id);
    }
    return FALSE;
  }

  /**
   * Returns variation if variation that matches target identifiers exists.
   *
   * @param array $variations
   *   The existing product variations to check against.
   * @param string $variation_type
   *   The product variation type.
   * @param string $productId
   *   The value of the productId (Sync DB Id) field returned from the api call.
   */
  protected function getVariationIfVariationExists(array $variations, string $variation_type, string $productId) {
    foreach ($variations as $variation) {
      $type = $variation->get('type')->first();
      $target_variation = $type->getValue('target_id');
      $syncdb_id_first_value = $variation->get('syncdb_id')->first();
      if ($syncdb_id_first_value) {
        $syncdb_id = $syncdb_id_first_value->getValue('value');
        if ($target_variation['target_id'] == $variation_type && $syncdb_id['value'] == $productId) {
          return $variation;
        }
      }
    }
    return FALSE;
  }

  /**
   * Sets the value of the given taxonomy field to the corresponding tid.
   *
   * Creates a taxonomy term with the given term name if a corresponding
   * term does not yet exist.
   *
   * @param string $field_name
   *   The name of the vocabulary term.
   * @param \Drupal\commerce_product\Entity\Product $product
   *   The product.
   * @param string $vid
   *   The taxonomy vocabulary id.
   * @param string|null $term_name
   *   The name of the vocabulary term.
   */
  protected function setTaxonomyTermField(string $field_name, Product $product, string $vid, ?string $term_name): void {
    if (($term_name === NULL) || (trim($term_name) === '')) {
      return;
    }
    $term_name = trim($term_name);
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $storage->loadByProperties([
      'vid' => $vid,
      'name' => $term_name,
    ]);
    if ($terms) {
      $term = reset($terms);
    }
    else {
      // No Taxonomy term matching our term name, need to create a term.
      $term = Term::create([
        'name' => $term_name,
        'vid' => $vid,
      ]);
      $term->save();
    }
    $product->set($field_name, ['target_id' => $term->id()]);
  }

  /**
   * Get tid of taxonomy term given the term name.
   *
   * @param string $vid
   *   The taxonomy vocabulary id.
   * @param string $term_name
   *   The name of the vocabulary term.
   *
   * @return int|null
   *   The term id or null if not found.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getTaxonomyTermTid(string $vid, string $term_name): ?int {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $storage->loadByProperties([
      'vid' => $vid,
      'name' => trim($term_name),
    ]);
    if ($terms) {
      /** @var \Drupal\taxonomy\TermInterface $term */
      $term = reset($terms);
      return $term->id();
    }
    return NULL;
  }

  /**
   * Sets the core product fields with values retrieved via api call.
   *
   * @param \Drupal\commerce_product\Entity\Product $product
   *   The product.
   * @param array $product_from_response
   *   The product from the Api response.
   * @param bool $newly_created
   *   Whether or not the product is newly-created by current import process.
   */
  protected function setCoreFieldsCommonToAllProducts(Product $product, array $product_from_response, bool $newly_created): void {
    $product->setOwnerId(0);
    $product->setTitle($product_from_response['pageTitle']);
    $is_published = $newly_created ? FALSE : $product->get('status')->value;
    $product->set('status', $is_published);
    if ($product_from_response['language']['language']) {
      $this->setTaxonomyTermField('language', $product, 'languages', $product_from_response['language']['language']);
    }
    $product->set('body', [
      'value' => $product_from_response['storeDetailedDescription'],
      'format' => 'basic_html',
    ]);
    $product->set('current_revision', $product_from_response['currentRevision'] ?? FALSE);
    $product->set('amendment_details', $product_from_response['amendmentDetails'] ?? '');
    $product->set('display_name', $product_from_response['storeDisplayName'] ?? '');
    // Here we need to fetch taxonomy terms for product.
    // Now we need to create mapped array of terms.
    $mapped_terms_array = $this->buildTermsArray($product_from_response);
    if (!empty($mapped_terms_array)) {
      $this->processTermsUpdate($product, $mapped_terms_array);
    }
    $product->save();
  }

  /**
   * Helper function to update product with terms from API.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product.
   * @param array $mapped_terms
   *   The array of mapped terms.
   */
  private function processTermsUpdate(ProductInterface $product, array $mapped_terms): void {
    foreach ($mapped_terms as $field => $term_data) {
      if ($product->hasField($field)) {
        $terms_ids = [];
        if (!empty($term_data)) {
          $parent_term_id = 0;
          if (isset($term_data['parent'])) {
            $parent_term_id = $this->createOrUpdateTerm($term_data['parent']) ?? 0;
          }
          if (isset($term_data['child'])) {
            $target_id = $this->createOrUpdateTerm($term_data['child'], $parent_term_id);
            if ($target_id !== NULL) {
              $terms_ids[] = ['target_id' => $target_id];
            }
          }

          // Also we can have multiple values.
          if (isset($term_data['children'])) {
            foreach ($term_data['children'] as $child) {
              $target_id = $this->createOrUpdateTerm($child, $parent_term_id);
              if ($target_id !== NULL) {
                $terms_ids[] = ['target_id' => $target_id];
              }
            }
          }
        }

        $product->set($field, $terms_ids);
      }
    }
  }

  /**
   * Helper function to create or update a vocabulary term.
   *
   * @param array $term_data
   *   The term data.
   * @param int $parent_term_id
   *   The parent term id.
   *
   * @return int|null
   *   Return term ID or null if we could not create/update the term.
   */
  private function createOrUpdateTerm(array $term_data, int $parent_term_id = 0): ?int {
    $term_name = $term_data['name'];
    if ($term_name === NULL || trim($term_name) === '') {
      return NULL;
    }
    $term_name = trim($term_name);
    $is_hashed_netsuite_id = isset($term_data['hashed_netsuite_id']);
    /** @var \Drupal\taxonomy\TermStorageInterface $term_storage */
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');

    $load_properties = $is_hashed_netsuite_id ?
      [
        'vid' => $term_data['vocabulary_id'],
        'hashed_netsuite_id' => $term_data['hashed_netsuite_id'],
      ] :
      [
        'vid' => $term_data['vocabulary_id'],
        'netsuite_id' => $term_data['netsuite_id'],
      ];
    $terms = $term_storage->loadByProperties($load_properties);
    if (!empty($terms)) {
      /** @var \Drupal\taxonomy\TermInterface $term */
      $term = reset($terms);
      $term->setName($term_name);
      $term->set('parent', $parent_term_id);

      // Set ipc_revisions label.
      if ($term->bundle() === 'ipc_revisions') {
        if (is_null($term->get('label')->value) || $term->get('label')->value === $term_name) {
          $label = $term_name;
          if ($label == '0') {
            $label = 'Original Version';
          }
          $term->set('label', $label);
        }
      }

      $term->save();
    }
    else {
      $term = $term_storage->create([
        'name' => $term_name,
        'vid' => $term_data['vocabulary_id'],
      ]);
      $term->save();
      if (!empty($parent_term_id)) {
        $term->set('parent', $parent_term_id);
      }

      if ($is_hashed_netsuite_id) {
        $term->set('hashed_netsuite_id', $term_data['hashed_netsuite_id']);
      }
      else {
        $term->set('netsuite_id', $term_data['netsuite_id']);
      }

      $term->save();
    }

    return $term->id();
  }

  /**
   * Helper function to build array of terms which we need to update.
   *
   * @param array $product
   *   The product array from API.
   *
   * @return array
   *   The result array of terms.
   */
  private function buildTermsArray(array $product): array {
    $result = [];
    $sync_db_id = $product['productId'];
    // Addendum Type.
    if (isset($product['addendumType']) && !empty($product['addendumType']['nsAddendumTypeId'])) {
      $result['addendum_type']['child'] = [
        'vocabulary_id' => 'ipc_addendum_types',
        'name' => $product['addendumType']['addendumType'],
        'netsuite_id' => $product['addendumType']['nsAddendumTypeId'],
      ];
    }
    else {
      $result['addendum_type'] = [];
      $this->logger->warning($this->t('Unable set "Addendum Type" for product with SyncDB ID @sync_db_id, because it does not exist at response.', [
        '@sync_db_id' => $sync_db_id,
      ]));
    }
    // Product types.
    if (
      isset($product['variety']) &&
      !empty($product['variety']['nsProductVarietyId']) &&
      isset($product['ipcProductType']) &&
      !empty($product['ipcProductType']['nsIPCProductTypeId'])
    ) {
      $pt_parent_ns_id = $product['variety']['nsProductVarietyId'];
      $pt_child_ns_id = $pt_parent_ns_id . '_' . $product['ipcProductType']['nsIPCProductTypeId'];
      $result['ipc_product_type'] = [
        'parent' => [
          'vocabulary_id' => 'ipc_product_types',
          'name' => $product['variety']['productVariety'],
          'hashed_netsuite_id' => Crypt::hashBase64($pt_parent_ns_id),
        ],
        'child' => [
          'vocabulary_id' => 'ipc_product_types',
          'name' => $product['ipcProductType']['ipcProductType'],
          'hashed_netsuite_id' => Crypt::hashBase64($pt_child_ns_id),
        ],
      ];
    }
    else {
      $result['ipc_product_type'] = [];
      $this->logger->warning($this->t('Unable set "Product types" for product with SyncDB ID @sync_db_id, because "Variety/ipcProductType" do not exist at response.', [
        '@sync_db_id' => $sync_db_id,
      ]));
    }
    // Document number.
    if (
      isset($product['programPrefix']) &&
      !empty($product['programPrefix']['nsProgramPrefixId']) &&
      isset($product['programNumber']) &&
      !empty($product['programNumber']['nsProgramNumberId']) &&
      isset($product['ipcProductType']) &&
      !empty($product['ipcProductType']['nsIPCProductTypeId'])
    ) {
      // For the document number we have specific flow.
      $dn_parent_ns_id = $product['programPrefix']['nsProgramPrefixId'] . '_' . $product['programNumber']['nsProgramNumberId'];
      $dn_child_ns_id = $dn_parent_ns_id . '_' . $product['ipcProductType']['nsIPCProductTypeId'];
      $dn_parent_name = $product['programPrefix']['programPrefix'] . '-' . $product['programNumber']['programNumber'];
      $result['ipc_document_number'] = [
        'parent' => [
          'vocabulary_id' => 'ipc_document_numbers',
          'name' => $dn_parent_name,
          'hashed_netsuite_id' => Crypt::hashBase64($dn_parent_ns_id),
        ],
        'child' => [
          'vocabulary_id' => 'ipc_document_numbers',
          'name' => $dn_parent_name . ' ' . $product['ipcProductType']['ipcProductType'],
          'hashed_netsuite_id' => Crypt::hashBase64($dn_child_ns_id),
        ],
      ];
    }
    else {
      $result['ipc_document_number'] = [];
      $this->logger->warning($this->t('Unable set "Document number" for product with SyncDB ID @sync_db_id, because "programPrefix/programNumber/ipcProductType" do not exist at response.', [
        '@sync_db_id' => $sync_db_id,
      ]));
    }

    // Product keywords.
    if (isset($product['productKeywords'])) {
      foreach ($product['productKeywords'] as $keyword) {
        if (
          $keyword['nsKeywordId'] &&
          !empty($keyword['keywordGroup']) &&
          $keyword['keywordGroup']['keywordGroupCode'] === KeywordGroupCodes::POPULAR_TOPICS
        ) {
          $result['popular_topics']['children'][] = [
            'vocabulary_id' => 'ipc_popular_topics',
            'name' => $keyword['keyword'],
            'netsuite_id' => $keyword['nsKeywordId'],
          ];
        }
      }
    }
    else {
      $result['popular_topics'] = [];
      $this->logger->warning($this->t('Unable set "productKeywords" for product with SyncDB ID @sync_db_id, because it does not exist at response.', [
        '@sync_db_id' => $sync_db_id,
      ]));
    }

    // Revisions.
    if (
      isset($product['revision']) &&
      !empty($product['revision']['nsRevisionId'])
    ) {
      $result['ipc_revision']['child'] = [
        'vocabulary_id' => 'ipc_revisions',
        'name' => $product['revision']['revision'],
        'netsuite_id' => $product['revision']['nsRevisionId'],
      ];
    }
    else {
      $result['ipc_revision'] = [];
      $this->logger->warning($this->t('Unable set "revision" for product with SyncDB ID @sync_db_id, because it does not exist at response.', [
        '@sync_db_id' => $sync_db_id,
      ]));
    }
    // Processes.
    if (isset($product['processes'])) {
      foreach ($product['processes'] as $process) {
        if ($process['nsProcessId']) {
          $result['process']['children'][] = [
            'vocabulary_id' => 'processes',
            'name' => $process['process'],
            'netsuite_id' => $process['nsProcessId'],
          ];
        }
      }
    }
    else {
      $result['process'] = [];
      $this->logger->warning($this->t('Unable set "processes" for product with SyncDB ID @sync_db_id, because it does not exist at response.', [
        '@sync_db_id' => $sync_db_id,
      ]));
    }
    // Technologies.
    if (isset($product['technologies'])) {
      foreach ($product['technologies'] as $technology) {
        if ($technology['nsTechnologyId']) {
          $result['technology']['children'][] = [
            'vocabulary_id' => 'technologies',
            'name' => $technology['technology'],
            'netsuite_id' => $technology['nsTechnologyId'],
          ];
        }
      }
    }
    else {
      $result['technology'] = [];
      $this->logger->warning($this->t('Unable set "technologies" for product with SyncDB ID @sync_db_id, because it does not exist at response.', [
        '@sync_db_id' => $sync_db_id,
      ]));
    }

    return $result;
  }

  /**
   * Sets the core product variation fields with values retrieved via api call.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariation $variation
   *   The product variation.
   * @param array $product_from_response
   *   The product from the Api response.
   * @param bool $newly_created
   *   Whether or not the product is newly-created by current import process.
   */
  protected function setCoreFieldsCommonToAllVariations(ProductVariation &$variation, array $product_from_response, bool $newly_created = FALSE): void {
    // Handle product Variation Title.
    $product_title = $product_from_response['description'];
    if (strlen($product_title) > 255 && str_contains($product_title, "\r\n")) {
      $product_title = strstr($product_title, "\r\n", TRUE);
    }
    $variation->setOwnerId(0);
    $variation->setTitle($product_title);
    $is_published = $this->isProductVariationPublished($variation, $product_from_response, $newly_created);
    $variation->set('status', $is_published);
    $variation->set('netsuite_id', $product_from_response['nsProductId']);
    $variation->set('syncdb_id', $product_from_response['productId']);
    $variation->setSku($product_from_response['productNumber']);
    $variation->set('avatax_tax_code', $product_from_response['avaTaxCode']);
    $variation->save();
  }

  /**
   * Displays Product Import Success message to the user.
   *
   * @param \Drupal\commerce_product\Entity\Product $product
   *   The product.
   * @param \Drupal\commerce_product\Entity\ProductVariation $variation
   *   The product.
   */
  protected function displayProductImportSuccessMessage(Product $product, ProductVariation $variation): void {
    if ($this->currentUser->hasPermission('administer ipcsync')) {
      $this->messenger->addMessage($this->t('Product with SyncDB ID :syncdb_id imported successfully: <a href=":url">:title</a>', [
        ':syncdb_id' => $variation->get('syncdb_id')->value,
        ':url' => $product->toUrl()->toString(),
        ':title' => $product->getTitle(),
      ]), 'status', FALSE);
    }
    $this->logger->notice($this->t('Product with SyncDB ID :syncdb_id imported successfully: <a href=":url">:title</a>', [
      ':syncdb_id' => $variation->get('syncdb_id')->value,
      ':url' => $product->toUrl()->toString(),
      ':title' => $product->getTitle(),
    ]));
  }

  /**
   * Sets the product variation attribute_format entity reference field.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariation $variation
   *   The product variation.
   * @param array $product_from_response
   *   The product from the Api response.
   */
  protected function setProductVariationFormatField(ProductVariation $variation, array $product_from_response): void {
    $productFormat = $product_from_response['formatCode'];
    $avs = $this->entityTypeManager->getStorage('commerce_product_attribute_value')
      ->loadByProperties([
        'attribute' => 'format',
        'name' => $productFormat,
      ]);
    if ($avs) {
      $av = reset($avs);
      $variation->set('attribute_format', ['target_id' => $av->id()]);
      $variation->save();
    }
  }

  /**
   * Sets the product variation weight field.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariation $variation
   *   The product variation.
   * @param array $product_from_response
   *   The product from the Api response.
   */
  protected function setProductVariationWeightField(ProductVariation $variation, array $product_from_response): void {
    $weight = $product_from_response['weight'];
    $weight_units = $product_from_response['weightUnits']['weightUnits'];
    // @todo Check weightUnits value against all allowed values for weight field.
    $variation->set('weight', ['number' => $weight, 'unit' => $weight_units]);
    $variation->save();
  }

  /**
   * Sets the product variation product_format field.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariation $variation
   *   The product variation.
   * @param array $product_from_response
   *   The product from the Api response.
   */
  protected function setProductVariationProductFormatField(ProductVariation $variation, array $product_from_response): void {
    switch ($product_from_response['format']['productFormat']) {
      case 'CD':
        $product_format = 'cd';
        break;

      case 'DVD':
        $product_format = 'dvd';
        break;

      case 'Hard Copy':
        $product_format = 'hardcopy';
        break;

      case 'Download':
      case 'Download Item':
        $product_format = 'download';
        break;

      default:
        $product_format = '';
    }
    $variation->set('product_format', $product_format);
    $variation->save();
  }

  /**
   * Sets the product variation item_type field.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariation $variation
   *   The product variation.
   * @param array $product_from_response
   *   The product from the Api response.
   */
  protected function setProductVariationItemTypeField(ProductVariation $variation, array $product_from_response): void {
    switch ($product_from_response['type']['productType']) {
      case 'Download Item':
        $item_type = 'download';
        break;

      case 'Service':
        $item_type = 'service';
        break;

      default:
        $item_type = '';
    }
    $variation->set('item_type', $item_type);
    $variation->save();
  }

  /**
   * Sets the product variation commerce_file field and license fields.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariation $variation
   *   The product variation.
   * @param array $product_from_response
   *   The product from the Api response.
   */
  protected function setProductVariationLicenseFields(ProductVariation $variation, array $product_from_response): void {
    // Construct new value for commerce_file field based on data from the API.
    if (!empty($product_from_response['productFiles'])) {
      $saved_files = [];
      foreach ($product_from_response['productFiles'] as $product_file) {
        $file_filename = trim($product_file['filename']);
        $file_url = "s3://$file_filename";

        // Create file if it doesn't exist in Drupal yet.
        /** @var \Drupal\file\FileStorageInterface $file_storage */
        $file_storage = $this->entityTypeManager->getStorage('file');
        $files = $file_storage->loadByProperties(['filename' => $file_filename]);
        if ($files) {
          $file = reset($files);
          // Here we have specific issues when some files
          // have whitespaces at the end. We need to check it and resave URI.
          $current_file_uri = $file->getFileUri();
          $last_char_position = strlen($current_file_uri) - 1;
          if (ctype_space($current_file_uri[$last_char_position])) {
            $file->set('uri', $file_url);
            $file->save();
          }
        }
        else {
          $file = $file_storage->create([
            'filename' => $file_filename,
            'uri' => $file_url,
            'status' => FileInterface::STATUS_PERMANENT,
          ]);
          $file->save();
        }
        $saved_files[] = $file;
      }
      $variation->set('commerce_file', $saved_files);
    }

    if ($product_from_response['drm'] == TRUE) {
      $variation->set('license_expiration', [
        'target_plugin_id' => 'unlimited',
      ]);
      $variation->set('license_type', [
        'target_plugin_id' => 'commerce_file',
        'target_plugin_configuration' => [
          'file_download_limit' => 0,
        ],
      ]);
    }
    else {
      $variation->set('license_expiration', [
        'target_plugin_id' => 'rolling_interval',
        'target_plugin_configuration' => [
          'interval' => [
            'interval' => 30,
            'period' => 'day',
          ],
        ],
      ]);
      $variation->set('license_type', [
        'target_plugin_id' => 'commerce_file',
        'target_plugin_configuration' => [
          'file_download_limit' => 3,
        ],
      ]);
    }
    $variation->save();
  }

  /**
   * Updates Price Lists for the target Product Variation.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariation $variation
   *   The product variation.
   * @param array $product_from_response
   *   The product from the Api response.
   */
  protected function updatePriceLists(ProductVariation $variation, array $product_from_response): void {
    $requestVariables = new \stdClass();
    $requestVariables->productId = $product_from_response['productId'];
    $response = ProductSync::getProductPriceListByProductId($requestVariables);

    $price_list_from_response = $response['productPriceList'];
    $currency_prices = $price_list_from_response['currencyPrices'];
    foreach ($currency_prices as $currency_price) {
      $currency = $currency_price['currency']['currency'];
      if ($currency !== 'US Dollar') {
        break;
      }
      $has_nonmember_price = FALSE;
      $has_member_price = FALSE;
      $has_distributor_price = FALSE;
      $priceLevelPrices = $currency_price['priceLevelPrices'];
      foreach ($priceLevelPrices as $priceLevelPrice) {
        $priceLevel = $priceLevelPrice['priceLevel'];
        $price_list = $this->getProductPriceList($priceLevel['priceLevel']);
        if ($price_list) {
          $priceBreaks = $priceLevelPrice['priceBreaks'];
          foreach ($priceBreaks as $priceBreak) {
            // Set $minQuantity to 1 if value from the API is 0.
            $minQuantity = $priceBreak['minQuantity'] != 0 ? $priceBreak['minQuantity'] : 1;
            $unitPrice = $priceBreak['unitPrice'];
            if ($unitPrice === NULL) {
              $this->logger->error($this->t('Product Importer Error - SyncDB ID: @sync_db_id. Price List for level "@level" does not have unit price set.', [
                '@sync_db_id' => $product_from_response['productId'],
                '@level' => $priceLevel['priceLevel'],
              ]));
            }
            if ($priceLevel['priceLevel'] === 'Non-Member' && $minQuantity == 1) {
              $variation->setPrice(new Price($unitPrice, 'USD'));
              $variation->save();
            }
            else {
              $price_list_item = $this->updatePriceListItem($price_list->id(), $variation->id(), $minQuantity, $unitPrice);
              $price_list_item->save();
            }
            switch ($priceLevel['priceLevel']) {
              case 'Non-Member':
                $has_nonmember_price = TRUE;
                break;

              case 'Member':
                $has_member_price = TRUE;
                break;

              case 'Distributor':
                $has_distributor_price = TRUE;
                break;
            }
          }
        }
      }
      $this->generatePriceListsLogMessages($product_from_response, $has_nonmember_price, $has_member_price, $has_distributor_price);
    }
  }

  /**
   * Creates or loads the Price List corresponding to the given Price Level.
   *
   * @param string $price_level_name
   *   The name of the Price Level, e.g. "Member", "Non-Member".
   */
  protected function getProductPriceList(string $price_level_name) {
    if ($price_level_name === 'Non-Member') {
      $price_level_name = 'Nonmember bulk pricing';
    }
    $storage = $this->entityTypeManager->getStorage('commerce_pricelist');
    $price_lists = $storage->loadByProperties([
      'name' => $price_level_name,
    ]);
    if ($price_lists) {
      $price_list = reset($price_lists);
      return $price_list;
    }
  }

  /**
   * Creates or updates a Price List Item with data obtained from SyncDB.
   *
   * @param int $price_list_id
   *   The ID of the Price List.
   * @param int $variation_id
   *   The ID of the Product Variation.
   * @param int $minQuantity
   *   The minimum quantity.
   * @param float $unitPrice
   *   The unit price.
   *
   * @return \Drupal\commerce_pricelist\Entity\PriceListItemInterface
   *   The Price List Item.
   */
  public function updatePriceListItem(int $price_list_id, int $variation_id, int $minQuantity, float $unitPrice): PriceListItemInterface {
    $storage = $this->entityTypeManager->getStorage('commerce_pricelist_item');
    $entities = $storage->loadByProperties([
      'type' => 'commerce_product_variation',
      'price_list_id' => $price_list_id,
      'purchasable_entity' => $variation_id,
      'quantity' => $minQuantity,
    ]);
    if ($entities) {
      /** @var \Drupal\commerce_pricelist\Entity\PriceListItem $price_list_item */
      $price_list_item = reset($entities);
      $price_list_item->setPrice(new Price($unitPrice, 'USD'));
    }
    else {
      $price_list_item = PriceListItem::create([
        'type' => 'commerce_product_variation',
        'price_list_id' => $price_list_id,
        'purchasable_entity' => $variation_id,
        'quantity' => $minQuantity,
        'price' => new Price($unitPrice, 'USD'),
      ]);
    }

    return $price_list_item;
  }

  /**
   * Sets the value of the later_revision field.
   *
   * @param \Drupal\commerce_product\Entity\Product $product
   *   The product.
   * @param array $product_from_response
   *   The product data from the Api response.
   */
  protected function setLaterRevisionField(Product $product, array $product_from_response): void {
    if ($product_from_response['laterRevision']['productId'] != $product_from_response['productId']) {
      $this->importProduct($product_from_response['laterRevision']['productId'], TRUE);
      $storage = $this->entityTypeManager->getStorage('commerce_product_variation');
      $variations = $storage->loadByProperties([
        'syncdb_id' => $product_from_response['laterRevision']['productId'],
      ]);
      if ($variations) {
        /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $variation */
        $variation = reset($variations);
        $product->set('later_revision', ['target_id' => $variation->getProductId()]);
        $product->save();
      }
    }
  }

  /**
   * Sets the value of the kit_products field.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariation $variation
   *   The product variation.
   * @param array $product_from_response
   *   The product data from the Api response.
   */
  protected function setKitProductsField(ProductVariation $variation, array $product_from_response): void {
    $kit_products = [];
    foreach ($product_from_response['productComponents'] as $component) {
      $product_id = $component['productId'];
      if ($product_id != $product_from_response['productId']) {
        $this->importProduct($product_id, TRUE);
      }
      $storage = $this->entityTypeManager->getStorage('commerce_product_variation');
      $variations = $storage->loadByProperties([
        'syncdb_id' => $product_id,
      ]);
      if ($variations) {
        $kit_variation = reset($variations);
        $kit_products[] = ['target_id' => $kit_variation->id()];
      }
    }
    $variation->set('kit_products', $kit_products);
    $variation->save();
  }

  /**
   * Determines the Published status of the product variation.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariation $variation
   *   The product variation.
   * @param array $product_from_response
   *   The product data from the Api response.
   * @param bool $newly_created
   *   Whether or not the product is newly-created by current import process.
   */
  protected function isProductVariationPublished(ProductVariation $variation, array $product_from_response, bool $newly_created) {
    $inactive = $product_from_response['inActive'];
    $display_in_website = $product_from_response['displayInWebsite'];
    $discontinued_item = $product_from_response['discontinuedItem'];
    // 'Inactive' = Yes or 'Display in Web Site' = No => 'Unpublished'.
    if ($inactive || !$display_in_website || $discontinued_item) {
      // This covers products that are unpublished but purchasable,
      // where 'Inactive' = No and 'Display in Web Site' = No.
      return FALSE;
    }
    // In Writing / pre-published.
    if (!$inactive && $display_in_website && !$discontinued_item) {
      if ($newly_created) {
        // Publish to Drupal is always manual.
        return FALSE;
      }
      else {
        // Return published status of existing product variation.
        return $variation->get('status')->value;
      }
    }
  }

  /**
   * Determines whether or not this product status should be 'Published'.
   *
   * @param \Drupal\commerce_product\Entity\Product $product
   *   The product.
   */
  protected function isProductPublished(Product $product): bool {
    $published_variation_exists = FALSE;
    foreach ($product->getVariations() as $variation) {
      $variation_status = $variation->get('status')->value;
      if ($variation_status) {
        $published_variation_exists = TRUE;
      }
    }
    if (!$published_variation_exists) {
      // Product should be unpublished if no published variation exists.
      return FALSE;
    }
    // Preserve existing status if published variation exists.
    return (bool) $product->get('status')->value;
  }

  /**
   * Log error message for product import routine.
   *
   * @param string $message_type
   *   The type of message to generate.
   * @param string $sync_db_id
   *   SyncDB ID of the product.
   * @param string $product_format
   *   The productFormat value from the API Call.
   */
  protected function generateProductImportErrorMessage($message_type, $sync_db_id, $product_format = NULL): void {
    switch ($message_type) {
      case self::PRODUCTFORMAT_MISSING:
        $this->logger->error($this->t('Unable to import product with SyncDB ID @sync_db_id, because it does not have productFormat field set.', [
          '@sync_db_id' => $sync_db_id,
        ]));
        break;

      case self::PRODUCT_TYPE_NOT_FOUND:
        $this->logger->error($this->t('Unable to create product for SyncDB ID @sync_db_id. Unable to map productFormat value "@productFormat" to a product type.', [
          '@sync_db_id' => $sync_db_id,
          '@productFormat' => $product_format,
        ]));
        break;

      case self::VARIATION_TYPE_NOT_FOUND:
        $this->logger->error($this->t('Unable to create product variation for SyncDB ID @sync_db_id. Unable to map productFormat value "@productFormat" to a variation type.', [
          '@sync_db_id' => $sync_db_id,
          '@productFormat' => $product_format,
        ]));
        break;
    }
  }

  /**
   * Delete orphaned product variation (one linked to wrong product).
   *
   * @param array $product_from_response
   *   The product data from the Api response.
   * @param \Drupal\commerce_product\Entity\Product $target_product
   *   The target product.
   */
  protected function ensureVariationIsAssignedToCorrectProduct(array $product_from_response, Product $target_product): void {
    $variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $variations = $variation_storage->loadByProperties([
      'syncdb_id' => $product_from_response['productId'],
    ]);
    if ($variations) {
      /** @var \Drupal\commerce_product\Entity\ProductVariation $variation */
      $variation = reset($variations);
      $product_id = $variation->getProductId();

      if ($product_id && $product_id != $target_product->id()) {
        // Attach the variation to the right product.
        $product = $variation->getProduct();
        $product->removeVariation($variation);
        $product->save();
        $target_product->addVariation($variation);
        $target_product->save();
        $variation->set('product_id', $target_product->id());
        $variation->save();

        // Unpublish orphaned product if no other variations for it exist.
        if (!$product->hasVariations()) {
          $product->set('status', FALSE);
        }
      }
    }
  }

  /**
   * Generate log messages for missing Price List values.
   *
   * @param array $product_from_response
   *   The product data from the Api response.
   * @param bool $has_nonmember_price
   *   Whether the product has a Non-Member price set.
   * @param bool $has_member_price
   *   Whether the product has a Member price set.
   * @param bool $has_distributor_price
   *   Whether the product has a Distributor price set.
   */
  protected function generatePriceListsLogMessages(array $product_from_response, bool $has_nonmember_price, bool $has_member_price, bool $has_distributor_price): void {
    if (!$has_nonmember_price) {
      $this->logger->error($this->t('Product Importer Error - SyncDB ID: @sync_db_id. Price List for level "@level" does not exist.', [
        '@sync_db_id' => $product_from_response['productId'],
        '@level' => 'Non-Member',
      ]));
    }
    if (!$has_member_price) {
      $this->logger->error($this->t('Product Importer Error - SyncDB ID: @sync_db_id. Price List for level "@level" does not exist.', [
        '@sync_db_id' => $product_from_response['productId'],
        '@level' => 'Member',
      ]));
    }
    if (!$has_distributor_price) {
      $this->logger->error($this->t('Product Importer Error - SyncDB ID: @sync_db_id. Price List for level "@level" does not exist.', [
        '@sync_db_id' => $product_from_response['productId'],
        '@level' => 'Distributor',
      ]));
    }
  }

  /**
   * Removes a discontinued product from all active carts.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariation $variation
   *   The product variation.
   * @param array $product_from_response
   *   The product from the Api response.
   */
  protected function removeDiscontinuedProductFromCarts(ProductVariation $variation, array $product_from_response): void {
    if ($product_from_response['discontinuedItem'] == TRUE) {
      $query = $this->connection->select('commerce_order', 'co')
        ->fields('co', ['order_id'])
        ->condition('co.state', 'draft')
        ->condition('co.cart', TRUE);
      $query->join('commerce_order_item', 'coi', 'co.order_id = coi.order_id');
      $query->condition('coi.purchased_entity', $variation->id());
      $cart_ids = $query->execute()->fetchCol();

      $order_storage = $this->entityTypeManager->getStorage('commerce_order');
      foreach ($cart_ids as $cart_id) {
        /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
        $order = $order_storage->load($cart_id);
        $order_items = $order->getItems();
        foreach ($order_items as $order_item) {
          $purchased_entity_id = $order_item->getPurchasedEntityId();
          if ($purchased_entity_id === $variation->id()) {
            $this->cartManager->removeOrderItem($order, $order_item);
          }
        }
      }
    }
  }

}
