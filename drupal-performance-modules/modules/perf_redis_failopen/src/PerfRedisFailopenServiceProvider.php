<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;
use Drupal\perf_redis_failopen\Client\TimeoutPhpRedis;

/**
 * Makes redis.factory use the bounded client before anything connects.
 *
 * Every Redis consumer gets its client through redis.factory, so its
 * configurator runs before the first connection.
 */
final class PerfRedisFailopenServiceProvider implements ServiceModifierInterface {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if ($container->hasDefinition('redis.factory')) {
      $container->getDefinition('redis.factory')->setConfigurator([TimeoutPhpRedis::class, 'install']);
    }
  }

}
