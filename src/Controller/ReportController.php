<?php

namespace Drupal\upgrade_status\Controller;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactory;
use Drupal\Core\Url;
use Drupal\update\UpdateManager;
use Drupal\update\UpdateProcessor;
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
   * Available releases store.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface|mixed
   */
  protected $releaseStore;

  /**
   * Update Processor Service.
   *
   * @var \Drupal\update\UpdateProcessor
   */
  protected $updateProcessor;

  /**
   * The update manager service.
   *
   * @var \Drupal\update\UpdateManager
   */
  protected $updateManager;

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
    CacheBackendInterface $cache,
    KeyValueExpirableFactory $key_value_expirable,
    UpdateProcessor $update_processor,
    UpdateManager $update_manager
  ) {
    $this->projectCollector = $projectCollector;
    $this->cache = $cache;
    $this->releaseStore = $key_value_expirable->get('update_available_releases');
    $this->updateProcessor = $update_processor;
    $this->updateManager = $update_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('upgrade_status.project_collector'),
      $container->get('cache.upgrade_status'),
      $container->get('keyvalue.expirable'),
      $container->get('update.processor'),
      $container->get('update.manager')
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
      '#attributes' => ['class' => ['upgrade-status-summary upgrade-status-summary-custom']],
      'data' => $custom,
    ];

    // List contrib project status second.
    $contrib = ['#type' => 'markup', '#markup' => '<br /><strong>' . $this->t('No contributed projects found.') . '</strong>'];
    if (count($projects['contrib'])) {
      $contrib = $this->buildProjectList($projects['contrib'], TRUE);
    }
    $content['contrib'] = [
      '#type' => 'details',
      '#title' => $this->t('Contributed modules and themes'),
      '#description' => $this->t('Contributed code is available from drupal.org. Problems here may be partially resolved by updating to the latest version. <a href=":update">Read more about how to update contributed projects</a>.', [':update' => 'https://www.drupal.org/docs/8/update/update-modules']),
      '#open' => TRUE,
      '#attributes' => ['class' => ['upgrade-status-summary upgrade-status-summary-contrib']],
      'data' => $contrib,
    ];

    return $content;
  }

  /**
   * Builds a list and status summary of projects.
   *
   * @param \Drupal\Core\Extension\Extension[] $projects
   *   Array of extensions representing projects.
   * @param bool $isContrib
   *   (Optional) Whether the list to be produced is for contributed projects.
   *
   * @return array
   *   Build array.
   */
  protected function buildProjectList(array $projects, bool $isContrib = FALSE) {
    $counters = [
      'not-scanned' => 0,
      'no-known-error' => 0,
      'known-errors' => 0,
    ];

    $header = ['project' => $this->t('Project'), 'status' => $this->t('Status')];
    if ($isContrib) {
      $header['update'] = $this->t('Available update');
    }
    $header['operations'] = $this->t('Operations');

    $build['data'] = [
      '#type' => 'table',
      '#header' => $header,
      '#weight' => 20,
    ];

    foreach ($projects as $name => $extension) {
      $cache = $this->cache->get($name);
      $info = $extension->info;
      $label = $info['name'] . (!empty($info['version']) ? ' ' . $info['version'] : '');

      $update_cell = [
        '#type' => 'markup',
        '#markup' => $isContrib ? $this->t('Up to date') : '',
      ];

      if ($isContrib) {
        $projectUpdateData = $this->releaseStore->get($name);

        if (is_null($projectUpdateData['releases'])) {
          // @todo is this not too expensive to run here?
          $this->updateManager->refreshUpdateData();
          $projectInfos = $this->updateManager->getProjects();
          $this->updateProcessor->createFetchTask($projectInfos[$name]);
          $this->updateProcessor->processFetchTask($projectInfos[$name]);

          $projectUpdateData = $this->releaseStore->get($name);
        }

        $latestRelease = reset($projectUpdateData['releases']);
        $latestVersion = $latestRelease['version'];

        if ($info['version'] !== $latestVersion) {
          $link = $projectUpdateData['link'] . '/releases/' . $info['version'];
          $update_cell = [
            '#type' => 'link',
            '#title' => $info['version'],
            '#url' => Url::fromUri($link),
          ];
        }
      }

      // If this project was not found in cache, it is not yet scanned, report that.
      if (empty($cache)) {
        $build['data'][$name] = [
          '#attributes' => ['class' => ['not-scanned', 'project-' . $name]],
          'project' => [
            '#type' => 'markup',
            '#markup' => $label,
          ],
          'status' => [
            '#type' => 'markup',
            '#markup' => $this->t('Not scanned'),
          ],
          'update' => $update_cell,
          'operations' => $this->projectCollector->getProjectOperations($name, $extension->getType()),
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
          '#attributes' => ['class' => ['no-known-error', 'project-' . $name]],
          'project' => [
            '#type' => 'markup',
            '#markup' => $label,
          ],
          'status' => [
            '#type' => 'markup',
            '#markup' => $this->t('No known errors'),
          ],
          'update' => $update_cell,
          'operations' => $this->projectCollector->getProjectOperations($name, $extension->getType(), FALSE),
        ];
        $counters['no-known-error']++;
        continue;
      }
      // Unlike the other two counters, this counts the number of errors, not projects.
      $counters['known-errors'] += $project_error_count;

      // Finally this project had errors found, display them.
      $build['data'][$name] = [
        '#attributes' => ['class' => ['known-errors', 'project-' . $name]],
        'project' => [
          '#type' => 'markup',
          '#markup' => $label,
        ],
        'status' => [
          '#type' => 'markup',
          '#markup' => $this->formatPlural($project_error_count, '@count error', '@count errors'),
        ],
        'update' => $update_cell,
        'operations' => $this->projectCollector->getProjectOperations($name, $extension->getType(), FALSE, TRUE),
      ];
    }

    if (!$isContrib) {
      // If the list is not for contrib, remove the update placeholder.
      foreach($build['data'] as $name => &$row) {
        if (is_array($row)) {
          unset($row['update']);
        }
      }
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
      '#markup' => '<div class="report-counters">' . join(', ', $summary) . '.</div>',
      '#weight' => -10,
    ];

    return $build;
  }

}
