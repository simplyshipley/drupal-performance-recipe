# Contrib modules evaluated

What was checked, and why each module is or is not in the recipe. Research date 2026-10-05.
Evidence labels as in the [README](https://github.com/simplyshipley/drupal-performance-recipe/blob/main/README.md): [V: source] = read in the module's source code, [REPORTED] = relayed from an issue thread or a page summary. The problem they were measured against is the delete and menu gaps in
[cache-sync.md](cache-sync.md#defaults-for-the-known-gaps).

| Module | Version | Verdict | Reason in one line |
|---|---|---|---|
| `purge_queuer_url` | 8.x-1.2 | **Not adopted** | Registry growth, `LIKE` scans, fan-out on broad tags |
| `purge_file` | 1.3.1 | **Not adopted** (trialled 2026-10-05) | Purges the file URL on delete but not its image-style URLs; `perf_purge_paths`'s file hook covers both |
| `query_string_optimizer` | 1.0.x-dev | **Not adopted** | No security coverage; Front Door already ignores parameter order |
| `index_now` | 3.1.11 | **Watch** | Submits `/node/N`, not the alias, on delete [V: source + core delete order] |

## purge_queuer_url 8.x-1.2: not adopted

It records every anonymous page-cache miss (URL plus its cache tags) and, on a tag invalidation,
queues every recorded URL with that tag [V: `src/StackMiddleware/UrlRegistrar.php:98-128`,
`src/TrafficRegistry.php:118-140`, `src/Plugin/Purge/Queuer/UrlAndPathQueuer.php:94-139`, cloned at
8.x-1.2].

- **Why it looks attractive:** a deleted page's alias was recorded under `node:N` before the
  delete, so it would cover known gap 1 without an alias lookup (follows from the source lines
  above; not run, because the module is not adopted).
- **Registry growth:** no expiry anywhere in the source. Issue #3386394 reports a 2.2 GB table
  that forced an uninstall [REPORTED].
- **Scans on the editor's request:** the lookup is `tag_ids LIKE '%;<id>;%'`. A leading wildcard
  cannot use an index, so each invalidation scans the whole table [V: source].
- **A database write on every anonymous cache miss** on the request path [V: source].
- **Fan-out:** `node_list`, menu and site config tags sit on nearly every page, so one save
  matches every recorded URL. The issue reports 1,880 queue items from one node save [REPORTED].
  A menu edit would become `/*` done the expensive way, as hundreds of Front Door purge calls.
- **Overlap:** it duplicates `modules/perf_purge_paths`, which maps tags to paths with no registry.
- **Maintainer position:** move to tag-based invalidation where the CDN supports tags; Front
  Door does not [REPORTED, issue #3386394, June 2025].
- **Instead:** a hook that captures the alias before it is deleted (option A in cache-sync.md, recommended on sites that delete or rename public pages often, otherwise B (short HTML TTL)) covers the
  delete gap without these costs. If a trial is wanted, measure table size, lookup time and
  queue length after one save and one menu edit on a lab with about 5,000 SYNTHETIC nodes.

Full review: session note `purge-queuer-url-review.md` (outside this repo).

## purge_file 1.3.1: not adopted (trialled 2026-10-05)

Drupal 8 to 11, security covered, about 2,402 installs [REPORTED: drupal.org project page via WebFetch 2026-10-05]. The
page also lists 2.0.0-rc1 (2026-09-22) [REPORTED]; it was not trialled. The module purges file URLs
when a file is inserted, updated or deleted. The recipe already purges a file replaced at the
same URL through `perf_purge_paths` (`file:N` tag); test `sync_file` PASSES [V: [cache-sync.md](cache-sync.md#edge-cases-and-results-ddev-lab-2026-09-28)].

**Trial** (Lando lab: `composer require drupal/purge_file:^1.3`, tests, then removed again;
`perf_purge_deleted` off; code read in `purge_file.module`) [V: tmp/lando-purge-file-trial.log,
tmp/lando-purge-file-trial-tests.log]:
- **Setup it needs:** it ships `invalidation_type: url`, which the Azure purger rejects (the purger takes `path` and `wildcardpath` only). Set `purge_file.settings` `invalidation_type` to `path`. `workflow: queue` (the default) works with the cron processor, and its queuer enables itself.
- **Queued format:** `path:sites/default/files/<name>.png`, the same as `perf_purge_paths` (`ltrim($file->createFileUrl(), '/')`). The mock Front Door got `["/sites/default/files/<name>.png"]` and the edge purged it.
- **File delete:** the file URL is purged (now 404).
- **Image styles:** the derivative stayed `TCP_HIT` with 200. It queues only the file's own URL, so `sync_file_delete` and `sync_file_swap` report FINDING.
- **Same-URL replacement:** `sync_file` PASS, unchanged.
- **Also:** it purges on insert, which the recipe does not need.
- **Verdict:** not adopted. `perf_purge_paths` (formerly `perf_purge_deleted`) covers the file URL and its image-style URLs ([cache-sync.md](cache-sync.md#defaults-for-the-known-gaps)).

## query_string_optimizer 1.0.x-dev: not adopted

Only a dev release exists and it has no security coverage [REPORTED: drupal.org project page via WebFetch 2026-10-05]. It
sorts and de-duplicates query parameters with a 301 redirect. Front Door already ignores
parameter order (see [research-afd-waf.md](research-afd-waf.md)), so the redirect adds a request
for no gain. Duplicate parameters are not covered by Microsoft's text, which is the only open
point.

## index_now 3.1.11: watch

It submits changed URLs to search engines. It uses `hook_entity_delete` and `Url::fromRoute`, so
on delete it submits `/node/N` rather than the alias [V: source at 3.1.11,
`src/Hook/EntityActions.php:91-103`, `src/AbstractEntityOperations.php:162-168`]. That
is the same ordering problem as the purge gap: by `hook_entity_delete` the alias is already gone
([cache-sync.md](cache-sync.md#defaults-for-the-known-gaps), known gap 1). It is a search-engine feature, not a
cache feature, and is not part of the recipe.

## Others looked at (purge gaps)

| Module | Verdict | Reason |
|---|---|---|
| `stale_404_purge` 1.0.0-alpha2 | Not adopted | Solves the opposite direction (cached 404 to valid page), alpha, no security coverage [REPORTED] |
| `azure_cdn_purge` 1.0.4 | In the recipe, patched | Upstream node-delete issue 3424553 is "Needs work"; watch issue 3506531 (tag purge via a database index) [REPORTED] |
| `expire` | Rejected | Drupal 6 and 7 releases only [REPORTED] |
| `redirect` | Not a purge fix | Creates a redirect row on alias change but purges nothing [REPORTED] |
| `fastly`, `cloudflare` | Idea source only | They purge by tag at the CDN, which Front Door cannot do [REPORTED] |

Survey with the full table: session note `contrib-purge-module-survey.md` (outside this repo).
All drupal.org facts there came from fetched page summaries. `purge_queuer_url`'s delete and
menu behaviour is read from its source, not run (not adopted).
