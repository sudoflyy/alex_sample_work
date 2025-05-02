<?php

namespace Drupal\ipc_syncdb\Logger;

/**
 * Handles Logging of actions during transaction.
 */
interface TransactionManagerLoggerInterface {

  /**
   * Init Logger with first message.
   *
   * @param string $method
   *   Post or get method.
   * @param string|NULL $job_id
   *   The Job ID.
   *
   * @return void
   */
  public function initLogger(string $method, ?string $job_id): void;

  /**
   * Get the message.
   *
   * @param string $method
   *   Post or get method.
   * @param string|NULL $job_id
   *   The Job ID.
   *
   * @return string|null
   */
  public function getMessage(string $method, ?string $job_id): ?string;

  /**
   * Set the message.
   *
   * @param string $method
   *   Post or get method.
   * @param string|NULL $job_id
   *   The Job ID.
   * @param string $value
   *   The new value
   *
   * @return void
   */
  public function setMessage(string $method, ?string $job_id, string $value): void;

  /**
   * Delete the message.
   *
   * @param string $method
   *   Post or get method.
   * @param string|NULL $job_id
   *   The Job ID.
   *
   * @return void
   */
  public function deleteMessage(string $method, ?string $job_id): void;

}
