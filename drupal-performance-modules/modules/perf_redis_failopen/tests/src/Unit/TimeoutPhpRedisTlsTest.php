<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Unit;

use Drupal\perf_redis_failopen\Client\TimeoutPhpRedis;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * Tests the TLS options of the bounded client against a real TLS listener.
 *
 * A child PHP process listens with a SYNTHETIC self-signed certificate (made
 * here, valid for one day, never leaving the temp directory) and completes the
 * TLS handshake, nothing more: connect() needs no Redis protocol. The tests
 * show that the CA file and the peer name reach the connection, and that
 * verification can never be switched off by accident.
 *
 * @group perf_redis_failopen
 * @requires extension redis
 * @requires extension openssl
 */
#[Group('perf_redis_failopen')]
#[RequiresPhpExtension('redis')]
#[RequiresPhpExtension('openssl')]
final class TimeoutPhpRedisTlsTest extends UnitTestCase {

  private const NAME = 'synthetic-valkey.test';

  /**
   * The temp directory with the certificates.
   */
  private static string $dir = '';

  /**
   * The child process, or NULL when it could not be started.
   *
   * @var resource|null
   */
  private static $process = NULL;

  /**
   * The port the child listens on, 0 when it is not running.
   */
  private static int $port = 0;

  /**
   * {@inheritdoc}
   */
  public static function setUpBeforeClass(): void {
    parent::setUpBeforeClass();
    if (!function_exists('proc_open') || !extension_loaded('openssl') || !extension_loaded('redis')) {
      return;
    }
    self::$dir = sys_get_temp_dir() . '/perf-redis-failopen-tls-' . getmypid();
    mkdir(self::$dir, 0700);
    [$cert, $key] = self::certificate(self::NAME);
    file_put_contents(self::$dir . '/server.pem', $cert . $key);
    file_put_contents(self::$dir . '/ca.crt', $cert);
    file_put_contents(self::$dir . '/other-ca.crt', self::certificate('synthetic-other.test')[0]);
    self::startListener();
  }

  /**
   * {@inheritdoc}
   */
  public static function tearDownAfterClass(): void {
    if (is_resource(self::$process)) {
      proc_terminate(self::$process);
      proc_close(self::$process);
    }
    foreach (glob(self::$dir . '/*') ?: [] as $file) {
      unlink($file);
    }
    if (self::$dir !== '' && is_dir(self::$dir)) {
      rmdir(self::$dir);
    }
    parent::tearDownAfterClass();
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    if (self::$port === 0) {
      $this->markTestSkipped('The TLS listener could not be started (proc_open, openssl or redis missing).');
    }
  }

  /**
   * Makes a self-signed certificate.
   *
   * @param string $commonName
   *   The name the certificate is valid for.
   *
   * @return array{string, string}
   *   The certificate and the private key, PEM encoded.
   */
  private static function certificate(string $commonName): array {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $request = openssl_csr_new(['commonName' => $commonName], $key, ['digest_alg' => 'sha256']);
    $certificate = openssl_csr_sign($request, NULL, $key, 1, ['digest_alg' => 'sha256']);
    openssl_x509_export($certificate, $certificatePem);
    openssl_pkey_export($key, $keyPem);
    return [$certificatePem, $keyPem];
  }

  /**
   * Starts the child process that completes TLS handshakes.
   *
   * It exits by itself after a minute, so a crashed test run leaves nothing
   * behind.
   */
  private static function startListener(): void {
    $code = <<<'PHP'
$context = stream_context_create(['ssl' => ['local_cert' => $argv[1], 'verify_peer' => FALSE]]);
$server = stream_socket_server('tls://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
fwrite(STDOUT, substr(strrchr(stream_socket_get_name($server, FALSE), ':'), 1) . "\n");
fflush(STDOUT);
$end = time() + 60;
while (time() < $end) {
  $connection = @stream_socket_accept($server, 1);
  if ($connection) {
    fclose($connection);
  }
}
PHP;
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-r', $code, self::$dir . '/server.pem'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
      return;
    }
    self::$process = $process;
    $read = [$pipes[1]];
    $write = $except = [];
    if (stream_select($read, $write, $except, 10) === 1) {
      self::$port = (int) trim((string) fgets($pipes[1]));
    }
  }

  /**
   * Connects to the listener with the given TLS options.
   *
   * @param array<string, string> $options
   *   The cafile and peer_name options.
   */
  private function connect(array $options): \Redis {
    $client = (new TimeoutPhpRedis(2.0, 2.0, $options))->getClient('tls://127.0.0.1', self::$port);
    $this->assertInstanceOf(\Redis::class, $client);
    return $client;
  }

  /**
   * The CA file and the peer name are what make a self-signed server trusted.
   *
   * The server is addressed by IP and its certificate is not in any system
   * store, so this can only succeed if both options reach the connection.
   */
  public function testTheCaFileAndPeerNameTrustTheServer(): void {
    $this->connect(['cafile' => self::$dir . '/ca.crt', 'peer_name' => self::NAME]);
  }

  /**
   * A peer name that is not the certificate's is refused, and says so.
   */
  public function testWrongPeerNameIsRefused(): void {
    $this->expectException(\RedisException::class);
    $this->expectExceptionMessage('wrong.test');
    $this->connect(['cafile' => self::$dir . '/ca.crt', 'peer_name' => 'wrong.test']);
  }

  /**
   * A certificate the CA file does not vouch for is refused, and says so.
   */
  public function testCertificateFromAnotherCaIsRefused(): void {
    $this->expectException(\RedisException::class);
    $this->expectExceptionMessage('certificate verify failed');
    $this->connect(['cafile' => self::$dir . '/other-ca.crt', 'peer_name' => self::NAME]);
  }

  /**
   * Without options the system trust store is used, and it does not know it.
   *
   * This is also the check that verification is on by default: a client that
   * accepted this server would be accepting any server.
   */
  public function testWithoutOptionsSelfSignedServerIsRefused(): void {
    $this->expectException(\RedisException::class);
    $this->expectExceptionMessage('certificate verify failed');
    $this->connect([]);
  }

  /**
   * The failure names the cause, not just that the server went away.
   *
   * Without the folded warnings the message would be "Redis server went
   * away", which does not help whoever has to fix a mismatch during the
   * switch to TLS.
   */
  public function testTheFailureMessageCarriesTheCauseOfTheHandshake(): void {
    try {
      $this->connect(['cafile' => self::$dir . '/other-ca.crt', 'peer_name' => self::NAME]);
      $this->fail('The connection should have been refused.');
    }
    catch (\RedisException $e) {
      $this->assertStringContainsString('Failed to enable crypto', $e->getMessage());
      $this->assertStringContainsString('certificate verify failed', $e->getMessage());
    }
  }

}
