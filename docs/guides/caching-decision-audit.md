# Caching documentation decision audit

This review compares the current recipe with the deployment requirements discussed during troubleshooting. It is a source/history audit, not a live Azure or Drupal acceptance test. It introduces no new cache duration, memory allocation or deployment setting.

## Deployment blocker: public PDF caching

The current requirement is **cache public PDFs at Front Door and purge their paths when replaced**. The checked-in PDF setup instructions and default verification expectations instead implement **no-cache**. They cannot be used to sign off the intended PDF behavior.

| Layer | Verified state | Consequence |
|---|---|---|
| [Front Door guide](../frontdoor-setup-guide.md#pdfs-and-office-files-cache-control-no-cache) | Existing PDF instructions set origin no-cache or use NoCacheDocs to disable edge caching | Following them prevents the intended PDF edge caching |
| [Lando template](../../lando/nginx-fast404.conf.tpl) | The PDF/Office location emits no-cache | A prose-only change does not align local behavior |
| [Public-file path mapper](../../drupal-performance-modules/modules/perf_purge_paths/src/PathMapper.php) | file:N maps the current public file URL without an extension filter | PDFs are supported by the purge mapping; private/temporary files are excluded |
| [Purge queuer](../../drupal-performance-modules/modules/perf_purge_paths/src/PathsQueuer.php) | Invalidated tags map to queued paths when enabled | Queuing is separate from a successful Azure purge |
| [Playwright policy checks](../../verification/playwright/policy.mjs) | document targets require no-cache and reject an edge HIT | The supplied report-pdf target verifies the old policy and can reject the desired behavior |
| [Azure comparison](../../verification/azure/verify.py) | StaticFiles extensions exclude PDFs; extra rules require manual review | Its comparison cannot certify a newly added PDF caching rule |
| [Replacement fixture](../../scripts/test-local.sh) | sync_file writes a synthetic TXT, saves its file entity and processes the queue | This is not PDF replacement proof or live Azure evidence |

Historical evidence: commit [fb55ee9](https://github.com/simplyshipley/drupal-performance-recipe/commit/fb55ee9dc046c64792df939da88c6023b411c005) introduced the no-cache document configuration and labelled it decided. The original user instruction behind that label was not recovered during this review. That historical label does not override the current requirement. The earlier research also described shared PDF caching with separate browser revalidation as an alternative; this is evidence of an option, not approval of a specific TTL.

The original exact PDF edge TTL and browser freshness lifetime were not established by the recovered evidence. The current PDF requirement does not establish an Office-file policy either. Do not silently apply a one-day asset TTL, cache Office files, or claim an approved header value on that basis.

Before PDF sign-off, align the public-PDF origin header, Front Door rule, Lando behavior, verification profile and documentation together. Keep private/authenticated response protections. An edge purge cannot evict a browser's fresh local copy, so browser freshness/revalidation needs its own explicit policy. Origin no-cache cannot simply be left in place and overcome with an edge override; [Front Door honors that restriction](https://learn.microsoft.com/en-us/azure/frontdoor/front-door-caching#cache-expiration).

Then use a synthetic approved PDF on QA to verify: initial/repeated GET cache behavior; unchanged browser revalidation under the chosen policy; same-URL replacement through the actual editor workflow; exact path queued; successful purge processing; and changed bytes through the edge and browser. A filesystem/blob overwrite alone does not trigger Drupal's file-entity cache invalidation. Uploading a new file URL is a different workflow from replacing the same file entity. Do not infer either workflow from the generic TXT test.

## Requirements checked across the recipe

| Requirement | Review result and source |
|---|---|
| Uniform normalization of every supported ENV, including secrets | Implemented by the shared trim helper in [settings.performance.php](../../drupal-performance-modules/settings/settings.performance.php); docs must use the effective settings rather than bypassing it with raw getenv examples |
| Keep PHP mapping keys intact; configure real values in App Service | The [Azure inventory](../azure.md#app-service-application-settings-environment-variables) documents this distinction |
| Government cloud defaults, without requiring every override | AZURE_CLOUD selects Government hosts; nonempty overrides take precedence. The [inventory](../azure.md#app-service-application-settings-environment-variables) separates eight required cloud/identity/target values from optional overrides |
| Toggle path queuing with AZURE_CDN_PURGE_PATHS_ENABLED | Present in the settings include. Disabling queuing does not disable edge caching or cancel existing queued purges |
| QA exposed Redis port 6378 can target listener 6379 | Supported by effective REDIS_PORT mapping and the Azure deployment profile. Correct the handoff's old all-ports-must-match instruction |
| Distinguish SSH/Drush from Apache web-worker behavior | Raw client success, a wrapper class, key counts or empty cache tables alone cannot establish healthy web Redis use. Compare effective CLI and protected web diagnostics |
| Keep Redis fail-open during outages | The implementation returns misses on an outage. Empty cache_render can also occur in that state, so it is not sufficient success evidence |
| Redis maxmemory is an explicit deployment value | Image default 256mb and the Azure example 768mb/1Gi are different scopes. This review recovered no approved QA/production allocation for the reported 4-core/8GB environment |
| Drupal anonymous page lifetime | recipe.yml sets 900 seconds. No recovered decision makes it 24 hours |
| Front Door static matching | Directory values are OR; extension values are OR; the two conditions are AND. These are two conditions, not one condition per value |
| Session and Authorization bypass independently | The [bypass matrix](../frontdoor-setup-guide.md#3-bypass-the-cache-for-logged-in-users-and-admin-paths) specifies independent rules and STOP ordering. Configured patterns still require Azure QA validation |
| Query strings matter for aggregates and pagers | Use Query String remains the documented baseline; do not discard meaningful query values |
| Public video differs from ordinary static assets | The [video recommendation](../frontdoor-setup-guide.md#public-mp4-video) is explicitly not Azure-QA certified; range delivery and replacement remain separate tests |
| Cache HIT is per response | A parent HTML HIT does not establish asset HITs, Redis health or authenticated isolation |
| CSP audit is a local rehearsal | [CSP.md](../../verification/playwright/CSP.md) distinguishes candidate policies from live headers and includes authenticated QA coverage limits; it does not certify admin saves |
| Remove personal decision/date attributions from deployment instructions | A word-bounded search of tracked Markdown found no matching personal decision attributions in the audited checkout |

## Corrections and remaining limits

Confirmed Redis documentation drift is corrected in the [handoff](../../redis/docs/HANDOFF.md), [setup guide](../../redis/docs/SETUP-GUIDE.md), optional [managed-service guide](../../redis/docs/paid-redis-setup.md), [command reference](../command-reference.md) and [site setup guide](../setup-guide.md): client-facing versus listener ports, environment toggles, uniform trimming including passwords, and effective diagnostics instead of raw client examples or insufficient web-success proof.

The [Front Door purge procedure](../frontdoor-setup-guide.md#10-purge-from-drupal) now uses the canonical published QA URL, displays status/redirect/cache headers and bounds repeat probes. A redirect HIT is not proof that the destination HTML was cached. The troubleshooting table no longer treats stale content with an empty queue as proof of missing alias coverage, or an undrained queue as proof that cron is not running; it requires actual path, target, worker and purge evidence.

The PDF mismatch remains a coordinated implementation/verification change, not a completed deployment fix. Warnings in the main guide, quick start, verification entry points and deployment checklist make that limitation explicit. Historical test results are retained with their original scope rather than relabelled as proof of the requested PDF policy.

This audit does not establish deployed PHP settings, origin headers, Azure rule associations, memory capacity, successful PDF purging, Front Door report availability or production traffic behavior. Those require environment evidence. Source alignment and local tool tests cannot replace that evidence.
