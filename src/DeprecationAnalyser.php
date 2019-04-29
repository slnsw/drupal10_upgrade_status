<?php

namespace Drupal\upgrade_status;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\Extension;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Exception;
use Nette\Neon\Neon;
use PHPStan\Command\AnalyseApplication;
use PHPStan\Command\CommandHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

class DeprecationAnalyser implements DeprecationAnalyserInterface {

  use StringTranslationTrait;

  /**
   * The error format to use to retrieve the report from PHPStan.
   *
   * @var string
   */
  const ERROR_FORMAT = 'json';

  /**
   * Upgrade status scan result storage.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface
   */
  protected $scanResultStorage;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Symfony Console input interface.
   *
   * @var \Symfony\Component\Console\Input\StringInput
   */
  protected $inputInterface;

  /**
   * Symfony Console output interface.
   *
   * @var \Symfony\Component\Console\Output\BufferedOutput
   */
  protected $outputInterface;

  /**
   * Path to the PHPStan neon configuration.
   *
   * @var string
   */
  protected $phpstanNeonPath;

  /**
   * @var string
   */
  protected $upgradeStatusTemporaryDirectory;

  /**
   * A configuration object containing upgrade_status settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * Constructs a \Drupal\upgrade_status\DeprecationAnalyser.
   *
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $key_value_factory
   *   The key/value factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Symfony\Component\Console\Input\StringInput $input
   *   The Symfony Console input interface.
   * @param \Symfony\Component\Console\Output\BufferedOutput $output
   *   The Symfony Console output interface.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    KeyValueFactoryInterface $key_value_factory,
    LoggerInterface $logger,
    StringInput $input,
    BufferedOutput $output,
    ConfigFactoryInterface $config_factory
  ) {
    $this->scanResultStorage = $key_value_factory->get('upgrade_status_scan_results');
    // Log errors to an upgrade status logger channel.
    $this->logger = $logger;
    $this->inputInterface = $input;
    $this->outputInterface = $output;
    $this->config = $config_factory->get('upgrade_status.settings');

    $this->populateAutoLoader();

    $this->upgradeStatusTemporaryDirectory = file_directory_temp() . '/upgrade_status';
    $this->phpstanNeonPath = $this->upgradeStatusTemporaryDirectory . '/deprecation_testing.neon';
    if (!file_exists($this->phpstanNeonPath)) {
      $this->prepareTempDirectory();
      $this->createModifiedNeonFile();
    }
  }

  /**
   * Populate the class loader for PHPStan.
   */
  protected function populateAutoLoader() {
    require_once DRUPAL_ROOT . '/core/tests/bootstrap.php';
    drupal_phpunit_populate_class_loader();
  }

  /**
   * {@inheritdoc}
   */
  public function analyse(Extension $extension) {
    drupal_register_shutdown_function([$this, 'logFatalError'], $extension->getName());

    // Set the autoloader for PHPStan.
    if (!isset($GLOBALS['autoloaderInWorkingDirectory'])) {
      $GLOBALS['autoloaderInWorkingDirectory'] = DRUPAL_ROOT . '/autoload.php';
    }

    $project_dir = DRUPAL_ROOT . '/' . $extension->subpath;
    $paths = $this->getDirContents($project_dir);
    foreach ($paths as $key => $file_path) {
      if (substr($file_path, -3) !== 'php'
        && substr($file_path, -7) !== '.module'
        && substr($file_path, -8) !== '.install'
        && substr($file_path, -3) !== 'inc') {
        unset($paths[$key]);
      }
    }

    $result = [
      'totals' => [
        'errors' => 0,
        'file_errors' => 0,
      ],
      'files' => [],
    ];

    if (!empty($paths)) {
      $num_of_files = $this->config->get('paths_per_scan');
      // @todo: refactor and validate.
      for ($offset = 0; $offset <= count($paths); $offset += $num_of_files) {
        $files = array_slice($paths, $offset, $num_of_files);
        if (!empty($files)) {
          $raw_errors = $this->checkDeprecationErrorMessages($files);
          $errors = json_decode($raw_errors, TRUE);
          if (!is_array($errors)) {
            continue;
          }
          $result['totals']['errors'] += $errors['totals']['errors'];
          $result['totals']['file_errors'] += $errors['totals']['file_errors'];
          $result['files'] = array_merge($result['files'], $errors['files']);
        }
      }
    }

    // Store the analysis results in our storage bin.
    $this->scanResultStorage->set($extension->getName(), json_encode($result));
  }

