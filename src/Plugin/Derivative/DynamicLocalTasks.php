<?php

namespace Drupal\salsify_integration\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;

/**
 * Defines dynamic local tasks.
 */
class DynamicLocalTasks extends DeriverBase {

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    // If the media entity module is installed, then make the media tab and its
    // mapping fields available for use with Salsify.
    if (\Drupal::moduleHandler()->moduleExists('media_entity')) {
      $media_types = \Drupal::entityTypeManager()->getStorage('media_bundle')->loadMultiple();
      $count = 0;
      foreach ($media_types as $media_type => $media_config) {
        $task_id = $base_plugin_definition['id'] . '.' . $media_type;
        $this->derivatives[$task_id] = $base_plugin_definition;
        $this->derivatives[$task_id]['title'] = $media_config->label();
        $this->derivatives[$task_id]['route_name'] = 'salsify_integration.media_mapping';
        $this->derivatives[$task_id]['parent_id'] = 'salsify_integration.media_mapping';
        $this->derivatives[$task_id]['route_parameters'] = array('media_type' => $media_type);
        $this->derivatives[$task_id]['weight'] = $count;
        $count++;
      }
    }
    // If the entity type selected is a commerce product, then the product
    // variations are required. Add them into a separate tab and all some
    // entity mapping into the fields.
    // if (\Drupal::moduleHandler()->moduleExists('commerce_product')) {
    //   $config = \Drupal::config('salsify_integration.settings');

    //   if ($config->get('entity_type') == 'commerce_product') {
    //     // $task_id = $base_plugin_definition['id'] . '.commerce_product_variation';
    //     // $this->derivatives[$task_id] = $base_plugin_definition;
    //     // $this->derivatives[$task_id]['title'] = t('Commerce: Product Variation');
    //     // $this->derivatives[$task_id]['route_name'] = 'salsify_integration.commerce_variation_mapping';
    //     // $this->derivatives[$task_id]['parent_id'] = 'salsify_integration.commerce_variation_mapping';
    //   }
    // }

    return $this->derivatives;
  }

}
