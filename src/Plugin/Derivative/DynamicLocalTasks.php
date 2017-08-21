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
    if (\Drupal::moduleHandler()->moduleExists('media_entity')) {

      // Build the base base for the Media Mapping fields.
      $this->derivatives['salsify_integration.media_mapping'] = $base_plugin_definition;
      $this->derivatives['salsify_integration.media_mapping']['title'] = t('Media Field Mapping');
      $this->derivatives['salsify_integration.media_mapping']['route_name'] = 'salsify_integration.media_mapping';
      $this->derivatives['salsify_integration.media_mapping']['parent_id'] = 'salsify_integration.configuration';

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
    return $this->derivatives;
  }

}
