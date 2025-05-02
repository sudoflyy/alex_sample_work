<?php

namespace Drupal\ipc_syncdb;

use Drupal\advancedqueue\Job;
use Drupal\commerce_license\Entity\License;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\group\Entity\GroupInterface;
use Drupal\ipc_commerce\UserHelper;
use Drupal\ipc_syncdb\Traits\TransactionAssignToGroupTrait;
use Drupal\ipcsync\Utilities\ProductSync;
use Drupal\ipcsync\Utilities\TransactionSync;
use Psr\Log\LoggerInterface;

/**
 * Imports licenses from Sync DB via IPCEntitiesApi.
 */
class LicenseImporter {

  use StringTranslationTrait;
  use TransactionAssignToGroupTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  public $entityTypeManager;

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
   * The product importer.
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
   * Constructs a new LicenseImporter object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity query factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state.
   * @param \Drupal\ipc_syncdb\UserImporter $user_importer
   *   The user importer.
   * @param \Drupal\ipc_syncdb\CompanyImporter $company_importer
   *   The company importer.
   * @param \Drupal\ipc_syncdb\ProductImporter $product_importer
   *   The product importer.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection to use.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerInterface $logger, StateInterface $state, UserImporter $user_importer, CompanyImporter $company_importer, ProductImporter $product_importer, Connection $connection) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
    $this->state = $state;
    $this->userImporter = $user_importer;
    $this->companyImporter = $company_importer;
    $this->productImporter = $product_importer;
    $this->connection = $connection;
  }

  /**
   * Poll for changes to download licenses in the Sync DB.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function pollForChangesToLicenses($modified_on_after = '') {
    $queue_storage = $this->entityTypeManager->getStorage('advancedqueue_queue');
    /** @var \Drupal\advancedqueue\Entity\QueueInterface $queue */
    $queue = $queue_storage->load('ipc_license_sync');
    $transactions = $this->getUpdatedDownloadTransactionsFromSyncDb($modified_on_after);

    $queue_machine_name = 'ipc_license_sync';
    foreach ($transactions as $transaction_id) {
      $payload = [
        'transaction_id' => $transaction_id,
      ];
      $import_job = Job::create('syncdb_license_sync', $payload);

      if (!$this->jobExists($queue_machine_name, $payload)) {
        $queue_storage = $this->entityTypeManager->getStorage('advancedqueue_queue');
        /** @var \Drupal\advancedqueue\Entity\QueueInterface $queue */
        $queue = $queue_storage->load($queue_machine_name);

        $queue->enqueueJob($import_job, 60);
      }
    }
  }

  /**
   * Get all licenses that have been updated since last run.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function getUpdatedDownloadTransactionsFromSyncDb($modified_on_after = '') {
    $run_time = ApiHelper::getRunTimeDateTimeString();
    $all_entries = [];

    $requestVariables = new \stdClass();
    $requestedPage = 1;
    $requestVariables->requestedPage = $requestedPage;
    if ($modified_on_after) {
      $modifiedOnAfter = $modified_on_after;
    }
    else {
      $last_run_time = $this->state->get('ipcsync_license_importer_last_run');
      $modifiedOnAfter = $last_run_time ?: ApiHelper::POLLING_ROUTINE_START_TIME;
    }
    $requestVariables->modifiedOnAfter = $modifiedOnAfter;

    $response = TransactionSync::getDigitalDownloadTransactionList($requestVariables);
    $response_list = $response['transactionList'];
    while ($response_list) {
      foreach ($response_list as $transaction) {
        if ($transaction['hasDigitalDownload']) {
          $all_entries[] = $transaction['transactionId'];
        }
      }
      $requestedPage++;
      $requestVariables->requestedPage = $requestedPage;
      $response = TransactionSync::getDigitalDownloadTransactionList($requestVariables);
      $response_list = $response['transactionList'];
    }

    $this->state->set('ipcsync_license_importer_last_run', $run_time);
    return $all_entries;
  }

  /**
   * Imports digital download license for a transaction.
   *
   * @param int $transaction_id
   *   The Transaction ID associated with the transaction.
   *
   * @return string
   *   The result, e.g. 'success', 'skipped', or 'failure'.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function importDigitalDownloadTransaction($transaction_id) {
    $requestVariables = new \stdClass();
    $requestVariables->transactionId = $transaction_id;
    $response = TransactionSync::getTransaction($requestVariables);
    $transaction = $response['transaction'];

    // Skip import for transactions created in Drupal.
    if ($transaction['externalTransactionNumber']) {
      $this->logger->notice($this->t('License Sync - Skipping import for license for transaction ID @tid because transaction originated in Drupal.', [
        '@tid' => $transaction_id,
      ]));
      return 'skipped';
    }

    // Cannot import transaction if neither "endUser" value is set.
    if (empty($transaction['endUser']['user']) && empty($transaction['endUser']['company'])) {
      $this->logger->error($this->t('License Sync Error - Cannot import license for transaction ID @tid because "endUser" field is not set', [
        '@tid' => $transaction_id,
      ]));
      return 'failure';
    }

    foreach ($transaction['lineItems'] as $line_item) {
      if ($line_item['isDigitalDownload']) {
        $product_id = $line_item['product']['productId'];
        $quantity = $line_item['quantity'];
        $product_number = $line_item['product']['productNumber'];

        $requestVariables = new \stdClass();
        $requestVariables->productId = $product_id;
        $response = ProductSync::getProductByProductId($requestVariables);
        $product_from_response = $response['product'];

        // Add import for Kit with licenses.
        $product_type = $this->productImporter->determineProductType($product_from_response['format']['productFormat']);
        if ($product_type === 'kit' && $product_from_response['productComponents']) {
          foreach ($product_from_response['productComponents'] as $productComponent) {
            // Import licenses products from kit.
            $requestVariablesLicense = new \stdClass();
            $requestVariablesLicense->productId = $productComponent['productId'];
            $responseLicense = ProductSync::getProductByProductId($requestVariablesLicense);
            $product_from_response_license = $responseLicense['product'];

            $product_files_license = $product_from_response_license['productFiles'];

            if ($product_files_license) {
              foreach ($product_files_license as $product_file_license) {
                $license_quantity = $quantity;
                $license_product_number = $product_number;

                $license_import_result = $this->importProductFileLicense($transaction, $productComponent['productId'], $product_file_license, $license_quantity, $license_product_number);
                if ($license_import_result === 'failure') {
                  $this->logger->error($this->t('License Sync Error - Cannot import kit license for transaction ID @tid because file is missing on the digital download item', [
                    '@tid' => $transaction_id,
                  ]));
                }
              }
            }
          }
        }
        else {
          $product_files = $product_from_response['productFiles'];
          if (!$product_files) {
            $this->logger->error($this->t('License Sync Error - Cannot import license for transaction ID @tid because file is missing on the digital download item', [
              '@tid' => $transaction_id,
            ]));
            return 'failure';
          }
          foreach ($product_files as $product_file) {
            $license_import_result = $this->importProductFileLicense($transaction, $product_id, $product_file, $quantity, $product_number);
            if ($license_import_result === 'failure') {
              return 'failure';
            }
          }
        }
      }
    }
    return 'success';
  }

  /**
   * Imports Download License data.
   *
   * @param array $transaction
   *   The Transaction data returned from the API.
   * @param int $product_id
   *   The Product ID.
   * @param array $product_file
   *   Product file data.
   * @param int $quantity
   *   The quantity of the licenses to be imported.
   * @param string $product_number
   *   The Product Number.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function importProductFileLicense(array $transaction, int $product_id, array $product_file, int $quantity, $product_number) {
    $variation = $this->createOrUpdateFileAndVariationForLicense($product_id, $product_file, $transaction['transactionId'], $product_number);
    // Proceed to create licenses if file and product variation saved correctly.
    if ($variation) {
      $result = $this->verifyFileOnVariationIsCorrect($variation, $transaction, $product_file, $product_id);
      if ($result === 'failure') {
        return 'failure';
      }

      // Extract license field values from the variation.
      $license_interval = '';
      $file_download_limit = '';
      $license_expiration = $variation->get('license_expiration')->first()->getValue();
      $license_type = $variation->get('license_type')->first()->getValue();
      // Expect 'target_plugin_id' to equal 'unlimited' or 'rolling_interval'.
      $target_plugin_id = $license_expiration['target_plugin_id'];
      if ($target_plugin_id === 'rolling_interval') {
        $license_interval = $license_expiration['target_plugin_configuration']['interval'];
        $file_download_limit = $license_type['target_plugin_configuration']['file_download_limit'];
      }

      // Skip import if license has expired.
      if ($target_plugin_id === 'rolling_interval' && !empty($license_interval['interval']) && !empty($license_interval['period'])) {
        $transaction_date = DrupalDateTime::createFromFormat("Y-m-d\TH:i:s\Z", $transaction['transactionDate']);
        $expiration_date = $transaction_date->modify('+' . $license_interval['interval'] . ' ' . $license_interval['period'] . 's');
        if (time() > $expiration_date->getTimestamp()) {
          $this->logger->notice($this->t('License Sync - Skipping import for license for transaction ID @tid because the license has expired.', [
            '@tid' => $transaction['transactionId'],
          ]));
          return 'skipped';
        }
      }

      $licences_for_update = [];
      $quantity_existing_licenses = 0;
      $quantity_licenses_created = 0;
      $license_storage = $this->entityTypeManager->getStorage('commerce_license');
      $query = $license_storage->getQuery()
        ->condition('type', 'commerce_file')
        ->condition('product_variation.target_id', $variation->id())
        ->condition('originating_ns_transaction_id', $transaction['transactionId'])
        ->accessCheck(FALSE);
      $license_ids = $query->execute();
      if ($license_ids) {
        $licences_for_update = License::loadMultiple($license_ids);
        $quantity_existing_licenses = count($license_ids);
      }

      while ($quantity_existing_licenses + $quantity_licenses_created < $quantity) {
        /** @var \Drupal\commerce_license\Entity\License $license */
        $license = License::create([
          'type' => 'commerce_file',
          'state' => 'active',
          'originating_ns_transaction_id' => $transaction['transactionId'],
          'product_variation' => [
            'target_id' => $variation->id(),
          ],
          'netsuite_order_number' => $transaction['documentNumber'],
        ]);
        if ($target_plugin_id === 'unlimited') {
          $license->set('expiration_type', [
            'target_plugin_id' => 'unlimited',
            'target_plugin_configuration' => [],
          ]);
        }
        elseif ($target_plugin_id === 'rolling_interval') {
          $license->set('expiration_type', [
            'target_plugin_id' => 'fixed_reference_date_interval',
            'target_plugin_configuration' => [
              'reference_date' => substr($transaction['transactionDate'], 0, 10),
              'interval' => $license_interval,
            ],
          ]);
          $license->set('file_download_limit', $file_download_limit);
        }

        $license->save();
        $this->setRelationshipsForLicense($license, $transaction);
        $this->generateLicenseImportSuccessMessage($license, $transaction);

        $quantity_licenses_created++;
      }
      // We faced with situation where some license was not assign to company.
      foreach ($licences_for_update as $license) {
        $this->setRelationshipsForLicense($license, $transaction);
      }
      $this->logger->notice($this->t('License Sync - transaction ID @tid - Sync complete. Number of existing licenses: @existing, number of licenses created: @created.', [
        '@tid' => $transaction['transactionId'],
        '@existing' => $quantity_existing_licenses,
        '@created' => $quantity_licenses_created,
      ]));
      return 'success';
    }
    else {
      return 'failure';
    }
  }

  /**
   * Verifies that the file attached to the variation matches data from API.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The variation.
   * @param array $transaction
   *   The Transaction data returned from the API.
   * @param array $product_file
   *   Product file data.
   * @param int $product_id
   *   The Product ID.
   */
  protected function verifyFileOnVariationIsCorrect(ProductVariationInterface $variation, array $transaction, array $product_file, int $product_id) {
    $commerce_file = $variation->get('commerce_file')->getValue();
    $file_target_id = $commerce_file[0]['target_id'];
    $file_storage = $this->entityTypeManager->getStorage('file');
    $file = $file_storage->load($file_target_id);
    $filename = $file->getFilename();

    if ($filename !== $product_file['filename']) {
      // If the filename on the variation does not match the filename from
      // the API, run import for the product and check again.
      $result = $this->productImporter->importProduct($product_id);
      if ($result->getStatus() === 'saved') {
        $variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');
        // Load the imported variation.
        $variations = $variation_storage->loadByProperties([
          'syncdb_id' => $product_id,
        ]);
        if ($variations) {
          /** @var \Drupal\commerce_product\Entity\ProductVariation $variation */
          $variation = reset($variations);
          $commerce_file = $variation->get('commerce_file')->getValue();
          $file_target_id = $commerce_file[0]['target_id'];
          $file = $file_storage->load($file_target_id);
          $filename = $file->getFilename();
          if ($filename !== $product_file['filename']) {
            $this->logger->error($this->t('License Sync Error - Cannot import license for transaction ID @tid, filename:@filename.  The filename from the API does not match the filename on the corresponding product variation.', [
              '@tid' => $transaction['transactionId'],
              '@filename' => $product_file['filename'],
            ]));
            return 'failure';
          }
        }
      }
      else {
        $this->logger->error($this->t('License Sync Error - Cannot import license for transaction ID @tid - a product variation for the file @filename could not be imported.', [
          '@tid' => $transaction['transactionId'],
          '@filename' => $product_file['filename'],
        ]));
        return 'failure';
      }
    }
    return 'success';
  }

  /**
   * Create or update File and Product Variation For License.
   *
   * @param int $product_id
   *   The Product ID.
   * @param array $product_file
   *   Product File data from IPCTransactionAPI.
   * @param int $transaction_id
   *   The Transaction ID.
   * @param string $product_number
   *   The Product Number.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function createOrUpdateFileAndVariationForLicense(int $product_id, array $product_file, int $transaction_id, string $product_number) {
    $variation = NULL;
    // Load product variation with sku that matches our productNumber.
    /** @var \Drupal\commerce_product\ProductVariationStorageInterface $variation_storage */
    $variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $query = $variation_storage->getQuery()
      ->condition('type', 'digital_document')
      ->condition('sku', $product_number)
      ->accessCheck(FALSE);
    $variation_ids = $query->execute();

    if ($variation_ids) {
      $variation_ids = array_values($variation_ids);
      $variation_id = reset($variation_ids);
      $variation = $variation_storage->load($variation_id);
    }
    else {
      // Ensure that product and product variation are imported.
      $result = $this->productImporter->importProduct($product_id);
      if ($result->getStatus() === 'saved') {
        // Load the imported variation.
        $variations = $variation_storage->loadByProperties([
          'syncdb_id' => $product_id,
        ]);
        if ($variations) {
          /** @var \Drupal\commerce_product\Entity\ProductVariation $variation */
          $variation = reset($variations);
        }
      }
      else {
        $this->logger->error($this->t('License Sync Error - Cannot import license for transaction ID @tid - a product variation for the file @filename could not be generated.', [
          '@tid' => $transaction_id,
          '@filename' => $product_file['filename'],
        ]));
      }
    }

    return $variation;
  }

  /**
   * Sets the uid field and assigns license to user/company.
   *
   * @param \Drupal\commerce_license\Entity\License $license
   *   The license.
   * @param array $transaction
   *   The Transaction data returned from the API.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function setRelationshipsForLicense(License $license, array $transaction) {
    $end_user_data = $transaction['endUser'];

    // If $transaction['company'], import Company and assign it to company, with
    // anonymous user.
    if (!empty($end_user_data['company'])) {
      if ($primary_company = $this->companyImporter->importCompany($end_user_data['company']['accountId'])) {
        $license->set('uid', 0);
        $this->assignToGroup($license, $primary_company);
      }
      else {
        $this->logger->error($this->t('License Sync Error - Transaction ID @tid - Error importing license with Drupal ID @drupal_license_id - Unable to import the associated company with AccountId: @account_id', [
          '@tid' => $transaction['transactionId'],
          '@drupal_license_id' => $license->id(),
          '@account_id' => $end_user_data['company']['accountId'],
        ]));
        return;
      }

    }
    // If $transaction['endUser'], try to find the parent company and assign
    // to this company if not assign to user.
    elseif (!empty($end_user_data['user'])) {
      if ($user = $this->userImporter->importUserByEmail($end_user_data['user']['email'])) {
        // Here we need to check if member company or individual.
        $primary_company = UserHelper::getPrimaryCompany($user);
        if ($primary_company instanceof GroupInterface) {
          $license->set('uid', 0);
          $this->assignToGroup($license, $primary_company);
        }
        else {
          $license->set('uid', $user->id());
        }
      }
      else {
        $this->logger->error($this->t('License Sync Error - Transaction ID @tid - Error importing license with Drupal ID @drupal_license_id - Unable to import the associated user with email: @email', [
          '@tid' => $transaction['transactionId'],
          '@drupal_license_id' => $license->id(),
          '@email' => $end_user_data['user']['email'],
        ]));
        return;
      }

    }
    else {
      // We should throw here that we can't identify the Owner of the License.
      $this->logger->error($this->t('License Sync Error - Transaction ID @tid - Error importing license with Drupal ID @drupal_license_id - Unable to identify owner of the License.', [
        '@tid' => $transaction['transactionId'],
        '@drupal_license_id' => $license->id(),
      ]));
      return;
    }

    $license->save();
  }

  /**
   * Generates the License Import success log message.
   *
   * @param \Drupal\commerce_license\Entity\License $license
   *   The license.
   * @param array $transaction
   *   The Transaction data returned from the API.
   */
  protected function generateLicenseImportSuccessMessage(License $license, array $transaction) {
    $this->logger->notice($this->t('License Importer Success - Transaction ID @tid - Created license with Drupal ID @drupal_license_id.', [
      '@tid' => $transaction['transactionId'],
      '@drupal_license_id' => $license->id(),
    ]));
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

  /**
   * Update digital download license for a transaction.
   *
   * Used only for hook update.
   *
   * @param int $transaction_id
   *   The Transaction ID associated with the transaction.
   * @param int $license_id
   *   The license id.
   *
   * @return string
   *   The result, e.g. 'success', 'skipped', or 'failure'.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function updateDigitalDownloadTransaction(int $transaction_id, int $license_id): string {
    $requestVariables = new \stdClass();
    $requestVariables->transactionId = $transaction_id;
    $response = TransactionSync::getTransaction($requestVariables);
    $transaction = $response['transaction'];

    // Skip update for transactions created in Drupal.
    if ($transaction['externalTransactionNumber']) {
      $this->logger->notice($this->t('License Sync - Skipping update for license for transaction ID @tid because transaction originated in Drupal.', [
        '@tid' => $transaction_id,
      ]));
      return 'skipped';
    }

    // Cannot update transaction if neither "endUser" value is set.
    if (empty($transaction['endUser']['user']) && empty($transaction['endUser']['company'])) {
      $this->logger->error($this->t('License Sync Error - Cannot update license for transaction ID @tid because "endUser" field is not set', [
        '@tid' => $transaction_id,
      ]));
      return 'failure';
    }

    foreach ($transaction['lineItems'] as $line_item) {
      if ($line_item['isDigitalDownload']) {
        $product_id = $line_item['product']['productId'];
        $quantity = $line_item['quantity'];
        $product_number = $line_item['product']['productNumber'];

        $requestVariables = new \stdClass();
        $requestVariables->productId = $product_id;
        $response = ProductSync::getProductByProductId($requestVariables);
        $product_from_response = $response['product'];

        $product_files = $product_from_response['productFiles'];
        if (!$product_files) {
          $this->logger->error($this->t('License Sync Error - Cannot update license for transaction ID @tid because file is missing on the digital download item', [
            '@tid' => $transaction_id,
          ]));
          return 'failure';
        }
        foreach ($product_files as $product_file) {
          $license_import_result = $this->updateProductFileLicense($transaction, $product_id, $product_file, $quantity, $product_number, $license_id);
          if ($license_import_result === 'failure') {
            return 'failure';
          }
        }
      }
    }
    return 'success';
  }

  /**
   * Update Download License data. Used only for hook update.
   *
   * @param array $transaction
   *   The Transaction data returned from the API.
   * @param int $product_id
   *   The Product ID.
   * @param array $product_file
   *   Product file data.
   * @param int $quantity
   *   The quantity of the licenses to be imported.
   * @param string $product_number
   *   The Product Number.
   * @param int $license_id
   *   The license id.
   *
   * @return string
   *   The result.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function updateProductFileLicense(array $transaction, int $product_id, array $product_file, int $quantity, string $product_number, int $license_id) {
    $variation = $this->createOrUpdateFileAndVariationForLicense($product_id, $product_file, $transaction['transactionId'], $product_number);
    // Proceed to create licenses if file and product variation saved correctly.
    if ($variation) {
      $result = $this->verifyFileOnVariationIsCorrect($variation, $transaction, $product_file, $product_id);
      if ($result === 'failure') {
        return 'failure';
      }

      // Extract license field values from the variation.
      $license_interval = '';
      $license_expiration = $variation->get('license_expiration')->first()->getValue();
      // Expect 'target_plugin_id' to equal 'unlimited' or 'rolling_interval'.
      $target_plugin_id = $license_expiration['target_plugin_id'];
      if ($target_plugin_id === 'rolling_interval') {
        $license_interval = $license_expiration['target_plugin_configuration']['interval'];
      }

      // Skip import if license has expired.
      if ($target_plugin_id === 'rolling_interval' && !empty($license_interval['interval']) && !empty($license_interval['period'])) {
        $transaction_date = DrupalDateTime::createFromFormat('Y-m-d\TH:i:s\Z', $transaction['transactionDate']);
        $expiration_date = $transaction_date->modify('+' . $license_interval['interval'] . ' ' . $license_interval['period'] . 's');
        if (time() > $expiration_date->getTimestamp()) {
          $this->logger->notice($this->t('License Sync - Skipping update for license for transaction ID @tid because the license has expired.', [
            '@tid' => $transaction['transactionId'],
          ]));
          return 'skipped';
        }
      }

      $licences_for_update = [];
      $quantity_existing_licenses = 0;

      $license_storage = $this->entityTypeManager->getStorage('commerce_license');
      $query = $license_storage->getQuery()
        ->condition('type', 'commerce_file')
        ->condition('license_id', $license_id)
        ->notExists('netsuite_order_number')
        ->condition('product_variation.target_id', $variation->id())
        ->condition('originating_ns_transaction_id', $transaction['transactionId'])
        ->accessCheck(FALSE);
      $license_ids = $query->execute();

      if ($license_ids) {
        $licences_for_update = License::loadMultiple($license_ids);
        $quantity_existing_licenses = count($license_ids);
      }

      // We faced with situation where some license was not assign to company.
      foreach ($licences_for_update as $license) {
        $license->set('netsuite_order_number', $transaction['documentNumber']);
        $license->save();

        $this->logger->notice($this->t('License Updated - licence ID @license - with document_number @documentNumber.', [
          '@license' => $license->id(),
          '@documentNumber' => $transaction['documentNumber'],
        ]));
      }
      $this->logger->notice($this->t('License Sync - transaction ID @tid - Update complete. Number of updated licenses: @existing.', [
        '@tid' => $transaction['transactionId'],
        '@existing' => $quantity_existing_licenses,
      ]));
      return 'success';
    }
    return 'failure';
  }

}
