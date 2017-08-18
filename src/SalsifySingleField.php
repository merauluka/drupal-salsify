<?php

namespace Drupal\salsify_integration;

use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Class Salsify.
 *
 * @package Drupal\salsify_integration
 */
class SalsifySingleField extends Salsify {

  /**
   * The main Salsify product field import function.
   *
   * This function syncs field configuration data from Salsify and ensures that
   * Drupal is ready to receive the fields that were passed in the product
   * feed from Salsify.
   *
   * @var array $product_data
   *   The array of product data from Salsify.
   *
   * @return mixed
   *   Returns an array of product and field data or a failure message.
   */
  protected function prepareSalsifyFields(array $product_data, $entity_type, $content_type) {
    // Create an id and textarea field to store the Salsify ID and the
    // serialized Salsify data. Check for existence prior to creating the field.
    if ($content_type) {

      // Load the dynamic fields in the system from previous Salsify imports.
      $salsify_fields = [
        'salsify_salsifyid' => t('Salsify ID'),
        'salsifysync_data' => t('Salsify Data'),
        'salsify_updated' => t('Salsify Date Updated'),
      ];
      $field_mapping = $this->getFieldMappings(
        [
          'entity_type' => $entity_type,
          'bundle' => $content_type,
          'method' => 'dynamic',
        ],
        'field_name'
      );
      $field_diff = array_diff_key($field_mapping, $salsify_fields);

      // Remove the manually mapped Salsify Fields from the product data so they
      // aren't added back into the system.
      $manual_field_mapping = $this->getFieldMappings(
        [
          'entity_type' => $entity_type,
          'bundle' => $content_type,
          'method' => 'manual',
        ],
        'salsify_id'
      );
      $manual_field_names = array_keys($manual_field_mapping);
      $salsify_field_data = array_diff_key($product_data['fields'], $manual_field_names);

      // Load all of the fields generated via the Field API.
      $filtered_fields = $this->getContentTypeFields($content_type);

      // Find all of the fields from Salsify that are already in the system and
      // update the field mapping.
      $salsify_intersect = array_intersect_key($salsify_fields, $field_mapping);
      foreach ($salsify_intersect as $salsify_field_name => $salsify_field_label) {
        $updated_record = $field_mapping[$salsify_field_name];
        $updated_record['data'] = $salsify_field_name == 'salsifysync_data' ? serialize($salsify_field_data) : '';
        $updated_record['changed'] = time();
        $this->updateFieldMapping($updated_record);
      }

      // Create any fields that don't yet exist in the system.
      $salsify_diff = array_diff_key($salsify_fields, $field_mapping);
      foreach ($salsify_diff as $salsify_field_name => $salsify_field_label) {
        // If the field exists on the system, but isn't in the map, just add it
        // it to the map instead of trying to create a new field. This should
        // cover if fields were left over from an uninstall.
        if (isset($filtered_fields[$salsify_field_name])) {
          $this->createFieldMapping([
            'field_id' => $salsify_field_name,
            'salsify_id' => $salsify_field_name,
            'salsify_data_type' => '',
            'entity_type' => $entity_type,
            'bundle' => $content_type,
            'field_name' => $salsify_field_name,
            'data' => ($salsify_field_name == 'salsifysync_data' ? serialize($salsify_field_data) : ''),
            'method' => 'dynamic',
            'created' => time(),
            'changed' => time(),
          ]);
        }
        // Add a record to track the Salsify field and the new Drupal field map.
        else {
          $this->createDynamicField($product_data, $salsify_field_name, $salsify_field_label);
        }
      }

      // Find any Salsify fields that are already in the system that weren't in
      // the Salsify feed. This means they were deleted from Salsify and need to
      // be deleted on the Drupal side.
      if ($filtered_fields) {
        $remove_fields = array_intersect_key($filtered_fields, $field_diff);
        foreach ($remove_fields as $key => $field) {
          if (strpos($key, 'salsify') == 0) {
            $field->delete();
          }
        }
        foreach ($field_diff as $salsify_field_id => $field) {
          if (strpos($salsify_field_id, 'salsify') == 0) {
            $diff_field_name = $field_mapping[$salsify_field_id]['field_name'];
            if (isset($filtered_fields[$diff_field_name])) {
              $this->deleteDynamicField($filtered_fields[$diff_field_name]);
            }
            // Delete the field mapping from the database.
            $this->deleteFieldMapping([
              'entity_type' => $entity_type,
              'bundle' => $content_type,
              'field_name' => $salsify_field_id,
            ]);
          }
        }
      }
    }

  }

