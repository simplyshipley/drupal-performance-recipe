<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_purge_paths\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\purge\Plugin\Purge\Invalidation\ImmutableInvalidationInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that core's cache tag invalidations reach Purge's queue as paths.
 *
 * @group perf_purge_paths
 */
#[Group('perf_purge_paths')]
final class TagInvalidationTest extends PurgePathsKernelTestBase {

  /**
   * Saving a node queues its paths, their twins and the listings.
   */
  public function testSavingNodeQueuesItsPathsAndTheListings(): void {
    $node = $this->createNode('/synthetic-saved');
    $node->setTitle('SYNTHETIC node, edited')->save();
    $queued = $this->queuedPaths();
    foreach (['/node/' . $node->id(), '/synthetic-saved', '/synthetic-saved/', '/', '/node'] as $path) {
      $this->assertContains($path, $queued, $path);
    }
  }

  /**
   * Saving a taxonomy term queues its page and that page's twin.
   */
  public function testSavingTermQueuesItsPage(): void {
    $this->installEntitySchema('taxonomy_term');
    $term = $this->withoutPurging(static function (): Term {
      Vocabulary::create(['vid' => 'synthetic', 'name' => 'SYNTHETIC vocabulary'])->save();
      $term = Term::create(['vid' => 'synthetic', 'name' => 'SYNTHETIC term']);
      $term->save();
      return $term;
    });
    $term->setName('SYNTHETIC term, edited')->save();
    $queued = $this->queuedPaths();
    $this->assertContains('/taxonomy/term/' . $term->id(), $queued);
    $this->assertContains('/taxonomy/term/' . $term->id() . '/', $queued);
  }

  /**
   * A file tag queues the file URL and every image style URL, as paths.
   */
  public function testFileTagQueuesTheFileAndEveryImageStyle(): void {
    $this->createImageStyles('large', 'thumbnail');
    $file = $this->createFile('public://synthetic-photo.png');
    Cache::invalidateTags(['file:' . $file->id()]);
    $queued = implode(' ', $this->queuedPaths());
    $this->assertStringContainsString('/files/synthetic-photo.png', $queued);
    $this->assertStringContainsString('/files/styles/large/public/synthetic-photo.png', $queued);
    $this->assertStringContainsString('/files/styles/thumbnail/public/synthetic-photo.png', $queued);
    $this->assertStringNotContainsString('?', $queued, 'No query string: Front Door purges by path.');
    $this->assertStringNotContainsString('http', $queued, 'No host: a path.');
  }

  /**
   * A menu change queues the operator's site-wide "/*" as a wildcard.
   */
  public function testMenuChangeQueuesTheSitewideWildcard(): void {
    $this->setSetting('perf_purge_sitewide_paths', '/*');
    Cache::invalidateTags(['config:system.menu.main']);
    $this->assertSame([['wildcardpath', '*']], $this->queuedTypes());
  }

  /**
   * A wildcard in the operator's list paths is queued as a wildcard.
   */
  public function testOperatorListWildcardIsQueuedAsWildcard(): void {
    $this->setSetting('perf_purge_list_paths', '/synthetic-news/*');
    Cache::invalidateTags(['node_list']);
    $this->assertSame([['wildcardpath', 'synthetic-news/*']], $this->queuedTypes());
  }

  /**
   * Nothing is queued while path purging is off.
   */
  public function testNothingIsQueuedWhilePurgingIsOff(): void {
    $node = $this->createNode('/synthetic-off');
    $this->overridePurging(FALSE);
    $node->setTitle('SYNTHETIC node, edited')->save();
    Cache::invalidateTags(['node:' . $node->id(), 'node_list']);
    $this->assertSame([], $this->queuedPaths());
  }

  /**
   * Security F1: a node whose alias contains "*" queues no wildcard.
   *
   * The editor's alias is dropped (and logged); the node's own system path
   * and the operator's list paths are still queued.
   */
  public function testAnAliasWithWildcardIsDroppedWhenTheNodeIsSaved(): void {
    $node = $this->createNode('/synthetic-*');
    $node->setTitle('SYNTHETIC node, edited')->save();
    $queued = $this->queuedPaths();
    foreach ($queued as $path) {
      $this->assertStringNotContainsString('*', $path);
    }
    $this->assertContains('/node/' . $node->id(), $queued);
  }

  /**
   * A purge queue failure is logged instead of escaping a content save.
   */
  public function testFailingPurgeQueueIsLoggedAndNotThrown(): void {
    $this->failQueue();
    $this->expectWarnings([['@msg' => 'SYNTHETIC queue failure']]);
    $this->container->get('perf_purge_paths.queuer')->queueTags(['node:5']);
  }

  /**
   * Returns the queued invalidations as [type, expression] pairs.
   *
   * @return array<int, array{string, string}>
   *   The type and expression of each item.
   */
  private function queuedTypes(): array {
    return array_map(
      static fn (ImmutableInvalidationInterface $item): array => [$item->getType(), $item->getExpression()],
      $this->queuedItems(),
    );
  }

}
