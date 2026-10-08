#!/usr/bin/env bash
# Plain-text report to save and show the team: what runs locally, what deploys
# to production, and whether the caching chain works. Runs INSIDE the app
# container from the project root, like test-local.sh. Lando:
#   lando perf-report > perf-report.txt   config redis edge purges (read-only; or name sections)
#   lando perf-report prove               short test-local.sh run (SYNTHETIC node, resets the mock log)
# Lines start with PASS, FAIL or INFO; exit code = number of FAIL lines.
# Never prints a secret: *SECRET* and *PASSWORD* values show as "set (hidden)".
set -uo pipefail

# Optional settings file, same as test-local.sh (the environment wins).
if [[ -f "${PERF_TEST_ENV:-perf-test.env}" ]]; then
  while IFS= read -r kv || [[ -n "$kv" ]]; do
    kv="${kv%$'\r'}"; key="${kv%%=*}"
    [[ "$kv" == *=* && "$key" =~ ^[A-Z_][A-Z0-9_]*$ && -z "${!key+x}" ]] && export "$key=${kv#*=}"
  done < "${PERF_TEST_ENV:-perf-test.env}"
fi
DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
DRUSH="${DRUSH:-vendor/bin/drush}"
TEST_PATH="${PERF_TEST_PATH:-/}"
EDGE="${PERF_EDGE_URL:-http://edge:8080}"
EDGE_CTL="${PERF_EDGE_CONTROL:-http://edge:8099}"
MOCK="${PERF_MOCK_URL:-http://mock-afd:8080}"
say() { printf '%s %s: %s\n' "$1" "$2" "$3"; }
php_eval() { $DRUSH php:eval "$1" 2>&1; }
git_() { git -c safe.directory='*' "$@" 2>/dev/null; }
xcache() { curl -s -o /dev/null -D - -m 10 "$1" | tr -d '\r' | awk 'NR==1{s=$2} tolower($1)=="x-cache:"{x=$2} END{print (s ? s " " x : "no answer")}'; }

header() {
  echo "# perf-report $(date -u '+%F %T') UTC"
  # get_env_name(): OIG's settings.base.php (Lando = local even with ENVIRONMENT unset).
  php_eval '$s = \Drupal::moduleHandler()->moduleExists("config_split") ? array_keys(array_filter(\Drupal::entityTypeManager()->getStorage("config_split")->loadMultiple(), fn($x) => $x->status())) : [];
    printf("# site: %s | Drupal %s | environment: %s | config splits on: %s\n", \Drupal::config("system.site")->get("name"), \Drupal::VERSION, function_exists("get_env_name") ? get_env_name() : (getenv("ENVIRONMENT") ?: "(not set)"), implode(",", $s) ?: "none");'
  echo "# git: $(git_ branch --show-current) $(git_ log -1 --format='%h %cs %s'); uncommitted files: $(git_ status --porcelain | wc -l | tr -d ' ')"
}

