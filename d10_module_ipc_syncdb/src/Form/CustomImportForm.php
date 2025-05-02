<?php

namespace Drupal\ipc_syncdb\Form;

use Drupal\advancedqueue\Job;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ipc_syncdb\Enums\TransactionTypes;
use Drupal\ipc_syncdb\TransactionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Contains a form for executing custom import from SyncDB.
 *
 * @internal
 */
class CustomImportForm extends FormBase implements ContainerInjectionInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The transaction manager.
   *
   * @var \Drupal\ipc_syncdb\TransactionManager
   */
  protected $transactionManager;

  /**
   * Constructs a new CustomImportForm instance.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\ipc_syncdb\TransactionManager $transaction_manager
   *   The transaction manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, TransactionManager $transaction_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->transactionManager = $transaction_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('ipc_syncdb.transaction_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'custom_import_form';
  }

  /**
   * Form constructor.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['type'] = [
      '#title' => $this->t('Please Select the Type of Transaction'),
      '#type' => 'select',
      '#options' => [
        TransactionTypes::SALES_ORDER => $this->t('Order'),
        TransactionTypes::QUOTES => $this->t('Quote'),
        TransactionTypes::INVOICE => $this->t('Invoice'),
      ],
      '#attributes' => [
        'name' => 'field_select_type',
      ],
    ];

    $form['select_import'] = [
      '#type' => 'radios',
      '#title' => $this->t('Type of Import to Run'),
      '#options' => [
        'syncdb' => $this->t('Sync DB'),
        'date' => $this->t('Modified on After'),
      ],
      '#attributes' => [
        'name' => 'field_select_import',
      ],
    ];

    $form['sync_db_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sync DB Id'),
      '#states' => [
        'visible' => [
          ':input[name="field_select_import"]' => ['value' => 'syncdb'],
        ],
      ],
    ];

    $form['modified_on_after'] = [
      '#type' => 'date',
      '#title' => $this->t('Please choose a date'),
      '#description' => $this->t('i.e. @time', ['@time' => date('Y-m-d')]),
      '#format' => 'Y-m-d',
      '#default_value' => date('Y-m-d'),
      '#states' => [
        'visible' => [
          ':input[name="field_select_import"]' => ['value' => 'date'],
        ],
      ],
    ];

    $form['historical_import'] = [
      '#type' => 'radios',
      '#title' => $this->t('If this import is Historical?'),
      '#options' => [
        1 => $this->t('Yes'),
        0 => $this->t('No'),
      ],
      '#default_value' => 1,
      '#states' => [
        'visible' => [
          ':input[name="field_select_type"]' => ['value' => TransactionTypes::INVOICE],
          ':input[name="field_select_import"]' => [
            ['value' => 'date'],
          ],
        ],
      ],
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add to the queue'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getUserInput();
    if ($values['field_select_import'] == 'date') {
      $params = [
        'modified_on_after' => $values['modified_on_after'],
        'transaction_type_id' => $values['field_select_type'],
      ];
      if (!empty($values['historical_import'])) {
        $params['historical_import'] = $values['historical_import'];
      }
      $this->transactionManager->pollForChangesToTransactions($params);
      $message = $this->t('Transactions with param "modifiedOnAfter" = @date was added to the queue.', ['@date' => $values['modified_on_after']]);
    }
    elseif (!empty($values['sync_db_id'])) {
      /** @var \Drupal\advancedqueue\Entity\QueueInterface $queue */
      $queue = $this->entityTypeManager->getStorage('advancedqueue_queue')
        ->load('ipc_transaction_get_sync');
      $transactions_sync_job = Job::create('ipc_syncdb_transactions_get_transaction', ['transaction_id' => $values['sync_db_id']]);
      $queue->enqueueJob($transactions_sync_job);
      $message = $this->t('Transaction with ID - @id was added to the queue.', ['@id' => $values['sync_db_id']]);
    }
    else {
      $message = $this->t('Something goes wrong. Please recheck selections and try again.');
    }

    $this->messenger()->addMessage($message, 'status', FALSE);
  }

}
