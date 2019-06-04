<?php

namespace Drupal\Tests\upgrade_status\Functional;

use Drupal\Core\Url;

/**
 * Tests the placing a block.
 *
 * @group upgrade_status
 */
class UpgradeStatusAnalyseTestBase extends UpgradeStatusTestBase {

  /**
   * Modules to install.
   *
   * @var array
   */
  public static $modules = [
    'upgrade_status',
    'upgrade_status_test_error',
    'upgrade_status_test_no_error',
    'upgrade_status_test_submodules_a',
    'upgrade_status_test_contrib_error',
    'upgrade_status_test_contrib_no_error',
  ];

  public function testAnalyser() {
    $this->drupalLogin($this->drupalCreateUser(['administer software updates']));
    $this->drupalGet(Url::fromRoute('upgrade_status.report'));
    $page = $this->getSession()->getPage();
    $page->pressButton('Start full scan');
    $this->runQueue();
    /** @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface $key_value */
    $key_value = \Drupal::service('keyvalue')->get('upgrade_status_scan_results');

    // Check if the project has scan result in the keyValueStorage.

    $this->assertTrue($key_value->has('upgrade_status_test_error'));
    $this->assertTrue($key_value->has('upgrade_status_test_no_error'));
    $this->assertTrue($key_value->has('upgrade_status_test_submodules'));
    $this->assertTrue($key_value->has('upgrade_status_test_contrib_error'));
    $this->assertTrue($key_value->has('upgrade_status_test_contrib_no_error'));
    // The project upgrade_status_test_submodules_a shouldn't have scan result,
    // because it's a submodule of 'upgrade_status_test_submodules',
    // and we always want to run the scan on root modules.
    $this->assertFalse($key_value->has('upgrade_status_test_submodules_a'));

    $project = $key_value->get('upgrade_status_test_error');
    $this->assertNotEmpty($project);
    $report = json_decode($project, TRUE);
    $this->assertEquals(1, $report['totals']['file_errors']);
    $this->assertCount(1, $report['files']);
    $file = reset($report['files']);
    $message = $file['messages'][0];
    $this->assertEquals('Call to deprecated function menu_cache_clear_all().', $message['message']);
    $this->assertEquals(10, $message['line']);

    $project = $key_value->get('upgrade_status_test_no_error');
    $this->assertNotEmpty($project);
    $report = json_decode($project, TRUE);
    $this->assertEquals(0, $report['totals']['file_errors']);
    $this->assertCount(0, $report['files']);

    $project = $key_value->get('upgrade_status_test_contrib_error');
    $this->assertNotEmpty($project);
    $report = json_decode($project, TRUE);
    $this->assertEquals(1, $report['totals']['file_errors']);
    $this->assertCount(1, $report['files']);
    $file = reset($report['files']);
    $message = $file['messages'][0];
    $this->assertEquals('Call to deprecated function format_string().', $message['message']);
    $this->assertEquals(15, $message['line']);
  }

}
