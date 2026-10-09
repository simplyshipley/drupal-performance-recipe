# Cache sync: Drupal, Redis, the purge queue and Front Door

How one content change reaches every cache layer, which edge cases were tested, and what to configure. For developers and DevOps running the purge setup. The lab uses the local edge (the Front Door stand-in, [edge-waf.md](edge-waf.md)) and mock-afd. Test logs are listed under Evidence at the end.

## The layers

| Layer | Holds | Invalidated by | How fast |
|---|---|---|---|
| Drupal render / dynamic page / page caches (Redis, fail-open) | Rendered output | Cache tags such as `node:5` and `node_list`. The tag checksums live in the database. | Immediately on save |
| Purge queue (database) | Paths waiting to be purged | Queuers: azure_cdn_purge's node-update hook, `perf_purge_paths` (this recipe, including its alias and file hooks) | Filled in the same request as the save |
| Front Door | Whole responses by path and query | A purge API call (`contentPaths`, case-insensitive, query-agnostic), or TTL expiry | When a **processor** works the queue (cron or drush), then Azure propagation (up to about 10 minutes) |

## Timeline of a node save

1. **Save:** an editor saves node 5 and the transaction commits.
2. **Cache tags:** core invalidates `node:5`, `node_list` and related tags. Drupal's own caches are fresh at once, even with Redis down; the fail-open wrapper records a pending flush.
3. **Queue fill:** in the same request, `perf_purge_paths` queues `/node/5`, `/node/5/`, the alias and `alias/`, plus the listing paths `/`, `/node` and `/rss.xml`. azure_cdn_purge also queues the alias.
4. **Stale window:** until a processor runs, **Front Door keeps serving the old page**. In `sync_cron` the edge still answered `TCP_HIT` with the old title.
5. **Queue processing:** the cron processor or `drush p:queue-work` sends the paths to the purge API, 50 per request (`chunk_size`).
6. **Edge fetch:** the next request at the edge misses and fetches the new page from Drupal.

**Ordering:** purges are sent only when the queue is worked, which is always after the save's
transaction. So Front Door cannot refetch a page before Drupal's own caches were invalidated.

**Stale time:** the stale window equals the processor interval plus Azure's propagation delay.
There is no late-runtime processor.

## What was wrong (baseline) and what the recipe changes

**azure_cdn_purge 1.0.4 queues only the current alias of an updated node**
(`azure_cdn_purge_node_update()`).
- There is no insert, delete or other entity type.
- It never queues `/node/N`, listings, files or old aliases.
- `purge_queuer_coretags` adds nothing, because the Azure purger does not accept `tag` invalidations.

**The purger declares `wildcardpath` but calls Azure only for `path`.** Wildcard chunks are
marked SUCCEEDED **without any API call** (`AzurePurger.php`, `if ($invalidation_type == 'path')`).

The fixes (small and in scope):
1. **`modules/perf_purge_paths`.** A cache-tags invalidator registered by `settings.performance.php`, the same way as the fail-open wrapper, so no module install is needed. It maps invalidated tags to paths:

   | Tag | Paths queued |
   |---|---|
   | `node:N`, `taxonomy_term:N` | System path, current alias, trailing-slash variants |
   | `file:N` | File URL, and every image-style derivative URL |
   | `*_list` | `PERF_PURGE_LIST_PATHS` (default `/,/node,/rss.xml`; `/news/*` allowed) |
   | Block content, menu links, menus, blocks | `PERF_PURGE_SITEWIDE_PATHS` (default empty) |

   `$config['perf_purge_paths.settings']['enabled'] = FALSE;` in settings.php turns it off. It queues through azure's `path_queuer`, so the queue, processors and chunking are unchanged.
