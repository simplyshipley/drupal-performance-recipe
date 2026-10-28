<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Site\Settings;
use Drupal\perf_redis_failopen\Diagnostic\FrontDoorDiagnostics;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as HttpRequest;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests Front Door checks with synthetic fixtures and no live Azure traffic.
 *
 * @group perf_redis_failopen
 */
#[Group('perf_redis_failopen')]
final class FrontDoorDiagnosticsTest extends UnitTestCase {

  /**
   * Synthetic credentials and resource identities, never real Azure resources.
   */
  private const SYNTHETIC_CONFIG = [
    'tenant_id' => 'synthetic-tenant',
    'client_id' => 'synthetic-client',
    'client_secret' => 'synthetic-secret-never-log',
    'subscription_id' => 'synthetic-subscription',
    'resource_group_name' => 'synthetic group',
    'profile_name' => 'synthetic-profile',
    'endpoint_name' => 'synthetic-endpoint',
    'azure_environment' => 'azureUSGovernmentCloud',
    'endpoint_type' => 'afdEndpoints',
    'api_version' => '2024-02-01',
  ];

  /**
   * Transient synthetic token; no fixture contains a real access token.
   */
  private const SYNTHETIC_TOKEN = 'synthetic-access-token-never-log';

  /**
   * Original global trusted proxy state, restored after every test.
   *
   * @var array
   */
  private array $trustedProxies;

