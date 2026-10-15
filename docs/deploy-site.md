# deploy-site: original, minimal and suggested

The shared base image runs `assets/deploy-site` at container start. With several App Service instances, every instance ran the deploy commands. This page compares the original with two fixed versions, tested side by side in the lab. Pick one to replace `assets/deploy-site` in the base image.

- Files: [`scripts/deploy-site/`](../scripts/deploy-site/)
  - `deploy-site-original` is typed from a photo of the base image file. Check it against the real file before applying a diff.
  - `deploy-site-minimal` and `deploy-site-suggested` are the two fixed versions.
- The same comparison as one page, with full files and highlighted diffs: [deploy-site-compare.html](deploy-site-compare.html). Download it and open it in a browser; GitHub shows the source.
- For a site that doesn't use this base image script: [deploy-once.md](deploy-once.md) (`scripts/deploy-once.sh`, Drupal 8+ only).

## Why the original runs on every instance

1. **The stale check always says "stale".** `elif find "$LOCK_PATH" -mmin +360` uses `find`'s exit code, which is 0 whether or not the file is older than 360 minutes.
   - So whenever the lock file exists, the script logs "Deployment lock file is stale. Continuing deployment." and the lock never blocks.
   - Shown on a fresh file: the `find` condition was true and `find` printed 0 matches.
2. **Check, then create.** Instances that start together all see "no lock file" before any of them runs `touch`.
3. **A failed deploy is recorded as done.** No drush exit code is checked, so the version is written even when `updb` or `cim` fails, and the next start never retries.

## Lab results (2026-10-15, DDEV lab site)

Four simulated instances per test, each with its own `/tmp` and a shared `private/`. The deploy commands were a stand-in that only logged them (`updb` took 3 seconds). The lock, the version file and maintenance mode were real. Raw log: [`compare-run-20261015.txt`](../scripts/deploy-site/tests/compare-run-20261015.txt).

| Test | Original | Minimal | Suggested |
|---|---|---|---|
| S1 4 instances start together | `deploys=4` | `deploys=1` | `deploys=1` |
| S2 1 starts, 3 more 1 s later (mid-deploy) | `deploys=4` | `deploys=1` | `deploys=1` |
| S3 same 4 restart | `deploys=0` | `deploys=0` | `deploys=0` |
| S4 cim fails: version recorded? | `recorded=yes lock_rows=0` | `recorded=yes lock_rows=0` | `recorded=no lock_rows=0` |
| S5 next start after the failure | `deploys=0` | `deploys=0` | `deploys=1` |
| S6 maintenance during updb/cim/cr | `on=0 of 4; after=0` | `on=0 of 4; after=0` | `on=4 of 4; after=0` |

- **Proof the test can fail:** the minimal version with its lock removed gave `deploys=4`.
- **What you want:**
  - 1 deploy when instances start together or close together;
  - 0 on restart;
  - a failed `cim` not recorded, so the next start deploys;
  - maintenance mode on for every step.

## What each version changes

**Minimal** (only the lock):
- **The lock:** the file lock becomes a database lock, one row named `deploy_site` in Drupal's `semaphore` table.
  - The name is the primary key, so only one instance's insert succeeds.
  - Lab: first insert exit 0, second insert exit 1.
- **Expiry:** the lock expires after `DEPLOY_LOCK_TTL` seconds, default 900 (15 minutes), about three times the longest normal deploy (3 to 5 minutes).
- **Re-check:** the version is checked again after the lock is taken.
- **Order:** the "Drupal installed?" check moves ahead of the lock, because the lock needs the database.
- **Maintenance mode:** no change. `drush updb` turns it on only while it has updates to run, and `cim` and the cache clears run with the site live.

**Suggested** (minimal, plus):
- **Failures:** it stops at the first failed drush command and doesn't record the version, so the next start retries.
- **Maintenance mode for the whole deploy** (Drupal 8+):
  - Left on if someone else had turned it on.
  - A marker file in `private/` lets the retry after a killed deploy turn it off.
- **Lock errors:** "another instance has the lock" is told apart from a database error.
- **Cleanup:** it runs on every exit (lock released, maintenance mode off).
- **Logging and `APP_VERSION`:** the instance ID (`WEBSITE_INSTANCE_ID`) is in every log line, and `APP_VERSION` must be set.

## Notes

