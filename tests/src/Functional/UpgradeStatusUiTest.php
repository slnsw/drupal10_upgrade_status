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
    $assert_session->buttonNotExists('Export full report');

    // Check links.
    $assert_session->linkExists('Single scan');
    $assert_session->linkNotExists('View errors');
    $assert_session->linkNotExists('Re-scan');

    // Status for every project should be 'Not scanned'.
    $status = $this->getSession()->getPage()->findAll('css', '*[class*=\'upgrade-status-summary-\'] *[class*=\'project-\'] td:nth-child(2)');
    foreach ($status as $project_status) {
      $this->assertSame('Not scanned', $project_status->getHtml());
    }

    // Check operations for custom projects.
    $custom_operations = $this->getSession()->getPage()->findAll('css', '*[class*=\'upgrade-status-summary-custom\'] *[class*=\'project-\'] td:nth-child(3)');
    foreach ($custom_operations as $operations) {
      $this->assertTrue($operations->hasLink('Single scan'));
      $this->assertFalse($operations->hasLink('View errors'));
      $this->assertFalse($operations->hasLink('Re-scan'));
      $this->assertFalse($operations->hasLink('Export'));
    }

    // Check operations for contributed projects.
    $contrib_operations = $this->getSession()->getPage()->findAll('css', '*[class*=\'upgrade-status-summary-custom\'] *[class*=\'project-\'] td:nth-child(4)');
    foreach ($contrib_operations as $operations) {
      $this->assertTrue($operations->hasLink('Single scan'));
      $this->assertFalse($operations->hasLink('View errors'));
      $this->assertFalse($operations->hasLink('Re-scan'));
      $this->assertFalse($operations->hasLink('Export'));
    }

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

    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();

    // Check button appearance.
    $assert_session->buttonExists('Restart full scan');
    $assert_session->buttonExists('Export full report');
    $assert_session->buttonNotExists('Start full scan');

    $upgrade_status_test_error = $page->find('css', '.upgrade-status-summary-custom .project-upgrade_status_test_error');
    $this->assertCount(3, $upgrade_status_test_error->findAll('css', 'td'));
    $this->assertSame('1 error', $upgrade_status_test_error->find('css', 'td:nth-child(2)')->getHtml());
    $upgrade_status_test_error->hasLink('View errors');
    $upgrade_status_test_error->hasLink('Re-scan');
    $upgrade_status_test_error->hasLink('Export');

    $upgrade_status_test_no_error = $page->find('css', '.upgrade-status-summary-custom .project-upgrade_status_test_no_error');
    $this->assertCount(3, $upgrade_status_test_no_error->findAll('css', 'td'));
    $this->assertSame('No known errors', $upgrade_status_test_no_error->find('css', 'td:nth-child(2)')->getHtml());
    $upgrade_status_test_no_error->hasLink('Re-scan');

    $upgrade_status_test_submodules = $page->find('css', '.upgrade-status-summary-custom .project-upgrade_status_test_submodules');
    $this->assertCount(3, $upgrade_status_test_submodules->findAll('css', 'td'));
    $this->assertSame('No known errors', $upgrade_status_test_submodules->find('css', 'td:nth-child(2)')->getHtml());
    $upgrade_status_test_submodules->hasLink('Re-scan');

    // Contributed modules should have one extra column because of possible available update.
    $upgrade_status_test_contrib_error = $page->find('css', '.upgrade-status-summary-contrib .project-upgrade_status_test_contrib_error');
    $this->assertCount(4, $upgrade_status_test_contrib_error->findAll('css', 'td'));
    $this->assertSame('1 error', $upgrade_status_test_contrib_error->find('css', 'td:nth-child(2)')->getHtml());
    $upgrade_status_test_contrib_error->hasLink('Re-scan');

    $upgrade_status_test_contrib_no_error = $page->find('css', '.upgrade-status-summary-contrib .project-upgrade_status_test_contrib_no_error');
    $this->assertCount(4, $upgrade_status_test_contrib_no_error->findAll('css', 'td'));
    $this->assertSame('No known errors', $upgrade_status_test_contrib_no_error->find('css', 'td:nth-child(2)')->getHtml());
    $upgrade_status_test_contrib_no_error->hasLink('View errors');
    $upgrade_status_test_contrib_no_error->hasLink('Re-scan');
    $upgrade_status_test_contrib_no_error->hasLink('Export');
  }

  /**
   * Test project collection's result by checking the amount of project.
   */
  public function testProjectCollector() {
    $this->drupalGet(Url::fromRoute('upgrade_status.report'));
    $page = $this->getSession()->getPage();

    $this->assertCount(3, $page->findAll('css', '.upgrade-status-summary-custom *[class*=\'project-\']'));
    $this->assertCount(4, $page->findAll('css', '.upgrade-status-summary-contrib *[class*=\'project-\']'));
  }

}
