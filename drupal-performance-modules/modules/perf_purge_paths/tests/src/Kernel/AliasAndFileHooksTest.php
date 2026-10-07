<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_purge_paths\Kernel;

use Drupal\Core\Cache\Cache;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the hooks that queue a deleted or renamed alias and a deleted file.
 *
 * @group perf_purge_paths
 */
#[Group('perf_purge_paths')]
final class AliasAndFileHooksTest extends PurgePathsKernelTestBase {

  /**
   * Stores a cache entry tagged with the node's tag and returns its id.
   */
  private function cacheEntryTaggedWithNode(int|string $nid): string {
    $cid = 'synthetic_probe:' . $nid;
    \Drupal::cache()->set($cid, 'cached page', Cache::PERMANENT, ['node:' . $nid]);
    $this->assertNotFalse(\Drupal::cache()->get($cid), 'The probe entry is cached.');
    return $cid;
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
   * Pointing an alias at another node, same text, queues the alias itself.
   *
   * The admin "URL aliases" form can do this. The other node has a newer alias
   * of its own, so mapping its tag does not queue the retargeted alias.
   */
  public function testRetargetingAnAliasQueuesTheAlias(): void {
    $this->createNode('/synthetic-news');
    $new = $this->createNode('/synthetic-other');
    $this->loadAlias('/synthetic-news')->setPath('/node/' . $new->id())->save();
    $this->assertContains('/synthetic-news', $this->queuedPaths());
    $this->assertContains('/synthetic-news/', $this->queuedPaths());
  }

  /**
   * Retargeting an alias invalidates the cache tags of both nodes.
   */
  public function testRetargetingAnAliasInvalidatesBothNodesCacheTags(): void {
    $old = $this->createNode('/synthetic-news');
    $new = $this->createNode('/synthetic-other');
    $cids = [$this->cacheEntryTaggedWithNode($old->id()), $this->cacheEntryTaggedWithNode($new->id())];
    $this->loadAlias('/synthetic-news')->setPath('/node/' . $new->id())->save();
    foreach ($cids as $cid) {
      $this->assertFalse(\Drupal::cache()->get($cid), "$cid was invalidated.");
    }
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

}
