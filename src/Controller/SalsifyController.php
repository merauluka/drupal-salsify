<?php

namespace Drupal\salsify_integration\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\salsify_integration\Salsify;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Salsify Controller Class.
 */
class SalsifyController extends ControllerBase {

  /**
   * The Drupal service container.
   *
   * @var \Symfony\Component\DependencyInjection\ContainerInterface
   */
  protected $container;

  /**
   * SalsifyController constructor.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The Drupal service container.
   */
  public function __construct(ContainerInterface $container) {
    $this->container = $container;
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The Drupal service container.
   *
   * @return static
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container
    );
  }

  /**
   * Loads the product values and kicks off the product import queue process.
   */
  public function getProducts() {
    $product_feed = Salsify::create($this->container);
    $product_feed->importProductData();
  }

}
