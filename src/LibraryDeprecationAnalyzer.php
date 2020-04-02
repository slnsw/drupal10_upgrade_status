<?php

declare(strict_types=1);

namespace Drupal\upgrade_status;

use Drupal\Core\Asset\LibraryDiscoveryParser;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ExtensionList;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ProfileExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Template\TwigEnvironment;
use Twig\Error\SyntaxError;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Node;
use Twig\Source;
use Twig\Util\TemplateDirIterator;

/**
 * A library deprecation analyzer.
 */
final class LibraryDeprecationAnalyzer {

  /**
   * The library discovery parser.
   *
   * @var \Drupal\Core\Asset\LibraryDiscoveryParser
   */
  protected $libraryDiscoveryParser;

  /**
   * The Twig environment.
   *
   * @var \Drupal\Core\Template\TwigEnvironment
   */
  protected $twigEnvironment;

  /**
   * The list of available modules.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $moduleExtensionList;

  /**
   * The list of available themes.
   *
   * @var \Drupal\Core\Extension\ThemeExtensionList
   */
  protected $themeExtensionList;

  /**
   * The list of available profiles.
   *
   * @var \Drupal\Core\Extension\ProfileExtensionList
   */
  protected $profileExtensionList;

  /**
   * Constructs a new library deprecation analyzer
   *
   * @param \Drupal\Core\Asset\LibraryDiscoveryParser $library_discovery_parser
   *   The library discovery parser.
   * @param \Drupal\Core\Template\TwigEnvironment $twig_environment
   *   The Twig environment.
   * @param \Drupal\Core\Extension\ModuleExtensionList $module_extension_list
   *   The module extension list service.
   * @param \Drupal\Core\Extension\ThemeExtensionList $theme_extension_list
   *   The theme extension handler service.
   * @param \Drupal\Core\Extension\ProfileExtensionList $profile_extension_list
   *   The profile extension handler service.
   */
  public function __construct(LibraryDiscoveryParser $library_discovery_parser, TwigEnvironment $twig_environment, ModuleExtensionList $module_extension_list, ThemeExtensionList $theme_extension_list, ProfileExtensionList $profile_extension_list) {
    $this->libraryDiscoveryParser = $library_discovery_parser;
    $this->twigEnvironment = $twig_environment;
    $this->moduleExtensionList = $module_extension_list;
    $this->themeExtensionList = $theme_extension_list;
    $this->profileExtensionList = $profile_extension_list;
  }

  /**
   * Analyzes usages of deprecated libraries in an extension.
   *
   * @param \Drupal\Core\Extension\Extension $extension
   *  The extensiion to be analyzed.
   *
   * @return \Drupal\upgrade_status\DeprecationMessage[]
   *   A list of deprecation messages.
   *
   * @throws \Exception
   */
  public function analyze(Extension $extension): array {
    $deprecations = [];
    $deprecations = array_merge($deprecations, $this->analyzeLibraryDependencies($extension));
    if ($extension->getType() === 'theme') {
      $deprecations = array_merge($deprecations, $this->analyzeThemeLibraryOverrides($extension));
      $deprecations = array_merge($deprecations, $this->analyzeThemeLibraryExtends($extension));
    }
    $deprecations = array_merge($deprecations, $this->analyzeTwigLibraryDependencies($extension));
    $deprecations = array_merge($deprecations, $this->analyzePhpLibraryReferences($extension));

    return $deprecations;
  }

