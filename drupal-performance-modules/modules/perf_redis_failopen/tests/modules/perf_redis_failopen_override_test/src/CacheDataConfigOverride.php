<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen_override_test;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;

/**
 * A config override that needs cache.data, as the key module's does.
 *
 * The config factory builds it with itself, so building config.factory
 * builds cache.data.
 */
final class CacheDataConfigOverride implements ConfigFactoryOverrideInterface {

  /**
   * Constructs a CacheDataConfigOverride.
   */
  public function __construct(
    public readonly CacheBackendInterface $cache,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function loadOverrides($names) {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix() {
    return 'perf_redis_failopen_override_test';
  }

  /**
   * {@inheritdoc}
   */
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata($name) {
    return new CacheableMetadata();
  }

}
