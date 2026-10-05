<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_purge_paths\Kernel;

use Drupal\perf_purge_paths\PathMapper;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the mapping from cache tags and files to the paths a CDN may hold.
 *
 * @group perf_purge_paths
 */
#[Group('perf_purge_paths')]
final class PathMapperTest extends PurgePathsKernelTestBase {

  /**
   * Returns the mapper under test.
   */
  private function mapper(): PathMapper {
    return \Drupal::service(PathMapper::class);
  }

  /**
   * A node tag maps to the system path and the current alias.
   */
  public function testNodeTagMapsToTheSystemPathAndTheAlias(): void {
    $node = $this->createNode('/synthetic-about');
    $this->assertSame(['/node/' . $node->id(), '/synthetic-about'], $this->mapper()->contentPathsForTag('node:' . $node->id()));
  }

  /**
   * A node without an alias maps to its system path twice, once is enough.
   */
  public function testNodeWithoutAnAliasMapsToItsSystemPath(): void {
    $node = $this->createNode('/synthetic-temp');
    $this->loadAlias('/synthetic-temp')->delete();
    $paths = array_unique($this->mapper()->contentPathsForTag('node:' . $node->id()));
    $this->assertSame(['/node/' . $node->id()], array_values($paths));
  }

  /**
   * A user tag maps to the user's page.
   */
  public function testUserTagMapsToTheUserPage(): void {
    $user = User::create(['name' => 'synthetic_user', 'mail' => 'synthetic_user@example.invalid', 'status' => 1]);
    $user->save();
    $this->assertContains('/user/' . $user->id(), $this->mapper()->contentPathsForTag('user:' . $user->id()));
  }

  /**
   * Tags that name no content map to nothing.
   */
  public function testUnrelatedTagsMapToNothing(): void {
    $this->assertSame([], $this->mapper()->contentPathsForTag('config:system.site'));
    $this->assertSame([], $this->mapper()->contentPathsForTag('route_match'));
    $this->assertSame([], $this->mapper()->configuredPathsForTag('node:5'));
  }

  /**
   * List tags map to the operator's list paths.
   */
  public function testListTagsMapToTheConfiguredListPaths(): void {
    $this->assertSame(['/', '/node'], $this->mapper()->configuredPathsForTag('node_list'));
    $this->assertSame(['/', '/node'], $this->mapper()->configuredPathsForTag('taxonomy_term_list'));
    $this->assertSame(['/', '/node'], $this->mapper()->configuredPathsForTag('node_list:page'));
  }

  /**
   * Menu and block tags map to the operator's site-wide paths.
   */
  public function testMenuAndBlockTagsMapToTheSitewidePaths(): void {
    $this->assertSame([], $this->mapper()->configuredPathsForTag('config:system.menu.main'));
    $this->setSetting('perf_purge_sitewide_paths', '/*, /about');
    $this->assertSame(['/*', '/about'], $this->mapper()->configuredPathsForTag('config:system.menu.main'));
    $this->assertSame(['/*', '/about'], $this->mapper()->configuredPathsForTag('block_content:3'));
    $this->assertSame(['/*', '/about'], $this->mapper()->configuredPathsForTag('menu_link_content:7'));
  }

  /**
   * A list setting can be a string or an array.
   */
  public function testListSettingCanBeAnArray(): void {
    $this->setSetting('perf_purge_list_paths', ['/', ' /news ', '']);
    $this->assertSame(['/', '/news'], $this->mapper()->configuredPathsForTag('node_list'));
  }

  /**
   * The path_alias branch maps the alias and the previous revision's alias.
   *
   * Core never invalidates a path_alias tag, so this branch is dormant on
   * stock core. It stays because a core or contrib change that starts
   * invalidating it would otherwise lose the old alias.
   */
  public function testPathAliasTagMapsTheAliasAndItsPreviousRevision(): void {
    $this->createNode('/synthetic-old');
    $alias = $this->loadAlias('/synthetic-old');
    $alias->setNewRevision(TRUE);
    $alias->setAlias('/synthetic-new')->save();
    $paths = $this->mapper()->contentPathsForTag('path_alias:' . $alias->id());
    $this->assertContains('/synthetic-new', $paths);
    $this->assertContains('/synthetic-old', $paths);
  }

  /**
   * System paths of entities map back to their cache tags.
   */
  public function testSystemPathsMapBackToTheirTags(): void {
    $this->assertSame(['node:5'], $this->mapper()->tagsForSystemPath('/node/5'));
    $this->assertSame(['user:3'], $this->mapper()->tagsForSystemPath('/user/3'));
    $this->assertSame(['taxonomy_term:9'], $this->mapper()->tagsForSystemPath('/taxonomy/term/9'));
    $this->assertSame([], $this->mapper()->tagsForSystemPath('/admin/config'));
    $this->assertSame([], $this->mapper()->tagsForSystemPath('/node/5/edit'));
  }

  /**
   * A public image maps to its URL and to every image style.
   */
  public function testPublicImageMapsToItsUrlAndEveryImageStyle(): void {
    $this->createImageStyles('large', 'thumbnail');
    $file = $this->createFile('public://synthetic-photo.png');
    $paths = $this->mapper()->contentPathsForTag('file:' . $file->id());
    $this->assertCount(3, $paths);
    $this->assertStringEndsWith('/files/synthetic-photo.png', $paths[0]);
    $this->assertStringEndsWith('/files/styles/large/public/synthetic-photo.png', $paths[1]);
    $this->assertStringEndsWith('/files/styles/thumbnail/public/synthetic-photo.png', $paths[2]);
    foreach ($paths as $path) {
      $this->assertStringNotContainsString('?', $path, 'No query string: Front Door purges by path.');
      $this->assertStringNotContainsString('http', $path, 'No host: a path.');
    }
  }

  /**
   * Security F2: a file that is not an image gets no image style URLs.
   */
  public function testFileThatIsNotAnImageGetsOnlyItsOwnUrl(): void {
    $this->createImageStyles('large');
    $file = $this->createFile('public://synthetic-report.pdf');
    $paths = $this->mapper()->contentPathsForTag('file:' . $file->id());
    $this->assertCount(1, $paths);
    $this->assertStringEndsWith('/files/synthetic-report.pdf', $paths[0]);
  }

  /**
   * Security F2: private and temporary files are never sent to the CDN.
   *
   * They are never cached at the edge, and their names (an upload's file name
   * can be personal) must not reach the Azure management API.
   */
  public function testPrivateAndTemporaryFilesMapToNothing(): void {
    $this->createImageStyles('large');
    foreach (['private://synthetic-hotline.png', 'temporary://synthetic-upload.png'] as $uri) {
      $file = $this->createFile($uri);
      $this->assertSame([], $this->mapper()->contentPathsForTag('file:' . $file->id()), $uri);
      $this->assertSame([], $this->mapper()->pathsForFile($file), $uri);
    }
  }

  /**
   * A file that no longer exists maps to nothing.
   */
  public function testMissingFileMapsToNothing(): void {
    $this->assertSame([], $this->mapper()->contentPathsForTag('file:999999'));
  }

}
