<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_purge_paths\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\file\Entity\File;
use Drupal\Tests\perf_purge_paths\Doubles\ThrowingTagMapper;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that alias changes and file deletes survive a failing mapping step.
 *
 * The mapper throws here for system paths and files, as a broken entity
 * definition or stream wrapper would. The editor's rename must still be saved,
 * the old alias must still be queued for the CDN, and a file delete (which
 * cron runs) must still complete.
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

  /**
   * A file whose URLs cannot be built is still deleted (cron deletes files).
   */
  public function testFileDeleteIsNotStoppedWhenItsPathsCannotBeBuilt(): void {
    $file = $this->createFile('public://synthetic-broken.png');
    $file->delete();
    $this->assertNull(File::load($file->id()));
  }

}
