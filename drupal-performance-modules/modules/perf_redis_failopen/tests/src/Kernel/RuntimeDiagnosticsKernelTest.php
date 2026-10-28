<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Kernel;

use Drupal\Core\KeyValueStore\DatabaseStorage;
use Drupal\KernelTests\KernelTestBase;
use Drupal\perf_redis_failopen\Diagnostic\RedisProbe;
use Drupal\perf_redis_failopen\Diagnostic\RuntimeDiagnostics;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the diagnostic's real dblog query against isolated synthetic log rows.
 *
 * @group perf_redis_failopen
 */
#[Group('perf_redis_failopen')]
final class RuntimeDiagnosticsKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'dblog', 'redis', 'perf_redis_failopen'];

  /**
   * Only recent own-channel outages count; malformed/context values stay safe.
   */
  public function testRecentOutagesFilterAndRedactRealWatchdogRows(): void {
    $this->installSchema('dblog', ['watchdog']);
    $database = $this->container->get('database');
    if (!$database->schema()->tableExists('key_value')) {
      $database->schema()->createTable('key_value', DatabaseStorage::schemaDefinition());
    }
    $this->setSetting('redis.connection', [
      'interface' => 'PhpRedis',
      'host' => 'synthetic-redis.invalid',
      'password' => 'synthetic-redis-password',
    ]);
    $probe = $this->createMock(RedisProbe::class);
    $probe->method('run')->willReturn([]);
    $this->container->set(RedisProbe::class, $probe);
    $this->container->set('redis.factory', $this->syntheticFactory());
    $this->syntheticLogs();

    $collector = $this->container->get(RuntimeDiagnostics::class);
    $report = $collector->collect(TRUE, FALSE);
    $row = $report['checks']['redis']['recent_outages'];
    $this->assertSame('WARN', $row['status']);
    $this->assertSame(3, $row['details']['count']);
    $this->assertCount(3, $row['details']['latest']);
    $this->assertSame([], $row['details']['latest'][0]['variables']);
    $encoded = json_encode($row);
    foreach ([
      'synthetic-redis-password', 'synthetic-hidden-context', 'PERF_DIAG',
      'synthetic-foreign', 'synthetic-expired', 'synthetic-malformed',
    ] as $excluded) {
      $this->assertStringNotContainsString($excluded, $encoded);
    }
    $this->assertStringContainsString('[redacted]', $encoded);
    $this->assertSame('data', $row['details']['latest'][2]['variables']['@bin']);

    $collector->log($report);
    $this->container->set(RuntimeDiagnostics::class, NULL);
    $again = $this->container->get(RuntimeDiagnostics::class)->collect(TRUE, FALSE);
    $this->assertSame(3, $again['checks']['redis']['recent_outages']['details']['count']);
  }

  /**
   * Returns a synthetic configured client without opening any Redis connection.
   */
  private function syntheticFactory(): object {
    return new class() {

      /**
       * Returns a synthetic client that answers only the diagnostic PING.
       */
      public function getClient(): object {
        return new class() {

          /**
           * Provides a synthetic PING result without network access.
           */
          public function ping(): bool {
            return TRUE;
          }

        };
      }

    };
  }

  /**
   * Inserts synthetic recent, malformed, expired, diagnostic and foreign rows.
   */
  private function syntheticLogs(): void {
    $now = time();
    $fixtures = [
      [
        'perf_redis_failopen', $now - 30,
        'Redis unavailable @message @bin @op @retry',
        serialize([
          '@message' => 'Synthetic failure synthetic-redis-password',
          '@bin' => 'data',
          '@op' => 'get',
          '@retry' => 30,
          '@context' => 'synthetic-hidden-context',
        ]),
      ],
      [
        'perf_redis_failopen', $now - 20,
        'Redis reachable again; emptied bin @bin',
        serialize(['@bin' => 'entity']),
      ],
      [
        'perf_redis_failopen', $now - 10,
        'Redis unavailable @message',
        'synthetic-malformed-serialized-value',
      ],
      ['perf_redis_failopen', $now - 1000, 'synthetic-expired', serialize([])],
      [
        'perf_redis_failopen', $now - 5,
        'PERF_DIAG @report',
        serialize(['@report' => 'synthetic-diagnostic']),
      ],
      ['synthetic-foreign', $now - 3, 'synthetic-foreign', serialize([])],
    ];
    $insert = $this->container->get('database')->insert('watchdog')->fields([
      'type', 'timestamp', 'message', 'variables', 'location',
    ]);
    foreach ($fixtures as $fixture) {
      $insert->values([...$fixture, 'synthetic-location']);
    }
    $insert->execute();
  }

}
