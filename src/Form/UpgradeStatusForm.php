<?php

namespace Drupal\upgrade_status\Form;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactory;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\upgrade_status\ProjectCollector;
use Symfony\Component\DependencyInjection\ContainerInterface;

class UpgradeStatusForm extends FormBase {

  /**
   * The project collector service.
   *
   * @var \Drupal\upgrade_status\ProjectCollector
   */
  protected $projectCollector;

  /**
   * Upgrade status scan result storage.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface
   */
  protected $scanResultStorage;

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
      $container->get('keyvalue'),
      $container->get('keyvalue.expirable')
    );
  }

  /**
   * Constructs a Drupal\upgrade_status\Form\UpgradeStatusForm.
   *
   * @param \Drupal\upgrade_status\ProjectCollector $projectCollector
   *   The project collector service.
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $key_value_factory
   *   The key/value factory.
   * @param \Drupal\Core\KeyValueStore\KeyValueExpirableFactory $key_value_expirable
   *   The expirable key/value storage.
   */
  public function __construct(
    ProjectCollector $projectCollector,
    KeyValueFactoryInterface $key_value_factory,
    KeyValueExpirableFactory $key_value_expirable
  ) {
    $this->projectCollector = $projectCollector;
    $this->scanResultStorage = $key_value_factory->get('upgrade_status_scan_results');
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
      '#description' => $this->t('Custom code is specific to your site, and must be upgraded manually. <a href=":upgrade">Read more about how developers can upgrade their code to Drupal 9</a>.', [':upgrade' => 'https://www.drupal.org/docs/9/how-drupal-9-is-made-and-what-is-included/how-and-why-we-deprecate-on-the-way-to-drupal-9']),
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
    ];
    $form['drupal_upgrade_status_form']['action']['export'] = [
      '#type' => 'submit',
      '#value' => $this->t('Export selected'),
      '#weight' => 5,
      '#submit' => [[$this, 'exportReport']],
    ];

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
      $label_cell = [
        'data' => [
          '#type' => 'html_tag',
          '#tag' => 'label',
          '#value' => $label,
          '#attributes' => [
            'for' => 'edit-' . ($isContrib ? 'contrib' : 'custom') . '-data-data-' . str_replace('_', '-', $name),
          ],
        ],
        'class' => 'project-label',
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
          'project' => $label_cell,
          'update' => $update_cell,
          'status' => ['class' => 'status-info', 'data' => $this->t('Not scanned')],
        ];
        $counters['not-scanned']++;
        continue;
      }

      // Unpack JSON of deprecations to display results.
      $report = json_decode($scan_result, TRUE);
      if (isset($report['data']['totals'])) {
        $project_error_count = $report['data']['totals']['file_errors'];
      }
      else {
        $project_error_count = 0;
      }

      // If this project had no known issues found, report that.
      if ($project_error_count === 0) {
        $build['data']['#options'][$name] = [
          '#attributes' => ['class' => ['no-known-error', 'project-' . $name]],
          'project' => $label_cell,
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
        'project' => $label_cell,
        'update' => $update_cell,
        'status' => [
          'class' => 'status-info',
          'data' => [
            '#type' => 'link',
            '#title' => $this->formatPlural($project_error_count, '@count error', '@count errors'),
            '#url' => Url::fromRoute('upgrade_status.project', ['type' => $extension->getType(), 'project_machine_name' => $name]),
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

  /**
   * Form submission handler.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $operations = [];
    $projects = $this->projectCollector->collectProjects();
    $submitted = $form_state->getValues();

    foreach (['custom', 'contrib'] as $type) {
      foreach($submitted[$type]['data']['data'] as $project => $checked) {
        if ($checked !== 0) {
          // If the checkbox was checked, add a batch operation.
          $operations[] = [static::class . '::parseProject', [$projects[$type][$project]]];
        }
      }
    }
    if (!empty($operations)) {
      $batch = [
        'title' => $this->t('Scanning projects'),
        'operations' => $operations,
      ];
      batch_set($batch);
    }
  }

  /**
   * Form submission handler.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function exportReport(array &$form, FormStateInterface $form_state) {
    $uri = Url::fromRoute('upgrade_status.full_export');
    $form_state->setRedirectUrl($uri);
  }

  /**
   * Batch callback to analyse a project.
   *
   * @param \Drupal\Core\Extension\Extension $extension
   *   The extension to analyse.
   * @param array $context
   *   Batch context.
   */
  public static function parseProject(Extension $extension, &$context) {
    $context['message'] = t('Completed @project.', ['@project' => $extension->getName()]);
    \Drupal::service('upgrade_status.deprecation_analyser')->analyse($extension);
  }

}
