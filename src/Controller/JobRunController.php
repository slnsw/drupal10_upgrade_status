<?php

namespace Drupal\upgrade_status\Controller;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\State\StateInterface;
use Drupal\upgrade_status\ProjectCollectorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

class JobRunController extends ControllerBase {

  /**
   * The queue service.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queue;

  /**
   * The queue plugin manager.
   *
   * @var \Drupal\Core\Queue\QueueWorkerManagerInterface
   */
  protected $queueManager;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The project collector service.
   *
   * @var \Drupal\upgrade_status\ProjectCollector
   */
  protected $projectCollector;

  /**
   * The date formatter service.
   *
   * @var Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The cache service.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('queue'),
      $container->get('plugin.manager.queue_worker'),
      $container->get('state'),
      $container->get('upgrade_status.project_collector'),
      $container->get('date.formatter'),
      $container->get('cache.upgrade_status'),
      $container->get('renderer')
    );
  }

  /**
   * Constructs a Drupal\upgrade_status\Controller\JobRunController.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queue
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queue_manager
   * @param \Drupal\Core\State\StateInterface $state
   * @param \Drupal\upgrade_status\ProjectCollectorInterface $projectCollector
   * @param \Drupal\Core\Datetime\DateFormatterInterface
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   */
  public function __construct(
    QueueFactory $queue,
    QueueWorkerManagerInterface $queue_manager,
    StateInterface $state,
    ProjectCollectorInterface $projectCollector,
    DateFormatterInterface $dateFormatter,
    CacheBackendInterface $cache,
    RendererInterface $renderer
  ) {
    $this->queue = $queue->get('upgrade_status_deprecation_worker');
    $this->queueManager = $queue_manager;
    $this->state = $state;
    $this->projectCollector = $projectCollector;
    $this->dateFormatter = $dateFormatter;
    $this->cache = $cache;
    $this->renderer = $renderer;
  }

  /**
   * Claims the next project from the queue and runs the deprecation analyser on it.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  public function runNextJob() {
    $job_count = $this->state->get('upgrade_status.number_of_jobs');

    // @todo not being able to claim a job may mean the last one is or
    //   last ones are still running.
    $job = $this->queue->claimItem();
    if (!$job) {
      // Jobs finished, delete the state data we use to keep track of them.
      $this->state->delete('upgrade_status.number_of_jobs');
      $this->state->set('upgrade_status.last_scan', REQUEST_TIME);

      return new JsonResponse([
        'status' => TRUE,
        'percentage' => 100,
        'message' => $this->t('Completed @completed_jobs of @job_count.',
          [
            '@completed_jobs' => $job_count,
            '@job_count' => $job_count,
          ]),
        'label' => $this->t('Scan complete.'),
        'last_scan' => $this->t('Report last ran on @date', ['@date' => $this->dateFormatter->format(REQUEST_TIME)]),
      ]);
    }

    $queue_worker = $this->queueManager->createInstance('upgrade_status_deprecation_worker');
    $queue_worker->processItem($job->data);

    $this->queue->deleteItem($job);

    $completed_jobs = $job_count - $this->queue->numberOfItems();
    $message = $this->t('Completed @completed_jobs of @job_count.', ['@completed_jobs' => $completed_jobs, '@job_count' => $job_count]);
    $percent = ($completed_jobs / $job_count) * 100;
    $label = $this->t('Scanning projects...');

    $project = $job->data->getName();
    $selector = '.project-' . $project;
    $result = $this->cache->get($project);

    if (empty($result)) {
      $operations = $this->projectCollector->getProjectOperations($project, $job->data->getType());
      $result = [$selector, 'not-scanned', $this->t('To be scanned')];
    }
    else {
      $report = json_decode($result->data, TRUE);
      if (!empty($report['totals']['file_errors'])) {
        $operations = $this->projectCollector->getProjectOperations($project, $job->data->getType(), FALSE, TRUE);
        $result = [$selector, 'known-errors', $this->formatPlural($report['totals']['file_errors'], '@count error', '@count errors')];
      }
      else {
        $operations = $this->projectCollector->getProjectOperations($project, $job->data->getType(), FALSE);
        $result = [$selector, 'no-known-error', $this->t('No known errors')];
      }
    }
    $result[] = $this->renderer->render($operations);

    return new JsonResponse([
      'status' => TRUE,
      'percentage' => $percent,
      'message' => $message,
      'label' => $label,
      'result' => $result,
    ]);
  }

  /**
   * Appends a specific project to the queue.
   *
   * @param \Drupal\upgrade_status\Controller\string $type
   *   Type of the extension, it can be either 'module' or 'theme.
   * @param \Drupal\upgrade_status\Controller\string $project_machine_name
   *   The machine name of the project.
   *
   * @return array
   *   Build array.
   */
  public function addProjectToQueue(string $type, string $project_machine_name) {
    $extension = $this->projectCollector->loadProject($type, $project_machine_name);

    $this->queue->createItem($extension);
    $number_of_jobs = $this->state->get('upgrade_status.number_of_jobs');
    $number_of_jobs++;
    $this->state->set('upgrade_status.number_of_jobs', $number_of_jobs);

    return [
      '#title' => $this->t('Add project to queue'),
      'data' => [
        '#type' => 'markup',
        '#markup' => $this->t('@extension added to the queue!', ['@extension' => $extension->getName()]),
      ],
    ];
  }

}
