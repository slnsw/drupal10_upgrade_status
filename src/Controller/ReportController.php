<?php

namespace Drupal\upgrade_status\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormState;
use Drupal\Core\Url;
use Drupal\upgrade_status\Form\UpgradeStatusForm;
use Drupal\upgrade_status\ProjectCollector;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ReportController extends ControllerBase {

  /**
   * The project collector service.
   *
   * @var \Drupal\upgrade_status\ProjectCollector
   */
  protected $projectCollector;

  /**
   * The cache service.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

    /**
     * UpdateStatusReportController constructor.
     *
     * @param \Drupal\upgrade_status\ProjectCollector $projectCollector
     *   The project collector service.
     * @param \Drupal\Core\Cache\CacheBackendInterface $cache
     *   The cache service.
     */
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
    $content = $this->formBuilder()->getForm(UpgradeStatusForm::class);
    $content['form'] = $this->formBuilder()->doBuildForm('drupal_upgrade_status_form', $content, $formState);

    $projects = $this->projectCollector->collectProjects();
    $custom = $this->buildProjectRows($projects['custom']);
    $contrib = $this->buildProjectRows($projects['contrib']);

    $content['drupal_upgrade_status_form']['custom'] = [
      '#type' => 'details',
      '#title' => $this->t('Custom modules and themes'),
      '#tree' => TRUE,
      '#open' => TRUE,
      'data' => $custom,
    ];

    $content['drupal_upgrade_status_form']['contrib'] = [
      '#type' => 'details',
      '#title' => $this->t('Contributed modules and themes'),
      '#tree' => TRUE,
      '#open' => TRUE,
      'data' => $contrib,
    ];

    return $content;
  }

  protected function buildProjectRows(array $projects) {
    $notDeprecated = 0;
    $deprecated = 0;
    $notScanned = 0;
    $totalErrors = 0;

    $projectsDisplay['not_scanned']['data'] = [];
    $projectsDisplay['deprecated']['data'] = [];
    $projectsDisplay['not_deprecated']['data'] = [];

    $projectsDisplay['deprecated'] = [
      '#type' => 'details',
      '#tree' => TRUE,
      '#open' => !empty($deprecated) ? TRUE : FALSE,
      'data' => [
        '#type' => 'table',
        '#header' => [
          'project' => $this->t('Project'),
          'status' => $this->t('Status'),
          'operations' => $this->t('Operations'),
        ],
      ],
    ];

    $projectsDisplay['not_deprecated'] = [
      '#type' => 'details',
      '#tree' => TRUE,
      '#open' => !empty($notDeprecated) ? TRUE : FALSE,
      'data' => [
        '#type' => 'table',
        '#header' => [
          'project' => $this->t('Project'),
        ],
      ],
    ];

    $projectsDisplay['not_scanned'] = [
      '#type' => 'details',
      '#tree' => TRUE,
      '#open' => FALSE,
      'data' => [
        '#type' => 'table',
        '#header' => [
          'project' => $this->t('Project'),
          'operations' => $this->t('Operations'),
        ],
      ],
    ];

    foreach ($projects as $projectMachineName => $projectData) {
      $cache = $this->cache->get($projectMachineName);
      $info = $projectData->info;

      if (empty($cache)) {
        $notScanned++;
        $projectsDisplay['not_scanned']['data'][$projectMachineName] = [
          'project' => [
            '#type' => 'markup',
            '#markup' => $info['name'],
          ],
          'operations' => [
            '#type' => 'operations',
            '#links' => [],
          ],
        ];
        continue;
      }

      $deprecationReportRaw = $cache->data;
      $deprecationReport = json_decode($deprecationReportRaw);
      $errors = $deprecationReport->totals->file_errors;

      if ($errors === 0) {
        $notDeprecated++;
        $projectsDisplay['not_deprecated']['data'][$projectMachineName] = [
          'project' => [
            '#type' => 'markup',
            '#markup' => $info['name'],
          ],
        ];
        continue;
      }
      $totalErrors += $errors;

      if (isset($info['version'])) {
        $projectHumanReadableName = implode(' ', [$info['name'], $info['version']]);
      }

      $deprecated++;
      $projectsDisplay['deprecated']['data'][$projectMachineName] = [
        'project' => [
          '#type' => 'markup',
          '#markup' => $projectHumanReadableName,
        ],
        'status' => [
          '#type' => 'markup',
          '#markup' => $this->t('@errors errors', ['@errors' => $errors]),
        ],
        'operations' => [
          '#type' => 'operations',
          '#links' => [
            'errors' => [
              'title' => $this->t('View errors'),
              'url' => Url::fromRoute('upgrade_status.project', ['project_name' => $projectMachineName]),
              'attributes' => [
                'class' => ['use-ajax'],
                'data-dialog-type' => 'modal',
                'data-dialog-options' => Json::encode([
                  'width' => 1024,
                  'height' => 568,
                ]),
              ],
            ],
          ],
        ],
      ];
    }

    $projectsDisplay['deprecated']['#title'] = $this->t('Found @project project with @errorCount known drupal 9 compatibility errors', ['@project' => $deprecated, '@errorCount' => $totalErrors]);
    $projectsDisplay['not_deprecated']['#title'] = $this->t('Found @project project with no known drupal 9 compatibility errors', ['@project' => $notDeprecated]);
    $projectsDisplay['not_scanned']['#title'] = $this->t('@project project not yet scanned', ['@project' => $notScanned]);

    return $projectsDisplay;
  }

}
