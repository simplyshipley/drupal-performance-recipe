<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\perf_redis_failopen\Cache\FailOpenCacheBackend;
use Drupal\perf_redis_failopen\Cache\FailOpenCacheBackendFactory;
use Drupal\Tests\perf_redis_failopen\Traits\RecordingDoublesTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for the decorator of the redis module's cache factory.
 *
 * @group perf_redis_failopen
 */
#[Group('perf_redis_failopen')]
final class FailOpenCacheBackendFactoryTest extends UnitTestCase {

  use RecordingDoublesTrait;

  /**
   * Builds the decorator around a decorated factory.
   *
   * @param \Drupal\Core\Cache\CacheFactoryInterface $inner
   *   The decorated factory.
   * @param array<string, mixed> $settings
   *   Site settings.
   */
  private function factory(CacheFactoryInterface $inner, array $settings = []): FailOpenCacheBackendFactory {
    $health = $this->createHealth($this->createStore(), $this->createLogger());
    return new FailOpenCacheBackendFactory($inner, $health, new Settings($settings));
  }

  /**
   * Each bin gets one wrapper, created once.
   */
  public function testEachBinIsWrappedOnce(): void {
    $factory = $this->factory($this->createMock(CacheFactoryInterface::class));
    $render = $factory->get('render');
    $this->assertInstanceOf(FailOpenCacheBackend::class, $render);
    $this->assertSame($render, $factory->get('render'));
    $this->assertNotSame($render, $factory->get('page'));
  }

  /**
   * The decorated factory connects to Redis, so it is not asked until needed.
   *
   * Handing out the wrapper must not open a connection: the first real cache
   * call does, inside the wrapper, where an error becomes a miss.
   */
  public function testTheDecoratedFactoryIsNotAskedWhenTheWrapperIsCreated(): void {
    $inner = $this->createMock(CacheFactoryInterface::class);
    $inner->expects($this->never())->method('get');
    $this->factory($inner)->get('render');
  }

  /**
   * With the wrapper switched off the plain backends come back.
   *
   * That is how the fail-proof tests show that the wrapper is what keeps the
   * site up: the same Redis outage then ends in an error.
   */
  public function testSwitchedOffReturnsThePlainBackends(): void {
    $plain = $this->createMock(CacheBackendInterface::class);
    $inner = $this->createMock(CacheFactoryInterface::class);
    $inner->expects($this->once())->method('get')->with('render')->willReturn($plain);
    $factory = $this->factory($inner, ['perf_redis_failopen_enabled' => FALSE]);
    $this->assertSame($plain, $factory->get('render'));
  }

  /**
   * The wrapper is on unless the setting says otherwise.
   */
  public function testTheWrapperIsOnByDefault(): void {
    $this->assertInstanceOf(FailOpenCacheBackend::class, $this->factory($this->createMock(CacheFactoryInterface::class))->get('render'));
  }

}
