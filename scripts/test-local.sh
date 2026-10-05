#!/usr/bin/env bash
# Performance recipe test suite. Runs INSIDE the web/app container from the
# project root (the folder with composer.json and vendor/).
#
#   scripts/test-local.sh [test ...]      run the named tests (default: all)
#   scripts/test-local.sh --list          list test names
#
# Environment:
#   BASE_URL        URL of the site as seen from this container
#                   (DDEV: http://localhost, Lando: http://appserver_nginx)
#   PERF_MOCK_URL   Mock Front Door control URL (default http://mock-afd:8080)
#   REDIS_HOST/REDIS_PORT/REDIS_PASSWORD/REDIS_PREFIX  same as the site uses
#   DRUSH           drush command (default vendor/bin/drush)
#
# Output: one line per check, "PASS|FAIL|INFO <test>: <detail>", then a
# summary. Exit code = number of FAIL lines (0 = all passed).
#
# The redis_down test is special: the Redis container must already be stopped
# (the ddev-test.sh / lando-test.sh wrappers do that from the host).
#
# All content this script creates is SYNTHETIC (titles start "SYNTHETIC").

set -uo pipefail

# LAB ONLY. This script creates SYNTHETIC nodes promoted to the front page,
# empties the purge queue, stops and starts Redis from its wrappers and runs a
# menu save with PERF_PURGE_SITEWIDE_PATHS=/* followed by queue-work, which is a
# real full purge. It ships in every deployment of the recipe, so it refuses to
# run anywhere that is not a DDEV or Lando environment (the same check the
# settings include uses for its lab switches). --list is harmless and exempt.
if [[ "${1:-}" != "--list" && "${IS_DDEV_PROJECT:-}" != "true" && "${LANDO:-}" != "ON" ]]; then
  echo "FAIL guard: test-local.sh is a lab test harness and only runs inside DDEV (IS_DDEV_PROJECT=true) or Lando (LANDO=ON). Refusing to run here." >&2
  exit 99
fi

BASE_URL="${BASE_URL:-http://localhost}"
MOCK="${PERF_MOCK_URL:-http://mock-afd:8080}"
DRUSH="${DRUSH:-vendor/bin/drush}"
PREFIX="${REDIS_PREFIX:-}"
FAILS=0
PASSES=0
ALL_TESTS=(redis_bins redis_in_use drush_cr tag_invalidation eviction purge_queue_fills purge_send purge_send_gov purge_retry
  fast404_static fast404_path themed_404 headers_anon headers_auth cache_4xx cache_mode_dev cache_mode_prod cache_mode_guard)
# Edge tests need the edge service (edge/). waf_rate_limit runs last: it
# exhausts this client's rate-limit buckets for about a minute.
# PERF_EDGE=0 skips them (site without the edge).
EDGE_TESTS=(edge_static_hit edge_querystring edge_bypass_session edge_html_ttl edge_purge_e2e
  waf_block_sqli waf_block_xss waf_blocked_paths waf_detection_mode origin_headers
  bot_challenge security_headers generator_removed origin_forwarded_port
  sync_alias sync_single_save sync_listing sync_alias_change sync_alias_rename sync_delete sync_alias_delete sync_alias_delete_404
  sync_publish_cycle sync_revert sync_new_path_404
  sync_other_entities sync_menu_block sync_file sync_file_delete sync_file_swap sync_bulk sync_cron sync_wildcard
  waf_rate_limit)
[[ "${PERF_EDGE:-1}" == 1 ]] && ALL_TESTS+=("${EDGE_TESTS[@]}")

FINDINGS=0
say() { printf '%s %s: %s\n' "$1" "$2" "$3"; case "$1" in FAIL) FAILS=$((FAILS+1));; PASS) PASSES=$((PASSES+1));; FINDING) FINDINGS=$((FINDINGS+1));; esac; }
dr() { $DRUSH "$@" 2>&1; }
php_eval() { $DRUSH php:eval "$1" 2>&1; }

# --- Redis helpers (phpredis extension, same connection the site uses) ------
redis_php() {
  php -r '
    $r = new Redis();
    $h = getenv("REDIS_HOST"); $p = (int) (getenv("REDIS_PORT") ?: 6379);
    if (strtolower((string) getenv("REDIS_SCHEME")) === "tls") { $h = "tls://" . $h; }
    $r->connect($h, $p, 2.0);
    if (getenv("REDIS_PASSWORD")) { $r->auth(getenv("REDIS_PASSWORD")); }
    if (getenv("REDIS_DB") !== false && getenv("REDIS_DB") !== "") { $r->select((int) getenv("REDIS_DB")); }
    // Without REDIS_PREFIX the redis module uses its own default prefix, so
    // match any prefix ("*") instead of silently matching nothing.
    $pfx = (string) getenv("REDIS_PREFIX");
    if ($pfx === "") { $pfx = "*"; }
    $cmd = $argv[1] ?? "count";
    switch ($cmd) {
      case "count":
        $n = 0; $it = null;
        while (($keys = $r->scan($it, $pfx . "*", 1000)) !== false) { $n += count($keys); }
        echo $n; break;
      case "count_bin":
        $n = 0; $it = null;
        while (($keys = $r->scan($it, $pfx . ":" . $argv[2] . ":*", 1000)) !== false) { $n += count($keys); }
        echo $n; break;
      case "info":
        $i = $r->info($argv[2] ?? "all"); echo $i[$argv[3]] ?? ""; break;
      case "config_get":
        $c = $r->config("GET", $argv[2]); echo $c[$argv[2]] ?? ""; break;
      case "config_set":
        var_export($r->config("SET", $argv[2], $argv[3])); break;
      case "get_bin":
        $it = null; $k = null;
        while (($keys = $r->scan($it, $pfx . ":" . $argv[2] . ":" . $argv[3], 100)) !== false) { if ($keys) { $k = $keys[0]; break; } }
        echo $k === null ? "" : (string) $r->get($k); break;
      case "ttl":
        echo $r->ttl($argv[2]); break;
      case "find":
        $it = null; $out = [];
        while (($keys = $r->scan($it, $argv[2], 1000)) !== false) { $out = array_merge($out, $keys); }
        echo implode("\n", $out); break;
      case "sample":
        $it = null; $keys = $r->scan($it, $pfx . ":" . $argv[2] . ":*", 100) ?: [];
        echo implode("\n", array_slice($keys, 0, (int) ($argv[3] ?? 5))); break;
    }
  ' -- "$@" 2>&1
}

# --- HTTP helpers ------------------------------------------------------------
# hdr <url> [curl args...] -> prints status and headers (lowercased names).
hdr() { local u="$1"; shift; curl -s -o /dev/null -D - "$@" "$u" | tr -d '\r' | awk 'NR==1{print "status: "$2; next} {split($0,a,": "); if (a[1]!="") print tolower(a[1])": "substr($0, length(a[1])+3)}'; }
hget() { printf '%s\n' "$1" | awk -v k="$2" -F': ' 'tolower($1)==k {sub(/^[^:]*: /,""); print; exit}'; }
status_of() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

mock_reset() { curl -s -X POST "$MOCK/__mock/reset" >/dev/null; }
mock_log() { curl -s "$MOCK/__mock/requests"; }
mock_count() { mock_log | grep -c "\"kind\": \"$1\"" || true; }

queue_volume() { $DRUSH p:queue-volume --format=string 2>/dev/null | tr -dc '0-9'; }

# Pick a SYNTHETIC published article node id (creates one if none exists).
first_node() {
  php_eval '
    $ids = \Drupal::entityQuery("node")->accessCheck(FALSE)->condition("status", 1)->sort("nid")->range(0, 1)->execute();
    if (!$ids) { $n = \Drupal\node\Entity\Node::create(["type" => "article", "title" => "SYNTHETIC perf test node", "status" => 1]); $n->save(); $ids = [$n->id()]; }
    print reset($ids);'
}
node_path() { php_eval "print \Drupal::service('path_alias.manager')->getAliasByPath('/node/$1');"; }
touch_node() { php_eval "\$n = \Drupal\node\Entity\Node::load($1); \$n->setTitle('SYNTHETIC perf test ' . time() . ' ' . mt_rand()); \$n->save(); print \$n->label();"; }

