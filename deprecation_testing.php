<?php

// The files we need to include.
$files = [
  'vendor/mglaman/phpstan-drupal/extension.neon',
  'vendor/phpstan/phpstan-deprecation-rules/rules.neon'
];

// Various possibilities as to where the module is placed and where the vendor
// directory is based on how the site is set up. This set may or may not be
// comprehensive.
$dirs = [
  __DIR__ . '/../../../',
  __DIR__ . '/../../../../',
  __DIR__ . '/../../../../../',
  __DIR__ . '/../../../../../../',
  __DIR__ . '/../../../../../../../',
];

foreach ($dirs as $dir) {
  if (file_exists($dir . $files[0])) {
    $neon = ['includes' => []];
    foreach ($files as $file_path) {
      $neon['includes'][] = $dir . $file_path;
    }
    return $neon;
  }
}
return [];
