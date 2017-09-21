<?php

namespace Drupal\salsify_integration\Form;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\salsify_integration\Salsify;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Distribution Configuration form class.
 */
class MappingConfigForm extends ConfigFormBase {

  /**
   * The container object.
   *
   * @var \Symfony\Component\DependencyInjection\ContainerInterface
   */
  protected $container;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The cache object associated with the specified bin.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The array of formatted Salsify data.
   *
   * @var array
   */
  protected $salsifyData;

  /**
   * Constructs a \Drupal\system\ConfigFormBase object.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container object.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_salsify
   *   The cache object associated with the salsify bin.
   */
  public function __construct(ContainerInterface $container, ConfigFactoryInterface $config_factory, EntityFieldManagerInterface $entity_field_manager, CacheBackendInterface $cache_salsify) {
    parent::__construct($config_factory);
    $this->container = $container;
    $this->entityFieldManager = $entity_field_manager;
    $this->cache = $cache_salsify;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container,
      $container->get('config.factory'),
      $container->get('entity_field.manager'),
      $container->get('cache.default')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'salsify_integration_mapping_base_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    return parent::buildForm($form, $form_state);
  }

  /**
   * Utility function to filter nested array values into a single value.
   *
   * @param array|string $entry
   *   The array with the 'value' element to filter.
   *
   * @return bool
   *   Whether or not the 'value' element is set on the array.
   */
  protected function filterNestedArray($entry) {
    if (is_array($entry)) {
      return (bool) $entry['value'];
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $field_values = $form_state->getValue('salsify_field_mapping');
    $field_mapping = array_filter($field_values, 'self::filterNestedArray');
    foreach ($field_mapping as $key => $field_map) {
      $field_mapping[$key] = $field_map['value'];
    }
    $count_values = array_count_values($field_mapping);
    foreach ($field_mapping as $key => $field_map) {
      if (isset($count_values[$field_map]) && $count_values[$field_map] > 1) {
        $form_state->setErrorByName('salsify_field_mapping][' . $key . '][value', $this->t('Each Drupal field can only be assigned to only one Salsify field.'));
      }
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $entity_type = $form_state->getValue('salsify_entity_type');
    $entity_bundle = $form_state->getValue('salsify_entity_bundle');

    // Setup the Salsify field data that was passed from the mapping form.
    $salsify_fields = $this->salsifyData['fields'];

    // Get the Salsify field mappings that already exist in the system.
    $drupal_field_mapping = Salsify::getFieldMappings(
      [
        'entity_type' => $entity_type,
        'entity_bundle' => $entity_bundle,
        'method' => 'manual',
      ]
    );
    $salsify_field_id_mapping = Salsify::getFieldMappings(
      [
        'entity_type' => $entity_type,
        'entity_bundle' => $entity_bundle,
        'method' => 'manual',
      ],
      'field_id'
    );

    // Get the submitted values and filter the results to those that have data.
    $field_values = $form_state->getValue('salsify_field_mapping');
    $field_mapping = array_filter($field_values, 'self::filterNestedArray');

    foreach ($field_mapping as $salsify_id => $field_map) {
      $salsify_field = $salsify_fields[$salsify_id];
      $values = [
        'field_id' => $salsify_field['salsify:system_id'],
        'salsify_id' => $salsify_id,
        'salsify_data_type' => $salsify_field['salsify:data_type'],
        'entity_type' => $entity_type,
        'entity_bundle' => $entity_bundle,
        'field_name' => $field_map['value'],
        'data' => serialize($salsify_field),
        'method' => 'manual',
        'created' => strtotime($salsify_field['salsify:created_at']),
        'changed' => $salsify_field['date_updated'],
      ];
      // If the Salsify field id is already in the system, check what field it's
      // stored against and clean up the mapping.
      if (isset($salsify_field_id_mapping[$salsify_field['salsify:system_id']])) {
        $salsify_id_map = $salsify_field_id_mapping[$salsify_field['salsify:system_id']];
        // The Salsify field id is associated with a different field. Remove
        // the field if it's dynamic. Remove the field mapping if not in order
        // to preserve user-created fields.
        if ($salsify_id_map['field_name'] != $field_map['value']) {
          if ($salsify_id_map['method'] == 'dynamic') {
            $field = FieldConfig::loadByName($entity_type, $entity_bundle, $field_map['value']);
            $field->delete();
          }
          else {
            Salsify::deleteFieldMapping([
              'entity_type' => $entity_type,
              'entity_bundle' => $entity_bundle,
              'method' => 'manual',
              'field_name' => $salsify_id_map['field_name'],
            ]);
          }
        }
      }
      // If the Drupal field is already in the system, check which Salsify field
      // it's associated with and clean up the mapping.
      if (isset($drupal_field_mapping[$field_map['value']])) {
        $drupal_field_map = $drupal_field_mapping[$field_map['value']];
        // The Drupal field name is associated with a different Salsify field.
        // Remove the field if it's dynamic. Remove the field mapping if not in
        // order to preserve user-created fields.
        if ($drupal_field_map['field_id'] != $salsify_field['salsify:system_id']) {
          if ($drupal_field_map['method'] == 'dynamic') {
            $field = FieldConfig::loadByName($entity_type, $entity_bundle, $field_map['value']);
            $field->delete();
          }
          else {
            Salsify::deleteFieldMapping([
              'entity_type' => $entity_type,
              'entity_bundle' => $entity_bundle,
              'method' => 'manual',
              'field_name' => $field_map['value'],
            ]);
            Salsify::deleteFieldMapping([
              'entity_type' => $entity_type,
              'entity_bundle' => $entity_bundle,
              'method' => 'dynamic',
              'field_name' => $field_map['value'],
            ]);
          }
          // Remove the mapping to provide a clean array for further processing.
          unset($drupal_field_mapping[$field_map['value']]);
        }
      }

      // Now that the fields are cleaned up and ready to go, create or update
      // field mappings as required.
      if (isset($salsify_field_id_mapping[$salsify_field['salsify:system_id']])) {
        // The mapping already exists, update it.
        $mapping = $salsify_field_id_mapping[$salsify_field['salsify:system_id']];
        $mapping = array_merge($mapping, $values);
        Salsify::updateFieldMapping($mapping);

        // Remove the mapping to provide a clean array for further processing.
        unset($drupal_field_mapping[$field_map['value']]);
      }
      else {
        Salsify::createFieldMapping($values);
      }

    }

    // Perform final clean up on any manual fields that have changed from
    // manual back to dynamic values by removing the field mapping. The dynamic
    // fields will be added back into the system on the next import.
    foreach ($drupal_field_mapping as $key => $field_name_map) {
      if ($field_name_map['method'] == 'manual') {
        Salsify::deleteFieldMapping([
          'entity_type' => $field_name_map['entity_type'],
          'entity_bundle' => $field_name_map['entity_bundle'],
          'method' => 'manual',
          'field_name' => $field_name_map['field_name'],
        ]);
      }
    }

    parent::submitForm($form, $form_state);

  }

  /**
   * Return the configuration names.
   */
  protected function getEditableConfigNames() {
    return [
      'salsify_integration.settings',
    ];
  }

  /**
   * Utility function to map Drupal field types to Salsify fields.
   *
   * @param array $fields
   *   The full array of FieldConfig items from the content type.
   *
   * @return array
   *   An array of values mapping the drupal field type to Salsify fields.
   */
  protected function getFieldsByType(array $fields) {
    $config = $this->config('salsify_integration.settings');
    $fields_by_type = [];

    /* @var \Drupal\field\Entity\FieldConfig $field */
    foreach ($fields as $field) {
      if (strpos($field->getName(), 'salsify') === FALSE) {
        // Map the Salsify data types to Drupal field types.
        $field_options = [
          'title' => $field->getLabel() . ' (' . $field->getName() . ')',
          'field_name' => $field->getName(),
        ];

        switch ($field->getType()) {
          case 'boolean':
            $fields_by_type['boolean'][$field_options['field_name']] = $field_options['title'];
            break;

          case 'datetime':
            $fields_by_type['date'][$field_options['field_name']] = $field_options['title'];
            break;

          case 'decimal':
            $fields_by_type['number'][$field_options['field_name']] = $field_options['title'];
            $fields_by_type['string'][$field_options['field_name']] = $field_options['title'];
            break;

          case 'entity_reference':
            if ($config->get('entity_reference_allow')) {
              $handler = $field->getSetting('handler');
              if ($handler == 'default:taxonomy_term') {
                $fields_by_type['enumerated'][$field_options['field_name']] = $field_options['title'];
                $fields_by_type['string'][$field_options['field_name']] = $field_options['title'];
              }
            }
            if ($config->get('process_media_assets')) {
              $handler = $field->getSetting('handler');
              if ($handler == 'default:media') {
                $fields_by_type['digital_asset'][$field_options['field_name']] = $field_options['title'];
              }
            }
            break;

          case 'file':
            $fields_by_type['digital_asset'][$field_options['field_name']] = $field_options['title'];
            break;

          case 'image':
            $fields_by_type['digital_asset'][$field_options['field_name']] = $field_options['title'];
            break;

          case 'link':
            $fields_by_type['link'][$field_options['field_name']] = $field_options['title'];
            $fields_by_type['string'][$field_options['field_name']] = $field_options['title'];
            break;

          case 'list_string':
            $fields_by_type['enumerated'][$field_options['field_name']] = $field_options['title'];
            $fields_by_type['string'][$field_options['field_name']] = $field_options['title'];
            break;

          case 'integer':
            $fields_by_type['number'][$field_options['field_name']] = $field_options['title'];
            $fields_by_type['string'][$field_options['field_name']] = $field_options['title'];
            break;

          case 'string_long':
            $fields_by_type['enumerated'][$field_options['field_name']] = $field_options['title'];
            $fields_by_type['rich_text'][$field_options['field_name']] = $field_options['title'];
            $fields_by_type['html'][$field_options['field_name']] = $field_options['title'];
            $fields_by_type['string'][$field_options['field_name']] = $field_options['title'];
            break;

          case 'text_long':
            $fields_by_type['enumerated'][$field_options['field_name']] = $field_options['title'];
            $fields_by_type['rich_text'][$field_options['field_name']] = $field_options['title'];
            $fields_by_type['html'][$field_options['field_name']] = $field_options['title'];
            $fields_by_type['string'][$field_options['field_name']] = $field_options['title'];
            break;

          default:
            $fields_by_type['enumerated'][$field_options['field_name']] = $field_options['title'];
            $fields_by_type['number'][$field_options['field_name']] = $field_options['title'];
            $fields_by_type['string'][$field_options['field_name']] = $field_options['title'];
            break;

        }
      }
    }

    return $fields_by_type;
  }

  /**
   * Utility function to load or refresh the array of Salsify data.
   */
  protected function loadSalsifyData() {
    $cache_entry = $this->cache->get('salsify_field_data');
    $cache_keys = [
      'salsify_config',
    ];
    if ($cache_entry) {
      $this->salsifyData = $cache_entry->data;
    }
    else {
      $salsify = Salsify::create($this->container);
      $this->salsifyData = $salsify->getProductData();
      $cache_expiry = time() + 15 * 60 * 60;
      $this->cacheItem('salsify_field_data', $this->salsifyData, $cache_expiry, $cache_keys);
    }
  }

  /**
   * Utility function to cache a value for the configuration form.
   *
   * @param string $key
   *   The name of the cache key to set.
   * @param array|string $data
   *   The data to set in the cache. Non-strings are automatically serialized.
   * @param int $expiry
   *   The expiration timestamp. Defaults to never expire.
   * @param array $tags
   *   Any custom tags that should trigger a cache refresh for the values.
   */
  protected function cacheItem($key, $data, $expiry = Cache::PERMANENT, array $tags = []) {
    // Only cache the data if there is data to cache to prevent caching empty
    // result sets before data is ready.
    if ($data) {
      $this->cache->set($key, $data, $expiry, $tags);
    }
  }

}
