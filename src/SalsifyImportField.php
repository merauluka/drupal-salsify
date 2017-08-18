<?php

namespace Drupal\salsify_integration;

use Drupal\node\Entity\Node;

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
   * A function to import Salsify data as nodes in Drupal.
   *
   * @param array $product_data
   *   The Salsify individual product data to process.
   */
  public function processSalsifyItem(array $product_data) {
    $content_type = $this->config->get('content_type');

    // Store this to send through to hook_salsify_node_presave_alter().
    $original_product_data = $product_data;

    // Load field mappings keyed by Salsify ID.
    $salsify_field_mapping = SalsifyMultiField::getFieldMappings(
      [
        'entity_type' => 'node',
        'bundle' => $content_type,
      ],
      'salsify_id'
    );

    // Lookup any existing nodes in order to overwrite their contents.
    $results = $this->entityQuery->get('node')
      ->condition('salsify_salsifyid', $product_data['salsify:id'])
      ->execute();

    // Load the existing node or generate a new one.
    if ($results) {
      $nid = array_values($results)[0];
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
    }
    else {
      $title = $product_data['salisfy:id'];
      // Allow users to alter the title set when  node is created by invoking
      // hook_salsify_process_node_title_alter().
      \Drupal::moduleHandler()->alter('salsify_process_node_title', $title, $product_data);
      $node = Node::create([
        'title' => $title,
        'type' => $content_type,
        'created' => strtotime($product_data['salsify:created_at']),
        'changed' => strtotime($product_data['salsify:updated_at']),
        'salsify_salsifyid' => $product_data['salsify:id'],
        'status' => 1,
      ]);
      $node->save();
    }

    // Load the configurable fields for this content type.
    $filtered_fields = Salsify::getContentTypeFields($node->getType());

    // Set the field data against the Salsify node. Remove the data from the
    // serialized list to prevent redundancy.
    foreach ($salsify_field_mapping as $field) {
      if (isset($product_data[$field['salsify_id']])) {
        $options = $this->getFieldOptions((array) $field, $product_data[$field['salsify_id']]);
        /* @var \Drupal\field\Entity\FieldConfig $field_config */
        $field_config = $filtered_fields[$field['field_name']];

        // Run all digital assets through additional processing as media
        // entities.
        if (\Drupal::moduleHandler()->moduleExists('media_entity')) {
          if ($field['salsify_data_type'] == 'digital_asset') {
            if (!isset($media_import)) {
              $media_import = new SalsifyImportMedia($this->configFactory, $this->entityQuery, $this->entityTypeManager);
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
            'salsify_data' => $salsify_data,
            'field_map' => $field,
          ];

          \Drupal::moduleHandler()->alter($hooks, $options, $context);
          $node->set($field['field_name'], $options);
          unset($product_data[$field['salsify_id']]);
        }
      }
    }

    // Allow users to alter the node just before it's saved.
    \Drupal::moduleHandler()->alter(['salsify_node_presave'], $node, $original_product_data);

    $node->save();
  }

}
