<?php

/**
 * @file
 * The Salsify integration module.
 */

use Drupal\node\Entity\Node;

/**
 * Alter the array of data returned from Salsify.
 *
 * @param array $salsify_data
 *   The array of product data from Salsify.
 *
 * @see \Drupal\salsify_integration\SalsifySingleField::importProductFields()
 * @see \Drupal\salsify_integration\SalsifyImportSerialized::processSalsifyItem()
 */
function hook_salsify_product_data_alter(array &$salsify_data) {
  // Add functionality to alter the product data returned form Salsify.
}

/**
 * Alter the mapping of a Salsify field.
 *
 * @param array $values
 *   The array of field and salsify data to add to the mapping table.
 *
 * @see \Drupal\salsify_integration\SalsifySingleField::importProductFields()
 * @see \Drupal\salsify_integration\SalsifyImportSerialized::processSalsifyItem()
 */
function hook_salsify_field_mapping_alter(array &$values) {
  // Alter field mappings before they are saved.
}

/**
 * Alter the handling of a Salsify field during the import process.
 *
 * @param string $title
 *   The options (values, attributes, etc.) for the field.
 * @param array $salsify_field_data
 *   The array of field settings from Salsify.
 *
 * @see \Drupal\salsify_integration\SalsifyImportField::processSalsifyItem()
 * @see \Drupal\salsify_integration\SalsifyImportSerialized::processSalsifyItem()
 */
function hook_salsify_process_node_title_alter(&$title, array $salsify_field_data) {
  $title = t('Test Title');
}

/**
 * Alter the handling of a Salsify field during the import process.
 *
 * @param array|string $options
 *   The options (values, attributes, etc.) for the field.
 * @param array $salsify_field_data
 *   The array of field settings from Salsify.
 *
 * @see \Drupal\salsify_integration\SalsifyImportField::processSalsifyItem()
 * @see \Drupal\salsify_integration\SalsifyImportSerialized::processSalsifyItem()
 */
function hook_salsify_process_field_alter(&$options, array $salsify_field_data) {
  /* @var \Drupal\field\Entity\FieldConfig $field_config */
  $field_config = $salsify_field_data['field_config'];
  $salsify_data = $salsify_field_data['salsify_data'];
  $field_map = $salsify_field_data['field_map'];
}

/**
 * Alter the handling of a Salsify field during the import process.
 *
 * This will handle a specific field type allowing targeting of certain fields
 * during the import process. This can make for cleaner code by making switch
 * statements or long if statements unnecessary.
 *
 * @param array|string $options
 *   The options (values, attributes, etc.) for the field.
 * @param array $salsify_field_data
 *   The array of field settings from Salsify.
 *
 * @see \Drupal\salsify_integration\SalsifyImportField::processSalsifyItem()
 * @see \Drupal\salsify_integration\SalsifyImportSerialized::processSalsifyItem()
 */
function hook_salsify_process_field_FIELD_TYPE_alter(&$options, array $salsify_field_data) {
  /* @var \Drupal\field\Entity\FieldConfig $field_config */
  $field_config = $salsify_field_data['field_config'];
  $salsify_data = $salsify_field_data['salsify_data'];
  $field_map = $salsify_field_data['field_map'];
}

/**
 * Alter the node managed by Salsify just before it is saved.
 *
 * @param \Drupal\node\Entity\Node $node
 *   The node object to be altered.
 * @param array $product_data
 *   The complete Salsify data array for the current product.
 */
function hook_salsify_node_presave_alter(Node &$node, array $product_data) {
  $node->set('field_name', $product_data['Value']);
}
