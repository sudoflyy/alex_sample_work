<?php

namespace Drupal\ipc_syncdb\Utilities;

use Drupal\commerce_product\Entity\ProductInterface;

/**
 * Helper class to store the result of Import.
 */
class ImportProductResult {

  /**
   * The status of the import.
   *
   * @var string
   */
  private string $status;

  /**
   * The message of the import.
   *
   * @var string
   */
  private string $message;

  /**
   * The product instance.
   *
   * @var \Drupal\commerce_product\Entity\ProductInterface|null
   */
  private ?ProductInterface $product;

  /**
   * Constructs a ImportProductResult class.
   *
   * @param string $status
   *   The status from import.
   * @param string $message
   *   The message from import.
   * @param \Drupal\commerce_product\Entity\ProductInterface|null $product
   *   The product or NULL.
   */
  public function __construct(string $status, string $message = '', ?ProductInterface $product = NULL) {
    $this->status = $status;
    $this->message = $message;
    $this->product = $product;
  }

  /**
   * Gets the status.
   *
   * @return string
   *   Return the status value.
   */
  public function getStatus() {
    return $this->status;
  }

  /**
   * Gets the message.
   *
   * @return string
   *   Return the message value.
   */
  public function getMessage() {
    return $this->message;
  }

  /**
   * Gets the product.
   *
   * @return \Drupal\commerce_product\Entity\ProductInterface|null
   *   Returns the product or NULL.
   */
  public function getProduct() {
    return $this->product;
  }

}
