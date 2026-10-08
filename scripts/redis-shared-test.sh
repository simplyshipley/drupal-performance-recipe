#!/usr/bin/env bash
# Shared-Redis isolation test (redis/docs/redis-sharing.md). Runs INSIDE the web
# container from the project root. Two "sites" = two drush processes of this
# Drupal with different REDIS_USERNAME / REDIS_PREFIX on one Redis started
# from redis/image in shared mode (users sitea, siteb, admin).
#
#   SHARED_HOST     Redis host (default redis-shared-lab)
#   SHARED_NEGATIVE=1  fail-proof: site B uses site A's user and prefix, so
#                   A's drush cr clears B's cache and the test FAILS.
# Passwords are SYNTHETIC lab values. Simulation limit: both "sites" share
# one database here, so only Redis-held data is compared (a cache item
# without tags); real sites also have separate databases.
set -uo pipefail
DRUSH="${DRUSH:-vendor/bin/drush}"
H="${SHARED_HOST:-redis-shared-lab}"
FAILS=0
say() { printf '%s %s: %s\n' "$1" "$2" "$3"; [[ "$1" == FAIL ]] && FAILS=$((FAILS+1)); return 0; }
site() { # site a|b <drush args...>
  local s="$1"; shift
  if [[ "$s" == b && "${SHARED_NEGATIVE:-0}" != 1 ]]; then
    env REDIS_HOST="$H" REDIS_PORT=6379 REDIS_USERNAME=siteb REDIS_PASSWORD=synthetic-b REDIS_PREFIX=siteb $DRUSH "$@" 2>&1
  else
    env REDIS_HOST="$H" REDIS_PORT=6379 REDIS_USERNAME=sitea REDIS_PASSWORD=synthetic-a REDIS_PREFIX=sitea $DRUSH "$@" 2>&1
  fi
}
# raw() evaluates PHP snippets written in this script only (never input).
raw() { # raw <user> <pass> <php using $r>
  php -r '$r = new Redis(); $r->connect($argv[1], 6379, 2.0); $r->auth([$argv[2], $argv[3]]); try { eval($argv[4]); } catch (Throwable $e) { echo get_class($e) . ": " . $e->getMessage(); }' -- "$H" "$1" "$2" "$3" 2>&1
}

echo "# redis-shared-test $(date -u +%FT%TZ) host=$H negative=${SHARED_NEGATIVE:-0}"
cls=$(site a php:eval 'print (new \ReflectionClass(\Drupal::cache("data")))->getShortName();' | tail -1)
site a php:eval '\Drupal::cache("data")->set("perf_shared_probe", "A");' >/dev/null
site b php:eval '\Drupal::cache("data")->set("perf_shared_probe", "B");' >/dev/null
na=$(raw admin synthetic-admin '$it = NULL; $n = 0; while (($k = $r->scan($it, "sitea:*", 1000)) !== FALSE) { $n += count($k); } echo $n;')
nb=$(raw admin synthetic-admin '$it = NULL; $n = 0; while (($k = $r->scan($it, "siteb:*", 1000)) !== FALSE) { $n += count($k); } echo $n;')
bkey=$(raw admin synthetic-admin '$it = NULL; while (($k = $r->scan($it, "siteb:data:*", 100)) !== FALSE) { if ($k) { echo $k[0]; break; } }')
if [[ "$cls" == FailOpenCacheBackend && "${na:-0}" -gt 0 && "${nb:-0}" -gt 0 ]]; then
  say PASS shared_both_write "both sites write through their own ACL user (data bin: $cls); keys sitea:* $na, siteb:* $nb"
else say FAIL shared_both_write "backend $cls, sitea keys $na, siteb keys $nb"; fi

if [[ "${SHARED_NEGATIVE:-0}" != 1 ]]; then
  g=$(raw sitea synthetic-a "var_export(\$r->get('$bkey'));"); d=$(raw sitea synthetic-a "var_export(\$r->del('$bkey'));")
  f=$(raw sitea synthetic-a 'var_export($r->flushAll());'); s=$(raw sitea synthetic-a '$it = NULL; var_export($r->scan($it, "*", 10)); echo " ", $r->getLastError();')
  if [[ "$g" == *NOPERM* && "$d" == *NOPERM* && "$f" == *NOPERM* && "$s" == *NOPERM* ]]; then
    say PASS shared_cross_access "site A's user on site B's key $bkey: GET -> ${g:0:60}; DEL -> ${d:0:60}; FLUSHALL -> ${f:0:60}; SCAN -> ${s:0:40}"
  else say FAIL shared_cross_access "GET='$g' DEL='$d' FLUSHALL='$f' SCAN='$s'"; fi
fi

site a cr >/dev/null
va=$(site a php:eval '$c = \Drupal::cache("data")->get("perf_shared_probe"); print $c ? $c->data : "MISS";' | tail -1)
vb=$(site b php:eval '$c = \Drupal::cache("data")->get("perf_shared_probe"); print $c ? $c->data : "MISS";' | tail -1)
nb2=$(raw admin synthetic-admin '$it = NULL; $n = 0; while (($k = $r->scan($it, "siteb:*", 1000)) !== FALSE) { $n += count($k); } echo $n;')
if [[ "$va" == MISS && "$vb" == B ]]; then
  say PASS shared_cr_isolated "after site A's drush cr: A's probe $va, B's probe still '$vb' (siteb:* keys $nb -> $nb2)"
else say FAIL shared_cr_isolated "after site A's drush cr: A's probe '$va' (want MISS), B's probe '$vb' (want B)"; fi
echo "# summary: $FAILS failed"
exit "$FAILS"
