<?php

namespace Drupal\Tests\upgrade_status\Functional;

use Drupal\Core\Url;

/**
 * Tests the placing a block.
 *
 * @group upgrade_status
 */
class UpgradeStatusUiTest extends UpgradeStatusBaseTest {

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

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();
    $this->drupalLogin($this->drupalCreateUser(['administer software updates']));
  }

  /**
   * Test the user interface before running a scan.
   */
  public function testUiBeforeScan() {
    $this->drupalGet(Url::fromRoute('upgrade_status.report'));

    $assert_session = $this->assertSession();

    // Check buttons.
    $assert_session->buttonExists('Start full scan');
    $assert_session->buttonNotExists('Restart full scan');
    $assert_session->buttonNotExists('Cancel');

    // Check links.
    $assert_session->linkExists('Single scan');
    $assert_session->linkNotExists('View errors');
    $assert_session->linkNotExists('Re-scan');

    $this->assertText('Not scanned');
    $this->assertNoText('In queue');
    $this->assertNoText('No known errors');
  }

  /**
   * Test the user interface after running a scan.
   */
  public function testUiAfterScan() {
    $this->drupalGet(Url::fromRoute('upgrade_status.report'));
    $page = $this->getSession()->getPage();
    $page->pressButton('Start full scan');
    $this->runQueue();

    $this->drupalGet(Url::fromRoute('upgrade_status.report'));

    $assert_session = $this->assertSession();

    // Check buttons.
    $assert_session->buttonExists('Restart full scan');
    $assert_session->buttonExists('Export full report');
    $assert_session->buttonNotExists('Start full scan');

    // Check links.
    $assert_session->linkExists('View errors');
    $assert_session->linkExists('Re-scan');
    $assert_session->linkNotExists('Single scan');

    // Check project statuses.
    $this->assertText('No known errors');
    $this->assertText('1 error');
    $this->assertNoText('Not scanned');
    $this->assertNoText('In queue');
  }

  /**
   * Test project collection's result by checking the amount of project.
   */
  public function testProjectCollector() {
    $this->drupalGet(Url::fromRoute('upgrade_status.report'));

    $this->assertEqual(3, count($this->getSession()->getPage()->findAll('css', '.upgrade-status-summary-custom *[class*=\'project-\']')));
    $this->assertEqual(4, count($this->getSession()->getPage()->findAll('css', '.upgrade-status-summary-contrib *[class*=\'project-\']')));
  }

}