# --- Tests -------------------------------------------------------------------
t_redis_bins() {
  local out
  out=$(php_eval '
    $c = \Drupal::getContainer();
    $r = [];
    foreach (["render","page","dynamic_page_cache","data","entity","menu","bootstrap","config","discovery"] as $b) { $r[] = $b . "=" . (new \ReflectionClass(\Drupal::cache($b)))->getShortName(); }
    $r[] = "lock=" . (new \ReflectionClass($c->get("lock")))->getShortName();
    $r[] = "flood=" . (new \ReflectionClass($c->get("flood")))->getShortName();
    $r[] = "checksum=" . (new \ReflectionClass($c->get("cache_tags.invalidator.checksum")))->getShortName();
    print implode(" ", $r);' | tail -1)
  local want_redis="${PERF_EXPECT_REDIS_CLASS:-FailOpenCacheBackend}" bad=""
  for b in render page dynamic_page_cache data entity menu; do [[ " $out " == *" $b=$want_redis "* ]] || bad="$bad $b"; done
  for b in bootstrap config discovery; do [[ " $out " == *" $b="*Redis* || " $out " == *" $b=FailOpen"* ]] && bad="$bad $b"; done
  [[ " $out " == *" lock=Redis"* || " $out " == *" flood=Redis"* || " $out " == *" checksum=Redis"* ]] && bad="$bad lock/flood/checksum"
  if [[ -z "$bad" ]]; then
    say PASS redis_bins "heavy bins on $want_redis; others not on Redis: $out"
  else
    say FAIL redis_bins "wrong placement for:$bad ($out)"
  fi
}

t_redis_in_use() {
  local cls before after
  cls=$(php_eval 'print get_class(\Drupal::cache("render"));')
  before=$(redis_php count)
  # Uncached URLs (unique query strings) so new entries must be written.
  curl -s -o /dev/null "$BASE_URL/?perf=$RANDOM$RANDOM"; curl -s -o /dev/null "$BASE_URL/node/$(first_node)?perf=$RANDOM$RANDOM"
  after=$(redis_php count)
  if [[ "$cls" == *redis* && "${after:-0}" -gt "${before:-0}" ]]; then
    say PASS redis_in_use "render bin class=$cls; keys (prefix '${PREFIX:-*}') grew $before -> $after after 2 uncached requests"
  else
    say FAIL redis_in_use "render bin class=$cls; keys before=$before after=$after (expected growth)"
  fi
}

t_drush_cr() {
  local nid path b_all b_render b_page a_all a_render a_page s1 x2 a2
  nid=$(first_node); path=$(node_path "$nid")
  curl -s -o /dev/null "$BASE_URL$path"; curl -s -o /dev/null "$BASE_URL$path"
  s1=$(hget "$(hdr "$BASE_URL$path")" x-drupal-cache)
  b_all=$(redis_php count); b_render=$(redis_php count_bin render); b_page=$(redis_php count_bin page)
  local ts0 ts1; ts0=$(redis_php get_bin page _redis_last_delete_all)
  dr cr >/dev/null
  ts1=$(redis_php get_bin page _redis_last_delete_all)
  a_all=$(redis_php count); a_render=$(redis_php count_bin render); a_page=$(redis_php count_bin page)
  x2=$(hget "$(hdr "$BASE_URL$path")" x-drupal-cache)
  a2=$(redis_php count)
  say INFO drush_cr "page bin _redis_last_delete_all: '${ts0:-unset}' -> '$ts1'; keys before cr: total=$b_all render=$b_render page=$b_page; right after cr: total=$a_all render=$a_render page=$a_page; after one page request: total=$a2"
  if [[ "$s1" == "HIT" && "$x2" == "MISS" && -n "$ts1" && "$ts1" != "$ts0" ]]; then
    say PASS drush_cr "Redis last-delete-all timestamp changed ($ts1) and the cached page went HIT -> MISS"
  else
    say FAIL drush_cr "X-Drupal-Cache before=$s1 after=$x2; last_delete_all '${ts0:-unset}' -> '${ts1:-unset}'"
  fi
}

t_tag_invalidation() {
  local nid path h1 h2 h3 title body
  nid=$(first_node); path=$(node_path "$nid")
  curl -s -o /dev/null "$BASE_URL$path"
  h1=$(hget "$(hdr "$BASE_URL$path")" x-drupal-cache)
  title=$(touch_node "$nid")
  h2=$(hget "$(hdr "$BASE_URL$path")" x-drupal-cache)
  body=$(curl -s "$BASE_URL$path")
  h3=$(hget "$(hdr "$BASE_URL$path")" x-drupal-cache)
  if [[ "$h1" == "HIT" && "$h2" == "MISS" && "$h3" == "HIT" && "$body" == *"$title"* ]]; then
    say PASS tag_invalidation "node/$nid: HIT -> save -> MISS (new title served) -> HIT"
  else
    say FAIL tag_invalidation "node/$nid: before=$h1 after-save=$h2 then=$h3 new-title-served=$([[ "$body" == *"$title"* ]] && echo yes || echo no)"
  fi
}

t_eviction() {
  local orig_mem orig_pol used limit ev0 ev1 code nids n
  orig_mem=$(redis_php config_get maxmemory); orig_pol=$(redis_php config_get maxmemory-policy)
  used=$(redis_php info memory used_memory)
  limit=$((used + 400000))
  # Always restore the Redis limits, even if the test is interrupted.
  trap "redis_php config_set maxmemory '$orig_mem' >/dev/null; redis_php config_set maxmemory-policy '$orig_pol' >/dev/null; perf_cache_restore" EXIT INT TERM
  redis_php config_set maxmemory "$limit" >/dev/null
  redis_php config_set maxmemory-policy allkeys-lru >/dev/null
  ev0=$(redis_php info stats evicted_keys)
  nids=$(php_eval 'print implode(" ", \Drupal::entityQuery("node")->accessCheck(FALSE)->condition("status",1)->range(0,200)->execute());')
  n=0
  for id in $nids; do curl -s -o /dev/null "$BASE_URL/node/$id?perf_evict=$RANDOM"; n=$((n+1)); done
  ev1=$(redis_php info stats evicted_keys)
  code=$(status_of "$BASE_URL/")
  local peak; peak=$(redis_php info memory used_memory_peak_human)
  redis_php config_set maxmemory "$orig_mem" >/dev/null
  redis_php config_set maxmemory-policy "$orig_pol" >/dev/null
  trap - INT TERM; trap perf_cache_restore EXIT
  if [[ "$((ev1 - ev0))" -gt 0 && "$code" == "200" ]]; then
    say PASS eviction "maxmemory temporarily $limit bytes (policy allkeys-lru), $n page requests: evicted_keys $ev0 -> $ev1, home page still $code; peak=$peak; restored maxmemory=$orig_mem policy=$orig_pol"
  else
    say FAIL eviction "evicted_keys $ev0 -> $ev1 after $n requests; home=$code"
  fi
}

t_redis_down() {
  local c_home c_node c_login body d warn
  c_home=$(curl -s -o /tmp/perf-redis-down.html -w '%{http_code} %{time_total}' "$BASE_URL/?perf=$RANDOM")
  body=$(head -c 200 /tmp/perf-redis-down.html | tr '\n' ' ')
  c_node=$(curl -s -o /dev/null -w '%{http_code} %{time_total}' "$BASE_URL/node/1?perf=$RANDOM")
  c_login=$(status_of "$BASE_URL/user/login")
  d=$($DRUSH status --field=bootstrap 2>&1 | tail -1)
  warn=$($DRUSH sqlq "SELECT COUNT(*) FROM watchdog WHERE type='perf_redis_failopen' AND timestamp > UNIX_TIMESTAMP() - 120" 2>/dev/null | tr -dc "0-9")
  say INFO redis_down "home: $c_home; node/1: $c_node; /user/login: $c_login; drush bootstrap: $d; fail-open warnings logged in last 120 s: ${warn:-n/a}; body starts: ${body:0:120}"
  if [[ "${c_home%% *}" == "200" && "${c_node%% *}" == "200" && "$c_login" == "200" && "${warn:-0}" -ge 1 ]]; then
    say PASS redis_down "site answered 200 with Redis stopped and logged a throttled warning (${warn} in 120 s)"
  else
    say FAIL redis_down "Redis stopped: home ${c_home%% *}, node ${c_node%% *}, login $c_login, warnings ${warn:-n/a}"
  fi
}

# Run while the Redis container is PAUSED (accepts TCP, never answers). The
# first request pays at most the connect/read timeout, later ones skip Redis.
t_redis_hang() {
  local t1 t2 t3 c1 c2 c3 bound="${PERF_HANG_BOUND:-5}"
  read -r c1 t1 <<<"$(curl -s -o /dev/null --max-time 90 -w '%{http_code} %{time_total}' "$BASE_URL/node/3?hang=$RANDOM")"
  read -r c2 t2 <<<"$(curl -s -o /dev/null --max-time 90 -w '%{http_code} %{time_total}' "$BASE_URL/node/4?hang=$RANDOM")"
  read -r c3 t3 <<<"$(curl -s -o /dev/null --max-time 90 -w '%{http_code} %{time_total}' "$BASE_URL/?hang=$RANDOM")"
  say INFO redis_hang "Redis paused: first $c1 in ${t1}s, second $c2 in ${t2}s, third $c3 in ${t3}s (bound ${bound}s first, 1s after)"
  if [[ "$c1" == 200 && "$c2" == 200 && "$c3" == 200 ]] && awk -v a="$t1" -v b="$t2" -v c="$t3" -v m="$bound" 'BEGIN{exit !(a<m && b<1 && c<1)}'; then
    say PASS redis_hang "hung Redis costs the first request ${t1}s, then pages are served without waiting"
  else
    say FAIL redis_hang "codes $c1/$c2/$c3, times ${t1}/${t2}/${t3}s"
  fi
}

# Run after Redis is started again: wait out the retry interval, then check
# that pages are cached in Redis again.
t_redis_recover() {
  local wait before after h
  wait=$(( ${PERF_REDIS_RETRY_INTERVAL:-30} + 2 ))
  sleep "$wait"
  before=$(redis_php count)
  curl -s -o /dev/null "$BASE_URL/node/2"; curl -s -o /dev/null "$BASE_URL/node/2"
  h=$(hget "$(hdr "$BASE_URL/node/2")" x-drupal-cache)
  after=$(redis_php count)
  if [[ "${after:-0}" -gt "${before:-0}" && "$h" == "HIT" ]]; then
    say PASS redis_recover "after ${wait}s Redis is used again: keys $before -> $after, node/2 X-Drupal-Cache=$h"
  else
    say FAIL redis_recover "keys $before -> $after, node/2 X-Drupal-Cache=$h after ${wait}s"
  fi
}

t_purge_queue_fills() {
  local nid v0 v1 items
  dr p:queue-empty >/dev/null
  v0=$(queue_volume)
  nid=$(first_node); touch_node "$nid" >/dev/null
  v1=$(queue_volume)
  items=$(php_eval '
    $rows = \Drupal::database()->query("SELECT data FROM {purge_queue}")->fetchCol();
    $out = [];
    foreach ($rows as $d) { $x = unserialize($d); $out[] = ($x[0] ?? "?") . ":" . ($x[2] ?? json_encode($x)); }
    print implode(", ", $out);' | tail -1)
  if [[ "${v1:-0}" -gt "${v0:-0}" ]]; then
    say PASS purge_queue_fills "queue volume $v0 -> $v1 after saving node/$nid; items: $items"
  else
    say FAIL purge_queue_fills "queue volume $v0 -> $v1 after saving node/$nid; items: $items"
  fi
}

t_purge_send() {
  local nid path v0 v1 out log paths
  mock_reset; dr p:queue-empty >/dev/null
  nid=$(first_node); touch_node "$nid" >/dev/null; path=$(node_path "$nid")
  v0=$(queue_volume)
  out=$(dr p:queue-work --finish)
  v1=$(queue_volume)
  log=$(mock_log)
  paths=$(printf '%s\n' "$log" | grep '"kind": "purge"' | sed -n 's/.*"content_paths": \(\[[^]]*\]\).*/\1/p' | tr '\n' ' ')
  local url; url=$(printf '%s\n' "$log" | grep '"kind": "purge"' | head -1 | sed -n 's/.*"path": "\([^"]*\)".*/\1/p')
  if printf '%s' "$log" | grep -q '"kind": "token"' \
    && [[ "$url" == /subscriptions/*/resourceGroups/*/providers/Microsoft.Cdn/profiles/*/afdEndpoints/*/purge ]] \
    && [[ "$paths" == *"\"$path\""* ]] && [[ "${v1:-1}" -eq 0 ]]; then
    say PASS purge_send "p:queue-work: queue $v0 -> $v1; mock got token + POST $url contentPaths=$paths"
  else
    say FAIL purge_send "queue $v0 -> $v1; expected path $path; mock purge url=$url contentPaths=$paths; drush: $(echo "$out" | tail -2 | tr '\n' ' ')"
  fi
}

# Azure Government: switch the stored azure_environment (the lab sets no
# AZURE_CLOUD, so stored config is authoritative), work the queue, assert the
# token and purge requests went to the Government hosts with the Government
# scope. PERF_GOV_NEGATIVE=1 skips the switch to prove the test can fail.
t_purge_send_gov() {
  local nid log tok pur scope env_over prev
  prev=$(php_eval 'print \Drupal::config("azure_cdn_purge.settings")->get("azure_environment") ?? "";' | tail -1)
  local typo_out typo_env
  typo_out=$(AZURE_CLOUD=usgov $DRUSH php:eval 'print "[" . \Drupal::config("azure_cdn_purge.settings")->get("azure_environment") . "]";' 2>&1)
  typo_env=$(printf '%s' "$typo_out" | grep -o '\[[A-Za-z]*\]' | tail -1)
  # Shown on screen when the site displays errors, otherwise logged (dblog).
  if [[ "$typo_out" != *'unknown AZURE_CLOUD value "usgov"'* ]] \
    && [[ "$($DRUSH sqlq "SELECT COUNT(*) FROM watchdog WHERE type='php' AND variables LIKE '%unknown AZURE_CLOUD value \"usgov\"%' AND timestamp > UNIX_TIMESTAMP() - 120" 2>/dev/null | tr -dc '0-9')" -ge 1 ]]; then
    typo_out="$typo_out (logged) unknown AZURE_CLOUD value \"usgov\""
  fi
  env_over=$(AZURE_CLOUD=usgovernment $DRUSH php:eval 'print \Drupal::config("azure_cdn_purge.settings")->get("azure_environment");' 2>&1 | tail -1)
  mock_reset; dr p:queue-empty >/dev/null
  [[ "${PERF_GOV_NEGATIVE:-0}" == "1" ]] || dr cset azure_cdn_purge.settings azure_environment azureUSGovernmentCloud -y >/dev/null
  nid=$(first_node); touch_node "$nid" >/dev/null
  dr p:queue-work --finish >/dev/null
  dr cset azure_cdn_purge.settings azure_environment "${prev:-azureCloud}" -y >/dev/null
  log=$(mock_log)
  tok=$(printf '%s\n' "$log" | grep '"kind": "token"' | sed -n 's/.*"host": "\([^"]*\)".*/\1/p' | head -1)
  scope=$(printf '%s\n' "$log" | grep '"kind": "token"' | sed -n 's/.*"scope": "\([^"]*\)".*/\1/p' | head -1)
  pur=$(printf '%s\n' "$log" | grep '"kind": "purge"' | sed -n 's/.*"host": "\([^"]*\)".*/\1/p' | head -1)
  if [[ "$tok" == "login.microsoftonline.us" && "$pur" == "management.usgovcloudapi.net" && "$scope" == "https://management.usgovcloudapi.net/.default" && "$env_over" == "azureUSGovernmentCloud" && "$typo_out" == *'unknown AZURE_CLOUD value "usgov"'* && "$typo_env" == "[${prev:-}]" ]]; then
    say PASS purge_send_gov "token -> $tok (scope $scope), purge -> $pur; AZURE_CLOUD=usgovernment maps to $env_over; AZURE_CLOUD=usgov -> warning, setting left at $typo_env"
  else
    say FAIL purge_send_gov "token host='$tok' scope='$scope' purge host='$pur' AZURE_CLOUD mapping='$env_over' typo(usgov) env='$typo_env' warning=$([[ "$typo_out" == *unknown\ AZURE_CLOUD* ]] && echo yes || echo no)${PERF_GOV_NEGATIVE:+ (negative run: switch skipped)}"
  fi
}

t_purge_retry() {
  local nid v1 v2 v3 s
  mock_reset; dr p:queue-empty >/dev/null
  curl -s -X POST "$MOCK/__mock/fail?count=1&status=500" >/dev/null
  nid=$(first_node); touch_node "$nid" >/dev/null
  v1=$(queue_volume)
  dr p:queue-work --finish >/dev/null
  v2=$(queue_volume)
  s=$(mock_log | grep '"kind": "purge"' | sed -n 's/.*"status": \([0-9]*\).*/\1/p' | tr '\n' ',')
  dr p:queue-work --finish >/dev/null
  v3=$(queue_volume)
  s=$(mock_log | grep '"kind": "purge"' | sed -n 's/.*"status": \([0-9]*\).*/\1/p' | tr '\n' ',')
  if [[ "$s" == 500,202* && "${v2:-0}" -ge 1 && "${v3:-1}" -eq 0 ]]; then
    say PASS purge_retry "mock answered 500 then 202; queue $v1 -> $v2 after failed run (item kept) -> $v3 after retry"
  else
    say FAIL purge_retry "mock statuses=$s; queue $v1 -> $v2 -> $v3"
  fi
}

t_fast404_static() {
  local u r code t size h
  u="$BASE_URL/sites/default/files/perf-missing-$RANDOM.png"
  r=$(curl -s -o /tmp/perf-404s.html -w '%{http_code} %{time_total} %{size_download}' "$u")
  read -r code t size <<<"$r"
  h=$(hdr "$u")
  local ref; ref=$(curl -s -o /dev/null -w '%{time_total}' "$BASE_URL/user/login?perf=$RANDOM")
  if grep -q '<center>nginx' /tmp/perf-404s.html; then
    say FAIL fast404_static "answered by nginx itself (${t}s), Drupal/Fast 404 never saw the request; pass missing static files to Drupal (see docs) to test Fast 404"
  elif [[ "$code" == "404" && -z "$(hget "$h" x-drupal-cache)" && -z "$(hget "$h" x-drupal-dynamic-cache)" ]]; then
    say PASS fast404_static "missing .png -> 404 in ${t}s, ${size} bytes, no Drupal cache headers (answered before Drupal booted); uncached /user/login for comparison ${ref}s"
  else
    say FAIL fast404_static "code=$code time=${t}s size=$size x-drupal-cache=$(hget "$h" x-drupal-cache)"
  fi
}

t_fast404_path() {
  local u r code t size f ref
  u="$BASE_URL/perf-no-such-path-$RANDOM/deeper"
  r=$(curl -s -o /tmp/perf-404p.html -w '%{http_code} %{time_total} %{size_download}' "$u")
  read -r code t size <<<"$r"
  ref=$(curl -s -o /dev/null -w '%{time_total}' "$BASE_URL/user/password?perf=$RANDOM")
  f="$(php_eval 'print \Drupal::service("file_system")->realpath("public://");')/page-not-found-en.html"
  # Served by fast 404 = the body is the static page (Themed Fast 404 file, or
  # the fast404 default HTML when cron has not written the file yet).
  if [[ "$code" == "404" ]] && { cmp -s "$f" /tmp/perf-404p.html || grep -q 'Not Found</h1>' /tmp/perf-404p.html; }; then
    say PASS fast404_path "unknown path -> 404 in ${t}s, ${size} bytes, body = static fast 404 page (uncached /user/password for comparison ${ref}s)"
  else
    say FAIL fast404_path "code=$code time=${t}s size=$size body-matches-static=$(cmp -s "$f" /tmp/perf-404p.html && echo yes || echo no)"
  fi
}

t_themed_404() {
  local f pub u
  pub=$(php_eval 'print \Drupal::service("file_system")->realpath("public://");')
  f="$pub/page-not-found-en.html"
  dr cron >/dev/null
  if [[ ! -s "$f" ]]; then
    say FAIL themed_404 "cron did not write $f"
    return
  fi
  local marker; marker=$(grep -o '<title>[^<]*</title>' "$f" | head -1)
  u="$BASE_URL/sites/default/files/perf-missing-$RANDOM.css"
  curl -s -o /tmp/perf-404t.html "$u"
  # The response must be byte-identical to the file cron wrote (fast404
  # substitutes @path, so compare with @path replaced by the request path).
  if cmp -s "$f" /tmp/perf-404t.html || cmp -s <(sed "s#@path#${u#$BASE_URL}#g" "$f") /tmp/perf-404t.html; then
    say PASS themed_404 "cron wrote $(basename "$f") ($(wc -c <"$f") bytes, $marker); the missing .css response is byte-identical to it"
  else
    say FAIL themed_404 "response ($(wc -c </tmp/perf-404t.html) bytes) is not the static file ($(wc -c <"$f") bytes); first bytes: $(head -c 120 /tmp/perf-404t.html | tr '\n' ' ')"
  fi
}

t_headers_anon() {
  local u h1 h2 cc
  u="$BASE_URL/node/$(first_node)"
  curl -s -o /dev/null "$u"; h1=$(hdr "$u"); h2=$(hdr "$u")
  cc=$(hget "$h2" cache-control)
  if [[ "$(hget "$h2" x-drupal-cache)" == "HIT" && "$cc" == *public* && "$cc" == *max-age=900* ]]; then
    say PASS headers_anon "anonymous: X-Drupal-Cache=$(hget "$h2" x-drupal-cache) X-Drupal-Dynamic-Cache=$(hget "$h1" x-drupal-dynamic-cache) Cache-Control='$cc'"
  else
    say FAIL headers_anon "X-Drupal-Cache=$(hget "$h2" x-drupal-cache) Cache-Control='$cc'"
  fi
}

t_headers_auth() {
  local jar link u h1 h2 cc
  jar=$(mktemp)
  link=$($DRUSH uli --uri="$BASE_URL" --no-browser 2>/dev/null | tail -1)
  curl -s -o /dev/null -c "$jar" -b "$jar" -L "$link"
  u="$BASE_URL/node/$(first_node)"
  h1=$(hdr "$u" -b "$jar"); h2=$(hdr "$u" -b "$jar")
  cc=$(hget "$h2" cache-control)
  rm -f "$jar" 2>/dev/null || true
  local pc; pc=$(hget "$h2" x-drupal-cache)
  if [[ ( -z "$pc" || "$pc" == UNCACHEABLE* ) && "$(hget "$h2" x-drupal-dynamic-cache)" == "HIT" && ( "$cc" == *private* || "$cc" == *no-cache* ) ]]; then
    say PASS headers_auth "logged in: X-Drupal-Cache='${pc:-absent}' (page cache skipped), X-Drupal-Dynamic-Cache $(hget "$h1" x-drupal-dynamic-cache) -> $(hget "$h2" x-drupal-dynamic-cache), Cache-Control='$cc'"
  else
    say FAIL headers_auth "X-Drupal-Cache='$(hget "$h2" x-drupal-cache)' dynamic='$(hget "$h2" x-drupal-dynamic-cache)' Cache-Control='$cc' (login link: ${link:0:60})"
  fi
}

t_cache_4xx() {
  local nid u h1 h2 h3 other ttl key
  nid=$(php_eval '$n = \Drupal\node\Entity\Node::create(["type" => "article", "title" => "SYNTHETIC unpublished 403 probe", "status" => 0]); $n->save(); print $n->id();' | tail -1)
  u="$BASE_URL/node/$nid"
  curl -s -o /dev/null "$u"; h1=$(hdr "$u"); h2=$(hdr "$u")
  key=$(redis_php find "${PREFIX:-*}:page:*node/$nid*" | head -1)
  ttl=$( [[ -n "$key" ]] && redis_php ttl "$key" )
  other=$(first_node); touch_node "$other" >/dev/null
  h3=$(hdr "$u")
  if [[ "$(hget "$h2" status)" == "403" && "$(hget "$h2" x-drupal-cache)" == "HIT" && "$(hget "$h3" x-drupal-cache)" == "MISS" ]]; then
    say PASS cache_4xx "unpublished node/$nid: 403 cached (HIT, Redis TTL ${ttl:-n/a}s = cache_ttl_4xx + redis_ttl_offset); saving another node (node/$other) cleared it (next request MISS)"
  else
    say FAIL cache_4xx "status=$(hget "$h2" status) second=$(hget "$h2" x-drupal-cache) after-other-save=$(hget "$h3" x-drupal-cache) ttl=${ttl:-n/a}"
  fi
}

# --- Cache modes (scripts/perf-cache.sh, docs/cache-modes.md) ---------------
# CACHE_MODE_NEGATIVE=1 fail-proof: each test switches to the OPPOSITE mode,
# so both must FAIL.
PERF_CACHE_SH="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/perf-cache.sh"
mode_probe() {
  local u="$1" h1 h2 body cls
  cls=$(php_eval 'print (new \ReflectionClass(\Drupal::cache("render")))->getShortName();' | tail -1)
  curl -s -o /dev/null "$u"; h1=$(hdr "$u"); h2=$(hdr "$u"); body=$(curl -s "$u")
  MP_CLS="$cls"; MP_PC="$(hget "$h2" x-drupal-cache)"; MP_CC="$(hget "$h2" cache-control)"
  MP_TWIG=$(printf '%s' "$body" | grep -c 'THEME DEBUG' || true)
  MP_AGG=$(printf '%s' "$body" | grep -c 'files/css/css_' || true)
}

t_cache_mode_dev() {
  local target=dev; [[ "${CACHE_MODE_NEGATIVE:-0}" == 1 ]] && target=prod-like
  bash "$PERF_CACHE_SH" "$target" >/dev/null 2>&1
  mode_probe "$BASE_URL/node/$(first_node)?mode=$(rnd)"
  bash "$PERF_CACHE_SH" prod-like >/dev/null 2>&1
  if [[ "$MP_CLS" == NullBackend && "$MP_PC" != HIT && "$MP_TWIG" -ge 1 && "$MP_AGG" -eq 0 && "$MP_CC" == *no-cache* ]]; then
    say PASS cache_mode_dev "dev: render bin $MP_CLS, 2nd request X-Drupal-Cache='${MP_PC:-absent}', Cache-Control '$MP_CC', $MP_TWIG THEME DEBUG comments, 0 aggregated CSS links; switched back to prod-like"
  else
    say FAIL cache_mode_dev "mode $target: render bin $MP_CLS, X-Drupal-Cache='${MP_PC:-absent}', Cache-Control '$MP_CC', twig comments $MP_TWIG, aggregated CSS links $MP_AGG"
  fi
}

t_cache_mode_prod() {
  local target=prod-like; [[ "${CACHE_MODE_NEGATIVE:-0}" == 1 ]] && target=dev
  bash "$PERF_CACHE_SH" "$target" >/dev/null 2>&1
  mode_probe "$BASE_URL/node/$(first_node)?mode=$(rnd)"
  [[ "$target" != prod-like ]] && bash "$PERF_CACHE_SH" prod-like >/dev/null 2>&1
  if [[ "$MP_CLS" != NullBackend && "$MP_PC" == HIT && "$MP_TWIG" -eq 0 && "$MP_AGG" -ge 1 && "$MP_CC" == *public* ]]; then
    say PASS cache_mode_prod "prod-like: render bin $MP_CLS, 2nd request X-Drupal-Cache=HIT, Cache-Control '$MP_CC', no THEME DEBUG, $MP_AGG aggregated CSS link(s)"
  else
    say FAIL cache_mode_prod "mode $target: render bin $MP_CLS, X-Drupal-Cache='${MP_PC:-absent}', Cache-Control '$MP_CC', twig comments $MP_TWIG, aggregated CSS links $MP_AGG"
  fi
}

# Guard: dev must be refused outside DDEV/Lando. Simulated with
# PERF_CACHE_GUARD_SIMULATE_REMOTE=1 (removing IS_DDEV_PROJECT would also drop
# DDEV's database settings). CACHE_GUARD_NEGATIVE=1 omits it (fail-proof:
# dev is then accepted and the test FAILS).
t_cache_mode_guard() {
  local out envcmd="env PERF_CACHE_GUARD_SIMULATE_REMOTE=1"
  [[ "${CACHE_GUARD_NEGATIVE:-0}" == 1 ]] && envcmd="env"
  bash "$PERF_CACHE_SH" dev >/dev/null 2>&1
  out=$($envcmd $DRUSH php:eval 'use Drupal\Core\Site\Settings; print "[" . Settings::get("perf_cache_mode", "none") . "|" . Settings::get("perf_cache_mode_refused", "") . "|" . (new \ReflectionClass(\Drupal::cache("render")))->getShortName() . "]";' 2>&1)
  bash "$PERF_CACHE_SH" prod-like >/dev/null 2>&1
  out=$(printf '%s' "$out" | grep -o '\[[^]]*\]' | tail -1)
  if [[ "$out" == "[none|dev|"* && "$out" != *"|NullBackend]" ]]; then
    say PASS cache_mode_guard "mode file = dev, simulated non-local: $out (refused, render bin not null); back to prod-like"
  else
    say FAIL cache_mode_guard "mode file = dev, simulated non-local=$([[ "${CACHE_GUARD_NEGATIVE:-0}" == 1 ]] && echo no || echo yes): $out (want [none|dev|<not NullBackend>])"
  fi
}

# --- Edge (Front Door + WAF stand-in, edge/) --------------------------------
# PERF_EDGE_URL     main edge route (default http://edge:8080)
# PERF_EDGE_CONTROL edge control listener (default http://edge:8099)
# EDGE_NEGATIVE=1   fail-proof: send the "edge" requests straight to the origin
#                   (BASE_URL); edge_purge_e2e instead keeps the edge and turns
#                   off the mock-afd -> edge purge forwarding;
#                   every edge_* / waf_* / origin_headers test must then FAIL.
EDGE="${PERF_EDGE_URL:-http://edge:8080}"
EDGE_CTL="${PERF_EDGE_CONTROL:-http://edge:8099}"
EDGE_HOST="${EDGE#*://}"; EDGE_HOST="${EDGE_HOST%%[:/]*}"
EDGE_NEG="${EDGE_NEGATIVE:-0}"
# edge_route <port>: URL of one edge listener (route).
edge_route() { if [[ "$EDGE_NEG" == 1 ]]; then echo "$BASE_URL"; else echo "http://$EDGE_HOST:$1"; fi; }
EDGE_REAL="$EDGE"
[[ "$EDGE_NEG" == 1 ]] && EDGE="$BASE_URL"
xc() { hget "$(hdr "$@")" x-cache; }
rnd() { echo "$RANDOM$RANDOM"; }
# First aggregated CSS URL on the front page (relative, &amp; decoded).
agg_css() { curl -s "$BASE_URL/" | grep -o '/sites/[^"]*/files/css/css_[^"]*\.css?[^"]*' | head -1 | sed 's/&amp;/\&/g'; }
agg_js() { curl -s "$BASE_URL/" | grep -o '/sites/[^"]*/files/js/js_[^"]*\.js?[^"]*' | head -1 | sed 's/&amp;/\&/g'; }
PERF_BLOCK_TEXT="The request is blocked."

t_edge_static_hit() {
  local agg aggjs u r1 r2 bad="" out="" q
  agg=$(agg_css); aggjs=$(agg_js); q="edgetest=$(rnd)"
  # Drupal builds aggregates on first request (a PHP response); warm them at
  # the origin so the edge sees the static file, as it would in production.
  curl -s -o /dev/null "$BASE_URL$agg"; curl -s -o /dev/null "$BASE_URL$aggjs"
  for u in "$agg&$q" "$aggjs&$q" "/core/misc/druplicon.png?$q"; do
    r1=$(hdr "$EDGE$u" -H 'Accept-Encoding: gzip'); r2=$(hdr "$EDGE$u" -H 'Accept-Encoding: gzip')
    out="$out ${u%%\?*}: $(hget "$r1" x-cache)->$(hget "$r2" x-cache) cc='$(hget "$r2" cache-control)' ce=$(hget "$r2" content-encoding);"
    [[ "$(hget "$r1" status)" == 200 && "$(hget "$r1" x-cache)" == TCP_MISS && "$(hget "$r2" x-cache)" == TCP_HIT && -n "$(hget "$r2" cache-control)" ]] || bad="$bad ${u%%\?*}"
  done
  [[ -z "$agg" || -z "$aggjs" ]] && bad="$bad no-css-or-js-aggregate-on-front-page"
  if [[ -z "$bad" ]]; then say PASS edge_static_hit "CSS aggregate/JS/image MISS then HIT:$out"; else say FAIL edge_static_hit "not MISS->HIT with Cache-Control:$bad |$out"; fi
}

t_edge_querystring() {
  local r img=/core/misc/druplicon.png bad="" out="" a b c agg re
  r=$(rnd)
  # "ignore" mode keys on the path alone, so start from an empty cache for it
  # (control listener purge, same API mock-afd uses).
  curl -s -X POST -H 'Content-Type: application/json' -d "{\"contentPaths\":[\"$img\"]}" "$EDGE_CTL/__edge/purge" >/dev/null
  # use (8091): order-independent, different values are different objects
  a=$(xc "$(edge_route 8091)$img?a=$r&b=2"); b=$(xc "$(edge_route 8091)$img?b=2&a=$r"); c=$(xc "$(edge_route 8091)$img?a=$r&b=3")
  out="$out use:$a/$b/$c"; [[ "$a/$b/$c" == TCP_MISS/TCP_HIT/TCP_MISS ]] || bad="$bad use"
  # ignore (8090): any query string shares one object
  a=$(xc "$(edge_route 8090)$img?x=$r"); b=$(xc "$(edge_route 8090)$img?x=$r-other")
  out="$out ignore:$a/$b"; [[ "$a/$b" == TCP_MISS/TCP_HIT ]] || bad="$bad ignore"
  # ignore-specified (8092, EDGE_QS_IGNORE_PARAMS has utm_source)
  a=$(xc "$(edge_route 8092)$img?page=$r&utm_source=a"); b=$(xc "$(edge_route 8092)$img?utm_source=b&page=$r"); c=$(xc "$(edge_route 8092)$img?page=$r-2")
  out="$out ignore-specified:$a/$b/$c"; [[ "$a/$b/$c" == TCP_MISS/TCP_HIT/TCP_MISS ]] || bad="$bad ignore-specified"
  # include-specified (8093, EDGE_QS_INCLUDE_PARAMS has v)
  a=$(xc "$(edge_route 8093)$img?v=$r&junk=1"); b=$(xc "$(edge_route 8093)$img?junk=2&v=$r"); c=$(xc "$(edge_route 8093)$img?v=$r-2")
  out="$out include-specified:$a/$b/$c"; [[ "$a/$b/$c" == TCP_MISS/TCP_HIT/TCP_MISS ]] || bad="$bad include-specified"
  # Drupal aggregated CSS on the main route (use): its own query params,
  # reordered, must hit the same object and still be CSS.
  agg=$(agg_css)
  if [[ -n "$agg" ]]; then
    re="${agg%%\?*}?$(printf '%s' "${agg#*\?}&edgeqs=$r" | tr '&' '\n' | sort -r | paste -sd '&' -)"
    a=$(hdr "$EDGE$agg&edgeqs=$r"); b=$(hdr "$EDGE$re")
    out="$out drupal-aggregate:$(hget "$a" x-cache)/$(hget "$b" x-cache) type=$(hget "$b" content-type)"
    [[ "$(hget "$a" x-cache)/$(hget "$b" x-cache)" == TCP_MISS/TCP_HIT && "$(hget "$b" content-type)" == text/css* ]] || bad="$bad drupal-aggregate"
  else
    bad="$bad no-aggregate"
  fi
  if [[ -z "$bad" ]]; then say PASS edge_querystring "$out"; else say FAIL edge_querystring "wrong:$bad |$out"; fi
}

t_edge_bypass_session() {
  local jar=/tmp/perf-edge-cookies.txt link u a1 a2 s1 s2 cc ck
  : > "$jar"; u="/node/$(first_node)?edgebypass=$(rnd)"
  link=$($DRUSH uli --uri="$EDGE" --no-browser 2>/dev/null | tail -1)
  curl -s -o /dev/null -c "$jar" -b "$jar" -L "$link"
  s1=$(hdr "$EDGE$u" -b "$jar"); s2=$(hdr "$EDGE$u" -b "$jar")
  a1=$(xc "$EDGE$u"); a2=$(xc "$EDGE$u")
  cc=$(hget "$s2" cache-control)
  ck=$(grep -c 'SESS' "$jar" 2>/dev/null || true)
  : > "$jar"
  if [[ "$ck" -ge 1 && "$(hget "$s1" x-cache)" == CONFIG_NOCACHE && "$(hget "$s2" x-cache)" == CONFIG_NOCACHE && "$(xc "$EDGE/user/login")" == CONFIG_NOCACHE && -n "$a1" && "$a1" != CONFIG_NOCACHE ]]; then
    say PASS edge_bypass_session "session cookie: X-Cache $(hget "$s1" x-cache),$(hget "$s2" x-cache) (Cache-Control '$cc'); /user/login CONFIG_NOCACHE; same URL anonymous: $a1,$a2"
  else
    say FAIL edge_bypass_session "cookie jar SESS=$ck; logged-in X-Cache '$(hget "$s1" x-cache)','$(hget "$s2" x-cache)'; anon '$a1','$a2'"
  fi
}

t_edge_html_ttl() {
  local prev u h1 h2 p1 p2 cc
  prev=$(php_eval 'print \Drupal::config("system.performance")->get("cache.page.max_age");' | tail -1)
  u="$EDGE/node/$(first_node)?edgettl=$(rnd)"
  php_eval '\Drupal::configFactory()->getEditable("system.performance")->set("cache.page.max_age", 300)->save();' >/dev/null
  h1=$(hdr "$u"); h2=$(hdr "$u"); cc=$(hget "$h2" cache-control)
  php_eval '\Drupal::configFactory()->getEditable("system.performance")->set("cache.page.max_age", 0)->save();' >/dev/null
  u="$u-private"; p1=$(hdr "$u"); p2=$(hdr "$u")
  php_eval "\Drupal::configFactory()->getEditable('system.performance')->set('cache.page.max_age', (int) '${prev:-0}')->save();" >/dev/null
  if [[ "$(hget "$h1" x-cache)/$(hget "$h2" x-cache)" == TCP_MISS/TCP_HIT && "$cc" == *max-age=300* && "$(hget "$p1" x-cache)/$(hget "$p2" x-cache)" == PRIVATE_NOSTORE/PRIVATE_NOSTORE ]]; then
    say PASS edge_html_ttl "max_age 300: $(hget "$h1" x-cache)->$(hget "$h2" x-cache) ('$cc'); max_age 0: $(hget "$p1" x-cache)->$(hget "$p2" x-cache) ('$(hget "$p2" cache-control)'); restored max_age=${prev:-0}"
  else
    say FAIL edge_html_ttl "300: $(hget "$h1" x-cache)->$(hget "$h2" x-cache) '$cc'; 0: $(hget "$p1" x-cache)->$(hget "$p2" x-cache) '$(hget "$p2" cache-control)'"
  fi
}

t_edge_purge_e2e() {
  local prev nid path t_old t_new b1 b2 b3 h3 log stale=no fresh=no
  prev=$(php_eval 'print \Drupal::config("system.performance")->get("cache.page.max_age");' | tail -1)
  php_eval '\Drupal::configFactory()->getEditable("system.performance")->set("cache.page.max_age", 300)->save();' >/dev/null
  mock_reset; [[ "$EDGE_NEG" == 1 ]] && curl -s -X POST "$MOCK/__mock/edge-purge?enabled=0" >/dev/null
  nid=$(first_node); t_old=$(touch_node "$nid" | tail -1); path=$(node_path "$nid")
  dr p:queue-work --finish >/dev/null; dr p:queue-empty >/dev/null
  curl -s -o /dev/null "$EDGE_REAL$path"; b1=$(curl -s "$EDGE_REAL$path")
  t_new=$(touch_node "$nid" | tail -1)
  b2=$(curl -s "$EDGE_REAL$path")
  dr p:queue-work --finish >/dev/null
  h3=$(hdr "$EDGE_REAL$path"); b3=$(curl -s "$EDGE_REAL$path")
  log=$(mock_log | grep '"kind": "edge_purge"' | tail -1)
  php_eval "\Drupal::configFactory()->getEditable('system.performance')->set('cache.page.max_age', (int) '${prev:-0}')->save();" >/dev/null
  [[ "$b2" == *"$t_old"* && "$b2" != *"$t_new"* ]] && stale=yes
  [[ "$b3" == *"$t_new"* ]] && fresh=yes
  if [[ "$b1" == *"$t_old"* && "$stale" == yes && "$fresh" == yes ]]; then
    say PASS edge_purge_e2e "edit node/$nid -> edge still served old title (cached); p:queue-work -> mock-afd -> edge purge ($log) -> $path X-Cache $(hget "$h3" x-cache) with new title"
  else
    say FAIL edge_purge_e2e "cached-old-before-purge=$stale fresh-after-purge=$fresh X-Cache after=$(hget "$h3" x-cache); edge_purge log: ${log:-none}"
  fi
}

t_waf_block_sqli() {
  local h b
  h=$(hdr "$EDGE/node?id=1%27%20OR%20%271%27%3D%271%27%20--%20"); b=$(curl -s "$EDGE/?search=1%20UNION%20SELECT%20password%20FROM%20users--")
  if [[ "$(hget "$h" status)" == 403 && "$b" == *"$PERF_BLOCK_TEXT"* ]]; then
    say PASS waf_block_sqli "GET ' OR '1'='1 -> 403; UNION SELECT -> Azure-style block page (ref $(printf '%s' "$b" | grep -o 'reference: [0-9a-f]*'))"
  else
    say FAIL waf_block_sqli "status $(hget "$h" status); body: ${b:0:80}"
  fi
}

t_waf_block_xss() {
  local s1 s2
  s1=$(status_of "$EDGE/?q=%3Cscript%3Ealert(1)%3C%2Fscript%3E")
  s2=$(status_of -X POST --data-urlencode 'comment=<img src=x onerror=alert(1)>' "$EDGE/")
  if [[ "$s1" == 403 && "$s2" == 403 ]]; then
    say PASS waf_block_xss "GET <script> -> $s1; POST body onerror= -> $s2"
  else
    say FAIL waf_block_xss "GET <script> -> $s1; POST body -> $s2 (want 403/403)"
  fi
}

t_waf_blocked_paths() {
  local p s bad="" out=""
  for p in /.env /.git/config /vendor/autoload.php /core/install.php /update.php /core/CHANGELOG.txt /wp-admin/ /xmlrpc.php /phpmyadmin/ /core/rebuild.php /sites/default/settings.php; do
    s=$(status_of "$EDGE$p"); out="$out $p=$s"; [[ "$s" == 403 ]] || bad="$bad $p=$s"
  done
  for p in / /index.php; do
    s=$(status_of "$EDGE$p"); out="$out $p=$s"; [[ "$s" == 200 ]] || bad="$bad $p=$s(want 200)"
  done
  if [[ -z "$bad" ]]; then say PASS waf_blocked_paths "$out"; else say FAIL waf_blocked_paths "wrong:$bad |$out"; fi
}

t_waf_detection_mode() {
  local m s1 s2 audit sqli_logged blk_logged
  m="det$(rnd)"
  s1=$(status_of "$(edge_route 8081)/node?id=1%27%20OR%20%271%27%3D%271%27%20--%20&m=$m")
  s2=$(status_of "$(edge_route 8081)/xmlrpc.php?m=$m")
  sleep 1
  audit=$(curl -s "$EDGE_CTL/__edge/log/audit" | grep "$m")
  sqli_logged=$(printf '%s' "$audit" | grep -c '"ruleId":"942' || true)
  blk_logged=$(printf '%s' "$audit" | grep -c '"ruleId":"10002"' || true)
  if [[ "$s1" != 403 && "$s2" != 403 && "$sqli_logged" -ge 1 && "$blk_logged" -ge 1 ]]; then
    say PASS waf_detection_mode "Detection route: SQLi -> $s1, /xmlrpc.php -> $s2 (not blocked); audit log has CRS 942xxx and custom 10002 matches for marker $m"
  else
    say FAIL waf_detection_mode "SQLi -> $s1, xmlrpc -> $s2; audit lines with marker: $(printf '%s' "$audit" | grep -c . || true), 942 logged=$sqli_logged, 10002 logged=$blk_logged"
  fi
}

t_origin_headers() {
  local m h seen host mine
  m="probe$(rnd)"
  h=$(hdr "$EDGE/admin/config?edgeprobe=$m" -H 'X-Forwarded-For: 203.0.113.9' -H 'X-Azure-ClientIP: 203.0.113.9')
  seen=$(hget "$h" x-perf-edge-seen)
  host=$($DRUSH sqlq "SELECT hostname FROM watchdog WHERE location LIKE '%edgeprobe=$m%' ORDER BY wid DESC LIMIT 1" 2>/dev/null | tr -d '[:space:]')
  mine=" $(hostname -i 2>/dev/null) "
  if [[ "$seen" == *"fdid=${PERF_EXPECT_FDID:-00000000-0000-0000-0000-0000000fd1d0}"* && "$seen" == *"via=1.1 Azure"* && "$seen" == *"chain=hops=1"* \
     && "$seen" == *"xff=203.0.113.9, "* && -n "$host" && "$mine" == *" $host "* && "$host" != 203.0.113.9 ]]; then
    say PASS origin_headers "origin saw: $seen; dblog client IP = $host (this container; spoofed 203.0.113.9 ignored)"
  else
    say FAIL origin_headers "origin saw: '${seen:-nothing}'; dblog client IP '$host' (want one of:$mine)"
  fi
}

# X-Forwarded-Port is not trusted (docs/hardening.md). Front Door overwrites only
# X-Forwarded-Host and -Proto and passes the other headers a client sends, so if
# Drupal trusted the port, a forged "X-Forwarded-Port: 4444" would put absolute
# links with :4444 (canonical, shortlink) into a page the edge then caches for
# everyone. The request goes through the edge (the trusted proxy) with a
# cache-busting query; no absolute URL in the page may carry the forged port.
# Fail-proof: trust the header again (lab settings: PERF_LAB_TRUST_FORWARDED_PORT=1
# in docs/tests.md); the page then carries :4444 and the test FAILs.
t_origin_forwarded_port() {
  local nid p body links
  nid=$(first_node); p=$(node_path "$nid")
  body=$(curl -s -H 'X-Forwarded-Port: 4444' "$EDGE$p?fwdport=$(rnd)")
  links=$(grep -o -E '(rel="(canonical|shortlink)"[^>]*|<link[^>]*(canonical|shortlink)[^>]*)' <<<"$body" | head -3 | tr '\n' ' ')
  if [[ "$links" != *canonical* ]]; then
    say FAIL origin_forwarded_port "no canonical link in the page for $p, so this proves nothing (got: '${links:0:160}')"
  elif [[ "$body" == *":4444"* ]]; then
    say FAIL origin_forwarded_port "a forged X-Forwarded-Port: 4444 reached the page: ${links:0:200}"
  else
    say PASS origin_forwarded_port "X-Forwarded-Port: 4444 sent through the edge is ignored: no ':4444' anywhere in $p (links: ${links:0:160})"
  fi
}

t_waf_rate_limit() {
  local i c codes="" lg n429=0 s429=0 p429 psz g429 dry=0
  for i in $(seq 1 12); do codes="$codes $(status_of -X POST --data 'name=x&pass=y&form_id=user_login_form' "$EDGE/user/login")"; done
  for c in $codes; do [[ "$c" == 429 ]] && n429=$((n429+1)); done
  lg=$((12 - n429))
  # 60/min refills one request per second, so send in parallel.
  s429=$(seq 1 90 | xargs -P 16 -I{} curl -s -o /dev/null -w '%{http_code}\n' "$EDGE/search/node?keys=rl{}" | grep -c 429 || true)
  # Pager/views-filter bursts must NOT hit the search limit (only general).
  # A 429 here can also come from the general limit (the suite's own traffic),
  # so attribute it: count edge error-log lines where the SEARCH zone limited
  # these marked requests (must be 0).
  local mk="pg$(rnd)"
  p429=$(seq 1 90 | xargs -P 16 -I{} curl -s -o /dev/null -w '%{http_code}\n' "$EDGE/node?page={}&rl=$mk" | grep -c 429 || true)
  sleep 1
  psz=$(curl -s "$EDGE_CTL/__edge/log/error" | grep "$mk" | grep -c 'zone "edge_search"' || true)
  g429=$(seq 1 320 | xargs -P 16 -I{} curl -s -o /dev/null -w '%{http_code}\n' "$EDGE/core/misc/druplicon.png" | grep -c 429 || true)
  for i in $(seq 1 12); do [[ "$(status_of -X POST --data 'name=x&pass=y' "$(edge_route 8081)/user/login")" == 429 ]] && dry=$((dry+1)); done
  # 10/min refills one request per 6 s; 12 serial POSTs can earn one refill.
  if [[ "$lg" -ge 10 && "$lg" -le 11 && "$s429" -ge 1 && "$psz" -eq 0 && "$g429" -ge 1 && "$dry" -eq 0 ]]; then
    say PASS waf_rate_limit "login POST: 10 allowed then 429 ($codes); search: $s429 of 90 got 429; /node?page=N: 0 of 90 limited by the search zone ($p429 by the general limit); general: $g429 of 320 got 429; Detection route: 0 of 12 login POSTs limited (dry run)"
  else
    say FAIL waf_rate_limit "login allowed=$lg (want 10, or 11 with one refill) codes:$codes; search 429s=$s429; /node?page=N search-zone limits=$psz (want 0), 429s=$p429; general 429s=$g429; detection-route 429s=$dry (want 0)"
  fi
}

# --- Bot header-consistency challenge + security headers (docs/edge-waf.md) --
CHROME_UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36'
t_bot_challenge() {
  local u b1 b2 c3 s4 s5 s6 d7 k8 bad="" out
  u="/node/$(first_node)?bot=$(rnd)"
  b1=$(hdr "$EDGE$u" -A "$CHROME_UA" -H 'Sec-Fetch-Mode: navigate' -H 'Accept-Language: en-US,en;q=0.9')
  b2=$(hdr "$EDGE$u" -A "$CHROME_UA")
  c3=$(status_of "$EDGE$u")
  s4=$(status_of -A "$CHROME_UA" "$EDGE/core/misc/druplicon.png")
  s5=$(status_of -A "$CHROME_UA" "$EDGE/rss.xml")
  s6=$(hdr "$EDGE$u" -A "$CHROME_UA" -H 'Accept-Language: en-US')
  d7=$(hdr "$(edge_route 8081)$u" -A "$CHROME_UA")
  # Logged-in user (session cookie), no Sec-Fetch-Mode/Accept-Language.
  # BOT_COOKIE_NEGATIVE=1 sends a non-session cookie instead (fail-proof).
  local ck="SSESSsyntheticbot$(rnd)=synthetic"; [[ "${BOT_COOKIE_NEGATIVE:-0}" == 1 ]] && ck="notasession=synthetic"
  k8=$(hdr "$EDGE$u" -A "$CHROME_UA" -H "Cookie: $ck")
  [[ "$(hget "$b1" status)" == 200 && -z "$(hget "$b1" x-edge-action)" ]] || bad="$bad browser-like"
  [[ "$(hget "$b2" status)" == 403 && "$(hget "$b2" x-edge-action)" == challenge ]] || bad="$bad bare-chrome"
  # EDGE_CHALLENGE_MISSING must match the edge's own setting: "both" (default)
  # lets one missing header through; "either" challenges it.
  local mode="${EDGE_CHALLENGE_MISSING:-both}"
  if [[ "$mode" == either ]]; then
    [[ "$(hget "$s6" status)" == 403 && "$(hget "$s6" x-edge-action)" == challenge ]] || bad="$bad one-header-missing(either)"
  else
    [[ "$(hget "$s6" status)" == 200 && -z "$(hget "$s6" x-edge-action)" ]] || bad="$bad one-header-present(default both)"
  fi
  [[ "$s4" == 200 && "$s5" == 200 ]] || bad="$bad static-or-feed"
  [[ "$(hget "$d7" status)" == 200 && "$(hget "$d7" x-edge-would-challenge)" == header-consistency ]] || bad="$bad detection"
  [[ "$(hget "$k8" status)" != 403 && -z "$(hget "$k8" x-edge-action)" ]] || bad="$bad session-cookie"
  out="browser-like $(hget "$b1" status); Chrome UA with neither header $(hget "$b2" status) X-Edge-Action=$(hget "$b2" x-edge-action); Chrome UA with Accept-Language only $(hget "$s6" status) (EDGE_CHALLENGE_MISSING=$mode); Chrome UA + session cookie, no headers $(hget "$k8" status) X-Edge-Action='$(hget "$k8" x-edge-action)'; plain curl UA $c3 (not a browser UA: rule does not apply); static image $s4, /rss.xml $s5; Detection route $(hget "$d7" status) X-Edge-Would-Challenge=$(hget "$d7" x-edge-would-challenge)"
  if [[ -z "$bad" ]]; then say PASS bot_challenge "$out"; else say FAIL bot_challenge "wrong:$bad | $out"; fi
}

t_security_headers() {
  local h bad="" v
  h=$(hdr "$EDGE/")
  [[ "$(hget "$h" strict-transport-security)" == *includeSubDomains* ]] || bad="$bad HSTS"
  [[ "$(hget "$h" permissions-policy)" == *"camera=()"* ]] || bad="$bad Permissions-Policy"
  [[ "$(hget "$h" cross-origin-opener-policy)" == same-origin ]] || bad="$bad COOP"
  [[ "$(hget "$h" content-security-policy-report-only)" == *"default-src 'self'"* ]] || bad="$bad CSP-Report-Only"
  [[ "$(hget "$h" content-security-policy)" == *"frame-ancestors 'self'"* ]] || bad="$bad CSP-frame-ancestors"
  v=$(hget "$(hdr "$EDGE/.env")" strict-transport-security)
  [[ -n "$v" ]] || bad="$bad HSTS-on-block-page"
  if [[ -z "$bad" ]]; then say PASS security_headers "HSTS '$(hget "$h" strict-transport-security)'; Permissions-Policy '$(hget "$h" permissions-policy)'; COOP $(hget "$h" cross-origin-opener-policy); CSP-Report-Only set; CSP '$(hget "$h" content-security-policy)'; also on the 403 block page"
  else say FAIL security_headers "missing:$bad"; fi
}

t_generator_removed() {
  local body h m
  body=$(curl -s "$BASE_URL/"); h=$(hdr "$BASE_URL/")
  m=$(php_eval 'print \Drupal::moduleHandler()->moduleExists("remove_http_headers") ? "on" : "off";' | tail -1)
  if [[ "$body" != *'name="Generator"'* && -z "$(hget "$h" x-generator)" ]]; then
    say PASS generator_removed "no Generator meta tag and no X-Generator header (remove_http_headers: $m)"
  else say FINDING generator_removed "Generator meta tag/X-Generator header present (remove_http_headers: $m). Install drupal/remove_http_headers and set headers_to_remove to [X-Generator] (docs/hardening.md)"; fi
}

# --- Cache sync: Drupal/Redis <-> purge queue <-> Front Door (docs/cache-sync.md)
# Each test warms URLs at the local edge (TCP_HIT), changes content with
# drush, works the purge queue, and checks the first edge response after it:
# TCP_MISS = purged, TCP_HIT = still cached (stale).
# SYNC_NEGATIVE=1 fail-proof: the content change runs with PERF_PURGE_PATHS=0,
# so perf_purge_paths queues nothing (while it is installed it also removes
# azure_cdn_purge's own node hook), and the tests that depend on it FAIL.
# FINDING lines = known gaps left as open decisions (docs/cache-sync.md).
# sync_delete, sync_alias_delete, sync_file_delete and sync_file_swap need the
# delete hooks of the perf_purge_paths module (the former perf_purge_deleted
# module is part of it): with it installed, stale = FAIL; without it, stale =
# FINDING. Each checks that the URL was cached at the edge BEFORE the delete,
# so a purge that never happened and a page that was never cached cannot look
# the same.
sync_env() { if [[ "${SYNC_NEGATIVE:-0}" == 1 ]]; then echo "PERF_PURGE_PATHS=0"; else echo "PERF_PURGE_PATHS=1"; fi; }
sphp() { env $(sync_env) ${SYNC_EXTRA_ENV:-} $DRUSH php:eval "$1" 2>&1; }
SYNC_PREV_MAXAGE=""
sync_begin() {
  [[ -z "$SYNC_PREV_MAXAGE" ]] && SYNC_PREV_MAXAGE=$(php_eval 'print \Drupal::config("system.performance")->get("cache.page.max_age");' | tail -1)
  php_eval '\Drupal::configFactory()->getEditable("system.performance")->set("cache.page.max_age", 300)->save();' >/dev/null
  mock_reset; dr p:queue-empty >/dev/null
}
sync_end() { php_eval "\Drupal::configFactory()->getEditable('system.performance')->set('cache.page.max_age', (int) '${SYNC_PREV_MAXAGE:-900}')->save();" >/dev/null; }
sync_work() { dr p:queue-work --finish >/dev/null; }
warm() { local u; for u in "$@"; do curl -s -o /dev/null "$EDGE_REAL$u"; curl -s -o /dev/null "$EDGE_REAL$u"; done; }
# after <urls...>: "url=XCACHE/status ..." for the first request after purge.
after() { local u h; for u in "$@"; do h=$(hdr "$EDGE_REAL$u"); printf '%s=%s/%s ' "$u" "$(hget "$h" x-cache)" "$(hget "$h" status)"; done; }
all_miss() { [[ "$1" != *TCP_HIT* && "$1" == *TCP_MISS* ]]; }
queued() { php_eval 'print implode(" ", array_map(fn($d) => ($x = unserialize($d)) ? ($x[0] . ":" . $x[2]) : "?", \Drupal::database()->query("SELECT data FROM {purge_queue}")->fetchCol()));' | tail -1; }
new_node() { php_eval "\$n = \Drupal\node\Entity\Node::create(['type' => 'article', 'title' => 'SYNTHETIC sync $1 ' . mt_rand(), 'status' => ${3:-1}, 'promote' => 1, 'path' => ['alias' => '$2', 'pathauto' => 0]]); \$n->save(); print \$n->id();" | tail -1; }
# deleted_mod: "on" when the perf_purge_paths module (which carries the alias and
# file delete/rename hooks) is installed.
deleted_mod() { php_eval 'print \Drupal::moduleHandler()->moduleExists("perf_purge_paths") ? "on" : "off";' | tail -1; }
# hits <result>: how many URLs came from the edge cache with status 200.
hits() { grep -o 'TCP_HIT/200 ' <<<"$1" | grep -c .; }
# gone <result> <n>: all n URLs answered 404 and none came from the edge cache.
gone() { [[ "$1" != *TCP_HIT* && "$(grep -o '/404 ' <<<"$1" | grep -c .)" == "$2" ]]; }
# new_png <name>: a SYNTHETIC public PNG (unique bytes) as a permanent managed
# file public://synthetic-sync-<name>.png; prints "<fid> <md5 of the bytes>".
new_png() {
  php_eval '
    $im = imagecreatetruecolor(4, 4); imagefill($im, 0, 0, imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
    ob_start(); imagepng($im); $bytes = ob_get_clean();
    $uri = "public://synthetic-sync-'"$1"'.png"; file_put_contents($uri, $bytes);
    $f = \Drupal\file\Entity\File::create(["uri" => $uri, "status" => 1]); $f->save();
    print $f->id() . " " . md5($bytes);' | tail -1
}
# style_url <fid>: root-relative URL (with ?itok) of the file in the first image
# style; empty when the site has no image style.
style_url() { php_eval "\$s = \Drupal\image\Entity\ImageStyle::loadMultiple(); \$s = reset(\$s); print \$s ? \Drupal::service('file_url_generator')->transformRelative(\$s->buildUrl(\Drupal\file\Entity\File::load($1)->getFileUri())) : '';" | tail -1; }

t_sync_alias() {
  local r nid a res q
  sync_begin; r=$(rnd); nid=$(new_node alias "/synthetic-sync-$r"); a="/synthetic-sync-$r"
  sync_work; warm "$a" "/node/$nid" "/node/$nid/" "$a/" "$a?x=1"
  sphp "\$n = \Drupal\node\Entity\Node::load($nid); \$n->setTitle('SYNTHETIC sync alias edited ' . mt_rand()); \$n->save();" >/dev/null
  q=$(queued); sync_work
  res=$(after "$a" "/node/$nid" "/node/$nid/" "$a/" "$a?x=1"); sync_end
  if all_miss "$res"; then say PASS sync_alias "node/$nid saved: alias, /node/N, trailing-slash and ?query variants all purged: $res (queued: $q)"
  else say FAIL sync_alias "stale at the edge after save + queue-work: $res (queued: $q)"; fi
}

t_sync_single_save() {
  local r nid a q n u dup
  sync_begin; r=$(rnd); a="/synthetic-sync-once-$r"; nid=$(new_node once "$a"); dr p:queue-empty >/dev/null
  sphp "\$n = \Drupal\node\Entity\Node::load($nid); \$n->setTitle('SYNTHETIC sync once ' . mt_rand()); \$n->save();" >/dev/null
  q=$(queued); dr p:queue-empty >/dev/null; sync_end
  n=$(printf '%s\n' $q | grep -c . || true); u=$(printf '%s\n' $q | sort -u | grep -c . || true)
  dup=$(printf '%s\n' $q | sort | uniq -d | tr '\n' ' ')
  if [[ "$n" -gt 0 && "$n" == "$u" && " $q " == *" path:${a#/} "* && " $q " == *" path:node/$nid "* && " $q " != *"rss.xml/"* ]]; then
    say PASS sync_single_save "one save of node/$nid queued $n invalidations, each once: $q"
  else say FAIL sync_single_save "one save queued $n items, $u unique; duplicates: '${dup}'; items: $q"; fi
}

t_sync_listing() {
  local nid res t body
  sync_begin; warm / /node /rss.xml "/?page=0"
  nid=$(sphp "\$n = \Drupal\node\Entity\Node::create(['type' => 'article', 'title' => 'SYNTHETIC sync listing ' . mt_rand(), 'status' => 1, 'promote' => 1]); \$n->save(); print \$n->id();" | tail -1)
  t=$(php_eval "print \Drupal\node\Entity\Node::load($nid)->label();" | tail -1)
  sync_work; res=$(after / /node /rss.xml "/?page=0"); body=$(curl -s "$EDGE_REAL/"); sync_end
  if all_miss "$res" && [[ "$body" == *"$t"* ]]; then say PASS sync_listing "new node/$nid: front page, /node, /rss.xml (and ?page=0 variant) purged and the new title is on the front page: $res"
  else say FAIL sync_listing "after creating node/$nid: $res; new title on edge front page: $([[ "$body" == *"$t"* ]] && echo yes || echo no)"; fi
}

t_sync_alias_change() {
  local r nid old new res
  sync_begin; r=$(rnd); old="/synthetic-sync-old-$r"; new="/synthetic-sync-new-$r"
  nid=$(new_node aliaschange "$old"); sync_work; warm "$old"
  sphp "\$n = \Drupal\node\Entity\Node::load($nid); \$n->path->alias = '$new'; \$n->save();" >/dev/null
  sync_work; res=$(after "$old" "$new"); sync_end
  if [[ "$res" == "$old="*"/404 "* && "$res" != "$old=TCP_HIT"* ]]; then say PASS sync_alias_change "alias $old -> $new (UI-style update of the same alias): old alias purged, now 404: $res"
  else say FAIL sync_alias_change "old alias still served from the edge after the alias change: $res"; fi
}

# A node is deleted: its alias and /node/N must be purged and answer 404. With
# the perf_purge_paths module installed (its alias hook queues the alias while it
# still exists) a stale alias is a FAIL; without it, a FINDING (known gap). The
# URLs must be cached at the edge BEFORE the delete, so "never cached" cannot
# pass as "purged", and both must answer 404, not merely "not a hit".
t_sync_delete() {
  local r nid a on pre res
  sync_begin; r=$(rnd); a="/synthetic-sync-del-$r"; on=$(deleted_mod)
  nid=$(new_node delete "$a"); sync_work; warm "$a" "/node/$nid"; pre=$(after "$a" "/node/$nid")
  sphp "\Drupal\node\Entity\Node::load($nid)->delete();" >/dev/null
  sync_work; res=$(after "$a" "/node/$nid"); sync_end
  if [[ "$(hits "$pre")" != 2 ]]; then say FAIL sync_delete "alias and /node/N were not both cached at the edge before the delete, so this proves nothing: $pre"
  elif gone "$res" 2; then say PASS sync_delete "deleted node/$nid: alias and /node/N purged (404): $res (before: $pre; perf_purge_paths $on)"
  elif [[ "$on" == off && "$res" == *"/node/$nid=TCP_MISS"* ]]; then say FINDING sync_delete "deleted node/$nid: /node/N purged but the ALIAS is still served from the edge until its TTL: $res. Fix: install the perf_purge_paths module (docs/cache-sync.md)"
  else say FAIL sync_delete "perf_purge_paths $on: deleted node still at the edge, or not 404: $res (before: $pre)"; fi
}

# Only the path_alias entity is deleted (the node stays): the alias must be purged
# at the edge (HIT -> MISS). This test checks the EDGE CONTRACT only; what Drupal
# answers is checked by sync_alias_delete_404 below. Core invalidates only
# "route_match" for an alias, and Drupal's page cache entries do not expire, so
# without the perf_purge_paths alias hook (which also invalidates the aliased
# entity's tags) Drupal keeps answering 200 for the deleted alias.
t_sync_alias_delete() {
  local r nid a on pre res
  sync_begin; r=$(rnd); a="/synthetic-sync-adel-$r"; on=$(deleted_mod)
  nid=$(new_node aliasdelete "$a"); sync_work; warm "$a" "$a/"; pre=$(after "$a" "$a/")
  sphp "\$s = \Drupal::entityTypeManager()->getStorage('path_alias'); \$s->delete(\$s->loadByProperties(['alias' => '$a']));" >/dev/null
  sync_work; res=$(after "$a" "$a/"); sync_end
  if [[ "$(hits "$pre")" != 2 ]]; then say FAIL sync_alias_delete "alias and alias/ were not both cached at the edge before the delete, so this proves nothing: $pre"
  elif all_miss "$res"; then say PASS sync_alias_delete "path_alias of node/$nid deleted: alias and alias/ purged at the edge (HIT -> MISS): $res (before: $pre; perf_purge_paths $on)"
  elif [[ "$res" == *TCP_HIT* && "$on" == off ]]; then say FINDING sync_alias_delete "deleted alias is still served from the edge until its TTL: $res. Fix: install the perf_purge_paths module (docs/cache-sync.md)"
  else say FAIL sync_alias_delete "perf_purge_paths $on: alias deleted, after queue-work: $res (before: $pre)"; fi
}

# The user-visible property of an alias-only delete: the deleted alias answers
# 404 from the ORIGIN and from the edge. Separate from sync_alias_delete (the
# edge contract) so neither check can hide the other. The origin is requested
# twice at BASE_URL BEFORE the delete, so that exact page cache entry exists: a
# cache-busting query string would hide the defect. Needs the perf_purge_paths
# alias hook (it invalidates the aliased entity's cache tags).
t_sync_alias_delete_404() {
  local r nid a on pre o_pre res_e res_o
  sync_begin; r=$(rnd); a="/synthetic-sync-adel404-$r"; on=$(deleted_mod)
  nid=$(new_node aliasdelete404 "$a"); sync_work; warm "$a"; pre=$(after "$a")
  status_of "$BASE_URL$a" >/dev/null; o_pre=$(status_of "$BASE_URL$a")
  sphp "\$s = \Drupal::entityTypeManager()->getStorage('path_alias'); \$s->delete(\$s->loadByProperties(['alias' => '$a']));" >/dev/null
  sync_work; res_e=$(after "$a"); res_o=$(status_of "$BASE_URL$a"); sync_end
  if [[ "$(hits "$pre")" != 1 || "$o_pre" != 200 ]]; then say FAIL sync_alias_delete_404 "the alias was not cached at the edge ($pre) and served by the origin ($o_pre) before the delete, so this proves nothing"
  elif [[ "$res_o" == 404 ]] && gone "$res_e" 1; then say PASS sync_alias_delete_404 "path_alias of node/$nid deleted: the origin answers 404 and the edge answers 404 (not from its cache): origin $res_o, edge $res_e (before: origin $o_pre, edge $pre; perf_purge_paths $on)"
  else say FAIL sync_alias_delete_404 "perf_purge_paths $on: the deleted alias is still served: origin $res_o (want 404), edge $res_e (want 404 not from the cache); before: origin $o_pre, edge $pre"; fi
}

# A path_alias entity is renamed through the entity API (not the node form, which
# also invalidates the node): the OLD alias must answer 404 at the origin and the
# edge, and the NEW alias must serve the node. Needs the perf_purge_paths alias
# update hook: it queues the old alias and invalidates the entity's cache tags,
# which core does not do for an alias.
t_sync_alias_rename() {
  local r nid old new on pre o_pre res_e res_o
  sync_begin; r=$(rnd); old="/synthetic-sync-renold-$r"; new="/synthetic-sync-rennew-$r"; on=$(deleted_mod)
  nid=$(new_node aliasrename "$old"); sync_work; warm "$old"; pre=$(after "$old")
  status_of "$BASE_URL$old" >/dev/null; o_pre=$(status_of "$BASE_URL$old")
  sphp "\$s = \Drupal::entityTypeManager()->getStorage('path_alias'); foreach (\$s->loadByProperties(['alias' => '$old']) as \$a) { \$a->setAlias('$new')->save(); }" >/dev/null
  sync_work; res_e=$(after "$old" "$new"); res_o=$(status_of "$BASE_URL$old"); sync_end
  if [[ "$(hits "$pre")" != 1 || "$o_pre" != 200 ]]; then say FAIL sync_alias_rename "the old alias was not cached at the edge ($pre) and served by the origin ($o_pre) before the rename, so this proves nothing"
  elif [[ "$res_o" == 404 && "$res_e" == "$old="*"/404 "* && "$res_e" != "$old=TCP_HIT"* && "$res_e" == *"$new=TCP_MISS/200"* ]]; then
    say PASS sync_alias_rename "path_alias of node/$nid renamed $old -> $new: the old alias answers 404 at the origin ($res_o) and the edge, the new one serves the node: $res_e (before: origin $o_pre, edge $pre; perf_purge_paths $on)"
  else say FAIL sync_alias_rename "perf_purge_paths $on: old alias origin $res_o (want 404), edge: $res_e (want old 404 not from the cache, new TCP_MISS/200); before: origin $o_pre, edge $pre"; fi
}

t_sync_publish_cycle() {
  local r nid a res1 res2
  sync_begin; r=$(rnd); a="/synthetic-sync-pub-$r"; nid=$(new_node publish "$a"); sync_work; warm "$a"
  sphp "\$n = \Drupal\node\Entity\Node::load($nid); \$n->setUnpublished()->save();" >/dev/null
  sync_work; res1=$(after "$a"); warm "$a"
  sphp "\$n = \Drupal\node\Entity\Node::load($nid); \$n->setPublished()->save();" >/dev/null
  sync_work; res2=$(after "$a"); sync_end
  if [[ "$res1" == *"TCP_MISS/403"* && "$res2" == *"TCP_MISS/200"* ]]; then say PASS sync_publish_cycle "unpublish: $res1 (403 then cached at the edge); publish again: $res2"
  else say FAIL sync_publish_cycle "unpublish -> $res1; publish -> $res2 (want MISS/403 then MISS/200)"; fi
}

t_sync_revert() {
  local r nid a res
  sync_begin; r=$(rnd); a="/synthetic-sync-rev-$r"; nid=$(new_node revert "$a")
  php_eval "\$n = \Drupal\node\Entity\Node::load($nid); \$n->setNewRevision(TRUE); \$n->setTitle('SYNTHETIC sync revert B'); \$n->save();" >/dev/null
  sync_work; warm "$a"
  sphp "\$s = \Drupal::entityTypeManager()->getStorage('node'); \$ids = array_values(\$s->revisionIds(\$s->load($nid))); \$old = \$s->loadRevision(\$ids[0]); \$old->setNewRevision(TRUE); \$old->isDefaultRevision(TRUE); \$old->save();" >/dev/null
  sync_work; res=$(after "$a"); local body; body=$(curl -s "$EDGE_REAL$a"); sync_end
  if [[ "$res" == *TCP_MISS/200* && "$body" != *"SYNTHETIC sync revert B"* ]]; then say PASS sync_revert "revision revert on node/$nid purged the alias: $res"
  else say FAIL sync_revert "after revert: $res; old title B still shown: $([[ "$body" == *"SYNTHETIC sync revert B"* ]] && echo yes || echo no)"; fi
}

t_sync_new_path_404() {
  local r a res
  sync_begin; r=$(rnd); a="/synthetic-sync-future-$r"; warm "$a"
  local pre; pre=$(hdr "$EDGE_REAL$a")
  new_node future "$a" >/dev/null; sync_work; res=$(after "$a"); sync_end
  if [[ -z "$(hget "$pre" x-cache)" ]]; then say FAIL sync_new_path_404 "edge did not answer (no X-Cache): $res"
  elif [[ "$res" == *TCP_MISS/200* ]]; then say PASS sync_new_path_404 "unknown path before the node existed: $(hget "$pre" x-cache)/$(hget "$pre" status) Cache-Control '$(hget "$pre" cache-control)' (a 404 is not stored at the edge); after create: $res"
  else say FINDING sync_new_path_404 "a 404 cached at the edge (before: $(hget "$pre" x-cache)/$(hget "$pre" status), Cache-Control '$(hget "$pre" cache-control)') is NOT purged when a node is created at that alias: $res. Core invalidates only list tags + 4xx-response on insert. Open decision (docs/cache-sync.md)"; fi
}

t_sync_other_entities() {
  local r tid ta uid res tres ures
  sync_begin; r=$(rnd); ta="/synthetic-sync-term-$r"
  tid=$(php_eval "\$t = \Drupal\taxonomy\Entity\Term::create(['vid' => 'tags', 'name' => 'SYNTHETIC term $r', 'path' => ['alias' => '$ta', 'pathauto' => 0]]); \$t->save(); print \$t->id();" | tail -1)
  uid=$(php_eval "\$u = \Drupal\user\Entity\User::create(['name' => 'synthetic_sync_$r', 'mail' => 'synthetic_sync_$r@example.invalid', 'status' => 1]); \$u->save(); print \$u->id();" | tail -1)
  sync_work; warm "$ta" "/taxonomy/term/$tid" "/user/$uid"
  sphp "\$t = \Drupal\taxonomy\Entity\Term::load($tid); \$t->setName('SYNTHETIC term edited $r'); \$t->save(); \$u = \Drupal\user\Entity\User::load($uid); \$u->setUsername('synthetic_sync_b_$r'); \$u->save();" >/dev/null
  sync_work; tres=$(after "$ta" "/taxonomy/term/$tid"); ures=$(after "/user/$uid"); sync_end
  local media; media=$(php_eval 'print \Drupal::moduleHandler()->moduleExists("media") ? "yes" : "no";' | tail -1)
  # /user/* is a bypass path at the edge (CONFIG_NOCACHE): never cached, nothing to purge.
  if all_miss "$tres" && { all_miss "$ures" || [[ "$ures" == *CONFIG_NOCACHE* ]]; }; then say PASS sync_other_entities "taxonomy term: $tres; user: $ures (bypassed at the edge); media module installed: $media (media:N maps the same way, untested when not installed)"
  else say FAIL sync_other_entities "term: $tres; user: $ures"; fi
}

t_sync_menu_block() {
  local nid p res1 res2
  sync_begin; nid=$(first_node); p=$(node_path "$nid"); warm "$p"
  sphp "\$l = \Drupal\menu_link_content\Entity\MenuLinkContent::create(['title' => 'SYNTHETIC sync menu ' . mt_rand(), 'link' => ['uri' => 'internal:/node/$nid'], 'menu_name' => 'main']); \$l->save();" >/dev/null
  sync_work; res1=$(after "$p")
  warm "$p"
  SYNC_EXTRA_ENV="PERF_PURGE_SITEWIDE_PATHS=/*" sphp "\$l = \Drupal\menu_link_content\Entity\MenuLinkContent::create(['title' => 'SYNTHETIC sync menu2 ' . mt_rand(), 'link' => ['uri' => 'internal:/node/$nid'], 'menu_name' => 'main']); \$l->save();" >/dev/null
  sync_work; res2=$(after "$p"); sync_end
  if [[ "$res2" == *TCP_MISS* ]]; then
    [[ "$res1" == *TCP_HIT* ]] && say FINDING sync_menu_block "default (PERF_PURGE_SITEWIDE_PATHS empty): a menu change leaves every page stale at the edge: $res1 (until HTML TTL)."
    say PASS sync_menu_block "with PERF_PURGE_SITEWIDE_PATHS=/* a menu change purges pages: $res2"
  else say FAIL sync_menu_block "even with PERF_PURGE_SITEWIDE_PATHS=/* the page stayed cached: $res2 (default: $res1)"; fi
}

t_sync_file() {
  local r fid u res body
  sync_begin; r=$(rnd)
  fid=$(php_eval "\$uri = 'public://synthetic-sync-$r.txt'; file_put_contents(\$uri, 'SYNTHETIC version A'); \$f = \Drupal\file\Entity\File::create(['uri' => \$uri, 'status' => 1]); \$f->save(); print \$f->id();" | tail -1)
  u="/sites/default/files/synthetic-sync-$r.txt"; sync_work; warm "$u"
  local cc; cc=$(hget "$(hdr "$EDGE_REAL$u")" cache-control)
  sphp "\$f = \Drupal\file\Entity\File::load($fid); file_put_contents(\$f->getFileUri(), 'SYNTHETIC version B'); \$f->save();" >/dev/null
  sync_work; res=$(after "$u"); body=$(curl -s "$EDGE_REAL$u"); sync_end
  if [[ "$res" == *TCP_MISS* && "$body" == *"version B"* ]]; then say PASS sync_file "file replaced at the same URL + file entity saved: $res, new content served (edge had Cache-Control '$cc')"
  else say FAIL sync_file "same-URL replacement: $res, body '$body' (edge Cache-Control '$cc')"; fi
}

# A file entity is deleted (an upload removed, or cron cleaning up): its URL and
# its image style derivative must be purged.
t_sync_file_delete() {
  local r fid u d on pre res urls
  sync_begin; r=$(rnd); on=$(deleted_mod)
  read -r fid _ <<<"$(new_png "fdel-$r")"
  u="/sites/default/files/synthetic-sync-fdel-$r.png"; d=$(style_url "$fid")
  urls=("$u"); [[ -n "$d" ]] && urls+=("$d")
  sync_work; warm "${urls[@]}"; pre=$(after "${urls[@]}")
  sphp "\Drupal\file\Entity\File::load($fid)->delete();" >/dev/null
  sync_work; res=$(after "${urls[@]}"); sync_end
  if [[ "$(hits "$pre")" != "${#urls[@]}" ]]; then say FAIL sync_file_delete "not every URL was cached at the edge before the delete, so this proves nothing: $pre"
  elif gone "$res" "${#urls[@]}"; then say PASS sync_file_delete "file/$fid deleted: ${#urls[@]} URL(s) (file$([[ -n "$d" ]] && echo " + image style derivative")) purged, now 404: $res (before: $pre; perf_purge_paths $on)"
  elif [[ "$res" == *TCP_HIT* && "$on" == off ]]; then say FINDING sync_file_delete "deleted file is still served from the edge until its TTL: $res. Fix: install the perf_purge_paths module (docs/cache-sync.md)"
  else say FAIL sync_file_delete "perf_purge_paths $on: file deleted, after queue-work: $res (before: $pre)"; fi
}

# A node's image is swapped for a new file, then the unused old file is deleted
# (what cron's cleanup of unused files does): the old URLs go, the new one stays.
t_sync_file_swap() {
  local r nid fa fb md5b ua ub da on pre res urls newres newmd5
  sync_begin; r=$(rnd); on=$(deleted_mod)
  read -r fa _ <<<"$(new_png "swapa-$r")"; read -r fb md5b <<<"$(new_png "swapb-$r")"
  ua="/sites/default/files/synthetic-sync-swapa-$r.png"; ub="/sites/default/files/synthetic-sync-swapb-$r.png"; da=$(style_url "$fa")
  nid=$(php_eval "\$n = \Drupal\node\Entity\Node::create(['type' => 'article', 'title' => 'SYNTHETIC sync swap $r', 'status' => 1, 'promote' => 1, 'field_image' => ['target_id' => $fa, 'alt' => 'SYNTHETIC'], 'path' => ['alias' => '/synthetic-sync-swap-$r', 'pathauto' => 0]]); \$n->save(); print \$n->id();" | tail -1)
  urls=("$ua"); [[ -n "$da" ]] && urls+=("$da")
  sync_work; warm "${urls[@]}"; pre=$(after "${urls[@]}")
  sphp "\$n = \Drupal\node\Entity\Node::load($nid); \$n->field_image = ['target_id' => $fb, 'alt' => 'SYNTHETIC']; \$n->save();" >/dev/null
  sphp "\Drupal\file\Entity\File::load($fa)->delete();" >/dev/null
  sync_work; res=$(after "${urls[@]}"); newres=$(after "$ub"); newmd5=$(curl -s "$EDGE_REAL$ub" | md5sum | cut -d' ' -f1); sync_end
  if [[ "$(hits "$pre")" != "${#urls[@]}" ]]; then say FAIL sync_file_swap "the old file's URLs were not all cached at the edge before the swap, so this proves nothing: $pre"
  elif [[ "$newres" != *"/200 "* || "$newmd5" != "$md5b" ]]; then say FAIL sync_file_swap "new file not served as expected: $newres, md5 at the edge $newmd5, wanted $md5b"
  elif gone "$res" "${#urls[@]}"; then say PASS sync_file_swap "node/$nid image swapped (file/$fa -> file/$fb), old file deleted: old URL(s) purged, now 404: $res; new file served with the right bytes: $newres (before: $pre; perf_purge_paths $on)"
  elif [[ "$res" == *TCP_HIT* && "$on" == off ]]; then say FINDING sync_file_swap "after the swap the OLD file is still served from the edge until its TTL: $res (new file: $newres). Fix: install the perf_purge_paths module (docs/cache-sync.md)"
  else say FAIL sync_file_swap "perf_purge_paths $on: old file deleted after the swap, after queue-work: $res (before: $pre; new file: $newres)"; fi
}

t_sync_bulk() {
  local ids n reqs maxp v
  sync_begin
  ids=$(php_eval '$ids = \Drupal::entityQuery("node")->accessCheck(FALSE)->range(0, 60)->execute(); print implode(",", $ids);' | tail -1)
  sphp "foreach (\Drupal\node\Entity\Node::loadMultiple([$ids]) as \$n) { \$n->save(); }" >/dev/null
  v=$(queue_volume); dr p:queue-work --finish >/dev/null
  reqs=$(mock_log | grep -c '"kind": "purge"' || true)
  maxp=$(mock_log | grep '"kind": "purge"' | sed -n 's/.*"content_paths": \[\([^]]*\)\].*/\1/p' | awk -F', ' '{print NF}' | sort -n | tail -1)
  n=$(printf '%s' "$ids" | tr ',' '\n' | grep -c .); sync_end
  if [[ "${v:-0}" -gt 0 && "$reqs" -ge 2 && "${maxp:-99}" -le 50 && "$(queue_volume)" == 0 ]]; then say PASS sync_bulk "$n nodes saved in one drush call: $v invalidations queued, sent as $reqs purge requests, largest $maxp paths (chunk_size 50); queue empty"
  else say FAIL sync_bulk "$n nodes: queued $v, purge requests $reqs, largest $maxp paths, queue now $(queue_volume)"; fi
}

t_sync_cron() {
  local r nid a res lr
  sync_begin; r=$(rnd); a="/synthetic-sync-cron-$r"; nid=$(new_node cron "$a"); sync_work; warm "$a"
  sphp "\$n = \Drupal\node\Entity\Node::load($nid); \$n->setTitle('SYNTHETIC sync cron ' . mt_rand()); \$n->save();" >/dev/null
  res=$(after "$a"); warm "$a"
  # Another cron (automated_cron from a page request) may hold the lock; its
  # search_cron can take ~50 s in the lab. drush cron would then skip.
  local waited=0
  while [[ "$(php_eval 'print \Drupal::lock()->lockMayBeAvailable("cron") ? "free" : "held";' | tail -1)" == held && $waited -lt 180 ]]; do sleep 5; waited=$((waited+5)); done
  dr cron >/dev/null; local res2; res2=$(after "$a")
  lr=$(php_eval 'print \Drupal::moduleHandler()->moduleExists("purge_processor_lateruntime") ? "on" : "off";' | tail -1); sync_end
  if [[ "$res" == *TCP_HIT* && "$res2" == *TCP_MISS* ]]; then say PASS sync_cron "before any processor ran the edge still served the old page ($res); drush cron (purge_processor_cron) purged it ($res2). Waited ${waited}s for another cron run to finish. Late-runtime processor: $lr"
  else say FAIL sync_cron "before processing: $res; after drush cron: $res2 (waited ${waited}s for the cron lock)"; fi
}

t_sync_wildcard() {
  local out log
  mock_reset
  out=$(dr p:invalidate wildcardpath "synthetic-sync-wild/*" 2>&1 | tail -1)
  log=$(mock_log | grep '"kind": "purge"' | tail -1)
  if [[ "$log" == *'"/synthetic-sync-wild/*"'* ]]; then say PASS sync_wildcard "wildcardpath invalidation reached Front Door: ${log:0:200}"
  else say FAIL sync_wildcard "wildcardpath invalidation ('$out') sent no purge request (purger marks it done without an API call)"; fi
}

# Run by the host wrappers while Redis is stopped (after redis_down).
t_sync_redis_down() {
  local r nid a q
  php_eval '\Drupal::configFactory()->getEditable("system.performance")->set("cache.page.max_age", 300)->save();' >/dev/null
  mock_reset; dr p:queue-empty >/dev/null
  nid=$(first_node); a=$(node_path "$nid"); warm "$a"
  sphp "\$n = \Drupal\node\Entity\Node::load($nid); \$n->setTitle('SYNTHETIC sync redis down ' . mt_rand()); \$n->save();" >/dev/null
  q=$(queued); sync_work; local res; res=$(after "$a"); local t; t=$(php_eval "print \Drupal\node\Entity\Node::load($nid)->label();" | tail -1)
  local body; body=$(curl -s "$EDGE_REAL$a")
  php_eval "\Drupal::configFactory()->getEditable('system.performance')->set('cache.page.max_age', 900)->save();" >/dev/null
  if [[ "$res" == *TCP_MISS/200* && "$body" == *"$t"* ]]; then say PASS sync_redis_down "Redis stopped: save queued ($q), purge sent, edge serves the new title: $res"
  else say FAIL sync_redis_down "Redis stopped: queued '$q', after purge $res, new title shown: $([[ "$body" == *"$t"* ]] && echo yes || echo no)"; fi
}

# --- Runner -------------------------------------------------------------------
if [[ "${1:-}" == "--list" ]]; then printf '%s\n' "${ALL_TESTS[@]}" redis_down sync_redis_down redis_recover redis_hang; exit 0; fi
TESTS=("$@"); [[ ${#TESTS[@]} -eq 0 ]] && TESTS=("${ALL_TESTS[@]}")
# The suite needs production behaviour: switch to prod-like, restore the
# previous mode at exit. PERF_CACHE_AUTO=0 skips (the host wrappers do it once).
PERF_CACHE_PREV=""; PERF_CACHE_SWITCHED=0
perf_cache_restore() {
  if [[ "$PERF_CACHE_SWITCHED" == 1 ]]; then
    bash "$PERF_CACHE_SH" "${PERF_CACHE_PREV:-off}" >/dev/null 2>&1
    echo "# cache mode restored to '${PERF_CACHE_PREV:-off}'"
  fi
}
if [[ "${PERF_CACHE_AUTO:-1}" == 1 ]]; then
  PERF_CACHE_PREV=$(bash "$PERF_CACHE_SH" current 2>/dev/null | tr -d '[:space:]')
  if [[ "$PERF_CACHE_PREV" != prod-like ]]; then
    bash "$PERF_CACHE_SH" prod-like >/dev/null 2>&1; PERF_CACHE_SWITCHED=1
    echo "# cache mode: '${PERF_CACHE_PREV:-off}' -> prod-like for the suite"
  fi
fi
trap perf_cache_restore EXIT
echo "# perf test-local.sh $(date -u +%FT%TZ) BASE_URL=$BASE_URL MOCK=$MOCK PREFIX=$PREFIX"
for t in "${TESTS[@]}"; do
  if declare -F "t_$t" >/dev/null; then "t_$t"; else say FAIL "$t" "unknown test"; fi
done
echo "# summary: $PASSES passed, $FAILS failed, $FINDINGS findings (open decisions, see docs/cache-sync.md)"
exit "$FAILS"
