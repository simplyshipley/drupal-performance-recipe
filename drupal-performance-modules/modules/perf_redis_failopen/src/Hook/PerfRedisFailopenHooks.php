<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Hook;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\perf_redis_failopen\Cache\FailOpenCacheBackendFactory;
use Drupal\perf_redis_failopen\Cache\RedisHealth;
use Drupal\perf_redis_failopen\Client\TimeoutClientInstaller;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Hook implementations of the Redis fail-open module (Drupal 11.1 and later).
 *
 * Drupal 10.6 to 11.2 reach the status entry through the procedural
 * hook_requirements() in perf_redis_failopen.install, and Drupal 10.6 the
 * Performance page section through perf_redis_failopen.module.
 */
final class PerfRedisFailopenHooks {

  use StringTranslationTrait;

  /**
   * Constructs a PerfRedisFailopenHooks.
   *
   * @param \Drupal\perf_redis_failopen\Cache\RedisHealth $health
   *   The outage state the cache wrapper keeps.
   * @param \Drupal\perf_redis_failopen\Client\TimeoutClientInstaller $installer
   *   Knows whether the bounded Redis client is in use.
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The string translation service.
   * @param \Drupal\perf_redis_failopen\Cache\FailOpenCacheBackendFactory $factory
   *   The decorating cache factory, which knows the config switch.
   */
  public function __construct(
    private readonly RedisHealth $health,
    private readonly TimeoutClientInstaller $installer,
    private readonly Settings $settings,
    TranslationInterface $translation,
    #[Autowire(service: 'perf_redis_failopen.cache_factory')]
    private readonly FailOpenCacheBackendFactory $factory,
  ) {
    $this->stringTranslation = $translation;
  }

  /**
   * Implements hook_form_FORM_ID_alter() for system_performance_settings.
   *
   * Adds the Redis on/off switch. Core's ConfigFormBase loads, validates and
   * saves the #config_target element, and says when settings.php overrides
   * it.
   */
  #[Hook('form_system_performance_settings_alter')]
  public function formSystemPerformanceSettingsAlter(array &$form): void {
    $form['perf_redis_failopen'] = [
      '#type' => 'details',
      '#title' => $this->t('Redis cache'),
      '#open' => TRUE,
      'perf_redis_failopen_settings_enabled' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Use Redis for the cache bins set in settings.php'),
        '#config_target' => 'perf_redis_failopen.settings:enabled',
        '#description' => $this->t('Off: those bins use the database, and each is emptied once when Redis is switched on again. To switch it off for one environment, add this line to its settings.php: <code>@line</code>', [
          '@line' => "\$config['perf_redis_failopen.settings']['enabled'] = FALSE;",
        ]),
      ],
    ];
  }

  /**
   * Implements hook_runtime_requirements().
   *
   * One status report entry. Error while Redis is skipped after an error (the
   * site still works on cache misses; drupal/redis's own entry shows the
   * connection). Warning when Redis or fail-open is switched off or the
   * bounded client is not in use. Information when no bin uses Redis.
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
    if ($this->health->isDown()) {
      return $this->requirement(2, $this->t('Degraded: Redis does not answer'), $this->t('Every read from the Redis bins is a miss and writes are dropped, so pages are slower and the database does more work. Redis is used again as soon as it answers. The perf_redis_failopen log names the address and the error.'));
    }
    $problems = $this->problems();
    return $this->requirement($problems ? 1 : 0, $this->t('Redis bins: @bins', ['@bins' => implode(', ', $bins)]), implode(' ', $problems));
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
   * Returns what is not as intended, as sentences.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   The problems, empty when everything is as intended.
   */
  private function problems(): array {
    $problems = [];
    if (!$this->settings->get('perf_redis_failopen_enabled', TRUE)) {
      $problems[] = $this->t('Fail-open is switched off (PERF_REDIS_FAILOPEN=0): a Redis outage returns HTTP 500.');
    }
    if ($this->installer->isMissing()) {
      $problems[] = $this->t('The Redis connect and read timeouts or TLS options are not applied: another Redis client connected first.');
    }
    return $problems;
  }

  /**
   * Builds the requirements array for the one entry.
   *
   * @param int $level
   *   -1 info, 0 OK, 1 warning, 2 error (the values of both severity types).
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
