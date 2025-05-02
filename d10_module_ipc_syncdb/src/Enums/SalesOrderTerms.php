<?php

namespace Drupal\ipc_syncdb\Enums;

/**
 * Sales order terms enums.
 */
class SalesOrderTerms {

  /**
   * Sales Order Term "Ineligible".
   */
  public const INELIGIBLE = 'ineligible';

  /**
   * Sales Order Term "1% 10 NET 30".
   */
  public const ONE10NET30 = 5;

  /**
   * Sales Order Term "2% 10 NET 30".
   */
  public const TWO10NET30 = 6;

  /**
   * Sales Order Term "Due on receipt".
   */
  public const DUE_ON_RECEIPT = 4;

  /**
   * Sales Order Term "NET 10".
   */
  public const NET10 = 10;

  /**
   * Sales Order Term "NET 15".
   */
  public const NET15 = 1;

  /**
   * Sales Order Term "NET 20".
   */
  public const NET20 = 8;

  /**
   * Sales Order Term "NET 25".
   */
  public const NET25 = 9;

  /**
   * Sales Order Term "NET 30".
   */
  public const NET30 = 2;

  /**
   * Sales Order Term "NET 45".
   */
  public const NET45 = 7;

  /**
   * Sales Order Term "NET 60".
   */
  public const NET60 = 3;

  /**
   * Sales Order Term "NET 90".
   */
  public const NET90 = 12;

  /**
   * Sales Order Term "Prepaid".
   */
  public const PREPAID = 11;

  /**
   * Map of the API Terms and Drupal Terms.
   */
  private const MAP_OF_SALES_ORDER_TERMS =
    [
      self::ONE10NET30 => '1_10net30',
      self::TWO10NET30 => '2_10net30',
      self::DUE_ON_RECEIPT => 'due_on_receipt',
      self::NET10 => 'net10',
      self::NET15 => 'net15',
      self::NET20 => 'net20',
      self::NET25 => 'net25',
      self::NET30 => 'net30',
      self::NET45 => 'net45',
      self::NET60 => 'net60',
      self::NET90 => 'net90',
      self::PREPAID => 'prepaid',
    ];

  /**
   * Helper function to get Sales Order Term by "salesOrderTermsId" from API.
   *
   * @param string $sales_order_term_id
   *   Sales order term ID.
   *
   * @return string
   *   The SalesOrderTerm key in Drupal instance.
   */
  public static function getDrupalSalesOrderTerm(string $sales_order_term_id): string {
    return self::MAP_OF_SALES_ORDER_TERMS[$sales_order_term_id] ?? self::INELIGIBLE;
  }

  /**
   * Helper function to get Sales Order Term by "salesOrderTermsId" from API.
   *
   * @param string $sales_order_term
   *   Sales order term Drupal value.
   *
   * @return string
   *   The SalesOrderTerm key in API instance.
   */
  public static function getApiSalesOrderTerm(string $sales_order_term): string {
    $founded_key = array_search($sales_order_term, self::MAP_OF_SALES_ORDER_TERMS);

    return $founded_key ?: '';
  }

}
