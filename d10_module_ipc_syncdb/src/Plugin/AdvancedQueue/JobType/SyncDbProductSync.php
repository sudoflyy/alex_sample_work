<?php

namespace Drupal\ipc_syncdb\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\ipc_administrative_notifications\Event\AdministrativeNotificationsEvents;
use GuzzleHttp\Exception\ServerException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides the job type for importing product data from Sync DB.
 *
 * @AdvancedQueueJobType(
 *   id = "syncdb_product_sync",
 *   label = @Translation("Sync DB Product Sync"),
 * )
 */
class SyncDbProductSync extends SyncDbJobTypeBase {

  /**
   * The product importer.
   *
   * @var \Drupal\ipc_syncdb\ProductImporter
   */
  protected $productImporter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->productImporter = $container->get('ipc_syncdb.product_importer');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function process(Job $job) {
    $payload = $job->getPayload();
    $product_id = $payload['product_id'];
    $payload_json = json_encode($payload);
    try {
      $result = $this->productImporter->importProduct($product_id);
    }
    catch (\Exception $exception) {
      $message = $this->t('Unhandled Exception: @message', ['@message' => $exception->getMessage()]);
      if ($exception instanceof ServerException && $exception->getCode() === Response::HTTP_GATEWAY_TIMEOUT) {
        $message = $this->t('Server Exception: @message', ['@message' => $exception->getMessage()]);
      }
      return $this->failure($job, $message, AdministrativeNotificationsEvents::PRODUCT_SYNC, 'commerce_product', $product_id, $payload_json);
    }

    switch ($result->getStatus()) {
      case 'saved':
        $storage = $this->entityTypeManager->getStorage('commerce_product_variation');
        if (!$storage->loadByProperties(['syncdb_id' => $product_id])) {
          $message = $this->t('Product not saved correctly.');
          $result = $this->failure($job, $message, AdministrativeNotificationsEvents::PRODUCT_SYNC, 'commerce_product', $product_id, $payload_json);
        }
        else {
          $message = '';
          if (!empty($result->getMessage())) {
            $message = $result->getMessage();
          }
          $result = $this->success($job, $message);
        }
        break;

      case 'skipped':
        $message = '';
        if (!empty($result->getMessage())) {
          $message = $result->getMessage();
        }
        $result = $this->success($job, $message);
        break;

      default:
        $message = $this->t('Product not saved.');
        $result = $this->failure($job, $message, AdministrativeNotificationsEvents::PRODUCT_SYNC, 'commerce_product', $product_id, $payload_json);
        break;
    }

    return $result;
  }

}
