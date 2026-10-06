<?php

declare(strict_types=1);

namespace Drupal\perf_purge_paths\Hook;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Extension\ProceduralCall;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Attribute\RemoveHook;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\path_alias\PathAliasInterface;
use Drupal\perf_purge_paths\PathMapper;
use Drupal\perf_purge_paths\PathsQueuer;
use Drupal\purge\Plugin\Purge\Queue\StatsTrackerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Purges what the tag mapping cannot find once it is deleted, and the UI.
 *
 * The cache tags of a deleted entity are invalidated after it is gone, so
 * these hooks queue a deleted or renamed alias and a deleted file while they
 * are still in hand. A deleted or renamed alias also invalidates the tags of
 * the entity it pointed to: core invalidates only route_match, so Drupal's
 * page cache would keep answering the old alias.
 *
 * azure_cdn_purge's hook_node_update() only queues the alias a second time,
 * so it is removed (#[RemoveHook] on Drupal 11.2 and later,
 * hook_module_implements_alter() in perf_purge_paths.module before that).
 * Drupal 10.6 reaches the other hooks through the #[LegacyHook] functions.
 */
#[RemoveHook(hook: 'node_update', class: ProceduralCall::class, method: 'azure_cdn_purge_node_update')]
final class PerfPurgePathsHooks {

  use StringTranslationTrait;

  /**
   * Constructs a PerfPurgePathsHooks.
   *
   * @param \Drupal\perf_purge_paths\PathsQueuer $queuer
   *   The paths queuer.
   * @param \Drupal\perf_purge_paths\PathMapper $mapper
   *   The tag, file and path mapper.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $invalidator
   *   The cache tags invalidator.
   * @param \Drupal\purge\Plugin\Purge\Queue\StatsTrackerInterface $stats
   *   Purge's queue statistics.
   */
  public function __construct(
    private readonly PathsQueuer $queuer,
    private readonly PathMapper $mapper,
    private readonly CacheTagsInvalidatorInterface $invalidator,
    #[Autowire(service: 'purge.queue.stats')]
    private readonly StatsTrackerInterface $stats,
  ) {}

  /**
   * Implements hook_form_FORM_ID_alter() for system_performance_settings.
   *
   * The on/off switch and a link to azure_cdn_purge's manual purge form. Core's
   * ConfigFormBase saves the #config_target element and names an override.
   */
  #[Hook('form_system_performance_settings_alter')]
  public function formSystemPerformanceSettingsAlter(array &$form): void {
    $manual = Url::fromRoute('azure_cdn_purge.purge_form');
    $form['perf_purge_paths'] = [
      '#type' => 'details',
      '#title' => $this->t('Front Door path purge'),
      '#open' => TRUE,
      'perf_purge_paths_settings_enabled' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Queue Front Door purges when content changes'),
        '#config_target' => 'perf_purge_paths.settings:enabled',
        '#description' => $this->t('To switch it off for one environment, add this line to its settings.php: <code>@line</code>', [
          '@line' => "\$config['perf_purge_paths.settings']['enabled'] = FALSE;",
        ]),
      ],
      'manual' => [
        '#type' => 'link',
        '#title' => $this->t('Purge paths manually'),
        '#url' => $manual,
        '#access' => $manual->access(),
      ],
    ];
  }

  /**
   * Implements hook_runtime_requirements().
   *
   * On or off, Purge's queue counters and the manual purge link. Drupal 10.6
   * to 11.2 reach it through perf_purge_paths.install.
   *
   * @return array<string, array<string, mixed>>
   *   The requirements for the status report.
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $on = $this->queuer->isEnabled();
    $level = $on ? 0 : 1;
    $counts = [
      '@waiting' => $this->stats->numberOfItems()->getInteger(),
      '@purged' => $this->stats->totalSucceeded()->getInteger(),
      '@failed' => $this->stats->totalFailed()->getInteger(),
    ];
    return [
      'perf_purge_paths' => [
        'title' => $this->t('Front Door path purge'),
        'value' => $on ? $this->t('On. Purge queue: @waiting waiting, @purged purged, @failed failed', $counts) : $this->t('Off (perf_purge_paths.settings:enabled is FALSE)'),
        'description' => Link::fromTextAndUrl($this->t('Purge paths manually'), Url::fromRoute('azure_cdn_purge.purge_form'))->toString(),
        // Drupal 11.2 and later use the enum; older versions the integers.
        'severity' => enum_exists(RequirementSeverity::class) ? RequirementSeverity::from($level) : $level,
      ],
    ];
  }

  /**
   * Implements hook_path_alias_delete().
   *
   * Runs inside a node delete too, before the node's tags are invalidated.
   */
  #[Hook('path_alias_delete')]
  public function pathAliasDelete(PathAliasInterface $alias): void {
    $this->invalidateEntityTags((string) $alias->getPath());
    $this->queuer->queuePaths([(string) $alias->getAlias()]);
  }

  /**
   * Implements hook_path_alias_update().
   *
   * Queues the old alias when the alias text changed.
   */
  #[Hook('path_alias_update')]
  public function pathAliasUpdate(PathAliasInterface $alias): void {
    $original = $this->original($alias);
    if (!$original instanceof PathAliasInterface || $original->getAlias() === $alias->getAlias()) {
      return;
    }
    $this->invalidateEntityTags((string) $original->getPath(), (string) $alias->getPath());
    $this->queuer->queuePaths([(string) $original->getAlias()]);
  }

  /**
   * Implements hook_file_delete().
   */
  #[Hook('file_delete')]
  public function fileDelete(FileInterface $file): void {
    $this->queuer->queuePaths($this->mapper->pathsForFile($file));
  }

  /**
   * Invalidates the cache tags of the entities the system paths show.
   *
   * @param string ...$systemPaths
   *   System paths such as "/node/5".
   */
  private function invalidateEntityTags(string ...$systemPaths): void {
    $tags = [];
    foreach ($systemPaths as $path) {
      $tags = array_merge($tags, $this->mapper->tagsForSystemPath($path));
    }
    if ($tags) {
      $this->invalidator->invalidateTags(array_values(array_unique($tags)));
    }
  }

  /**
   * Returns the alias as it was before the update, if known.
   *
   * Drupal 11.2 added getOriginal(); Drupal 10.6 has only the "original"
   * property.
   */
  private function original(PathAliasInterface $alias): mixed {
    // @phpstan-ignore function.alreadyNarrowedType
    return method_exists($alias, 'getOriginal') ? $alias->getOriginal() : ($alias->original ?? NULL);
  }

}
