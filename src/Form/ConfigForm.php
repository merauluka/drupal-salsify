<?php

namespace Drupal\salsify_integration\Form;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\salsify_integration\SalsifyMultiField;
use Drupal\salsify_integration\SalsifySingleField;

/**
 * Salsify Configuration form class.
 */
class ConfigForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'salsify_integration_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $config = $this->config('salsify_integration.settings');

    $form['salsify_api_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Salisfy API Settings'),
      '#collapsible' => TRUE,
      '#group' => 'salsify_api_settings_group',
    ];

    $form['salsify_api_settings']['product_feed_url'] = [
      '#type' => 'url',
      '#size' => 75,
      '#title' => $this->t('Salsify Product Feed'),
      '#default_value' => $config->get('product_feed_url'),
      '#description' => $this->t('The link to the product feed from a Salsify channel. For details on channels in Salisfy, see <a href="@url" target="_blank">Salsify\'s documentation</a>', array('@url' => 'https://help.salsify.com/help/getting-started-with-channels')),
      '#required' => TRUE,
    ];

    $form['salsify_api_settings']['access_token'] = [
      '#type' => 'textfield',
      '#size' => 75,
      '#title' => $this->t('Salsify Access Token'),
      '#default_value' => $config->get('access_token'),
      '#description' => $this->t('The access token from the Salsify user account to use for this integration. For details on where to find the access token, see <a href="@url" target="_blank">Salsify\'s API documentation</a>', array('@url' => 'https://help.salsify.com/help/getting-started-api-authorization')),
      '#required' => TRUE,
    ];

    $content_types = \Drupal::entityTypeManager()->getStorage('node_type')->loadMultiple();
    $content_types_options = [];
    foreach ($content_types as $content_type) {
      $content_types_options[$content_type->id()] = $content_type->label();
    }
    $form['salsify_api_settings']['content_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Drupal Content Type'),
      '#options' => $content_types_options,
      '#default_value' => $config->get('content_type'),
      '#description' => $this->t('The content type to used for product mapping from Salsify.'),
      '#required' => TRUE,
      '#cache' => [
        'tags' => [
          'salsify_config',
        ],
      ],
    ];

    if ($config->get('product_feed_url') && $config->get('access_token') && $config->get('content_type')) {
      $form['salsify_operations'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Operations'),
        '#collapsible' => TRUE,
        '#group' => 'salsify_operations_group',
      ];
      $form['salsify_operations']['salsify_start_import'] = [
        '#type' => 'button',
        '#value' => $this->t('Sync with Salsify'),
        '#prefix' => '<p>',
        '#suffix' => '</p>',
      ];
      $form['salsify_operations']['salsify_import_reminder'] = [
        '#type' => 'markup',
        '#markup' => '<p><strong>' . $this->t('Not seeing your changes from Salsify?') . '</strong><br/>' . $this->t('If you just made a change, your product channel will need to be updated to reflect the change. For details on channels in Salisfy, see <a href="@url" target="_blank">Salsify\'s documentation.</a >', ['@url' => 'https://help.salsify.com/help/getting-started-with-channels']) . '</p>',
      ];
    }

    $form['admin_options'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Additional options'),
      '#collapsible' => TRUE,
      '#group' => 'additional_settings',
    ];

    // Create a description for the Import Method field. This is to note the
    // issue with Drupal core, which at the time of this writing has issues
    // rendering more than 64 fields on entity edit/update forms.
    $description = '<strong>' . $this->t('Serialized:') . '</strong> '
      . $this->t('All Salsify fields will be imported as serialized data in a single field and unserialized for display.') . '<br/>'
      . '<strong>' . $this->t('Drupal Fields:') . '</strong> '
      . $this->t('All Salsify fields will be imported into fields. These fields will be dynamically created on import and managed via this module.') . '<br/>'
      . '<em>' . $this->t('Warning:') . ' '
      . $this->t('For imports with a large number of fields, editing the Salsify content type nodes can result performance issues and 500 errors. It is not recommended to use the "Fields" options for large data sets.') . '</em>';

    $form['admin_options']['import_method'] = [
      '#type' => 'select',
      '#title' => $this->t('Import Method'),
      '#description' => $description,
      '#options' => [
        'serialized' => $this->t('Serialized'),
        'fields' => $this->t('Fields'),
      ],
      '#default_value' => $config->get('import_method') ? $config->get('import_method') : 'serialized',
      '#required' => TRUE,
    ];

    $form['admin_options']['entity_reference_allow'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow mapping Salsify data to entity reference fields.'),
      '#description' => $this->t('Note: This will require additional processing via custom code. Imports performed with this checked without any custom processing will likely fail.'),
      '#default_value' => $config->get('entity_reference_allow'),
    ];

    if (\Drupal::moduleHandler()->moduleExists('media_entity')) {
      $form['admin_options']['process_media_assets'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Process Salsify media assets into media fields.'),
        '#description' => $this->t('Note: This will require media entities setup to match filetypes imported from Salsify. Importing will complete on a best effort basis.'),
        '#default_value' => $config->get('process_media_assets'),
      ];
    }
    else {
      $form['admin_options']['process_media_notice'] = [
        '#type' => 'markup',
        '#markup' => $this->t('Enable the Media Entity module to allow importing media assets.'),
        '#prefix' => '<p><em>',
        '#suffix' => '</em></p>',
      ];
    }

    $form['admin_options']['keep_fields_on_uninstall'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Leave all dynamically added fields on module uninstall.'),
      '#default_value' => $config->get('keep_fields_on_uninstall'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // If the form was submitted via the "Sync" button, then run the import
    // process right away.
    $trigger = $form_state->getTriggeringElement();
    if ($trigger['#id'] == 'edit-salsify-start-import') {
      $container = \Drupal::getContainer();
      if ($form_state->getValue('import_method') == 'serialized') {
        $product_feed = SalsifySingleField::create($container);
      }
      else {
        $product_feed = SalsifyMultiField::create($container);
      }
      $results = $product_feed->importProductData(TRUE);
      if ($results) {
        drupal_set_message($results['message'], $results['status']);
      }
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('salsify_integration.settings');

    // Remove the options settings if the import method was changed from fields
    // to serialized.
    $new_import_method = $form_state->getValue('import_method');
    if ($config->get('import_method') != $new_import_method && $new_import_method == 'serialized') {
      $config_options = $this->configFactory->getEditable('salsify_integration.field_options');
      $config_options->delete();
    }
    $config->set('import_method', $new_import_method);

    $config->set('product_feed_url', $form_state->getValue('product_feed_url'));
    $config->set('access_token', $form_state->getValue('access_token'));
    $config->set('content_type', $form_state->getValue('content_type'));
    $config->set('keep_fields_on_uninstall', $form_state->getValue('keep_fields_on_uninstall'));
    $config->set('entity_reference_allow', $form_state->getValue('entity_reference_allow'));
    $config->set('process_media_assets', $form_state->getValue('process_media_assets'));
    $config->set('import_method', $form_state->getValue('import_method'));

    // Save the configuration.
    $config->save();

    // Flush the cache entries tagged with 'salsify_config' to force the API
    // to lookup the field configurations again for the field mapping form.
    Cache::invalidateTags(['salsify_config']);

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

}
