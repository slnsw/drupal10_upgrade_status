<?php

namespace Drupal\upgrade_status;

use Drupal\Core\Extension\Extension;

interface DeprecationAnalyserInterface {

  public function analyse(Extension $projectData);

}
