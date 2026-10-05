<?php

declare(strict_types=1);

namespace Drupal\perf_purge_paths;

/**
 * Removes azure_cdn_purge's node_update implementation (Drupal 10.6 to 11.1).
 *
 * The azure_cdn_purge module queues the alias of an updated node from its own
 * hook_node_update(). While perf_purge_paths is installed the tag mapping
 * already queues that alias, so the contrib implementation only queues it a
 * second time. Drupal 11.2 and later drop it with #[RemoveHook] on the hook
 * class; older versions use hook_module_implements_alter(), which calls this.
 *
 * Plain static code, on purpose: module implementations are looked up while
 * services are still being created, so this must not need a service.
 */
final class ImplementationFilter {

  /**
   * The module whose implementation is removed.
   */
  public const MODULE = 'azure_cdn_purge';

  /**
   * The hook it is removed from.
   */
  public const HOOK = 'node_update';

  /**
   * Removes the implementation from the list for the hook, if it is there.
   *
   * @param array<string, string> $implementations
   *   The implementations of $hook, keyed by module name, altered in place.
   * @param string $hook
   *   The hook name without the "hook_" prefix.
   */
  public static function apply(array &$implementations, string $hook): void {
    if ($hook === self::HOOK) {
      unset($implementations[self::MODULE]);
    }
  }

}
