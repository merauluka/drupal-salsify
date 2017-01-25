<?php

namespace Drupal\salsify_integration;

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
   */
  public function __construct(LoggerInterface $logger, ConfigFactoryInterface $config_factory, QueryFactory $entity_query, EntityTypeManagerInterface $entity_type_manager) {
    $this->logger = $logger;
    $this->configFactory = $config_factory;
    $this->config = $this->configFactory->get('salsify_integration.settings');
    $this->entityQuery = $entity_query;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('logger.factory')->get('salsify_integration'),
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
      throw new MissingDataException(__CLASS__ . ': Could not make GET request to ' . $endpoint . ' because of error "' . $e->getMessage() . '".');
    }
  }

  /**
   * Utility function to load and process product data from Salsify.
   *
   * @return array
   *   An array of product data.
   */
  protected function getProductData() {
    try {
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

      return $product_data + $raw_data;
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
   * Utility function to update a dynamic field's settings.
   *
   * @param array $salsify_field
   *   The array of field data from Salsify.
   * @param \Drupal\field\Entity\FieldConfig $field
   *   The field configuration object from the content type.
   */
  protected function updateDynamicField(array $salsify_field, FieldConfig $field) {}

  /**
   * Utility function to remove a field mapping.
   *
   * @param string $salsify_system_id
   *   The ID of the field from Salsify.
   * @param \Drupal\field\Entity\FieldConfig $field
   *   The field configuration object from the content type.
   */
  protected function deleteDynamicField($salsify_system_id, FieldConfig $field) {}

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

}
