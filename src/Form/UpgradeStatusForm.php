<?php

namespace Drupal\upgrade_status\Form;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormBuilder;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Queue\QueueFactory;
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
   * The queue service.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queue;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The cache service.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The form builder service.
   *
   * @var \Drupal\Core\Form\FormBuilder
   */
  protected $formBuilder;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('upgrade_status.project_collector'),
      $container->get('queue'),
      $container->get('state'),
      $container->get('cache.upgrade_status'),
      $container->get('form_builder'),
      $container->get('date.formatter')
    );
  }

  /**
   * Constructs a Drupal\upgrade_status\Form\UpgradeStatusForm.
   *
   * @param \Drupal\upgrade_status\ProjectCollector $projectCollector
   *   The project collector service.
   * @param \Drupal\Core\Queue\QueueFactory $queue
   *   The queue service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache service.
   * @param \Drupal\Core\Form\FormBuilder $formBuilder
   *   The form builder service.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter service.
   */
  public function __construct(
    ProjectCollector $projectCollector,
    QueueFactory $queue,
    StateInterface $state,
    CacheBackendInterface $cache,
    FormBuilder $formBuilder,
    DateFormatterInterface $dateFormatter
  ) {
    $this->projectCollector = $projectCollector;
    $this->queue = $queue->get('upgrade_status_deprecation_worker');
    $this->state = $state;
    $this->cache = $cache;
    $this->formBuilder = $formBuilder;
    $this->dateFormatter = $dateFormatter;
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
    // If project scanning started, display the progress bar.
    if ($job_count = $this->state->get('upgrade_status.number_of_jobs')) {

      $completed_jobs = $job_count - $this->queue->numberOfItems();
      $process_job_url = $this->processNextJobUrl();
      $percent = ($completed_jobs / $job_count) * 100;

      // @todo content refreshes on page to show scanned results
      $form['drupal_upgrade_status_form']['progress_bar'] = [
        '#theme' => 'progress_bar',
        '#percent' => $percent,
        '#status' => TRUE,
        '#label' => $this->t('Scanning projects...'),
        '#message' => [
          '#markup' => $this->t('Completed @completed_jobs of @job_count.',
            [
              '@completed_jobs' => $completed_jobs,
              '@job_count' => $job_count,
            ]),
        ],
        '#weight' => 0,
        // @todo This progress bar requires JavaScript, document it.
        '#attached' => [
          'drupalSettings' => [
            'batch' => [
              'uri' => $process_job_url,
            ],
          ],
          'library' => [
            'upgrade_status/upgrade_status.queue',
          ],
        ],
      ];

      $form['drupal_upgrade_status_form']['action']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Restart full scan'),
        '#weight' => 2,
        '#button_type' => 'primary',
        '#disabled' => $percent !== 100 ? TRUE : FALSE,
        '#name' => 'scan',
      ];
      $form['drupal_upgrade_status_form']['action']['export'] = [
        '#type' => 'submit',
        '#value' => $this->t('Export full report'),
        '#weight' => 5,
        '#disabled' => TRUE,
        '#name' => 'export',
        '#submit' => [[$this, 'exportFullReport']],
      ];
      // @todo: this shouldn't be a link.
      $form['drupal_upgrade_status_form']['action']['cancel'] = [
        '#type' => 'link',
        '#title' => $this->t('Cancel'),
        '#weight' => 10,
        '#name' => 'cancel',
        '#url' => Url::fromRoute('upgrade_status.cancel_form_controller'),
        '#attributes' => [
          'class' => [
            'use-ajax',
            'button',
          ],
        ],
      ];

      $form['drupal_upgrade_status_form']['#attached']['library'][] = 'core/drupal.dialog.ajax';

      return $form;
    }

    $scan_date = $this->state->get('upgrade_status.last_scan');
    if ($scan_date) {
      $last_scan = $this->t('Report last ran on @date', ['@date' => $this->dateFormatter->format($scan_date)]);
      $form['drupal_upgrade_status_form']['date'] = [
        '#type' => 'markup',
        '#markup' => '<div class="report-date">' . $last_scan . '</div>',
      ];
    }

    // If there was a prior scan, reflect that in the button label.
    $button_label = $scan_date ? $this->t('Restart full scan') : $this->t('Start full scan');
    $form['drupal_upgrade_status_form']['action']['submit'] = [
      '#type' => 'submit',
      '#value' => $button_label,
      '#weight' => 0,
      '#button_type' => 'primary',
      '#name' => 'scan',
    ];
    $form['drupal_upgrade_status_form']['action']['export'] = [
      '#type' => 'submit',
      '#value' => $this->t('Export full report'),
      '#weight' => 5,
      '#name' => 'export',
      '#submit' => [[$this, 'exportFullReport']],
    ];

    return $form;
  }

  protected function processNextJobUrl() {
    return Url::fromRoute('upgrade_status.run_job')->toString(TRUE)->getGeneratedUrl();
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
    $button = $form_state->getTriggeringElement();

    if ($button['#name'] == 'cancel') {
      // Cancel all queued items and delete the queue state metadata.
      $this->queue->deleteQueue();
      $this->state->delete('upgrade_status.number_of_jobs');
    }
    else {
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
  }

  /**
   * Removes all items from queue and clears cache.
   */
  protected function clearData() {
    $this->queue->deleteQueue();
    $this->cache->deleteAll();
  }

}
