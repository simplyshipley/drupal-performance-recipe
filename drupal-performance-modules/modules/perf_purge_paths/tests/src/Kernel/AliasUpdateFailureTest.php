<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_purge_paths\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\perf_purge_paths\Doubles\ThrowingTagMapper;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that an alias rename survives a failing cache invalidation step.
 *
 * The mapper that turns a system path into entity cache tags throws here, as a
 * broken entity definition would. The editor's rename must still be saved and
 * the old alias must still be queued for the CDN.
 *
 * @group perf_purge_paths
 */
#[Group('perf_purge_paths')]
final class AliasUpdateFailureTest extends PurgePathsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('perf_purge_paths.mapper', ThrowingTagMapper::class)->setPublic(TRUE);
  }

  /**
   * The rename is saved and the old alias is queued.
   */
  public function testRenameStillQueuesTheOldAliasWhenTheInvalidationFails(): void {
    $this->createNode('/synthetic-old');
    $this->loadAlias('/synthetic-old')->setAlias('/synthetic-new')->save();
    $this->loadAlias('/synthetic-new');
    $this->assertContains('/synthetic-old', $this->recorder()->all());
  }

  /**
   * Deleting the alias is not stopped either.
   */
  public function testDeleteStillQueuesTheAliasWhenTheInvalidationFails(): void {
    $this->createNode('/synthetic-gone');
    $this->loadAlias('/synthetic-gone')->delete();
    $this->assertContains('/synthetic-gone', $this->recorder()->all());
  }

}
