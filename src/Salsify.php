<?php

namespace Drupal\rinnai_salsify;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use GuzzleHttp\Client;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class Salsify.
 *
 * @package Drupal\rinnai_salsify
 */
class Salsify {

  /**
   * The configFactory interface.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The Salsify config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * Entity query factory.
   *
   * @var \Drupal\Core\Entity\Query\QueryFactory
   */
  protected $entityQuery;

  /**
   * The Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger interface.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a \Drupal\rinnai_salsify\Salsify object.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger interface.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory interface.
   * @param \Drupal\Core\Entity\Query\QueryFactory $entity_query
   *   The query factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(LoggerInterface $logger, ConfigFactoryInterface $config_factory, QueryFactory $entity_query, EntityTypeManagerInterface $entity_type_manager) {
    $this->logger = $logger;
    $this->configFactory = $config_factory;
    $this->config = $this->configFactory->get('rinnai_salsify.settings');
    $this->entityQuery = $entity_query;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('logger.factory')->get('rinnai_salsify'),
      $container->get('config.factory'),
      $container->get('entity.query'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Get the URL to the Salsify product channel.
   *
   * @return string
   *   A fully-qualified URL string.
   */
  protected function getUrl() {
    return $this->config->get('product_feed_url');
  }

  /**
   * Get the Salsify user account access token to use with this integration.
   *
   * @return string
   *   The access token string.
   */
  protected function getAccessToken() {
    return $this->config->get('access_token');
  }