  /**
   * Analyzes libraries for dependencies on deprecated libraries.
   *
   * @param \Drupal\Core\Extension\Extension $extension
   *   The extension to be analyzed.
   *
   * @return \Drupal\upgrade_status\DeprecationMessage[]
   */
  private function analyzeLibraryDependencies(Extension $extension): array {
    $libraries = $this->libraryDiscoveryParser->buildByExtension($extension->getName());
    $libraries_with_dependencies = array_filter($libraries, function($library) {
      return isset($library['dependencies']);
    });

    $deprecations = [];
    foreach ($libraries_with_dependencies as $key => $library_with_dependency) {
      foreach ($library_with_dependency['dependencies'] as $dependency) {
        if ($deprecation_message = $this->isLibraryDeprecated($dependency)) {
          $message = sprintf("The '%s' library is depending on a deprecated library. %s", $key, $deprecation_message);
          $file = sprintf('%s/%s.libraries.yml', $extension->getPath(), $extension->getName());
          $deprecations[] = new DeprecationMessage($message, $file, 0);
        }
      }
    }

    return $deprecations;
  }

  /**
   * Analyze library overrides in a theme.
   *
   * @param \Drupal\Core\Extension\Extension $extension
   *
   * @return \Drupal\upgrade_status\DeprecationMessage[]
   * @throws \Exception
   */
  private function analyzeThemeLibraryOverrides(Extension $extension): array {
    if ($extension->getType() !== 'theme') {
      throw new \Exception('Library overrides are only available in themes.');
    }
    if (!isset($extension->info['libraries-override'])) {
      return [];
    }

    return array_reduce(array_keys($extension->info['libraries-override']), function($deprecated_libraries, $library) use($extension) {
      if ($deprecation_message = $this->isLibraryDeprecated($library)) {
        $message = "Theme is overriding a deprecated library. $deprecation_message";
        $deprecated_libraries[] = new DeprecationMessage($message, $extension->getFilename(), 0);
      }
      return $deprecated_libraries;
    }, []);
  }

  /**
   * Analyze library extends in a theme.
   *
   * @param \Drupal\Core\Extension\Extension $extension
   *
   * @return \Drupal\upgrade_status\DeprecationMessage[]
   * @throws \Exception
   */
  private function analyzeThemeLibraryExtends(Extension $extension): array {
    if ($extension->getType() !== 'theme') {
      throw new \Exception('Library extends are only available in themes.');
    }
    if (!isset($extension->info['libraries-extend'])) {
      return [];
    }


    return array_reduce(array_keys($extension->info['libraries-extend']), function($deprecated_libraries, $library) use($extension) {
      if ($deprecation_message = $this->isLibraryDeprecated($library)) {
        $message = "Theme is extending a deprecated library. $deprecation_message";
        $deprecated_libraries[] = new DeprecationMessage($message, $extension->getFilename(), 0);
      }
      return $deprecated_libraries;
    }, []);
  }

  /**
   * Analyzes Twig library dependencies.
   *
   * At the moment we analyze only libraries attached using `library_attach()`.
   * However, there could be other ways to attache a library in a template, such
   * as generating and rendering a render array with `#attached`.
   *
   * @param \Drupal\Core\Extension\Extension $extension
   *
   * @return \Drupal\upgrade_status\DeprecationMessage[]
   */
  private function analyzeTwigLibraryDependencies(Extension $extension): array {
    $iterator = new TemplateDirIterator(new \RegexIterator(
      new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($extension->subpath), \RecursiveIteratorIterator::LEAVES_ONLY
      ), '{'.preg_quote('.html.twig').'$}'
    ));

    $deprecations = [];
    foreach ($iterator as $name => $contents) {
      try {
        $libraries = $this->findLibrariesAttachedInTemplate($this->twigEnvironment->parse($this->twigEnvironment->tokenize(new Source($contents, $name))));
        foreach ($libraries as $library) {
          if ($deprecation_message = $this->isLibraryDeprecated($library['library'])) {
            $message = 'Template is attaching a deprecated library. ' . $deprecation_message;
            $deprecations[] = new DeprecationMessage($message, $name, $library['line']);
          }
        }
      } catch (SyntaxError $e) {
        // Ignore templates containing syntax errors.
      }
    }

