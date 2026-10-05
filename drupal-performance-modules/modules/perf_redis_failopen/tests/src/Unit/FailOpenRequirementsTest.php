<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Unit;

use Drupal\Core\Cache\CacheFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\perf_redis_failopen\Cache\FailOpenCacheBackendFactory;
use Drupal\perf_redis_failopen\Cache\PendingFlushStoreInterface;
use Drupal\perf_redis_failopen\Client\ClientOptions;
use Drupal\perf_redis_failopen\Client\TimeoutClientInstaller;
use Drupal\perf_redis_failopen\Requirements\FailOpenRequirements;
use Drupal\redis\ClientFactory;
use Drupal\Tests\perf_redis_failopen\Traits\ClientFactoryStateTrait;
use Drupal\Tests\perf_redis_failopen\Traits\LocalPortTrait;
use Drupal\Tests\perf_redis_failopen\Traits\RecordingDoublesTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * Unit tests for the status report entry of the Redis fail-open module.
 *
 * A closed local port stands in for a Redis that does not answer; no server is
 * needed.
 *
 * @group perf_redis_failopen
 * @requires extension redis
 */
#[Group('perf_redis_failopen')]
#[RequiresPhpExtension('redis')]
final class FailOpenRequirementsTest extends UnitTestCase {

  use ClientFactoryStateTrait;
  use LocalPortTrait;
  use RecordingDoublesTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->resetClientFactory();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->resetClientFactory();
    parent::tearDown();
  }

  /**
   * Settings for a site whose Redis bins point at a port nobody listens on.
   *
   * @param array<string, mixed> $more
   *   Settings that replace or add to these.
   *
   * @return array<string, mixed>
   *   The settings.
   */
  private function unreachable(array $more = []): array {
    return $more + [
      'cache' => ['bins' => ['render' => 'cache.backend.redis']],
      'redis.connection' => ['interface' => 'PhpRedis', 'host' => '127.0.0.1', 'port' => $this->closedPort()],
      'perf_redis_connect_timeout' => 0.4,
    ];
  }

  /**
   * Builds the requirements service around fresh doubles.
   *
   * @param array<string, mixed> $settings
   *   Site settings.
   * @param \Drupal\Core\Cache\CacheFactoryInterface|null $redisFactory
   *   The cache.backend.redis service; the decorator by default.
   * @param \Drupal\perf_redis_failopen\Cache\PendingFlushStoreInterface|null $store
   *   The lost-write store.
   * @param bool $install
   *   Whether the bounded client is installed first, as the container does.
   */
  private function requirements(array $settings, ?CacheFactoryInterface $redisFactory = NULL, ?PendingFlushStoreInterface $store = NULL, bool $install = TRUE): FailOpenRequirements {
    // Drupal's Settings is a singleton that the last "new Settings()" replaces,
    // so everything that builds its own (createHealth) goes first.
    $health = $this->createHealth($this->createStore(), $this->createLogger());
    $site = new Settings($settings);
    $options = new ClientOptions($site);
    if ($install) {
      (new TimeoutClientInstaller($options))->install(new ClientFactory());
    }
    $redisFactory ??= new FailOpenCacheBackendFactory($this->createMock(CacheFactoryInterface::class), $health, $site);
    return new FailOpenRequirements($redisFactory, static fn () => new ClientFactory(), $store ?? $this->createStore(), $options, $site, $this->getStringTranslationStub());
  }

  /**
   * Returns the one requirement, with the severity as an integer.
   *
   * @param \Drupal\perf_redis_failopen\Requirements\FailOpenRequirements $requirements
   *   The service.
   *
   * @return array{severity: int, value: string, description: string}
   *   Severity -1 info, 0 OK, 1 warning, 2 error; texts as strings.
   */
  private function entry(FailOpenRequirements $requirements): array {
    $entry = $requirements->runtime()['perf_redis_failopen'];
    $severity = $entry['severity'];
    return [
      'severity' => $severity instanceof \BackedEnum ? (int) $severity->value : (int) $severity,
      'value' => (string) $entry['value'],
      'description' => (string) $entry['description'],
    ];
  }

  /**
   * Without Redis bins the entry only says so.
   */
  public function testNoRedisBinsIsJustInformation(): void {
    $entry = $this->entry($this->requirements([]));
    $this->assertSame(-1, $entry['severity']);
    $this->assertSame('No cache bin uses Redis', $entry['value']);
  }

  /**
   * A Redis that does not answer is an error that says what the site does.
   */
  public function testRedisThatDoesNotAnswerIsAnError(): void {
    $entry = $this->entry($this->requirements($this->unreachable()));
    $this->assertSame(2, $entry['severity']);
    $this->assertSame('Degraded: Redis does not answer', $entry['value']);
    $this->assertStringContainsString('Connection refused', $entry['description']);
    $this->assertStringContainsString('every read from the Redis bins is a miss', $entry['description']);
    $this->assertStringContainsString('used again as soon as it answers', $entry['description']);
  }

  /**
   * A failure that is not a Redis error is reported, not thrown.
   *
   * With no Redis library installed the redis module throws a plain Exception.
   * The status report is the page that has to explain that, so it must render.
   */
  public function testAnyFailureOfThePingIsReportedNotThrown(): void {
    $this->pretendClientIsConnected(new class() {

      /**
       * Fails like a client that cannot be used at all.
       */
      public function ping(): never {
        throw new \LogicException('synthetic client failure');
      }

    });
    $entry = $this->entry($this->requirements($this->unreachable()));
    $this->assertSame(2, $entry['severity']);
    $this->assertStringContainsString('LogicException: synthetic client failure', $entry['description']);
  }

  /**
   * A cache.backend.redis service that is not decorated is reported.
   */
  public function testAnUndecoratedServiceIsReported(): void {
    $entry = $this->entry($this->requirements($this->unreachable(), $this->createMock(CacheFactoryInterface::class)));
    $this->assertStringContainsString('is not decorated', $entry['description']);
  }

  /**
   * The decorated service is not reported as a problem.
   */
  public function testTheDecoratedServiceIsNotReported(): void {
    $entry = $this->entry($this->requirements($this->unreachable()));
    $this->assertStringNotContainsString('not decorated', $entry['description']);
    $this->assertStringNotContainsString('switched off', $entry['description']);
  }

  /**
   * Switching the wrapper off is reported.
   */
  public function testSwitchingTheWrapperOffIsReported(): void {
    $entry = $this->entry($this->requirements($this->unreachable(['perf_redis_failopen_enabled' => FALSE])));
    $this->assertStringContainsString('switched off', $entry['description']);
  }

  /**
   * Bins that wait to be emptied are reported.
   */
  public function testLostWritesWaitingForFlushAreReported(): void {
    $store = $this->createStore();
    $store->record('render');
    $entry = $this->entry($this->requirements($this->unreachable(), NULL, $store));
    $this->assertStringContainsString('wait to be emptied', $entry['description']);
  }

  /**
   * Timeouts that were asked for but are not in effect are reported.
   *
   * The configurator did not run, as when something connected to Redis before
   * the redis.factory service existed.
   */
  public function testTimeoutsThatAreNotInEffectAreReported(): void {
    $entry = $this->entry($this->requirements($this->unreachable(), NULL, NULL, FALSE));
    $this->assertStringContainsString('timeouts or TLS options are not applied', $entry['description']);
  }

  /**
   * Timeouts that are in effect are not reported.
   */
  public function testTimeoutsThatAreInEffectAreNotReported(): void {
    $entry = $this->entry($this->requirements($this->unreachable()));
    $this->assertStringNotContainsString('not applied', $entry['description']);
  }

}
