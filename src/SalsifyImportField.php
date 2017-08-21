<?php

namespace Drupal\salsify_integration;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class SalsifyImportField.
 *
 * The class used to perform content imports in to individual fields. Imports
 * are triggered either through queues during a cron run or via the
 * configuration page.
 *
 * @package Drupal\salsify_integration
 */
class SalsifyImportField extends SalsifyImport {

  /**
   * The module handler interface.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  private $moduleHandler;

  /**
   * SalsifyImportField constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\Query\QueryFactory $entity_query
   *   The entity query interface.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager interface.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_salsify
   *   The Salsify cache interface.
   * @param \Drupal\Core\Extension\ModuleHandler $module_handler
   *   The module handler interface.
   */
  public function __construct(ConfigFactoryInterface $config_factory, QueryFactory $entity_query, EntityTypeManagerInterface $entity_type_manager, CacheBackendInterface $cache_salsify, ModuleHandlerInterface $module_handler) {
    parent::__construct($config_factory, $entity_query, $entity_type_manager, $cache_salsify);
    $this->moduleHandler = $module_handler;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity.query'),
      $container->get('entity_type.manager'),
      $container->get('cache.default'),
      $container->get('module_handler')
    );
  }

  /**
   * A function to import Salsify data as nodes in Drupal.
   *
   * @param array $product_data
   *   The Salsify individual product data to process.
   */
  public function processSalsifyItem(array $product_data) {
    $entity_type = $this->config->get('entity_type');
    $entity_bundle = $this->config->get('entity_bundle');

    // Store this to send through to hook_salsify_node_presave_alter().
    $original_product_data = $product_data;

    // Load field mappings keyed by Salsify ID.
    $salsify_field_mapping = SalsifyMultiField::getFieldMappings(
      [
        'entity_type' => $entity_type,
        'bundle' => $entity_bundle,
      ],
      'salsify_id'
    );

    // Lookup any existing nodes in order to overwrite their contents.
    $results = $this->entityQuery->get($entity_type)
      ->condition('salsify_salsifyid', $product_data['salsify:id'])
      ->execute();

    // Load the existing node or generate a new one.
    if ($results) {
      $entity_id = array_values($results)[0];
      $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
    }
    else {
      $title = $product_data['salsify:id'];
      // Allow users to alter the title set when a node is created by invoking
      // hook_salsify_process_node_title_alter().
      $this->moduleHandler->alter('salsify_process_node_title', $title, $product_data);
      $entity = $this->entityTypeManager->getStorage($entity_type)->create([
        'title' => $title,
        'type' => $entity_bundle,
        'created' => strtotime($product_data['salsify:created_at']),
        'changed' => strtotime($product_data['salsify:updated_at']),
        'salsify_salsifyid' => $product_data['salsify:id'],
        'status' => 1,
      ]);
      $entity->getTypedData();
      $entity->save();
    }

    // Load the configurable fields for this content type.
    $filtered_fields = Salsify::getContentTypeFields($entity_type, $entity_bundle);

    // Set the field data against the Salsify node. Remove the data from the
    // serialized list to prevent redundancy.
    foreach ($salsify_field_mapping as $field) {
      if (isset($product_data[$field['salsify_id']])) {
        $options = $this->getFieldOptions((array) $field, $product_data[$field['salsify_id']]);
        /* @var \Drupal\field\Entity\FieldConfig $field_config */
        $field_config = $filtered_fields[$field['field_name']];

        // Run all digital assets through additional processing as media
        // entities.
        if ($this->moduleHandler->moduleExists('media_entity')) {
          if ($field['salsify_data_type'] == 'digital_asset') {
            if (!isset($media_import)) {
              $media_import = SalsifyImportMedia::create(\Drupal::getContainer());
            }
            /* @var \Drupal\media_entity\Entity\Media $media */
            $media_entities = $media_import->processSalsifyMediaItem($field, $product_data);
            if ($media_entities) {
              $options = [];
              foreach ($media_entities as $media) {
                $options[] = [
                  'target_id' => $media->id(),
                ];
              }
            }
          }
        }

        if ($field_config) {
          // Invoke hook_salsify_process_field_alter() and
          // hook_salsify_process_field_FIELD_TYPE_alter() implementations.
          $hooks = [
            'salsify_process_field',
            'salsify_process_field_' . $field_config->getType(),
          ];
          $context = [
            'field_config' => $field_config,
            'product_data' => $product_data,
            'field_map' => $field,
          ];

          $this->moduleHandler->alter($hooks, $options, $context);

          // Truncate strings if they are too long for the string field they
          // are mapped against.
          if ($field_config->getType() == 'string') {
            $field_storage = $field_config->getFieldStorageDefinition();
            $max_length = $field_storage->getSetting('max_length');
            if (strlen($options) > $max_length) {
              $options = substr($options, 0, $max_length);
            }
          }

          $entity->set($field['field_name'], $options);
          unset($product_data[$field['salsify_id']]);
        }
      }
    }

    // Allow users to alter the node just before it's saved.
    $this->moduleHandler->alter(['salsify_entity_presave'], $entity, $original_product_data);

    $entity->save();
  }

}
