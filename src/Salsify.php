<?php

namespace Drupal\salsify_integration;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\field\Entity\FieldConfig;
use GuzzleHttp\Client;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class Salsify.
 *
 * @package Drupal\salsify_integration
 */
class Salsify {

  /**
   * The cache object associated with the specified bin.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

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
   * The Entity Field Manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The logger interface.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a \Drupal\salsify_integration\Salsify object.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger interface.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory interface.
   * @param \Drupal\Core\Entity\Query\QueryFactory $entity_query
   *   The query factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_salsify
   *   The cache object associated with the Salsify bin.
   */
  public function __construct(LoggerInterface $logger, ConfigFactoryInterface $config_factory, QueryFactory $entity_query, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, CacheBackendInterface $cache_salsify) {
    $this->logger = $logger;
    $this->cache = $cache_salsify;
    $this->configFactory = $config_factory;
    $this->config = $this->configFactory->get('salsify_integration.settings');
    $this->entityQuery = $entity_query;
    $this->entityTypeManager = $entity_type_manager;
    /* TODO: This can likely be removed now that fields are loaded statically.*/
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('logger.factory')->get('salsify_integration'),
      $container->get('config.factory'),
      $container->get('entity.query'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('cache.default')
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
  protected function getRawData() {
    $client = new Client();
    $endpoint = $this->getUrl();
    $access_token = $this->getAccessToken();
    try {
      // Access the channel URL to fetch the newest product feed URL.
      $generate_product_feed = $client->get($endpoint, [
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
        ],
      ]);
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
      throw new MissingDataException(__CLASS__ . ': Could not make GET request to ' . $endpoint . ' because of error "' . $e->getMessage() . '".');
    }
  }

  /**
   * Utility function to load and process product data from Salsify.
   *
   * @return array
   *   An array of product data.
   */
  public function getProductData() {
    try {
      $raw_data = $this->getRawData();
      $product_data = [];
      $field_data = &$product_data['fields'];

      if (isset($raw_data['digital_assets'])) {
        // Rekey the Digital Assets by their Salsify ID to make looking them
        // up in later calls easier.
        $raw_data['digital_assets'] = $this->rekeyArray($raw_data['digital_assets'], 'salsify:id');
      }

      // Organize the fields and options (for enumerated fields) by salsify:id.
      foreach ($raw_data['attributes'] as $attribute) {
        $field_data[$attribute['salsify:id']] = $attribute;
        $field_data[$attribute['salsify:id']]['date_updated'] = strtotime($attribute['salsify:updated_at']);
        foreach ($field_data[$attribute['salsify:id']]['salsify:entity_types'] as $entity_types) {
          $product_data['entity_field_mapping'][$entity_types][] = $attribute['salsify:system_id'];
        }
      }
      foreach ($raw_data['attribute_values'] as $value) {
        $field_data[$value['salsify:attribute_id']]['values'][$value['salsify:id']] = $value;
        $date_updated = strtotime($value['salsify:updated_at']);
        if ($date_updated > $field_data[$value['salsify:attribute_id']]['date_updated']) {
          $field_data[$value['salsify:attribute_id']]['date_updated'] = $date_updated;
        }
      }

      // Add in the Salisfy id from the imported content as a special field.
      // This will allow for tracking data that has already been imported into
      // the system without making the user manage the ID field.
      $field_data['salsify:id'] = [
        'salsify:id' => 'salsify:id',
        'salsify:system_id' => 'salsify:system_id',
        'salsify:name' => t('Salisfy Sync ID'),
        'salsify:data_type' => 'string',
        'salsify:created_at' => date('Y-m-d', time()),
        'date_updated' => time(),
      ];

      $new_product_data = $product_data + $raw_data;

      // Allow users to alter the product data from Salsify by invoking
      // hook_salsify_product_data_alter().
      \Drupal::moduleHandler()->alter('salsify_product_data', $new_product_data);

      // Add the newly updated product data into the site cache.
      $this->cache->set('salsify_import_product_data', $new_product_data);

      return $new_product_data;

    }
    catch (MissingDataException $e) {
      throw new MissingDataException(__CLASS__ . ': Unable to load Salsify product data. ' . $e->getMessage());
    }
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
   * Utility function to load a content types configurable fields.
   *
   * @param string $content_type
   *   The content type to use for the Salsify integration.
   * @param string $entity_type
   *   The type of entity to use to lookup fields.
   *
   * @return array
   *   An array of field objects.
   */
  public static function getContentTypeFields($content_type, $entity_type = 'node') {
    $fields = \Drupal::service('entity_field.manager')->getFieldDefinitions($entity_type, $content_type);
    $filtered_fields = array_filter(
      $fields, function ($field_definition) {
        return $field_definition instanceof FieldConfig;
      }
    );
    return $filtered_fields;
  }

  /**
   * Utility function to return the list of Salsify field mappings.
   *
   * @param array $keys
   *   The keys in the mapping table to use for the returned associative array.
   * @param string $key_by
   *   The value to use when keying the associative array of results.
   *
   * @return mixed
   *   An array of configuration arrays.
   */
  public static function getFieldMappings(array $keys, $key_by = 'field_name') {
    if (isset($keys['method'])) {
      $methods = [
        $keys['method'],
      ];
    }
    else {
      $methods = [
        'manual',
        'dynamic',
      ];
    }
    $configs = [];
    foreach ($methods as $method) {
      $keys['method'] = $method;
      $config_prefix = self::getConfigName($keys);
      $configs += \Drupal::configFactory()->listAll($config_prefix);
    }
    $results = [];
    foreach ($configs as $config_name) {
      $config = \Drupal::config($config_name);
      $results[$config->get($key_by)] = $config->getRawData();
    }
    return $results;
  }

  /**
   * Utility function to create a new field mapping.
   *
   * @param array $values
   *   An array of field mapping values to insert into the database.
   */
  public static function createFieldMapping(array $values) {
    // Allow users to alter the field mapping data by invoking
    // hook_salsify_field_mapping_alter().
    \Drupal::moduleHandler()->alter('salsify_field_mapping_create', $values);

    if ($values) {
      self::setConfig($values);
    }
  }

  /**
   * Utility function to update a field mapping.
   *
   * @param array $values
   *   The values to update in the matched row.
   */
  public static function updateFieldMapping(array $values) {
    // Allow users to alter the field mapping data by invoking
    // hook_salsify_field_mapping_alter().
    \Drupal::moduleHandler()->alter('salsify_field_mapping_update', $values);

    if ($values) {
      self::setConfig($values);
    }
  }

  /**
   * Utility function to remove a field mapping.
   *
   * @param array $keys
   *   The array of column name => value settings to use when matching the row.
   */
  public static function deleteFieldMapping(array $keys) {
    self::deleteConfig($keys);
  }

  /**
   * Utility function to create a config name string.
   *
   * @param array $values
   *   The array of keys to use to create the config name.
   *
   * @return string
   *   The config name to lookup.
   */
  public static function getConfigName(array $values) {
    $field_name = '';
    if (isset($values['field_name'])) {
      $field_name = '.' . $values['field_name'];
    }
    return 'salsify_integration.' . $values['method'] . '.' . $values['entity_type'] . '.' . $values['bundle'] . $field_name;
  }

  /**
   * Utility function to write configuration values for field mappings.
   *
   * @param array $values
   *   The values to write into the configuration element.
   */
  public static function setConfig(array $values) {
    $config_name = self::getConfigName($values);
    /* @var \Drupal\Core\Config\Config $config */
    $config = \Drupal::service('config.factory')->getEditable($config_name);
    foreach ($values as $label => $value) {
      $config->set($label, $value);
    }
    $config->save();
  }

  /**
   * Utility function to delete configuration values for field mappings.
   *
   * @param array $values
   *   The values used to lookup the  configuration element.
   */
  public static function deleteConfig(array $values) {
    $config_name = self::getConfigName($values);
    /* @var \Drupal\Core\Config\Config $config */
    $config = \Drupal::service('config.factory')->getEditable($config_name);
    $config->delete();
  }

  /**
   * Utility function to update a dynamic field's settings.
   *
   * @param array $salsify_field
   *   The array of field data from Salsify.
   * @param \Drupal\field\Entity\FieldConfig $field
   *   The field configuration object from the content type.
   */
  protected function updateDynamicField(array $salsify_field, FieldConfig $field) {}

  /**
   * Utility function to remove a Drupal field.
   *
   * @param \Drupal\field\Entity\FieldConfig $field
   *   The field configuration object from the content type.
   */
  protected function deleteDynamicField(FieldConfig $field) {
    try {
      // Delete the field from Drupal since it is no longer in use by Salisfy.
      $field->delete();
    }
    catch (DatabaseExceptionWrapper $e) {
      $this->logger->notice('Could not delete field. Error: "%error".', ['%error' => $e->getMessage()]);
    }
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
  public static function createFieldViewDisplay($content_type, $field_name, $view_mode) {}

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
  public static function createFieldFormDisplay($content_type, $field_name, $salsify_type) {}

  /**
   * Utility function to set the allowed values list from Salsify for a field.
   *
   * @param array $salsify_data
   *   The field level data from Salsify augmented with allowed values.
   */
  protected function setFieldOptions(array $salsify_data) {
    $config = $this->configFactory->getEditable('salsify_integration.field_options');
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
    $config = $this->configFactory->getEditable('salsify_integration.field_options');
    if ($config->get($salsify_system_id)) {
      $config->clear($salsify_system_id);
      $config->save();
    }
  }

  /**
   * Utility function to rekey a nested array using one of its subvalues.
   *
   * @param array $array
   *   An array of arrays.
   * @param string $key
   *   The key in the subarray to use as the key on $array.
   *
   * @return array|bool
   *   The newly keyed array or FALSE if the key wasn't found.
   */
  public static function rekeyArray(array $array, $key) {
    $new_array = [];
    foreach ($array as $entry) {
      if (is_array($entry) && isset($entry[$key])) {
        $new_array[$entry[$key]] = $entry;
      }
      else {
        break;
      }
    }

    return (!empty($new_array) ? $new_array : FALSE);

  }

}
