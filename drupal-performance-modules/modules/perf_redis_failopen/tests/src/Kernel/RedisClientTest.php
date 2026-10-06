<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\perf_redis_failopen\Client\TimeoutPhpRedis;
use Drupal\perf_redis_failopen\Hook\PerfRedisFailopenHooks;
use Drupal\redis\ClientFactory;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the bounded client's configurator and the status report entry.
 *
 * @group perf_redis_failopen
 */
#[Group('perf_redis_failopen')]
final class RedisClientTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'redis', 'perf_redis_failopen'];

  /**
   * A synthetic site configured to use PhpRedis for one cache bin.
   */
  private const REDIS_SITE = [
    'cache' => ['bins' => ['render' => 'cache.backend.redis']],
    'redis.connection' => ['interface' => 'PhpRedis'],
  ];

  /**
   * Simulates an earlier connection without relying on a server for status.
   */
  private function pretendClientIsConnected(): void {
    (new \ReflectionProperty(ClientFactory::class, '_client'))->setValue(NULL, new \stdClass());
  }

  /**
   * Creates redis.factory to run the configurator with the site settings.
   */
  private function install(array $settings): void {
    foreach ($settings + ['redis.connection' => ['interface' => 'PhpRedis']] as $name => $value) {
      $this->setSetting($name, $value);
    }
    $this->container->set('redis.factory', NULL);
    $this->container->get('redis.factory');
  }

  /**
   * Invokes the runtime requirement through Drupal's module handler.
   *
   * @return array{int, string, string}
   *   Severity, value and description.
   */
  private function entry(array $settings): array {
    foreach ($settings as $name => $value) {
      $this->setSetting($name, $value);
    }
    $this->container->set(PerfRedisFailopenHooks::class, NULL);
    $handler = $this->container->get('module_handler');
    $handler->loadInclude('perf_redis_failopen', 'install');
    $entry = $handler->invoke('perf_redis_failopen', 'requirements', ['runtime'])['perf_redis_failopen'];
    $severity = $entry['severity'] instanceof \BackedEnum ? (int) $entry['severity']->value : (int) $entry['severity'];
    return [$severity, (string) $entry['value'], (string) $entry['description']];
  }

  /**
   * PhpRedis is replaced by the bounded client, which keeps its name.
   */
  public function testPhpRedisGetsTheBoundedClient(): void {
    $this->install([]);
    $client = ClientFactory::getClientInterface();
    $this->assertSame(TimeoutPhpRedis::class, $client::class);
    $this->assertSame('PhpRedis', $client->getName());
  }

  /**
   * A client that already connected cannot be swapped, and nothing is done.
   */
  public function testConnectedClientIsLeftAlone(): void {
    $this->pretendClientIsConnected();
    $this->install([]);
    $this->assertNotInstanceOf(TimeoutPhpRedis::class, ClientFactory::getClientInterface());
  }

  /**
   * Without Redis bins the entry is information only.
   */
  public function testNoRedisBinsIsJustInformation(): void {
    $this->assertSame([-1, 'No cache bin uses Redis', ''], $this->entry([]));
  }

  /**
   * Redis in use is OK and lists the bins.
   */
  public function testRedisInUseIsOk(): void {
    $this->assertSame([0, 'Redis bins: render', ''], $this->entry(self::REDIS_SITE));
  }

  /**
   * Switching Redis off in settings.php is a warning that names the bins.
   */
  public function testSwitchingRedisOffIsReported(): void {
    $GLOBALS['config']['perf_redis_failopen.settings']['enabled'] = FALSE;
    $this->container->get('config.factory')->reset('perf_redis_failopen.settings');
    $this->container->set('perf_redis_failopen.cache_factory', NULL);
    [$severity, $value, $description] = $this->entry(self::REDIS_SITE);
    $this->assertSame(1, $severity);
    $this->assertSame('Off (perf_redis_failopen.settings:enabled is FALSE)', $value);
    $this->assertSame('The bins render use the database.', $description);
  }

}
