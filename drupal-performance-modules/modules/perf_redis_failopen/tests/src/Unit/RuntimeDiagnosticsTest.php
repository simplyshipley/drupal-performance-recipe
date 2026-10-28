<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\DatabaseBackend;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Drupal\perf_redis_failopen\Cache\RedisHealth;
use Drupal\perf_redis_failopen\Diagnostic\FrontDoorDiagnostics;
use Drupal\perf_redis_failopen\Diagnostic\RedisProbe;
use Drupal\perf_redis_failopen\Diagnostic\RuntimeDiagnostics;
use Drupal\perf_redis_failopen\Drush\Commands\PerformanceCommands;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests runtime gates, privacy and fail-open diagnostic side-effect isolation.
 */
#[Group('perf_redis_failopen')]
final class RuntimeDiagnosticsTest extends UnitTestCase {

  /**
   * Creates a collector from isolated synthetic services, never a real network.
   */
  private function collector(array $settings = [], array $pending = [], bool $authorized = TRUE): array {
    $settings = new Settings($settings);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(TRUE);
    $config = $this->getMockBuilder(ImmutableConfig::class)->disableOriginalConstructor()->getMock();
    $config->method('get')->willReturnMap([['client_secret', 'synthetic-azure-secret']]);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $notes = $this->createMock(KeyValueStoreInterface::class);
    $notes->method('getAll')->willReturn($pending);
    $keys = $this->createMock(KeyValueFactoryInterface::class);
    $keys->method('get')->willReturn($notes);
    $logger = $this->createMock(LoggerInterface::class);
    $health = new RedisHealth(fn () => $logger, $keys);
    $probe = $this->createMock(RedisProbe::class);
    $probe->method('run')->willReturn([]);
    $frontdoor = (new \ReflectionClass(FrontDoorDiagnostics::class))->newInstanceWithoutConstructor();
    $route = $this->createMock(RouteMatchInterface::class);
    $route->method('getRouteName')->willReturn('redis.report');
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('hasPermission')->with('access redis report')->willReturn($authorized);
    $container = new Container();
    $collector = new RuntimeDiagnostics($settings, $modules, $factory, $health, $probe, $frontdoor, $route, $account, $container, fn () => $logger);
    return [$collector, $probe, $container, $health, $notes, $logger];
  }

  /**
   * Defines only synthetic connection identifiers and cache configuration.
   */
  private function settings(): array {
    return [
      'perf_redis_debug' => TRUE,
      'redis.connection' => [
        'host' => 'synthetic-redis.invalid',
        'password' => 'synthetic-password',
      ],
      'cache' => ['bins' => ['data' => 'cache.backend.redis']],
    ];
  }

  /**
   * Default-off status collection performs no probe and adds no debug entry.
   */
  public function testDefaultOffDoesNotProbe(): void {
    [$collector, $probe] = $this->collector();
    $probe->expects($this->never())->method('run');
    $this->assertSame([], $collector->collect()['checks']);
    $this->assertSame([], $collector->requirements());
  }

  /**
   * A denied redis.report request never collects, logs, or exposes diagnostics.
   */
  public function testDeniedRedisReportDoesNotProbe(): void {
    [$collector, $probe, , , , $logger] = $this->collector($this->settings(), [], FALSE);
    $probe->expects($this->never())->method('run');
    $logger->expects($this->never())->method('notice');
    $page = [];
    $collector->redisReport($page);
    $this->assertSame([], $page);
  }

  /**
   * Missing Drupal factory is reported independently of transport success.
   */
  public function testMissingDrupalFactoryIsNotHidden(): void {
    [$collector] = $this->collector($this->settings());
    $report = $collector->collect();
    $this->assertSame('FAIL', $report['checks']['redis']['drupal_client']['status']);
    $this->assertStringContainsString('redis.factory', $report['checks']['redis']['drupal_client']['message']);
  }

  /**
   * A pending real-bin flush must not be triggered by any diagnostic cleanup.
   */
  public function testPendingFlushDoesNotTouchBackend(): void {
    [$collector, , $container, , $notes] = $this->collector($this->settings(), ['data' => '']);
    $backend = $this->createMock(CacheBackendInterface::class);
    $backend->expects($this->never())->method('set');
    $backend->expects($this->never())->method('get');
    $backend->expects($this->never())->method('delete');
    $notes->expects($this->never())->method('set');
    $container->set('cache.data', $backend);
    $this->assertSame('SKIP', $collector->collect()['checks']['redis']['drupal_backend']['status']);
  }

  /**
   * Swallowed writes fail the check without destructive wrapper cleanup.
   */
  public function testSwallowedWriteDoesNotCreateLostInvalidation(): void {
    [$collector, , $container, $health, $notes] = $this->collector($this->settings());
    $backend = $this->createMock(CacheBackendInterface::class);
    $backend->method('set')->willReturnCallback(static function () use ($health): void {
      $health->markDown(new \RedisException('Synthetic disconnected Redis'), 'data', 'set');
    });
    $backend->method('get')->willReturn(FALSE);
    $backend->expects($this->never())->method('delete');
    $notes->expects($this->never())->method('set');
    $container->set('cache.data', $backend);
    $this->assertSame('FAIL', $collector->collect()['checks']['redis']['drupal_backend']['status']);
  }

