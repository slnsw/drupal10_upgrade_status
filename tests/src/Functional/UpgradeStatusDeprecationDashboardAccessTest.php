<?php

namespace Drupal\Tests\upgrade_status\Functional;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests the accessibility of deprecation dashboard.
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

  /**
   * Tests access to deprecation dashboard with user that has the correct permission.
   */
  public function testDeprecationDashboardAccessPrivileged() {
    $this->drupalLogin($this->drupalCreateUser(['administer software updates']));
    $this->drupalGet(Url::fromRoute('upgrade_status.report'));
    $this->assertSession()->statusCodeEquals(200);
  }

}
