<?php

namespace Drupal\salsify_integration\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\salsify_integration\Salsify;

/**
 * Distribution Configuration form class.
 */
class NodeMappingConfigForm extends MappingConfigForm {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'salsify_integration_mapping_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $config = $this->config('salsify_integration.settings');
    $content_type = $config->get('content_type');
    $form_state->setTemporaryValue('salsify_entity_type', 'node');
    $form_state->setTemporaryValue('salsify_bundle', $content_type);
    $cache_keys = [
      'salsify_config',
    ];

    if (isset($content_type)) {
      // Load manual field mappings keyed by Salsify ID.
      $salsify_field_mapping = Salsify::getFieldMappings(
        [
          'entity_type' => 'node',
          'bundle' => $content_type,
          'method' => 'manual',
        ],
        'salsify_id'
      );

      $form['header'] = [
        '#type' => '#markup',
        '#markup' => $this->t('Select a field to match with incoming data from Salsify. Eligible fields are ones created by users and not fields that will be managed by Salsify.'),
        '#weight' => 0,
      ];

      $form['salsify_field_mapping'] = [
        '#type' => 'table',
        '#header' => array($this->t('Salsify Field'), $this->t('Drupal Field')),
        '#empty' => $this->t('No fields on the selected content type are compatible with this integration.'),
        '#tableselect' => FALSE,
        '#weight' => 50,
      ];

      // Gather all of the configured fields on the configured content type.
      $filtered_fields = Salsify::getContentTypeFields($content_type);

      $cache_entry = $this->cache->get('salsify_field_data');
      if ($cache_entry) {
        $salsify_data = $cache_entry->data;
      }
      else {
        $salsify = Salsify::create($this->container);
        $salsify_data = $salsify->getProductData();
        $cache_expiry = time() + 15 * 60 * 60;
        $this->cacheItem('salsify_field_data', $salsify_data, $cache_expiry, $cache_keys);
      }
      $salsify_fields = $salsify_data['fields'];
      $form_state->setTemporaryValue('salsify_field_data', $salsify_fields);

      $field_types = $this->getFieldsByType($filtered_fields);
      $incompatible = [];

      foreach ($salsify_fields as $key => $salsify_field) {
        if (isset($salsify_field['salsify:entity_types']) && in_array('products', $salsify_field['salsify:entity_types'])) {
          $form['salsify_field_mapping'][$key]['label'] = [
            '#type' => 'markup',
            '#markup' => '<strong>' . $salsify_field['salsify:name'] . '</strong> (' . $this->t('data_type:') . ' ' . $salsify_field['salsify:data_type'] . ')',
          ];
          if (isset($field_types[$salsify_field['salsify:data_type']])) {
            $types = $field_types[$salsify_field['salsify:data_type']];
            $options = array_merge(['' => $this->t('- None -')], $types);
            $form['salsify_field_mapping'][$key]['value'] = [
              '#type' => 'select',
              '#options' => $options,
              '#default_value' => isset($salsify_field_mapping[$key]) ? $salsify_field_mapping[$key]['field_name'] : '',
            ];
          }
          else {
            $form['salsify_field_mapping'][$key]['value'] = [
              '#type' => 'markup',
              '#markup' => $this->t('No fields on the content type are compatible with this field.'),
            ];
            $incompatible[$key] = $salsify_field;
          }
        }
      }
      $form['subheader'] = [
        '#type' => '#markup',
        '#markup' => $this->t('Of the @total fields from Salsify, @no-match do not have compatible fields available on the content type.', ['@no-match' => count($incompatible), '@total' => count($form['salsify_field_mapping'])]),
        '#weight' => 0,
        '#prefix' => '<p>',
        '#suffix' => '</p>',
      ];

    }
    else {
      $form['salsify_mapping_message'] = [
        '#type' => 'markup',
        '#markup' => $this->t('The Salsify module is not yet set up. Please choose a content type to sync with Salsify from the configuration form.'),
      ];
    }

    return $form;
  }

}
