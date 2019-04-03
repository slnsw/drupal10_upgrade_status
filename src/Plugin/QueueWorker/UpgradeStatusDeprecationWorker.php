<?php

namespace Drupal\upgrade_status\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\upgrade_status\DeprecationAnalyser;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Process a queue of project paths to perform deprecation analysis.
 *
 * @todo figure out how to mix batch queue running with cron so they don't conflict.
 *
 * @QueueWorker(
 *   id = "upgrade_status_deprecation_worker",
 *   title = @Translation("Upgrade Status Deprecation Worker")
 * )
 */
class UpgradeStatusDeprecationWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The deprecation analyser service.
   *
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
   * Constructs a \Drupal\upgrade_status\Plugin\QueueWorker\UpgradeStatusDeprecationWorker object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\upgrade_status\DeprecationAnalyser $deprecationAnalyser
   *   The deprecation analyser service.
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
