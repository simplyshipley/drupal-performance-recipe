<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;
use Drupal\perf_redis_failopen\Client\TimeoutClientInstaller;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Bounds the Redis connect and read time before anything connects.
 *
 * The redis 1.x module has no timeout setting (the upstream evaluation is in
 * the package README). Every consumer (cache backends, lock, flood, queue and
 * the cache tag checksum) gets its client through the redis.factory service,
 * so a configurator on that service runs before the first connection,
 * whatever is instantiated first.
 */
final class PerfRedisFailopenServiceProvider implements ServiceModifierInterface {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if (!$container->hasDefinition('redis.factory')) {
      return;
    }
    $container->getDefinition('redis.factory')
      ->setConfigurator([new Reference(TimeoutClientInstaller::class), 'install']);
  }

}
