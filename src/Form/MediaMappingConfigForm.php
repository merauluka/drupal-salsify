<?php

namespace Drupal\salsify_integration\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\salsify_integration\Salsify;

/**
 * Distribution Configuration form class.
 */
class MediaMappingConfigForm extends MappingConfigForm {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'salsify_integration_media_mapping_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $entity_type = 'media';
    $request = $this->getRequest();
    $bundle = $request->attributes->get('media_type');
    $form_state->setTemporaryValue('salsify_entity_type', $entity_type);
    $form_state->setTemporaryValue('salsify_bundle', $bundle);
    $cache_keys = [
      'salsify_config',
    ];

    if (isset($bundle)) {
      // Load manual field mappings keyed by Salsify ID.
      $salsify_field_mapping = Salsify::getFieldMappings(
        [
          'entity_type' => $entity_type,
          'bundle' => $bundle,
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

      // Gather all of the configured fields on the requested entity.
      $filtered_fields = Salsify::getContentTypeFields($bundle, $entity_type);

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
      $salsify_field_map = $salsify_data['entity_field_mapping'];
      $salsify_fields = [];

      // First allow the user to map any fields that aren't system values
      // in Salsify.
      if (isset($salsify_field_map['digital_assets'])) {
        // Filter out all fields that aren't set against media assets.
        $media_fields = $salsify_field_map['digital_assets'];
        $salsify_fields = $salsify_data['fields'];
        foreach ($salsify_fields as $key => $salsify_field) {
          if (!in_array($salsify_field['salsify:system_id'], $media_fields)) {
            unset($salsify_fields[$key]);
          }
        }
      }

      // Augment the custom fields with system data.
      $salsify_fields['salsify:url'] = [
        'salsify:system_id' => 'salsify_media_asset_id',
        'salsify:id' => 'salsify_media_asset_id',
        'salsify:name' => $this->t('Salsify File')->render(),
        'salsify:data_type' => 'digital_asset',
        'salsify:created_at' => date('Y-m-d', time()),
        'date_updated' => time(),
      ];

      $form_state->setTemporaryValue('salsify_field_data', $salsify_fields);

      $field_types = $this->getFieldsByType($filtered_fields);
      $incompatible = [];

      foreach ($salsify_fields as $key => $salsify_field) {
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
            '#markup' => $this->t('No fields on the media type are compatible with this field.'),
          ];
          $incompatible[$key] = $salsify_field;
        }
      }

      $form['subheader'] = [
        '#type' => '#markup',
        '#markup' => $this->t('Of the @total fields from Salsify, @no-match do not have compatible fields available on the media type.', ['@no-match' => count($incompatible), '@total' => count($salsify_fields)]),
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
