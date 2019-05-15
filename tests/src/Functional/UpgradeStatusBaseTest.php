<?php

namespace Drupal\Tests\upgrade_status\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Defines shared functions used by some of the functional tests.
 */
abstract class UpgradeStatusBaseTest extends BrowserTestBase {

  /**
   * Perform the scan by running the queue.
   */
  protected function runQueue() {
    // Prepare for running the queue.
    /** @var \Drupal\Core\Queue\QueueWorkerManagerInterface $worker_manager */
    $worker_manager = \Drupal::service('plugin.manager.queue_worker');
    /** @var \Drupal\upgrade_status\Plugin\QueueWorker\UpgradeStatusDeprecationWorker $worker */
    $worker = $worker_manager->createInstance('upgrade_status_deprecation_worker');

    $queue_factory = \Drupal::service('queue.inspectable');
    /** @var \Drupal\upgrade_status\Queue\InspectableQueue $queue */
    $queue = $queue_factory->get('upgrade_status_deprecation_worker');

    // Run the queue.
    while ($queue->numberOfItems() !== 0 && ($item = $queue->claimItem())) {
      $worker->processItem($item->data);
      $queue->deleteItem($item);
    }
  }

}
