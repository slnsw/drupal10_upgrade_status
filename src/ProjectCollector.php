<?php

namespace Drupal\upgrade_status;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeHandler;

/**
 * Collects projects collated for the purposes of upgrade status.
 */
class ProjectCollector implements ProjectCollectorInterface {

  /**
   * The list of available modules.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $moduleExtensionList;

  /**
   * The theme handler.
   *
   * @var \Drupal\Core\Extension\ThemeHandler
   */
  protected $themeHandler;

  /**
   * ProjectCollector constructor.
   *
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleExtensionList
   *   The module extension list service.
   * @param \Drupal\Core\Extension\ThemeHandler $themeHandler
   *   The theme extension handler service.
   */
  public function __construct(
    ModuleExtensionList $moduleExtensionList,
    ThemeHandler $themeHandler
  ) {
    $this->moduleExtensionList = $moduleExtensionList;
    $this->themeHandler = $themeHandler;
  }

  /**
   * {@inheritdoc}
   */
  public function collectProjects() {
    $projects = ['custom' => [], 'contrib' => []];
    $modules = $this->moduleExtensionList->reset()->getList();
    $themes = $this->themeHandler->rebuildThemeData();
    $extensions = array_merge($modules, $themes);

    /** @var \Drupal\Core\Extension\Extension $extension */
    foreach ($extensions as $key => $extension) {

      if ($extension->origin === 'core') {
        // Ignore core extensions for the sake of upgrade status.
        continue;
      }

      // If the project is already specified in this extension, use that.
      $project = isset($extension->info['project']) ? $extension->info['project'] : '';
      if (array_key_exists($project, $projects['custom'])
        || array_key_exists($project, $projects['contrib'])
      ) {
        // If we already have a representative of this project in the list,
        // don't add this extension.
        // @todo Make sure to use the extension with the shortest file path.
        continue;
      }

      // For extensions that are not in core and no project was specified,
      // they are assumed to be custom code. Drupal.org packages contrib
      // extensions with a project key and composer packages also include it.
      if (empty($project)) {
        $projects['custom'][$key] = $extension;
        continue;
      }

      // @todo should this use $project as the key?
      $projects['contrib'][$key] = $extension;
    }

    // Collate custom extensions to projects, removing sub-extensions.
    $projects['custom'] = $this->collateCustumExtensionsIntoProjects($projects['custom']);

    return $projects;
  }

  /**
   * Finds topmost custom project for each project and keeps only that.
   *
   * @param \Drupal\Core\Extension\Extension[] $projects
   *   List of all enabled custom extensions.
   *
   * @return \Drupal\Core\Extension\Extension[]
   *   List of custom extensions, with only the topmost custom extension left
   *   for each extension that has a parent extension.
   */
  protected function collateCustumExtensionsIntoProjects(array $projects) {
    foreach ($projects as $name_a => $data_a) {
      $subpath_a = $data_a->subpath;
      $subpath_a_length = strlen($subpath);

      foreach ($projects as $name_b => $data_b) {
        $subpath_b = $data_b->subpath;
        // If the extension is not the same but the beginning of paths match,
        // remove this extension from the list as it is part of another one.
        if ($name_b != $name_a && substr($subpath_b, 0, $subpath_a_length) === $subpath_a) {
          unset($projects[$name_b]);
        }
      }
    }
    return $projects;
  }

}
