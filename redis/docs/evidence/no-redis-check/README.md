# Check: the sites without Redis (`REDIS_HOST` unset)

Run in the local DDEV lab; no Azure involved. `result.txt` line 1 has the date, the file loaded and the PHP version; `result-site.txt` lines 1 and 9 have the date, Drupal and PHP versions.

- `check-settings.php` loads this repo's `drupal-performance-modules/settings/settings.performance.php` the way `settings.php` does. The lab mounts this repo read-only at `/opt/perf-recipe-src`, and `result.txt` line 1 prints the file it loaded. It runs first with `REDIS_HOST` unset, then set. It prints which cache bins point at Redis.
  - Result (`result.txt`):
    - Unset: no `redis.connection` and no bins on Redis.
    - Set: the bins `render,page,dynamic_page_cache,data,entity,menu` are on Redis.
  - Code: [Redis activation guard in settings.performance.php](../../../../drupal-performance-modules/settings/settings.performance.php). Redis is configured only when `REDIS_HOST` is set, the phpredis extension is loaded, the redis module directory exists and Drupal isn't being installed.
- `bins.php`, run with `drush php:script` on the lab site, prints the cache class of those six bins (`result-site.txt`):
  - With `REDIS_HOST` unset, all six are `Drupal\Core\Cache\DatabaseBackend`.
  - With `REDIS_HOST=redis`, all six are the recipe's `Drupal\perf_redis_failopen\Cache\FailOpenCacheBackend`.
  - The lab site's own copy of the settings file is older than this repo's. Its Redis condition is the same four checks.
- Drupal core's fallback when no cache backend is set: "fall back to the global default of 'cache.backend.database'" (core `lib/Drupal/Core/Cache/CacheFactory.php:27,108`, 10.6.17).

Re-run: copy `check-settings.php` and `bins.php` into the Drupal project root, then inside the site's web container, from that root:

```bash
php check-settings.php <path to settings.performance.php>
env -u REDIS_HOST drush php:script bins.php
drush php:script bins.php
```
