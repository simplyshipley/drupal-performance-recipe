<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Unit;

use Drupal\Core\Site\Settings;
use Drupal\perf_redis_failopen\Cache\RedisHealth;
use Drupal\perf_redis_failopen\Client\TimeoutClientInstaller;
use Drupal\perf_redis_failopen\Hook\PerfRedisFailopenHooks;
use Drupal\redis\ClientFactory;
use Drupal\Tests\perf_redis_failopen\Traits\ClientFactoryStateTrait;
use Drupal\Tests\perf_redis_failopen\Traits\RecordingDoublesTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * Unit tests for the status report entry of the Redis fail-open module.
 *
 * @group perf_redis_failopen
 * @requires extension redis
 */
#[Group('perf_redis_failopen')]
#[RequiresPhpExtension('redis')]
final class PerfRedisFailopenHooksTest extends UnitTestCase {

  use ClientFactoryStateTrait;
  use RecordingDoublesTrait;

  /**
   * Settings with one bin on Redis and the stock PhpRedis client.
   */
  private const REDIS_SITE = [
    'cache' => ['bins' => ['render' => 'cache.backend.redis']],
    'redis.connection' => ['interface' => 'PhpRedis'],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->resetClientFactory();
    if (function_exists('apcu_clear_cache')) {
      apcu_clear_cache();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->resetClientFactory();
    parent::tearDown();
  }

  /**
   * Returns the entry as [severity as int, value, description].
   *
   * @param array<string, mixed> $settings
   *   The site settings.
   * @param \Drupal\perf_redis_failopen\Cache\RedisHealth|null $health
   *   The outage state; a healthy one when NULL.
   *
   * @return array{0: int, 1: string, 2: string}
   *   The severity, the value and the description.
   */
  private function entry(array $settings, ?RedisHealth $health = NULL): array {
    $health ??= $this->createHealth($this->createStore(), $this->createLogger());
    // Created last, so it is also the Settings instance the redis module reads.
    $site = new Settings($settings);
    $hooks = new PerfRedisFailopenHooks($health, new TimeoutClientInstaller($site), $site, $this->getStringTranslationStub());
    $entry = $hooks->runtimeRequirements()['perf_redis_failopen'];
    $severity = $entry['severity'] instanceof \BackedEnum ? (int) $entry['severity']->value : (int) $entry['severity'];
    return [$severity, (string) $entry['value'], (string) $entry['description']];
  }

  /**
   * Without Redis bins the entry is information only.
   */
  public function testNoRedisBinsIsJustInformation(): void {
    $this->assertSame([-1, 'No cache bin uses Redis', ''], $this->entry([]));
  }

  /**
   * While Redis is skipped after an error, the entry is an error.
   */
  public function testRedisThatIsSkippedAfterAnErrorIsAnError(): void {
    $health = $this->createHealth($this->createStore(), $this->createLogger());
    $health->markDown(new \RedisException('Connection refused'), 'render', 'get');
    [$severity, $value, $description] = $this->entry(self::REDIS_SITE, $health);
    $this->assertSame(2, $severity);
    $this->assertSame('Degraded: Redis does not answer', $value);
    $this->assertStringContainsString('used again as soon as it answers', $description);
  }

  /**
   * A healthy Redis with the bounded client in use is OK and lists the bins.
   */
  public function testRedisInUseWithTheBoundedClientIsOk(): void {
    (new TimeoutClientInstaller(new Settings(self::REDIS_SITE)))->install(new ClientFactory());
    $this->pretendClientIsConnected();
    $this->assertSame([0, 'Redis bins: render', ''], $this->entry(self::REDIS_SITE));
  }

  /**
   * Switching the wrapper off is a warning.
   */
  public function testSwitchingTheWrapperOffIsReported(): void {
    [$severity, , $description] = $this->entry(self::REDIS_SITE + ['perf_redis_failopen_enabled' => FALSE]);
    $this->assertSame(1, $severity);
    $this->assertStringContainsString('switched off', $description);
  }

  /**
   * A connected stock client, without the timeouts, is a warning.
   */
  public function testTimeoutsThatAreNotInEffectAreReported(): void {
    $this->pretendClientIsConnected();
    [$severity, , $description] = $this->entry(self::REDIS_SITE);
    $this->assertSame(1, $severity);
    $this->assertStringContainsString('timeouts or TLS options are not applied', $description);
  }

}
