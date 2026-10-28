<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Unit;

use Composer\Autoload\ClassLoader;
use Drupal\Tests\UnitTestCase;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * Exercises the shipped settings include with synthetic environment values.
 *
 * @group perf_redis_failopen
 * @requires extension redis
 */
#[Group('perf_redis_failopen')]
#[RequiresPhpExtension('redis')]
final class SettingsEnvironmentTest extends UnitTestCase {

  /**
   * Environment fields changed only for the duration of each synthetic include.
   */
  private const VARIABLES = [
    'REDIS_HOST', 'REDIS_PORT', 'REDIS_SCHEME', 'REDIS_ENABLED',
    'REDIS_PERSISTENT', 'REDIS_DEBUG', 'FRONTDOOR_DEBUG', 'REDIS_PASSWORD',
    'AZURE_CDN_CLIENT_SECRET', 'AZURE_CLOUD', 'DRUPAL_REVERSE_PROXY',
  ];

  /**
   * Loads the actual include without opening Redis or database connections.
   */
  private function load(array $environment): array {
    $previous = [];
    $values = array_replace(array_fill_keys(self::VARIABLES, NULL), [
      'REDIS_HOST' => 'synthetic-redis.invalid',
    ], $environment);
    foreach ($values as $name => $value) {
      $previous[$name] = getenv($name);
      putenv($value === NULL ? $name : $name . '=' . $value);
    }
    try {
      $root = vfsStream::setup('synthetic-site', NULL, ['modules' => ['contrib' => ['redis' => []]]]);
      $app_root = $root->url();
      $class_loader = $this->createMock(ClassLoader::class);
      $class_loader->expects($this->once())->method('addPsr4')->with(
        'Drupal\\redis\\', $app_root . '/modules/contrib/redis/src',
      );
      $settings = [];
      $config = [];
      require dirname(__DIR__, 5) . '/settings/settings.performance.php';
      return [$settings, $config];
    }
    finally {
      foreach ($previous as $name => $value) {
        putenv($value === FALSE ? $name : $name . '=' . $value);
      }
    }
  }

  /**
   * A quoted host/port works with explicit external ports in TCP and TLS.
   */
  public function testQuotedConnectionValuesRetainExplicitPort(): void {
    foreach (['tcp' => 'synthetic-redis.invalid', 'tls' => 'tls://synthetic-redis.invalid'] as $scheme => $host) {
      [$settings] = $this->load([
        'REDIS_HOST' => ' "synthetic-redis.invalid" ',
        'REDIS_PORT' => ' "6378" ',
        'REDIS_SCHEME' => ' "' . $scheme . '" ',
      ]);
      $this->assertSame($host, $settings['redis.connection']['host']);
      $this->assertSame(6378, $settings['redis.connection']['port']);
    }
  }

  /**
   * Default ports apply only when the typed environment field is empty.
   */
  public function testDefaultPortsRemainUnchanged(): void {
    [$tcp] = $this->load([]);
    [$tls] = $this->load(['REDIS_SCHEME' => 'tls']);
    [$empty] = $this->load(['REDIS_PORT' => ' "" ']);
    $this->assertSame(6379, $tcp['redis.connection']['port']);
    $this->assertSame(6380, $tls['redis.connection']['port']);
    $this->assertSame(6379, $empty['redis.connection']['port']);
  }

  /**
   * Supported boolean spellings work with or without surrounding quotes.
   */
  public function testBooleanSpellingsAreParsed(): void {
    foreach (['1', 'true', 'on', 'yes', 'TRUE'] as $value) {
      foreach ([$value, ' "' . $value . '" '] as $input) {
        $this->assertSwitches($input, TRUE);
      }
    }
    foreach (['0', 'false', 'off', 'no', 'FALSE'] as $value) {
      foreach ([$value, " '" . $value . "' "] as $input) {
        $this->assertSwitches($input, FALSE);
      }
    }
  }

  /**
   * Verifies all existing switches against the real settings include.
   */
  private function assertSwitches(string $input, bool $expected): void {
    [$settings] = $this->load(array_fill_keys([
      'REDIS_ENABLED', 'REDIS_PERSISTENT', 'REDIS_DEBUG',
      'FRONTDOOR_DEBUG', 'DRUPAL_REVERSE_PROXY',
    ], $input));
    $this->assertSame($expected, $settings['perf_redis_enabled']);
    $this->assertSame($expected, $settings['redis.connection']['persistent'] ?? FALSE);
    $this->assertSame($expected, $settings['perf_redis_debug']);
    $this->assertSame($expected, $settings['perf_frontdoor_debug']);
    $this->assertSame($expected, $settings['reverse_proxy'] ?? FALSE);
  }

  /**
   * Empty/invalid switches retain the explicit enable-versus-debug defaults.
   */
  public function testMissingEmptyAndInvalidBooleanDefaults(): void {
    foreach ([NULL, '', ' "" ', 'synthetic-invalid-boolean'] as $value) {
      [$settings] = $this->load(array_fill_keys([
        'REDIS_ENABLED', 'REDIS_PERSISTENT', 'REDIS_DEBUG',
        'FRONTDOOR_DEBUG', 'DRUPAL_REVERSE_PROXY',
      ], $value));
      $this->assertTrue($settings['perf_redis_enabled']);
      $this->assertFalse($settings['redis.connection']['persistent'] ?? FALSE);
      $this->assertFalse($settings['perf_redis_debug']);
      $this->assertFalse($settings['perf_frontdoor_debug']);
      $this->assertFalse($settings['reverse_proxy'] ?? FALSE);
    }
  }

  /**
   * Only exterior quote/space characters are trimmed; embedded quotes remain.
   */
  public function testExteriorQuotesAreTrimmedAndEmbeddedQuotesRemain(): void {
    [$mismatched] = $this->load(['REDIS_HOST' => " ' \"synthetic-redis.invalid'\" "]);
    [$embedded] = $this->load(['REDIS_HOST' => ' synthetic-"redis".invalid ']);
    $this->assertSame('synthetic-redis.invalid', $mismatched['redis.connection']['host']);
    $this->assertSame('synthetic-"redis".invalid', $embedded['redis.connection']['host']);
  }

  /**
   * Credential values retain every original byte, including exterior quotes.
   */
  public function testCredentialBytesAreNeverNormalized(): void {
    $redis = ' "synthetic redis password" ';
    $azure = " 'synthetic Azure client secret' ";
    [$settings, $config] = $this->load([
      'REDIS_PASSWORD' => $redis,
      'AZURE_CDN_CLIENT_SECRET' => $azure,
    ]);
    $this->assertSame($redis, $settings['redis.connection']['password']);
    $this->assertSame($azure, $config['azure_cdn_purge.settings']['client_secret']);
  }

}
