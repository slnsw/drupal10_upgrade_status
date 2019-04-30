<?php

namespace Drupal\upgrade_status\Queue;

use Drupal\Core\Database\Connection;

class InspectableQueueFactory {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * Constructs this factory object.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The Connection object containing the key-value tables.
   */
  public function __construct(Connection $connection) {
    $this->connection = $connection;
  }

  /**
   * Constructs a new queue object for a given name.
   *
   * @param string $name
   *   The name of the collection holding key and value pairs.
   *
   * @return \Drupal\upgrade_status\Queue\InspectableQueue
   *   A key/value store implementation for the given $collection.
   */
  public function get($name) {
    return new InspectableQueue($name, $this->connection);
  }

}
