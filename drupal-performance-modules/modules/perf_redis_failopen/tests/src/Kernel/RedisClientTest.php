<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\perf_redis_failopen\Cache\RedisHealth;
use Drupal\perf_redis_failopen\Client\TimeoutPhpRedis;
use Drupal\perf_redis_failopen\Hook\PerfRedisFailopenHooks;
use Drupal\redis\Client\PhpRedis;
use Drupal\redis\ClientFactory;
use PHPUnit\Framework\Attributes\Group;

/**
 * Proves client configuration and status through the installed module services.
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
   * Prevents the contrib singleton from leaking into another Kernel test.
   */
  private function resetClientFactory(): void {
    foreach (['_clientInterface', '_client'] as $name) {
      (new \ReflectionProperty(ClientFactory::class, $name))->setValue(NULL, NULL);
    }
  }

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
  private function entry(array $settings, ?RedisHealth $health = NULL): array {
    foreach ($settings as $name => $value) {
      $this->setSetting($name, $value);
    }
    $this->container->set(PerfRedisFailopenHooks::class, NULL);
    if ($health) {
      $this->container->set(RedisHealth::class, $health);
    }
    $handler = $this->container->get('module_handler');
    $handler->loadInclude('perf_redis_failopen', 'install');
    $entry = $handler->invoke('perf_redis_failopen', 'requirements', ['runtime'])['perf_redis_failopen'];
    $severity = $entry['severity'] instanceof \BackedEnum ? (int) $entry['severity']->value : (int) $entry['severity'];
    return [$severity, (string) $entry['value'], (string) $entry['description']];
  }

  /**
   * The bounded client replaces the stock one, with the configured options.
   */
  public function testInstallsTheBoundedClientWithTheConfiguredOptions(): void {
    $this->install([
      'perf_redis_connect_timeout' => 1.5,
      'perf_redis_read_timeout' => 2.5,
      'perf_redis_tls_cafile' => '/etc/ssl/synthetic-ca.pem',
    ]);
    $client = ClientFactory::getClientInterface();
    $this->assertInstanceOf(TimeoutPhpRedis::class, $client);
    $this->assertSame('PhpRedis', $client->getName());
    $this->assertSame(1.5, $this->property($client, 'connectTimeout'));
    $this->assertSame(2.5, $this->property($client, 'readTimeout'));
    $this->assertSame(['cafile' => '/etc/ssl/synthetic-ca.pem'], $this->property($client, 'tlsOptions'));
  }

  /**
   * Without settings the client gets 1 s to connect, 2 s to answer, no TLS.
   */
  public function testDefaultsAreOneAndTwoSecondsWithoutTls(): void {
    $this->install([]);
    $client = ClientFactory::getClientInterface();
    $this->assertInstanceOf(TimeoutPhpRedis::class, $client);
    $this->assertSame(1.0, $this->property($client, 'connectTimeout'));
    $this->assertSame(2.0, $this->property($client, 'readTimeout'));
    $this->assertSame([], $this->property($client, 'tlsOptions'));
  }

  /**
   * An empty TLS setting is no option at all.
   */
  public function testEmptyTlsSettingsAreDropped(): void {
    $this->install(['perf_redis_tls_cafile' => '', 'perf_redis_tls_peer_name' => 'valkey.example.invalid']);
    $this->assertSame(['peer_name' => 'valkey.example.invalid'], $this->property(ClientFactory::getClientInterface(), 'tlsOptions'));
  }

  /**
   * Timeouts are seconds with fractions, whatever type the setting has.
   */
  public function testTimeoutsAreFractionalSeconds(): void {
    $this->install(['perf_redis_connect_timeout' => '0.25', 'perf_redis_read_timeout' => 3]);
    $client = ClientFactory::getClientInterface();
    $this->assertSame(0.25, $this->property($client, 'connectTimeout'));
    $this->assertSame(3.0, $this->property($client, 'readTimeout'));
  }

  /**
   * A connect timeout of 0 with no TLS options keeps the redis module's client.
   */
  public function testNothingAskedForKeepsTheStockClient(): void {
    $this->install(['perf_redis_connect_timeout' => 0]);
    $client = ClientFactory::getClientInterface();
    $this->assertNotInstanceOf(TimeoutPhpRedis::class, $client);
    $this->assertInstanceOf(PhpRedis::class, $client);
  }

  /**
   * A TLS option alone is enough to ask for the module's client.
   */
  public function testTlsOptionAloneInstallsTheClient(): void {
    $this->install(['perf_redis_connect_timeout' => 0, 'perf_redis_tls_peer_name' => 'valkey.example.invalid']);
    $this->assertInstanceOf(TimeoutPhpRedis::class, ClientFactory::getClientInterface());
  }

  /**
   * Predis is never replaced: only PhpRedis has the bounded client.
   */
  public function testPredisIsLeftAlone(): void {
    $this->install(['redis.connection' => ['interface' => 'Predis']]);
    $client = ClientFactory::getClientInterface();
    $this->assertNotInstanceOf(TimeoutPhpRedis::class, $client);
    $this->assertSame('Predis', $client->getName());
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
   * Running the configurator again keeps the first bounded client.
   *
   * The container is rebuilt several times inside one process while a module
   * is installed; the second run must not swap or fail.
   */
  public function testRunningTwiceKeepsTheFirstClient(): void {
    $this->install([]);
    $first = ClientFactory::getClientInterface();
    $this->install([]);
    $this->assertSame($first, ClientFactory::getClientInterface());
  }

  /**
   * Reads a private property of the client.
   */
  private function property(object $object, string $name): mixed {
    return (new \ReflectionProperty($object, $name))->getValue($object);
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
    $health = $this->container->get(RedisHealth::class);
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
    $this->install(self::REDIS_SITE);
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

  /**
   * A connected stock client, without the timeouts, is a warning.
   */
  public function testTimeoutsThatAreNotInEffectAreReported(): void {
    $this->pretendClientIsConnected();
    [$severity, , $description] = $this->entry(self::REDIS_SITE);
    $this->assertSame(1, $severity);
    $this->assertStringContainsString('timeouts or TLS options are not applied', $description);
  }

  /**
   * A Redis default backend counts even when no bin overrides are configured.
   */
  public function testDefaultRedisBackendIsListed(): void {
    $this->assertSame([0, 'Redis bins: default', ''], $this->entry(['cache' => ['default' => 'cache.backend.redis']]));
  }

}
