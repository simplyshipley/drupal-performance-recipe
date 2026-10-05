<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_purge_paths\Unit;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\file\FileInterface;
use Drupal\path_alias\PathAliasInterface;
use Drupal\perf_purge_paths\Hook\PerfPurgePathsHooks;
use Drupal\perf_purge_paths\PathMapperInterface;
use Drupal\perf_purge_paths\PathsQueuerInterface;
use Drupal\Tests\perf_purge_paths\Doubles\RecordingLogger;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for the alias and file hooks, with the services as mocks.
 *
 * The Kernel tests show what the hooks do on a real site; these show how they
 * fail: each step is guarded on its own, so one that throws is logged and does
 * not stop the other, and nothing is ever thrown at the code that deleted the
 * alias or the file.
 *
 * @group perf_purge_paths
 */
#[Group('perf_purge_paths')]
final class PerfPurgePathsHooksTest extends UnitTestCase {

  /**
   * The paths queuer mock.
   */
  private PathsQueuerInterface&MockObject $queuer;

  /**
   * The mapper mock.
   */
  private PathMapperInterface&MockObject $mapper;

  /**
   * The cache tags invalidator mock.
   */
  private CacheTagsInvalidatorInterface&MockObject $invalidator;

  /**
   * The logger.
   */
  private RecordingLogger $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->queuer = $this->createMock(PathsQueuerInterface::class);
    $this->mapper = $this->createMock(PathMapperInterface::class);
    $this->invalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
    $this->logger = new RecordingLogger();
  }

  /**
   * Builds the hook class around the mocks.
   */
  private function hooks(): PerfPurgePathsHooks {
    return new PerfPurgePathsHooks($this->queuer, $this->mapper, $this->invalidator, $this->logger);
  }

  /**
   * Creates an alias double with a path and an alias text.
   *
   * @param string $path
   *   The system path, such as /node/5.
   * @param string $alias
   *   The alias, with a leading slash.
   */
  private function alias(string $path, string $alias): PathAliasInterface {
    $double = $this->createMock(PathAliasInterface::class);
    $double->method('getPath')->willReturn($path);
    $double->method('getAlias')->willReturn($alias);
    return $double;
  }

  /**
   * Deleting an alias invalidates its entity's tags and queues the alias.
   */
  public function testDeleteInvalidatesTheEntityTagsAndQueuesTheAlias(): void {
    $this->mapper->method('tagsForSystemPath')->with('/node/5')->willReturn(['node:5']);
    $this->invalidator->expects($this->once())->method('invalidateTags')->with(['node:5']);
    $this->queuer->expects($this->once())->method('queuePaths')->with(['/synthetic-gone']);
    $this->hooks()->pathAliasDelete($this->alias('/node/5', '/synthetic-gone'));
    $this->assertSame([], $this->logger->records);
  }

  /**
   * An alias that points at no entity invalidates nothing.
   */
  public function testDeleteOfAnAliasForNoEntityInvalidatesNothing(): void {
    $this->mapper->method('tagsForSystemPath')->willReturn([]);
    $this->invalidator->expects($this->never())->method('invalidateTags');
    $this->queuer->expects($this->once())->method('queuePaths')->with(['/synthetic-gone']);
    $this->hooks()->pathAliasDelete($this->alias('/admin/config', '/synthetic-gone'));
  }

  /**
   * A queue that throws does not stop the page cache invalidation.
   *
   * The page cache does not depend on the CDN queue; an editor deleting an
   * alias must never leave Drupal answering it just because the queue broke.
   */
  public function testDeleteStillInvalidatesWhenTheQueueFails(): void {
    $this->mapper->method('tagsForSystemPath')->willReturn(['node:5']);
    $this->queuer->method('queuePaths')->willThrowException(new \RuntimeException('queue table is gone'));
    $this->invalidator->expects($this->once())->method('invalidateTags')->with(['node:5']);
    $this->hooks()->pathAliasDelete($this->alias('/node/5', '/synthetic-gone'));
    $this->assertCount(1, $this->logger->records);
    $this->assertSame('warning', $this->logger->records[0][0]);
    $this->assertSame('queue table is gone', $this->logger->records[0][2]['@msg']);
  }

  /**
   * An invalidation that throws does not stop the CDN queue.
   */
  public function testDeleteStillQueuesWhenTheInvalidationFails(): void {
    $this->mapper->method('tagsForSystemPath')->willReturn(['node:5']);
    $this->invalidator->method('invalidateTags')->willThrowException(new \RuntimeException('cache backend is gone'));
    $this->queuer->expects($this->once())->method('queuePaths')->with(['/synthetic-gone']);
    $this->hooks()->pathAliasDelete($this->alias('/node/5', '/synthetic-gone'));
    $this->assertCount(1, $this->logger->records);
    $this->assertSame('cache backend is gone', $this->logger->records[0][2]['@msg']);
  }

  /**
   * Deleting a file queues the paths the mapper finds for it.
   */
  public function testFileDeleteQueuesTheMappedPaths(): void {
    $file = $this->createMock(FileInterface::class);
    $this->mapper->method('pathsForFile')->with($file)->willReturn(['/sites/default/files/a.png']);
    $this->queuer->expects($this->once())->method('queuePaths')->with(['/sites/default/files/a.png']);
    $this->hooks()->fileDelete($file);
  }

  /**
   * A file that cannot be mapped is logged, not thrown.
   *
   * Cron deletes files; a purge problem must not stop it.
   */
  public function testFileDeleteLogsInsteadOfThrowing(): void {
    $this->mapper->method('pathsForFile')->willThrowException(new \RuntimeException('no such style'));
    $this->hooks()->fileDelete($this->createMock(FileInterface::class));
    $this->assertCount(1, $this->logger->records);
    $this->assertSame('no such style', $this->logger->records[0][2]['@msg']);
  }

}
