<?php

namespace Drupal\ipc_syncdb\Event;

use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * Event that is fired when cron maintenance tasks are performed.
 *
 * @see rules_cron()
 */
class IpcCronDrushEvent extends GenericEvent {

  const IPC_DRUSH_CRON = 'ipc_syncdb_drush_cron';

}
