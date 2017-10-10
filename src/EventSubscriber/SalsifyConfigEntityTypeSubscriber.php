<?php

namespace Drupal\salsify_integration\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\salsify_integration\Event\SalsifyGetEntityTypesEvent;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscriber for entity type support events.
 */
class SalsifyConfigEntityTypeSubscriber implements EventSubscriberInterface {

  /**
   * The Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The module handler interface.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  private $moduleHandler;

  /**
   * Constructs a SalsifySubscriber object.
   */
  public function __construct(ModuleHandlerInterface $module_handler, EntityTypeManagerInterface $entity_type_manager) {
    $this->moduleHandler = $module_handler;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('module_handler'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[SalsifyGetEntityTypesEvent::GET_TYPES][] = ['addEntityTypes'];
    return $events;
  }

  /**
   * Called when the SalsifyGetEntityTypesEvent::GET_TYPES event is dispatched.
   *
   * @param \Drupal\salsify_integration\Event\SalsifyGetEntityTypesEvent $event
   *   The event triggered while loading the config entity types.
   */
  public function addEntityTypes(SalsifyGetEntityTypesEvent $event) {
    // Get the list of entity types on the system.
    $entity_types_list = $event->getEntityTypesList();
    ksort($entity_types_list);
    $original_entity_types_list = $entity_types_list;
    $entity_types = $this->entityTypeManager->getDefinitions();
    // Add support for the ECK module.
    if ($this->moduleHandler->moduleExists('eck')) {
      $eck_types = [];
      foreach ($entity_types as $entity_type) {
        if ($entity_type->getProvider() == 'eck' && $entity_type->getGroup() == 'content') {
          $eck_types[$entity_type->id()] = $entity_type->getLabel();
        }
      }
      if ($eck_types) {
        $entity_types_list = $entity_types_list + $eck_types;
        ksort($entity_types_list);
      }
    }
    // Add support for Commerce Products.
    if ($this->moduleHandler->moduleExists('commerce_product')) {
      $entity_types_list['commerce_product'] = t('Commerce Product');
      ksort($entity_types_list);
    }
    // If the entity type list has changed, update it on the event.
    if ($entity_types_list != $original_entity_types_list) {
      $event->setEntityTypesList($entity_types_list);
    }
  }

}
