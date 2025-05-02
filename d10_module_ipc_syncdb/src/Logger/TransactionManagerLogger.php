<?php

namespace Drupal\ipc_syncdb\Logger;

use Drupal\Core\State\State;

/**
 * Handles Logging of actions during transaction.
 */
class TransactionManagerLogger implements TransactionManagerLoggerInterface {

  const STATE_KEY_PREFIX = 'ipc_queue:';

  /**
   * The State service.
   *
   * @var \Drupal\Core\State\State
   */
  protected $state;

  /**
   * Constructs a new TransactionManagerLoggerBase.
   *
   * @param \Drupal\Core\State\State $state
   *   The State service.
   */
  public function __construct(State $state) {
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public function initLogger(string $method, ?string $job_id): void {
    if (!empty($job_id)) {
      $this->deleteMessage($method, $job_id);
      $key = self::STATE_KEY_PREFIX . "{$method}:job:{$job_id}";
      $method_name = ucfirst($method);
      $init_value = "<b>{$method_name} Transaction Sync. JOB ID - {$job_id}</b><br> ";
      $this->state->set($key, $init_value);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getMessage(string $method, ?string $job_id): ?string {
    if (!empty($job_id)) {
      $key = self::STATE_KEY_PREFIX . "{$method}:job:{$job_id}";

      return $this->state->get($key);
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setMessage(string $method, ?string $job_id, string $value): void {
    if (!empty($job_id)) {
      $key = self::STATE_KEY_PREFIX . "{$method}:job:{$job_id}";
      $existed_value = $this->getMessage($method, $job_id);
      $this->state->set($key, $existed_value . $value . "<br>");
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteMessage(string $method, ?string $job_id): void {
    if (!empty($job_id)) {
      $key = self::STATE_KEY_PREFIX . "{$method}:job:{$job_id}";
      $this->state->delete($key);
    }
  }

}
