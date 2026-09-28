#!/usr/bin/env bash
# Host-side wrapper for Lando (run from the app root). Runs the tests in
# appserver, then the Redis-down test: stops ONLY this app's "cache" container,
# runs the check, starts it again. Output saved to $PERF_OUT
# (default ./perf-test-lando.log). Uses LANDO_PROXY=OFF (see docs).
set -uo pipefail
export LANDO_PROXY=OFF
OUT="${PERF_OUT:-perf-test-lando.log}"
APP=$(awk -F': *' '/^name:/{print $2; exit}' .lando.yml)
PROJECT=$(printf '%s' "$APP" | tr -d '_.-' | tr '[:upper:]' '[:lower:]')
CACHE=$(docker ps -a --filter "label=com.docker.compose.project=$PROJECT" --filter "label=com.docker.compose.service=cache" --format '{{.Names}}' | head -1)
SCRIPT=recipes/drupal-performance-recipe/scripts/test-local.sh
MODE=recipes/drupal-performance-recipe/scripts/perf-cache.sh
run_in() { lando ssh -s appserver -c "cd /app && PERF_CACHE_AUTO=0 BASE_URL=http://appserver_nginx bash $SCRIPT $*"; }
mode_in() { lando ssh -s appserver -c "cd /app && BASE_URL=http://appserver_nginx bash $MODE $*"; }
{
  # Tests need production behaviour: prod-like once for the whole run, then
  # the previous cache mode is restored (docs/cache-modes.md).
  PREV=$(mode_in current | tr -d '[:space:]')
  echo "# cache mode: '${PREV:-off}' -> prod-like for the suite"
  mode_in prod-like >/dev/null
  run_in "$@"
  if [[ $# -eq 0 ]]; then
    echo "# redis_down: stopping $CACHE"
    docker stop "$CACHE" >/dev/null
    run_in redis_down
    run_in sync_redis_down
    docker start "$CACHE" >/dev/null
    sleep 2
    run_in redis_recover
    echo "# redis_hang: pausing $CACHE (TCP accepted, no answers)"
    docker pause "$CACHE" >/dev/null
    run_in redis_hang
    docker unpause "$CACHE" >/dev/null
    echo "# after restarting Redis: home HTTP $(lando ssh -s appserver -c "curl -s -o /dev/null -w '%{http_code}' http://appserver_nginx/")"
  fi
  mode_in "${PREV:-off}" >/dev/null
  echo "# cache mode restored to '${PREV:-off}'"
} 2>&1 | tee "$OUT"
exit "$(grep -c '^FAIL' "$OUT")"
