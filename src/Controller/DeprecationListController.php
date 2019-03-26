<?php

namespace Drupal\upgrade_status\Controller;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DeprecationListController extends ControllerBase {

  /**
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

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

  public function getDeprecationReportForProject(string $project_name) {
    $cache = $this->cache->get($project_name);

    if (empty($cache)) {
      return [
        '#title' => $project_name,
        'data' => [
          '#type' => 'markup',
          '#markup' => 'Empty',
        ],
      ];
    }

    $deprecationReportRaw = $cache->data;
    $deprecationReport = json_decode($deprecationReportRaw, TRUE);

    $headers = [
      'filename' => $this->t('Filename'),
      'line' => $this->t('Line'),
      'issue' => $this->t('Issue'),
    ];

    $table = [
      '#type' => 'table',
      '#header' => $headers,
    ];

    if (isset($deprecationReport['files'])) {
      foreach ($deprecationReport['files'] as $filepath => $errors) {
        foreach ($errors['messages'] as $deprecationError) {
          $table[] = [
            'filename' => [
              '#type' => 'markup',
              '#markup' => $filepath,
            ],
            'line' => [
              '#type' => 'markup',
              '#markup' => $deprecationError['line'],
            ],
            'issue' => [
              '#type' => 'markup',
              '#markup' => $deprecationError['message'],
            ],
          ];
        }
      }
    }

    return [
      '#title' => sprintf('%s - detected issues ( %d )', $project_name, $deprecationReport['totals']['file_errors']),
      'description' => [
        '#type' => 'markup',
        '#markup' => $this->t('This list contains all detected Drupal 9 compatibility errors in this code.'),
      ],
      'data' => $table,
    ];
  }

}
