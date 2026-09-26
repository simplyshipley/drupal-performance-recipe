# Cache modes for local testing

Local environments usually turn Drupal's caches off. The usual tools are `settings.local.php`
null backends, `sites/development.services.yml`, Twig debug and aggregation off. That is right for
theming, but it makes cache, Redis, edge and purge tests meaningless.

The recipe adds a switch, so the same site can run as **dev** while you build and as
**prod-like** while you test.

## Modes

| | `dev` | `dev-twig` | `prod-like` | `off` / unset |
|---|---|---|---|---|
| `render`, `page`, `dynamic_page_cache` bins | `cache.backend.null` | normal (Redis if `REDIS_HOST`) | normal (Redis if `REDIS_HOST`). Null backends from `settings.local.php` are removed. | whatever the site settings say |
| Twig | debug on, auto_reload on, no Twig cache | debug on, auto_reload on, no Twig cache | debug off, cache on (core defaults) | site settings |
| `sites/development.services.yml` | removed from `container_yamls` | removed | removed | loaded if the site adds it |
| Cacheability debug headers | on | on | off | site settings |
| CSS/JS aggregation (`$config` override, not a config write) | off | site config | on | site config |
| Page max-age (`$config` override) | 0 | site config | site config (recipe: 900) | site config |
| core "Development settings" (key_value `development_settings`) | all three on | Twig debug + Twig cache off | all off | all off |
| Redis / edge required | no | no | used if configured | – |

Page max-age is not forced in `prod-like`, so that the edge/HTML TTL tests (and you) can still
change it.

## Commands

The commands are the same in every environment.

| Environment | Command |
|---|---|
| DDEV | `ddev perf-cache dev|dev-twig|prod-like|off|status|clear` (copy `ddev/commands/web/perf-cache` to `.ddev/commands/web/`) |
| Lando | `lando perf-cache …` (tooling in `lando/lando.perf.yml`, then `lando rebuild`) |
| Anywhere | `scripts/perf-cache.sh …`, run in the web container from the project root |

Switching a mode:
1. Writes the mode to `<site dir>/perf-cache-mode`, e.g. `web/sites/default/perf-cache-mode`. **Add it to `.gitignore`** (snippet below).
2. Sets core's `development_settings` key_value to match, so the admin page at `/admin/config/development/settings` never contradicts the mode.
3. Runs `drush cr`. This is needed because container YAMLs change.
4. Deletes the Redis keys under `REDIS_PREFIX`. It refuses to do this without a prefix, so a shared Redis is never flushed.
5. Purges the local edge (`/*`) when `PERF_EDGE_CONTROL` is set.

`clear` does steps 3–5 only.

`status` prints the **effective** state, read from Drupal:
- the backend class per bin;
- the Twig parameters and the live Twig service;
- aggregation and page max-age as Drupal sees them (overrides included);
- the core development settings, and whether development.services.yml is loaded;
- whether Redis is in use;
- the front page's cache headers, Twig debug comment count and aggregated CSS link count;
- whether the edge is reachable.

`PERF_CACHE_MODE` (environment) sets a default mode. A non-empty `perf-cache-mode` file wins over
it, so the command always works. `off` writes an empty file.

## Include order (why it wins)

`settings.performance.php` must be the **last** include in `settings.php`, after
`settings.ddev.php`, `settings.local.php` and any `settings.ddev.redis.php`. Its cache-mode block
is at the very end of the file. So:

- **Null backends:** `$settings['cache']['bins'][...] = 'cache.backend.null'` set in `settings.local.php` is replaced (`dev`) or removed (`prod-like`).
- **development.services.yml:** it is taken out of `$settings['container_yamls']`, and the mode's own YAML is appended last. The files are `settings/cache-mode.debug.services.yml` and `cache-mode.prod.services.yml`. Container parameters from later YAMLs win, so `twig.config` is ours even if `services.yml` enables Twig debug.
- **Core's Development settings page:** its compiler pass runs after the YAML files. That is why the command also writes its key_value.
  - If someone ticks the checkboxes afterwards, Twig debug comes back in `prod-like`.
  - `status` shows the conflict on the `core development_settings` line. Rerun `perf-cache prod-like` to fix it.
