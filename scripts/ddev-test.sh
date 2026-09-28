#!/usr/bin/env bash
# Host-side wrapper for DDEV. Runs scripts/test-local.sh in the web container,
# then (unless tests were named) the Redis-down test: stops ONLY this
# project's Redis container, runs the check, starts Redis again.
# Output is printed and saved to $PERF_OUT (default ./perf-test-ddev.log).
set -uo pipefail
cd "${DDEV_APPROOT:-$(pwd)}"
NAME=$(ddev describe -j | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["raw"]["name"];' 2>/dev/null || true)
[[ -z "$NAME" ]] && NAME=$(awk -F': *' '/^name:/{print $2; exit}' .ddev/config.yaml)
OUT="${PERF_OUT:-perf-test-ddev.log}"
SCRIPT=recipes/drupal-performance-recipe/scripts/test-local.sh
MODE=recipes/drupal-performance-recipe/scripts/perf-cache.sh
fails=0
{
  # Tests need production behaviour: prod-like once for the whole run, then
  # the previous cache mode is restored (docs/cache-modes.md).
  # Wait for the edge (if this project has one) before testing.
  if docker ps -a --format '{{.Names}}' | grep -qx "ddev-$NAME-edge"; then
    if ddev exec 'for i in $(seq 1 60); do curl -sf -m 2 -o /dev/null http://edge:8099/__edge/health && exit 0; sleep 1; done; exit 1' >/dev/null 2>&1; then
      echo "# edge healthy"
    else
      echo "# edge NOT healthy after 60 s:"; docker ps -a --filter "name=ddev-$NAME-edge" --format '{{.Names}} {{.Status}}'; docker logs --tail 5 "ddev-$NAME-edge" 2>&1
    fi
  fi
  PREV=$(ddev exec "bash $MODE current" 2>/dev/null | tr -d '[:space:]')
  echo "# cache mode: '${PREV:-off}' -> prod-like for the suite"
  ddev exec "bash $MODE prod-like" >/dev/null
  ddev exec "PERF_CACHE_AUTO=0 BASE_URL=http://localhost bash $SCRIPT $*"; fails=$?
  if [[ $# -eq 0 ]]; then
    echo "# redis_down: stopping ddev-$NAME-redis"
    docker stop "ddev-$NAME-redis" >/dev/null
    ddev exec "PERF_CACHE_AUTO=0 BASE_URL=http://localhost bash $SCRIPT redis_down"; fails=$((fails + $?))
    ddev exec "PERF_CACHE_AUTO=0 BASE_URL=http://localhost bash $SCRIPT sync_redis_down"; fails=$((fails + $?))
    docker start "ddev-$NAME-redis" >/dev/null
    sleep 2
    ddev exec "PERF_CACHE_AUTO=0 BASE_URL=http://localhost bash $SCRIPT redis_recover"; fails=$((fails + $?))
    echo "# redis_hang: pausing ddev-$NAME-redis (TCP accepted, no answers)"
    docker pause "ddev-$NAME-redis" >/dev/null
    ddev exec "PERF_CACHE_AUTO=0 BASE_URL=http://localhost bash $SCRIPT redis_hang"; fails=$((fails + $?))
    docker unpause "ddev-$NAME-redis" >/dev/null
    echo "# after restarting Redis: home HTTP $(ddev exec "curl -s -o /dev/null -w '%{http_code}' http://localhost/")"
  fi
  ddev exec "bash $MODE ${PREV:-off}" >/dev/null
  echo "# cache mode restored to '${PREV:-off}'"
  echo "# total FAIL lines: $fails"
} 2>&1 | tee "$OUT"
exit "$(grep -c '^FAIL' "$OUT")"
