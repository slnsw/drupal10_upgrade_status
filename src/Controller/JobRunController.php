<?php

namespace Drupal\upgrade_status\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
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
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('queue'),
      $container->get('plugin.manager.queue_worker')
    );
  }

  /**
   * JobRunController constructor.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queue
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queue_manager
   */
  public function __construct(
    QueueFactory $queue,
    QueueWorkerManagerInterface $queue_manager
  ) {
    $this->queue = $queue->get('upgrade_status_deprecation_worker');
    $this->queueManager = $queue_manager;
  }

  public function runNextJob() {
    $queue_worker = $this->queueManager->createInstance('upgrade_status_deprecation_worker');
    $job = $this->queue->claimItem();
    $queue_worker->processItem($job->data);
    $this->queue->deleteItem($job);

    return new JsonResponse('Scanning module');
  }

}
