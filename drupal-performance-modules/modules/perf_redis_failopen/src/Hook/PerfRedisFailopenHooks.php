<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Hook;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\perf_redis_failopen\Cache\FailOpenCacheBackendFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The status report entry of the Redis fail-open module.
 *
 * Drupal 10.6 to 11.2 reach it through perf_redis_failopen.install.
 */
final class PerfRedisFailopenHooks {

  use StringTranslationTrait;

  /**
   * Constructs a PerfRedisFailopenHooks.
   *
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The string translation service.
   * @param \Drupal\perf_redis_failopen\Cache\FailOpenCacheBackendFactory $factory
   *   The decorating cache factory, which knows the config switch.
   */
  public function __construct(
    private readonly Settings $settings,
    TranslationInterface $translation,
    #[Autowire(service: 'perf_redis_failopen.cache_factory')]
    private readonly FailOpenCacheBackendFactory $factory,
  ) {
    $this->stringTranslation = $translation;
  }

  /**
   * Implements hook_runtime_requirements().
   *
   * Information when no bin uses Redis, a warning when Redis is switched off,
   * otherwise the Redis bins. drupal/redis's own entry shows the connection.
   *
   * @return array<string, array<string, mixed>>
   *   The requirements for the status report.
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $bins = $this->redisBins();
    if (!$bins) {
      return $this->requirement(-1, $this->t('No cache bin uses Redis'), '');
    }
    if (!$this->factory->isRedisOn()) {
      return $this->requirement(1, $this->t('Off (perf_redis_failopen.settings:enabled is FALSE)'), $this->t('The bins @bins use the database.', ['@bins' => implode(', ', $bins)]));
    }
    return $this->requirement(0, $this->t('Redis bins: @bins', ['@bins' => implode(', ', $bins)]), '');
  }

  /**
   * Returns the cache bins that are set to cache.backend.redis.
   *
   * @return string[]
   *   The bin names ("default" when it is the default backend).
   */
  private function redisBins(): array {
    $cache = $this->settings->get('cache', []);
    $bins = array_keys(array_filter($cache['bins'] ?? [], static fn ($service) => $service === 'cache.backend.redis'));
    return ($cache['default'] ?? '') === 'cache.backend.redis' ? array_merge(['default'], $bins) : $bins;
  }

  /**
   * Builds the requirements array for the one entry.
   *
   * @param int $level
   *   -1 info, 0 OK, 1 warning (the values of both severity types).
   * @param mixed $value
   *   The value column.
   * @param mixed $description
   *   The description column.
   *
   * @return array<string, array<string, mixed>>
   *   The requirements, keyed by id.
   */
  private function requirement(int $level, mixed $value, mixed $description): array {
    return [
      'perf_redis_failopen' => [
        'title' => $this->t('Redis fail-open'),
        'value' => $value,
        'description' => $description,
        // Drupal 11.2 and later use the enum; older versions the integers.
        'severity' => enum_exists(RequirementSeverity::class) ? RequirementSeverity::from($level) : $level,
      ],
    ];
  }

}
