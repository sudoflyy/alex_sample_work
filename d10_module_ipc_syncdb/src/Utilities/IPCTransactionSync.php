<?php

namespace Drupal\ipc_syncdb\Utilities;

use Drupal\ipcsync\Api;
use Drupal\ipcsync\Utilities\TransactionSync;

/**
 * Extends base class for Transaction Sync.
 */
class IPCTransactionSync extends TransactionSync {

  /**
   * Create a Payment Transaction record in Sync DB.
   *
   * @param object $requestParams
   *   The parameters to create a transaction.
   * @param string $body
   *   Body for API call.
   * @param int $order_id
   *   The order_id in Drupal.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public static function postPayment(object $requestParams, string $body, int $order_id = NULL) {
    $response = Api::processApiRequest('IPCTransactionAPI', "/transaction/PostPayment", 'POST', $requestParams, $body);
    if ($order_id) {
      Api::generateLogMessage($response, 'PostPayment', 'order_id', $order_id);
    }
    else {
      Api::generateLogMessage($response, 'PostPayment');
    }
    Api::generateDetailedLogMessage('IPCTransactionAPI', 'PostPayment', $requestParams, $response, $body);

    return $response;
  }

}
