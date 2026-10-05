<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_purge_paths\Unit;

use Drupal\perf_purge_paths\ImplementationFilter;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for the filter that drops azure_cdn_purge's node hook.
 *
 * @group perf_purge_paths
 */
#[Group('perf_purge_paths')]
final class ImplementationFilterTest extends UnitTestCase {

  /**
   * The contrib module's node_update implementation is removed, others stay.
   */
  public function testItRemovesOnlyAzureCdnPurgeFromNodeUpdate(): void {
    $implementations = [
      'azure_cdn_purge' => 'azure_cdn_purge_node_update',
      'path' => 'path_node_update',
      'perf_purge_paths' => 'perf_purge_paths_node_update',
    ];
    ImplementationFilter::apply($implementations, 'node_update');
    $this->assertSame([
      'path' => 'path_node_update',
      'perf_purge_paths' => 'perf_purge_paths_node_update',
    ], $implementations);
  }

  /**
   * Other hooks are left alone, even for that module.
   */
  public function testOtherHooksAreUntouched(): void {
    $implementations = ['azure_cdn_purge' => 'x', 'path' => 'y'];
    ImplementationFilter::apply($implementations, 'node_insert');
    $this->assertSame(['azure_cdn_purge' => 'x', 'path' => 'y'], $implementations);
  }

  /**
   * A list without the module is not an error.
   */
  public function testListWithoutTheModuleIsNotAnError(): void {
    $implementations = ['path' => 'y'];
    ImplementationFilter::apply($implementations, 'node_update');
    $this->assertSame(['path' => 'y'], $implementations);
  }

}
