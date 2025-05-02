<?php

namespace Drupal\ipc_syncdb\Form;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\group\Entity\Storage\GroupRelationshipStorageInterface;
use Drupal\ipc_commerce\GcommerceConstants;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure a form which will find duplicates for Licenses.
 */
class ClearLicenseDuplicatesBatchForm extends FormBase {

  /**
   * Batch Builder.
   *
   * @var \Drupal\Core\Batch\BatchBuilder
   */
  protected BatchBuilder $batchBuilder;

  /**
   * The Database instance.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $connection;

  /**
   * The module extension list.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected ModuleExtensionList $moduleExtensionList;

  /**
   * The group relationship storage.
   *
   * @var \Drupal\group\Entity\Storage\GroupRelationshipStorageInterface
   */
  protected GroupRelationshipStorageInterface $groupRelationshipStorage;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ClearLicenseDuplicatesBatchForm {
    $instance = parent::create($container);
    $instance->batchBuilder = new BatchBuilder();
    $instance->connection = $container->get('database');
    $instance->moduleExtensionList = $container->get('extension.list.module');
    $instance->groupRelationshipStorage = $container->get('entity_type.manager')->getStorage(GcommerceConstants::GROUP_RELATIONSHIP_ENTITY_TYPE_ID->value);

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'clear_license_duplicates_batch_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['help'] = [
      '#markup' => $this->t('Find "Company Relationship" duplicates for License.'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['run'] = [
      '#type' => 'submit',
      '#value' => $this->t('Find'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $licenses = $this->connection
      ->select('group_relationship_field_data', 'grfd')
      ->fields('grfd', ['entity_id'])
      ->condition('grfd.type', 'group_content_type_47ea65b118027')
      ->groupBy('entity_id')
      ->having('COUNT(*) > 1')
      ->execute()
      ->fetchAll();

    if (count($licenses) > 0) {
      $this->batchBuilder
        ->setTitle($this->t('Processing'))
        ->setInitMessage($this->t('Initializing.'))
        ->setProgressMessage($this->t('Completed @current of @total.'))
        ->setErrorMessage($this->t('An error has occurred.'));

      $this->batchBuilder->setFile($this->moduleExtensionList->getPath('ipc_syncdb') . '/src/Form/ClearLicenseDuplicatesBatchForm.php');
      $this->batchBuilder->addOperation([$this, 'processItems'], [$licenses]);
      $this->batchBuilder->setFinishCallback([$this, 'finished']);

      batch_set($this->batchBuilder->toArray());
    }
    else {
      $message = $this->t('No duplicates licenses found.');
      $this->messenger()->addStatus($message);
    }

  }

  /**
   * Processor for batch operations.
   *
   * @param array $items
   *   The items to process.
   * @param array $context
   *   The context.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function processItems(array $items, array &$context): void {
    // Elements per operation.
    $limit = 50;

    // Set default progress values.
    if (empty($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['max'] = count($items);
    }

    // Save items to array which will be changed during processing.
    if (empty($context['sandbox']['items'])) {
      $context['sandbox']['items'] = $items;
    }

    $counter = 0;
    if (!empty($context['sandbox']['items'])) {
      // Remove already processed items.
      if ($context['sandbox']['progress'] != 0) {
        array_splice($context['sandbox']['items'], 0, $limit);
      }

      foreach ($context['sandbox']['items'] as $item) {
        if ($counter !== $limit) {
          $this->processItem($item->entity_id);

          $counter++;
          $context['sandbox']['progress']++;

          $context['message'] = $this->t('Now processing License :progress of :count', [
            ':progress' => $context['sandbox']['progress'],
            ':count' => $context['sandbox']['max'],
          ]);

          // Increment total processed item values. Will be used in finished
          // callback.
          $context['results']['processed'] = $context['sandbox']['progress'];
        }
      }
    }

    // If not finished all tasks, we count percentage of process. 1 = 100%.
    if ($context['sandbox']['progress'] != $context['sandbox']['max']) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
  }

  /**
   * Process single item.
   *
   * @param int|string $entity_id
   *   An id of License.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function processItem(int | string $entity_id): void {
    // We need to get all the results by entity ID with sort Desc Order and
    // skip first element.
    $results = $this->connection
      ->select('group_relationship_field_data', 'grfd')
      ->fields('grfd', ['id'])
      ->condition('grfd.type', 'group_content_type_47ea65b118027')
      ->condition('grfd.entity_id', $entity_id)
      ->range(1, 1000)
      ->orderBy('created', 'DESC')
      ->execute()->fetchCol();
    if (!empty($results)) {
      $group_relationships = $this->groupRelationshipStorage->loadMultiple($results);
      foreach ($group_relationships as $group_relationship) {
        $group_relationship->delete();
      }
    }

  }

  /**
   * Finished callback for batch.
   */
  public function finished($success, $results, $operations): void {
    $message = $this->t('Number of duplicate licenses: @count', [
      '@count' => $results['processed'],
    ]);

    $this->messenger()->addStatus($message);
  }

}
