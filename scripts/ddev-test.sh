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
fails=0
{
  ddev exec "BASE_URL=http://localhost bash $SCRIPT $*"; fails=$?
  if [[ $# -eq 0 ]]; then
    echo "# redis_down: stopping ddev-$NAME-redis"
    docker stop "ddev-$NAME-redis" >/dev/null
    ddev exec "BASE_URL=http://localhost bash $SCRIPT redis_down"; fails=$((fails + $?))
    docker start "ddev-$NAME-redis" >/dev/null
    sleep 2
    echo "# after restarting Redis: home HTTP $(ddev exec "curl -s -o /dev/null -w '%{http_code}' http://localhost/")"
  fi
  echo "# total FAIL lines: $fails"
} 2>&1 | tee "$OUT"
exit "$(grep -c '^FAIL' "$OUT")"
