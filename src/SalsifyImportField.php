<?php

namespace Drupal\salsify_integration;

use Drupal\node\Entity\Node;

/**
 * Class SalsifyImportField.
 *
 * The class used to perform content imports in to individual fields. Imports
 * are triggered either through queues during a cron run or via the
 * configuration page.
 *
 * @package Drupal\salsify_integration
 */
class SalsifyImportField extends SalsifyImport {

  /**
   * A function to import Salsify data as nodes in Drupal.
   *
   * @param array $salsify_data
   *   The Salsify individual product data to process.
   */
  public function processSalsifyItem(array $salsify_data) {
    $content_type = $this->config->get('content_type');

    // Load field mappings keyed by Salsify ID.
    $salsify_field_mapping = SalsifyMultiField::getFieldMappings('salsify_id');

    // Lookup any existing nodes in order to overwrite their contents.
    $results = $this->entityQuery->get('node')
      ->condition('salsify_salsifyid', $salsify_data['salsify:id'])
      ->execute();

    // Load the existing node or generate a new one.
    if ($results) {
      $nid = array_values($results)[0];
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
    }
    else {
      $node = Node::create([
        'title' => $salsify_data['Product - American Model #'],
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
