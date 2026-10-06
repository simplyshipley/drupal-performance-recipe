<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\perf_redis_failopen\Cache\DatabasePendingFlushStore;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the pending-flush store against a real database table.
 *
 * Covers the actual SQL merge and compare-and-delete through its service.
 *
 * @group perf_redis_failopen
 */
#[Group('perf_redis_failopen')]
final class DatabasePendingFlushStoreTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'redis', 'perf_redis_failopen'];

  /**
   * The store under test.
   */
  private DatabasePendingFlushStore $store;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('perf_redis_failopen', [DatabasePendingFlushStore::TABLE]);
    $this->store = $this->container->get(DatabasePendingFlushStore::class);
  }

  /**
   * A recorded bin is listed with its token.
   */
  public function testRecordedBinIsListed(): void {
    $this->assertSame([], $this->store->all());
    $this->store->record('render');
    $all = $this->store->all();
    $this->assertSame(['render'], array_keys($all));
    $this->assertNotSame('', $all['render']);
  }

  /**
   * Recording a bin again keeps one row and changes its token.
   */
  public function testRecordingTwiceKeepsOneRowWithNewToken(): void {
    $this->store->record('render');
    $first = $this->store->all()['render'];
    $this->store->record('render');
    $all = $this->store->all();
    $this->assertCount(1, $all);
    $this->assertNotSame($first, $all['render']);
  }

  /**
   * A flush clears the record only when its token is still the one it read.
   */
  public function testClearRemovesOnlyTheRecordWithTheSameToken(): void {
    $this->store->record('render');
    $this->store->record('page');
    $token = $this->store->all()['render'];
    $this->assertFalse($this->store->clear('render', 'not-the-token'), 'A wrong token clears nothing.');
    $this->assertArrayHasKey('render', $this->store->all());
    $this->assertTrue($this->store->clear('render', $token));
    $this->assertSame(['page'], array_keys($this->store->all()), 'Other bins are untouched.');
  }

  /**
   * A record written after the flush read its token survives the flush.
   *
   * This is the case that makes the token necessary: another worker loses a
   * write for the same bin while this one is emptying it.
   */
  public function testNewerRecordSurvivesFlushThatReadTheOlderToken(): void {
    $this->store->record('render');
    $oldToken = $this->store->all()['render'];
    $this->store->record('render');
    $this->assertFalse($this->store->clear('render', $oldToken));
    $this->assertArrayHasKey('render', $this->store->all());
  }

  /**
   * A missing table means nothing is pending, not an error.
   *
   * The wrapper is live for a moment before the table exists (module install)
   * and after it is dropped (module uninstall); a request in that moment must
   * not fail.
   */
  public function testMissingTableMeansNothingIsPending(): void {
    $this->container->get('database')->schema()->dropTable(DatabasePendingFlushStore::TABLE);
    $this->assertSame([], $this->store->all());
    $this->assertTrue($this->store->clear('render', 'synthetic-token'), 'Nothing is left to clear.');
  }

  /**
   * A record made before the table exists is written by hook_install().
   *
   * Core creates cache bins before it creates the module's table
   * (ModuleInstaller::install() builds the route provider, which needs
   * cache.data, before installSchema()), so a bin switched off, or a delete
   * lost to an outage, in that moment cannot be written yet.
   */
  public function testRecordMadeBeforeTheTableExistsIsWrittenOnInstall(): void {
    $this->container->get('database')->schema()->dropTable(DatabasePendingFlushStore::TABLE);
    $this->store->record('data', 'off-');
    $this->installSchema('perf_redis_failopen', [DatabasePendingFlushStore::TABLE]);
    $before = $this->store->all();
    $this->assertSame([], $before);

    $this->container->get('module_handler')->loadInclude('perf_redis_failopen', 'install');
    \perf_redis_failopen_install();
    $all = $this->store->all();
    $this->assertSame(['data'], array_keys($all));
    $this->assertStringStartsWith('off-', $all['data']);
  }

}
