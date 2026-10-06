<?php

declare(strict_types=1);

namespace Drupal\perf_purge_paths\Hook;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ProceduralCall;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\ToConfig;
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
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Purges what the tag-to-path mapping cannot find once it is deleted.
 *
 * The cache tags of a deleted entity are invalidated after it is gone, so the
 * tag mapping cannot look up its alias or its file URL any more. These hooks
 * run while the alias or file is still in hand:
 *
 * - a URL alias is deleted (also when its node is deleted): the alias is
 *   queued;
 * - a URL alias is renamed: the old alias is queued;
 * - a file is deleted (an upload removed, or a replaced file cleaned up by
 *   cron): the file URL and its image style derivative URLs are queued.
 *
 * A deleted or renamed alias also invalidates the cache tags of the entity it
 * pointed to. Core invalidates only route_match for an alias, so without this
 * Drupal's page cache keeps answering the old alias with a cached 200 for as
 * long as nothing else invalidates the entity.
 *
 * Every step swallows its errors (and logs them): a failed purge must never
 * stop a delete, and cron deletes files. The page cache invalidation and the
 * CDN queue are separate steps, so one failing does not skip the other: the
 * page cache does not depend on the CDN.
 *
 * On Drupal 10.6 these methods are reached through the #[LegacyHook] functions
 * in perf_purge_paths.module; Drupal 11.1 and later call them directly.
 *
 * azure_cdn_purge queues the alias of an updated node from its own
 * hook_node_update(). The tag mapping already queues that alias, so the
 * RemoveHook below drops the contrib implementation (Drupal 11.2 and later);
 * Drupal 10.6 to 11.1 do the same through hook_module_implements_alter() in
 * perf_purge_paths.module.
 *
 * The on/off switch and a link to azure_cdn_purge's manual purge form are on
 * core's Performance page, and one status report entry shows the purge
 * queue counts.
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
   * @param \Psr\Log\LoggerInterface $logger
   *   The perf_purge_paths logger channel.
   * @param \Drupal\purge\Plugin\Purge\Queue\StatsTrackerInterface $stats
   *   Purge's queue statistics (counters kept in state).
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, to read the switch with its overrides.
   */
  public function __construct(
    private readonly PathsQueuer $queuer,
    private readonly PathMapper $mapper,
    private readonly CacheTagsInvalidatorInterface $invalidator,
    #[Autowire(service: 'logger.channel.perf_purge_paths')]
    private readonly LoggerInterface $logger,
    #[Autowire(service: 'purge.queue.stats')]
    private readonly StatsTrackerInterface $stats,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_form_FORM_ID_alter() for system_performance_settings.
   *
   * Adds the on/off switch and a link to azure_cdn_purge's manual purge form.
   * Core's ConfigFormBase loads, validates and saves the #config_target
   * element, and says when settings.php overrides it. An overridden switch is
   * shown read-only (see lockIfOverridden()).
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
    $this->lockIfOverridden($form['perf_purge_paths']['perf_purge_paths_settings_enabled']);
  }

  /**
   * Shows the value settings.php sets for the switch, and does not save it.
   *
   * Core's ConfigFormBase shows and saves the stored value of a #config_target
   * element and only says that settings.php overrides it
   * (loadDefaultValuesFromConfig(), submitForm(), checkConfigOverrides()).
   * Like core's SystemBrandingOffCanvasForm, an overridden value is not
   * edited: the checkbox shows the value that applies and is disabled, and
   * ToConfig::NoOp keeps the stored value as it is.
   *
   * @param array $element
   *   The switch checkbox.
   */
  private function lockIfOverridden(array &$element): void {
    $config = $this->configFactory->get('perf_purge_paths.settings');
    if (!$config->hasOverrides('enabled')) {
      return;
    }
    $element['#default_value'] = (bool) $config->get('enabled');
    $element['#disabled'] = TRUE;
    $element['#config_target'] = new ConfigTarget('perf_purge_paths.settings', 'enabled', toConfig: static fn (): ToConfig => ToConfig::NoOp);
  }

  /**
   * Implements hook_runtime_requirements().
   *
   * One status report entry: on or off, Purge's own queue counters, and a
   * link to azure_cdn_purge's manual purge form. Drupal 10.6 to 11.2 reach it
   * through hook_requirements() in perf_purge_paths.install.
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
   * A node delete removes the node's aliases inside the node's own delete, so
   * this runs before the node's cache tags are invalidated.
   */
  #[Hook('path_alias_delete')]
  public function pathAliasDelete(PathAliasInterface $alias): void {
    $this->guarded(fn () => $this->invalidateEntityTags((string) $alias->getPath()));
    $this->guarded(fn () => $this->queuer->queuePaths([(string) $alias->getAlias()]));
  }

  /**
   * Implements hook_path_alias_update().
   *
   * Queues the old alias when the alias text changed. The tag mapping only
   * finds the new one: an alias update makes no new revision to read the old
   * one from.
   */
  #[Hook('path_alias_update')]
  public function pathAliasUpdate(PathAliasInterface $alias): void {
    $original = $this->original($alias);
    if (!$original instanceof PathAliasInterface || $original->getAlias() === $alias->getAlias()) {
      return;
    }
    $this->guarded(fn () => $this->invalidateEntityTags((string) $original->getPath(), (string) $alias->getPath()));
    $this->guarded(fn () => $this->queuer->queuePaths([(string) $original->getAlias()]));
  }

  /**
   * Implements hook_file_delete().
   */
  #[Hook('file_delete')]
  public function fileDelete(FileInterface $file): void {
    $this->guarded(fn () => $this->queuer->queuePaths($this->mapper->pathsForFile($file)));
  }

  /**
   * Runs a callback and logs, instead of throwing, when it fails.
   *
   * @param callable $callback
   *   The work to do.
   */
  private function guarded(callable $callback): void {
    try {
      $callback();
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not queue purge paths: @msg', ['@msg' => $e->getMessage()]);
    }
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
   * Drupal 11.2 added getOriginal() and deprecated the "original" property;
   * Drupal 10.6 has only the property.
   */
  private function original(PathAliasInterface $alias): mixed {
    return method_exists($alias, 'getOriginal') ? $alias->getOriginal() : ($alias->original ?? NULL);
  }

}
