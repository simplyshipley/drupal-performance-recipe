<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_purge_paths\Unit;

use Drupal\Tests\perf_purge_paths\Traits\QueuerDoublesTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for the class that decides which Front Door paths are queued.
 *
 * The mapper and the purge queue are doubles, so these tests are about the
 * rules only: wildcards, trailing slashes, once-per-process, and failures.
 *
 * @group perf_purge_paths
 */
#[Group('perf_purge_paths')]
final class PathsQueuerTest extends UnitTestCase {

  use QueuerDoublesTrait;

  /**
   * A node tag queues its paths and their trailing-slash twins.
   */
  public function testTagQueuesItsPathsAndTheirTrailingSlashTwins(): void {
    $enqueuer = $this->createEnqueuer();
    $queuer = $this->createQueuer($this->createMapper(['node:5' => ['/node/5', '/about-us']]), $enqueuer, $this->createRecordingLogger());
    $queuer->queueTags(['node:5']);
    $this->assertSame(['/node/5', '/node/5/', '/about-us', '/about-us/'], $enqueuer->all());
  }

  /**
   * Twins are not made where a trailing slash makes no sense.
   *
   * @dataProvider pathsWithoutTwin
   */
  #[DataProvider('pathsWithoutTwin')]
  public function testSomePathsGetNoTrailingSlashTwin(string $path): void {
    $enqueuer = $this->createEnqueuer();
    $queuer = $this->createQueuer($this->createMapper([], ['node_list' => [$path]]), $enqueuer, $this->createRecordingLogger());
    $queuer->queueTags(['node_list']);
    $this->assertSame([$path], $enqueuer->all());
  }

  /**
   * The front page, wildcards and file paths.
   *
   * @return array<string, array{string}>
   *   One path each.
   */
  public static function pathsWithoutTwin(): array {
    return [
      'front page' => ['/'],
      'wildcard' => ['/news/*'],
      'sitewide wildcard' => ['/*'],
      'feed with an extension' => ['/rss.xml'],
      'image file' => ['/sites/default/files/photo.jpeg'],
    ];
  }

  /**
   * Turning twins off leaves only the paths themselves.
   */
  public function testTheTrailingSlashSettingTurnsTwinsOff(): void {
    $enqueuer = $this->createEnqueuer();
    $queuer = $this->createQueuer($this->createMapper(['node:5' => ['/about-us']]), $enqueuer, $this->createRecordingLogger(), ['perf_purge_trailing_slash' => FALSE]);
    $queuer->queueTags(['node:5']);
    $this->assertSame(['/about-us'], $enqueuer->all());
  }

  /**
   * Security F1: an editor's alias with "*" never becomes a wildcard.
   *
   * An alias is free text. "/*" would otherwise purge the whole endpoint on
   * every save of the node, as often as the editor likes. The path is skipped
   * and logged; the rest of the node's paths are still queued.
   */
  public function testContentPathWithWildcardIsNeverQueued(): void {
    $enqueuer = $this->createEnqueuer();
    $logger = $this->createRecordingLogger();
    $queuer = $this->createQueuer($this->createMapper(['node:5' => ['/node/5', '/*', '/news*']]), $enqueuer, $logger);
    $queuer->queueTags(['node:5']);
    $this->assertSame(['/node/5', '/node/5/'], $enqueuer->all());
    $this->assertCount(2, $logger->records);
    $this->assertSame('warning', $logger->records[0][0]);
    $this->assertSame('/*', $logger->records[0][2]['@path']);
  }

  /**
   * The same rule holds for paths handed to queuePaths() by the hooks.
   */
  public function testQueuePathsRefusesWildcardToo(): void {
    $enqueuer = $this->createEnqueuer();
    $queuer = $this->createQueuer($this->createMapper(), $enqueuer, $this->createRecordingLogger());
    $queuer->queuePaths(['/synthetic-*', '/about-us']);
    $this->assertSame(['/about-us', '/about-us/'], $enqueuer->all());
  }

