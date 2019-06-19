<?php

namespace Drupal\upgrade_status\Queue;

use Drupal\Core\Extension\Extension;
use Drupal\Core\Queue\DatabaseQueue;

class InspectableQueue extends DatabaseQueue {

  const TABLE_NAME = 'queue_inspectable';

  /**
   * {@inheritdoc}
   */
  public function createItem($data) {
    $try_again = FALSE;

    // Check if this job already exists in the queue. Do not add the job again
    // if it is already in this queue. Just return the existing job identifier.
    $job = $this->getItem($data);
    if ($job && !empty($job->item_id)) {
      return $job->item_id;
    }

    try {
      $id = $this->doCreateItem($data);
    }
    catch (\Exception $e) {
      // If there was an exception, try to create the table.
      if (!$try_again = $this->ensureTableExists()) {
        // If the exception happened for other reason than the missing table,
        // propagate the exception.
        throw $e;
      }
    }
    // Now that the table has been created, try again if necessary.
    if ($try_again) {
      $id = $this->doCreateItem($data);
    }
    return $id;
  }

  /**
   * Get an item from the queue based on the extension data.
   *
   * @param \Drupal\Core\Extension\Extension $extension
   *   The extension to search for in the queue.
   *
   * @return bool|object
   *   Returns false if the item is not in the queue currently. Returns the
   *   item object if it is in the queue.
   */
  public function getItem(Extension $data) {
    $path = $data->getPath();
    try {
      $query = $this
        ->connection
        ->select(self::TABLE_NAME)
        ->fields(self::TABLE_NAME);
      $query->condition('data', "%$path%", 'LIKE');
      $result = $query->execute()->fetchObject();

      return $result ? $result : FALSE;
    }
    catch (\Exception $e) {
      $this->catchException($e);

      // If there is no table there cannot be any items.
      return 0;
    }
  }

}
