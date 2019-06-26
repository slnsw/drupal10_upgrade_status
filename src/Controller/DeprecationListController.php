<?php

namespace Drupal\upgrade_status\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\upgrade_status\ProjectCollectorInterface;
use Drupal\upgrade_status\ScanResultFormatter;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DeprecationListController extends ControllerBase {

  /**
   * he scan result formatter service.
   *
   * @var \Drupal\upgrade_status\ScanResultFormatter
   */
  protected $resultFormatter;

  /**
   * The project collector service.
   *
   * @var \Drupal\upgrade_status\ProjectCollectorInterface
   */
  protected $projectCollector;

  /**
   * Constructs a \Drupal\upgrade_status\Controller\DeprecationListController.
   *
   * @param \Drupal\upgrade_status\ScanResultFormatter $result_formatter
   *   The scan result formatter service.
   * @param \Drupal\upgrade_status\ProjectCollectorInterface $project_collector
   *   The project collector service.
   */
  public function __construct(
    ScanResultFormatter $result_formatter,
    ProjectCollectorInterface $project_collector
  ) {
    $this->resultFormatter = $result_formatter;
    $this->projectCollector = $project_collector;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('upgrade_status.result_formatter'),
      $container->get('upgrade_status.project_collector')
    );
  }

  /**
   * Builds content for the error list page/popup.
   *
   * @param string $type
   *   Type of the extension, it can be either 'module' or 'theme.
   * @param string $project_machine_name
   *   The machine name of the project.
   *
   * @return array
   *   Build array.
   */
  public function content(string $type, string $project_machine_name) {
    $extension = $this->projectCollector->loadProject($type, $project_machine_name);
    return $this->resultFormatter->formatResult($extension);
  }

}