  /**
   * Wildcards the operator configured are queued as they are.
   */
  public function testWildcardFromTheOperatorsSettingsIsQueued(): void {
    $enqueuer = $this->createEnqueuer();
    $queuer = $this->createQueuer($this->createMapper([], ['menu_link_content_list' => ['/*']]), $enqueuer, $this->createRecordingLogger());
    $queuer->queueTags(['menu_link_content_list']);
    $this->assertSame(['/*'], $enqueuer->all());
  }

  /**
   * A path is queued once per process, whatever maps to it.
   */
  public function testPathIsQueuedOnce(): void {
    $enqueuer = $this->createEnqueuer();
    $queuer = $this->createQueuer($this->createMapper(['node:5' => ['/node/5'], 'node:6' => ['/node/5']], ['node_list' => ['/']]), $enqueuer, $this->createRecordingLogger());
    $queuer->queueTags(['node:5', 'node_list']);
    $queuer->queueTags(['node:5', 'node:6', 'node_list']);
    $this->assertSame(['/node/5', '/node/5/', '/'], $enqueuer->all());
  }

  /**
   * A tag is mapped once per process, however often it is invalidated.
   *
   * Mapping a tag reads entities and aliases; a request that saves a node
   * invalidates the same tags several times.
   */
  public function testTagIsMappedOncePerProcess(): void {
    $mapper = $this->createMapper(['node:5' => ['/node/5']]);
    $queuer = $this->createQueuer($mapper, $this->createEnqueuer(), $this->createRecordingLogger());
    $queuer->queueTags(['node:5']);
    $queuer->queueTags(['node:5', 'node:5']);
    $this->assertSame(['node:5' => 1], $mapper->contentCalls);
  }

  /**
   * Nothing is queued while path purging is off.
   */
  public function testNothingIsQueuedWhenPurgingIsOff(): void {
    $enqueuer = $this->createEnqueuer();
    $queuer = $this->createQueuer($this->createMapper(['node:5' => ['/node/5']]), $enqueuer, $this->createRecordingLogger(), ['perf_purge_paths_enabled' => FALSE]);
    $queuer->queueTags(['node:5']);
    $queuer->queuePaths(['/about-us']);
    $this->assertSame([], $enqueuer->batches);
  }

  /**
   * Paths come back as a generator too.
   */
  public function testQueuePathsAcceptsGenerator(): void {
    $enqueuer = $this->createEnqueuer();
    $queuer = $this->createQueuer($this->createMapper(), $enqueuer, $this->createRecordingLogger());
    $queuer->queuePaths((static function (): \Generator {
      yield '/a';
      yield '/b';
    })());
    $this->assertSame(['/a', '/a/', '/b', '/b/'], $enqueuer->all());
  }

  /**
   * A tag that cannot be mapped is logged and the other tags still work.
   *
   * A failed purge must never fail a content save.
   */
  public function testMappingFailureIsLoggedAndTheOtherTagsStillWork(): void {
    $enqueuer = $this->createEnqueuer();
    $logger = $this->createRecordingLogger();
    $mapper = $this->createMapper(['node:6' => ['/node/6']], [], ['node:5' => new \RuntimeException('entity gone')]);
    $this->createQueuer($mapper, $enqueuer, $logger)->queueTags(['node:5', 'node:6']);
    $this->assertSame(['/node/6', '/node/6/'], $enqueuer->all());
    $this->assertSame('warning', $logger->records[0][0]);
    $this->assertStringContainsString('node:5', $logger->records[0][2]['@tag']);
  }

  /**
   * A purge queue that fails is logged, never thrown.
   *
   * Cron deletes files and editors save nodes: neither may break because the
   * queue could not be written.
   */
  public function testFailingPurgeQueueIsLoggedAndNotThrown(): void {
    $enqueuer = $this->createEnqueuer();
    $enqueuer->fail = new \RuntimeException('queue table is gone');
    $logger = $this->createRecordingLogger();
    $this->createQueuer($this->createMapper(['node:5' => ['/node/5']]), $enqueuer, $logger)->queueTags(['node:5']);
    $this->assertCount(1, $logger->records);
    $this->assertSame('queue table is gone', $logger->records[0][2]['@msg']);
  }

}
