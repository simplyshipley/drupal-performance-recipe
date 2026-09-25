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

BASE_URL="${BASE_URL:-http://localhost}"
MOCK="${PERF_MOCK_URL:-http://mock-afd:8080}"
DRUSH="${DRUSH:-vendor/bin/drush}"
PREFIX="${REDIS_PREFIX:-}"
FAILS=0
PASSES=0
ALL_TESTS=(redis_bins redis_in_use drush_cr tag_invalidation eviction purge_queue_fills purge_send purge_send_gov purge_retry
  fast404_static fast404_path themed_404 headers_anon headers_auth cache_4xx)

say() { printf '%s %s: %s\n' "$1" "$2" "$3"; case "$1" in FAIL) FAILS=$((FAILS+1));; PASS) PASSES=$((PASSES+1));; esac; }
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
  trap "redis_php config_set maxmemory '$orig_mem' >/dev/null; redis_php config_set maxmemory-policy '$orig_pol' >/dev/null" EXIT INT TERM
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
  trap - EXIT INT TERM
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

# --- Runner -------------------------------------------------------------------
if [[ "${1:-}" == "--list" ]]; then printf '%s\n' "${ALL_TESTS[@]}" redis_down redis_recover; exit 0; fi
TESTS=("$@"); [[ ${#TESTS[@]} -eq 0 ]] && TESTS=("${ALL_TESTS[@]}")
echo "# perf test-local.sh $(date -u +%FT%TZ) BASE_URL=$BASE_URL MOCK=$MOCK PREFIX=$PREFIX"
for t in "${TESTS[@]}"; do
  if declare -F "t_$t" >/dev/null; then "t_$t"; else say FAIL "$t" "unknown test"; fi
done
echo "# summary: $PASSES passed, $FAILS failed"
exit "$FAILS"
