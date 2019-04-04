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
   * Class Resolver service.
   *
   * @var \Drupal\Core\DependencyInjection\ClassResolverInterface
   */
  protected $classResolver;

  /**
   * @var \Drupal\upgrade_status\ProjectCollectorInterface
   */
  protected $projectCollector;

  /**
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

  public function downloadFullExport() {
    $projects = $this->projectCollector->collectProjects();

    /** @var \Drupal\upgrade_status\Controller\DeprecationListController $deprecation_list_controller */
    $deprecation_list_controller = $this->classResolver->getInstanceFromDefinition(DeprecationListController::class);

    $content['#theme'] = 'full_export';
    foreach ($projects['custom'] as $project_machine_name => $project) {
      $content['#projects']['custom'][] = $deprecation_list_controller->content($project_machine_name);
    }

    foreach ($projects['contrib'] as $project_machine_name => $project) {
      $content['#projects']['contrib'][] = $deprecation_list_controller->content($project_machine_name);
    }

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



    $time = $this->time->getCurrentTime();
    $formattedTime = $this->dateFormatter->format($time, 'html_datetime');
    $filename = 'full-export-' . $formattedTime . '.html';

    $output = $this->renderCache->getCacheableRenderArray($content);
    $output['#cache']['tags'][] = 'rendered';
    $response = new HtmlResponse($output, 200);
    //$response->headers->set('Content-Type', 'application/force-download;');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

    return $response;
  }

}
