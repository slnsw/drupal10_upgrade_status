<?php

namespace Drupal\upgrade_status;

use Composer\Semver\Semver;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\Extension;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Exception;
use GuzzleHttp\Client;
use Nette\Neon\Neon;
use PHPStan\Command\AnalyseApplication;
use PHPStan\Command\CommandHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

class DeprecationAnalyser implements DeprecationAnalyserInterface {

  use StringTranslationTrait;

  /**
   * The oldest supported core minor version.
   *
   * @var string
   */
  const CORE_MINOR_OLDEST_SUPPORTED = '8.7';

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
   * HTTP Client for drupal.org API calls.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * File system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

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
   * @param \GuzzleHttp\Client $http_client
   *   HTTP client.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   File system service.
   */
  public function __construct(
    KeyValueFactoryInterface $key_value_factory,
    LoggerInterface $logger,
    StringInput $input,
    BufferedOutput $output,
    ConfigFactoryInterface $config_factory,
    Client $http_client,
    FileSystemInterface $file_system
  ) {
    $this->scanResultStorage = $key_value_factory->get('upgrade_status_scan_results');
    // Log errors to an upgrade status logger channel.
    $this->logger = $logger;
    $this->inputInterface = $input;
    $this->outputInterface = $output;
    $this->config = $config_factory->get('upgrade_status.settings');
    $this->httpClient = $http_client;
    $this->fileSystem = $file_system;

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
    // Prepare for possible fatal errors while autoloading or due to issues with
    // dependencies.
    drupal_register_shutdown_function([$this, 'logFatalError'], $extension);

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

    $this->logger->notice($this->t("Extension @project_machine_name contains @number files to process.", ['@project_machine_name' => $extension->getName(), '@number' => count($paths)]));

    $result = [];
    $result['date'] = REQUEST_TIME;
    $result['data'] = [
      'totals' => [
        'errors' => 0,
        'file_errors' => 0,
      ],
      'files' => [],
    ];

    // Manually add on info file incompatibility to phpstan results.
    $info = $extension->info;
    if (!isset($info['core_version_requirement'])) {
      $result['data']['files'][$extension->getFilename()]['messages'] = [
        [
          'message' => 'Add <code>core_version_requirement: ^8 || ^9</code> to ' . $extension->getFilename() . ' to designate that the module is compatible with Drupal 9. See https://www.drupal.org/node/3070687.',
          'line' => 0,
        ],
      ];
      $result['data']['totals']['errors']++;
      $result['data']['totals']['file_errors']++;
    }
    elseif (!Semver::satisfies('9.0.0', $info['core_version_requirement'])) {
      $result['data']['files'][$extension->getFilename()]['messages'] = [
        [
          'message' => "The current value  <code>core_version_requirement: {$info['core_version_requirement']}</code> in {$extension->getFilename()} is not compatible with Drupal 9.0.0. See https://www.drupal.org/node/3070687.",
          'line' => 0,
        ],
      ];
      $result['data']['totals']['errors']++;
      $result['data']['totals']['file_errors']++;
    }

    if (!empty($paths)) {
      $num_of_files = $this->config->get('paths_per_scan') ?: 30;
      // @todo: refactor and validate.
      for ($offset = 0; $offset <= count($paths); $offset += $num_of_files) {
        $files = array_slice($paths, $offset, $num_of_files);
        if (!empty($files)) {
          $raw_errors = $this->runPhpStan($files);
          $errors = json_decode($raw_errors, TRUE);
          if (!is_array($errors)) {
            continue;
          }
          $result['data']['totals']['errors'] += $errors['totals']['errors'];
          $result['data']['totals']['file_errors'] += $errors['totals']['file_errors'];
          $result['data']['files'] = array_merge($result['data']['files'], $errors['files']);
        }
      }
    }

    foreach($result['data']['files'] as $path => &$errors) {
      if (!empty($errors['messages'])) {
        foreach($errors['messages'] as &$error) {

          // Overwrite message with processed text. Save category.
          list($message, $category) = $this->categorizeMessage($error['message'], $extension);
          $error['message'] = $message;
          $error['upgrade_status_category'] = $category;

          // Sum up the error based on the category it ended up in. Split the
          // categories into two high level buckets needing attention now or
          // later for Drupal 9 compatibility. Ignore Drupal 10 here.
          @$result['data']['totals']['upgrade_status_category'][$category]++;
          if (in_array($category, ['safe', 'old'])) {
            @$result['data']['totals']['upgrade_status_split']['error']++;
          }
          elseif (in_array($category, ['later', 'uncategorized'])) {
            @$result['data']['totals']['upgrade_status_split']['warning']++;
          }
        }
      }
    }

    // For contributed projects, attempt to grab Drupal 9 plan information.
    if (!empty($extension->info['project'])) {
      /** @var \Psr\Http\Message\ResponseInterface $response */
      $response = $this->httpClient->request('GET', 'https://www.drupal.org/api-d7/node.json?field_project_machine_name=' . $extension->getName());
      if ($response->getStatusCode()) {
        $data = json_decode($response->getBody(), TRUE);
        if (!empty($data['list'][0]['field_next_major_version_info']['value'])) {
          $result['plans'] = str_replace('href="/', 'href="https://drupal.org/', $data['list'][0]['field_next_major_version_info']['value']);
          // @todo implement "replaced by" collection once drupal.org exposes
          // that in an accessible way
          // @todo once/if drupal.org deprecation testing is in place, grab
          // the status from there so we know if it improves by updating
        }
      }
    }

    // Store the analysis results in our storage bin.
    $this->scanResultStorage->set($extension->getName(), json_encode($result));
  }

