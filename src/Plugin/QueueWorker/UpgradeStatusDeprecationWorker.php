<?php

namespace Drupal\upgrade_status\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\upgrade_status\DeprecationAnalyser;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Process a queue of project paths to perform deprecation analysis.
 *
 * @QueueWorker(
 *   id = "upgrade_status_deprecation_worker",
 *   title = @Translation("Upgrade Status Deprecation Worker"),
 *   cron = {"time" = 60}
 * )
 */
class UpgradeStatusDeprecationWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * @var \Drupal\upgrade_status\DeprecationAnalyser
   */
  protected $deprecationAnalyser;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('upgrade_status.deprecation_analyser')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    DeprecationAnalyser $deprecationAnalyser
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->deprecationAnalyser = $deprecationAnalyser;
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $this->deprecationAnalyser->analyse($data);
  }

}
