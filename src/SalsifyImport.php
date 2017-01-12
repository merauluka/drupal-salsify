<?php

namespace Drupal\rinnai_salsify;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\Core\Entity\Query\QueryFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class SalsifyImport.
 *
 * The main class used to perform content imports. Imports are trigger either
 * through queues during a cron run or via the configuration page.
 *
 * @package Drupal\rinnai_salsify
 */
class SalsifyImport {

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
   * Constructs a \Drupal\rinnai_salsify\Salsify object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory interface.
   * @param \Drupal\Core\Entity\Query\QueryFactory $entity_query
   *   The query factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(ConfigFactoryInterface $config_factory, QueryFactory $entity_query, EntityTypeManagerInterface $entity_type_manager) {
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
      $container->get('config.factory'),
      $container->get('entity.query'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * A function to import Salsify data as nodes in Drupal.
   *
   * @param array $salsify_data
   *   The Salsify individual product data to process.
   */
  public function processSalsifyItem(array $salsify_data) {
    $content_type = $this->config->get('content_type');

    // Load field mappings keyed by Salsify ID.
    $salsify_field_mapping = Salsify::getFieldMappings('salsify_id');

    // Lookup any existing nodes in order to overwrite their contents.
    $results = $this->entityQuery->get('node')
      ->condition('salsify_salsifyid', $salsify_data['salsify:id'])
      ->execute();

    // Load the existing node or generate a new one.
    if ($results) {
      $nid = array_keys($results)[0];
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
    }
    else {
      $node = Node::create([
        'title' => $salsify_data['Model Number'],
        'type' => $content_type,
        'created' => strtotime($salsify_data['salsify:created_at']),
        'changed' => strtotime($salsify_data['salsify:updated_at']),
        'salsify_salsifyid' => $salsify_data['salsify:id'],
        'status' => 1,
      ]);
      $node->save();
    }

    // Set the field data against the Salsify node and save the results.
    foreach ($salsify_field_mapping as $field) {
      if (isset($salsify_data[$field->salsify_id])) {
        $options = $this->getFieldOptions((array) $field, $salsify_data[$field->salsify_id]);
        $node->set($field->field_name, $options);
      }
    }
    $node->save();
  }

  /**
   * Helper function to return a properly formatting set of field options.
   *
   * @param array $field
   *   The field mapping database object.
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
        $options = strtotime($field_data);
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
