<?php

namespace Drupal\upgrade_status\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
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
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('queue'),
      $container->get('plugin.manager.queue_worker'),
      $container->get('state'),
      $container->get('upgrade_status.project_collector')
    );
  }

  /**
   * Constructs a Drupal\upgrade_status\Controller\JobRunController.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queue
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queue_manager
   * @param \Drupal\Core\State\StateInterface $state
   * @param \Drupal\upgrade_status\ProjectCollectorInterface $projectCollector
   */
  public function __construct(
    QueueFactory $queue,
    QueueWorkerManagerInterface $queue_manager,
    StateInterface $state,
    ProjectCollectorInterface $projectCollector
  ) {
    $this->queue = $queue->get('upgrade_status_deprecation_worker');
    $this->queueManager = $queue_manager;
    $this->state = $state;
    $this->projectCollector = $projectCollector;
  }

  /**
   * Claims the next project from the queue and runs the deprecation analyser on it.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  public function runNextJob() {
    $queue_worker = $this->queueManager->createInstance('upgrade_status_deprecation_worker');
    $job = $this->queue->claimItem();

    $job_count = $this->state->get('upgrade_status.number_of_jobs');

    if (!$job) {
      return new JsonResponse([
        'status' => TRUE,
        'percentage' => 100,
        'message' => $this->t('Completed @completed_jobs of @maximum_jobs',
          [
            '@completed_jobs' => $job_count,
            '@maximum_jobs' => $job_count,
          ]),
        'label' => 'Scan Complete.',
      ]);
    }

    $queue_worker->processItem($job->data);
    $this->queue->deleteItem($job);

    $completed_jobs = $job_count - $this->queue->numberOfItems();
    $message = $this->t('Completed @completed of @job_count.', ['@completed' => $completed_jobs, '@job_count' => $job_count]);
    $percent = ($completed_jobs / $job_count) * 100;
    $label = 'Scanning module ... ';

    return new JsonResponse([
      'status' => TRUE,
      'percentage' => $percent,
      'message' => $message,
      'label' => $label,
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
