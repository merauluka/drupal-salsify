<?php

namespace Drupal\salsify_integration;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class SalsifyImport.
 *
 * The main class used to perform content imports. Imports are trigger either
 * through queues during a cron run or via the configuration page.
 *
 * @package Drupal\salsify_integration
 */
class SalsifyImport {

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
   * Constructs a \Drupal\salsify_integration\Salsify object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory interface.
   * @param \Drupal\Core\Entity\Query\QueryFactory $entity_query
   *   The query factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_salsify
   *   The cache object associated with the Salsify bin.
   */
  public function __construct(ConfigFactoryInterface $config_factory, QueryFactory $entity_query, EntityTypeManagerInterface $entity_type_manager, CacheBackendInterface $cache_salsify) {
    $this->cache = $cache_salsify;
    $this->configFactory = $config_factory;
    $this->config = $this->configFactory->get('salsify_integration.settings');
    $this->entityQuery = $entity_query;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Creates a new SalsifyImport object.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container object to use when gathering dependencies.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity.query'),
      $container->get('entity_type.manager'),
      $container->get('cache.default')
    );
  }

  /**
   * A function to import Salsify data as entities in Drupal.
   *
   * @param array $product_data
   *   The Salsify individual product data to process.
   * @param bool $force_update
   *   If set to TRUE, the updated date highwater mark will be ignored.
   */
  public function processSalsifyItem(array $product_data, $force_update = FALSE) {}

  /**
   * Helper function to return a properly formatting set of field options.
   *
   * @param array $field
   *   The field mapping array.
   * @param array|string $field_data
   *   The Salsify field data from the queue.
   *
   * @return array|string
   *   The options array or string values.
   */
  protected function getFieldOptions(array $field, $field_data) {
    $options = $field_data;
    switch ($field['salsify_data_type']) {
      case 'link':
        $options = [
          'uri' => $field_data,
          'title' => '',
          'options' => [],
        ];
        break;

      case 'date':
        $options = [
          'value' => $field_data,
        ];
        break;

      case 'enumerated':
        if (!is_array($field_data)) {
          $options = [$field_data];
        }
        break;

      case 'rich_text':
        $options = [
          'value' => $field_data,
          'format' => 'full_html',
        ];
        break;
    }
    return $options;
  }

}
