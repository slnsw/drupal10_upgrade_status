<?php

namespace Drupal\upgrade_status\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\State\StateInterface;
use Drupal\upgrade_status\ProjectCollectorInterface;
use Drupal\upgrade_status\Queue\InspectableQueueFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

class JobRunController extends ControllerBase {

  /**
   * The queue service.
   *
   * @var \Drupal\Core\Queue\DatabaseQueue
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
   * Upgrade status scan result storage.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface
   */
  protected $scanResultStorage;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('queue.inspectable'),
      $container->get('plugin.manager.queue_worker'),
      $container->get('state'),
      $container->get('upgrade_status.project_collector'),
      $container->get('date.formatter'),
      $container->get('keyvalue'),
      $container->get('renderer'),
      $container->get('logger.channel.upgrade_status')
    );
  }

  /**
   * Constructs a Drupal\upgrade_status\Controller\JobRunController.
   *
   * @param \Drupal\upgrade_status\Queue\InspectableQueueFactory $queue
   *   The queue factory service.
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queue_manager
   *   The queue manager service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\upgrade_status\ProjectCollectorInterface $projectCollector
   *   The project collector service.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter service.
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $key_value_factory
   *   The key/value factory.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    InspectableQueueFactory $queue,
    QueueWorkerManagerInterface $queue_manager,
    StateInterface $state,
    ProjectCollectorInterface $projectCollector,
    DateFormatterInterface $dateFormatter,
    KeyValueFactoryInterface $key_value_factory,
    RendererInterface $renderer,
    LoggerInterface $logger
  ) {
    $this->queue = $queue->get('upgrade_status_deprecation_worker');
    $this->queueManager = $queue_manager;
    $this->state = $state;
    $this->projectCollector = $projectCollector;
    $this->dateFormatter = $dateFormatter;
    $this->scanResultStorage = $key_value_factory->get('upgrade_status_scan_results');
    $this->renderer = $renderer;
    $this->logger = $logger;
  }

  /**
   * Claims the next project from the queue and runs the deprecation analyser on it.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  public function runNextJob() {
    $remaining_count = $this->queue->numberOfItems();
    $all_count = $this->state->get('upgrade_status.number_of_jobs');

    $this->logger->notice($this->t('@remaining_count of @all_count queued jobs remain.',
      [
        '@remaining_count' => $remaining_count,
        '@all_count' => $all_count,
      ]
    ));

    if ($remaining_count) {
      $job = $this->queue->claimItem();
      if ($job) {
        // Process this job.
        $project = $job->data->getName();

        $this->logger->notice($this->t('Attempting to process @project_machine_name.', ['@project_machine_name' => $project]));
        $queue_worker = $this->queueManager->createInstance('upgrade_status_deprecation_worker');
        $queue_worker->processItem($job->data);
        $this->queue->deleteItem($job);
        $this->logger->notice($this->t('Removed @project_machine_name from the queue.', ['@project_machine_name' => $project]));

        $completed_jobs = $all_count - $this->queue->numberOfItems();
        $message = $this->t('Completed @completed_jobs of @job_count.', ['@completed_jobs' => $completed_jobs, '@job_count' => $all_count]);
        $percent = ($completed_jobs / $all_count) * 100;
        $label = $this->t('Scanning projects...');

        $selector = '.project-' . $project;
        $result = $this->scanResultStorage->get($project);

        if (empty($result)) {
          $operations = $this->projectCollector->getProjectOperations($project, $job->data->getType());
          $updates = [$selector, 'not-scanned', $this->t('Not scanned')];
        }
        else {
          $report = json_decode($result, TRUE);
          if (!empty($report['totals']['file_errors'])) {
            $operations = $this->projectCollector->getProjectOperations($project, $job->data->getType(), FALSE, TRUE);
            $updates = [
              $selector,
              'known-errors',
              $this->formatPlural(
                $report['totals']['file_errors'], '@count error', '@count errors'
              ),
            ];
          }
          else {
            $operations = $this->projectCollector->getProjectOperations($project, $job->data->getType(), FALSE);
            $updates = [
              $selector,
              'no-known-error',
              $this->t('No known errors'),
            ];
          }
        }
        $updates[] = $this->renderer->render($operations);

        $last_scan = '';
        if ($percent == 100) {
          $this->logger->notice($this->t('All scanning done.'));
          // Jobs finished, delete the state data we use to keep track of them.
          $this->state->delete('upgrade_status.number_of_jobs');
          $this->state->set('upgrade_status.last_scan', REQUEST_TIME);
          $last_scan = $this->t('Report last ran on @date', ['@date' => $this->dateFormatter->format(REQUEST_TIME)]);
        }

        return new JsonResponse([
          'status' => TRUE,
          'percentage' => floor($percent),
          'message' => $message,
          'label' => $label,
          'result' => $updates,
          'date' => $last_scan,
        ]);
      }
      else {
        // There appear to be jobs but we cannot claim any. Likely they are
        // being processed in cron for example. Continue the feedback loop.

        // todo: review
        // if the job failed because of an error, remove it from the queue.
        $this->queue->garbageCollection();
        $failedJob = $this->queue->claimItem();
        $this->logger->notice($this->t('Queue item @project_machine_name failed and removed.', ['@project_machine_name' => $failedJob->data->getName()]));
        $this->queue->deleteItem($failedJob);
        $completed_jobs = $all_count - $this->queue->numberOfItems();
        $message = $this->t('Completed @completed_jobs of @job_count.', ['@completed_jobs' => $completed_jobs, '@job_count' => $all_count]);
        $percent = ($completed_jobs / $all_count) * 100;
        $label = $this->t('Scanning projects...');
        return new JsonResponse([
          'status' => TRUE,
          'percentage' => $percent,
          'message' => $message,
          'label' => $label,
          'result' => [],
          'date' => '',
        ]);
      }
    }
    else {
      // There does not appear to be any jobs left, but the progress runner was invoked
      // anyway. Clean up the indicators for the progress runner so it can complete.
      $this->state->delete('upgrade_status.number_of_jobs');
      $this->state->set('upgrade_status.last_scan', REQUEST_TIME);
      $this->logger->notice($this->t('Final cleanup ran.'));

      return new JsonResponse([
        'status' => TRUE,
        'percentage' => 100,
        'message' => $this->t('Completed @completed_jobs of @job_count.',
          [
            '@completed_jobs' => $all_count,
            '@job_count' => $all_count,
          ]),
        'label' => $this->t('Scan complete.'),
        'result' => [],
        'date' => $this->t('Report last ran on @date', ['@date' => $this->dateFormatter->format(REQUEST_TIME)]),
      ]);
    }
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
    $this->scanResultStorage->set($project_machine_name, '');

    return [
      '#title' => $this->t('Add project to queue'),
      'data' => [
        '#type' => 'markup',
        '#markup' => $this->t('@extension added to the queue!', ['@extension' => $extension->getName()]),
      ],
    ];
  }

}
