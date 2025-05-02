<?php

namespace Drupal\ipc_syncdb;

use Drupal\commerce_invoice\Entity\InvoiceInterface;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\RounderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * IPC Invoice price splitter service.
 */
class InvoicePriceSplitter implements InvoicePriceSplitterInterface {

  /**
   * The currency storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $currencyStorage;

  /**
   * The rounder.
   *
   * @var \Drupal\commerce_price\RounderInterface
   */
  protected $rounder;

  /**
   * Constructs a new PriceSplitter object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\commerce_price\RounderInterface $rounder
   *   The rounder.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, RounderInterface $rounder) {
    $this->currencyStorage = $entity_type_manager->getStorage('commerce_currency');
    $this->rounder = $rounder;
  }

  /**
   * {@inheritdoc}
   */
  public function split(InvoiceInterface $invoice, Price $amount, $percentage = NULL) {
    /** @var \Drupal\commerce_invoice\Entity\InvoiceItemInterface[] $invoice_items */
    $invoice_items = $invoice->getItems();

    if (!$invoice_items) {
      return [];
    }

    $subtotal_price = $invoice->getSubtotalPrice();
    // Applied all discount line items.
    foreach ($invoice_items as $invoice_item) {
      foreach ($invoice_item->getAdjustments(['ipc_discount']) as $adjustment) {
        $subtotal_price = $subtotal_price->add($adjustment->getAmount());
      }
    }

    if (!$percentage) {
      // The percentage is intentionally not rounded, for maximum precision.
      $percentage = Calculator::divide($amount->getNumber(), $subtotal_price->getNumber());
    }

    // Calculate the initial per-invoice-item amounts using the percentage.
    $amounts = [];
    foreach ($invoice_items as $invoice_item) {
      if (!$invoice_item->getTotalPrice()->isZero()) {
        $individual_amount = $invoice_item->getAdjustedTotalPrice(['ipc_discount']);
        $individual_amount = $individual_amount->multiply($percentage);
        $individual_amount = $this->rounder->round($individual_amount, PHP_ROUND_HALF_DOWN);

        // Due to rounding it is possible for the last calculated
        // per-invoice-item amount to be larger than the total remaining amount.
        if ($amount->isNegative()) {
          if ($individual_amount->lessThan($amount)) {
            $individual_amount = $amount;
          }
        }
        else {
          if ($individual_amount->greaterThan($amount)) {
            $individual_amount = $amount;
          }
        }

        $amounts[$invoice_item->id()] = $individual_amount;
        $amount = $amount->subtract($individual_amount);
      }
    }

    // The individual amounts don't add up to the full amount, distribute
    // the reminder among them.
    if (!$amount->isZero()) {
      /** @var \Drupal\commerce_price\Entity\CurrencyInterface $currency */
      $currency = $this->currencyStorage->load($amount->getCurrencyCode());
      $precision = $currency->getFractionDigits();
      // Use the smallest rounded currency amount (e.g. '0.01' for USD).
      $smallest_number = Calculator::divide('1', pow(10, $precision), $precision);
      $smallest_amount = new Price($smallest_number, $amount->getCurrencyCode());
      if ($amount->isNegative()) {
        $smallest_amount = $smallest_amount->multiply(-1);
      }
      while (!$amount->isZero()) {
        foreach ($amounts as $invoice_item_id => $individual_amount) {
          $amounts[$invoice_item_id] = $individual_amount->add($smallest_amount);
          $amount = $amount->subtract($smallest_amount);
          if ($amount->isZero()) {
            break 2;
          }
        }
      }
    }

    return $amounts;
  }

}
