<?php

namespace Drupal\salsify_integration;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\field\Entity\FieldConfig;
use Drupal\file\Entity\File;
use Drupal\media_entity\Entity\Media;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class SalsifyImportTaxonomyTerm.
 *
 * The main class used to perform taxonomy term imports from enumerated fields.
 * Imports are trigger either through queues during a cron run or via the
 * configuration page.
 *
 * @package Drupal\salsify_integration
 */
class SalsifyImportTaxonomyTerm extends SalsifyImport {

  /**
   * A function to import Salsify data as taxonomy terms in Drupal.
   *
   * @param string $vid
   *   The vocabulary ID for the taxonomy term field.
   * @param array $field
   *   The Salsify to Drupal field mapping entry.
   * @param array $salsify_ids
   *   The salsify_ids of the values to process.
   * @param array $salsify_field_data
   *   The salsify_field_data of the values to process.
   *
   * @return array|bool
   *   An array of term entities or FALSE.
   */
  public function processSalsifyTaxonomyTermItems($vid, array $field, array $salsify_ids, array $salsify_field_data = []) {
    // Set the default fields to use to lookup any existing terms that were
    // previously imported.
    $field_name = 'salsify_id';
    $field_config = FieldConfig::loadByName($field['entity_type'], $field['bundle'], $field['field_name']);

    if (empty($salsify_field_data)) {
      $salsify = Salsify::create(\Drupal::getContainer());
      $salsify_data = $salsify->getProductData();
      $salsify_field_data = $salsify_data['fields'][$field['salsify_id']];
    }

    // Ensure that the tracking field values is created and ready to be used
    // on the given taxonomy vocabulary.
    $salsify_id_field = FieldConfig::loadByName('taxonomy_term', $vid, $field_name);
    if (is_null($salsify_id_field)) {
      $salsify_fields = SalsifyFields::create(\Drupal::getContainer());
      $salsify_fields->createDynamicField($salsify_field_data, $field_name, 'taxonomy_term', $vid);
    }

    // Find any and all existing terms and update them as needed.
    $existing_terms = $this->getTaxonomyTerms($field_name, $salsify_ids);
    $updated_ids = [];
    foreach ($existing_terms as $existing_term) {
      $salsify_id = $existing_term->{$field_name}->value;
      if (isset($salsify_field_data['values'][$salsify_id]) && $salsify_field_data['values'][$salsify_id]['salsify:name'] != $existing_term->name->value) {
        $existing_term->set('name', $salsify_field_data['values'][$salsify_id]['salsify:name']);
        $existing_term->save();
      }
      $updated_ids[] = $salsify_id;
    }

    // Loop through the remaining values and create new terms for each.f
    $new_ids = array_diff($salsify_ids, $updated_ids);
    foreach ($new_ids as $salsify_id) {
      // Only use the first vocabulary if there are multiple.
      $new_term = $this->entityTypeManager->getStorage('taxonomy_term')->create([
        'vid' => $vid,
        'name' => $salsify_field_data['values'][$salsify_id]['salsify:name'],
        'salsify_id' => $salsify_field_data['values'][$salsify_id]['salsify:id'],
      ]);
      $new_term->save();
    }

  }

  /**
   * Query media entities based on a given field and its value.
   *
   * @param string $field_name
   *   The name of the field to search on.
   * @param array $field_values
   *   The values of the field to match.
   *
   * @return array|int
   *   An array of media entity ids that match the given options.
   */
  public function getTaxonomyTerms($field_name, array $field_values) {
    $term_ids = $this->entityQuery->get('taxonomy_term')
      ->condition($field_name, $field_values, 'IN')
      ->execute();
    return $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($term_ids);
  }

}
