<?php

namespace Drupal\upgrade_status\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\State\StateInterface;
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
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('queue'),
      $container->get('plugin.manager.queue_worker'),
      $container->get('state')
    );
  }

  /**
   * Constructs a Drupal\upgrade_status\Controller\JobRunController.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queue
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queue_manager
   * @param \Drupal\Core\State\StateInterface $state
   */
  public function __construct(
    QueueFactory $queue,
    QueueWorkerManagerInterface $queue_manager,
    StateInterface $state
  ) {
    $this->queue = $queue->get('upgrade_status_deprecation_worker');
    $this->queueManager = $queue_manager;
    $this->state = $state;
  }

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

}