  public function getDirContents($dir, &$results = []) {
    $files = scandir($dir);

    foreach ($files as $value) {
      $path = realpath($dir . '/' . $value);

      if (!is_dir($path)) {
        $results[] = $path;
        continue;
      }

      if ($value != '.' && $value != '..') {
        $this->getDirContents($path, $results);
        $results[] = $path;
      }
    }

    return $results;
  }

  public function checkDeprecationErrorMessages(array $paths) {
    // Analyse code in the given directory with PHPStan. The most sensible way
    // we could find was to pretend we have Symfony console inputs and outputs
    // and take the result from there. PHPStan as-is is highly tied to the
    // console and we could not identify an independent PHP API to use.
    try {
      $result = CommandHelper::begin(
        $this->inputInterface,
        $this->outputInterface,
        $paths,
        NULL,
        NULL,
        NULL,
        $this->phpstanNeonPath,
        NULL
      );
    }
    catch (Exception $e) {
      $this->logger->error($e);
    }

    $container = $result->getContainer();
    $error_formatter_service = sprintf('errorFormatter.%s', self::ERROR_FORMAT);
    if (!$container->hasService($error_formatter_service)) {
      $this->logger->error('Error formatter @formatter not found', ['@formatter' => self::ERROR_FORMAT]);
    }
    else {
      $errorFormatter = $container->getService($error_formatter_service);
      $application = $container->getByType(AnalyseApplication::class);

      $result->handleReturn(
        $application->analyse(
          $result->getFiles(),
          $result->isOnlyFiles(),
          $result->getConsoleStyle(),
          $errorFormatter,
          $result->isDefaultLevelUsed(),
          FALSE
        )
      );

      return $this->outputInterface->fetch();
    }
  }

  /**
   * Prepare fundamental directories for upgrade_status.
   *
   * The created directories in Drupal's temporary directory is needed to
   * dynamically set temporary directory for PHPStan in the neon file
   * provided by upgrade_status.
   * The temporary directory used by PHPStan cache.
   *
   * @return bool
   *   True if the temporary directory is created, false if not.
   */
  protected function prepareTempDirectory() {
    $success = file_prepare_directory($this->upgradeStatusTemporaryDirectory, FILE_CREATE_DIRECTORY | FILE_MODIFY_PERMISSIONS);

    if (!$success) {
      $this->logger->error($this->t("Couldn't write temporary directory for Upgrade Status: @directory", ['@directory' => $this->upgradeStatusTemporaryDirectory]));
      return $success;
    }

    $phpstan_cache_directory = $this->upgradeStatusTemporaryDirectory . '/phpstan';
    $success = file_prepare_directory($phpstan_cache_directory, FILE_CREATE_DIRECTORY | FILE_MODIFY_PERMISSIONS);

    if (!$success) {
      $this->logger->error($this->t("Couldn't write temporary directory for PHPStan: @directory", ['@directory' => $phpstan_cache_directory]));
    }

    return $success;
  }

  /**
   * Creates the final config file in the temporary directory.
   *
   * @return bool
   */
  protected function createModifiedNeonFile() {
    $module_path = drupal_get_path('module', 'upgrade_status');
    $unmodified_neon_file = DRUPAL_ROOT . "/$module_path/deprecation_testing.neon";
    $config = file_get_contents($unmodified_neon_file);
    $neon = Neon::decode($config);
    $neon['parameters']['tmpDir'] = $this->upgradeStatusTemporaryDirectory . '/phpstan';
    $success = file_put_contents($this->phpstanNeonPath, Neon::encode($neon), Neon::BLOCK);

    return $success ? TRUE : FALSE;
  }

  public function logFatalError(string $project_name) {
    $result = $this->scanResultStorage->get($project_name);
    $message = error_get_last();

    if (empty($result)) {

      $result = [
        'totals' => [
          'errors' => 0,
          'file_errors' => 1,
        ],
        'files' => [],
      ];

      $file_name = $message['file'];

      $result['files'][$file_name] = [
        'errors' => 1,
        'messages' => [
          [
            'message' => $message['message'],
            'line' => $message['line'],
          ],
        ],
      ];

      $this->scanResultStorage->set($project_name, json_encode($result));
    }

  }

}
