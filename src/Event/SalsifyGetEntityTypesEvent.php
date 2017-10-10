<?php

namespace Drupal\salsify_integration\Event;

use Symfony\Component\EventDispatcher\Event;

/**
 * Class SalsifyGetEntityTypesEvent.
 *
 * @package Drupal\salsify_integration
 */
class SalsifyGetEntityTypesEvent extends Event {

  const GET_TYPES = 'salsify.get_entity_type';

  /**
   * The list of entity ids=>entity labels to use in the Salsify config form.
   *
   * @var array
   */
  private $entityTypesList;

  /**
   * SalsifyGetEntityTypesEvent constructor.
   *
   * @param array $entity_types_list
   *   The list of entity ids=>entity labels to use in the Salsify config form.
   */
  public function __construct(array $entity_types_list) {
    $this->entityTypesList = $entity_types_list;
  }

  /**
   * Get the entity types available.
   *
   * @return array
   *   The array of entity types (machine name => UI friendly name).
   */
  public function getEntityTypesList() {
    return $this->entityTypesList;
  }

  /**
   * Function to set the updated entity type list.
   *
   * @param array $entity_types_list
   *   The list of entities to set against the event.
   */
  public function setEntityTypesList(array $entity_types_list) {
    $this->entityTypesList = $entity_types_list;
  }

}
