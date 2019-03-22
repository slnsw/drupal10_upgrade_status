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
    $custom = $this->buildProjectRows($projects['custom']);
    $contrib = $this->buildProjectRows($projects['contrib']);

    $content['drupal_readiness_form']['custom'] = [
      '#type' => 'details',
      '#title' => $this->t('Custom modules and themes'),
      '#tree' => TRUE,
      '#open' => TRUE,
      'data' => $custom,
    ];

    $content['drupal_readiness_form']['contrib'] = [
      '#type' => 'details',
      '#title' => $this->t('Contributed modules and themes'),
      '#tree' => TRUE,
      '#open' => TRUE,
      'data' => $contrib,
    ];

    return $content;
  }

  protected function buildProjectRows(array $projects) {
    $notDeprecated = [];
    $deprecated = [];
    $notScanned = [];
    $projectsDisplay = [];
    $totalErrors = 0;

    foreach ($projects as $projectName => $projectData) {
      $cache = $this->cache->get($projectName);
      $info = $projectData->info;

      if (empty($cache)) {
        $notScanned[] = [
          'project' => $info['name'],
        ];
        continue;
      }

      $deprecationReportRaw = $cache->data;
      $deprecationReport = json_decode($deprecationReportRaw);
      $errors = $deprecationReport->totals->file_errors;

      if ($errors === 0) {
        $notDeprecated[] = [
          'project' => $info['name'],
        ];
        continue;
      }
      $totalErrors += $errors;

      if (isset($info['version'])) {
        $deprecated[] = [
          'project' => $info['name'] . $info['version'],
          'status' => $this->t('@errors errors', ['@errors' => $errors]),
        ];
        continue;
      }

      $deprecated[] = [
        'project' => $info['name'],
        'status' => $this->t('@errors errors', ['@errors' => $errors]),
      ];
    }

    $projectsDisplay['deprecated'] = [
      '#type' => 'details',
      '#title' => $this->t('Found @project project with @errorCount known drupal 9 compatibility errors', ['@project' => count($deprecated), '@errorCount' => $totalErrors]),
      '#tree' => TRUE,
      '#open' => !empty($deprecated) ? TRUE : FALSE,
      'data' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Project'),
          $this->t('Status'),
        ],
        '#rows' => $deprecated,
      ],
    ];;
    $projectsDisplay['not_deprecated'] = [
      '#type' => 'details',
      '#title' => $this->t('Found @project project with no known drupal 9 compatibility errors', ['@project' => count($notDeprecated)]),
      '#tree' => TRUE,
      '#open' => !empty($notDeprecated) ? TRUE : FALSE,
      'data' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Project'),
        ],
        '#rows' => $notDeprecated,
      ],
    ];
    $projectsDisplay['not_scanned'] = [
      '#type' => 'details',
      '#title' => $this->t('@project project not yet scanned', ['@project' => count($notScanned)]),
      '#tree' => TRUE,
      '#open' => FALSE,
      'data' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Project'),
        ],
        '#rows' => $notScanned,
      ],
    ];

    return $projectsDisplay;
  }

}
