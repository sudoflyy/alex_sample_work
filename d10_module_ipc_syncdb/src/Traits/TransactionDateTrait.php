<?php

namespace Drupal\ipc_syncdb\Traits;

use Drupal\Core\Entity\ContentEntityInterface;

trait TransactionDateTrait {

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;


  /**
   * Helper function to get Transaction date..
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $commerce_entity
   *   The commerce entity.
   *
   * @return string
   *   Transaction date Value.
   *
   */
  protected function getTransactionDate(ContentEntityInterface $commerce_entity): ?string {
    $transaction_date = $commerce_entity->get('placed')->value ?: ($commerce_entity->get('changed')->value ?: ($commerce_entity->get('created')->value ?: NULL));

    if ($transaction_date) {
      return $this->dateFormatter->format($transaction_date, 'custom', 'Y-m-d', 'US/Central');
    }

    return NULL;
  }

}
