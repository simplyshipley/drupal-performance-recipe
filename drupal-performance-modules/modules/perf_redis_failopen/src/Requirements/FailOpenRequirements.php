<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Requirements;

use Drupal\Core\Cache\CacheFactoryInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\perf_redis_failopen\Cache\FailOpenCacheBackendFactory;
use Drupal\perf_redis_failopen\Cache\PendingFlushStoreInterface;
use Drupal\perf_redis_failopen\Client\ClientOptions;
use Drupal\perf_redis_failopen\Client\TimeoutPhpRedis;
use Drupal\redis\ClientFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * Builds the status report entry for the Redis fail-open wrapper.
 *
 * One entry, worst severity wins:
 * - error: Redis does not answer (checked live with a PING, bounded by the
 *   connect timeout). The site still works, but every Redis bin is a miss;
 * - warning: the wrapper or the bounded client is not in effect, or bins are
 *   waiting to be emptied;
 * - ok: Redis answers and the wrapper is in effect.
 *
 * The same array serves hook_requirements() (Drupal 10.6 to 11.2) and the OOP
 * runtime_requirements hook (11.3 and later); only the severity type differs,
 * so it is chosen in severity().
 */
final class FailOpenRequirements {

  use StringTranslationTrait;

  private const INFO = -1;
  private const OK = 0;
  private const WARNING = 1;
  private const ERROR = 2;

  /**
   * Constructs a FailOpenRequirements.
   *
   * @param \Drupal\Core\Cache\CacheFactoryInterface $redisFactory
   *   The cache.backend.redis service (the decorator, when it is active).
   * @param \Closure $clientFactory
   *   Returns the redis.factory service. Resolving it runs the configurator
   *   that installs the bounded client, which must happen before the status
   *   report touches the Redis client.
   * @param \Drupal\perf_redis_failopen\Cache\PendingFlushStoreInterface $store
   *   The lost-write records.
   * @param \Drupal\perf_redis_failopen\Client\ClientOptions $options
   *   The timeouts and TLS options that were asked for.
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The string translation service.
   */
  public function __construct(
    #[Autowire(service: 'cache.backend.redis')]
    private readonly CacheFactoryInterface $redisFactory,
    #[AutowireServiceClosure('redis.factory')]
    private readonly \Closure $clientFactory,
    private readonly PendingFlushStoreInterface $store,
    private readonly ClientOptions $options,
    private readonly Settings $settings,
    TranslationInterface $translation,
  ) {
    $this->stringTranslation = $translation;
  }

  /**
   * Returns the runtime requirements.
   *
   * @return array<string, array<string, mixed>>
   *   The requirements, keyed by id.
   */
  public function runtime(): array {
    $bins = $this->redisBins();
    if (!$bins) {
      return $this->requirement(self::INFO, $this->t('No cache bin uses Redis'), []);
    }
    $error = $this->connectionError();
    $problems = $this->configurationProblems();
    if ($error !== NULL) {
      array_unshift($problems, $this->t('Redis does not answer (@error). The site keeps working: every read from the Redis bins is a miss and writes are dropped, so pages are slower and the database does more work. Redis is used again as soon as it answers.', ['@error' => $error]));
    }
    $value = $error !== NULL
      ? $this->t('Degraded: Redis does not answer')
      : $this->t('Redis bins: @bins', ['@bins' => implode(', ', $bins)]);
    return $this->requirement($error !== NULL ? self::ERROR : ($problems ? self::WARNING : self::OK), $value, $problems);
  }

  /**
   * Returns the cache bins that are set to cache.backend.redis.
   *
   * @return string[]
   *   The bin names.
   */
  private function redisBins(): array {
    $cache = $this->settings->get('cache', []);
    $bins = array_keys(array_filter($cache['bins'] ?? [], static fn ($service) => $service === 'cache.backend.redis'));
    return ($cache['default'] ?? '') === 'cache.backend.redis' ? array_merge(['default'], $bins) : $bins;
  }

  /**
   * Pings Redis and returns what went wrong, or NULL when it answers.
   *
   * The ping goes through the same client, timeouts and TLS options as the
   * cache bins, so a mismatch between the settings and the server shows up
   * here exactly as it does for visitors.
   *
   * Every failure is reported, not only Redis errors: a status report that
   * throws (for example when no Redis library is installed at all) takes down
   * the page that is meant to explain the problem.
   */
  private function connectionError(): ?string {
    try {
      ($this->clientFactory)();
      ClientFactory::getClient()->ping();
      return NULL;
    }
    catch (\Throwable $e) {
      return $e::class . ': ' . $e->getMessage();
    }
  }

  /**
   * Returns what is not as intended, as sentences.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   The problems, empty when everything is as intended.
   */
  private function configurationProblems(): array {
    $problems = [];
    if (!$this->redisFactory instanceof FailOpenCacheBackendFactory) {
      $problems[] = $this->t('The cache.backend.redis service is not decorated, so a Redis outage returns HTTP 500.');
    }
    elseif (!$this->settings->get('perf_redis_failopen_enabled', TRUE)) {
      $problems[] = $this->t('Fail-open is switched off (PERF_REDIS_FAILOPEN=0): a Redis outage returns HTTP 500.');
    }
    if ($this->clientOptionsMissing()) {
      $problems[] = $this->t('The Redis connect and read timeouts or TLS options are not applied (see the perf_redis_failopen log).');
    }
    if ($this->store->all()) {
      $problems[] = $this->t('Some bins wait to be emptied because deletes were lost during an outage; the next request that reaches Redis empties them.');
    }
    return $problems;
  }

  /**
   * Whether timeouts or TLS options were asked for but the client lacks them.
   */
  private function clientOptionsMissing(): bool {
    ($this->clientFactory)();
    return $this->options->wanted()
      && ClientFactory::getClientName() === 'PhpRedis'
      && !ClientFactory::getClientInterface() instanceof TimeoutPhpRedis;
  }

  /**
   * Builds the requirements array for the one entry.
   *
   * @param int $level
   *   One of the severity constants of this class.
   * @param mixed $value
   *   The value column.
   * @param array<int, mixed> $problems
   *   The sentences for the description column.
   *
   * @return array<string, array<string, mixed>>
   *   The requirements, keyed by id.
   */
  private function requirement(int $level, mixed $value, array $problems): array {
    return [
      'perf_redis_failopen' => [
        'title' => $this->t('Redis fail-open'),
        'value' => $value,
        'description' => implode(' ', $problems),
        'severity' => $this->severity($level),
      ],
    ];
  }

  /**
   * Returns the severity in the form this Drupal version expects.
   *
   * Drupal 11.2 and later use the RequirementSeverity enum; older versions
   * use the integer constants, which have the same values.
   */
  private function severity(int $level): mixed {
    return enum_exists(RequirementSeverity::class) ? RequirementSeverity::from($level) : $level;
  }

}
