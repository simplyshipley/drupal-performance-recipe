<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_purge_paths\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\perf_purge_paths\PathsQueuer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests path safety and deduplication through the real mapper and Purge queue.
 *
 * @group perf_purge_paths
 */
#[Group('perf_purge_paths')]
final class PathsQueuerTest extends PurgePathsKernelTestBase {

  /**
   * Returns the registered cache tag invalidator.
   */
  private function queuer(): PathsQueuer {
    return $this->container->get('perf_purge_paths.queuer');
  }

  /**
   * A node tag queues its paths and their trailing-slash twins.
   */
  public function testTagQueuesItsPathsAndTheirTrailingSlashTwins(): void {
    $node = $this->createNode('/synthetic-about');
    $this->queuer()->queueTags(['node:' . $node->id()]);
    $path = '/node/' . $node->id();
    $this->assertSame([$path, $path . '/', '/synthetic-about', '/synthetic-about/'], $this->queuedPaths());
  }

  /**
   * Twins are not made where a trailing slash makes no sense.
   *
   * @dataProvider pathsWithoutTwin
   */
  #[DataProvider('pathsWithoutTwin')]
  public function testSomePathsGetNoTrailingSlashTwin(string $path): void {
    $this->setSetting('perf_purge_list_paths', [$path]);
    $this->queuer()->queueTags(['node_list']);
    $this->assertSame([$path], $this->queuedPaths());
  }

  /**
   * Paths for which no distinct trailing-slash twin should be queued.
   *
   * @return array<string, array{string}>
   *   One operator path per case.
   */
  public static function pathsWithoutTwin(): array {
    return [
      'front page' => ['/'],
      'wildcard' => ['/synthetic-news/*'],
      'sitewide wildcard' => ['/*'],
      'feed with an extension' => ['/synthetic-rss.xml'],
      'image file' => ['/sites/default/files/synthetic-photo.jpeg'],
    ];
  }

  /**
   * Turning twins off leaves only the paths themselves.
   */
  public function testTheTrailingSlashSettingTurnsTwinsOff(): void {
    $this->setSetting('perf_purge_trailing_slash', FALSE);
    $this->queuer()->queuePaths(['/synthetic-about']);
    $this->assertSame(['/synthetic-about'], $this->queuedPaths());
  }

  /**
   * Editor wildcards are dropped and logged without losing ordinary paths.
   */
  public function testContentPathWithWildcardIsNeverQueued(): void {
    $first = $this->createNode('/*');
    $second = $this->createNode('/synthetic-news*');
    $this->expectWarnings([['@path' => '/*'], ['@path' => '/synthetic-news*']]);
    $this->queuer()->queueTags(['node:' . $first->id(), 'node:' . $second->id()]);
    $paths = ['/node/' . $first->id(), '/node/' . $second->id()];
    $this->assertSame([$paths[0], $paths[0] . '/', $paths[1], $paths[1] . '/'], $this->queuedPaths());
  }

  /**
   * The content wildcard rule also holds for paths handed over by hooks.
   */
  public function testQueuePathsRefusesWildcardToo(): void {
    $this->queuer()->queuePaths(['/synthetic-*', '/synthetic-about']);
    $this->assertSame(['/synthetic-about', '/synthetic-about/'], $this->queuedPaths());
  }

  /**
   * Wildcards explicitly configured by the operator remain allowed.
   */
  public function testWildcardFromTheOperatorsSettingsIsQueued(): void {
    $this->setSetting('perf_purge_sitewide_paths', '/*');
    $this->queuer()->queueTags(['menu_link_content_list']);
    $this->assertSame(['/*'], $this->queuedPaths());
  }

  /**
   * Repeated tags and direct paths cannot queue the same path twice.
   */
  public function testPathIsQueuedOnce(): void {
    $this->setSetting('perf_purge_list_paths', '/synthetic-shared,/');
    $this->queuer()->queueTags(['node_list']);
    $this->queuer()->queueTags(['node_list', 'user_list']);
    $this->queuer()->queuePaths(['/synthetic-shared']);
    $this->assertSame(['/synthetic-shared', '/synthetic-shared/', '/'], $this->queuedPaths());
  }

  /**
   * Repeated tags do not repeat the mapper's alias lookup.
   */
  public function testTagIsMappedOncePerProcess(): void {
    $aliases = $this->createMock(AliasManagerInterface::class);
    $aliases->expects($this->once())->method('getAliasByPath')->with('/node/5')->willReturn('/synthetic-once');
    $this->container->set('path_alias.manager', $aliases);
    $this->queuer()->queueTags(['node:5']);
    $this->queuer()->queueTags(['node:5', 'node:5']);
    $this->assertSame(['/node/5', '/node/5/', '/synthetic-once', '/synthetic-once/'], $this->queuedPaths());
  }

  /**
   * Both entry points are inert while path purging is switched off.
   */
  public function testNothingIsQueuedWhenPurgingIsOff(): void {
    $this->setSetting('perf_purge_paths_enabled', FALSE);
    $this->queuer()->queueTags(['node:5']);
    $this->queuer()->queuePaths(['/synthetic-about']);
    $this->assertSame([], $this->queuedPaths());
  }

  /**
   * The absent setting must not even create the mapper or enqueuer.
   */
  public function testPurgingIsOffWhenTheSettingIsMissing(): void {
    $settings = Settings::getAll();
    unset($settings['perf_purge_paths_enabled']);
    new Settings($settings);
    $aliases = $this->createMock(AliasManagerInterface::class);
    $aliases->expects($this->never())->method('getAliasByPath');
    $this->container->set('path_alias.manager', $aliases);
    $this->queuer()->invalidateTags(['node:5']);
    $this->queuer()->queuePaths(['/synthetic-about']);
    $this->assertFalse($this->container->initialized('perf_purge_paths.enqueuer'));
    $this->assertSame([], $this->queuedPaths());
  }

  /**
   * A broken alias lookup logs its tag, and later tags still reach the queue.
   */
  public function testMappingFailureIsLoggedAndTheOtherTagsStillWork(): void {
    $aliases = $this->createMock(AliasManagerInterface::class);
    $aliases->method('getAliasByPath')->willReturnCallback(static function (string $path): string {
      if ($path === '/node/5') {
        throw new \RuntimeException('SYNTHETIC mapping failure');
      }
      return $path;
    });
    $this->container->set('path_alias.manager', $aliases);
    $this->expectWarnings([['@tag' => 'node:5']]);
    $this->queuer()->queueTags(['node:5', 'node:6']);
    $this->assertSame(['/node/6', '/node/6/'], $this->queuedPaths());
  }

  /**
   * An external queue failure is logged instead of escaping a content save.
   */
  public function testFailingPurgeQueueIsLoggedAndNotThrown(): void {
    $this->failQueue();
    $this->expectWarnings([['@msg' => 'SYNTHETIC queue failure']]);
    $this->queuer()->queueTags(['node:5']);
  }

}