- **Table prefix:** the SQL uses the table name `semaphore`.
  - A site with a database table prefix needs the prefix added.
  - `drush sqlq --db-prefix` can't be used: it quoted the name as `"semaphore"`, which MySQL rejected with `ERROR 1064`.
- **Shared `private/`:** the version file is in `private/`, which must be shared by all instances, as Drupal's private files are.
- **Not tested:** Drupal 7 sites, a real App Service, and the lock expiring on its own in these scripts.
- **`devel_entity_updates`:** with `DEPLOY_RUN_ENTITY_UPDATES=1`, all three versions enable this development module on the live site.

## Run the comparison again

Copy `scripts/deploy-site/` into the lab root as `deploy-site-test/`, then run `ddev exec bash deploy-site-test/compare-tests.sh`.

## Diff: original → minimal

```diff
--- deploy-site-original
+++ deploy-site-minimal
@@ -3,9 +3,21 @@
 cd "$DRUPAL_DIR" || exit 1
 
 VERSION_PATH="$DRUPAL_DIR/private/deployed-version.txt"
-LOCK_PATH="$DRUPAL_DIR/private/deploy-site.lock"
 LOCK_PATH_LOCAL="/tmp/deploy-site.lock"
+# Database lock: one row in Drupal's semaphore table. The name is the primary
+# key, so only one instance's INSERT can succeed, however close together they
+# start. Expires after DEPLOY_LOCK_TTL seconds if a deploy dies.
+LOCK_TTL="${DEPLOY_LOCK_TTL:-900}"
 
+lock_acquire() {
+  drush sqlq "DELETE FROM semaphore WHERE name = 'deploy_site' AND expire < UNIX_TIMESTAMP()" >/dev/null 2>&1
+  drush sqlq "INSERT INTO semaphore (name, value, expire) VALUES ('deploy_site', 'deploy-site', UNIX_TIMESTAMP() + $LOCK_TTL)" >/dev/null 2>&1
+}
+
+lock_release() {
+  drush sqlq "DELETE FROM semaphore WHERE name = 'deploy_site'" >/dev/null 2>&1
+}
+
 if [ "$DEPLOY_ENABLED" != "1" ]; then
   echo "Deployment is disabled. Skipping deployment."
   exit 0
@@ -22,23 +34,31 @@
   exit 0
 fi
 
-if [ -f "$LOCK_PATH" ]; then
-  if [ -f "$LOCK_PATH_LOCAL" ]; then
-    echo "Local deployment file exists. Continuing deployment."
-  elif find "$LOCK_PATH" -mmin +360; then
-    echo "Deployment lock file is stale. Continuing deployment."
-  else
-    echo "Deployment lock file exists. Skipping deployment commands."
-    exit 0
-  fi
+if ! drush status --field=bootstrap | grep -q Successful; then
+  echo "Drupal is not installed. Skipping deployment."
+  exit 0
 fi
 
-touch "$LOCK_PATH"
+if [ -f "$LOCK_PATH_LOCAL" ]; then
+  echo "Local deployment file exists. Continuing deployment."
+elif ! lock_acquire; then
+  echo "Deployment lock is held by another instance. Skipping deployment commands."
+  exit 0
+fi
+
+# Another instance may have finished between the version check and the lock.
+if [ -f "$VERSION_PATH" ] && [ "$(cat "$VERSION_PATH")" == "$APP_VERSION" ]; then
+  echo "Current version ($APP_VERSION) was deployed by another instance."
+  lock_release
+  exit 0
+fi
+
 touch "$LOCK_PATH_LOCAL"
 echo "Deploying site version $APP_VERSION..."
 
 cleanup() {
-  rm -f "$LOCK_PATH" "$LOCK_PATH_LOCAL"
+  rm -f "$LOCK_PATH_LOCAL"
+  lock_release
   exit 0
 }
 
@@ -55,12 +75,6 @@
 
 trap 'cleanup' SIGTERM SIGINT
 
-if ! drush status --field=bootstrap | grep -q Successful; then
-  echo "Drupal is not installed. Skipping deployment."
-  rm -f "$LOCK_PATH" "$LOCK_PATH_LOCAL"
-  exit 0
-fi
-
 cacheclear
 
 REBUILD_CACHE_AGAIN=0
@@ -96,4 +110,5 @@
 # Update the deployed version
 echo "$APP_VERSION" > "$VERSION_PATH"
 echo "Site version $APP_VERSION deployed."
-rm -f "$LOCK_PATH" "$LOCK_PATH_LOCAL"
+rm -f "$LOCK_PATH_LOCAL"
+lock_release
```

