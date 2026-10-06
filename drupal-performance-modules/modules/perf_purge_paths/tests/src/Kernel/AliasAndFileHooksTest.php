<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_purge_paths\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\Entity\File;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the hooks that run while an alias or a file is still in hand.
 *
 * The cache tags of a deleted entity are invalidated after it is gone, so the
 * tag mapping cannot find its alias or file URL any more. The module's hooks
 * queue them in time, and for an alias they also invalidate the aliased
 * entity's cache tags, which core does not do.
 *
 * @group perf_purge_paths
 */
#[Group('perf_purge_paths')]
final class AliasAndFileHooksTest extends PurgePathsKernelTestBase {

  /**
   * Stores a cache entry tagged with the node's tag and returns its id.
   */
  private function cacheEntryTaggedWithNode(int|string $nid): string {
    \Drupal::cache()->set('synthetic_probe', 'cached page', Cache::PERMANENT, ['node:' . $nid]);
    $this->assertNotFalse(\Drupal::cache()->get('synthetic_probe'), 'The probe entry is cached.');
    return 'synthetic_probe';
  }

  /**
   * Deleting an alias queues it, with its trailing-slash twin.
   */
  public function testDeletingAnAliasQueuesTheAlias(): void {
    $this->createNode('/synthetic-gone');
    $this->loadAlias('/synthetic-gone')->delete();
    $this->assertContains('/synthetic-gone', $this->queuedPaths());
    $this->assertContains('/synthetic-gone/', $this->queuedPaths());
  }

  /**
   * Deleting an alias invalidates the cache tags of the node it pointed to.
   *
   * Without this Drupal's page cache keeps answering the deleted alias with a
   * cached 200: core invalidates only "route_match" for an alias and page
   * cache entries do not expire.
   */
  public function testDeletingAnAliasInvalidatesTheNodesCacheTags(): void {
    $node = $this->createNode('/synthetic-gone');
    $cid = $this->cacheEntryTaggedWithNode($node->id());
    $this->loadAlias('/synthetic-gone')->delete();
    $this->assertFalse(\Drupal::cache()->get($cid), 'The page cached for the node is invalidated.');
  }

  /**
   * Renaming an alias queues the old one and not the new one twice.
   */
  public function testRenamingAnAliasQueuesTheOldAlias(): void {
    $this->createNode('/synthetic-old');
    $alias = $this->loadAlias('/synthetic-old');
    $alias->setAlias('/synthetic-new')->save();
    $this->assertContains('/synthetic-old', $this->queuedPaths());
    $this->assertContains('/synthetic-old/', $this->queuedPaths());
  }

  /**
   * Renaming an alias invalidates the cache tags of the node.
   */
  public function testRenamingAnAliasInvalidatesTheNodesCacheTags(): void {
    $node = $this->createNode('/synthetic-old');
    $cid = $this->cacheEntryTaggedWithNode($node->id());
    $this->loadAlias('/synthetic-old')->setAlias('/synthetic-new')->save();
    $this->assertFalse(\Drupal::cache()->get($cid));
  }

  /**
   * Saving an alias without changing its text queues nothing.
   */
  public function testSavingAnUnchangedAliasQueuesNothing(): void {
    $node = $this->createNode('/synthetic-same');
    $cid = $this->cacheEntryTaggedWithNode($node->id());
    $this->loadAlias('/synthetic-same')->save();
    $this->assertSame([], $this->queuedPaths());
    $this->assertNotFalse(\Drupal::cache()->get($cid), 'Nothing was invalidated either.');
  }

  /**
   * Deleting a node queues its alias, which is gone when its tags arrive.
   */
  public function testDeletingNodeQueuesItsAlias(): void {
    $node = $this->createNode('/synthetic-deleted-node');
    $node->delete();
    $this->assertContains('/synthetic-deleted-node', $this->queuedPaths());
    $this->assertContains('/node/' . $node->id(), $this->queuedPaths());
  }

  /**
   * Deleting a public image queues its URL and one URL per image style.
   */
  public function testDeletingPublicImageQueuesTheFileAndEveryStyle(): void {
    $this->createImageStyles('large', 'thumbnail');
    $file = $this->createFile('public://synthetic-gone.png');
    $file->delete();
    $queued = implode(' ', $this->queuedPaths());
    $this->assertStringContainsString('/files/synthetic-gone.png', $queued);
    $this->assertStringContainsString('/files/styles/large/public/synthetic-gone.png', $queued);
    $this->assertStringContainsString('/files/styles/thumbnail/public/synthetic-gone.png', $queued);
  }

  /**
   * Security F2: deleting a private or temporary file queues nothing.
   *
   * Cron deletes up to 100 temporary files per run. They are never cached at
   * the edge, and their names must not be sent to the Azure management API.
   */
  public function testDeletingPrivateOrTemporaryFileQueuesNothing(): void {
    $this->createImageStyles('large');
    foreach (['private://synthetic-hotline.png', 'temporary://synthetic-upload.png'] as $uri) {
      $this->createFile($uri)->delete();
      $this->assertSame([], $this->queuedPaths(), $uri);
    }
  }