s_config() {
  echo "# LOCAL: cache mode, bins and front page headers (perf-cache.sh status)"
  bash "$DIR/perf-cache.sh" status 2>&1 | sed 's/^/  /'
  echo "# Recipe config: LOCAL (in use now) vs PRODUCTION (config/sync, what deploys)"
  echo "# PRODUCTION here = config/sync only. A prod config split or settings.prod.php \$config line would change it: check those before quoting a production value."
  php_eval "$(cat <<'PHP'
// Drift uses the same view as drush config:status: active config after config splits.
[$sync, $export] = [\Drupal::service("config.storage.sync"), \Drupal::service("config.storage.export")];
$show = fn($v) => is_array($v) ? (implode(",", array_map(fn($p) => $p["plugin_id"] ?? "?", array_filter($v, fn($p) => $p["status"] ?? TRUE))) ?: "(none)")
  : ($v === NULL ? "(none)" : (is_string($v) ? $v : var_export($v, TRUE)));
$at = function ($data, $k) { $data = $data ?: []; return \Drupal\Component\Utility\NestedArray::getValue($data, explode(".", $k)); };
foreach ([
  "system.performance" => ["cache.page.max_age", "css.preprocess", "js.preprocess", "fast_404.enabled", "fast_404.paths"],
  "purge.plugins" => ["purgers", "queuers", "processors", "queue"],
  "azure_cdn_purge.settings" => ["endpoint_type", "azure_environment", "api_version"],
  "perf_purge_paths.settings" => ["enabled"],
] as $name => $keys) {
  $live = \Drupal::config($name);
  foreach ($keys as $k) {
    [$l, $p] = [$show($live->get($k)), $show($at($sync->read($name), $k))];
    if ($l === "(none)") { [$m, $why] = ["FAIL", "not set: is the recipe applied?"]; }
    elseif ($l === $p) { [$m, $why] = ["PASS", "same"]; }
    elseif ($show($at($export->read($name), $k)) !== $p) { [$m, $why] = ["FAIL", "DRIFT: drush cex to export it, or drush cim to import config/sync"]; }
    else { [$m, $why] = ["INFO", $live->hasOverrides($k) ? "set in settings.php, not in config" : "local only (config split); production uses config/sync"]; }
    printf("%s config %s %s: local=%s production=%s (%s)\n", $m, $name, $k, $l, $p, $why);
  }
}
PHP
)"
  local doc="$DIR/../docs/azure.md" names ex n v
  echo "# Environment: LOCAL value | PRODUCTION App Service setting example (docs/azure.md)"
  [[ -f "$doc" ]] || { say INFO env "docs/azure.md not found next to scripts/"; return; }
  awk '/^## App Service application settings/{f=1; next} /^## /{f=0} f && /^\| `[A-Z]/' "$doc" |
    while IFS='|' read -r _ names ex _; do
      for n in $(grep -o '[A-Z][A-Z0-9_]*' <<<"$names"); do
        if [[ -z "${!n:-}" ]]; then v="(not set)"; elif [[ "$n" =~ SECRET|PASSWORD ]]; then v="set (hidden)"; else v="${!n}"; fi
        printf '  %-31s %-37s %s\n' "$n" "$v" "$(tr -d '`' <<<"$ex" | sed 's/^ *//; s/ *$//')"
      done
    done
  echo "# Lab only (settings.local-lab.php): production must NOT set these"
  for n in PERF_MOCK_AFD_PROXY PERF_EDGE_PROXY_HOST PERF_CACHE_MODE; do printf '  %-31s %s\n' "$n" "${!n:-(not set)}"; done
}

s_redis() {
  echo "# Each link Drupal needs to use Redis (redis-check.php; fixes: troubleshooting-commands.md 4-C)"
  $DRUSH php:script "$DIR/redis-check.php" 2>&1 | sed 's/^/  /'
  php_eval "$(cat <<'PHP'
[$cls, $pfx] = [get_class(\Drupal::cache("render")), (string) getenv("REDIS_PREFIX")];
try {
  $r = \Drupal\redis\ClientFactory::getClient();
  $n = 0; $it = NULL;
  while (($keys = $r->scan($it, $pfx === "" ? "*" : "$pfx:*", 1000)) !== FALSE) { $n += count($keys); }
  [$st, $mem] = [$r->info("stats"), $r->info("memory")];
  [$h, $mi] = [(int) $st["keyspace_hits"], (int) $st["keyspace_misses"]];
  printf("INFO redis: server-wide hits %d, misses %d (%d%% hits), evicted %d; memory %s of %s; policy %s\n", $h, $mi, $h + $mi ? 100 * $h / ($h + $mi) : 0, $st["evicted_keys"], $mem["used_memory_human"], $mem["maxmemory_human"], $r->config("GET", "maxmemory-policy")["maxmemory-policy"] ?? "?");
  printf("%s redis: render bin %s; %d keys under prefix \"%s\"\n", preg_match("/FailOpen|Redis/", $cls) ? "PASS" : "FAIL", $cls, $n, $pfx);
}
catch (\Throwable $e) {
  printf("FAIL redis: Drupal cannot use Redis (%s); render bin %s\n", $e->getMessage(), $cls);
}
PHP
)"
}

