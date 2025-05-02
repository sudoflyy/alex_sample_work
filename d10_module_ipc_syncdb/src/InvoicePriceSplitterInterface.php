<?php

namespace Drupal\ipc_syncdb;

use Drupal\commerce_invoice\Entity\InvoiceInterface;
use Drupal\commerce_price\Price;

/**
 * Splits price amounts across invoice items.
 *
 * Useful for dividing a single order-level promotion or fee into multiple
 * order-item-level ones, for easier VAT calculation or refunds.
 */
interface InvoicePriceSplitterInterface {

  /**
   * Splits the given amount across invoice items.
   *
   * @param \Drupal\commerce_invoice\Entity\InvoiceInterface $invoice
   *   The invoice.
   * @param \Drupal\commerce_price\Price $amount
   *   The amount.
   * @param string $percentage
   *   The percentage used to calculate the amount, as a decimal.
   *   For example, '0.2' for 20%. When missing, calculated by comparing
   *   the amount to the invoice subtotal.
   *
   * @return \Drupal\commerce_price\Price[]
   *   An array of amounts keyed by invoice item ID.
   */
  public function split(InvoiceInterface $invoice, Price $amount, $percentage = NULL);

}
