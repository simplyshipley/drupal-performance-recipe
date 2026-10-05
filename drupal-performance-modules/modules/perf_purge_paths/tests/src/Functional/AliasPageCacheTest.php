<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_purge_paths\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests what visitors see after an alias is deleted or renamed.
 *
 * The requests are real HTTP through Drupal's page cache. Page cache entries
 * do not expire, and core invalidates only "route_match" when an alias goes,
 * so without the module a visitor keeps getting the cached 200 for an alias
 * that no longer exists. The first test is the control: it shows that
 * behaviour with the module not installed, so the other two tests can only
 * pass because of the module. If core ever starts invalidating the entity on
 * an alias change the control goes red, and the module's alias hooks can be
 * reviewed. All content is SYNTHETIC.
 *
 * @group perf_purge_paths
 */
#[Group('perf_purge_paths')]
final class AliasPageCacheTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'path', 'page_cache'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'page']);
  }

  /**
   * Installs the module under test, as the recipe does.
   */
  private function installModule(): void {
    $this->container->get('module_installer')->install(['perf_purge_paths']);
    $this->rebuildContainer();
  }

  /**
   * Creates a published page with an alias.
   *
   * @param string $alias
   *   The alias, with a leading slash.
   */
  private function createPage(string $alias): void {
    $this->drupalCreateNode(['type' => 'page', 'title' => 'SYNTHETIC page', 'path' => ['alias' => $alias]]);
  }

  /**
   * Visits the alias twice as a visitor; the second answer is the page cache.
   *
   * @param string $alias
   *   The alias, with a leading slash.
   */
  private function warmPageCache(string $alias): void {
    $this->drupalGet($alias);
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet($alias);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'HIT');
  }

  /**
   * Loads the alias entity.
   *
   * @param string $alias
   *   The alias, with a leading slash.
   *
   * @return \Drupal\path_alias\PathAliasInterface
   *   The path alias entity.
   */
  private function loadAlias(string $alias) {
    $aliases = $this->container->get('entity_type.manager')->getStorage('path_alias')->loadByProperties(['alias' => $alias]);
    $this->assertCount(1, $aliases, "Alias $alias exists.");
    return reset($aliases);
  }

  /**
   * Control: without the module a deleted alias is still answered from cache.
   */
  public function testWithoutTheModuleDeletedAliasIsStillServedFromPageCache(): void {
    $this->createPage('/synthetic-control');
    $this->warmPageCache('/synthetic-control');
    $this->loadAlias('/synthetic-control')->delete();
    $this->drupalGet('/synthetic-control');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'HIT');
  }

  /**
   * With the module a deleted alias stops answering at once.
   */
  public function testDeletedAliasStopsAnsweringForVisitors(): void {
    $this->installModule();
    $this->createPage('/synthetic-deleted');
    $this->warmPageCache('/synthetic-deleted');
    $this->loadAlias('/synthetic-deleted')->delete();
    $this->drupalGet('/synthetic-deleted');
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * With the module a renamed alias stops answering, and the new one works.
   */
  public function testRenamedAliasStopsAnsweringForVisitors(): void {
    $this->installModule();
    $this->createPage('/synthetic-old');
    $this->warmPageCache('/synthetic-old');
    $this->loadAlias('/synthetic-old')->setAlias('/synthetic-new')->save();
    $this->drupalGet('/synthetic-old');
    $this->assertSession()->statusCodeEquals(404);
    $this->drupalGet('/synthetic-new');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('SYNTHETIC page');
  }

}
