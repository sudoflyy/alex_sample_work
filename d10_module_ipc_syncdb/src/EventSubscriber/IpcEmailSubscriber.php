<?php

namespace Drupal\ipc_syncdb\EventSubscriber;

use Drupal\commerce\EntityHelper;
use Drupal\commerce_email\EmailEventManager;
use Drupal\commerce_email\EmailSenderInterface;
use Drupal\commerce_email\Entity\EmailInterface;
use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\advancedqueue\Job;
use Drupal\commerce_email\EventSubscriber\EmailSubscriber;
use Drupal\advancedqueue\Event\AdvancedQueueEvents;
use Drupal\ipc_syncdb\Event\IpcCronDrushEvent;

/**
 * Subscribes to Symfony events and maps them to email events.
 *
 * @todo Optimize performance by implementing an event map in \Drupal::state().
 *       This would allow us to subscribe only to events which have emails
 *       defined, and to load only those emails (instead of all of them).
 */
class IpcEmailSubscriber extends EmailSubscriber{

  /**
   * The email sender.
   *
   * @var \Drupal\commerce_email\EmailSenderInterface
   */
  protected $emailSender;

  /**
   * The email event plugin manager.
   *
   * @var \Drupal\commerce_email\EmailEventManager
   */
  protected $emailEventManager;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Contracts\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The commerce_email_queue queue.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected $queue;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new EmailSubscriber object.
   *
   * @param \Drupal\commerce_email\EmailSenderInterface $email_sender
   *   The email sender.
   * @param \Drupal\commerce_email\EmailEventManager $email_event_manager
   *   The email event plugin manager.
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EmailSenderInterface $email_sender, EmailEventManager $email_event_manager, EventDispatcherInterface $event_dispatcher, QueueFactory $queue_factory, ModuleHandlerInterface $module_handler, EntityTypeManagerInterface $entity_type_manager) {
    $this->emailSender = $email_sender;
    $this->emailEventManager = $email_event_manager;
    $this->eventDispatcher = $event_dispatcher;
    $this->queue = $queue_factory->get('commerce_email_queue');
    $this->moduleHandler = $module_handler;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritDoc}
   */
  public static function getSubscribedEvents() {
    // Subscribe to kernel request very early.
    $events[KernelEvents::REQUEST][] = ['onRequest', 900];
    $events[AdvancedQueueEvents::PRE_PROCESS][] = ['onRequest', 900];
    $events[IpcCronDrushEvent::IPC_DRUSH_CRON][] = ['onRequest', 800];

    return $events;
  }

}