  /**
   * The main product import function.
   *
   * This is the main function of this class. Running this function will
   * initiate a field data sync prior to importing product data. Once the field
   * data is ready, the product data is imported using Drupal's queue system.
   *
   * @param bool $process_immediately
   *   If set to TRUE, the product import will bypass the queue system.
   */
  public function importProductData($process_immediately = FALSE) {
    try {
      // Load the product and field data from Salsify.
      $product_data = $this->getProductData();

      // Verify the field exists on the chosen content type. Recreate on the fly
      // if necessary.
      $content_type = $this->getContentType();
      if ($content_type) {
        $this->prepareSalsifyFields($product_data, 'node', $content_type);

        // Import the actual product data into a serialized field.
        if (!empty($product_data['products'])) {
          // Handle cases where the user wants to perform all of the data
          // processing immediately instead of waiting for the queue to finish.
          if ($process_immediately) {
            $salsify_import = SalsifyImportSerialized::create(\Drupal::getContainer());
            foreach ($product_data['products'] as $product) {
              $salsify_import->processSalsifyItem($product);
            }
            return [
              'status' => 'status',
              'message' => t('The Salsify data import is complete.'),
            ];
          }
          // Add each product value into a queue for background processing.
          else {
            /** @var \Drupal\Core\Queue\QueueInterface $queue */
            $queue = \Drupal::service('queue')
              ->get('salsify_integration_serialized_import');
            foreach ($product_data['products'] as $product) {
              $queue->createItem(
                [
                  'product_data' => $product,
                ]
              );
            }
          }
        }
        else {
          $message = t('Could not complete Salsify data import. No product data is available')->render();
          $this->logger->error($message);
          return [
            'status' => 'error',
            'message' => $message,
          ];
        }
      }
      else {
        $message = t('Could not complete Salsify field data import. No content type configured.')->render();
        $this->logger->error($message);
      }
    }
    catch (MissingDataException $e) {
      $message = t('A error occurred while making the request to Salsify. Check the API settings and try again.')->render();
      $this->logger->error($message);
      return [
        'status' => 'error',
        'message' => $message,
      ];
    }
  }

  /**
   * Utility function that creates a field on a node for Salsify data.
   *
   * @param array $product_data
   *   The array of field and product data from Salsify.
   * @param string $field_name
   *   The machine name for the Drupal field.
   * @param string $field_label
   *   The label for the Drupal field.
   */
  protected function createDynamicField(array $product_data, $field_name, $field_label) {
    $content_type = $this->getContentType();
    $field_storage = FieldStorageConfig::loadByName('node', $field_name);
    $field_settings = $this->getFieldSettingsByType($content_type, $field_name, $field_label);
    $field = FieldConfig::loadByName('node', $content_type, $field_name);
    if (empty($field_storage)) {
      $field_storage = FieldStorageConfig::create($field_settings['field_storage']);
      $field_storage->save();
    }
    if (empty($field)) {
      // Setup the field configuration options.
      $field_settings['field']['field_storage'] = $field_storage;
      // Create the field against the given content type.
      $field = FieldConfig::create($field_settings['field']);
      $field->save();
    }

    // Add a record to track the Salsify field and the new Drupal field map.
    $this->createFieldMapping([
      'field_id' => $field_name,
      'salsify_id' => $field_name,
      'salsify_data_type' => '',
      'entity_type' => 'node',
      'bundle' => $content_type,
      'field_name' => $field_name,
      'data' => ($field_name == 'salsifysync_data' ? serialize($product_data['fields']) : ''),
      'method' => 'dynamic',
      'created' => time(),
      'changed' => time(),
    ]);

  }

  /**
   * Helper function that returns Drupal field options based on a Salsify type.
   *
   * @param string $content_type
   *   The content type to set the field against.
   * @param string $field_name
   *   The machine name for the Drupal field.
   * @param string $field_label
   *   The string to use as the field's label.
   *
   * @return array
   *   An array of field options for the generated field.
   */
  protected function getFieldSettingsByType($content_type, $field_name, $field_label) {
    $field_settings = [
      'field' => [
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => $content_type,
        'label' => $field_label,
      ],
      'field_storage' => [
        'id' => 'node.' . $field_name,
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => 'string_long',
        'module' => 'core',
        'settings' => [],
        'locked' => FALSE,
        'cardinality' => 1,
        'translatable' => TRUE,
        'indexes' => [],
        'persist_with_no_fields' => FALSE,
        'custom_storage' => FALSE,
      ],
    ];

    return $field_settings;
  }

}
