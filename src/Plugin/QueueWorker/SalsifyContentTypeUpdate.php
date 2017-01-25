<?php

namespace Drupal\salsify_integration\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\salsify_integration\SalsifyMultiField;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides functionality for the SalsifyContentTypeUpdate Queue.
 *
 * @QueueWorker(
 *   id = "rinnai_salsify_content_type_update",
 *   title = @Translation("Salsify: Content Type Update"),
 *   cron = {"time" = 10}
 * )
 */
class SalsifyContentTypeUpdate extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The QueueFactory object.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  private $queueFactory;

  /**
   * Creates a new SalsifyContentTypeUpdate object.
   *
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The QueueFactory object.
   */
  public function __construct(EntityFieldManagerInterface $entity_field_manager, EntityTypeManagerInterface $entity_type_manager, QueueFactory $queue_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->queueFactory = $queue_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('entity_field.manager'),
      $container->get('entity_type.manager'),
      $container->get('queue')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    // Set default values.
    $original = $data['original'];
    $current = $data['current'];
    $view_modes = [
      'default',
      'teaser',
    ];

    // Gather the fields from the old content type.
    $fields = $this->entityFieldManager->getFieldDefinitions('node', $original);
    // Load the field mappings for Salsify and Drupal.
    $salsify_field_mapping = SalsifyMultiField::getFieldMappings();

    foreach ($salsify_field_mapping as $salsify_field) {
      if (isset($fields[$salsify_field->field_name])) {
        $field_name = $salsify_field->field_name;
        /* @var \Drupal\field\Entity\FieldConfig $field */
        $field = $fields[$field_name];

        // Setup the new field for the new content type and store it.
        $field_settings = [
          'field_name' => $field->getName(),
          'entity_type' => 'node',
          'bundle' => $current,
          'label' => $field->get('label'),
        ];
        // Create the field against the given content type.
        $new_field = FieldConfig::create($field_settings);
        $new_field->save();

        // Create the form and view displays for the field.
        SalsifyMultiField::createFieldFormDisplay($current, $field_name, $salsify_field->salsify_data_type);
        foreach ($view_modes as $view_mode) {
          SalsifyMultiField::createFieldViewDisplay($current, $field_name, $view_mode);
        }

        // The field has been moved. Remove it from the old content type.
        $field->delete();
      }
    }
  }

}
