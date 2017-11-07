<?php

namespace Drupal\salsify_integration\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\salsify_integration\Salsify;

/**
 * Commerce Product Variation Mapping Configuration form class.
 */
class CommerceProductVariationMappingConfigForm extends MappingConfigForm {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'salsify_integration_commerce_variation_mapping_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $request = $this->getRequest();
    $entity_type = 'commerce_product_variation';
    $entity_bundle = 'default';
    $form['salsify_entity_type'] = [
      '#type' => 'value',
      '#value' => $entity_type,
    ];
    $form['salsify_entity_bundle'] = [
      '#type' => 'value',
      '#value' => $entity_bundle,
    ];
    if (isset($entity_bundle)) {
      // Load manual field mappings keyed by Salsify ID.
      $salsify_field_mapping = Salsify::getFieldMappings(
        [
          'entity_type' => $entity_type,
          'bundle' => $entity_bundle,
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
        '#empty' => $this->t('No fields on the selected entity type are compatible with this integration.'),
        '#tableselect' => FALSE,
        '#weight' => 50,
      ];

      // Gather all of the configured fields on the requested entity.
      $filtered_fields = Salsify::getEntityTypeFields($entity_type, $entity_bundle);

      // Load the Salsify data array.
      $this->loadSalsifyData();
      $salsify_fields = $this->salsifyData['fields'];

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
            '#markup' => $this->t('No fields on the product variation are compatible with this field.'),
          ];
          $incompatible[$key] = $salsify_field;
        }
      }

      $form['subheader'] = [
        '#type' => '#markup',
        '#markup' => $this->t('Of the @total fields from Salsify, @no-match do not have compatible fields available on the product variation.', ['@no-match' => count($incompatible), '@total' => count($salsify_fields)]),
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

  /**
   * Utility function to load or refresh the array of Salsify data.
   */
  protected function loadSalsifyData() {
    parent::loadSalsifyData();
    $salsify_field_map = $this->salsifyData['entity_field_mapping'];
    $salsify_fields = [];

    // Allow the user to map any fields that aren't system values in Salsify.
    // if (isset($salsify_field_map['digital_assets'])) {
    //   // Filter out all fields that aren't set against media assets.
    //   $media_fields = $salsify_field_map['digital_assets'];
    //   $salsify_fields = $this->salsifyData['fields'];
    //   foreach ($salsify_fields as $key => $salsify_field) {
    //     if (!in_array($salsify_field['salsify:system_id'], $media_fields)) {
    //       unset($salsify_fields[$key]);
    //     }
    //   }
    // }

    // Augment the custom fields with system data.
    $salsify_fields['salsify:url'] = [
      'salsify:system_id' => 'salsify_media_asset_id',
      'salsify:id' => 'salsify_media_asset_id',
      'salsify:name' => $this->t('Salsify File')->render(),
      'salsify:data_type' => 'digital_asset',
      'salsify:created_at' => date('Y-m-d', time()),
      'date_updated' => time(),
    ];

    $this->salsifyData['fields'] = $salsify_fields;

  }

}
