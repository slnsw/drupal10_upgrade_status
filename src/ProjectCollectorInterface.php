<?php

namespace Drupal\upgrade_status;

/**
 * Provides an interface for project collection.
 */
interface ProjectCollectorInterface {

  /**
   * Collect projects of installed modules grouped by custom and contrib.
   *
   * @return array
   *   An array keyed by 'custom' and 'contrib' where each array is a list
   *   of projects grouped into that project group. Custom modules get a
   *   project name based on their topmost parent custom module and only
   *   that topmost custom module gets included in the list. Each item is
   *   a \Drupal\Core\Extension\Extension object in both arrays.
   */
  public function collectProjects();

}
