<?php

namespace Drupal\upgrade_status\Controller;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DeprecationListController extends ControllerBase {

  /**
   * The cache service.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * DeprecationListController constructor.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache service.
   */
  public function __construct(CacheBackendInterface $cache) {
    $this->cache = $cache;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('cache.upgrade_status')
    );
  }

  /**
   * Builds content for the error list page/popup.
   *
   * @param string $project_name
   *   Name of the project to list errors for.
   *
   * @return array
   *   Build array.
   */
  public function content(string $project_name) {
    $cache = $this->cache->get($project_name);

    // This project was not yet scanned or the scan results were removed.
    if (empty($cache)) {
      return [
        // @todo print human readable name and version
        '#title' => $project_name,
        'data' => [
          '#type' => 'markup',
          '#markup' => $this->t('No deprecation scanning data available.'),
        ],
      ];
    }

    $report = json_decode($cache->data, TRUE);
    $project_error_count = $report->totals->file_errors;

    // If this project had no known issues found, report that.
    if ($project_error_count === 0) {
      return [
        // @todo print human readable name and version
        '#title' => $project_name,
        'data' => [
          '#type' => 'markup',
          '#markup' => $this->t('No known issues found.'),
        ],
      ];
    }

    // Otherwise prepare list of errors in a table.
    $table = [
      '#type' => 'table',
      '#header' => [
        'filename' => $this->t('File name'),
        'line' => $this->t('Line'),
        'issue' => $this->t('Error'),
      ],
    ];

    foreach ($report['files'] as $filepath => $errors) {
      foreach ($errors['messages'] as $error) {
        $table[] = [
          // @todo shorten filename for better display
          'filename' => [
            '#type' => 'markup',
            '#markup' => $filepath,
          ],
          'line' => [
            '#type' => 'markup',
            '#markup' => $error['line'],
          ],
          'issue' => [
            '#type' => 'markup',
            // @todo figure out how to link known components with reflection
            // see https://www.php.net/manual/en/reflectionfunctionabstract.getfilename.php
            // see https://www.php.net/manual/en/reflectionclass.getfilename.php
            '#markup' => $error['message'],
          ],
        ];
      }
    }

    // @todo print human readable name and version
    // @todo include action button to export once/if available
    return [
      '#title' => $this->t('@project_name - detected issues (@count)', ['@project_name' => $project_name, '@count' => $project_error_count]),
      'description' => [
        '#type' => 'markup',
        '#markup' => $this->t('All known Drupal 9 compatibility errors in this project are listed below.'),
      ],
      'data' => $table,
    ];
  }

}
