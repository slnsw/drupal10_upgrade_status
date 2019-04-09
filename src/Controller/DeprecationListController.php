<?php

namespace Drupal\upgrade_status\Controller;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\upgrade_status\ProjectCollectorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DeprecationListController extends ControllerBase {

  /**
   * The cache service.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The project collector service.
   *
   * @var \Drupal\upgrade_status\ProjectCollectorInterface
   */
  protected $projectCollector;

  /**
   * Constructs a \Drupal\upgrade_status\Controller\DeprecationListController.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache service.
   * @param \Drupal\upgrade_status\ProjectCollectorInterface $project_collector
   *   The project collector service.
   */
  public function __construct(CacheBackendInterface $cache, ProjectCollectorInterface $project_collector) {
    $this->cache = $cache;
    $this->projectCollector = $project_collector;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('cache.upgrade_status'),
      $container->get('upgrade_status.project_collector')
    );
  }

  /**
   * Builds content for the error list page/popup.
   *
   * @param string $type
   *   Type of the extension, it can be either 'module' or 'theme.
   * @param string $project_machine_name
   *   The machine name of the project.
   *
   * @return array
   *   Build array.
   */
  public function content(string $type, string $project_machine_name) {
    $cache = $this->cache->get($project_machine_name);
    $extension = $this->projectCollector->loadProject($type, $project_machine_name);
    $info = $extension->info;
    $label = $info['name'] . (!empty($info['version']) ? ' ' . $info['version'] : '');

    // This project was not yet scanned or the scan results were removed.
    if (empty($cache)) {
      return [
        '#title' => $label,
        'data' => [
          '#type' => 'markup',
          '#markup' => $this->t('No deprecation scanning data available.'),
        ],
      ];
    }

    $report = json_decode($cache->data, TRUE);
    if (isset($report['totals'])) {
      $project_error_count = $report['totals']['file_errors'];
    }
    else {
      $project_error_count = 0;
    }

    // If this project had no known issues found, report that.
    if ($project_error_count === 0) {
      return [
        '#title' => $label,
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

        // Remove the Drupal root directory and allow paths and namespaces to wrap.
        // Emphasize filename as it may show up in the middle of the info.
        $short_path = str_replace(DRUPAL_ROOT . '/', '', $filepath);
        $short_path = str_replace('/', '&#8203;/&#8203;', $short_path);
        if (strpos($short_path, 'in context of')) {
          $short_path = preg_replace('!/([^/]+)( \(in context of)!', '/<strong>\1</strong>\2', $short_path);
          $short_path = str_replace('\\', '&#8203;\\&#8203;', $short_path);
        }
        else {
          $short_path = preg_replace('!/([^/]+)$!', '/<strong>\1</strong>', $short_path);
        }

        // @todo could be more accurate with reflection but not sure it is even possible as the reflected
        //   code may not be in the runtime at this point (eg. functions in include files)
        //   see https://www.php.net/manual/en/reflectionfunctionabstract.getfilename.php
        //   see https://www.php.net/manual/en/reflectionclass.getfilename.php

        // Link to documentation for a function in this specific Drupal version.
        $api_version = preg_replace('!^(8\.\d+)\..+$!', '\1', \Drupal::VERSION) . '.x';
        $api_link = 'https://api.drupal.org/api/drupal/' . $api_version . '/search/';
        $formatted_error = preg_replace('!deprecated function ([^(]+)\(\)!', 'deprecated function <a target="_blank" href="' . $api_link . '\1">\1()</a>', $error['message']);

        // Replace deprecated class links.
        if (preg_match('!class (Drupal\\\\.+)\.!', $formatted_error, $found)) {
          if (preg_match('!Drupal\\\\([a-z_0-9A-Z]+)\\\\(.+)$!', $found[1], $namespace)) {

            $path_parts = explode('\\', $namespace[2]);
            $class = array_pop($path_parts);
            if (in_array($namespace[1], ['Component', 'Core'])) {
              $class_file = 'core!lib!Drupal!' . $namespace[1];
            }
            elseif ($namespace[1] == 'Tests') {
              $module = array_shift($path_parts);
              $class_file = 'core!modules!' . $module . '!tests!src';
            }
            elseif (in_array($namespace[1], ['KernelTests', 'FunctionalTests', 'FunctionalJavascriptTests'])) {
              $class_file = 'core!tests!Drupal!' . $namespace[1];
            }
            else {
              $class_file = 'core!modules!' . $namespace[1] . '!src';
            }

            if (count($path_parts)) {
              $class_file .= '!' . join('!', $path_parts);
            }

            $class_file .= '!' . $class . '.php';
            $api_link = 'https://api.drupal.org/api/drupal/' . $class_file . '/class/' . $class . '/' . $api_version;
            $formatted_error = str_replace($found[1], '<a target="_blank" href="' . $api_link . '">' . $found[1] . '</a>', $formatted_error);
          }
        }

        // Allow error messages to wrap.
        $formatted_error = str_replace('\\', '&#8203;\\&#8203;', $formatted_error);

        $table[] = [
          'filename' => [
            '#type' => 'markup',
            '#markup' => $short_path,
          ],
          'line' => [
            '#type' => 'markup',
            '#markup' => $error['line'],
          ],
          'issue' => [
            '#type' => 'markup',
            '#markup' => $formatted_error,
          ],
        ];
      }
    }

    // @todo include action button to export once/if available
    return [
      '#title' => $label,
      'description' => [
        '#type' => '#markup',
        '#markup' => '<div class="error-description">' . $this->formatPlural($project_error_count, '@count known compatibility Drupal 9 error.', '@count known Drupal 9 compatibility errors found.') . '</div>',
        ],
      'data' => $table,
    ];
  }

}