## Diff: original → suggested

```diff
--- deploy-site-original
+++ deploy-site-suggested
@@ -1,90 +1,153 @@
 #!/bin/bash
+# Deploy commands for one site version, run on ONE instance however many start
+# at once. Settings: DEPLOY_ENABLED, DEPLOY_RUN_CR, DEPLOY_RUN_UPDB,
+# DEPLOY_RUN_ENTITY_UPDATES, DEPLOY_RUN_CIM, DRUPAL_VERSION, APP_VERSION,
+# DEPLOY_LOCK_TTL (seconds, default 900).
 
 cd "$DRUPAL_DIR" || exit 1
 
 VERSION_PATH="$DRUPAL_DIR/private/deployed-version.txt"
-LOCK_PATH="$DRUPAL_DIR/private/deploy-site.lock"
+MAINT_PATH="$DRUPAL_DIR/private/deploy-site.maintenance"
 LOCK_PATH_LOCAL="/tmp/deploy-site.lock"
+# Database lock: one row in Drupal's semaphore table. The name is the primary
+# key, so only one instance's INSERT can succeed, however close together they
+# start. Expires after DEPLOY_LOCK_TTL seconds if a deploy dies; keep it longer
+# than the slowest deploy.
+LOCK_TTL="${DEPLOY_LOCK_TTL:-900}"
 
-if [ "$DEPLOY_ENABLED" != "1" ]; then
-  echo "Deployment is disabled. Skipping deployment."
-  exit 0
-fi
+log() {
+  echo "deploy-site [${WEBSITE_INSTANCE_ID:-local}]: $*"
+}
 
-if [ -f "$VERSION_PATH" ]; then
-  DEPLOYED_VERSION=$(cat "$VERSION_PATH")
-else
-  DEPLOYED_VERSION=""
-fi
+lock_acquire() {
+  drush sqlq "DELETE FROM semaphore WHERE name = 'deploy_site' AND expire < UNIX_TIMESTAMP()" >/dev/null 2>&1
+  drush sqlq "INSERT INTO semaphore (name, value, expire) VALUES ('deploy_site', 'deploy-site', UNIX_TIMESTAMP() + $LOCK_TTL)" >/dev/null 2>&1
+}
 
-if [ "$DEPLOYED_VERSION" == "$APP_VERSION" ]; then
-  echo "Current version ($APP_VERSION) already deployed."
-  exit 0
-fi
+lock_held() {
+  [ "$(drush sqlq "SELECT COUNT(*) FROM semaphore WHERE name = 'deploy_site'" 2>/dev/null | grep -E '^[0-9]+$')" = "1" ]
+}
 
-if [ -f "$LOCK_PATH" ]; then
-  if [ -f "$LOCK_PATH_LOCAL" ]; then
-    echo "Local deployment file exists. Continuing deployment."
-  elif find "$LOCK_PATH" -mmin +360; then
-    echo "Deployment lock file is stale. Continuing deployment."
-  else
-    echo "Deployment lock file exists. Skipping deployment commands."
-    exit 0
-  fi
-fi
+lock_release() {
+  drush sqlq "DELETE FROM semaphore WHERE name = 'deploy_site'" >/dev/null 2>&1
+}
 
-touch "$LOCK_PATH"
-touch "$LOCK_PATH_LOCAL"
-echo "Deploying site version $APP_VERSION..."
+deployed_version() {
+  if [ -f "$VERSION_PATH" ]; then cat "$VERSION_PATH"; fi
+}
 
-cleanup() {
-  rm -f "$LOCK_PATH" "$LOCK_PATH_LOCAL"
-  exit 0
+maintenance() {
+  drush state:set system.maintenance_mode "$1" --input-format=integer >/dev/null
 }
 
+maintenance_is_on() {
+  [ "$(drush state:get system.maintenance_mode 2>/dev/null)" = "1" ]
+}
+
+# Runs one drush command; on failure the deploy stops and the version is not recorded.
+run() {
+  if ! drush "$@"; then
+    log "drush $* failed. $APP_VERSION not recorded; restart one instance to retry."
+    exit 1
+  fi
+}
+
 cacheclear() {
   if [ "$DEPLOY_RUN_CR" = "1" ]; then
-    echo "Rebuilding cache..."
+    log "Rebuilding cache..."
     if [ "$DRUPAL_VERSION" = "7" ]; then
-      drush cc all
+      run cc all
     else
-      drush cr
+      run cr
     fi
   fi
 }
 
-trap 'cleanup' SIGTERM SIGINT
+if [ "$DEPLOY_ENABLED" != "1" ]; then
+  log "Deployment is disabled. Skipping deployment."
+  exit 0
+fi
 
+if [ -z "$APP_VERSION" ]; then
+  log "APP_VERSION is not set. Skipping deployment."
+  exit 1
+fi
+
+if [ "$(deployed_version)" == "$APP_VERSION" ]; then
+  log "Current version ($APP_VERSION) already deployed."
+  exit 0
+fi
+
 if ! drush status --field=bootstrap | grep -q Successful; then
-  echo "Drupal is not installed. Skipping deployment."
-  rm -f "$LOCK_PATH" "$LOCK_PATH_LOCAL"
+  log "Drupal is not installed. Skipping deployment."
   exit 0
 fi
 
+if [ -f "$LOCK_PATH_LOCAL" ]; then
+  log "Local deployment file exists. Continuing deployment."
+elif ! lock_acquire; then
+  if lock_held; then
+    log "Deployment lock is held by another instance. Skipping deployment commands."
+    exit 0
+  fi
+  log "Could not take the deployment lock (database error). Skipping deployment."
+  exit 1
+fi
+
+cleanup() {
+  status=$?
+  if [ -f "$MAINT_PATH" ]; then
+    maintenance 0 && rm -f "$MAINT_PATH"
+  fi
+  rm -f "$LOCK_PATH_LOCAL"
+  lock_release
+  exit "$status"
+}
+trap cleanup EXIT
+trap 'exit 143' SIGTERM
+trap 'exit 130' SIGINT
+
+# Another instance may have finished between the version check and the lock.
+if [ "$(deployed_version)" == "$APP_VERSION" ]; then
+  log "Current version ($APP_VERSION) was deployed by another instance."
+  exit 0
+fi
+
+touch "$LOCK_PATH_LOCAL"
+log "Deploying site version $APP_VERSION..."
+
+# Maintenance mode for the whole deploy (updb alone sets it only while it has
+# updates to run). Left alone if someone else turned it on; MAINT_PATH marks it
+# as ours, so a retry after a killed deploy turns it off. Drupal 8+ only.
+if [ "$DRUPAL_VERSION" != "7" ] && { [ -f "$MAINT_PATH" ] || ! maintenance_is_on; }; then
+  touch "$MAINT_PATH"
+  maintenance 1 || { log "Could not turn on maintenance mode."; exit 1; }
+fi
+
 cacheclear
 
 REBUILD_CACHE_AGAIN=0
 
 if [ "$DEPLOY_RUN_UPDB" = "1" ]; then
-  echo "Running database updates..."
-  drush updb -y
+  log "Running database updates..."
+  run updb -y
   REBUILD_CACHE_AGAIN=1
 fi
 
 if [ "$DRUPAL_VERSION" = "7" ]; then
-  echo "Skipping D8+ deployment steps..."
+  log "Skipping D8+ deployment steps..."
 else
   if [ "$DEPLOY_RUN_ENTITY_UPDATES" = "1" ]; then
-    echo "Running entity updates..."
-    drush en devel_entity_updates -y
-    drush entup -y
+    log "Running entity updates..."
+    run en devel_entity_updates -y
+    run entup -y
     cacheclear
     REBUILD_CACHE_AGAIN=1
   fi
 
   if [ "$DEPLOY_RUN_CIM" = "1" ]; then
-    echo "Importing configuration..."
-    drush cim -y
+    log "Importing configuration..."
+    run cim -y
     REBUILD_CACHE_AGAIN=1
   fi
 
@@ -93,7 +156,7 @@
   fi
 fi
 
-# Update the deployed version
+# Update the deployed version. The EXIT trap turns maintenance mode off and
+# releases the lock.
 echo "$APP_VERSION" > "$VERSION_PATH"
-echo "Site version $APP_VERSION deployed."
-rm -f "$LOCK_PATH" "$LOCK_PATH_LOCAL"
+log "Site version $APP_VERSION deployed."
```
