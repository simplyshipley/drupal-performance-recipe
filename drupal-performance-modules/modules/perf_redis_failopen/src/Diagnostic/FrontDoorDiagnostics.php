<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Diagnostic;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Checks Front Door configuration, proxy trust and read-only Azure access.
 *
 * The caller gates collection behind FRONTDOOR_DEBUG or an explicit CLI option.
 * Tokens, response bodies and credentials never enter the returned report.
 */
final class FrontDoorDiagnostics {

  /**
   * Required effective configuration keys and their environment variable names.
   */
  private const REQUIRED = [
    'tenant_id' => 'AZURE_CDN_TENANT_ID',
    'client_id' => 'AZURE_CDN_CLIENT_ID',
    'client_secret' => 'AZURE_CDN_CLIENT_SECRET',
    'subscription_id' => 'AZURE_CDN_SUBSCRIPTION_ID',
    'resource_group_name' => 'AZURE_CDN_RESOURCE_GROUP',
    'profile_name' => 'AZURE_CDN_PROFILE',
    'endpoint_name' => 'AZURE_CDN_ENDPOINT',
  ];

  /**
   * Cloud endpoints used by the recipe's patched AzurePurger implementation.
   */
  private const CLOUDS = [
    'azureCloud' => ['https://login.microsoftonline.com', 'https://management.azure.com'],
    'azureUSGovernmentCloud' => ['https://login.microsoftonline.us', 'https://management.usgovcloudapi.net'],
  ];

