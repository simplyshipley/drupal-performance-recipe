#!/usr/bin/env bash
# Runs the original, minimal and suggested deploy-site side by side in the DDEV
# lab web container. Copy scripts/deploy-site/ (the three versions plus
# tests/compare-tests.sh and tests/bin/) into the lab root as deploy-site-test/,
# then: ddev exec bash deploy-site-test/compare-tests.sh
# Each "instance" is a copy of the script with its own /tmp lock path (separate
# containers have separate /tmp). private/ is shared, as on App Service.
# drush on PATH is a stand-in: cr/cc/updb/en/entup/cim are logged (updb takes
# 3 s); status, sqlq and state go to the real drush. Lab only.
set -u
cd /var/www/html
T=/var/www/html/deploy-site-test
REAL=/var/www/html/vendor/bin/drush
export PATH="$T/bin:$PATH" DRUPAL_DIR=/var/www/html DEPLOY_ENABLED=1 DEPLOY_RUN_CR=1 DEPLOY_RUN_UPDB=1 DEPLOY_RUN_CIM=1 DRUPAL_VERSION=11
mkdir -p /var/www/html/private
VP=/var/www/html/private/deployed-version.txt

reset() { : > "$VP"; $REAL sqlq "DELETE FROM semaphore WHERE name='deploy_site'" >/dev/null 2>&1; mv -f /var/www/html/private/deploy-site.lock /var/www/html/private/deploy-site.lock.gone 2>/dev/null; $REAL state:set system.maintenance_mode 0 --input-format=integer; }
lockrows() { $REAL sqlq "SELECT COUNT(*) FROM semaphore WHERE name='deploy_site'" 2>/dev/null | grep -E '^[0-9]+$' | tail -n 1; }
# start <variant> <n> <version> [stagger-seconds]
start() {
  local v=$1 n=$2 ver=$3 gap=${4:-0} run; run=$(mktemp -d)
  export CALLS=$run/calls; : > "$CALLS"
  for i in $(seq 1 "$n"); do
    mkdir -p "$run/tmp$i"
    sed "s|/tmp/deploy-site.lock|$run/tmp$i/deploy-site.lock|" "$T/deploy-site-$v" > "$run/script$i"
    ( APP_VERSION=$ver WEBSITE_INSTANCE_ID=inst$i bash "$run/script$i"; echo "exit=$?" ) > "$run/out$i" 2>&1 &
    [[ "$i" == 1 && "$gap" != 0 ]] && sleep "$gap"
  done
  wait
  DEPLOYS=$(grep -c 'FAKE updb' "$CALLS")
}
row() { printf '%-10s %-55s %s\n' "$1" "$2" "$3"; }

echo "# deploy-site comparison $(date -u +%FT%TZ)"
for v in original minimal suggested; do
  reset; start "$v" 4 r1;      row "$v" "S1 4 instances start together" "deploys=$DEPLOYS"
  reset; start "$v" 4 r2 1;    row "$v" "S2 1 starts, 3 more 1 s later (mid-deploy)" "deploys=$DEPLOYS"
  start "$v" 4 r2;             row "$v" "S3 same 4 restart" "deploys=$DEPLOYS"
  reset; FAIL_ON=cim start "$v" 1 r3
  row "$v" "S4 cim fails: version recorded?" "recorded=$([[ "$(cat "$VP")" == r3 ]] && echo yes || echo no) lock_rows=$(lockrows)"
  start "$v" 1 r3;             row "$v" "S5 next start after the failure" "deploys=$DEPLOYS"
  reset; start "$v" 1 r4
  row "$v" "S6 maintenance during updb/cim/cr" "on=$(grep -cE 'FAKE (updb|cim|cr) .*maint=1' "$CALLS") of $(grep -cE 'FAKE (updb|cim|cr)' "$CALLS"); after=$($REAL state:get system.maintenance_mode)"
done
sed 's/^elif ! lock_acquire; then/elif false; then/' "$T/deploy-site-minimal" > "$T/deploy-site-mutant"
grep -q '^elif false; then' "$T/deploy-site-mutant" || echo "mutant not created"
reset; start mutant 4 r5;      row "mutant" "S1 minimal with the lock removed (proof the test can fail)" "deploys=$DEPLOYS"
reset
