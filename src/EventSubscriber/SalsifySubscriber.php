<?php

namespace Drupal\salsify_integration\EventSubscriber;

use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Queue\QueueFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscriber for configuration events.
 */
class SalsifySubscriber implements EventSubscriberInterface {

  /**
   * The QueueFactory object.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  private $queueFactory;

  /**
   * Constructs a SalsifySubscriber object.
   */
  public function __construct(QueueFactory $queue_factory) {
    $this->queueFactory = $queue_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('queue')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[ConfigEvents::SAVE][] = ['checkContentTypeFields'];
    return $events;
  }

  /**
   * This method is called whenever the ConfigEvents::SAVE event is dispatched.
   *
   * @param \Drupal\Core\Config\ConfigCrudEvent $event
   *   The event triggered by the configuration update.
   */
  public function checkContentTypeFields(ConfigCrudEvent $event) {
    $config = $event->getConfig();
    if ($config->getName() == 'salsify_integration.settings') {
      // Check to see if the content type was changed. If so, need to remove any
      // Salsify fields from the old content type and move them to the new.
      $changed = $event->isChanged('entity_type');

      if ($changed && $config->get('entity_type') && $config->getOriginal('entity_type')) {
        /** @var \Drupal\Core\Queue\QueueInterface $queue */
        $queue = $this->queueFactory->get('salsify_integration_entity_type_update');
        $item = [
          'original' => [
            'entity_type' => $config->getOriginal('entity_type'),
            'bundle' => $config->getOriginal('bundle'),
          ],
          'current' => [
            'entity_type' => $config->get('entity_type'),
            'bundle' => $config->get('bundle'),
          ],
        ];
        $queue->createItem($item);
      }
    }
  }

}