2. **`patches/azure_cdn_purge-wildcardpath-purged.patch`.** Wildcard invalidations are now sent to Azure. Add it to the root composer.json patches ([install.md](install.md)).
3. **Alias and file hooks in `perf_purge_paths`** (formerly the optional `perf_purge_deleted` module). They run while the alias or file still exists, because tags arrive too late for a deleted one (open decision 1). They use the same queuer, so switching path purging off turns them off too. Nothing to install ([install.md](install.md#deleted-and-renamed-aliases-and-deleted-files)).

   | Hook | Queues |
   |---|---|
   | `hook_path_alias_delete` | The alias and `alias/`, also when its node is deleted |
   | `hook_path_alias_insert` | The new alias and `alias/`, so a 404 the edge cached before the page existed is purged when the page is published (Bo ruling 2026-10-07) |
   | `hook_path_alias_update` | The old alias and `old/`, when the alias text or its system path changed (a retarget queues the alias itself) |
   | `hook_file_delete` | The file URL and every image-style URL of the file |

## Queue volume

These counts were measured with azure_cdn_purge's node hook removed by perf_purge_paths [V: tmp/jrock-cut/13-ddev-suite.log].

- **One node save with an alias queues 9 invalidations, each exactly once** (test `sync_single_save`):
  - `/`, `/node`, `/node/`, `/rss.xml`, `/rss.xml/`
  - `/node/N`, `/node/N/`
  - the alias and `alias/`
- **Bulk:** 60 node saves in one drush call queued 125 items (the listing paths once per request), which were sent as 3 purge requests of up to 50 paths.
  - Before the dedupe it was 185 items: duplicates plus azure_cdn_purge's own alias item.
  - With azure_cdn_purge alone it was 60 (alias only).
- **Dedupe:** a tag is mapped once per PHP request, and each invalidation queues a path once. Limitation: a long-running process that saves, works the queue, then saves the same node again in the same request would not re-queue it. Normal saves, cron and drush runs are separate requests.
- **Double-queue fix:** while installed, `perf_purge_paths` removes azure_cdn_purge's `hook_node_update` (`#[RemoveHook]`; `hook_module_implements_alter()` on Drupal 10.6), which would queue the alias a second time.

## Edge cases and results (DDEV lab, 2026-09-28)

"Before" means azure_cdn_purge alone: `SYNC_NEGATIVE=1` (then `PERF_PURGE_PATHS=0`, a variable since removed; now `PERF_LAB_PURGE_OFF=1`) and the purger unpatched.

| Case | Test | Before | After |
|---|---|---|---|
| One save queues each path once | `sync_single_save` | – | **PASS** (fail-proof: with `SYNC_NEGATIVE=1` only the alias is queued, so it FAILS [V: tmp/ddev/sync-single-failproof.log]) |
| Node with alias: alias, `/node/N`, trailing slash, `?query` | `sync_alias` | FAIL: only the alias (and its query variants) purged; `/node/N`, `/node/N/`, `alias/` stale | **PASS** |
| New node shows in listings (`/`, `/node`, `/rss.xml`, `?page=0`) | `sync_listing` | FAIL: all stale, new title missing | **PASS** |
| Alias changed (UI-style update of the same alias) | `sync_alias_change` | FAIL: old alias served 200 from the edge | **PASS**: the old alias was purged and is now 404. The old alias was in the purge request (mock log) because the alias lookup still returned it when `node:N` was mapped. The `path_alias` update made no new revision. That this timing holds on every save path is [ASSUMED]. See open decision 1. |
| Node deleted | `sync_delete` | FAIL: alias and `/node/N` stale | **PASS** with the delete hooks: alias and `/node/N` both 404 [V: tmp/lando-deleted-on.log]. **FINDING** without it [V: tmp/lando-deleted-off.log]: `/node/N` was purged (now 404), but the **alias stays cached** until its TTL. Cause [V: Drupal 10.6.17 core read in the Lando lab, 2026-10-05]: the alias is already deleted when the tags are mapped (ordering below). See open decision 1. |
| Alias deleted on its own (the node stays) | `sync_alias_delete` | FINDING: alias and `alias/` stay `TCP_HIT` | **PASS** with the module: both purged (HIT → MISS). The edge then refetches a **200** from Drupal's page cache (open decision 1). FINDING without the module. |
| File deleted (upload removed, cron cleanup) | `sync_file_delete` | FINDING: file and its image-style URL stay `TCP_HIT` | **PASS** with the module: both purged, now 404. FINDING without it. |
| Image swapped, old file cleaned up | `sync_file_swap` | FINDING: old file and its image-style URL stay `TCP_HIT` | **PASS** with the module: old URLs purged (404), new file served with the right bytes. FINDING without it. |
| Alias renamed on its own (admin "URL aliases" form) | probe only, no suite test | Old alias stays `TCP_HIT` (nothing is queued) | Old alias purged (HIT → MISS) with the module [V: tmp/lando-probe-alias-rename.log, module on and off] |
| Unpublish (403) then publish | `sync_publish_cycle` | PASS (the node update queues the alias) | PASS |
| 404 at a path that later gets content | `sync_new_path_404` | PASS | **PASS since 2026-10-07**, with `hook_path_alias_insert`. Core's 404s are public now (`Cache-Control: max-age=900, public`), so Front Door can store them. Edge `TCP_HIT`/404 became `TCP_MISS`/200 after the purge; without the hook it stayed `TCP_HIT`/404 [V: tmp/drop-fast404-20261007/16-probe-published-new-path.log]. The old claim (404s are `PRIVATE_NOSTORE`) was true for the removed fast_404 module's response path, not for core's. The cached 403 of an unpublished node is purged on publish, as above. |
| Revision revert | `sync_revert` | PASS (a node update) | PASS |
| Taxonomy term | `sync_other_entities` | FAIL: stale | **PASS** |
| User edit | `sync_other_entities` | – | Nothing to purge: `/user/*` is bypassed at the edge |
| Media | – | – | Not installed in the lab. A file replaced in place (same file entity, same URL) is purged through `file:N`. Switching a media item to a **new** file purges nothing at the edge: `media:N` tags map to no paths, and the old file URL stays public [V: code read, `PathMapper.php` maps only `node`, `taxonomy_term`, `file:N` and the site-wide tags; not run]. |
| Menu link / block content shown on every page | `sync_menu_block` | FAIL | **FINDING** with the default: every page stays stale until the HTML TTL. **PASS** with `PERF_PURGE_SITEWIDE_PATHS=/*`. See open decision 2. |
| File replaced at the same URL (file entity saved) | `sync_file` | FAIL: old bytes for the static TTL | **PASS** |
| Bulk: 60 nodes in one drush call | `sync_bulk` | PASS: 60 items, 2 requests of ≤50 paths | PASS: 124 items, 3 requests of ≤50 paths, queue empty |
| Processing: cron vs drush vs late runtime | `sync_cron` | PASS | PASS: stale until a processor runs. The test runs only `purge_processor_cron`'s cron hook, never a full cron (2026-10-08). The late-runtime processor is not installed. See open decision 3. |
| Wildcard purge (`news/*`) | `sync_wildcard` | FAIL: marked done, no API call | **PASS** (patch) |
| Save while Redis is down (fail-open) | `sync_redis_down` (wrappers, Redis stopped) | – | See the full-suite logs |
| Purge API returns 5xx | `purge_retry` (existing) | – | PASS: the item stays queued and is retried |
| Redirect module, language prefixes | – | – | Not in the lab and untested. Only the default-language alias is queued [ASSUMED gap for multilingual sites]. |
| Content moderation | – | – | Not installed. State changes are node saves [ASSUMED, untested]. |

## Open decisions (for Bo)

1. **Old aliases (delete, and alias changes without timing luck).**
   - **Verified ordering on delete** [V: Drupal 10.6.17 core, read in the Lando lab on 2026-10-05]:
     `core/lib/Drupal/Core/Entity/EntityStorageBase.php:450-462` runs the predelete hook, then `doDelete()`, then `postDelete()`, then the delete hook. `core/modules/path/src/Plugin/Field/FieldType/PathFieldItemList.php:57-65` removes the node's aliases during `doDelete()`. `core/lib/Drupal/Core/Entity/EntityBase.php:557-568` invalidates the entity's cache tags in `postDelete()`, after the alias is gone. `core/modules/path_alias/src/Entity/PathAlias.php:119-125` clears the alias cache.
   - **Consequence:** the tag-to-path mapping (`perf_purge_paths`, and any `hook_entity_delete` that builds a URL, such as index_now) runs too late: the alias is already deleted. The alias can still be read in `hook_entity_predelete` / `hook_node_predelete` on the node, and in `hook_path_alias_predelete` / `hook_path_alias_delete`: `PathFieldItemList.php:65` calls the `path_alias` storage `delete()`, which runs the predelete and delete hooks with the alias entity (`EntityStorageBase.php:452` and `:462`; hook names built at `:213-218`) inside the node's `doDelete()` (`:456`), before the node's `postDelete()` tag invalidation (`:460`). The delete hook was run in the lab and it works [V: tmp/lando-deleted-on.log].
   - **Status: option A is built and ships** as part of `perf_purge_paths` (lab-tested as the separate `perf_purge_deleted`: 3 new tests plus `sync_delete`, on and off).
   - A. The hooks (built, in `perf_purge_paths`, nothing to install).
   - B. Short HTML TTL and accept the window.
   - C. The redirect module.

   Recommendation: A on sites that delete or rename public pages or files often, otherwise B.

   **What the lab found while testing option A:**
   - **`path_alias:N` never fires.** Core invalidates only `route_match` for an alias (`PathAlias.php:41` and `:168-170`) [V: core read; tags recorded in the lab for a rename and for a delete: tmp/lando-probe-alias-tags.log]. So the mapping row for `path_alias:N` is dead on stock core, and a rename in the admin "URL aliases" form leaves the old alias cached. `hook_path_alias_update` is what covers it [V: tmp/lando-probe-alias-rename.log: nothing queued and `TCP_HIT` without the module; old alias queued and purged with it].
   - **Drupal's page cache outlives the alias.** After deleting only the alias entity, Drupal still answers the old alias with `200` from its page cache; a query-string variant or `drush cr` gives 404 [V: tmp/lando-probe-alias-only-delete-origin.log]. The purge is correct, but the edge refetches that 200 and keeps it for the HTML TTL. A node delete does not have this problem, because `node:N` clears the page cache. Fixed since: both alias hooks invalidate the cache tags of the entity the alias points to (`PerfPurgePathsHooks.php`).
   - **Covered:** an alias pointed at a different system path with the same text. The alias is queued and the tags of both system paths are invalidated [V: Kernel tests `AliasAndFileHooksTest::testRetargetingAnAlias*`, failing before the fix and passing after: tmp/jrock-alias-retarget/; not run in the lab].
   - **Open gap, needs Bo's ruling:** an alias added on its own (admin URL aliases form) is purged at the edge, but Drupal's own cached 404 is cleared only by entity saves (the `4xx-response` tag). The edge can refetch that cached 404 for up to `cache_ttl_4xx` (3600 s) [V: tmp/drop-fast404-20261007/17 log].
   - **Queue volume:** an import that creates N aliased nodes queues about 2N extra paths (the alias and `alias/` for each).
   - **Not covered** [INFERRED from the hook code, not run]: aliases of other languages (no language prefix is queued).
   - **Queue volume per deleted file:** the file URL plus one URL per image style, whatever the file type or scheme (private, temporary, PDFs). `PathsQueuer::pathsForFile()` does not filter, and the tag mapping is unchanged [V: code; a deleted PNG queued 1 + 4 paths in the Lando lab]. A cleanup that deletes many files queues that many times (1 + number of styles).
2. **Menus and blocks (site-wide changes).**
   - A. `PERF_PURGE_SITEWIDE_PATHS=/*`: a full endpoint purge on each save, which causes an origin load spike.
   - B. Wait for the HTML TTL.

   Recommendation: B with a short TTL. Use A where menus change rarely and must be correct at once.
3. **Stale window.**
   - A. Cron every 1–5 minutes.
   - B. `purge_processor_lateruntime`, which purges during the editor's request. It needs an Azure token and API call per save, which adds latency to saves.
   - C. A scheduled drush run.

   Recommendation: A (5 minutes) with a short HTML TTL.

## Cron contention (found 2026-09-29, Lando)

In the Lando lab a full-suite `sync_cron` FAILED (when it still ran a full `drush cron`; since 2026-10-08 it runs only Purge's cron hook):
- `drush cron` did nothing because another cron run held the lock. Watchdog shows many "Attempting to re-run cron while it is already running".
- The holder was an `automated_cron` run that a page request started at 20:47:38Z.
- Measured with `drush cron -v`: `search_cron()` took **46.7 s**, indexing the lab's backlog of synthetic nodes. A run like that blocks the purge processor until it finishes.
- Stopping the lab killed that run, so the lock stayed until it expired after 900 s.

**Production implication:** the purge cron processor waits behind every slow cron hook (search indexing, feeds, and so on), and page requests pay for automated_cron. Recommendation:
- **Disable automated_cron.** Run cron from a scheduler instead.
- **Work the purge queue separately.** Run `drush p:queue-work --finish` on its own short schedule, every 1–5 minutes.
- **Watch the lock.** Alert on a cron lock older than a few minutes.

The test now waits up to 180 s for the lock before running cron, and reports the wait.

## Recommended settings

- **HTML TTL:** 15–60 min. This bounds every remaining gap.
- **Static assets:** long TTL. Aggregates are content-hashed, and same-URL replacements are purged via `file:N`.
- **Processors:** cron processor on and cron every 5 minutes, plus `drush p:queue-work` in deploys.
- **perf_purge_paths:**
  - Keep path purging on (the default).
  - Set `PERF_PURGE_LIST_PATHS` to the site's listings, for example `/,/node,/rss.xml,/news/*`.
  - Wildcards need the patch.
- **Alias and file hooks:** part of `perf_purge_paths`, on with path purging.
- **Monitoring:** watch `drush p:queue-volume` and dblog. A growing queue means purges fail, for example because of credentials.

## Evidence

- Before the fixes: [V: tmp/ddev/sync-baseline.log]
- After the fixes: [V: tmp/ddev/sync-fixed.log]
- Fail-proof: [V: tmp/ddev/sync-failproof.log]
- Full suites: [V: tmp/ddev/run15.log, tmp/lando-run11.log] (earlier: run14-sync, lando-run10-sync)
- Delete tests, module on and off: [V: tmp/lando-deleted-on.log, tmp/lando-deleted-off.log]; fail-proof [V: tmp/lando-deleted-failproof.log]
- Full suites with the delete hooks (then the separate `perf_purge_deleted` module, now part of `perf_purge_paths`): [V: tmp/lando-run17-deleted.log, tmp/ddev/run19-deleted.log]