  /**
   * Original global trusted header mask.
   */
  private int $trustedHeaders;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->trustedProxies = Request::getTrustedProxies();
    $this->trustedHeaders = Request::getTrustedHeaderSet();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    Request::setTrustedProxies($this->trustedProxies, $this->trustedHeaders);
    parent::tearDown();
  }

  /**
   * Effective settings overrides are used instead of raw stored configuration.
   */
  public function testMergedOverridesDriveLiveChecks(): void {
    $factory = $this->factoryWithOverrides();
    $history = [];
    $report = $this->collector([$this->tokenResponse(), new Response(200)], $history, self::SYNTHETIC_CONFIG, TRUE, $factory)->collect();
    $this->assertSame('PASS', $report['frontdoor_configuration']['status']);
    $this->assertSame('PASS', $report['frontdoor_auth']['status']);
    $this->assertSame('PASS', $report['frontdoor_endpoint_read']['status']);
    $this->assertCount(2, $history);
    $this->assertStringContainsString('synthetic%20group', (string) $history[1]['request']->getUri());
    $this->assertSafe($report);
    $this->assertBoundedReadOnly($history);
  }

  /**
   * Missing effective settings skip network requests and identify missing keys.
   */
  public function testMissingCredentialsSkipAllRequests(): void {
    $config = self::SYNTHETIC_CONFIG;
    unset($config['client_secret']);
    $history = [];
    $report = $this->collector([], $history, $config)->collect();
    $this->assertSame('FAIL', $report['frontdoor_configuration']['status']);
    $this->assertStringContainsString('client_secret', $report['frontdoor_configuration']['message']);
    $this->assertSame('SKIP', $report['frontdoor_auth']['status']);
    $this->assertSame([], $history);
  }

  /**
   * A disabled Azure integration does not perform diagnostic authentication.
   */
  public function testDisabledModulesSkipAllRequests(): void {
    $history = [];
    $report = $this->collector([], $history, self::SYNTHETIC_CONFIG, FALSE)->collect();
    $this->assertSame('FAIL', $report['frontdoor_modules']['status']);
    $this->assertSame('SKIP', $report['frontdoor_auth']['status']);
    $this->assertSame([], $history);
  }

  /**
   * Token errors report only safe codes, never complete response descriptions.
   */
  public function testAuthenticationFailureIsRedacted(): void {
    $body = json_encode([
      'error' => 'invalid_client',
      'error_description' => 'AADSTS7000215 ' . self::SYNTHETIC_CONFIG['client_secret'] . self::SYNTHETIC_TOKEN,
    ]);
    $history = [];
    $report = $this->collector([new Response(400, [], $body)], $history)->collect();
    $this->assertSame('FAIL', $report['frontdoor_auth']['status']);
    $this->assertSame('invalid_client', $report['frontdoor_auth']['details']['error_code']);
    $this->assertSame('AADSTS7000215', $report['frontdoor_auth']['details']['aadsts_code']);
    $this->assertSame('SKIP', $report['frontdoor_endpoint_read']['status']);
    $this->assertCount(1, $history);
    $this->assertSafe($report);
  }

  /**
   * HTTP 403 on read cannot establish missing purge permission.
   */
  public function testReadForbiddenIsInconclusiveForPurgePermission(): void {
    $history = [];
    $report = $this->collector([
      $this->tokenResponse(),
      new Response(403, [], '{"error":{"code":"AuthorizationFailed"}}'),
    ], $history)->collect();
    $row = $report['frontdoor_endpoint_read'];
    $this->assertSame('WARN', $row['status']);
    $this->assertStringContainsString('inconclusive for purge permission', $row['message']);
    $this->assertFalse($row['details']['purge_permission_tested']);
    $this->assertSafe($report);
    $this->assertBoundedReadOnly($history);
  }

  /**
   * A missing endpoint is a separate failure after successful authentication.
   */
  public function testEndpointNotFoundDoesNotEraseAuthenticationSuccess(): void {
    $history = [];
    $report = $this->collector([$this->tokenResponse(), new Response(404, [], '{"error":{"code":"ResourceNotFound"}}')], $history)->collect();
    $this->assertSame('PASS', $report['frontdoor_auth']['status']);
    $this->assertSame('FAIL', $report['frontdoor_endpoint_read']['status']);
    $this->assertSame('ResourceNotFound', $report['frontdoor_endpoint_read']['details']['error_code']);
  }

  /**
   * Transport exceptions cannot leak their potentially credential-bearing text.
   */
  public function testDnsFailureIsClassifiedAndRedacted(): void {
    $error = new ConnectException(self::SYNTHETIC_TOKEN . self::SYNTHETIC_CONFIG['client_secret'], new HttpRequest('POST', 'https://login.microsoftonline.us'), NULL, ['errno' => 6]);
    $history = [];
    $report = $this->collector([$error], $history)->collect();
    $this->assertSame('FAIL', $report['frontdoor_auth']['status']);
    $this->assertSame('DNS resolution', $report['frontdoor_auth']['details']['failure_stage']);
    $this->assertSame('SKIP', $report['frontdoor_endpoint_read']['status']);
    $this->assertSafe($report);
  }

  /**
   * Unresolved synthetic Key Vault references are detected before network I/O.
   */
  public function testUnresolvedKeyVaultReferenceIsNotSent(): void {
    $config = self::SYNTHETIC_CONFIG;
    $config['client_secret'] = '@Microsoft.KeyVault(SecretUri=https://synthetic-vault.invalid/secrets/synthetic-secret)';
    $history = [];
    $report = $this->collector([], $history, $config)->collect();
    $this->assertTrue($report['frontdoor_target']['details']['client_secret_keyvault_reference']);
    $this->assertSame('SKIP', $report['frontdoor_auth']['status']);
    $this->assertStringNotContainsString($config['client_secret'], json_encode($report));
    $this->assertSame([], $history);
  }

  /**
   * Unsafe configured hosts are rejected without sending synthetic credentials.
   */
  public function testInvalidHostOverrideIsNotContacted(): void {
    $config = self::SYNTHETIC_CONFIG + ['login_host' => 'http://synthetic-login.invalid'];
    $history = [];
    $report = $this->collector([], $history, $config)->collect();
    $this->assertSame('SKIP', $report['frontdoor_auth']['status']);
    $this->assertSame([], $history);
  }

  /**
   * A token redirect is not followed and never produces an endpoint request.
   */
  public function testAuthenticationRedirectIsNotFollowed(): void {
    $history = [];
    $report = $this->collector([new Response(302, ['Location' => 'https://synthetic-other.invalid'])], $history)->collect();
    $this->assertSame('FAIL', $report['frontdoor_auth']['status']);
    $this->assertCount(1, $history);
    $this->assertFalse($history[0]['options']['allow_redirects']);
  }

  /**
   * Synthetic IPv4 peer trust is separated from the derived client address.
   */
  public function testIpv4ProxyMatchingAndHeaderPrivacy(): void {
    $proxies = ['198.51.100.0/24'];
    $request = Request::create('https://synthetic-site.invalid/admin/reports/status', 'GET', [], [], [], [
      'REMOTE_ADDR' => '198.51.100.20',
      'SERVER_ADDR' => '192.0.2.10',
      'HTTP_X_FORWARDED_FOR' => '203.0.113.12',
      'HTTP_X_FORWARDED_PROTO' => 'https',
      'HTTP_X_AZURE_FDID' => 'synthetic-fdid',
      'HTTP_COOKIE' => 'synthetic-cookie',
      'HTTP_AUTHORIZATION' => 'synthetic-authorization',
    ]);
    Request::setTrustedProxies($proxies, Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
    $history = [];
    $collector = $this->collector([], $history);
    $details = (new \ReflectionMethod($collector, 'proxyDetails'))->invoke($collector, $request, '198.51.100.20', $proxies, $proxies);
    $this->assertTrue($details['active_peer_match']);
    $this->assertTrue($details['configured_peer_match']);
    $this->assertSame('192.0.2.10', $details['server_ip']);
    $this->assertSame(hash('sha256', '203.0.113.12'), $details['client_ip_sha256']);
    $this->assertTrue($details['client_differs_from_peer']);
    $this->assertFalse($details['fdid_is_authenticated']);
    foreach (['synthetic-cookie', 'synthetic-authorization', 'synthetic-fdid', '203.0.113.12'] as $private) {
      $this->assertStringNotContainsString($private, json_encode($details));
    }
  }

  /**
   * Synthetic IPv6 CIDRs are compared using Symfony's implementation.
   */
  public function testIpv6ProxyMatching(): void {
    $history = [];
    $collector = $this->collector([], $history);
    $method = new \ReflectionMethod($collector, 'matches');
    $this->assertTrue($method->invoke($collector, '2001:db8:1::25', ['2001:db8:1::/64']));
    $this->assertFalse($method->invoke($collector, '2001:db8:2::25', ['2001:db8:1::/64']));
  }

  /**
   * A synthetic Drush request context is not an actual Apache peer measurement.
   */
  public function testCliDoesNotClaimWebPeerEvidence(): void {
    $history = [];
    $report = $this->collector([], $history, [])->collect();
    $this->assertSame('SKIP', $report['frontdoor_proxy']['status']);
  }

  /**
   * Creates a collector with synthetic configuration and queued HTTP mocks.
   *
   * @param array $responses
   *   Mock responses/exceptions, never live Azure traffic.
   * @param array $history
   *   Captured request history, which remains private to this test.
   * @param array $config
   *   Synthetic module configuration.
   * @param bool $enabled
   *   Whether optional modules appear enabled.
   * @param \Drupal\Core\Config\ConfigFactoryInterface|null $factory
   *   Optional real immutable configuration with settings overrides.
   *
   * @return \Drupal\perf_redis_failopen\Diagnostic\FrontDoorDiagnostics
   *   The test subject.
   */
  private function collector(array $responses, array &$history, array $config = self::SYNTHETIC_CONFIG, bool $enabled = TRUE, ?ConfigFactoryInterface $factory = NULL): FrontDoorDiagnostics {
    $handler = HandlerStack::create(new MockHandler($responses));
    $handler->push(Middleware::history($history));
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn($enabled);
    $factory ??= $this->getConfigFactoryStub([
      'azure_cdn_purge.settings' => $config,
      'purge.plugins' => [
        'purgers' => [['plugin_id' => 'azurecdn']],
        'processors' => [['status' => TRUE]],
        'queue' => 'database',
      ],
    ]);
    return new FrontDoorDiagnostics($factory, $modules, new RequestStack(), new Settings([]), new Client(['handler' => $handler]));
  }

  /**
   * Creates real immutable config whose raw data is deliberately empty.
   *
   * @return \Drupal\Core\Config\ConfigFactoryInterface
   *   A factory returning effective synthetic settings overrides.
   */
  private function factoryWithOverrides(): ConfigFactoryInterface {
    $config = new ImmutableConfig('azure_cdn_purge.settings', $this->createMock(StorageInterface::class), new EventDispatcher(), $this->createMock(TypedConfigManagerInterface::class));
    $config->initWithData([])->setSettingsOverride(self::SYNTHETIC_CONFIG);
    $this->assertSame([], $config->getRawData());
    $fallback = $this->getConfigFactoryStub(['purge.plugins' => []]);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturnCallback(static fn (string $name): ImmutableConfig => $name === 'azure_cdn_purge.settings' ? $config : $fallback->get($name));
    return $factory;
  }

  /**
   * Builds a successful token response containing a synthetic token.
   *
   * @return \GuzzleHttp\Psr7\Response
   *   The mocked successful authentication response.
   */
  private function tokenResponse(): Response {
    return new Response(200, [], json_encode(['access_token' => self::SYNTHETIC_TOKEN]));
  }

  /**
   * Asserts reports omit synthetic credentials, tokens and identities.
   *
   * @param array $report
   *   The returned diagnostic report.
   */
  private function assertSafe(array $report): void {
    $encoded = json_encode($report);
    foreach ([
      self::SYNTHETIC_TOKEN,
      self::SYNTHETIC_CONFIG['client_secret'],
      self::SYNTHETIC_CONFIG['subscription_id'],
      self::SYNTHETIC_CONFIG['profile_name'],
    ] as $secret) {
      $this->assertStringNotContainsString($secret, $encoded);
    }
  }

  /**
   * Asserts only bounded token POST and resource GET requests were sent.
   *
   * @param array $history
   *   Captured synthetic mock request history.
   */
  private function assertBoundedReadOnly(array $history): void {
    $this->assertCount(2, $history);
    $this->assertSame('POST', $history[0]['request']->getMethod());
    $this->assertSame('GET', $history[1]['request']->getMethod());
    foreach ($history as $request) {
      $this->assertStringNotContainsString('/purge', (string) $request['request']->getUri());
      $this->assertSame(2, $request['options']['connect_timeout']);
      $this->assertSame(5, $request['options']['timeout']);
      $this->assertFalse($request['options']['allow_redirects']);
      $this->assertTrue($request['options']['verify']);
      $this->assertFalse($request['options']['debug']);
    }
  }

}
