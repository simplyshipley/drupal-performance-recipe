<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Unit;

use Drupal\Core\Site\Settings;
use Drupal\perf_redis_failopen\Client\TimeoutClientInstaller;
use Drupal\perf_redis_failopen\Client\TimeoutPhpRedis;
use Drupal\redis\Client\PhpRedis;
use Drupal\redis\ClientFactory;
use Drupal\Tests\perf_redis_failopen\Traits\ClientFactoryStateTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for the configurator that installs the bounded Redis client.
 *
 * @group perf_redis_failopen
 */
#[Group('perf_redis_failopen')]
final class TimeoutClientInstallerTest extends UnitTestCase {

  use ClientFactoryStateTrait;

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
   * Runs the configurator with the given settings.
   *
   * @param array<string, mixed> $settings
   *   Site settings.
   */
  private function install(array $settings): void {
    $settings += ['redis.connection' => ['interface' => 'PhpRedis']];
    (new TimeoutClientInstaller(new Settings($settings)))->install(new ClientFactory());
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

}
