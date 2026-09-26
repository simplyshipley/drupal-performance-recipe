#!/usr/bin/env bash
# Switch Drupal's cache mode for local testing. Runs INSIDE the web/app
# container from the project root (the folder with composer.json and vendor/).
#
#   scripts/perf-cache.sh dev        render/page/dynamic caches off (null),
#                                    Twig debug, aggregation off, max-age 0
#   scripts/perf-cache.sh dev-twig   caches on, Twig debug on
#   scripts/perf-cache.sh prod-like  what production runs
#   scripts/perf-cache.sh off        remove the switch (site settings decide)
#   scripts/perf-cache.sh status     effective state, read from Drupal itself
#   scripts/perf-cache.sh clear      clear caches only (drush cr, Redis prefix,
#                                    local edge)
#
# The mode is written to <site dir>/perf-cache-mode, which
# settings.performance.php reads (it wins over PERF_CACHE_MODE). Core's
# "Development settings" page (/admin/config/development/settings) stores
# its Twig/cache toggles in key_value "development_settings"; this script sets
# them to match the mode so the admin page and the mode never disagree.
# See docs/cache-modes.md.
set -uo pipefail

DRUSH="${DRUSH:-vendor/bin/drush}"
EDGE_CTL="${PERF_EDGE_CONTROL:-}"

site_dir() {
  $DRUSH php:eval 'print \Drupal::root() . "/" . \Drupal::getContainer()->getParameter("site.path");' 2>/dev/null | tail -1
}

current_mode() {
  local f; f="$(site_dir)/perf-cache-mode"
  if [[ -s "$f" ]]; then tr -d '[:space:]' < "$f"; else printf '%s' "${PERF_CACHE_MODE:-}"; fi
}

clear_caches() {
  echo "# drush cr"
  $DRUSH cr 2>&1 | tail -1
  if [[ -n "${REDIS_HOST:-}" ]]; then
    local n
    n=$(php -r '
      $r = new Redis();
      $h = getenv("REDIS_HOST"); if (strtolower((string) getenv("REDIS_SCHEME")) === "tls") { $h = "tls://" . $h; }
      try { $r->connect($h, (int) (getenv("REDIS_PORT") ?: 6379), 2.0); } catch (Throwable $e) { echo "unreachable"; exit; }
      if (getenv("REDIS_PASSWORD")) { $r->auth(getenv("REDIS_PASSWORD")); }
      if (getenv("REDIS_DB") !== false && getenv("REDIS_DB") !== "") { $r->select((int) getenv("REDIS_DB")); }
      $p = (string) getenv("REDIS_PREFIX");
      if ($p === "") { echo "skipped (no REDIS_PREFIX, will not flush a shared Redis)"; exit; }
      $n = 0; $it = null;
      while (($keys = $r->scan($it, $p . ":*", 1000)) !== false) { if ($keys) { $n += $r->del($keys); } }
      echo $n;' 2>&1)
    echo "# Redis keys deleted with prefix ${REDIS_PREFIX:-}: $n"
  fi
  if [[ -n "$EDGE_CTL" ]]; then
    local out
    if out=$(curl -sf -m 5 -X POST -H 'Content-Type: application/json' -d '{"contentPaths":["/*"]}' "$EDGE_CTL/__edge/purge" 2>/dev/null); then
      echo "# local edge purged (/*): $(printf '%s' "$out" | grep -o '"[^"]*:[0-9]*/[^"]*"' | wc -l | tr -d ' ') objects"
    else
      echo "# local edge not reachable at $EDGE_CTL (skipped)"
    fi
  fi
}

set_mode() {
  local mode="$1" dir twig=0 nocache=0 bins=0
  dir="$(site_dir)"
  if [[ -z "$dir" || ! -d "$dir" ]]; then echo "perf-cache: cannot find the site directory (is Drupal installed?)" >&2; exit 1; fi
  case "$mode" in
    dev) twig=1; nocache=1; bins=1 ;;
    dev-twig) twig=1; nocache=1 ;;
    prod-like|off) ;;
  esac
  if [[ "$mode" == off ]]; then : > "$dir/perf-cache-mode"; else printf '%s\n' "$mode" > "$dir/perf-cache-mode"; fi
  $DRUSH php:eval "\$kv = \Drupal::keyValue('development_settings'); \$kv->setMultiple(['twig_debug' => (bool) $twig, 'twig_cache_disable' => (bool) $nocache, 'disable_rendered_output_cache_bins' => (bool) $bins]);" >/dev/null 2>&1
  echo "# cache mode: ${mode} (file $dir/perf-cache-mode; core development_settings twig_debug=$twig twig_cache_disable=$nocache disable_rendered_output_cache_bins=$bins)"
  clear_caches
}

