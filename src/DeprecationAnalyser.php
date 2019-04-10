<?php

namespace Drupal\upgrade_status;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Exception;
use Nette\Neon\Neon;
use PHPStan\Command\AnalyseApplication;
use PHPStan\Command\CommandHelper;
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
   * The cache service.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

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
   * Constructs a \Drupal\upgrade_status\DeprecationAnalyser.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory service.
   * @param \Symfony\Component\Console\Input\StringInput $inputInterface
   *   The Symfony Console input interface.
   * @param \Symfony\Component\Console\Output\BufferedOutput $outputInterface
   *   The Symfony Console output interface.
   */
  public function __construct(
    CacheBackendInterface $cache,
    LoggerChannelFactoryInterface $loggerFactory,
    StringInput $inputInterface,
    BufferedOutput $outputInterface
  ) {
    $this->cache = $cache;
    // Log errors to an upgrade status logger channel.
    $this->logger = $loggerFactory->get('upgrade_status');
    $this->inputInterface = $inputInterface;
    $this->outputInterface = $outputInterface;

    $this->populateAutoLoader();

    $this->upgradeStatusTemporaryDirectory = file_directory_temp() . '/upgrade_status';
    // @todo: check if temporary directory // modified neon file exists
    $this->prepareTempDirectory();
    $this->createModifiedNeonFile();
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
    // Set the autoloader for PHPStan.
    if (!isset($GLOBALS['autoloaderInWorkingDirectory'])) {
      $GLOBALS['autoloaderInWorkingDirectory'] = DRUPAL_ROOT . '/autoload.php';
    }

    // Analyse code in the given directory with PHPStan. The most sensible way
    // we could find was to pretend we have Symfony console inputs and outputs
    // and take the result from there. PHPStan as-is is highly tied to the
    // console and we could not identify an independent PHP API to use.
    try {
      $result = CommandHelper::begin(
        $this->inputInterface,
        $this->outputInterface,
        [$extension->subpath],
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

      // Store the analysis results in our cache bin.
      $this->cache->set($extension->getName(), $this->outputInterface->fetch());
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

    // Set the PHPStan configuration neon file path.
    $this->phpstanNeonPath = $this->upgradeStatusTemporaryDirectory . '/deprecation_testing.neon';
    $success = file_put_contents($this->phpstanNeonPath, Neon::encode($neon), Neon::BLOCK);

    return $success ? TRUE : FALSE;
  }

}