  /**
   * Both display and logging receive a sanitized, request-memoized report.
   */
  public function testMemoizedProbeAndSingleLog(): void {
    [$collector, $probe, , , , $logger] = $this->collector($this->settings());
    $probe->expects($this->once())->method('run');
    $logger->expects($this->once())->method('notice');
    $first = $collector->collect();
    $this->assertSame($first, $collector->collect());
    $collector->log($first);
    $collector->log($first);
  }

  /**
   * Backend exceptions cannot expose credentials or break the status report.
   */
  public function testBackendExceptionIsRedacted(): void {
    [$collector, , $container] = $this->collector($this->settings());
    $backend = $this->createMock(CacheBackendInterface::class);
    $backend->method('set')->willThrowException(new \RuntimeException('Synthetic error synthetic-password synthetic-azure-secret Bearer synthetic-token @Microsoft.KeyVault(SecretUri=synthetic-reference)'));
    $container->set('cache.data', $backend);
    $report = $collector->collect();
    $encoded = json_encode($report);
    foreach (['synthetic-password', 'synthetic-azure-secret', 'synthetic-token', 'synthetic-reference'] as $secret) {
      $this->assertStringNotContainsString($secret, $encoded);
    }
    $this->assertSame('FAIL', $report['checks']['redis']['drupal_backend']['status']);
    $this->assertSame(1, $collector->requirements()['perf_runtime_diagnostics']['severity']);
  }

  /**
   * Malformed external error bytes cannot crash diagnostic status rendering.
   */
  public function testMalformedErrorBytesDoNotCrashRequirements(): void {
    [$collector, , $container] = $this->collector($this->settings());
    $backend = $this->createMock(CacheBackendInterface::class);
    $backend->method('set')->willThrowException(new \RuntimeException("Synthetic malformed error \xB1"));
    $container->set('cache.data', $backend);
    $entry = $collector->requirements()['perf_runtime_diagnostics'];
    $this->assertSame(1, $entry['severity']);
    $this->assertArrayHasKey('checks', $entry['description']);
  }

  /**
   * The discovered Drush factory uses the same collector and explicit switches.
   */
  public function testDrushFactoryAndExplicitRedisSelection(): void {
    [$collector, $probe, $container] = $this->collector();
    $probe->expects($this->once())->method('run');
    $container->set(RuntimeDiagnostics::class, $collector);
    $command = PerformanceCommands::create($container);
    $output = new BufferedOutput();
    $command->setOutput($output);
    $command->diagnose(['redis' => TRUE, 'frontdoor' => FALSE]);
    $report = json_decode($output->fetch(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertArrayHasKey('redis', $report['checks']);
    $this->assertArrayNotHasKey('frontdoor', $report['checks']);
    $this->assertSame(PHP_SAPI, $report['runtime']['sapi']);
  }

  /**
   * Lazy-client timing, APCu state and unavailable dblog are explicit evidence.
   */
  public function testLazyClientAndSapiCooldownMetadata(): void {
    [$collector] = $this->collector($this->settings());
    $rows = $collector->collect()['checks']['redis'];
    $this->assertArrayHasKey('before_explicit_probe', $rows['client_initialization']['details']);
    $this->assertArrayHasKey('after_explicit_probe', $rows['client_initialization']['details']);
    $this->assertSame(ini_get('apc.enable_cli'), $rows['apcu']['details']['apc_enable_cli']);
    $this->assertSame('SKIP', $rows['recent_outages']['status']);
  }

  /**
   * Hostname bytes and invisible whitespace differences are measured safely.
   */
  public function testHostnameWhitespaceComparison(): void {
    $previous = getenv('REDIS_HOST');
    putenv('REDIS_HOST= synthetic-redis.invalid ');
    try {
      [$collector] = $this->collector($this->settings());
      $details = $collector->collect()['checks']['redis']['settings']['details'];
      $this->assertTrue($details['env_host_outer_whitespace']);
      $this->assertFalse($details['host_matches_environment']);
      $this->assertGreaterThan($details['effective_host_length'], $details['env_host_length']);
      $this->assertStringNotContainsString('synthetic-redis.invalid', json_encode($details));
    }
    finally {
      putenv($previous === FALSE ? 'REDIS_HOST' : 'REDIS_HOST=' . $previous);
    }
  }

  /**
   * A working stale database backend cannot be reported as working Redis.
   */
  public function testDatabaseBackendCannotConfirmRedis(): void {
    [$collector, , $container] = $this->collector($this->settings());
    $backend = $this->getMockBuilder(DatabaseBackend::class)->disableOriginalConstructor()->getMock();
    $value = NULL;
    $backend->method('set')->willReturnCallback(static function ($key, $data) use (&$value): void {
      $value = $data;
    });
    $backend->method('get')->willReturnCallback(static function () use (&$value): object {
      return (object) ['data' => $value];
    });
    $container->set('cache.data', $backend);
    $row = $collector->collect()['checks']['redis']['drupal_backend'];
    $this->assertSame('FAIL', $row['status']);
    $this->assertTrue($row['details']['read_after_write_succeeded']);
    $this->assertTrue($row['details']['known_database_or_null_backend']);
    $this->assertFalse($row['details']['redis_backend_confirmed']);
  }

}
