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
  protected function prepareSalsifyFields(array $product_data) {
    $content_type = $this->getContentType();
    $salsify_fields = [
      'salsify_salsifyid' => t('Salsify ID'),
      'salsifysync_data' => t('Salsify Data'),
    ];
    $field_mapping = $this->getFieldMappings();
    $field_diff = array_diff_key($field_mapping, $salsify_fields);

    // Create an id and textarea field to store the Salsify ID and the
    // serialized Salsify data. Check for existence prior to creating the field.
    if ($content_type) {
      $fields = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', $content_type);
      $filtered_fields = array_filter(
        $fields, function ($field_definition) {
          return $field_definition instanceof FieldConfig;
        }
      );

      // Find all of the fields from Salsify that are already in the system and
      // update the field mapping.
      $salsify_intersect = array_intersect_key($salsify_fields, $field_mapping);
      foreach ($salsify_intersect as $salsify_field_name => $salsify_field_label) {
        $this->updateFieldMapping('field_name', $salsify_field_name, [
          'data' => ($salsify_field_name == 'salsifysync_data' ? serialize($product_data['fields']) : ''),
          'changed' => time(),
        ]);
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
            'field_name' => $salsify_field_name,
            'data' => ($salsify_field_name == 'salsifysync_data' ? serialize($product_data['fields']) : ''),
            'created' => time(),
            'changed' => time(),
          ]);
        }
        // Add a record to track the Salsify field and the new Drupal field map.
        else {
          $this->createDynamicField($product_data, $salsify_field_name, $salsify_field_label);
        }
      }

      // Find any fields that are already in the system that weren't in the
      // Salsify feed. This means they were deleted from Salsify and need to be
      // deleted on the Drupal side.
      if ($filtered_fields) {
        $remove_fields = array_intersect_key($filtered_fields, $field_diff);
        foreach ($remove_fields as $key => $field) {
          if (strpos($key, 'salsify') == 0) {
            $field->delete();
          }
        }
        foreach ($field_diff as $key => $field) {
          if (strpos($key, 'salsify') == 0) {
            $this->deleteDynamicField($field->field_id, $filtered_fields[$field_mapping[$key]->field_name]);
          }
        }
      }
    }
    else {
      $message = t('Could not complete Salsify field data import. No content type configured.')->render();
      $this->logger->error($message);
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
      $this->prepareSalsifyFields($product_data);

      // Import the actual product data into a serialized field.
      if (!empty($product_data['products'])) {
        // Handle cases where the user wants to perform all of the data
        // processing immediately instead of waiting for the queue to finish.
        if ($process_immediately) {
          $salsify_import = new SalsifyImportSerialized($this->configFactory, $this->entityQuery, $this->entityTypeManager);
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
            ->get('rinnai_salsify_serialized_import');
          foreach ($product_data['products'] as $product) {
            $queue->createItem($product);
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
      'field_name' => $field_name,
      'data' => ($field_name == 'salsifysync_data' ? serialize($product_data['fields']) : ''),
      'created' => time(),
      'changed' => time(),
    ]);

  }

  /**
   * Utility function to remove a field mapping.
   *
   * @param string $salsify_system_id
   *   The ID of the field from Salsify.
   * @param \Drupal\field\Entity\FieldConfig $field
   *   The field configuration object from the content type.
   */
  protected function deleteDynamicField($salsify_system_id, FieldConfig $field) {
    // Delete the field from Drupal since it is no longer in use by Salisfy.
    $field->delete();
  }

  /**
   * Helper function that returns Drupal field options based on a Salsify type.
   *
   * @param string $content_type
   *   The content type to set the field against.
   * @param string $field_name
   *   The machine name for the Drupal field.
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
