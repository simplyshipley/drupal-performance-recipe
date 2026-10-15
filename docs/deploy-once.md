# Run the deploy commands on one instance

When an App Service runs several instances, a new image restarts all of them, and the entrypoint's drush commands (`updb`, `cim`, `cr`, `cron`) run once per instance. [`scripts/deploy-once.sh`](../scripts/deploy-once.sh) makes exactly one instance run them for each image version.

## Why a file lock (`LOCK_PATH`) doesn't work

- Every instance runs the container. Learn: "When the app runs, it runs on all the VM instances configured in the App Service plan" ([hosting plans](https://learn.microsoft.com/en-us/azure/app-service/overview-hosting-plans)). The image webhook "causes your App Service app to restart and run docker pull" ([custom container CI/CD](https://learn.microsoft.com/en-us/azure/app-service/deploy-ci-cd-custom-container)).
- A lock file on the container's own disk is a different file on each instance, so every instance gets "its" lock.
- On a shared disk, "check the file, then create it" still lets every instance pass the check when they start together.
- Running them 4 times matters:
  - We found no lock for `updatedb` in Drush's command or core's update code, so the update functions can run at the same time.
  - `config:import` has a lock: a second copy fails with "config_importer is already importing" (core `ConfigImporter.php`).

## How the script decides

1. **Already deployed?** It reads the version it last recorded (`drush state:get deploy_once.version`). If that equals `APP_VERSION`, it skips. That covers later restarts and scale-out.
2. **Take the lock.** It takes Drupal's persistent lock `deploy_once` (core service `lock.persistent`).
   - The lock is a row in the database `semaphore` table, and the name is the primary key, so only one instance's insert can succeed, however close together they start.
   - The others log "another instance is deploying; skipping" and start serving.
3. **Check again.** If another instance finished between step 1 and step 2, the winner skips.
4. **Deploy.** It turns maintenance mode on, then runs `updatedb -y`, `config:import -y` and `cache:rebuild`. Then it turns maintenance mode off, records the version, releases the lock and runs `cron`.
   - **Why maintenance mode:** `updb` turns it on only while it has updates to run, and `cim` and `cr` would otherwise run with the site live.
     - Maintenance mode is stored in the shared database, so every instance, old or new, shows the maintenance page during the deploy.
     - In the lab that page was `503` with `cache-control: must-revalidate, no-cache, private`.
   - **If maintenance mode was already on** (someone turned it on by hand), the script leaves it on.
   - **If a command fails,** the deploy stops, maintenance mode goes off, the lock is released and nothing is recorded.
     - Nothing retries on its own: the other instances already skipped.
     - Fix the cause, then restart one instance.
   - **If cron fails,** that's only logged.

## Change the entrypoint

1. Add the script to the base image, for example `COPY deploy-once.sh /usr/local/bin/drupal-deploy-once`.
2. In the entrypoint, remove the `LOCK_PATH` block and its drush lines.
3. Put this in their place, before the web server starts:

```bash
# Deploy commands: one instance per image version (docs/deploy-once.md).
( cd "${DRUPAL_DIR}" && drupal-deploy-once ) &
```

- **Why in the background:** the containers serve right away; during the deploy they all show the maintenance page (step 4). Learn says App Service waits `WEBSITES_CONTAINER_START_TIME_LIMIT` seconds for a container to become ready, and "The default value is 230" ([app settings reference](https://learn.microsoft.com/en-us/azure/app-service/reference-app-settings)).
  - In the background, the web server starts at once, as it already does on the instances that skip. A long `updb` then can't hold up the container start.
- **`APP_VERSION`:**
  - Required. The OIG site copy's Dockerfile sets `ENV APP_VERSION=$BUILD_VERSION`, and without it the script refuses to deploy.
  - Must be unique per image. A rebuilt image that reuses a version is treated as already deployed.
    - The OIG site copy's `bin/docker-build` accepts `latest` as the version. Every `latest` build gets the same `APP_VERSION`, so only the first one deploys. Build with a release number.
  - Every site on the shared base image needs it. A site image without `APP_VERSION` gets no deploy: the script exits 1, and the web server still starts.
- **`DEPLOY_LOCK_TTL`** (default 1800 seconds) must be longer than your slowest deploy.
  - If a deploy runs past it, the lock expires, and an instance that restarts can start a second deploy at the same time.
  - If the deploying instance dies, the lock stays held until the TTL runs out.
- **`DRUSH`** defaults to `vendor/bin/drush`.
- **Single-instance sites** behave as today. Their one instance takes the lock unless a dead deploy still holds it.
- **Keep the lock in the database.** The recipe already does this (`settings.performance.php`). Don't move `lock.persistent` to Redis.

## Cron

- The script runs cron once after a deploy. Your regular cron still comes from a scheduler, with `automated_cron` off ([cache-sync.md](cache-sync.md)).

## Check it after a release

In the App Service log stream, look for:

- One instance with `deploying <version>` then `deployed <version>`.
- Every other instance with `another instance is deploying; skipping`, `was deployed by another instance; skipping` or `already deployed; skipping`.
- Then `drush state:get deploy_once.version` prints the new version.

## If a deploy fails part way: the lock

| What happened | Lock afterwards | What to do |
|---|---|---|
| A drush command failed | Released by the script | Fix the cause, restart one instance |
| The script was stopped (SIGTERM) | Released by the script | Restart one instance |
| The script was killed (SIGKILL), or the container or instance went away mid-deploy | **Still held** until `DEPLOY_LOCK_TTL` runs out, and **the site stays in maintenance mode** | Break it (below), or wait for the TTL, then restart one instance. The retry turns maintenance mode off |

The first three rows were tested in the lab. For the third, assume any hard stop leaves the lock held: the lab can't show how App Service stops a background process.

**Is it held?** From a shell on any instance (for example App Service SSH):

```bash
drush sql:query "SELECT name, expire, expire > UNIX_TIMESTAMP() AS held FROM semaphore WHERE name='deploy_once'"
```

- No row: not held.
- `held` 1: held until `expire`, a Unix time; convert it with `date -d @<expire>`.

**Break it:**

1. Make sure no instance is still deploying. The log stream shows `deploying <version>` with no `deployed` or `failed` line after it.
2. Release the lock: `drush php:eval "\Drupal::service('lock.persistent')->release('deploy_once');"`
3. Restart one instance. It finds the version not recorded, runs all three commands again, and turns maintenance mode off.
4. To bring the site back before the retry, run `drush maint:set 0`.

Breaking the lock while a deploy is still running lets a second instance deploy at the same time. That's the case this script exists to prevent.

## Troubleshooting

| Symptom | Fix |
|---|---|
| Every instance skips and the version never changes | A deploying instance died and its lock is still held. Wait for `DEPLOY_LOCK_TTL`, or release it: `drush php:eval "\Drupal::service('lock.persistent')->release('deploy_once');"`, then restart one instance |
| `drush config:import -y failed` in the log | Fix the cause, then restart one instance. It deploys again because the version wasn't recorded |
| A database was copied from another environment and the deploy is skipped | The copy brought that environment's recorded version. Run `drush state:delete deploy_once.version` and restart one instance |
| `APP_VERSION is not set; not deploying` | Build the image with `--build-arg BUILD_VERSION=<version>` |
| `could not take the lock` | Drush couldn't reach the database. Check the database settings, then restart one instance |

## Lab tests (2026-10-15, DDEV lab site)

All 34 checks passed. In the lab, `updb`, `cim`, `cr` and `cron` were replaced by a stand-in (`updatedb` took 3 seconds), because a real `cim` would have changed the lab's config. The lock and the version calls were real Drupal calls against the lab database.

- **Covered:**
  - 4 and 8 instances starting together: 1 deploy each time.
  - A restart: 0 deploys.
  - A failed `cim`: the next start deploys.
  - A dead instance's lock: everyone skips until the lock is released or its TTL expires on its own.
  - A late instance, version `1.10`, a warning printed after the win, and `APP_VERSION` unset (exit 1).
  - Maintenance mode:
    - on for all three commands, and off before cron;
    - off again after a failed `cim`;
    - left on when it was already on;
    - turned off by the retry after a killed deploy.
- **Proof the tests can fail:**
  - A lock that always says yes (what a per-instance lock file does) gives 4 deploys out of 4.
  - Without the second check, the late instance deploys again.
  - Without `--input-format=string`, version `1.20` deploys again on restart.
  - Without the maintenance step, the commands run with the site live.
  - Without the marker, a retry leaves the site in maintenance mode.
- **Not tested:** a real App Service, and the background start inside your entrypoint.
