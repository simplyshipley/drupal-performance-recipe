<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Hook;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\perf_redis_failopen\Diagnostic\RuntimeDiagnostics;

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
   * @param \Drupal\perf_redis_failopen\Diagnostic\RuntimeDiagnostics $diagnostics
   *   The shared runtime diagnostic collector.
   */
  public function __construct(
    private readonly Settings $settings,
    TranslationInterface $translation,
    private readonly RuntimeDiagnostics $diagnostics,
  ) {
    $this->stringTranslation = $translation;
  }

  /**
   * Implements hook_runtime_requirements().
   *
   * The state the environment sets: Redis bins, off (REDIS_ENABLED=0, a
   * warning), or not configured. drupal/redis's own entry shows the connection.
   *
   * @return array<string, array<string, mixed>>
   *   The requirements for the status report.
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    return $this->stateRequirement() + $this->diagnostics->requirements();
  }

  /**
   * Returns the existing configured-bin status independently of debug probes.
   */
  private function stateRequirement(): array {
    if ($this->settings->get('perf_redis_enabled') === FALSE) {
      return $this->requirement(1, $this->t('Off (REDIS_ENABLED=0)'), $this->t('Set by the environment. The cache bins use the database.'));
    }
    $bins = $this->redisBins();
    if (!$bins) {
      return $this->requirement(-1, $this->t('No cache bin uses Redis'), '');
    }
    return $this->requirement(0, $this->t('Redis bins: @bins', ['@bins' => implode(', ', $bins)]), $this->t('Set by the environment (REDIS_HOST, REDIS_ENABLED).'));
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
