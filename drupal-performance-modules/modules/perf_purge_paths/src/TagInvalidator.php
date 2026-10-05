<?php

declare(strict_types=1);

namespace Drupal\perf_purge_paths;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * Hands every cache tag invalidation to the paths queuer.
 *
 * Like purge_queuer_coretags, this runs on every invalidation of a cache tag.
 * Core creates every cache_tags_invalidator while it creates the cache tags
 * service, which happens before the entity type manager or the purge services
 * can be created. So this class holds only the settings and a closure: the
 * queuer, and everything it needs, is resolved on the first invalidation, and
 * not at all while path purging is off.
 */
final class TagInvalidator implements CacheTagsInvalidatorInterface {

  /**
   * Constructs a TagInvalidator.
   *
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings (perf_purge_paths_enabled).
   * @param \Closure $queuer
   *   Returns the PathsQueuerInterface service when called.
   */
  public function __construct(
    private readonly Settings $settings,
    #[AutowireServiceClosure('perf_purge_paths.queuer')]
    private readonly \Closure $queuer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function invalidateTags(array $tags): void {
    if (!$this->settings->get('perf_purge_paths_enabled', FALSE)) {
      return;
    }
    ($this->queuer)()->queueTags($tags);
  }

}
