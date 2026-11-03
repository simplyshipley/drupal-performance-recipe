# Deployment verification: Drupal, Redis and Azure Front Door

Use one copy per environment, **QA first**, then the production smoke checks. This is the sign-off checklist; the [setup guide](../setup-guide.md) explains installation. All boxes start unchecked. Source review and local tests do not certify your Azure deployment.

For each item record **PASS / FAIL / BLOCKED / accepted N/A**, date, operator, actual value, evidence/report location and any approved exception with an owner. Missing evidence is BLOCKED. Never attach secrets, session cookies, authorization values or an unrestricted environment dump.

## 1. Record the environment and tested artifact

| Record | QA | Production |
|---|---|---|
| Public hostname and canonical test URLs | | |
| App Service, slot, origin hostname and deployed revision/image digest | | |
| Drupal/core, Redis module and custom-module versions | | |
| Actual included settings file path and SHA-256 | | |
| Redis app/image, exposed port, target port, DB and unique prefix | | |
| Redis container allocated CPU/RAM and effective maxmemory | | |
| Front Door cloud, SKU, profile, endpoint **name**, route, ordered rule sets | | |
| WAF policy/mode, purge identity/endpoint and cron owner | | |
| Approved HTML/browser, static/browser and static/edge TTLs; purge SLA | | |
| Evidence directory, change owner, rollback owner and acceptance date | | |

