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

    /**
     * Provides content for the upgrade status report page.
     *
     * @return array
     *   Render array.
     */
  public function content() {
    $content = [];

    // Add form to populate and run the scanning queue.
    $content['form'] = $this->formBuilder()->getForm(UpgradeStatusForm::class);

    // Gather project list grouped by custom and contrib projects.
    $projects = $this->projectCollector->collectProjects();

    // List custom project status first.
    $custom = ['#type' => 'markup', '#markup' => '<br /><strong>' . $this->t('No custom projects found.') . '</strong>'];
    if (count($projects['custom'])) {
      $custom = $this->buildProjectGroups($projects['custom']);
    }
    $content['custom'] = [
      '#type' => 'details',
      '#title' => $this->t('Custom modules and themes'),
      '#description' => $this->t('Custom code is specific to your site, and must be upgraded manually. <a href=":upgrade">Read more about how developers can upgrade their code to Drupal 9</a>.', [':upgrade' => 'https://www.drupal.org/documentation/9#deprecated']),
      '#open' => TRUE,
      'data' => $custom,
    ];

    // List contrib project status second.
    $contrib = ['#type' => 'markup', '#markup' => '<br /><strong>' . $this->t('No contributed projects found.') . '</strong>'];
    if (count($projects['contrib'])) {
      $contrib = $this->buildProjectGroups($projects['contrib']);
    }
    $content['contrib'] = [
      '#type' => 'details',
      '#title' => $this->t('Contributed modules and themes'),
      '#description' => $this->t('Contributed code is available from drupal.org. Problems here may be partially resolved by updating to the latest version. <a href=":update">Read more about how to update contributed projects</a>.', [':update' => 'https://www.drupal.org/docs/8/update/update-modules']),
      '#open' => TRUE,
      'data' => $contrib,
    ];

    return $content;
  }

  /**
   * Builds a grouped list of projects by known issues, no known issues and still to be scanned.
   *
   * @param \Drupal\Core\Extension\Extension[] $projects
   *   Array of extensions representing projects.
   *
   * @return array
   *   Build array.
   */
  protected function buildProjectGroups(array $projects) {
    $build = [];
    $total_error_count = 0;

    // Set up containers for each group of project in case we need them.
    $build['known_errors'] = [
      '#type' => 'details',
      '#weight' => -10,
      // Open the known errors list if there was any. Otherwise the list will be removed later.
      '#open' => TRUE,
      'data' => [],
    ];
    $build['no_known_errors'] = [
      '#type' => 'details',
      '#weight' => 0,
      '#open' => FALSE,
      'data' => [],
    ];
    $build['not_scanned'] = [
      '#type' => 'details',
      '#weight' => 10,
      '#open' => FALSE,
      'data' => [],
    ];

    foreach ($projects as $name => $extension) {
      $cache = $this->cache->get($name);
      $info = $extension->info;
      $label = $info['name'] . (!empty($info['version']) ? ' ' . $info['version'] : '');

      // If this project was not found in cache, it is not yet scanned, report that.
      if (empty($cache)) {
        $build['not_scanned']['data'][$name] = [
          'project' => [
            '#type' => 'markup',
            '#markup' => $label,
          ],
          'operations' => [
            '#type' => 'operations',
            // @todo add release info for contrib
            '#links' => [],
          ],
        ];
        continue;
      }

      // Unpack JSON of deprecations to display results.
      $deprecation_report = json_decode($cache->data);
      $project_error_count = $deprecation_report->totals->file_errors;

      // If this project had no known issues found, report that.
      if ($project_error_count === 0) {
        $build['no_known_errors']['data'][$name] = [
          'project' => [
            '#type' => 'markup',
            '#markup' => $label,
          ],
          'operations' => [
            '#type' => 'operations',
            // @todo add rescan operation and release info for contrib
            '#links' => [],
          ],
        ];
        continue;
      }
      $total_error_count += $project_error_count;

      // Finally this project had errors found, display them.
      $build['known_errors']['data'][$name] = [
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
          ],
        ],
      ];
    }

    // Set up the known errors list if there were any known errors found, otherwise remove it.
    if (count($build['known_errors']['data'])) {
      $build['known_errors']['#title'] = $this->formatPlural(
       count($build['known_errors']['data']),
      'Found @count project with @errorCount known Drupal 9 compatibility errors',
      'Found @count projects with @errorCount known Drupal 9 compatibility errors',
       ['@errorCount' => $total_error_count]
      );
      $build['known_errors']['data']['#type'] = 'table';
      $build['known_errors']['data']['#header'] = [
        'project' => $this->t('Project'),
        'status' => $this->t('Status'),
        'operations' => $this->t('Operations'),
      ];
    }
    else {
      unset($build['known_errors']);
    }

    // Set up the no known errors list if there were any projects with no known errors.
    if (count($build['no_known_errors']['data'])) {
      $build['no_known_errors']['#title'] = $this->formatPlural(
        count($build['no_known_errors']['data']),
        'Found @count project with no known Drupal 9 compatibility errors',
        'Found @count projects with no known Drupal 9 compatibility errors'
      );
      $build['no_known_errors']['data']['#type'] = 'table';
      $build['no_known_errors']['data']['#header'] = [
        'project' => $this->t('Project'),
        'operations' => $this->t('Operations'),
      ];
    }
    else {
      unset($build['no_known_errors']);
    }

    // Set up the not scanned list if there were any projects left to scan.
    if (count($build['not_scanned']['data'])) {
      $build['not_scanned']['#title'] = $this->formatPlural(
        count($build['not_scanned']['data']),
        '@count project not yet scanned',
        '@count projects not yet scanned'
      );
      $build['not_scanned']['data']['#type'] = 'table';
      $build['not_scanned']['data']['#header'] = [
        'project' => $this->t('Project'),
        'operations' => $this->t('Operations'),
      ];
    }
    else {
      unset($build['not_scanned']);
    }

    return $build;
  }

}
