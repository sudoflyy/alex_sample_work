<?php

namespace Drupal\ipc_syncdb;

/**
 * Contains helper methods for Sync DB Api operations.
 *
 * Most functionality has been moved from this class to ipcsync module.
 */
class ApiHelper {

  // The default modifiedOnAfter value for SyncDB polling routines.
  public const POLLING_ROUTINE_START_TIME = '2022-05-04T12:00:00';

  /**
   * Get the current datetime as an ISO-8601 formatted string.
   *
   * @return string
   *   The date time string in ISO-8601 format.
   *
   * @throws \Exception
   */
  public static function getRunTimeDateTimeString(): string {
    $d = new \Datetime('now', new \DateTimeZone('UTC'));

    return $d->format('c');
  }

}
