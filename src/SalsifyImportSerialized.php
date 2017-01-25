<?php

namespace Drupal\salsify_integration;

use Drupal\node\Entity\Node;

/**
 * Class SalsifyImportSerialized.
 *
 * The class used to perform an import of content into a single field with all
 * Salsify field data serialized. Imports are triggered either through queues
 * during a cron run or via the configuration page.
 *
 * @package Drupal\salsify_integration
 */
class SalsifyImportSerialized extends SalsifyImport {

  /**
   * A function to import Salsify data as nodes in Drupal.
   *
   * @param array $salsify_data
   *   The Salsify individual product data to process.
   */
  public function processSalsifyItem(array $salsify_data) {
    $content_type = $this->config->get('content_type');

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
        'title' => $salsify_data['Product Name'] . ' (' . $salsify_data['Product - Manufacturer Model # (RJ)'] . ')',
        'type' => $content_type,
        'created' => strtotime($salsify_data['salsify:created_at']),
        'changed' => strtotime($salsify_data['salsify:updated_at']),
        'salsify_salsifyid' => $salsify_data['salsify:id'],
        'status' => 1,
      ]);
      $node->save();
    }

    // Set the field data against the Salsify node and save the results.
    $salsify_serialized = serialize($salsify_data);
    $node->set('salsifysync_data', $salsify_serialized);
    $node->save();
  }

}
