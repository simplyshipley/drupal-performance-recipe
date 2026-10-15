#!/usr/bin/env bash
# Run the deploy drush commands (updb, cim, cr, then cron) on ONE instance per
# image version, however many App Service instances start at the same moment.
# Call it from the image entrypoint in place of the drush lines
# (docs/deploy-once.md). Run from the Drupal project root.
#
# Who deploys: the instance that takes Drupal's persistent lock. The lock is a
# row in the database `semaphore` table and its name is the primary key, so
# only one insert can succeed; every other instance skips and starts serving.
# A file lock (LOCK_PATH) can't do this: each instance has its own disk, and a
# check-then-create on a shared disk lets every instance pass the check.
#
#   APP_VERSION      required, unique per image (ENV APP_VERSION=$BUILD_VERSION)
#   DEPLOY_LOCK_TTL  seconds the lock lasts if the deploying instance dies
#                    mid-deploy (default 1800); longer than your slowest deploy
#   DRUSH            default vendor/bin/drush
#
# Exit 0: deployed, already deployed, or another instance is deploying.
# Exit non-zero: APP_VERSION unset, the lock can't be read, or a drush command
# failed (the version is not recorded, so the next start retries).
set -uo pipefail
DRUSH="${DRUSH:-vendor/bin/drush}"
VERSION="${APP_VERSION:-}"
TTL="${DEPLOY_LOCK_TTL:-1800}"
LOCK=deploy_once
KEY=deploy_once.version
MAINT_KEY=deploy_once.maintenance

log() { printf 'deploy-once %s [%s]: %s\n' "$(date -u +%FT%TZ)" "${WEBSITE_INSTANCE_ID:-local}" "$*"; }
deployed() { [[ "$($DRUSH state:get "$KEY" 2>/dev/null)" == "$VERSION" ]]; }
# Every persistent lock has the same owner id, so only the winner may call this.
release() { $DRUSH php:eval "\Drupal::service('lock.persistent')->release('$LOCK');" >/dev/null 2>&1; }

[[ -n "$VERSION" ]] || { log "APP_VERSION is not set; not deploying"; exit 1; }
if deployed; then log "$VERSION already deployed; skipping"; exit 0; fi

# stdout only: a warning on stderr must not hide a win (the lock outlives this process).
claim=$($DRUSH php:eval "print PHP_EOL . (\Drupal::service('lock.persistent')->acquire('$LOCK', $TTL) ? 'won' : 'lost') . PHP_EOL;" 2>/dev/null)
if grep -qx won <<<"$claim"; then
  trap release EXIT
elif grep -qx lost <<<"$claim"; then
  log "another instance is deploying; skipping"; exit 0
else
  log "could not take the lock (check the database connection)"; exit 1
fi

# Another instance may have finished between our first check and the lock.
if deployed; then log "$VERSION was deployed by another instance; skipping"; exit 0; fi

log "deploying $VERSION"
# Maintenance mode for the whole deploy: updb alone sets it only while it has
# updates to run, and cim/cr would otherwise run with the site live. Turned off
# again even if a command fails. Left on if someone else turned it on; MAINT_KEY
# marks it as ours, so a retry after a killed deploy still turns it off.
maint_off() { $DRUSH maint:set 0 && $DRUSH state:delete "$MAINT_KEY"; }
ours=0
if [[ "$($DRUSH maint:get 2>/dev/null | tail -n 1)" != 1 || "$($DRUSH state:get "$MAINT_KEY" 2>/dev/null)" == 1 ]]; then
  ours=1
  for cmd in "state:set $MAINT_KEY 1 --input-format=integer" "maint:set 1"; do
    # shellcheck disable=SC2086
    $DRUSH $cmd || { rc=$?; log "could not turn on maintenance mode (exit $rc)"; exit "$rc"; }
  done
  trap 'maint_off >/dev/null 2>&1; release' EXIT
fi
for cmd in "updatedb -y" "config:import -y" "cache:rebuild"; do
  # shellcheck disable=SC2086
  $DRUSH $cmd || { rc=$?; log "drush $cmd failed (exit $rc); $VERSION not recorded"; exit "$rc"; }
done
[[ "$ours" == 0 ]] || maint_off || log "could not turn off maintenance mode: run drush maint:set 0"
# string: auto format would store 1.10 as the number 1.1, and it would never match again.
$DRUSH state:set "$KEY" "$VERSION" --input-format=string || { rc=$?; log "could not record $VERSION (exit $rc)"; exit "$rc"; }
release; trap - EXIT
log "deployed $VERSION"

# Cron has its own lock and isn't part of the deploy: a failure is logged only.
$DRUSH cron || log "drush cron exited $?; the deploy itself succeeded"
