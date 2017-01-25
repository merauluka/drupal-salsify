<?php

namespace Drupal\salsify_integration;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class SalsifyImport.
 *
 * The main class used to perform content imports. Imports are trigger either
 * through queues during a cron run or via the configuration page.
 *
 * @package Drupal\salsify_integration
 */
class SalsifyImport {

  /**
   * The configFactory interface.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The Salsify config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * Entity query factory.
   *
   * @var \Drupal\Core\Entity\Query\QueryFactory
   */
  protected $entityQuery;

  /**
   * The Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a \Drupal\salsify_integration\Salsify object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory interface.
   * @param \Drupal\Core\Entity\Query\QueryFactory $entity_query
   *   The query factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(ConfigFactoryInterface $config_factory, QueryFactory $entity_query, EntityTypeManagerInterface $entity_type_manager) {
    $this->configFactory = $config_factory;
    $this->config = $this->configFactory->get('salsify_integration.settings');
    $this->entityQuery = $entity_query;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity.query'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * A function to import Salsify data as nodes in Drupal.
   *
   * @param array $salsify_data
   *   The Salsify individual product data to process.
   */
  public function processSalsifyItem(array $salsify_data) {}

}
