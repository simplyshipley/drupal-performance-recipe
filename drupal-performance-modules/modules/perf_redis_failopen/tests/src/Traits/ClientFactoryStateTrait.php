<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Traits;

use Drupal\redis\ClientFactory;

/**
 * Resets the process-wide state of the redis module's client factory.
 *
 * ClientFactory keeps the client proxy and the connected client in static
 * properties, so one test would otherwise leak its client into the next.
 */
trait ClientFactoryStateTrait {

  /**
   * Forgets the client proxy and the connected client.
   */
  protected function resetClientFactory(): void {
    foreach (['_clientInterface', '_client'] as $name) {
      (new \ReflectionProperty(ClientFactory::class, $name))->setValue(NULL, NULL);
    }
  }

  /**
   * Pretends something already connected a client in this process.
   *
   * @param object|null $client
   *   The connected client; a plain object when the test does not use it.
   */
  protected function pretendClientIsConnected(?object $client = NULL): void {
    (new \ReflectionProperty(ClientFactory::class, '_client'))->setValue(NULL, $client ?? new \stdClass());
  }

}
