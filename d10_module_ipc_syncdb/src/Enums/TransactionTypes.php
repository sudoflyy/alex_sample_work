<?php

namespace Drupal\ipc_syncdb\Enums;

/**
 * Transaction type enum.
 */
class TransactionTypes {

  /**
   * Transaction Type ID of Invoice.
   */
  public const INVOICE = 6;

  /**
   * Transaction Type ID of Sales Order.
   */
  public const SALES_ORDER = 11;

  /**
   * Transaction Type ID of Quote.
   */
  public const QUOTES = 14;

  /**
   * Transaction types we currently support.
   *
   * @return int[]
   *   The supported transaction types.
   */
  public static function supportedTransactionTypes(): array {
    return [self::SALES_ORDER, self::QUOTES, self::INVOICE];
  }

}
