<?php

namespace Drupal\upgrade_status\Form;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactory;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\upgrade_status\ProjectCollector;
use Drupal\upgrade_status\Queue\InspectableQueueFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

class UpgradeStatusForm extends FormBase {

  /**
   * The project collector service.
   *
   * @var \Drupal\upgrade_status\ProjectCollector
   */
  protected $projectCollector;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Upgrade status scan result storage.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface
   */
  protected $scanResultStorage;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Available releases store.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface|mixed
   */
  protected $releaseStore;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('upgrade_status.project_collector'),
      $container->get('state'),
      $container->get('keyvalue'),
      $container->get('date.formatter'),
      $container->get('keyvalue.expirable')
    );
  }

  /**
   * Constructs a Drupal\upgrade_status\Form\UpgradeStatusForm.
   *
   * @param \Drupal\upgrade_status\ProjectCollector $projectCollector
   *   The project collector service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $key_value_factory
   *   The key/value factory.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter service.
   * @param \Drupal\Core\KeyValueStore\KeyValueExpirableFactory $key_value_expirable
   *   The expirable key/value storage.
   */
  public function __construct(
    ProjectCollector $projectCollector,
    StateInterface $state,
    KeyValueFactoryInterface $key_value_factory,
    DateFormatterInterface $dateFormatter,
    KeyValueExpirableFactory $key_value_expirable
  ) {
    $this->projectCollector = $projectCollector;
    $this->state = $state;
    $this->scanResultStorage = $key_value_factory->get('upgrade_status_scan_results');
    $this->dateFormatter = $dateFormatter;
    $this->releaseStore = $key_value_expirable->get('update_available_releases');
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'drupal_upgrade_status_form';
  }

  /**
   * Form constructor.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'upgrade_status/upgrade_status.admin';

    // Gather project list grouped by custom and contrib projects.
    $projects = $this->projectCollector->collectProjects();

    // List custom project status first.
    $custom = ['#type' => 'markup', '#markup' => '<br /><strong>' . $this->t('No custom projects found.') . '</strong>'];
    if (count($projects['custom'])) {
      $custom = $this->buildProjectList($projects['custom']);
    }
    $form['custom'] = [
      '#type' => 'details',
      '#title' => $this->t('Custom modules and themes'),
      '#description' => $this->t('Custom code is specific to your site, and must be upgraded manually. <a href=":upgrade">Read more about how developers can upgrade their code to Drupal 9</a>.', [':upgrade' => 'https://www.drupal.org/documentation/9#deprecated']),
      '#open' => TRUE,
      '#attributes' => ['class' => ['upgrade-status-summary upgrade-status-summary-custom']],
      'data' => $custom,
      '#tree' => TRUE,
    ];

    // List contrib project status second.
    $contrib = ['#type' => 'markup', '#markup' => '<br /><strong>' . $this->t('No contributed projects found.') . '</strong>'];
    if (count($projects['contrib'])) {
      $contrib = $this->buildProjectList($projects['contrib'], TRUE);
    }
    $form['contrib'] = [
      '#type' => 'details',
      '#title' => $this->t('Contributed modules and themes'),
      '#description' => $this->t('Contributed code is available from drupal.org. Problems here may be partially resolved by updating to the latest version. <a href=":update">Read more about how to update contributed projects</a>.', [':update' => 'https://www.drupal.org/docs/8/update/update-modules']),
      '#open' => TRUE,
      '#attributes' => ['class' => ['upgrade-status-summary upgrade-status-summary-contrib']],
      'data' => $contrib,
      '#tree' => TRUE,
    ];

    $form['drupal_upgrade_status_form']['action']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Scan selected'),
      '#weight' => 2,
      '#button_type' => 'primary',
      '#name' => 'scan',
    ];

    $scan_date = $this->state->get('upgrade_status.last_scan');
    if ($scan_date) {
      $last_scan = $this->t('Report last ran on @date', ['@date' => $this->dateFormatter->format($scan_date)]);
      $form['drupal_upgrade_status_form']['date'] = [
        '#type' => 'markup',
        '#markup' => '<div class="report-date">' . $last_scan . '</div>',
      ];

      $form['drupal_upgrade_status_form']['action']['export'] = [
        '#type' => 'submit',
        '#value' => $this->t('Export full report'),
        '#weight' => 5,
        '#name' => 'export',
        '#submit' => [[$this, 'exportFullReport']],
      ];
    }

    return $form;
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

    $header = ['project' => ['data' => $this->t('Project'), 'class' => 'project-label']];
    if ($isContrib) {
      $header['update'] = ['data' => $this->t('Available update'), 'class' => 'update-info'];
    }
    $header['status'] = ['data' => $this->t('Status'), 'class' => 'status-info'];

    $build['data'] = [
      '#type' => 'tableselect',
      '#header' => $header,
      '#weight' => 20,
      '#options' => [],
    ];

    foreach ($projects as $name => $extension) {
      $scan_result = $this->scanResultStorage->get($name);
      $info = $extension->info;
      $label = $info['name'] . (!empty($info['version']) ? ' ' . $info['version'] : '');

      $update_cell = [
        'class' => 'update-info',
        'data' => $isContrib ? $this->t('Up to date') : '',
      ];

      if ($isContrib) {
        $projectUpdateData = $this->releaseStore->get($name);

        // @todo: trigger update information fetch.
        if (!isset($projectUpdateData['releases']) || is_null($projectUpdateData['releases'])) {
          $update_cell = ['class' => 'update-info', 'data' => $this->t('N/A')];
        }
        else {
          $latestRelease = reset($projectUpdateData['releases']);
          $latestVersion = $latestRelease['version'];

          if ($info['version'] !== $latestVersion) {
            $link = $projectUpdateData['link'] . '/releases/' . $latestVersion;
            $update_cell = [
              'class' => 'update-info',
              'data' => [
                '#type' => 'link',
                '#title' => $latestVersion,
                '#url' => Url::fromUri($link),
              ]
            ];
          }
        }
      }

      // If this project was not found in our keyvalue storage, it is not yet scanned, report that.
      if (empty($scan_result)) {
        $build['data']['#options'][$name] = [
          '#attributes' => ['class' => ['not-scanned', 'project-' . $name]],
          'project' => ['class' => 'project-label', 'data' => $label],
          'update' => $update_cell,
          'status' => ['class' => 'status-info', 'data' => $this->t('Not scanned')],
        ];
        $counters['not-scanned']++;
        continue;
      }

      // Unpack JSON of deprecations to display results.
      $report = json_decode($scan_result, TRUE);
      if (isset($report['totals'])) {
        $project_error_count = $report['totals']['file_errors'];
      }
      else {
        $project_error_count = 0;
      }

      // If this project had no known issues found, report that.
      if ($project_error_count === 0) {
        $build['data']['#options'][$name] = [
          '#attributes' => ['class' => ['no-known-error', 'project-' . $name]],
          'project' => ['class' => 'project-label', 'data' => $label],
          'update' => $update_cell,
          'status' => ['class' => 'status-info', 'data' => $this->t('No known errors')],
        ];
        $counters['no-known-error']++;
        continue;
      }
      // Unlike the other two counters, this counts the number of errors, not projects.
      $counters['known-errors'] += $project_error_count;

      // Finally this project had errors found, display them.
      $build['data']['#options'][$name] = [
        '#attributes' => ['class' => ['known-errors', 'project-' . $name]],
        'project' => ['class' => 'project-label', 'data' => $label],
        'update' => $update_cell,
        'status' => [
          'class' => 'status-info',
          'data' => [
            '#type' => 'link',
            '#title' => $this->formatPlural($project_error_count, '@count error', '@count errors'),
            '#url' => Url::fromRoute('upgrade_status.project', ['type' =>$extension->getType(), 'project_machine_name' => $name]),
            '#attributes' => [
              'class' => ['use-ajax'],
              'data-dialog-type' => 'modal',
              'data-dialog-options' => Json::encode([
                'width' => 1024,
                'height' => 568,
              ]),
            ],
          ]
        ],
      ];
    }

    if (!$isContrib) {
      // If the list is not for contrib, remove the update placeholder.
      foreach ($build['data']['#options'] as $name => &$row) {
        if (is_array($row)) {
          unset($row['update']);
        }
      }
    }

    // @todo Make the display better and more visual.
    /* $summary = [];
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
    ];*/

    return $build;
  }

  public function exportFullReport(array $form, FormStateInterface $form_state) {
    $uri = Url::fromRoute('upgrade_status.full_export');
    $form_state->setRedirectUrl($uri);
  }

  /**
   * Form submission handler.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $selected = [];

    // Gather project list grouped by custom and contrib projects.
    $projects = $this->projectCollector->collectProjects();

    $submitted = $form_state->getValues();
    foreach (['custom', 'contrib'] as $type) {
      foreach($submitted[$type]['data']['data'] as $project => $checked) {
        if ($checked !== 0) {
          $selected[] = [static::class . '::parseProject', [$projects[$type][$project]]];
        }
      }
    }
    if (empty($selected)) {
      return;
    }

    $batch = [
      'title' => t('Scanning projects'),
      'operations' => $selected,
      //'finished' => '\Drupal\batch_example\DeleteNode::deleteNodeExampleFinishedCallback',
    ];
    batch_set($batch);
    return;

    // Clear the queue and the stored data to run a new queue.
    $this->clearData();

    // Queue each project for deprecation scanning.
    $projects = $this->projectCollector->collectProjects();
    foreach ($projects['custom'] as $projectData) {
      $this->queue->createItem($projectData);
    }
    foreach ($projects['contrib'] as $projectData) {
      $this->queue->createItem($projectData);
    }

    $job_count = $this->queue->numberOfItems();
    $this->state->set('upgrade_status.number_of_jobs', $job_count);
  }

  /**
   * Analyse the codebase of an extension including all its sub-components.
   *
   * @param \Drupal\Core\Extension\Extension $extension
   *   The extension to analyse.
   * @param array $context
   *   Batch context.
   */
  public static function parseProject(Extension $extension, &$context) {
    \Drupal::service('upgrade_status.deprecation_analyser')->analyse($extension);
  }

  /**
   * Removes all items from queue and clears storage.
   */
  protected function clearData() {
    $this->state->delete('upgrade_status.number_of_jobs');
    $this->state->delete('upgrade_status.last_scan');
    $this->state->delete('upgrade_status.scanning_job_fatal');
    $this->queue->deleteQueue();
    $this->scanResultStorage->deleteAll();
  }

}
