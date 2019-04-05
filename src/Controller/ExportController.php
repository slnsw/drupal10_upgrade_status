<?php

namespace Drupal\upgrade_status\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Render\RenderCacheInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\upgrade_status\ProjectCollectorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ExportController extends ControllerBase {

  /**
   * The class resolver service.
   *
   * @var \Drupal\Core\DependencyInjection\ClassResolverInterface
   */
  protected $classResolver;

  /**
   * The project collector service.
   *
   * @var \Drupal\upgrade_status\ProjectCollectorInterface
   */
  protected $projectCollector;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The render cache service.
   *
   * @var \Drupal\Core\Render\RenderCacheInterface
   */
  protected $renderCache;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The Date Formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Constructs a \Drupal\upgrade_status\Controller\ExportController.
   *
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $class_resolver
   *   The class resolver service.
   * @param \Drupal\upgrade_status\ProjectCollectorInterface $project_collector
   *   The project collector service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\Render\RenderCacheInterface $render_cache
   *   The render cache service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   */
  public function __construct(
    ClassResolverInterface $class_resolver,
    ProjectCollectorInterface $project_collector,
    RendererInterface $renderer,
    RenderCacheInterface $render_cache,
    TimeInterface $time,
    DateFormatterInterface $date_formatter
  ) {
    $this->classResolver = $class_resolver;
    $this->projectCollector = $project_collector;
    $this->renderer = $renderer;
    $this->renderCache = $render_cache;
    $this->time = $time;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('class_resolver'),
      $container->get('upgrade_status.project_collector'),
      $container->get('renderer'),
      $container->get('render_cache'),
      $container->get('datetime.time'),
      $container->get('date.formatter')
    );
  }

  /**
   * Generates full export of Upgrade Status report.
   *
   * @return HtmlResponse
   */
  public function downloadFullExport() {
    $projects = $this->projectCollector->collectProjects();

    /** @var \Drupal\upgrade_status\Controller\DeprecationListController $deprecation_list_controller */
    $deprecation_list_controller = $this->classResolver->getInstanceFromDefinition(DeprecationListController::class);

    $content['#theme'] = 'full_export';
    $time = $this->time->getCurrentTime();
    $formattedTime = $this->dateFormatter->format($time, 'html_datetime');
    $filename = 'full-export-' . $formattedTime . '.html';
    $content['#date'] = $this->dateFormatter->format($time);

    foreach ($projects['custom'] as $project_machine_name => $project) {
      $content['#projects']['custom'][] = $deprecation_list_controller->content($project->getType(), $project_machine_name);
    }

    foreach ($projects['contrib'] as $project_machine_name => $project) {
      $content['#projects']['contrib'][] = $deprecation_list_controller->content($project->getType(), $project_machine_name);
    }

    return $this->createResponse($content, $filename);
  }

  /**
   * Generates single project export of Upgrade Status report.
   *
   * @param string $type
   *   Type of the extension, it can be either 'module' or 'theme.
   * @param string $project_machine_name
   *   The machine name of the project.
   *
   * @return HtmlResponse
   */
  public function downloadSingleExport(string $type, string $project_machine_name) {
    $extension = $this->projectCollector->loadProject($type, $project_machine_name);
    $info = $extension->info;
    $label = $info['name'] . (!empty($info['version']) ? ' ' . $info['version'] : '');

    $deprecation_list_controller = $this->classResolver->getInstanceFromDefinition(DeprecationListController::class);

    $content['#theme'] = 'single_export';
    $content['#project'] = $deprecation_list_controller->content($type, $project_machine_name);
    $content['#project']['name'] = $label;
    $time = $this->time->getCurrentTime();
    $formattedTime = $this->dateFormatter->format($time, 'html_datetime');
    $content['#date'] = $this->dateFormatter->format($time);
    $filename = 'single-export-' . $project_machine_name . '-' .$formattedTime . '.html';

    return $this->createResponse($content, $filename);
  }

  /**
   * Wraps the report output in a response.
   *
   * @return HtmlResponse
   */
  protected function createResponse(&$content, string $filename) {
    $render_context = new RenderContext();
    $this->renderer->executeInRenderContext($render_context, function () use (&$content) {
      // RendererInterface::render() renders the $html render array and updates
      // it in place. We don't care about the return value (which is just
      // $html['#markup']), but about the resulting render array.
      // @todo Simplify this when https://www.drupal.org/node/2495001 lands.
      $this->renderer->render($content);
    });
    $bubbleable_metadata = $render_context->pop();
    $bubbleable_metadata->applyTo($content);

    $output = $this->renderCache->getCacheableRenderArray($content);
    $output['#cache']['tags'][] = 'rendered';
    $response = new HtmlResponse($output, 200);
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

    return $response;
  }

}
