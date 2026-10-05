<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_purge_paths\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Site\Settings;
use Drupal\file\Entity\File;
use Drupal\image\Entity\ImageStyle;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\Tests\perf_purge_paths\Doubles\RecordingEnqueuer;

/**
 * Base class of the perf_purge_paths Kernel tests.
 *
 * Installs the entity types the module maps (nodes, aliases, files, image
 * styles, users), swaps the purge enqueuer for a recorder, and turns path
 * purging on. Fixtures are created with purging switched off: the queuer
 * remembers what it queued for the rest of the PHP process (here, the whole
 * test), so a fixture that queued its paths would hide the same paths when the
 * test then asks for them. All content is SYNTHETIC.
 */
abstract class PurgePathsKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Taxonomy stands in for "another module that implements hook_node_update()",
   * which the hook removal test must see untouched.
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'taxonomy',
    'path',
    'path_alias',
    'file',
    'image',
    'purge',
    'azure_cdn_purge',
    'perf_purge_paths',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('perf_purge_paths.enqueuer', RecordingEnqueuer::class)->setPublic(TRUE);
  }

  /**
   * {@inheritdoc}
   *
   * Core registers the private:// stream wrapper only when a private path is
   * set before the container is built, so the tests of private files need it
   * here and not in setUp().
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    mkdir($this->siteDirectory . '/private', 0775);
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('file');
    $this->installSchema('user', ['users_data']);
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'node', 'filter']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->setSetting('perf_purge_paths_enabled', TRUE);
    $this->setSetting('perf_purge_trailing_slash', TRUE);
    $this->setSetting('perf_purge_list_paths', '/,/node');
    $this->setSetting('perf_purge_sitewide_paths', '');
    $this->recorder()->reset();
  }

  /**
   * Returns the recording enqueuer.
   */
  protected function recorder(): RecordingEnqueuer {
    // register() replaces the service, which the static service map of the
    // analyser does not know.
    /** @var \Drupal\Tests\perf_purge_paths\Doubles\RecordingEnqueuer $recorder */
    $recorder = \Drupal::service('perf_purge_paths.enqueuer');
    return $recorder;
  }

  /**
   * Runs a fixture with path purging off, so it queues and remembers nothing.
   *
   * @param callable $fixture
   *   Creates the entities a test needs.
   *
   * @return mixed
   *   What the fixture returns.
   */
  protected function withoutPurging(callable $fixture): mixed {
    $before = Settings::get('perf_purge_paths_enabled');
    $this->setSetting('perf_purge_paths_enabled', FALSE);
    try {
      return $fixture();
    }
    finally {
      $this->setSetting('perf_purge_paths_enabled', $before);
    }
  }

  /**
   * Creates a published page with a URL alias.
   *
   * @param string $alias
   *   The alias, with a leading slash.
   */
  protected function createNode(string $alias): Node {
    return $this->withoutPurging(static function () use ($alias): Node {
      $node = Node::create([
        'type' => 'page',
        'title' => 'SYNTHETIC node',
        'status' => 1,
        'path' => ['alias' => $alias, 'pathauto' => 0],
      ]);
      $node->save();
      return $node;
    });
  }

  /**
   * Loads the path alias entity with the given alias.
   */
  protected function loadAlias(string $alias): PathAlias {
    $aliases = \Drupal::entityTypeManager()->getStorage('path_alias')->loadByProperties(['alias' => $alias]);
    $this->assertCount(1, $aliases, "Alias $alias exists.");
    $found = reset($aliases);
    assert($found instanceof PathAlias);
    return $found;
  }

  /**
   * Creates a file entity.
   *
   * @param string $uri
   *   The file URI, such as public://synthetic.png.
   */
  protected function createFile(string $uri): File {
    return $this->withoutPurging(static function () use ($uri): File {
      $file = File::create(['uri' => $uri, 'status' => 1]);
      $file->save();
      return $file;
    });
  }

  /**
   * Creates image styles by machine name.
   *
   * @param string ...$names
   *   The style names.
   */
  protected function createImageStyles(string ...$names): void {
    $this->withoutPurging(static function () use ($names): void {
      foreach ($names as $name) {
        ImageStyle::create(['name' => $name, 'label' => ucfirst($name)])->save();
      }
    });
  }

}