  /**
   * Utility function to load product data from Salsify for further processing.
   *
   * @return array
   *   An array of raw, unprocessed product data. Empty if an error was found.
   */
  protected function getRawProductData() {
    $client = new Client();
    $endpoint = $this->getUrl();
    $access_token = $this->getAccessToken();
    try {
      // Access the channel URL to fetch the newest product feed URL.
      $generate_product_feed = $client->get($endpoint . '?access_token=' . $access_token);
      $response = $generate_product_feed->getBody()->getContents();
      $response_array = Json::decode($response);
      // TODO: Should implement a check to verify that the channel has completed
      // exporting prior to attempting an import.
      $feed_url = $response_array['product_export_url'];
      // Load the feed URL and data in order to get product and field data.
      $product_feed = $client->get($feed_url);
      $product_results = Json::decode($product_feed->getBody()->getContents());
      // Remove the single-level nesting returned by Salsify to make it easier
      // to access the product data.
      $product_data = [];
      foreach ($product_results as $product_result) {
        $product_data = $product_data + $product_result;
      }
      return $product_data;
    }
    catch (RequestException $e) {
      $this->logger->notice('Could not make GET request to %endpoint because of error "%error".', ['%endpoint' => $endpoint, '%error' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Utility function to load and process product data from Salsify.
   *
   * @return array
   *   An array of product data.
   */
  protected function getProductData() {
    $raw_data = $this->getRawProductData();
    $product_data = [];
    $field_data = &$product_data['fields'];

    // Organize the fields and options (for enumerated fields) by salsify:id.
    foreach ($raw_data['attributes'] as $attribute) {
      $field_data[$attribute['salsify:id']] = $attribute;
      $field_data[$attribute['salsify:id']]['date_updated'] = strtotime($attribute['salsify:updated_at']);
    }
    foreach ($raw_data['attribute_values'] as $value) {
      $field_data[$value['salsify:attribute_id']]['values'][$value['salsify:id']] = $value;
      $date_updated = strtotime($value['salsify:updated_at']);
      if ($date_updated > $field_data[$value['salsify:attribute_id']]['date_updated']) {
        $field_data[$value['salsify:attribute_id']]['date_updated'] = $date_updated;
      }
    }

    // Add in the Salisfy id from the imported content as a special field. This
    // will allow for tracking data that has already been imported into the
    // system without making the user manage the ID field.
    $field_data['salsify:id'] = [
      'salsify:id' => 'salsify:id',
      'salsify:system_id' => 'salsify:system_id',
      'salsify:name' => t('Salisfy Sync ID'),
      'salsify:data_type' => 'string',
      'salsify:created_at' => date('Y-m-d', time()),
      'date_updated' => time(),
    ];

    return $product_data + $raw_data;
  }

  /**
   * Utility function to return the list of Salsify field mappings.
   *
   * @param string $key
   *   The key in the mapping table to use for the returned associative array.
   *
   * @return mixed
   *   An array of database row object data.
   */
  public static function getFieldMappings($key = 'field_name') {
    return \Drupal::database()->select('salsify_field_data', 'f')
      ->fields('f')
      ->execute()
      ->fetchAllAssoc($key);
  }

  /**
   * Utility function to create a new field mapping.
   *
   * @param array $values
   *   An array of field mapping values to insert into the database.
   */
  protected function createFieldMapping(array $values) {
    \Drupal::database()->insert('salsify_field_data')
      ->fields($values)
      ->execute();
  }

  /**
   * Utility function to create a new field mapping.
   *
   * @param string $key
   *   The column name to use when matching the row to update.
   * @param string $key_value
   *   The column value to use when matching the row to update.
   * @param array $values
   *   The values to update in the matched row.
   */
  protected function updateFieldMapping($key, $key_value, array $values) {
    \Drupal::database()->update('salsify_field_data')
      ->fields($values)
      ->condition($key, $key_value, '=')
      ->execute();
  }

  /**
   * Utility function to remove a field mapping.
   *
   * @param string $key
   *   The column name to use when matching the row to delete.
   * @param string $key_value
   *   The column value to use when matching the row to delete.
   */
  public function deleteFieldMapping($key, $key_value) {
    \Drupal::database()->delete('salsify_field_data')
      ->condition($key, $key_value, '=')
      ->execute();
  }

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
    // Load the product and field data from Salsify.
    $product_data = $this->getProductData();
    $salsify_fields = $product_data['fields'];
    $field_mapping = $this->getFieldMappings('salsify_id');
    $field_diff = array_diff_key($field_mapping, $salsify_fields);

    $content_type = $this->getContentType();
    // Sync the fields in Drupal with the fields in the Salsify feed.
    // TODO: Put this logic into a queue since it can get resource intensive.
    if ($content_type) {
      $fields = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', $content_type);
      $filtered_fields = array_filter(
        $fields, function ($field_definition) {
          return $field_definition instanceof FieldConfig;
        }
      );

      // Find all of the fields from Salsify that are already in the system.
      // Check if they need to be updated using the "updated_at" field.
      $salsify_intersect = array_intersect_key($salsify_fields, $field_mapping);
      foreach ($salsify_intersect as $key => $salsify_field) {
        $updated = $salsify_field['date_updated'];
        if ($updated <> $field_mapping[$key]->changed) {
          $this->updateFieldMapping('field_id', $salsify_field['salsify:system_id'], [
            'changed' => $updated,
          ]);
          $this->updateDynamicField($salsify_field, $filtered_fields[$field_mapping[$key]->field_name]);
        }
      }

      // Create any fields that don't yet exist in the system.
      $salsify_diff = array_diff_key($salsify_fields, $field_mapping);
      foreach ($salsify_diff as $salsify_field) {
        $field_name = $this->createFieldMachineName($salsify_field['salsify:id']);

        // If the field exists on the system, but isn't in the map, just add it
        // it to the map instead of trying to create a new field. This should
        // cover if fields were left over from an uninstall.
        if (isset($filtered_fields[$field_name])) {
          $this->createFieldMapping([
            'field_id' => $salsify_field['salsify:system_id'],
            'salsify_id' => $salsify_field['salsify:id'],
            'salsify_data_type' => $salsify_field['salsify:data_type'],
            'field_name' => $field_name,
            'created' => strtotime($salsify_field['salsify:created_at']),
            'changed' => $salsify_field['date_updated'],
          ]);
        }
        // Add a record to track the Salsify field and the new Drupal field map.
        else {
          $this->createDynamicField($salsify_field);
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
      return [$message];
    }

    return $product_data;

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
    // Refresh the product field settings from Salsify.
    $product_data = $this->importProductFields();

    // Import the actual product data.
    if (!empty($product_data['products'])) {
      // Handle cases where the user wants to perform all of the data processing
      // immediately instead of waiting for the queue to finish.
      if ($process_immediately) {
        $salsify_import = new SalsifyImport($this->configFactory, $this->entityQuery, $this->entityTypeManager);
        foreach ($product_data['products'] as $product) {
          $salsify_import->processSalsifyItem($product);
        }
      }
      // Add each product value into a queue for background processing.
      else {
        /** @var \Drupal\Core\Queue\QueueInterface $queue */
        $queue = \Drupal::service('queue')
          ->get('rinnai_salsify_content_import');
        foreach ($product_data['products'] as $product) {
          $queue->createItem($product);
        }
      }
    }
    else {
      $message = t('Could not complete Salsify data import. No product data is available')->render();
      $this->logger->error($message);
    }

  }

  /**
   * Utility function that creates a Drupal-compatible field name.
   *
   * @param string $field_name
   *   Salsify string field name.
   *
   * @return string
   *   Drupal field machine name.
   */
  protected function createFieldMachineName($field_name) {
    // Differentiate between default and custom salsify fields.
    if (strpos($field_name, 'salsify:') !== FALSE) {
      $prefix = 'salsify_';
    }
    else {
      $prefix = 'salsifysync_';
    }

    // Clean the string to remove spaces.
    $field_name = \Drupal::service('pathauto.alias_cleaner')->cleanString($field_name);
    $field_name = str_replace('-', '_', $field_name);
    $cleaned_string = $prefix . str_replace(' ', '_', $field_name);
    $cleaned_string = strtolower($cleaned_string);

    return substr($cleaned_string, 0, 32);
  }

  /**
   * Utility function that retrieves the configured content type value.
   *
   * @return string
   *   The content type to use for Salsify data.
   */
  protected function getContentType() {
    return $this->config->get('content_type');
  }

  /**
   * Utility function that creates a field on a node for Salsify data.
   *
   * @param array $salsify_data
   *   The Salsify entry for this field.
   */
  protected function createDynamicField(array $salsify_data) {
    $content_type = $this->getContentType();
    $field_name = $this->createFieldMachineName($salsify_data['salsify:id']);
    $field_storage = FieldStorageConfig::loadByName('node', $field_name);
    $field_settings = $this->getFieldSettingsByType($salsify_data, $content_type, $field_name);
    $field = FieldConfig::loadByName('node', $content_type, $field_name);
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
      'field_name' => $field_name,
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
    // Remove the options listing for this field.
    $this->removeFieldOptions($salsify_system_id);
    // Delete the field mapping from the database.
    $this->deleteFieldMapping('field_id', $salsify_system_id);
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
   *
   * @return array
   *   An array of field options for the generated field.
   */
  protected function getFieldSettingsByType(array $salsify_data, $content_type, $field_name) {
    $field_settings = [
      'field' => [
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => $content_type,
        'label' => $salsify_data['salsify:name'],
      ],
      'field_storage' => [
        'id' => 'node.' . $field_name,
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => 'string',
        'settings' => [],
        'module' => 'text',
        'locked' => FALSE,
        'cardinality' => 1,
        'translatable' => TRUE,
        'indexes' => [],
        'persist_with_no_fields' => FALSE,
        'custom_storage' => FALSE,
      ],
    ];

    // Map the Salsify data types to Drupal field types and set default options.
    switch ($salsify_data['salsify:data_type']) {
      case 'enumerated':
        $field_settings['field_storage']['type'] = 'list_string';
        $field_settings['field_storage']['cardinality'] = -1;
        $field_settings['field_storage']['settings']['allowed_values_function'] = 'rinnai_salsify_allowed_values_callback';
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

  /**
   * Utility function to set the allowed values list from Salsify for a field.
   *
   * @param array $salsify_data
   *   The field level data from Salsify augmented with allowed values.
   */
  protected function setFieldOptions(array $salsify_data) {
    $config = $this->configFactory->getEditable('rinnai_salsify.field_options');
    $options = [];
    if (isset($salsify_data['values'])) {
      foreach ($salsify_data['values'] as $value) {
        $options[$value['salsify:id']] = $value['salsify:name'];
      }
      $config->set($salsify_data['salsify:system_id'], $options);
      $config->save();
    }
  }

  /**
   * Utility function to set the allowed values list from Salsify for a field.
   *
   * @param string $salsify_system_id
   *   The Salsify system id to remove from the options configuration.
   */
  public function removeFieldOptions($salsify_system_id) {
    $config = $this->configFactory->getEditable('rinnai_salsify.field_options');
    if ($config->get($salsify_system_id)) {
      $config->clear($salsify_system_id);
      $config->save();
    }
  }

}
