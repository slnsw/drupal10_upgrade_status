<?php

namespace Drupal\upgrade_status\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
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
   * Constructs a \Drupal\upgrade_status\Controller\UpdateStatusReportController.
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

  /**
   * Provides content for the upgrade status report page.
   *
   * @return array
   *   Render array.
   */
  public function content() {
    $content = ['#attached' => ['library' => ['upgrade_status/upgrade_status.admin']]];

    // Add form to populate and run the scanning queue.
    $content['form'] = $this->formBuilder()->getForm(UpgradeStatusForm::class);

    // Gather project list grouped by custom and contrib projects.
    $projects = $this->projectCollector->collectProjects();

    // List custom project status first.
    $custom = ['#type' => 'markup', '#markup' => '<br /><strong>' . $this->t('No custom projects found.') . '</strong>'];
    if (count($projects['custom'])) {
      $custom = $this->buildProjectList($projects['custom']);
    }
    $content['custom'] = [
      '#type' => 'details',
      '#title' => $this->t('Custom modules and themes'),
      '#description' => $this->t('Custom code is specific to your site, and must be upgraded manually. <a href=":upgrade">Read more about how developers can upgrade their code to Drupal 9</a>.', [':upgrade' => 'https://www.drupal.org/documentation/9#deprecated']),
      '#open' => TRUE,
      '#attributes' => ['class' => ['upgrade-status-summary']],
      'data' => $custom,
    ];

    // List contrib project status second.
    $contrib = ['#type' => 'markup', '#markup' => '<br /><strong>' . $this->t('No contributed projects found.') . '</strong>'];
    if (count($projects['contrib'])) {
      $contrib = $this->buildProjectList($projects['contrib']);
    }
    $content['contrib'] = [
      '#type' => 'details',
      '#title' => $this->t('Contributed modules and themes'),
      '#description' => $this->t('Contributed code is available from drupal.org. Problems here may be partially resolved by updating to the latest version. <a href=":update">Read more about how to update contributed projects</a>.', [':update' => 'https://www.drupal.org/docs/8/update/update-modules']),
      '#open' => TRUE,
      '#attributes' => ['class' => ['upgrade-status-summary']],
      'data' => $contrib,
    ];

    return $content;
  }

  /**
   * Builds a list and status summary of projects.
   *
   * @param \Drupal\Core\Extension\Extension[] $projects
   *   Array of extensions representing projects.
   *
   * @return array
   *   Build array.
   */
  protected function buildProjectList(array $projects) {
    $counters = [
      'not-scanned' => 0,
      'no-known-error' => 0,
      'known-errors' => 0,
    ];

    $build['data'] = [
      '#type' => 'table',
      '#header' => [
        'project' => $this->t('Project'),
        'status' => $this->t('Status'),
        'operations' => $this->t('Operations'),
      ],
      '#weight' => 20,
    ];

    foreach ($projects as $name => $extension) {
      $cache = $this->cache->get($name);
      $info = $extension->info;
      $label = $info['name'] . (!empty($info['version']) ? ' ' . $info['version'] : '');

      // If this project was not found in cache, it is not yet scanned, report that.
      if (empty($cache)) {
        $build['data'][$name] = [
          '#attributes' => ['class' => ['not-scanned']],
          'project' => [
            '#type' => 'markup',
            '#markup' => $label,
          ],
          'status' => [],
          'operations' => [
            '#type' => 'operations',
            // @todo add release info for contrib
            '#links' => [],
          ],
        ];
        $counters['not-scanned']++;
        continue;
      }

      // Unpack JSON of deprecations to display results.
      $report = json_decode($cache->data, TRUE);
      if (isset($report['totals'])) {
        $project_error_count = $report['totals']['file_errors'];
      }
      else {
        $project_error_count = 0;
      }

      // If this project had no known issues found, report that.
      if ($project_error_count === 0) {
        $build['data'][$name] = [
          '#attributes' => ['class' => 'no-known-error'],
          'project' => [
            '#type' => 'markup',
            '#markup' => $label,
          ],
          'status' => [],
          'operations' => [
            '#type' => 'operations',
            // @todo add rescan operation and release info for contrib
            '#links' => [
              're-scan' => [
                'title' => $this->t('Re-scan'),
                'url' => Url::fromRoute('upgrade_status.add_project', ['type' => $extension->getType(), 'project_machine_name' => $extension->getName()]),
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
        $counters['no-known-error']++;
        continue;
      }
      // Unlike the other two counters, this counts the number of errors, not projects.
      $counters['no-known-error'] += $project_error_count;

      // Finally this project had errors found, display them.
      $build['data'][$name] = [
        '#attributes' => ['class' => 'known-errors'],
        'project' => [
          '#type' => 'markup',
          '#markup' => $label,
        ],
        'status' => [
          '#type' => 'markup',
          '#markup' => $this->formatPlural($project_error_count, '@count error', '@count errors'),
        ],
        'operations' => [
          '#type' => 'operations',
          '#links' => [
            'errors' => [
              'title' => $this->t('View errors'),
              'url' => Url::fromRoute('upgrade_status.project', ['project_name' => $name]),
              'attributes' => [
                'class' => ['use-ajax'],
                'data-dialog-type' => 'modal',
                'data-dialog-options' => Json::encode([
                  'width' => 1024,
                  'height' => 568,
                ]),
              ],
            ],
            're-scan' => [
              'title' => $this->t('Re-scan'),
              'url' => Url::fromRoute('upgrade_status.add_project', ['type' => $extension->getType(), 'project_machine_name' => $extension->getName()]),
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

    $summary = [];
    if ($counters['known-errors'] > 0) {
      $summary[] = $this->formatPlural($counters['known-errors'], '@count total error found', '@count total errors found');
    }
    if ($counters['no-known-error'] > 0) {
      $summary[] = $this->formatPlural($counters['no-known-error'], '@count project has no known errors', '@count projects have no known errors');
    }
    if ($counters['not-scanned'] > 0) {
      $summary[] = $this->formatPlural($counters['not-scanned'], '@count project remaining to scan', '@count projects remaining to scan');
    }
    $build['summary'] = [
      '#type' => 'markup',
      '#markup' => '<div class="report-counters">' . join(', ', $summary) . '</div>',
      '#weight' => -10,
    ];

    return $build;
  }

}