    return $deprecations;
  }

  /**
   * Finds libraries attached using `libraries_attach()` in a Twig template.
   *
   * @param \Twig\Node\Node $node
   *
   * @return string[]
   */
  private function findLibrariesAttachedInTemplate(Node $node): array {
    if (!is_iterable($node)) {
      return [];
    }

    $libraries = [];
    foreach ($node as $item) {
      if ($item instanceof FunctionExpression) {
        if ($item->getAttribute('name') === 'attach_library') {
          foreach ($item->getNode('arguments') as $argument) {
            if ($argument instanceof ConstantExpression && $argument->hasAttribute('value')) {
              $libraries[] = [
                'library' => $argument->getAttribute('value'),
                'line' => $item->getTemplateLine(),
              ];
            }
          }
        }
      } else {
        $libraries = array_merge($libraries, $this->findLibrariesAttachedInTemplate($item));
      }
    }

    return $libraries;
  }

  /**
   * Analyzes libraries referenced in PHP.
   *
   * This can only analyze statically attached libraries. We are not checking
   * the context where the library is being referenced, so in some cases this
   * could lead into false negatives. Testing the context would be possible, but
   * could lead into not detecting all references to deprecated libraries.
   *
   * @param \Drupal\Core\Extension\Extension $extension
   *
   * @return \Drupal\upgrade_status\DeprecationMessage[]
   */
  private function analyzePhpLibraryReferences(Extension $extension): array {
    $iterator = new \RegexIterator(
      new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($extension->subpath), \RecursiveIteratorIterator::LEAVES_ONLY
      ), '/\.(php|module|theme|profile|inc)$/'
    );

    $deprecations = [];
    foreach ($iterator as $file) {
      try {
        $tokens = token_get_all(file_get_contents($file->getPathName()));
      } catch (\ParseError $error) {
        // Ignore syntax errors.
        continue;
      }

      // Find nodes that look like attaching a library.
      $potential_libraries = array_values(
        array_map(
          function($token) {
            list($type, $value, $line) = $token;
            return [
              'value' => substr($value, 1, -1),
              'line' => $line,
            ];
          },
          array_filter($tokens, function($token) {
            if (is_array($token)) {
              list($type, $value) = $token;
              return ($type === T_CONSTANT_ENCAPSED_STRING && preg_match('/^[\"\'][a-zA-Z0-9\.\-\_]+\/[a-zA-Z0-9\.\-\_]+[\"\']$/', $value));
            }
            return FALSE;
          })
        )
      );
      foreach ($potential_libraries as $potential_library) {
        list($extension_name) = explode('/', $potential_library['value'], 2);
        $extension_lists = [
          $this->moduleExtensionList,
          $this->themeExtensionList,
          $this->profileExtensionList,
        ];
        // Iterate through all extension lists to see if we have found a valid
        // extension.
        $valid_extension = array_reduce($extension_lists, function($valid_extension, ExtensionList $extension_list) use($extension_name) {
          if ($valid_extension || $extension_list->exists($extension_name)) {
            return TRUE;
          }
          return FALSE;
        }, FALSE);
        if ($valid_extension && $deprecation_message = $this->isLibraryDeprecated($potential_library['value'])) {
          $message = "The referenced library is deprecated. $deprecation_message";
          $deprecations[] = new DeprecationMessage($message, $file->getPathName(), $potential_library['line']);
        }
      }
    }

    return $deprecations;
  }

  /**
   * Tests if library is deprecated.
   *
   * @param string $library
   *   A string representing library. For example, 'node/drupal.node'.
   *
   * @return bool|string
   *   Deprecation message or FALSE in case the library is not deprecated.
   */
  private function isLibraryDeprecated($library) {
    list($extension_name, $library_name) = explode('/', $library, 2);
    $dependency_libraries = $this->libraryDiscoveryParser->buildByExtension($extension_name);
    if (isset($dependency_libraries[$library_name]) && isset($dependency_libraries[$library_name]['deprecated'])) {
      return str_replace('%library_id%', "$extension_name/$library_name", $dependency_libraries[$library_name]['deprecated']);
    }

    return FALSE;
  }

}