  /**
   * Security F1: an alias with "*" is never queued as a wildcard.
   *
   * An editor with "create url aliases" can give a page the alias "/*". Saving
   * it, renaming it and deleting it must never queue a wildcard purge.
   */
  public function testAnAliasWithWildcardIsNeverQueued(): void {
    $node = $this->createNode('/synthetic-*');
    $node->setTitle('SYNTHETIC node, saved again')->save();
    $this->loadAlias('/synthetic-*')->setAlias('/synthetic-*-renamed')->save();
    $this->loadAlias('/synthetic-*-renamed')->delete();
    $node->delete();
    foreach ($this->queuedPaths() as $path) {
      $this->assertStringNotContainsString('*', $path, 'A content-derived wildcard was queued.');
    }
    $this->assertNotEmpty($this->queuedPaths(), 'The ordinary paths of the node were still queued.');
  }

  /**
   * Nothing is queued while path purging is off.
   */
  public function testNothingIsQueuedWhenPurgingIsOff(): void {
    $this->createNode('/synthetic-off');
    $this->overridePurging(FALSE);
    $this->loadAlias('/synthetic-off')->delete();
    $this->createFile('public://synthetic-off.png')->delete();
    $this->assertSame([], $this->queuedPaths());
  }

  /**
   * A purge queue that fails stops neither the rename nor the invalidation.
   */
  public function testFailingQueueDoesNotStopTheRename(): void {
    $node = $this->createNode('/synthetic-old');
    $cid = $this->cacheEntryTaggedWithNode($node->id());
    $this->failQueue();
    $this->loadAlias('/synthetic-old')->setAlias('/synthetic-new')->save();
    $this->loadAlias('/synthetic-new');
    $this->assertFalse(\Drupal::cache()->get($cid), 'The page cache is still invalidated.');
  }

  /**
   * A purge queue that fails stops neither the delete nor the invalidation.
   *
   * Cron deletes files and editors delete aliases: neither may fail because
   * the queue could not be written. Drupal's own page cache must still be
   * invalidated, as it does not depend on the CDN queue.
   */
  public function testFailingQueueDoesNotStopTheDelete(): void {
    $node = $this->createNode('/synthetic-failing');
    $cid = $this->cacheEntryTaggedWithNode($node->id());
    $this->failQueue();
    $this->loadAlias('/synthetic-failing')->delete();
    $this->assertSame([], \Drupal::entityTypeManager()->getStorage('path_alias')->loadByProperties(['alias' => '/synthetic-failing']));
    $this->assertFalse(\Drupal::cache()->get($cid), 'The page cache is still invalidated.');
  }

  /**
   * A failed cache-tag invalidation cannot prevent queuing the old alias.
   */
  public function testRenameStillQueuesTheOldAliasWhenTheInvalidationFails(): void {
    $this->createNode('/synthetic-old');
    $this->failInvalidation();
    $this->loadAlias('/synthetic-old')->setAlias('/synthetic-new')->save();
    $this->loadAlias('/synthetic-new');
    $this->assertContains('/synthetic-old', $this->queuedPaths());
  }

  /**
   * Alias deletion survives the same independent invalidation failure.
   */
  public function testDeleteStillQueuesTheAliasWhenTheInvalidationFails(): void {
    $this->createNode('/synthetic-gone');
    $this->failInvalidation();
    $this->loadAlias('/synthetic-gone')->delete();
    $this->assertContains('/synthetic-gone', $this->queuedPaths());
  }

  /**
   * A failed URL generator cannot prevent the real file entity deletion.
   */
  public function testFileDeleteIsNotStoppedWhenItsPathsCannotBeBuilt(): void {
    $file = $this->createFile('public://synthetic-broken.png');
    $generator = $this->createMock(FileUrlGeneratorInterface::class);
    $generator->expects($this->once())->method('generateString')->willThrowException(new \RuntimeException('SYNTHETIC URL failure'));
    $this->container->set('file_url_generator', $generator);
    $this->expectWarnings([['@msg' => 'SYNTHETIC URL failure']]);
    $file->delete();
    $this->assertNull(File::load($file->id()));
  }

  /**
   * Adds a failing external invalidator without replacing any module service.
   */
  private function failInvalidation(): void {
    $invalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
    $invalidator->method('invalidateTags')->willReturnCallback(static function (array $tags): void {
      if (array_filter($tags, static fn (string $tag): bool => str_starts_with($tag, 'node:'))) {
        throw new \RuntimeException('SYNTHETIC invalidation failure');
      }
    });
    $this->container->get('cache_tags.invalidator')->addInvalidator($invalidator);
    $this->expectWarnings([['@msg' => 'SYNTHETIC invalidation failure']]);
  }

}
