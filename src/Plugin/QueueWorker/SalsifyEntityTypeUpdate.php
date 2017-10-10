<?php

namespace Drupal\salsify_integration\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\salsify_integration\Salsify;
use Drupal\salsify_integration\SalsifyFields;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides functionality for the SalsifyEntityTypeUpdate Queue.
 *
 * @QueueWorker(
 *   id = "salsify_integration_entity_type_update",
 *   title = @Translation("Salsify: Entity Type Update"),
 *   cron = {"time" = 10}
 * )
 */
class SalsifyEntityTypeUpdate extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Entity query factory.
   *
   * @var \Drupal\Core\Entity\Query\QueryFactory
   */
  protected $entityQuery;

  /**
   * The QueueFactory object.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  private $queueFactory;

  /**
   * Creates a new SalsifyContentTypeUpdate object.
   *
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The QueueFactory object.
   * @param \Drupal\Core\Entity\Query\QueryFactory $entity_query
   *   The query factory.
   */
  public function __construct(EntityFieldManagerInterface $entity_field_manager, EntityTypeManagerInterface $entity_type_manager, QueueFactory $queue_factory, QueryFactory $entity_query) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->queueFactory = $queue_factory;
    $this->entityQuery = $entity_query;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('entity_field.manager'),
      $container->get('entity_type.manager'),
      $container->get('queue'),
      $container->get('entity.query')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    // Set default values.
    $original_type = $data['original']['entity_type'];
    $current_type = $data['current']['entity_type'];
    $original_bundle = $data['original']['bundle'];
    $current_bundle = $data['current']['bundle'];

    // Delete the old content before switching out fields.
    $old_entity_ids = $this->entityQuery->get($original_type)
      ->exists('salsify_id')
      ->execute();
    $partial_entity_ids = array_chunk($old_entity_ids, 50);
    foreach ($partial_entity_ids as $partial_entity_id) {
      $old_entities = $this->entityTypeManager->getStorage($original_type)
        ->loadMultiple($partial_entity_id);
      foreach ($old_entities as $old_entity) {
        $old_entity->delete();
      }
    }

    // Gather the field and field storage definitions from the old entity type.
    $fields = $this->entityFieldManager->getFieldDefinitions($original_type, $original_bundle);
    $fields_storage = $this->entityFieldManager->getFieldStorageDefinitions($original_type);

    // Load the field mappings for Salsify and Drupal, but only the dynamic
    // fields. Delete any manual mappings.
    $salsify_field_mapping = Salsify::getFieldMappings(
      [
        'entity_type' => $original_type,
        'bundle' => $original_bundle,
        'method' => 'dynamic',
      ],
      'field_name'
    );

    // Delete any manual field mappings.
    $salsify_manual_field_mappings = Salsify::getFieldMappings([
      'entity_type' => $original_type,
      'bundle' => $original_bundle,
      'method' => 'manual',
    ]);
    foreach ($salsify_manual_field_mappings as $salsify_manual_field_mapping) {
      Salsify::deleteFieldMapping($salsify_manual_field_mapping);
    }

    foreach ($salsify_field_mapping as $field_name => $salsify_field) {
      if (isset($fields[$field_name])) {
        /* @var \Drupal\field\Entity\FieldConfig $field */
        $field = $fields[$field_name];
        $field_storage = $fields_storage[$field_name];
        $field_storage_settings = [];

        // If the entity type has changed, then setup a new storage value for
        // the field if needed.
        if ($original_type != $current_type) {
          $field_storage_settings = [
            'id' => $current_type . '.' . $field_name,
            'field_name' => $field_name,
            'entity_type' => $current_type,
            'type' => $field_storage->getType(),
            'settings' => $field_storage->getSettings(),
            'locked' => FALSE,
            'cardinality' => -1,
            'translatable' => TRUE,
            'indexes' => [],
            'persist_with_no_fields' => FALSE,
            'custom_storage' => FALSE,
          ];
          $new_field_storage = FieldStorageConfig::create($field_storage_settings);
          $new_field_storage->save();
        }

        // Setup the new field for the new content type and store it.
        $field_settings = [
          'field_name' => $field->getName(),
          'entity_type' => $current_type,
          'bundle' => $current_bundle,
          'label' => $field->get('label'),
        ];
        // Create the field against the given content type.
        $new_field = FieldConfig::create($field_settings);
        $new_field->save();

        // Update the field mappings to point to the new content type.
        Salsify::deleteFieldMapping($salsify_field);
        $salsify_field['entity_type'] = $current_type;
        $salsify_field['bundle'] = $current_bundle;
        Salsify::createFieldMapping($salsify_field);

        // Create the form and view displays for the field.
        SalsifyFields::createFieldFormDisplay($current_type, $current_bundle, $field_name, $salsify_field['salsify_data_type']);
        SalsifyFields::createFieldViewDisplay($current_type, $current_bundle, $field_name, 'default');

        // The field has been moved. Remove it from the old content type.
        $field->delete();
      }
    }
  }

}