  /**
   * Constructs the Front Door diagnostic collector.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory, including runtime overrides.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The enabled module registry.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The actual web request, absent in CLI.
   * @param \Drupal\Core\Site\Settings $settings
   *   Final site settings after bootstrap.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   Drupal's HTTP client. Tests replace it with a mock handler.
   */
  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly RequestStack $requestStack,
    protected readonly Settings $settings,
    #[Autowire(service: 'http_client')]
    protected readonly ClientInterface $httpClient,
  ) {}

  /**
   * Collects configuration, request trust and bounded read-only Azure checks.
   *
   * @return array<string, array{status: string, message: string, details: array}>
   *   Diagnostic rows. No purge is performed or inferred from these checks.
   */
  public function collect(): array {
    $config = $this->configFactory->get('azure_cdn_purge.settings')->get();
    $rows = $this->configuration($config) + $this->proxy();
    $rows += $this->liveChecks($config);
    $rows['frontdoor_edge_cache'] = $this->row('SKIP', 'Origin-side checks cannot establish edge cache HIT/MISS or successful purging. Inspect edge response headers and existing azurecdn purge errors.');
    return $rows;
  }

  /**
   * Reports enabled modules and effective required configuration presence.
   *
   * @param array $config
   *   Merged runtime Azure purge configuration.
   *
   * @return array
   *   Configuration rows, excluding raw identity values and credentials.
   */
  protected function configuration(array $config): array {
    $presence = [];
    foreach (self::REQUIRED as $key => $environment) {
      $env = getenv($environment);
      $presence[$key] = [
        'effective_present' => $this->present($config[$key] ?? NULL),
        'env_present' => $this->present($env),
        'matches_env' => $this->present($env) ? ($config[$key] ?? NULL) === $env : NULL,
      ];
    }
    $missing = $this->missing($config);
    return [
      'frontdoor_modules' => $this->moduleReadiness(),
      'frontdoor_configuration' => $this->row($missing ? 'FAIL' : 'PASS', $missing ? 'Required effective purge configuration is missing: ' . implode(', ', $missing) : 'All seven required purge identity/target settings are present; presence does not establish validity.', $presence),
      'frontdoor_target' => $this->target($config),
      'frontdoor_purge_plugins' => $this->purgeReadiness(),
    ];
  }

  /**
   * Reports optional module enablement without constructing optional services.
   *
   * @return array
   *   Enabled module facts and readiness status.
   */
  protected function moduleReadiness(): array {
    $enabled = [];
    foreach (['azure_cdn_purge', 'purge', 'perf_purge_paths'] as $module) {
      $enabled[$module] = $this->moduleHandler->moduleExists($module);
    }
    return $this->row($enabled['azure_cdn_purge'] && $enabled['purge'] ? 'PASS' : 'FAIL', 'Azure purge and Purge must be enabled. Path queuing is reported separately.', $enabled);
  }

  /**
   * Reports configured Purge plugins without processing the queue.
   *
   * @return array
   *   Configured plugin readiness, without claiming runtime queue health.
   */
  protected function purgeReadiness(): array {
    $plugins = $this->configFactory->get('purge.plugins');
    $purgers = array_column($plugins->get('purgers') ?? [], 'plugin_id');
    $processors = array_filter($plugins->get('processors') ?? [], static fn (array $plugin): bool => !empty($plugin['status']));
    return $this->row(in_array('azurecdn', $purgers, TRUE) && $processors ? 'PASS' : 'WARN', 'Configured plugin readiness; no queued item is claimed or purged.', [
      'azurecdn_configured' => in_array('azurecdn', $purgers, TRUE),
      'enabled_processor_count' => count($processors),
      'queue_backend_configured' => $this->present($plugins->get('queue')),
    ]);
  }

  /**
   * Fingerprints targets and reports nonsecret protocol configuration.
   *
   * @param array $config
   *   Effective module configuration.
   *
   * @return array
   *   A target row without raw resource identities.
   */
  protected function target(array $config): array {
    $details = [];
    foreach (['subscription_id', 'resource_group_name', 'profile_name', 'endpoint_name'] as $key) {
      $details[$key . '_sha256'] = $this->present($config[$key] ?? NULL) ? hash('sha256', (string) $config[$key]) : NULL;
    }
    $details += $this->protocolDetails($config);
    $valid = $details['cloud'] !== 'unknown_or_unset' && $details['endpoint_type'] === 'afdEndpoints' && $details['api_version'] !== NULL;
    $warning = $details['client_secret_keyvault_reference'] || !($details['scope_matches_management'] ?? TRUE) || $details['purger_devel_enabled'];
    return $this->row(!$valid ? 'FAIL' : ($warning ? 'WARN' : 'PASS'), 'Compare target fingerprints between QA and production. Check cloud/scope selection, unresolved Key Vault references and the purger devel flag.', $details);
  }

  /**
   * Describes cloud/scope selection without exposing raw configured host URLs.
   *
   * @param array $config
   *   Effective module configuration.
   *
   * @return array
   *   Safe protocol metadata, including possible cross-cloud scope mismatch.
   */
  protected function protocolDetails(array $config): array {
    $cloud = $config['azure_environment'] ?? '';
    $known = is_string($cloud) && isset(self::CLOUDS[$cloud]);
    $details = [
      'cloud' => $known ? $cloud : 'unknown_or_unset',
      'endpoint_type' => ($config['endpoint_type'] ?? '') === 'afdEndpoints' ? 'afdEndpoints' : 'other_or_unset',
      'api_version' => $this->apiVersion($config),
      'client_secret_keyvault_reference' => str_starts_with((string) ($config['client_secret'] ?? ''), '@Microsoft.KeyVault('),
      'scope_explicit' => $this->present($config['scope'] ?? NULL),
      'purger_devel_enabled' => (bool) ($config['devel'] ?? FALSE),
    ];
    if ($known) {
      [$login, $management] = $this->endpoints($config);
      $details += [
        'login_host_sha256' => hash('sha256', $login),
        'management_host_sha256' => hash('sha256', $management),
        'scope_matches_management' => !$details['scope_explicit'] || $config['scope'] === $management . '/.default',
      ];
    }
    return $details;
  }

  /**
   * Reports immediate peer trust and derived client information separately.
   *
   * @return array
   *   A proxy evidence row, or a skip row when there is no real web request.
   */
  protected function proxy(): array {
    $request = $this->requestStack->getCurrentRequest();
    if (PHP_SAPI === 'cli' || !$request) {
      return ['frontdoor_proxy' => $this->row('SKIP', 'CLI has no Apache/App Service web peer; run the web status report for proxy/IP evidence.')];
    }
    $peer = (string) $request->server->get('REMOTE_ADDR', '');
    $configured = $this->settings->get('reverse_proxy_addresses', []);
    $actual = Request::getTrustedProxies();
    $details = $this->proxyDetails($request, $peer, $configured, $actual);
    $trusted = $this->matches($peer, $actual);
    return ['frontdoor_proxy' => $this->row($trusted ? 'PASS' : 'WARN', $trusted ? 'The immediate web peer matches Symfony\'s active trusted proxies; forwarded header trust is reported independently.' : 'The immediate web peer does not match active trusted proxies. This may be a direct-origin request or a proxy configuration mismatch.', $details)];
  }

  /**
   * Builds proxy details without logging complete forwarded client chains.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The actual request.
   * @param string $peer
   *   The immediate REMOTE_ADDR.
   * @param array $configured
   *   Configured trusted proxy addresses/CIDRs.
   * @param array $actual
   *   Symfony's currently active trusted addresses/CIDRs.
   *
   * @return array
   *   Safe IP/trust facts. Client address and FDID are fingerprinted.
   */
  protected function proxyDetails(Request $request, string $peer, array $configured, array $actual): array {
    $forwarded = (string) $request->headers->get('X-Forwarded-For', '');
    $fdid = (string) $request->headers->get('X-Azure-FDID', '');
    $client = $this->clientAddress($request);
    return [
      'peer_ip' => filter_var($peer, FILTER_VALIDATE_IP) ? $peer : 'invalid_or_unset',
      'server_ip' => filter_var($request->server->get('SERVER_ADDR'), FILTER_VALIDATE_IP) ?: NULL,
      'reverse_proxy_setting' => (bool) $this->settings->get('reverse_proxy', FALSE),
      'configured_proxy_count' => count($configured),
      'configured_peer_match' => $this->matches($peer, $configured),
      'active_proxy_count' => count($actual),
      'active_peer_match' => $this->matches($peer, $actual),
      'configured_header_mask' => $this->settings->get('reverse_proxy_trusted_headers'),
      'active_header_mask' => Request::getTrustedHeaderSet(),
      'client_ip_sha256' => $client ? hash('sha256', $client) : NULL,
      'client_differs_from_peer' => $client !== NULL && $client !== $peer,
      'forwarded_for_count' => $forwarded === '' ? 0 : count(explode(',', $forwarded)),
      'forwarded_proto' => $this->forwardedScheme($request),
      'fdid_present' => $fdid !== '',
      'fdid_sha256' => $fdid !== '' ? hash('sha256', $fdid) : NULL,
      'fdid_is_authenticated' => FALSE,
    ];
  }

  /**
   * Matches IPv4/IPv6 peers against configured IP addresses or CIDR ranges.
   *
   * @param string $peer
   *   Immediate web peer IP.
   * @param array $proxies
   *   Configured IP addresses/CIDRs.
   *
   * @return bool
   *   Whether the peer matches a valid entry.
   */
  protected function matches(string $peer, array $proxies): bool {
    if (!filter_var($peer, FILTER_VALIDATE_IP)) {
      return FALSE;
    }
    try {
      return IpUtils::checkIp($peer, $proxies);
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Gets the derived client address without failing the report on bad headers.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request being diagnosed.
   *
   * @return string|null
   *   The derived address, or NULL for conflicting/invalid headers.
   */
  protected function clientAddress(Request $request): ?string {
    try {
      return $request->getClientIp();
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Returns an allowlisted forwarded scheme without logging arbitrary headers.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request being diagnosed.
   *
   * @return string|null
   *   HTTP/HTTPS, invalid, or NULL when absent.
   */
  protected function forwardedScheme(Request $request): ?string {
    $value = strtolower(trim((string) $request->headers->get('X-Forwarded-Proto', '')));
    return in_array($value, ['http', 'https'], TRUE) ? $value : ($value === '' ? NULL : 'invalid');
  }

  /**
   * Performs at most two bounded requests without changing Azure resources.
   *
   * @param array $config
   *   Effective module configuration, including credentials kept in memory.
   *
   * @return array
   *   Authentication and endpoint-read results; no tokens or response bodies.
   */
  protected function liveChecks(array $config): array {
    $reason = $this->skipReason($config);
    if ($reason !== NULL) {
      return ['frontdoor_auth' => $this->row('SKIP', $reason), 'frontdoor_endpoint_read' => $this->row('SKIP', $reason)];
    }
    [$auth, $token] = $this->authenticate($config);
    return [
      'frontdoor_auth' => $auth,
      'frontdoor_endpoint_read' => $token !== NULL ? $this->readEndpoint($config, $token) : $this->row('SKIP', 'No authenticated token was obtained; endpoint read was not attempted.'),
    ];
  }

  /**
   * Returns a reason not to send credential-bearing diagnostic requests.
   *
   * @param array $config
   *   Effective module configuration.
   *
   * @return string|null
   *   A reason when prerequisites do not permit a safe test.
   */
  protected function skipReason(array $config): ?string {
    if (!$this->moduleHandler->moduleExists('azure_cdn_purge') || !$this->moduleHandler->moduleExists('purge')) {
      return 'Required Azure/Purge module is disabled.';
    }
    if ($this->missing($config) || str_starts_with((string) ($config['client_secret'] ?? ''), '@Microsoft.KeyVault(')) {
      return 'Required effective settings are missing or the client secret is an unresolved Key Vault reference.';
    }
    if (!isset(self::CLOUDS[$config['azure_environment'] ?? '']) || ($config['endpoint_type'] ?? '') !== 'afdEndpoints' || $this->apiVersion($config) === NULL) {
      return 'Recognized Azure cloud, afdEndpoints type and a valid API version are required for this Front Door test.';
    }
    [$login, $management] = $this->endpoints($config);
    return $this->safeBaseUrl($login) && $this->safeBaseUrl($management) ? NULL : 'Configured Azure hosts must be HTTPS base URLs without credentials, paths, query strings or fragments.';
  }

  /**
   * Acquires a transient token using the purger's same runtime configuration.
   *
   * @param array $config
   *   Effective module configuration.
   *
   * @return array
   *   A safe result row and transient token; token is never put in the report.
   */
  protected function authenticate(array $config): array {
    [$login, $management] = $this->endpoints($config);
    $url = $login . '/' . rawurlencode($config['tenant_id']) . '/oauth2/v2.0/token';
    try {
      [$response, $transport] = $this->request('POST', $url, [
        'form_params' => [
          'client_id' => $config['client_id'],
          'client_secret' => $config['client_secret'],
          'scope' => $config['scope'] ?? '' ?: $management . '/.default',
          'grant_type' => 'client_credentials',
        ],
      ]);
      $data = $this->responseData($response);
      $token = $response->getStatusCode() === 200 && is_string($data['access_token'] ?? NULL) && $data['access_token'] !== '' ? $data['access_token'] : NULL;
      return [
        $this->row($token !== NULL ? 'PASS' : 'FAIL', $token !== NULL ? 'Client-credential authentication succeeded; token is retained only for the read-only endpoint check.' : 'Azure authentication failed or returned no usable access token.', $transport + [
          'http_status' => $response->getStatusCode(),
          'error_code' => $this->errorCode($data),
          'aadsts_code' => $this->aadstsCode($data),
        ]),
        $token,
      ];
    }
    catch (\Throwable $error) {
      return [$this->transportFailure($error, 'Azure authentication request failed.'), NULL];
    }
  }

  /**
   * Reads endpoint metadata without calling purge or mutating Azure resources.
   *
   * @param array $config
   *   Effective module configuration.
   * @param string $token
   *   Transient token, never included in returned data.
   *
   * @return array
   *   An endpoint-read result; it cannot establish purge permission.
   */
  protected function readEndpoint(array $config, string $token): array {
    [, $management] = $this->endpoints($config);
    $segments = array_map('rawurlencode', [
      $config['subscription_id'],
      $config['resource_group_name'],
      $config['profile_name'],
      $config['endpoint_name'],
    ]);
    $url = sprintf('%s/subscriptions/%s/resourceGroups/%s/providers/Microsoft.Cdn/profiles/%s/afdEndpoints/%s', $management, ...$segments);
    try {
      [$response, $transport] = $this->request('GET', $url, [
        'headers' => ['Authorization' => 'Bearer ' . $token],
        'query' => ['api-version' => $config['api_version']],
      ]);
      $status = $response->getStatusCode();
      $message = $status === 200 ? 'Configured endpoint metadata is readable. Purge permission and edge caching were not tested.' : ($status === 403 ? 'Endpoint read returned HTTP 403. A purge-only role may lack read permission; this is inconclusive for purge permission.' : 'Configured endpoint metadata could not be read; inspect the HTTP status and safe Azure error code.');
      return $this->row($status === 200 ? 'PASS' : ($status === 403 ? 'WARN' : 'FAIL'), $message, $transport + [
        'http_status' => $status,
        'error_code' => $this->errorCode($this->responseData($response)),
        'purge_permission_tested' => FALSE,
      ]);
    }
    catch (\Throwable $error) {
      return $this->transportFailure($error, 'Azure endpoint metadata request failed.');
    }
  }

  /**
   * Sends one bounded request and records validated transport IP addresses.
   *
   * @param string $method
   *   HTTP method, POST for token or GET for resource metadata.
   * @param string $url
   *   A URL derived only from configured module targets.
   * @param array $options
   *   Request-specific headers, form values or query arguments.
   *
   * @return array
   *   The response and safe transport facts.
   */
  protected function request(string $method, string $url, array $options): array {
    $transport = [];
    $options += [
      'connect_timeout' => 2,
      'timeout' => 5,
      'allow_redirects' => FALSE,
      'http_errors' => FALSE,
      'verify' => TRUE,
      'debug' => FALSE,
      'cookies' => FALSE,
    ];
    $options['on_stats'] = static function (TransferStats $stats) use (&$transport): void {
      foreach (['primary_ip', 'local_ip'] as $key) {
        $value = $stats->getHandlerStats()[$key] ?? NULL;
        $transport[$key] = is_string($value) && filter_var($value, FILTER_VALIDATE_IP) ? $value : NULL;
      }
    };
    $response = $this->httpClient->request($method, $url, $options);
    return [$response, $transport];
  }

  /**
   * Reports transport errors without exception strings that may contain tokens.
   *
   * @param \Throwable $error
   *   A failed request or response parse.
   * @param string $message
   *   Static stage description.
   *
   * @return array
   *   A redacted failure row.
   */
  protected function transportFailure(\Throwable $error, string $message): array {
    $errno = $error instanceof ConnectException ? (int) ($error->getHandlerContext()['errno'] ?? 0) : 0;
    $cause = [
      6 => 'DNS resolution',
      7 => 'TCP connection',
      28 => 'timeout',
      35 => 'TLS handshake',
      60 => 'TLS certificate verification',
    ][$errno] ?? 'transport or response processing';
    return $this->row('FAIL', $message, [
      'exception_class' => $error::class,
      'curl_errno' => $errno ?: NULL,
      'failure_stage' => $cause,
    ]);
  }

  /**
   * Reads a bounded JSON response without returning the body in diagnostics.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   The HTTP response.
   *
   * @return array
   *   Decoded response data or an empty array for malformed JSON.
   */
  protected function responseData(ResponseInterface $response): array {
    $data = json_decode($response->getBody()->read(65536), TRUE);
    return is_array($data) ? $data : [];
  }

  /**
   * Extracts only known error identifiers, never Azure response descriptions.
   *
   * @param array $data
   *   Decoded response data.
   *
   * @return string|null
   *   A safe known error code or NULL.
   */
  protected function errorCode(array $data): ?string {
    $code = is_array($data['error'] ?? NULL) ? ($data['error']['code'] ?? '') : ($data['error'] ?? '');
    if (in_array($code, [
      'invalid_client',
      'invalid_scope',
      'invalid_request',
      'unauthorized_client',
      'AuthorizationFailed',
      'ResourceNotFound',
      'ParentResourceNotFound',
      'ResourceGroupNotFound',
      'SubscriptionNotFound',
      'InvalidApiVersionParameter',
      'NoRegisteredProviderFound',
    ], TRUE)) {
      return $code;
    }
    return NULL;
  }

  /**
   * Extracts only the numeric AADSTS identifier from an auth error description.
   *
   * @param array $data
   *   Decoded response data, never returned or logged in full.
   *
   * @return string|null
   *   A safe AADSTS identifier, without surrounding text or credentials.
   */
  protected function aadstsCode(array $data): ?string {
    $description = $data['error_description'] ?? NULL;
    return is_string($description) && preg_match('/\bAADSTS\d{5,10}\b/', $description, $matches) ? $matches[0] : NULL;
  }

  /**
   * Resolves the same cloud and explicit host overrides as AzurePurger.
   *
   * @param array $config
   *   Effective validated module configuration.
   *
   * @return array{string, string}
   *   Login and management base URLs.
   */
  protected function endpoints(array $config): array {
    [$login, $management] = self::CLOUDS[$config['azure_environment']];
    return [
      rtrim($config['login_host'] ?? '' ?: $login, '/'),
      rtrim($config['management_host'] ?? '' ?: $management, '/'),
    ];
  }

  /**
   * Validates configured Azure base URLs before sending credentials or tokens.
   *
   * @param string $url
   *   Configured base URL, never taken from incoming request headers.
   *
   * @return bool
   *   Whether this is an HTTPS authority-only URL with no user information.
   */
  protected function safeBaseUrl(string $url): bool {
    $parts = parse_url($url);
    return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment']) && empty($parts['path']) && (!isset($parts['port']) || $parts['port'] === 443);
  }

  /**
   * Returns missing mandatory effective configuration keys.
   *
   * @param array $config
   *   Effective module configuration.
   *
   * @return string[]
   *   Missing keys.
   */
  protected function missing(array $config): array {
    return array_values(array_filter(array_keys(self::REQUIRED), fn (string $key): bool => !$this->present($config[$key] ?? NULL)));
  }

  /**
   * Checks whether a configuration/environment value is a nonempty string.
   *
   * @param mixed $value
   *   A candidate value.
   *
   * @return bool
   *   Whether the value is a nonempty string.
   */
  protected function present(mixed $value): bool {
    return is_string($value) && $value !== '';
  }

  /**
   * Validates and returns the configured Azure API version.
   *
   * @param array $config
   *   Effective module configuration.
   *
   * @return string|null
   *   A date-style API version, with optional preview suffix.
   */
  protected function apiVersion(array $config): ?string {
    $version = $config['api_version'] ?? NULL;
    return is_string($version) && preg_match('/^\d{4}-\d{2}-\d{2}(?:-preview)?$/D', $version) ? $version : NULL;
  }

  /**
   * Builds a consistent diagnostic row.
   *
   * @param string $status
   *   PASS, FAIL, WARN or SKIP.
   * @param string $message
   *   A safe explanatory message.
   * @param array $details
   *   Safe structured facts.
   *
   * @return array{status: string, message: string, details: array}
   *   The diagnostic row.
   */
  protected function row(string $status, string $message, array $details = []): array {
    return ['status' => $status, 'message' => $message, 'details' => $details];
  }

}