- [ ] **A1 — Deploy the tested files.** Compare the actual included settings file and custom-module package with the tested QA artifact. On this site the settings copy has been `/opt/drupal/docroot/sites/default/settings.performance.php`; verify the active include rather than assuming the package copy is used. First installation of runtime diagnostics needs the module files and settings together, followed by `drush cr`. [Install](../install.md); [web/CLI diagnosis](../troubleshooting-commands.md#azure-drush-works-but-web-requests-do-not-use-redis).
- [ ] **A2 — Verify uniform ENV normalization and artifact parity.** Compare the active deployed file hash with the tested repository artifact and confirm Apache/CLI load it. The helper now trims exterior whitespace and single/double quotes from all supported ENV values, including IDs, scope, Redis password and client secret; embedded characters remain and process ENV is unchanged. Blank-after-trim input uses defaults or leaves a runtime override absent. Saved Drupal config is not normalized or erased. The exact field responsible for the original Redis failure remains unconfirmed. Compare effective nonsecret host/port/DB/prefix metadata, never secret bytes. Boolean `1` and `0` remain valid. [Normalization contract](../azure.md#normalized-connection-fields-and-boolean-switches).
- [ ] **A3 — Isolate environments.** QA uses its own Redis target/prefix/DB and purge endpoint. Mark environment-bound App Service settings as slot settings where required. QA must never purge production. Verify Key Vault references resolve without printing their values. [Environment variables](../quickstart-azure-frontdoor.md#whats-needed-one-copy-per-site-per-environment).
- [ ] **A4 — Keep mapping keys and defaults intact.** Actual values belong in App Service, not in the PHP mapping strings. Use the eight required Government cloud/identity/target entries; optional login/management/scope/API overrides remain unset unless explicitly needed. Review stale stored scope/hosts before trusting defaults. Check effective `perf_purge_paths.settings:enabled` with `drush cget perf_purge_paths.settings enabled --include-overridden`: expect `true` for normal production, or the deliberately selected QA policy. `AZURE_CDN_PURGE_PATHS_ENABLED=0/1` controls this module's queuing, not edge caching. [Full inventory](../azure.md#app-service-application-settings-environment-variables).

## 2. Run the automated checks

The [Playwright checks](../../verification/playwright/README.md) perform bounded HTTP GET probes using Playwright's request client. They check observed headers/statuses against explicit targets and produce expected/actual/reason/troubleshooting reports. They do not crawl the page, certify rendered browser behavior, read Azure configuration, edit content or conduct outage tests.

```bash
cd verification/playwright
npm ci
# Create and review your environment-specific target file from targets.example.json.
BASE_URL=https://your-qa-host CACHE_PROFILE=qa CACHE_TARGETS=./targets.qa.json npm test
```

- [ ] **T1 — Review every target and expectation before running.** Use canonical existing public pages, representative static assets, approved private/bypass paths and a report PDF. Add a public video only if used. Use no live operation URLs such as a cron key or a delete/revert action. Tests are read-only but GET routes can have application effects; use approved safe targets.
- [ ] **T2 — Save the report and inspect gaps.** Save `verification/playwright/reports/cache-report.html` and `cache-report.json`. A skipped/unconfigured check is not a pass. With Front Door enabled, run the documented strict edge-HIT mode after propagation; record any lack of HIT as unresolved instead of assuming request two must HIT. A pre-Front Door QA run can verify Drupal policy but cannot sign off the edge.
- [ ] **T3 — Run the effective-settings audit.** Follow [Drupal settings verifier](../../verification/drupal/README.md) from the correct site's Drush bootstrap. Copy/customize `qa.example.json` or `prod.example.json`; fill the expected port, DB, unique prefix, final host SHA-256 and approved proxy addresses. Missing required expectations remain BLOCKED. Save its report, review every mismatch and compare it with the Apache web diagnostics in section 3. The verifier is an allowlisted settings/config audit, not proof of successful web-worker DNS or a live purge.
- [ ] **T4 — Compare Azure configuration.** Follow the [Azure settings verifier](../../verification/azure/README.md) to capture narrow, protected configuration exports and compare them with the approved environment profile. Save its JSON/Markdown report: route, ordered rule sets, ten bypasses, static/video rules, Redis allocation/ports and main-site restrictions are checked. Missing snapshots and additional unreviewed actions remain BLOCKED. A matching saved export cannot establish live propagation, WAF behavior or successful purging.
- [ ] **T5 — Complete the manual checks below.** Automated header checks cannot certify Azure rule ordering, identity scope, authenticated isolation, actual invalidation, failure recovery, client-IP trust or production capacity. Keep those gates open until their evidence is captured.

```bash
# In the deployed site's Drush context; use absolute paths to your reviewed files.
PERF_VERIFY_PROFILE=/absolute/profile.qa.json PERF_VERIFY_FORMAT=markdown \
  drush php:script /absolute/verification/drupal/audit.php > settings-report.md
# Repeat with PERF_VERIFY_FORMAT=json for settings-report.json.
```

## 3. Drupal settings, modules and cacheability

Run in App Service SSH against the correct site/URI. These commands read status/configuration; the diagnostic command also writes its report to the configured logger and performs short-lived synthetic cache probes.

```bash
drush status
drush pm:list --status=enabled --format=list
drush cget system.performance
drush perf:diagnose --redis --frontdoor
```

| ID | Check and expected result | Why / troubleshooting |
|---|---|---|
| D1 | `cache.page.max_age: 900`, CSS and JS preprocess `true`; admin UI `/admin/config/development/performance` agrees with effective config. Record approved deviations. | Baseline is **15 minutes**, not 24 hours for HTML. Cache lifetime is separate from Redis entry expiry. [Durations](../frontdoor-setup-guide.md#how-long-front-door-caches-each-kind-of-response) |
| D2 | Enabled: `page_cache`, `dynamic_page_cache`, `big_pipe`, `syslog`, `redis`, `purge`, `purge_ui`, `purge_processor_cron`, `purge_drush`, `azure_cdn_purge`, `perf_redis_failopen`, `perf_purge_paths`. Required patches match Composer lock and installed code. | These are the [recipe](../../recipe.yml) modules. Do not uninstall fail-open to troubleshoot an outage or blindly reapply a strict recipe over differing config. [Recipe errors](../troubleshooting-commands.md#7-recipe-and-config-errors) |
| D3 | Active settings included after site DB/bootstrap settings; no local lab/mock override, no `NullBackend` for production bins; Twig cache enabled/debug off; Xdebug inactive for production requests. | Development overrides defeat caching or expose diagnostics. [Cache modes](../cache-modes.md) |
| D4 | `REDIS_ENABLED=1`, host nonempty and normalized, correct effective port/DB/prefix. QA's working **exposed 6378 → listener 6379** is retained; production uses its actual ingress. Explicit `REDIS_PORT` wins over scheme defaults. | A reachable internal listener does not prove the app uses the correct exposed endpoint. [Redis networking](../../redis/azure/README.md); [web/CLI comparison](../troubleshooting-commands.md#azure-drush-works-but-web-requests-do-not-use-redis) |
| D5 | Actual **Apache** report shows PHP Redis extension loaded and configured client available; compare SAPI, site, include, effective connection and diagnostic revision with CLI. | `php -m`/Drush success proves CLI only. Cookie/session INI settings do not establish DNS/TCP success. [Web runtime](../troubleshooting-commands.md#azure-drush-works-but-web-requests-do-not-use-redis) |
| D6 | Six default bins `render,page,dynamic_page_cache,data,entity,menu` assign `cache.backend.redis`; their instantiated backends use the fail-open decorator. Record any deliberate `REDIS_BINS` override. | A single backend diagnostic roundtrip normally tests **one bin**, preferring `data`. PASS does not test all six. [Settings source](../../drupal-performance-modules/settings/settings.performance.php) |
| D7 | Cache tag checksum, lock and flood services remain database-backed; `redis.services.yml` loaded, not `example.services.yml`. | Intentional outage resilience. Redis's zero tag/zero invalidation warning is expected only after this service choice is verified; publishing tests must still prove invalidation. [Redis setup](../../redis/docs/SETUP-GUIDE.md) |
| D8 | Homepage **and** representative inner page (`/about-us`), content pages, languages, pager/search and forms have correct policy/content for their purpose. Intended public pages show public cacheability; personalized/form/admin responses remain private as appropriate. | One cacheable homepage is insufficient. [UNCACHEABLE diagnosis](../troubleshooting-commands.md#3-pages-are-not-cached-headers_anon-fails) |

- [ ] **D1–D8 — Record actual results for every row.** Use the settings-audit report for supported rows, plus the independent web evidence.
- [ ] **D9 — Resolve the language/response-policy failure.** For unexpected `UNCACHEABLE (response policy)`, inspect the first denying response policy and the `page_cache_kill_switch::trigger()` call stack in the actual deployed core. Browser language negotiation can trigger the kill switch whenever reached; the operator's “language” observation alone does not prove the exact caller. Review `/admin/config/regional/language/detection`: test intended URL/default-language behavior, all translated URLs and correct content before accepting a change. Do not force private responses public. [Response-policy debugging](#8-troubleshooting-map).
- [ ] **D10 — Capture web probes.** Temporarily enable `REDIS_DEBUG=1` and/or `FRONTDOOR_DEBUG=1`, visit existing `/admin/reports/redis` or `/admin/reports/status` as an authorized administrator, save report and corresponding `PERF_DIAG` log evidence, then restore `0`/unset. Expect DNS → TCP/TLS → auth/DB → PING → synthetic SET/GET/DELETE → active-backend roundtrip success, with no unresolved cooldown/pending flush. If tables are absent inspect the always-visible diagnostic marker. [Procedure](../troubleshooting-commands.md#azure-drush-works-but-web-requests-do-not-use-redis).

## 4. Redis container, real usage and recovery

- [ ] **R1 — Server configuration.** Verify the deployed image/digest, secret references, private network and DNS, readiness/liveness probes, **one always-on replica** and no scale-to-zero. This cache-only design has persistence off (`save ""`, `appendonly no`); multiple independent replicas behind an ingress do not provide coherent Redis replication. [Azure template](../../redis/azure/README.md).
- [ ] **R2 — Memory budget.** Record *each container's* allocation and effective `maxmemory`, `maxmemory_policy`, used/peak memory, RSS/working set, evictions and restarts. Repository Azure baseline is **1 GiB container / 768 MB maxmemory**, policy `volatile-lfu`; image-only default is **256 MB**. An environment described as 4 cores/8 GB is not proof of an individual container's allowance. Keep measured headroom and tune QA/prod independently from representative pressure, not IP counts alone. [Sizing](../../redis/azure/README.md#monitoring-and-alerts).
- [ ] **R3 — Overcommit warning disposition.** Record the warning, actual platform support and memory/restart evidence with the Azure owner. Host-kernel tuning is not a PHP INI setting; do not add a privileged host change to the container image. A warning alone neither proves swapping nor proves the allocation is safe.
- [ ] **R4 — Prove web usage.** Compare this site's correct DB/prefix key activity and server hit/miss deltas around repeated approved origin-reaching anonymous requests, alongside successful web diagnostics and no new Redis-unavailable logs. Global INFO counters can include other sites. Do not copy raw-`getenv` helpers blindly when their normalization/DB differs from Drupal. `X-Drupal-Cache: HIT` alone does not identify Redis storage. [Redis checks](../troubleshooting-commands.md#4-redis).
- [ ] **R5 — QA outage and recovery.** Schedule a controlled **QA-only** connectivity interruption with bins still using Redis. Exercise requests reaching the origin, not merely a warm edge HIT. Confirm baseline user behavior remains usable, dropped cache operations become misses, logging is bounded and Apache APCu shares the retry cooldown. Test a clearly labelled synthetic QA content edit/invalidation during the outage; restore service, verify current content and eventual cleared recovery state. Do not uninstall fail-open. [Fail-open behavior](../troubleshooting.md#redis-down-the-site-stays-up-fail-open).
- [ ] **R6 — Operational fallback.** Rehearse documented `REDIS_ENABLED=0` DB-cache mode and normal `REDIS_ENABLED=1` plus `drush cr` recovery in QA; record warm-up impact. Automatic fail-open means cache **misses**, not automatic DB cache writes. A recovery notice about emptying a bin protects against destructive invalidations lost during the outage; it is not by itself a fresh failure. [Redis off/on](../install.md#8-turn-caching-off-per-environment).

Do not grant the application broad Redis admin permissions just to obtain statistics. Use an approved operations identity for commands denied by the app ACL. `drush cr` invalidates/rebuilds caches; it does not guarantee immediate physical deletion of all Redis keys or return of allocated memory.

## 5. Front Door configuration and observed headers

- [ ] **F1 — Route and query key.** Verify the real environment's SKU/features/cloud, HTTPS/certificate/origin host, route `/*`, associated rule sets and order. Enable caching after bypasses are associated; HTML honors origin headers. **Use Query String**: preserve language/pager/search and CSS/JS aggregate parameters. Optional exclusions are enumerated tracking names, not literal `utm_*`. [Route](../frontdoor-setup-guide.md#1-route-caching); [query strings](../frontdoor-setup-guide.md#2-query-strings).
- [ ] **F2 — Independent bypass rules.** Verify all ten rows below, each **Caching Disabled + Stop evaluating remaining rules**. Header alternatives are separate rules. Path operator **Wildcards**, transform **To lowercase**, values within a condition OR; separate conditions AND. Path values omit the leading slash. For each path family `P`, enter `P`, `P/*`, `*/P`, `*/P/*` as individual values. [Exact portal matrix](../frontdoor-setup-guide.md#exact-conditions-and-match-values).

| Rule | Match |
|---|---|
| SessionCookie | Cookie header Contains `SESS` (also covers `SSESS`) |
| Authorization | Authorization header **Any**; no value |
| AccountAdminPaths | Families `user`, `admin` |
| RuntimePaths | Families `batch`, `cron` |
| AjaxPrivatePaths | Families `system/ajax`, `system/files` |
| OEmbedPaths | Family `media/oembed` |
| EditPaths | Family `edit` |
| AddDeletePaths | Families `add`, `delete` |
| RevisionPaths | Families `revision`, `revisions` |
| PreviewRevertPaths | Families `preview`, `revert` |

- [ ] **F3 — STOP and route inventory.** Required security-header/redirect/origin actions execute **before** stopping bypasses or at the origin. STOP also skips later associated rule sets. Cache-enabling rules follow bypasses. Inventory site-specific private routes/aliases; this matrix is not access control. Check safe root/nested/language/trailing-slash/case variants and near misses such as synthetic `/editorial` separately. Do not invoke cron/delete/revert operations just to test matching. [Acceptance matrix](../frontdoor-setup-guide.md#qa-acceptance-matrix).
- [ ] **F4 — Static policy.** Directory prefixes `core/`, `themes/`, `modules/`, `libraries/`, `sites/default/files/` **AND** extensions `css js png jpg jpeg gif svg ico webp woff woff2 ttf`; lowercase both. Cache enabled, Use Query String, compression enabled, edge override **1 day**. Verify real CSS/JS/images/library/font content, MIME types and meaningful query variants. Browser freshness is separate: an edge TTL override does not itself add browser Cache-Control. [Static files](../frontdoor-setup-guide.md#static-files).
- [ ] **F5 — Public video if used.** Separate recommended `PublicVideo`: path prefix `sites/default/files/video/` AND extension `mp4`, lowercase, query strings retained, edge override 1 day, compression disabled. Test valid range GET → `206`, correct `Content-Range` and bytes, and replacement plus purge. X-Cache HIT for a large file describes the first 8-MB chunk, not the entire video. This rule requires actual Azure QA validation. [Video](../frontdoor-setup-guide.md#public-mp4-video).
- [ ] **F6 — Documents.** PDF/Office responses retain `Cache-Control: no-cache`; confirm no edge HIT, unchanged Last-Modified revalidation and replacement visibility. Prefer origin Apache policy; fallback `NoCacheDocs` explicitly disables caching **and** sets header, then STOP before static/video. Do not require ETag revalidation through Front Door. [Document policy](../frontdoor-setup-guide.md#pdfs-and-office-files-cache-control-no-cache).

### Header checks and their meaning

Use cookie-free **GET**, not `curl -I`/HEAD, against the canonical URL. Do not send `Cache-Control: no-cache` while trying to measure ordinary cache reuse. Do not follow redirects silently: first inspect their Location, then test the canonical page.

```bash
# Set SITE to the environment you intend to test. No credentials/cookies here.
curl -sS -o /dev/null -D - "$SITE/about-us" \
  | grep -iE '^(HTTP/|cache-control:|x-cache:|x-drupal-cache:|x-drupal-dynamic-cache:|age:|vary:|location:|content-type:|content-encoding:|last-modified:)'
```

| Response under test | Expected evidence | Interpretation / failure path |
|---|---|---|
| Intended public HTML at origin | Correct content/status; `public`, intended `max-age=900`; repeated origin-reaching requests demonstrate Drupal reuse where eligible | `private`, `no-store` or unexpected UNCACHEABLE requires Drupal response-policy investigation, not forced edge caching |
| Same public HTML through Front Door | Eventual `TCP_HIT` or `TCP_REMOTE_HIT` with correct content and policy | A first HIT is valid; additional MISS can occur at another POP. Do not require exactly first MISS/second HIT. `CONFIG_NOCACHE` is configured bypass; `PRIVATE_NOSTORE` reports origin policy, not its cause |
| Shared edge HIT with `X-Drupal-Cache: MISS` | Correct body plus edge HIT | The Drupal header may be stored from the cache-fill response; it does not prove Drupal currently misses |
| Session/auth/action/private response | No shared personalized HIT; correct access behavior; configured bypass expected `CONFIG_NOCACHE` | `PRIVATE_NOSTORE` alone does not prove a path rule matched. Test cookie and Authorization bypass independently, then the anonymous control |
| Public static file | Correct MIME/bytes, eventual edge HIT; intended browser freshness separately recorded | Compression on eligible text; no requirement to compress tiny/already compressed assets |
| PDF/Office | `no-cache`, no edge HIT; unchanged revalidation and changed bytes after replacement | `no-cache` allows storage with revalidation; it is not synonymous with `no-store` |
| Missing URL / redirect | Deliberate status, target and cache policy recorded | Do not assume all redirects or 4xx responses are uncacheable |

- [ ] **F7 — Record the header matrix.** Test homepage and inner pages, real logged-in test account, logout, forms/AJAX/oEmbed/private files, and anonymous negative controls. Synthetic nonsecret cookie/Authorization probes supplement a real authenticated account; they do not certify personalized isolation. Set-Cookie alone is not a safe caching guard: Front Door may remove it from cacheable responses. Keep sensitive captures restricted.
- [ ] **F8 — Security/compression headers.** Agreed HSTS/CSP/framing/content-type policy appears on both cache HIT and bypass responses. Validate embeds, forms and third-party resources; use the approved policy, not an invented strict CSP or unreviewed HSTS subdomain scope. Eligible text compression works. [Compression](../frontdoor-setup-guide.md#9-compression); [hardening](../hardening.md).

## 6. Origin restrictions, client IP, WAF and purge

- [ ] **O1 — Origin lock.** Same App Service allow rule requires both `AzureFrontDoor.Backend` service tag **and this profile's** `X-Azure-FDID`; unmatched deny and no alternate broad allow. Approved QA tests: Front Door hostname serves intended content, direct origin refused, forged FDID from outside the service tag still refused. Inspect inherited/alternate host exceptions. Review SCM independently and verify portal SSH/Drush and actual deployment webhook still work. Do not copy main-site restrictions blindly to SCM. [Origin lock and SCM](../frontdoor-setup-guide.md#5-lock-the-origin-to-this-front-door).
- [ ] **O2 — Trusted proxies.** Drupal sees correct public HTTPS/hostname and real client IP from trusted actual peers. On OIG retain existing `REVERSE_PROXY_ENABLED`, `REVERSE_PROXY_ADDRESSES`, `HOST_NAME`; leave duplicate recipe `DRUPAL_REVERSE_PROXY*` settings unset. Do not trust arbitrary forwarded headers or X-Forwarded-Port. [Client-IP test](../azure.md#client-ip).
- [ ] **O3 — WAF.** Verify real SKU/Government feature availability, attached policy, enabled logs and configured mode/action. Use a safe **rule-specific QA test** and correlate TrackingReference/log/action. One SQL-looking query or HTTP 200/403 alone does not prove a match. Review editor/upload/login/AJAX/asset/monitor false positives before enforcement; many-IP traffic is not solved by per-IP rate limits alone. [WAF troubleshooting](../frontdoor-setup-guide.md#7-waf).
- [ ] **P1 — Government purge target.** Effective runtime settings contain the correct `AZURE_CLOUD=usgovernment`, tenant/client/secret presence, subscription, resource group, profile, endpoint **name**. Do not print values/secrets indiscriminately. Endpoint type `afdEndpoints`, API `2024-02-01`, correct patched purger and Government host defaults; leave manual login/management/scope overrides unset unless explicitly needed. Own-environment least-privilege purge identity; secrets resolved through Key Vault. [Variables and troubleshooting](../quickstart-azure-frontdoor.md#whats-needed-one-copy-per-site-per-environment).
- [ ] **P2 — Diagnostic scope.** `drush perf:diagnose --frontdoor` success verifies its token/read checks only. It does **not** issue a purge or prove edge caching. Read-endpoint 403 may be inconclusive for purge-only credentials: test the authorized purge action before changing RBAC. [Front Door diagnostics](../troubleshooting-commands.md#azure-front-door-configuration-debugging).
- [ ] **P3 — End-to-end content purge.** In QA, warm a clearly labelled synthetic published test page, record content, edit/save, inspect `drush p:queue-volume` and `drush p:diagnostics`, run the approved purge worker, confirm no new Azure errors and new content through the edge within the agreed SLA (allow documented Azure propagation). `drush p:queue-work --finish` **sends queued purges**; use it in the controlled workflow, not as a read-only diagnostic. Empty queue alone is insufficient. [Procedure](../frontdoor-setup-guide.md#10-purge-from-drupal).
- [ ] **P4 — Dependencies and files.** Verify canonical/alias rename/retarget/delete, relevant list pages, menus/blocks, image/style and video replacement, and PDF replacement. Default list purges `/,/node,/rss.xml`; no automatic all-site menu/block purge. Configure critical dependencies or record accepted 900-second stale windows. New alias after cached 404 can retain the default 3600-second 4xx window. Browser-fresh copies cannot be removed by an edge purge; record actual browser TTL impact. [Known gaps](../cache-sync.md#defaults-for-the-known-gaps).
- [ ] **P5 — Scheduler.** One owned external cron schedule, no overlapping workers; disable Drupal `automated_cron` when external scheduling is selected. Purge processing every 1–5 minutes, with full cron/maintenance separately accounted for. Verify actual runs, queue age/drain and failures; do not clear a stuck queue to make the dashboard green. [Recommended settings](../cache-sync.md#recommended-settings).

## 7. Operations, performance and final acceptance

- [ ] **S1 — Production smoke evidence.** Repeat safe settings/web/header/auth-isolation/purge checks on production's actual targets after deployment. QA outage tests are not repeated automatically in production. Verify expected site content and logged-in workflows, not headers alone.
- [ ] **S2 — Representative capacity evidence.** With an agreed QA traffic budget, measure warm/cold latency, origin request rate, 5xx, Redis memory/evictions/hits/misses/restarts, DB/cache-table pressure, PHP workers and purge queue age. Agree thresholds and an owner. Functional success does not certify a 20k-IP/hour burst, especially with many unique URLs/cache variants. No load test is implied by the small header suite.
- [ ] **S3 — Asset budget.** Review real page request count/transfer, image derivatives/responsive sizing/lazy loading and video loading. Save a new browser/network trace; historical homepage findings are not a current measurement. Cache configuration alone does not reduce oversized first-visit transfers. [Prior site findings](../uspsoig-cache-bot-check.md).
- [ ] **S4 — Alerts and rollback.** Alert on origin errors/latency, Redis unavailability/restarts/memory, queue age/purge failures and WAF anomalies. Record rollback artifact, responsible operator and tested Redis DB-mode procedure. Disable detailed debug flags and production Xdebug; preserve only approved restricted evidence. [Operations](../setup-guide.md#phase-5-operate).
- [ ] **S5 — Sign-off.** Every required row has environment-specific evidence or an explicitly accepted exception with owner/date. No unresolved private-content caching, wrong environment target, unexpected public-page cache denial, failed invalidation, web Redis failure or missing recovery evidence. Record application owner, Azure owner and acceptance date. A green partial automated report does not close untested manual gates.

## 8. Troubleshooting map

| Symptom | Start here / next evidence |
|---|---|
| CLI works; Apache Redis not connected or `getaddrinfo` fails | [Web diagnostics](../troubleshooting-commands.md#azure-drush-works-but-web-requests-do-not-use-redis): compare actual includes, ENV metadata/effective host/port, SAPI and DNS stages before changing permissions/INI |
| `redis.factory` missing | [Settings loaded](../troubleshooting-commands.md#2-settings-are-loaded): Redis activation prerequisites, enabled flag, contrib path, extension and service discovery |
| No diagnostics table on existing Redis report | [Marker/revision/debug-state checks](../troubleshooting-commands.md#azure-drush-works-but-web-requests-do-not-use-redis); updating an unused settings copy cannot change web runtime |
| Public inner page UNCACHEABLE | [Header reasons](../troubleshooting-commands.md#3-pages-are-not-cached-headers_anon-fails); inspect first `ResponsePolicyInterface::DENY`, policy class and kill-switch call stack. Test language negotiation rather than guessing from max-age |
| Redis-unavailable logs continue | [Redis checks](../troubleshooting-commands.md#4-redis): actual web connection/cooldown/APCu, then recovery state; don't uninstall decorator |
| Front Door CONFIG_NOCACHE / PRIVATE_NOSTORE / perpetual MISS | [Route troubleshooting](../frontdoor-setup-guide.md#1-route-caching), [bypass order and matching](../frontdoor-setup-guide.md#3-bypass-the-cache-for-logged-in-users-and-admin-paths); inspect origin policy separately |
| Wrong static/PDF/video behavior | [Static, video and document policies](../frontdoor-setup-guide.md#4-static-files-and-keeping-pdfs-uncached): rule AND/OR/order, origin headers, range bytes and browser freshness |
| 403, 502/503, SSH/deploy failure after origin lock | [Origin and independent SCM rules](../frontdoor-setup-guide.md#5-lock-the-origin-to-this-front-door); inspect actual origin status and access restrictions |
| OAuth/purge error or stale content | [Government/identity errors](../quickstart-azure-frontdoor.md#if-something-goes-wrong), [queue/purge workflow](../frontdoor-setup-guide.md#10-purge-from-drupal), [dependency gaps](../cache-sync.md#defaults-for-the-known-gaps) |
| Wrong client IP, redirect scheme or host | [Proxy-trust checks](../azure.md#client-ip); inspect actual trusted peer/headers and existing OIG bootstrap |
| WAF blocks editors or doesn't log | [WAF logs and exclusions](../frontdoor-setup-guide.md#7-waf): correlate the configured rule/action with request TrackingReference |

Source baseline: [recipe](../../recipe.yml), [settings](../../drupal-performance-modules/settings/settings.performance.php), [Redis Azure template](../../redis/azure/redis-containerapp.bicep), [Front Door guide and linked Microsoft sources](../frontdoor-setup-guide.md#sources). Check deployed values instead of assuming these defaults were applied.