- **Aggregation / max-age:** these use `$config` overrides, so no config is written and nothing appears in `drush cex`.

Verified in the DDEV lab with the example `settings.local.php` enabled:
- null backends for render/page/dynamic_page_cache;
- development.services.yml;
- aggregation off.

`prod-like` gave FailOpen (Redis) bins, Twig debug off, aggregation on and no development.services.yml. `dev-twig` gave Twig debug with caches on [V: `ddev perf-cache status` output, 2026-09-26].

## Guard and .gitignore

`dev` and `dev-twig` are honoured **only** inside DDEV or Lando, where `IS_DDEV_PROJECT=true` or `LANDO=ON`. Anywhere else they are refused:
- `error_log` records the refusal;
- `perf-cache status` prints a `REFUSED` warning;
- the site's own settings apply, the same as `off`.

`prod-like` is allowed anywhere. The guard is the real protection, but keep the file out of Git too. Add this to the site's root `.gitignore`:

```
# drupal-performance-recipe cache mode switch (local only)
web/sites/*/perf-cache-mode
```

Test `cache_mode_guard` sets `dev`, runs drush with `PERF_CACHE_GUARD_SIMULATE_REMOTE=1` (acts as outside DDEV/Lando; it can only refuse, never allow — unsetting `IS_DDEV_PROJECT` would also drop DDEV's database settings), and expects the mode refused and the render bin not null. Fail-proof: `CACHE_GUARD_NEGATIVE=1` keeps the markers and FAILS [V: tmp/ddev/run13-guard-failproof.log].

## When to use which

- **`dev`:** theming and site building, where every change must show immediately.
- **`dev-twig`:** debugging templates while keeping cache behaviour realistic (cache tags, contexts, performance).
- **`prod-like`:** anything about performance, like the test suite, the edge/WAF, Redis, purge and header checks.
- **`off`:** the site's own settings only.

## Tests

- **`cache_mode_dev`:**
  - It switches to `dev` and requests a node twice. It expects the render bin to be `NullBackend`, the second response not `X-Drupal-Cache: HIT`, `Cache-Control` no-cache, Twig debug comments present and no aggregated CSS.
  - It then switches back to `prod-like`.
- **`cache_mode_prod`:** the opposite checks: not NullBackend, `HIT`, `public`, no Twig comments, aggregated CSS present.
- **Fail-proof:** `CACHE_MODE_NEGATIVE=1` switches each test to the opposite mode, and both FAIL.
- **Suite mode handling:**
  - The suite (`test-local.sh`) switches to `prod-like` at start and restores the previous mode at exit, including on a failure exit.
  - The host wrappers (`ddev-test.sh`, `lando-test.sh`) do this once around the whole run, including the Redis-down steps, and pass `PERF_CACHE_AUTO=0` to the inner runs.

## Pitfalls

- **Forgetting to switch back.** `dev` stays on until you change it. `ddev perf-cache status` tells you which mode is active. The test wrappers restore your mode, but a crash of the host shell (not the script) can leave `prod-like` on.
- **With Redis configured, `off` is not "dev".** `settings.performance.php` puts the heavy bins on Redis after `settings.local.php`, which overrides its null backends. Use `dev` if you want no caches.
- **In `dev`, `X-Drupal-Cache` is `MISS` on every request, not absent.** The page cache middleware still runs, but its bin is a null backend.
- **Changing modes needs a container rebuild.** The command does it. If you set `PERF_CACHE_MODE` in the environment instead, run `drush cr` yourself.
- **Never deploy a `perf-cache-mode` file.** In production, leave `PERF_CACHE_MODE` unset; the block is then a no-op.
