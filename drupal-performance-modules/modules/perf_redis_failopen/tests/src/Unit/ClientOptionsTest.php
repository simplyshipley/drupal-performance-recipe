<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Unit;

use Drupal\Core\Site\Settings;
use Drupal\perf_redis_failopen\Client\ClientOptions;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for the connection options of the bounded Redis client.
 *
 * @group perf_redis_failopen
 */
#[Group('perf_redis_failopen')]
final class ClientOptionsTest extends UnitTestCase {

  /**
   * Without settings the bounded client is on, with no TLS options.
   */
  public function testDefaults(): void {
    $options = new ClientOptions(new Settings([]));
    $this->assertSame(1.0, $options->connectTimeout());
    $this->assertSame(2.0, $options->readTimeout());
    $this->assertSame([], $options->tlsOptions());
    $this->assertTrue($options->wanted());
  }

  /**
   * A connect timeout of 0 and no TLS options keeps the redis module's client.
   */
  public function testNothingAskedForMeansTheStockClient(): void {
    $options = new ClientOptions(new Settings(['perf_redis_connect_timeout' => 0]));
    $this->assertFalse($options->wanted());
  }

  /**
   * A TLS option alone asks for the module's client, even without a timeout.
   */
  public function testTlsOptionAloneAsksForTheModulesClient(): void {
    $options = new ClientOptions(new Settings([
      'perf_redis_connect_timeout' => 0,
      'perf_redis_tls_cafile' => '/etc/ssl/synthetic-ca.pem',
    ]));
    $this->assertTrue($options->wanted());
    $this->assertSame(['cafile' => '/etc/ssl/synthetic-ca.pem'], $options->tlsOptions());
  }

  /**
   * Empty TLS settings are left out, so the defaults of PHP apply.
   */
  public function testEmptyTlsSettingsAreDropped(): void {
    $options = new ClientOptions(new Settings([
      'perf_redis_tls_cafile' => '',
      'perf_redis_tls_peer_name' => 'valkey.example.invalid',
    ]));
    $this->assertSame(['peer_name' => 'valkey.example.invalid'], $options->tlsOptions());
  }

  /**
   * The timeouts are read as seconds with a fraction.
   */
  public function testTimeoutsAreFractionalSeconds(): void {
    $options = new ClientOptions(new Settings(['perf_redis_connect_timeout' => '0.25', 'perf_redis_read_timeout' => 3]));
    $this->assertSame(0.25, $options->connectTimeout());
    $this->assertSame(3.0, $options->readTimeout());
  }

}