  /**
   * Get directory contents recursively.
   *
   * @param string $dir
   *   Path to directory.
   * @return array
   *   The list of files found.
   */
  public function getDirContents(string $dir) {
    $results = [];
    $files = scandir($dir);
    foreach ($files as $value) {
      $path = realpath($dir . '/' . $value);
      if (!is_dir($path)) {
        $results[] = $path;
        continue;
      }
      if ($value != '.' && $value != '..') {
        $results = array_merge($results, $this->getDirContents($path, $results));
      }
    }
    return $results;
  }

  /**
   * Run PHPStan on the given paths.
   *
   * @param array $paths
   *   List of paths.
   * @return mixed
   *   Results in self::ERROR_FORMAT.
   */
  public function runPhpStan(array $paths) {
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
        NULL,
        FALSE
      );
    }
    catch (Exception $e) {
      $this->logger->error($e);
    }

    $container = $result->getContainer();
    $error_formatter_service = sprintf('errorFormatter.%s', self::ERROR_FORMAT);
    if (!$container->hasService($error_formatter_service)) {
      $this->logger->error('Error formatter @formatter not found.', ['@formatter' => self::ERROR_FORMAT]);
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
          FALSE,
          NULL
        )
      );

      return $this->outputInterface->fetch();
    }
  }

  /**
   * Prepare temporary directories for Upgrade Status.
   *
   * The created directories in Drupal's temporary directory are needed to
   * dynamically set a temporary directory for PHPStan's cache in the neon file
   * provided by Upgrade Status.
   *
   * @return bool
   *   True if the temporary directory is created, false if not.
   */
  protected function prepareTempDirectory() {
    $success = $this->fileSystem->prepareDirectory($this->upgradeStatusTemporaryDirectory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    if (!$success) {
      $this->logger->error($this->t("Unable to create temporary directory for Upgrade Status: @directory.", ['@directory' => $this->upgradeStatusTemporaryDirectory]));
      return $success;
    }

    $phpstan_cache_directory = $this->upgradeStatusTemporaryDirectory . '/phpstan';
    $success = $this->fileSystem->prepareDirectory($phpstan_cache_directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    if (!$success) {
      $this->logger->error($this->t("Unable to create temporary directory for PHPStan: @directory.", ['@directory' => $phpstan_cache_directory]));
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

    if (!$success) {
      $this->logger->error($this->t("Couldn't write configuration for PHPStan: @file.", ['@file' => $this->phpstanNeonPath]));
    }

    return $success ? TRUE : FALSE;
  }

  /**
   * Shutdown function to handle fatal errors in the parsing process.
   *
   * @param \Drupal\Core\Extension\Extension $extension
   *   Failed extension.
   */
  public function logFatalError(Extension $extension) {
    $project_name = $extension->getName();
    $result = $this->scanResultStorage->get($project_name);
    $message = error_get_last();

    if (empty($result)) {

      $this->logger->error($this->t("Fatal error occurred for @project_machine_name.", ['@project_machine_name' => $project_name]));

      $result = [];
      $result['date'] = REQUEST_TIME;
      $result['data'] = [
        'totals' => [
          'errors' => 0,
          'file_errors' => 1,
        ],
        'files' => [],
      ];

      $file_name = $message['file'];

      $result['data']['files'][$file_name] = [
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

  /**
   * Annotate and categorize the error message.
   *
   * @param string $error
   *   Error message as identified by phpstan.
   * @param \Drupal\Core\Extension\Extension $extension
   *   Extension where the error was found.
   *
   * @return array
   *   Two item array. The reformatted error and the category.
   */
  protected function categorizeMessage(string $error, Extension $extension) {
    // Make the error more readable in case it has the deprecation text.
    $error = preg_replace('!:\s+(in|as of)!', '. Deprecated \1', $error);

    // TestBase and WebTestBase replacements are available at least from Drupal
    // 8.6.0, so use that version number. Otherwise use the number from the
    // message.
    $version = '';
    if (preg_match('!\\\\(Web|)TestBase. Deprecated in [Dd]rupal[ :]8.8.0 !', $error)) {
      $version = '8.6.0';
      $error .= " Replacement available from drupal:8.6.0.";
    }
    elseif (preg_match('!Deprecated (in|as of) [Dd]rupal[ :](8.\d)!', $error, $version_found)) {
      $version = $version_found[2];
    }

    // Set a default category for the messages we can't categorize.
    $category = 'uncategorized';

    if (!empty($version)) {

      // Categorize deprecations for contributed projects based on
      // community rules.
      if (!empty($extension->info['project'])) {
        // If the found deprecation is older or equal to the oldest
        // supported core version, it should be old enough to update
        // either way.
        if (version_compare($version, self::CORE_MINOR_OLDEST_SUPPORTED) <= 0) {
          $category = 'old';
        }
        // If the deprecation is not old and we are dealing with a contrib
        // module, the deprecation should be dealt with later.
        else {
          $category = 'later';
        }
      }
      // For custom projects, look at this site's version specifically.
      else {
        // If the found deprecation is older or equal to the current
        // Drupal version on this site, it should be safe to update.
        if (version_compare($version, \Drupal::VERSION) <= 0) {
          $category = 'safe';
        }
        else {
          $category = 'later';
        }
      }
    }

    // If the deprecation is already for Drupal 10, put it in the ignore
    // category. This overwrites any categorization before intentionally.
    if (preg_match('!(will be|is) removed (before|from) [Dd]rupal[ :](10.\d)!', $error)) {
      $category = 'ignore';
    }

    return [$error, $category];
  }

}