status() {
  local base="${BASE_URL:-http://localhost}"
  echo "# requested mode: '$(current_mode)' (file wins over PERF_CACHE_MODE='${PERF_CACHE_MODE:-}')"
  $DRUSH php:eval '
    use Drupal\Core\Site\Settings;
    $c = \Drupal::getContainer();
    printf("effective mode setting: %s\n", Settings::get("perf_cache_mode", "(none)"));
    if ($r = Settings::get("perf_cache_mode_refused")) { printf("WARNING: mode \"%s\" REFUSED (not DDEV/Lando); site settings apply\n", $r); }
    foreach (["render", "page", "dynamic_page_cache", "data", "entity", "config"] as $b) {
      $o = \Drupal::cache($b);
      $cls = (new \ReflectionClass($o))->getShortName();
      printf("bin %-19s %s\n", $b, $cls);
    }
    $t = $c->getParameter("twig.config");
    printf("twig debug=%s auto_reload=%s cache=%s\n", var_export($t["debug"], TRUE), var_export($t["auto_reload"], TRUE), var_export($t["cache"], TRUE));
    $tw = $c->get("twig");
    printf("twig service: isDebug=%s cache=%s\n", var_export($tw->isDebug(), TRUE), $tw->getCache() ? "on" : "off");
    $p = \Drupal::config("system.performance");
    printf("aggregation css=%s js=%s; page max-age=%s\n", var_export($p->get("css.preprocess"), TRUE), var_export($p->get("js.preprocess"), TRUE), $p->get("cache.page.max_age"));
    $kv = \Drupal::keyValue("development_settings")->getAll();
    printf("core development_settings: %s\n", $kv ? json_encode($kv) : "(empty)");
    $dev = array_filter(Settings::get("container_yamls", []), fn($y) => str_ends_with($y, "/development.services.yml"));
    printf("development.services.yml loaded: %s\n", $dev ? "yes" : "no");
    printf("redis in use: %s\n", str_contains(get_class(\Drupal::cache("render")), "Redis") || str_contains(get_class(\Drupal::cache("render")), "FailOpen") ? "yes (render bin)" : "no (render bin)");
  ' 2>&1
  local h
  h=$(curl -s -o /dev/null -D - "$base/" 2>/dev/null | tr -d '\r' | grep -iE '^(x-drupal-cache|x-drupal-dynamic-cache|cache-control):' | tr '\n' ' ')
  echo "front page headers: ${h:-none}"
  echo "front page: twig debug comments $(curl -s "$base/" | grep -c 'THEME DEBUG' || true), aggregated CSS links $(curl -s "$base/" | grep -c 'files/css/css_' || true)"
  if [[ -n "$EDGE_CTL" ]]; then
    if curl -s -m 3 -o /dev/null "$EDGE_CTL/__edge/health"; then echo "local edge: reachable ($EDGE_CTL)"; else echo "local edge: not reachable ($EDGE_CTL)"; fi
  fi
}

case "${1:-status}" in
  dev|dev-twig|prod-like|off) set_mode "$1" ;;
  status) status ;;
  clear) clear_caches ;;
  current) current_mode; echo ;;
  *) echo "usage: $0 dev|dev-twig|prod-like|off|status|clear" >&2; exit 2 ;;
esac
