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
    $job = $this->getItem($data);

    if ($job && array_key_exists('item_id', $job)) {
      return $job['item_id'];
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

  public function getItem(Extension $data) {
    $path = $data->getPath();
    try {
      // todo: refactor this.
      $result = $this
        ->connection
        ->query(
          sprintf("select * FROM %s WHERE data LIKE '%s'", self::TABLE_NAME, "%$path%"))
        ->fetch();

      return $result ? (array) $result : FALSE;
    }
    catch (\Exception $e) {
      $this->catchException($e);
      // If there is no table there cannot be any items.
      return 0;
    }
  }

}
