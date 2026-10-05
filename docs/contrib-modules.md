# Contrib modules evaluated

What was checked, and why each module is or is not in the recipe. Research date 2026-10-05.
Evidence labels as in the [README](../README.md): [V: source] = read in the module's source code, [REPORTED] = relayed from an issue thread or a page summary, [INFERRED] = reasoning,
untested. The problem they were measured against is the open delete and menu gaps in
[cache-sync.md](cache-sync.md#open-decisions-for-bo).

| Module | Version | Verdict | Reason in one line |
|---|---|---|---|
| `purge_queuer_url` | 8.x-1.2 | **Not adopted** | Registry growth, `LIKE` scans, fan-out on broad tags |
| `purge_file` | 1.3.1 | **Not adopted** (watch) | The recipe already purges same-URL file replacement; file delete is untested |
| `query_string_optimizer` | 1.0.x-dev | **Not adopted** | No security coverage; Front Door already ignores parameter order |
| `index_now` | 3.1.11 | **Watch** | Likely submits `/node/N`, not the alias, on delete [INFERRED] |

## purge_queuer_url 8.x-1.2: not adopted

It records every anonymous page-cache miss (URL plus its cache tags) and, on a tag invalidation,
queues every recorded URL with that tag [V: `src/StackMiddleware/UrlRegistrar.php:98-128`,
`src/TrafficRegistry.php:118-140`, `src/Plugin/Purge/Queuer/UrlAndPathQueuer.php:94-139`, cloned at
8.x-1.2].

- **Why it looks attractive:** a deleted page's alias was recorded under `node:N` before the
  delete, so it would close open decision 1 without an alias lookup [INFERRED; untested].
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

## purge_file 1.3.1: not adopted, watch

Drupal 8 to 11, security covered, about 2,402 installs [REPORTED: drupal.org project page via WebFetch 2026-10-05]. It
purges file URLs when a file is updated or deleted. The recipe already purges a file replaced at
the same URL through `perf_purge_paths` (`file:N` tag: the file URL and every image-style
derivative); test `sync_file` PASSES [V: [cache-sync.md](cache-sync.md#edge-cases-and-results-ddev-lab-2026-09-28)].
Deleting a file is not tested in the lab. If file delete turns out to leave stale URLs at Front
Door, evaluate this module then.

## query_string_optimizer 1.0.x-dev: not adopted

Only a dev release exists and it has no security coverage [REPORTED: drupal.org project page via WebFetch 2026-10-05]. It
sorts and de-duplicates query parameters with a 301 redirect. Front Door already ignores
parameter order (see [research-afd-waf.md](research-afd-waf.md)), so the redirect adds a request
for no gain. Duplicate parameters are not covered by Microsoft's text, which is the only open
point.

## index_now 3.1.11: watch

It submits changed URLs to search engines. It uses `hook_entity_delete` and `Url::fromRoute`, so
on delete it likely submits `/node/N` rather than the alias [INFERRED from source:
`src/Hook/EntityActions.php:93-101`, `src/AbstractEntityOperations.php:163-168`; untested]. That
is the same ordering problem as the purge gap: by `hook_entity_delete` the alias is already gone
([cache-sync.md](cache-sync.md#open-decisions-for-bo)). It is a search-engine feature, not a
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
All drupal.org facts there came from fetched page summaries, and `purge_queuer_url`'s delete and
menu behaviour was [INFERRED], not tested in the lab.
