<?php

namespace Drupal\upgrade_status;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Exception;
use PHPStan\Command\AnalyseApplication;
use PHPStan\Command\CommandHelper;
use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

class DeprecationAnalyser {

  use StringTranslationTrait;

  const ERROR_FORMAT = 'json';

  /**
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * @var \Symfony\Component\Console\Input\StringInput
   */
  protected $inputInterface;

  /**
   * @var \Symfony\Component\Console\Output\BufferedOutput
   */
  protected $outputInterface;

  /**
   * DeprecationAnalyser constructor.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   */
  public function __construct(
    CacheBackendInterface $cache,
    LoggerChannelFactoryInterface $loggerFactory,
    StringInput $inputInterface,
    BufferedOutput $outputInterface
  ) {
    $this->cache = $cache;
    $this->logger = $loggerFactory->get('readiness');
    $this->inputInterface = $inputInterface;
    $this->outputInterface = $outputInterface;
  }

  protected function loadTestNamespaces() {
    require_once implode(DIRECTORY_SEPARATOR, [DRUPAL_ROOT, 'core', 'tests', 'bootstrap.php']);
    drupal_phpunit_populate_class_loader();
  }

  public function analyse() {
    $this->loadTestNamespaces();

    $modulePath = drupal_get_path('module', 'upgrade_status');

    if (!isset($GLOBALS['autoloaderInWorkingDirectory'])) {
      $GLOBALS['autoloaderInWorkingDirectory'] = implode(DIRECTORY_SEPARATOR, [DRUPAL_ROOT, 'autoload.php']);
    }

    $configuration = implode(DIRECTORY_SEPARATOR, [DRUPAL_ROOT, $modulePath, 'deprecation_testing.neon']);

    $files = [];
    $files[] = implode(DIRECTORY_SEPARATOR, [DRUPAL_ROOT, 'core', 'lib', 'Drupal.php']);

    try {
      $inspectionResult = CommandHelper::begin(
        $this->inputInterface,
        $this->outputInterface,
        $files,
        null,
        null,
        null,
        $configuration,
        null
      );
    } catch (Exception $e) {
      $this->logger->error($e);
    }

    $container = $inspectionResult->getContainer();
    $errorFormatterServiceName = sprintf('errorFormatter.%s', self::ERROR_FORMAT);
    if (!$container->hasService($errorFormatterServiceName)) {
      $this->logger->error($this->t('Error formatter @formatter not found'), ['@formatter' => self::ERROR_FORMAT]);
      return '';
    }

    /** @var ErrorFormatter $errorFormatter */
    $errorFormatter = $container->getService($errorFormatterServiceName);
    $application = $container->getByType(AnalyseApplication::class);

    $inspectionResult->handleReturn(
      $application->analyse(
        $inspectionResult->getFiles(),
        $inspectionResult->isOnlyFiles(),
        $inspectionResult->getConsoleStyle(),
        $errorFormatter,
        $inspectionResult->isDefaultLevelUsed(),
        FALSE
      )
    );

    return $this->outputInterface->fetch();
  }

}
