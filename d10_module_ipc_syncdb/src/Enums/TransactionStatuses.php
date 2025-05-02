<?php

namespace Drupal\ipc_syncdb\Enums;

/**
 * Transaction statuses enum.
 */
class TransactionStatuses {

  /**
   * Transaction Statuses "Open" of Invoice.
   */
  public const INVOICE_OPEN = 11;

  /**
   * Transaction Statuses "Paid in Full" of Invoice.
   */
  public const INVOICE_PAID_IN_FULL = 12;

  /**
   * Transaction Statuses "Pending Approval" of Invoice.
   */
  public const INVOICE_PENDING_APPROVAL = 13;

  /**
   * Transaction Statuses "Rejected" of Invoice.
   */
  public const INVOICE_REJECTED = 14;

  /**
   * Transaction Statuses "Voided" of Invoice.
   */
  public const INVOICE_VOIDED = 85;

  /**
   * Transaction Statuses "Open" of Quote.
   */
  public const QUOTE_OPEN = 43;

  /**
   * Transaction Statuses "Processed" of Quote.
   */
  public const QUOTE_PROCESSED = 44;

  /**
   * Transaction Statuses "Closed" of Quote.
   */
  public const QUOTE_CLOSED = 45;

  /**
   * Transaction Statuses "Voided" of Quote.
   */
  public const QUOTE_VOIDED = 46;

  /**
   * Transaction Statuses "Expired" of Quote.
   */
  public const QUOTE_EXPIRED = 47;

  /**
   * Transaction Statuses "Pending Approval" of Order.
   */
  public const ORDER_PENDING_APPROVAL = 24;

  /**
   * Transaction Statuses "Pending Fulfillment" of Order.
   */
  public const ORDER_PENDING_FULFILLMENT = 25;

  /**
   * Transaction Statuses "Cancelled" of Order.
   */
  public const ORDER_CANCELLED = 26;

  /**
   * Transaction Statuses "Partially Fulfilled" of Order.
   */
  public const ORDER_PARTIALLY_FULFILLED = 27;

  /**
   * Transaction Statuses "Pending Billing/Partially Fulfilled" of Order.
   */
  public const ORDER_PENDING_BILLING_PARTIALLY_FULFILLED = 28;

  /**
   * Transaction Statuses "Pending Billing" of Order.
   */
  public const ORDER_PENDING_BILLING = 29;

  /**
   * Transaction Statuses "Billed" of Order.
   */
  public const ORDER_BILLED = 30;

  /**
   * Transaction Statuses "Closed" of Order.
   */
  public const ORDER_CLOSED = 31;

}
