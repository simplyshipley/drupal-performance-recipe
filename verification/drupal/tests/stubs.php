<?php

/**
 * @file
 * Synthetic Drupal fixtures. No real Drupal site or database is loaded.
 */

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Cache {
  /** Synthetic factory; any accidental bin operation must fail the test. */
  final class FailOpenCacheBackendFactory {
    public function get(string $bin): never {
      throw new \RuntimeException('Synthetic fixture forbids cache operations.');
    }
  }
}

namespace Drupal\Core\Cache {
  /** Synthetic database checksum class; no database exists. */
  final class DatabaseCacheTagsChecksum {}
}

namespace Drupal\Core\Lock {
  /** Synthetic lock class; no database exists. */
  final class DatabaseLockBackend {}
}

namespace Drupal\Core\Flood {
  /** Synthetic flood class; no database exists. */
  final class DatabaseBackend {}
}

namespace Drupal\Core\Site {
  /** Synthetic settings with secret sentinels that must not enter reports. */
  final class Settings {
    public static function getInstance(): self {
      return new self();
    }

    public function get(string $key, mixed $default = NULL): mixed {
      $bins = array_fill_keys(['render', 'page', 'dynamic_page_cache', 'data', 'entity', 'menu'], 'cache.backend.redis');
      if (getenv('PERF_SYNTHETIC_FIXTURE') === 'bad-bin') {
        $bins['page'] = 'cache.backend.null';
      }
      $settings = [
        'redis.connection' => [
          'host' => 'synthetic-redis.example.invalid',
          'port' => 6378,
          'base' => 0,
          'interface' => 'PhpRedis',
          'password' => 'SYNTHETIC_SECRET_PASSWORD_DO_NOT_PRINT',
        ],
        'cache' => ['bins' => $bins],
        'cache_prefix' => 'synthetic-qa',
        'perf_redis_enabled' => TRUE,
        'reverse_proxy' => TRUE,
        'reverse_proxy_addresses' => ['192.0.2.10'],
        'trusted_host_patterns' => ['^synthetic\.example\.invalid$'],
        'database_secret' => 'SYNTHETIC_SECRET_DATABASE_DO_NOT_PRINT',
      ];
      return $settings[$key] ?? $default;
    }
  }
}

namespace {
  /** Synthetic container allows only the service reads used by the audit. */
  final class SyntheticContainer {
    public function has(string $id): bool {
      return in_array($id, ['cache.backend.redis', 'cache_tags.invalidator.checksum', 'lock', 'flood'], TRUE);
    }

    public function get(string $id): object {
      return match ($id) {
        'cache.backend.redis' => new \Drupal\perf_redis_failopen\Cache\FailOpenCacheBackendFactory(),
        'cache_tags.invalidator.checksum' => new \Drupal\Core\Cache\DatabaseCacheTagsChecksum(),
        'lock' => new \Drupal\Core\Lock\DatabaseLockBackend(),
        'flood' => new \Drupal\Core\Flood\DatabaseBackend(),
        default => throw new \RuntimeException('Forbidden synthetic service access.'),
      };
    }

    public function hasParameter(string $name): bool {
      return in_array($name, ['twig.config', 'site.path', 'cache_default_bin_backends', 'memory_cache_default_bin_backends'], TRUE);
    }

    public function getParameter(string $name): mixed {
      return match ($name) {
        'twig.config' => ['debug' => FALSE, 'cache' => TRUE],
        'site.path' => 'synthetic/sites/default',
        default => [],
      };
    }
  }

  /** Synthetic config implements only immutable get(). */
  final class SyntheticConfig {
    public function __construct(private readonly string $name) {}

    public function get(string $key): mixed {
      return match ($this->name . ':' . $key) {
        'system.performance:cache.page.max_age' => 900,
        'system.performance:css.preprocess', 'system.performance:js.preprocess',
        'perf_purge_paths.settings:enabled' => TRUE,
        'language.types:negotiation.language_interface.enabled' => ['language-url' => 0, 'language-selected' => 1],
        'language.types:negotiation' => getenv('PERF_SYNTHETIC_FIXTURE') === 'browser-content' ? ['language_content' => ['enabled' => ['language-browser' => 0]]] : ['language_interface' => ['enabled' => ['language-url' => 0, 'language-selected' => 1]]],
        default => NULL,
      };
    }
  }

  /** Synthetic module handler treats the recipe requirements as enabled. */
  final class SyntheticModuleHandler {
    public function moduleExists(string $name): bool {
      return TRUE;
    }
  }

  /** Synthetic facade, not a real Drupal bootstrap. */
  final class Drupal {
    public static function getContainer(): SyntheticContainer {
      return new SyntheticContainer();
    }

    public static function moduleHandler(): SyntheticModuleHandler {
      return new SyntheticModuleHandler();
    }

    public static function config(string $name): SyntheticConfig {
      return new SyntheticConfig($name);
    }
  }
}
