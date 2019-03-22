<?php

namespace Drupal\upgrade_status\Controller;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormState;
use Drupal\upgrade_status\Form\ReadinessForm;
use Drupal\upgrade_status\ProjectCollector;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DeprecationDasboardController extends ControllerBase {

  /**
   * @var \Drupal\upgrade_status\ProjectCollector
   */
  protected $projectCollector;

  /**
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  public function __construct(
    ProjectCollector $projectCollector,
    CacheBackendInterface $cache
  ) {
    $this->projectCollector = $projectCollector;
    $this->cache = $cache;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('upgrade_status.project_collector'),
      $container->get('cache.upgrade_status')
    );
  }

  public function content() {
    $formState = new FormState();
    $content = $this->formBuilder()->getForm(ReadinessForm::class);
    $content['form'] = $this->formBuilder()->doBuildForm('drupal_readiness_form', $content, $formState);

    $projects = $this->projectCollector->collectProjects();

    $custom = [];
    foreach ($projects['custom'] as $projectName => $projectData) {
      $cache = $this->cache->get($projectName);
      $info = $projectData->info;
      $custom[$projectName] = [
        'project' => $info['name'],
        'status' => $cache->data ?? [],
      ];
    }

    $contrib = [];
    foreach ($projects['contrib'] as $projectName => $projectData) {
      $cache = $this->cache->get($projectName);
      $info = $projectData->info;
      $contrib[$projectName] = [
        'project' => $info['name'],
        'status' => $cache->data ?? [],
      ];
    }

    $content['drupal_readiness_form']['custom'] = [
      '#type' => 'details',
      '#title' => $this->t('Custom modules and themes'),
      '#tree' => TRUE,
      '#open' => TRUE,
      'data' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Project'),
          $this->t('Status'),
          $this->t('Operations'),
        ],
        '#rows' => $custom,
      ],
    ];

    $content['drupal_readiness_form']['contrib'] = [
      '#type' => 'details',
      '#title' => $this->t('Contributed modules and themes'),
      '#tree' => TRUE,
      '#open' => TRUE,
      'data' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Project'),
          $this->t('Status'),
          $this->t('Available updates'),
          $this->t('Operations'),
        ],
        '#rows' => $contrib,
      ],
    ];

    return $content;
  }

}
