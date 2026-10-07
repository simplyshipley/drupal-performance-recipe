#!/usr/bin/env bash
# Host-side wrapper for Lando (run from the app root). Runs the tests in
# appserver, then the Redis-down test: stops ONLY this app's "cache" container,
# runs the check, starts it again. Output saved to $PERF_OUT
# (default ./perf-test-lando.log). Settings: perf-test.env in the app root (read
# by test-local.sh); BASE_URL or EDGE_CHALLENGE_MISSING set here win over it.
set -uo pipefail
OUT="${PERF_OUT:-perf-test-lando.log}"
APP=$(awk -F': *' '/^name:/{print $2; exit}' .lando.yml)
PROJECT=$(printf '%s' "$APP" | tr -d '_.-' | tr '[:upper:]' '[:lower:]')
CACHE=$(docker ps -a --filter "label=com.docker.compose.project=$PROJECT" --filter "label=com.docker.compose.service=cache" --format '{{.Names}}' | head -1)
SCRIPT=recipes/drupal-performance-recipe/scripts/test-local.sh
MODE=recipes/drupal-performance-recipe/scripts/perf-cache.sh
run_in() { lando ssh -s appserver -c "cd /app && PERF_CACHE_AUTO=0 ${EDGE_CHALLENGE_MISSING:+EDGE_CHALLENGE_MISSING=$EDGE_CHALLENGE_MISSING} ${BASE_URL:+BASE_URL=$BASE_URL} bash $SCRIPT $*"; }
mode_in() { lando ssh -s appserver -c "cd /app && bash $MODE $*"; }
{
  # The lab must match the repo before anything runs (scripts/lab-preflight.sh,
  # run from the repo mount so its checks are always the current ones).
  if ! lando ssh -s appserver -c "cd /app && bash /opt/perf-recipe-src/scripts/lab-preflight.sh"; then
    echo "FAIL preflight: the lab does not match the repo; no test was run."
    exit 1
  fi
  # Tests need production behaviour: prod-like once for the whole run, then
  # the previous cache mode is restored (docs/cache-modes.md).
  # Wait for the edge (if this app has one) before testing.
  EDGE_C=$(docker ps -a --filter "label=com.docker.compose.project=$PROJECT" --filter "label=com.docker.compose.service=edge" --format '{{.Names}}' | head -1)
  if [[ -n "$EDGE_C" ]]; then
    if lando ssh -s appserver -c 'for i in $(seq 1 60); do curl -sf -m 2 -o /dev/null http://edge:8099/__edge/health && exit 0; sleep 1; done; exit 1' >/dev/null 2>&1; then
      echo "# edge healthy"
    else
      echo "# edge NOT healthy after 60 s:"; docker ps -a --filter "name=$EDGE_C" --format '{{.Names}} {{.Status}}'; docker logs --tail 5 "$EDGE_C" 2>&1
    fi
  fi
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
    echo "# after restarting Redis: home HTTP $(run_in --home)"
  fi
  mode_in "${PREV:-off}" >/dev/null
  echo "# cache mode restored to '${PREV:-off}'"
} 2>&1 | tee "$OUT"
exit "$(grep -c '^FAIL' "$OUT")"
