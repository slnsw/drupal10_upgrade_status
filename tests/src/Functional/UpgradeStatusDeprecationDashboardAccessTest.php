<?php

namespace Drupal\Tests\upgrade_status\Functional;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests the placing a block.
 *
 * @group upgrade_status
 */
class UpgradeStatusDeprecationDashboardAccessTest extends BrowserTestBase {

  /**
   * Modules to install.
   *
   * @var array
   */
  public static $modules = ['upgrade_status'];

  /**
   * Tests deprecation dashboard without permission.
   */
  public function testDeprecationDashboardAccessUnprivileged() {
    $this->drupalGet(Url::fromRoute('upgrade_status.report'));
    $this->assertSession()->statusCodeEquals(403);
  }

}
