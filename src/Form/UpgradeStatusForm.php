<?php

namespace Drupal\upgrade_status\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Cache\CacheBackendInterface;
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
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('upgrade_status.project_collector'),
      $container->get('queue'),
      $container->get('state'),
      $container->get('cache.upgrade_status'),
      $container->get('form_builder')
    );
  }

  /**
   * Constructs a Drupal\upgrade_status\Form\UpgradeStatusForm.
   *
   * @param \Drupal\upgrade_status\ProjectCollector $projectCollector
   * @param \Drupal\Core\Queue\QueueFactory $queue
   * @param \Drupal\Core\State\StateInterface $state
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   */
  public function __construct(
    ProjectCollector $projectCollector,
    QueueFactory $queue,
    StateInterface $state,
    CacheBackendInterface $cache,
    FormBuilder $formBuilder
  ) {
    $this->projectCollector = $projectCollector;
    $this->queue = $queue->get('upgrade_status_deprecation_worker');
    $this->state = $state;
    $this->cache = $cache;
    $this->formBuilder = $formBuilder;
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
        '#message' => ['#markup' => $this->t('Completed @completed_jobs of @job_count.', ['@completed_jobs' => $completed_jobs, '@job_count' => $job_count])],
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
      ];
      $form['drupal_upgrade_status_form']['action']['cancel'] = [
        '#type' => 'button',
        '#value' => $this->t('Cancel'),
        '#weight' => 10,
        '#name' => 'cancel',
        '#ajax' => [
          'callback' => '::loadCancelForm',
        ],
      ];
      return $form;
    }

    // If there was a prior scan, reflect that in the button label.
    $button_label = $this->state->get('upgrade_status.last_scan') ? $this->t('Restart full scan') : $this->t('Start full scan');
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
    ];

    return $form;
  }

  protected function processNextJobUrl() {
    return Url::fromRoute('upgrade_status.run_job')->toString(TRUE)->getGeneratedUrl();
  }

  public function loadCancelForm() {
    $response = new AjaxResponse();

    $modal_form = $this
      ->formBuilder
      ->getForm(CancelScanForm::class);

    $form['#attached']['library'][] = 'core/drupal.dialog.ajax';
    $response->setAttachments($form['#attached']);

    $options = [
      'width' => 1024,
      'height' => 568,
    ];

    $title = t('Cancel Scan?');

    $response->addCommand(new OpenModalDialogCommand($title, $modal_form, $options));
    return $response;
  }

  public function cancelScan() {
    $this->queue->deleteQueue();
    $this->state->delete('upgrade_status.number_of_jobs');
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
      \Drupal::state()->delete('upgrade_status.number_of_jobs');
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
