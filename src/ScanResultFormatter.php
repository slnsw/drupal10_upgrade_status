<?php

namespace Drupal\upgrade_status;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Extension\Extension;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Format scan results for display or export.
 */
class ScanResultFormatter {

  use StringTranslationTrait;

  /**
   * Upgrade status scan result storage.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface
   */
  protected $scanResultStorage;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Constructs a \Drupal\upgrade_status\Controller\ScanResultFormatter.
   *
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $key_value_factory
   *   The key/value factory.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter service.
   */
  public function __construct(
    KeyValueFactoryInterface $key_value_factory,
    DateFormatterInterface $dateFormatter
  ) {
    $this->scanResultStorage = $key_value_factory->get('upgrade_status_scan_results');
    $this->dateFormatter = $dateFormatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('keyvalue'),
      $container->get('date.formatter')
    );
  }

  /**
   * Format results output for an extension.
   *
   * @return array
   *   Build array.
   */
  public function formatResult(Extension $extension) {
    $scan_result = $this->scanResultStorage->get($extension->getName());
    $info = $extension->info;
    $label = $info['name'] . (!empty($info['version']) ? ' ' . $info['version'] : '');

    // This project was not yet scanned or the scan results were removed.
    if (empty($scan_result)) {
      return [
        '#title' => $label,
        'data' => [
          '#type' => 'markup',
          '#markup' => $this->t('No deprecation scanning data available.'),
        ],
      ];
    }

    $report = json_decode($scan_result, TRUE);
    if (isset($report['data']['totals'])) {
      $project_error_count = $report['data']['totals']['file_errors'];
    }
    else {
      $project_error_count = 0;
    }

    $build = [
      '#title' => $this->t('@title - scanned on @date.', ['@title' => $label, '@date' => $this->dateFormatter->format($report['date'])]),
    ];

    // If this project had no known issues found, report that.
    if ($project_error_count === 0) {
      $build['data'] = [
        '#type' => 'markup',
        '#markup' => $this->t('No known issues found.'),
      ];
      return $build;
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

    foreach ($report['data']['files'] as $filepath => $errors) {
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
            elseif (in_array($namespace[1], ['KernelTests', 'FunctionalTests', 'FunctionalJavascriptTests', 'Tests'])) {
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

    $build['summary'] = [
      '#type' => '#markup',
      '#markup' => '<div class="error-description">' . $this->formatPlural($project_error_count, '@count known Drupal 9 compatibility error found.', '@count known Drupal 9 compatibility errors found.') . '</div>',
    ];
    $build['data'] = $table;
    $build['export'] = [
      '#type' => 'link',
      '#title' => $this->t('Export report'),
      '#weight' => 10,
      '#name' => 'export',
      '#url' => Url::fromRoute(
        'upgrade_status.export',
        [
          'type' => $extension->getType(),
          'project_machine_name' => $extension->getName(),
        ]
      ),
      '#attributes' => [
        'class' => [
          'button',
          'button--primary',
        ],
      ],
    ];

    return $build;
  }

}