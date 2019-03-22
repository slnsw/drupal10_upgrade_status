<?php

namespace Drupal\upgrade_status;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeHandler;

class ProjectCollector implements ProjectCollectorInterface {

  /**
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $moduleExtensionList;

  /**
   * @var \Drupal\Core\Extension\ThemeHandler
   */
  protected $themeHandler;

  /**
   * ProjectCollector constructor.
   *
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleExtensionList
   * @param \Drupal\Core\Extension\ThemeHandler $themeHandler
   */
  public function __construct(
    ModuleExtensionList $moduleExtensionList,
    ThemeHandler $themeHandler
  ) {
    $this->moduleExtensionList = $moduleExtensionList;
    $this->themeHandler = $themeHandler;
  }

  public function collectProjects() {
    $projects['custom'] = [];
    $projects['contrib'] = [];

    $moduleData = $this->moduleExtensionList->reset()->getList();
    $themeData = $this->themeHandler->rebuildThemeData();

    $everyProject = array_merge($moduleData, $themeData);

    foreach ($everyProject as $key => $projectData) {

      if ($projectData->origin === 'core') {
        continue;
      }

      $info = $projectData->info;
      $projectName = '';

      if (isset($info['project'])) {
        $projectName = $projectData->info['project'];
      }

      if (array_key_exists($projectName, $projects['custom'])
        || array_key_exists($projectName, $projects['contrib'])
      ) {
        continue;
      }

      if (empty($projectName)) {
        $projects['custom'][$key] = $projectData;
        continue;
      }

      $projects['contrib'][$key] = $projectData;
    }

    $projects['custom'] = $this->getCustomProjectsBySubPath($projects['custom']);

    return $projects;
  }

  protected function getCustomProjectsBySubPath(array $projects) {

    foreach ($projects as $moduleName => $moduleData) {
      $subpath = $moduleData->subpath;
      $subpathLength = strlen($subpath);

      foreach ($projects as $comparedModuleName => $comparedModuleData) {
        $comparedModuleSubPath = $comparedModuleData->subpath;

        if ($comparedModuleName != $moduleName
          && substr($comparedModuleSubPath, 0, $subpathLength) === $subpath
        ) {
          unset($projects[$comparedModuleName]);
        }
      }
    }

    return $projects;

  }

}
