<?php

namespace Drupal\salsify_integration;

use Drupal\node\Entity\Node;

/**
 * Class SalsifyImportSerialized.
 *
 * The class used to perform an import of content into a single field with all
 * Salsify field data serialized. Imports are triggered either through queues
 * during a cron run or via the configuration page.
 *
 * @package Drupal\salsify_integration
 */
class SalsifyImportSerialized extends SalsifyImport {

  /**
   * A function to import Salsify data as nodes in Drupal.
   *
   * @param array $product_data
   *   The Salsify individual product data to process.
   */
  public function processSalsifyItem(array $product_data) {
    $entity_type = $this->config->get('entity_type');
    $entity_bundle = $this->config->get('entity_bundle');

    // Retrieve the full Salsify product array from the cache.
    $cache_entry = $this->cache->get('salsify_import_product_data');
    if ($cache_entry) {
      $salsify_data = $cache_entry->data;
    }
    else {
      $salsify = Salsify::create(\Drupal::getContainer());
      // NOTE: During this call the cached item is refreshed.
      $salsify_data = $salsify->getProductData();
    }

    // Store this to send through to hook_salsify_node_presave_alter().
    $original_product_data = $product_data;

    // Lookup any existing nodes in order to overwrite their contents. Add the
    // accessCheck statement and set it to FALSE to allow unpublished models
    // to be matched as well.
    $results = $this->entityQuery->get('node')
      ->accessCheck(FALSE)
      ->condition('salsify_salsifyid', $product_data['salsify:id'])
      ->execute();

    // Load the existing node or generate a new one.
    if ($results) {
      $entity_id = array_values($results)[0];
      $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
      // If the model in Salsify hasn't been updated since the last time it was
      // imported, then skip it. If it was, then update salsify_updated and
      // pass it along for further processing.
      $salsify_updated = strtotime($product_data['salsify:updated_at']);
      if ($entity->salsify_updated->isEmpty() || $salsify_updated > $entity->salsify_updated->value) {
        $entity->set('salsify_updated', $salsify_updated);
      }
      else {
        return;
      }
    }
    else {
      $title = $product_data['salsify:id'];
      // Allow users to alter the title set when a node is created by invoking
      // hook_salsify_process_node_title_alter().
      \Drupal::moduleHandler()->alter('salsify_process_node_title', $title, $product_data);
      $entity = $this->entityTypeManager->getStorage($entity_type)->create([
        'title' => $title,
        'type' => $entity_bundle,
        'created' => strtotime($product_data['salsify:created_at']),
        'changed' => strtotime($product_data['salsify:updated_at']),
        'salsify_updated' => strtotime($product_data['salsify:updated_at']),
        'salsify_salsifyid' => $product_data['salsify:id'],
        'status' => 1,
      ]);
      $entity->save();
    }

    // Load the configurable fields for this content type.
    $filtered_fields = Salsify::getContentTypeFields($entity_bundle, $entity_type);

    // Load manual field mappings keyed by Salsify ID.
    $salsify_field_mapping = SalsifyMultiField::getFieldMappings(
      [
        'entity_type' => $entity_type,
        'bundle' => $entity_bundle,
        'method' => 'manual',
      ],
      'salsify_id'
    );

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

        // Invoke hook_salsify_process_field_alter() and
        // hook_salsify_process_field_FIELD_TYPE_alter() implementations.
        $hooks = ['salsify_process_field', 'salsify_process_field_' . $field_config->getType()];
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

    // Set the field data against the Salsify node and save the results.
    $salsify_serialized = serialize($product_data);
    $entity->set('salsifysync_data', $salsify_serialized);

    // Allow users to alter the node just before it's saved.
    \Drupal::moduleHandler()->alter(['salsify_node_presave'], $entity, $original_product_data);

    $entity->save();

  }

}