s_edge() {
  [[ "${PERF_EDGE:-1}" == 1 ]] || { say INFO edge "skipped (PERF_EDGE=0: no edge service)"; return; }
  local a b
  a=$(xcache "$EDGE$TEST_PATH"); b=$(xcache "$EDGE$TEST_PATH")
  if [[ "$b" == "200 TCP_HIT" ]]; then say PASS edge "$TEST_PATH through $EDGE: $a, then $b (served from the edge cache)"
  else say FAIL edge "$TEST_PATH through $EDGE: $a, then $b (want 200 TCP_HIT; docs/edge-waf.md)"; fi
  echo "# Last 10 edge requests (xcache = cache result, key = cache key). Keys cached now: lando perf-edge-keys"
  curl -s -m 5 "$EDGE_CTL/__edge/log/access" | grep -v '"GET /__edge/' | tail -n 10 | sed 's/ ref=.*//; s/^/  /'
}

s_purges() {
  php_eval 'use Drupal\Core\Site\Settings;
    $p = Settings::get("http_client_config")["proxy"]["https"] ?? ""; $ok = $p !== "" && $p === getenv("PERF_MOCK_AFD_PROXY");
    printf("%s purges: Drupal sends Azure calls to %s\n", $ok ? "PASS" : "FAIL", $ok ? "the mock Front Door ($p)" : ($p ?: "the real Microsoft hosts (no mock proxy: quick start step 4)"));
    printf("INFO purges: %d items in the purge queue (drush p:queue-work sends them now)\n", \Drupal::service("purge.queue")->numberOfItems());'
  local log
  if ! log=$(curl -sf -m 5 "$MOCK/__mock/requests"); then say FAIL purges "mock Front Door not reachable at $MOCK"; return; fi
  printf '%s\n' "$log" | php -r '
    $rows = array_values(array_filter(array_map(fn($l) => json_decode($l, TRUE), file("php://stdin")), fn($r) => in_array($r["kind"] ?? "", ["purge", "edge_purge"], TRUE)));
    $c = array_count_values(array_column($rows, "kind"));
    printf("INFO purges: mock log has %d purge requests and %d edge purges%s\n", $c["purge"] ?? 0, $c["edge_purge"] ?? 0, $rows ? "; last 10 (time, kind, status, paths):" : " (save a node, then drush p:queue-work)");
    foreach (array_slice($rows, -10) as $r) {
      printf("  %s %-10s %s %s\n", $r["ts"], $r["kind"], $r["status"], json_encode($r["content_paths"] ?? $r["purged"] ?? $r["error"] ?? NULL, JSON_UNESCAPED_SLASHES));
    }'
}

s_prove() {
  local t=(headers_anon core_404_static core_404_page redis_in_use purge_send)
  [[ "${PERF_EDGE:-1}" == 1 ]] && t+=(edge_purge_e2e)
  echo "# Short test run: test-local.sh ${t[*]}"
  bash "$DIR/test-local.sh" "${t[@]}"
}

main() {
  header
  [[ $# -eq 0 ]] && set -- config redis edge purges
  local s
  for s in "$@"; do
    printf '\n== %s\n' "$s"
    case "$s" in config|redis|edge|purges|prove) "s_$s" ;; *) say FAIL "$s" "unknown section (config, redis, edge, purges, prove)" ;; esac
  done
}

OUT=$(mktemp)
main "$@" 2>&1 | tee "$OUT"
FAILS=$(grep -c '^FAIL' "$OUT")
printf '\n# perf-report: %s PASS, %s FAIL\n' "$(grep -c '^PASS' "$OUT")" "$FAILS"
rm -f "$OUT"
exit "$FAILS"
