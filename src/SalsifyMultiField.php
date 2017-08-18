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
class SalsifyMultiField extends Salsify {

  /**
   * The main Salsify product field import function.
   *
   * This function syncs field configuration data from Salsify and ensures that
   * Drupal is ready to receive the fields that were passed in the product
   * feed from Salsify.
   *
   * @return mixed
   *   Returns an array of product and field data or a failure message.
   */
  public function importProductFields() {
    try {
      $content_type = $this->getContentType();

      // Sync the fields in Drupal with the fields in the Salsify feed.
      // TODO: Put this logic into a queue since it can get resource intensive.
      if ($content_type) {
        // Load the product and field data from Salsify.
        $product_data = $this->getProductData();

        $field_mapping = $this->getFieldMappings(
          [
            'entity_type' => 'node',
            'bundle' => $content_type,
          ],
          'salsify_id'
        );

        // Remove the manually mapped Salsify Fields from the product data so
        // they aren't added back into the system.
        $manual_field_mapping = $this->getFieldMappings(
          [
            'entity_type' => 'node',
            'bundle' => $content_type,
            'method' => 'manual',
          ],
          'salsify_id'
        );
        $manual_field_names = array_keys($manual_field_mapping);
        $salsify_fields = array_diff_key($product_data['fields'], $manual_field_names);

        // Determine the dynamically mapped fields that are in the field mapping
        // that aren't in the list of fields from Salsify.
        $field_diff = array_diff_key($field_mapping, $salsify_fields);

        // Setup the list of Drupal fields and machine names.
        $filtered_fields = $this->getContentTypeFields($content_type);
        $field_machine_names = array_keys($filtered_fields);

        // Find all of the fields from Salsify that are already in the system.
        // Check if they need to be updated using the "updated_at" field.
        $salsify_intersect = array_intersect_key($salsify_fields, $field_mapping);
        foreach ($salsify_intersect as $key => $salsify_field) {
          $updated = $salsify_field['date_updated'];
          if ($updated <> $field_mapping[$key]['changed']) {
            $updated_mapping = $field_mapping[$key];
            $updated_mapping['changed'] = $updated;
            $this->updateFieldMapping($updated_mapping);
            $this->updateDynamicField($salsify_field, $filtered_fields[$field_mapping[$key]['field_name']]);
          }
        }

        // Create any fields that don't yet exist in the system.
        $salsify_diff = array_diff_key($salsify_fields, $field_mapping);
        foreach ($salsify_diff as $salsify_field) {
          $field_name = $this->createFieldMachineName($salsify_field['salsify:id'], $field_machine_names);

          // If the field exists on the system, but isn't in the map, just add
          // it to the map instead of trying to create a new field. This should
          // cover if fields were left over from an uninstall.
          if (isset($filtered_fields[$field_name])) {
            $this->createFieldMapping([
              'field_id' => $salsify_field['salsify:system_id'],
              'salsify_id' => $salsify_field['salsify:id'],
              'salsify_data_type' => $salsify_field['salsify:data_type'],
              'entity_type' => 'node',
              'bundle' => $content_type,
              'field_name' => $field_name,
              'data' => serialize($salsify_field),
              'method' => 'dynamic',
              'created' => strtotime($salsify_field['salsify:created_at']),
              'changed' => $salsify_field['date_updated'],
            ]);
          }
          // Add a record to track the Salsify field and the new Drupal field
          // map.
          else {
            $this->createDynamicField($salsify_field, $field_name);
          }

        }

        // Find any fields that are already in the system that weren't in the
        // Salsify feed. This means they were deleted from Salsify and need to
        // be deleted on the Drupal side.
        if ($filtered_fields) {
          $remove_fields = array_intersect_key($filtered_fields, $field_diff);
          foreach ($remove_fields as $key => $field) {
            if (strpos($key, 'salsify') == 0) {
              $field->delete();
            }
          }
          foreach ($field_diff as $salsify_field_id => $field) {
            $diff_field_name = $field_mapping[$salsify_field_id]['field_name'];
            if (isset($filtered_fields[$diff_field_name])) {
              $this->deleteDynamicField($filtered_fields[$diff_field_name]);
            }
            // Remove the options listing for this field.
            $this->removeFieldOptions($salsify_field_id);
            // Delete the field mapping from the database.
            $this->deleteFieldMapping(
              [
                'entity_type' => 'node',
                'bundle' => $content_type,
                'salsify_id' => $salsify_field_id,
              ]
            );
          }
        }

      }
      else {
        $message = t('Could not complete Salsify field data import. No content type configured.')->render();
        $this->logger->error($message);
        throw new MissingDataException($message);
      }

      return $product_data;
    }
    catch (MissingDataException $e) {
      $message = t('Could not complete Salsify field data import. A error occurred connecting with Salsify. @error', ['@error' => $e->getMessage()])->render();
      $this->logger->error($message);
      throw new MissingDataException($message);
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
      // Refresh the product field settings from Salsify.
      $product_data = $this->importProductFields();

      // Import the actual product data.
      if (!empty($product_data['products'])) {
        // Handle cases where the user wants to perform all of the data
        // processing immediately instead of waiting for the queue to finish.
        if ($process_immediately) {
          $salsify_import = SalsifyImportField::create(\Drupal::getContainer());
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
            ->get('salsify_integration_content_import');
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
   * Utility function that creates a Drupal-compatible field name.
   *
   * @param string $field_name
   *   Salsify string field name.
   * @param array $field_machine_names
   *   Array of Drupal configured fields to use to prevent duplication.
   *
   * @return string
   *   Drupal field machine name.
   */
  public static function createFieldMachineName($field_name, array &$field_machine_names) {
    // Differentiate between default and custom salsify fields.
    if (strpos($field_name, 'salsify:') !== FALSE) {
      $prefix = 'salsify_';
    }
    else {
      $prefix = 'salsifysync_';
    }

    // Clean the string to remove spaces.
    $field_name = str_replace('-', '_', $field_name);
    $field_name = preg_replace('/[^A-Za-z0-9\-]/', '', $field_name);
    $cleaned_string = strtolower($prefix . $field_name);
    $new_field_name = substr($cleaned_string, 0, 32);

    // Check for duplicate field names and append an integer value until a
    // unique field name is found.
    if (in_array($new_field_name, $field_machine_names)) {
      $count = 0;
      while (in_array($new_field_name, $field_machine_names)) {
        $length = 32 - strlen($count) - 1;
        $new_field_name = substr($new_field_name, 0, $length) . '_' . $count;
        $count++;
      }
    }
    // Add the new field name to the field array.
    $field_machine_names[] = $new_field_name;

    return $new_field_name;
  }

  /**
   * Utility function that creates a field on a node for Salsify data.
   *
   * NOTE: Normally the Entity Type and Content Type should be managed by
   * this module. These values currently exist to allow the creation of a field
   * against other entities when entity reference fields are utilized.
   *
   * @param array $salsify_data
   *   The Salsify entry for this field.
   * @param string $field_name
   *   The machine name for the Drupal field.
   * @param string $entity_type
   *   The entity type to create the field against. Defaults to node.
   * @param string $content_type
   *   The content type (bundle) to set the field against.
   */
  public function createDynamicField(array $salsify_data, $field_name, $entity_type = 'node', $content_type = '') {
    if (!$content_type) {
      $content_type = $this->getContentType();
    }
    $field_storage = FieldStorageConfig::loadByName($entity_type, $field_name);
    $field_settings = $this->getFieldSettingsByType($salsify_data, $content_type, $field_name, $entity_type);
    $field = FieldConfig::loadByName($entity_type, $content_type, $field_name);
    $created = strtotime($salsify_data['salsify:created_at']);
    $changed = $salsify_data['date_updated'];
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

      // Only add user-facing fields onto the form and view displays. Otherwise
      // allow the fields to remain hidden (which is default).
      if (strpos($field_name, 'salsifysync_') !== FALSE) {
        // Add the field to the default displays.
        /* @var \Drupal\Core\Entity\Display\EntityViewDisplayInterface $view_storage */
        $this->createFieldViewDisplay($content_type, $field_name, 'default');
        $this->createFieldViewDisplay($content_type, $field_name, 'teaser');
        $this->createFieldFormDisplay($content_type, $field_name, $salsify_data['salsify:data_type']);
      }
    }

    // Add a record to track the Salsify field and the new Drupal field map.
    $this->createFieldMapping([
      'field_id' => $salsify_data['salsify:system_id'],
      'salsify_id' => $salsify_data['salsify:id'],
      'salsify_data_type' => $salsify_data['salsify:data_type'],
      'entity_type' => $entity_type,
      'bundle' => $content_type,
      'field_name' => $field_name,
      'data' => serialize($salsify_data),
      'method' => 'dynamic',
      'created' => $created,
      'changed' => $changed,
    ]);

  }

  /**
   * Utility function to update a dynamic field's settings.
   *
   * @param array $salsify_field
   *   The array of field data from Salsify.
   * @param \Drupal\field\Entity\FieldConfig $field
   *   The field configuration object from the content type.
   */
  protected function updateDynamicField(array $salsify_field, FieldConfig $field) {
    // Update the label on the field to pull in any changes from Salsify.
    $field->set('label', $salsify_field['salsify:name']);
    $field->save();
    // Update the options list on enumerated fields.
    if ($salsify_field['salsify:data_type'] == 'enumerated') {
      $this->setFieldOptions($salsify_field);
    }
  }

  /**
   * Helper function that returns Drupal field options based on a Salsify type.
   *
   * @param array $salsify_data
   *   The Salsify entry for this field.
   * @param string $content_type
   *   The content type to set the field against.
   * @param string $field_name
   *   The machine name for the Drupal field.
   * @param string $entity_type
   *   The type of entity to use when pulling field definitions.
   *
   * @return array
   *   An array of field options for the generated field.
   */
  protected function getFieldSettingsByType(array $salsify_data, $content_type, $field_name, $entity_type) {
    $field_settings = [
      'field' => [
        'field_name' => $field_name,
        'entity_type' => $entity_type,
        'bundle' => $content_type,
        'label' => $salsify_data['salsify:name'],
      ],
      'field_storage' => [
        'id' => $entity_type . '.' . $field_name,
        'field_name' => $field_name,
        'entity_type' => $entity_type,
        'type' => 'string',
        'settings' => [],
        'module' => 'text',
        'locked' => FALSE,
        'cardinality' => -1,
        'translatable' => TRUE,
        'indexes' => [],
        'persist_with_no_fields' => FALSE,
        'custom_storage' => FALSE,
      ],
    ];

    // Map the Salsify data types to Drupal field types and set default options.
    switch ($salsify_data['salsify:data_type']) {
      case 'digital_asset':
        if ($salsify_data['salsify:attribute_group'] == 'Images') {
          $field_settings['field_storage']['type'] = 'image';
          $field_settings['field_storage']['type'] = 'image';
          $field_settings['field_storage']['cardinality'] = -1;
          $field_settings['field_storage']['settings']['allowed_values_function'] = 'salsify_integration_allowed_values_callback';
        }
        break;

      case 'enumerated':
        $field_settings['field_storage']['type'] = 'list_string';
        $field_settings['field_storage']['cardinality'] = -1;
        $field_settings['field_storage']['settings']['allowed_values_function'] = 'salsify_integration_allowed_values_callback';
        $this->setFieldOptions($salsify_data);
        break;

      case 'date':
        $field_settings['field_storage']['type'] = 'datetime';
        $field_settings['field_storage']['module'] = 'datetime';
        $field_settings['field_storage']['settings'] = [
          'datetime_type' => 'date',
        ];
        break;

      case 'boolean':
        $field_settings['field_storage']['type'] = 'boolean';
        $field_settings['field_storage']['module'] = 'core';
        $field_settings['field']['settings'] = [
          'on_label' => 'Yes',
          'off_label' => 'No',
        ];
        break;

      case 'rich_text':
        $field_settings['field_storage']['type'] = 'text_long';
        $field_settings['field_storage']['module'] = 'text';
        break;

      case 'html':
        $field_settings['field_storage']['type'] = 'string_long';
        $field_settings['field_storage']['module'] = 'core';
        break;

      case 'link':
        $field_settings['field_storage']['type'] = 'link';
        $field_settings['field_storage']['module'] = 'link';
        $field_settings['field']['settings'] = [
          'link_type' => 16,
          'title' => 0,
        ];
        break;

    }

    return $field_settings;
  }

  /**
   * Utility function to add a field onto a node's display.
   *
   * @param string $content_type
   *   The content type to set the field against.
   * @param string $field_name
   *   The machine name for the Drupal field.
   * @param string $view_mode
   *   The view mode on which to add the field.
   */
  public static function createFieldViewDisplay($content_type, $field_name, $view_mode) {
    /* @var \Drupal\Core\Entity\Display\EntityViewDisplayInterface $view_storage */
    $view_storage = \Drupal::entityTypeManager()
      ->getStorage('entity_view_display')
      ->load('node.' . $content_type . '.' . $view_mode);

    // If the node display doesn't exist, create it in order to set the field.
    if (empty($view_storage)) {
      $values = [
        'targetEntityType' => 'node',
        'bundle' => $content_type,
        'mode' => $view_mode,
        'status' => TRUE,
      ];
      $view_storage = \Drupal::entityTypeManager()
        ->getStorage('entity_view_display')
        ->create($values);
    }

    $view_storage->setComponent($field_name, [
      'label' => 'above',
      'weight' => 0,
    ])->save();
  }

  /**
   * Utility function to add a field onto a node's form display.
   *
   * @param string $content_type
   *   The content type to set the field against.
   * @param string $field_name
   *   The machine name for the Drupal field.
   * @param string $salsify_type
   *   The Salsify data type for this field.
   */
  public static function createFieldFormDisplay($content_type, $field_name, $salsify_type) {
    /* @var \Drupal\Core\Entity\Display\EntityViewDisplayInterface $form_storage */
    $form_storage = \Drupal::entityTypeManager()
      ->getStorage('entity_form_display')
      ->load('node.' . $content_type . '.default');
    $field_options = [
      'weight' => 0,
    ];

    // Set form widget for multi-value options to checkboxes.
    if ($salsify_type == 'enumerated') {
      $field_options['type'] = 'options_buttons';
    }

    $form_storage->setComponent($field_name, $field_options)->save();

  }

}
