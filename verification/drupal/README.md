# Drupal effective-settings verifier

Run this beside the [deployment checklist](../../docs/guides/deployment-verification-checklist.md). It compares an explicit environment profile with bootstrapped CLI settings, selected config, required modules, effective bin factory selections and service classes. Every result includes actual, expected, why and troubleshooting text.

It requests no Redis connections, cache get/set/delete operations, outbound requests, configuration writes or outage changes. Drush/Drupal bootstrap itself may perform normal internal cache writes. The existing `drush perf:diagnose --redis` and actual web report remain the shared implementation for active DNS/transport/client/backend tests; this verifier does not duplicate them.

## Run on QA or Prod

Copy this whole directory to a location accessible to Drush, outside the public document root. No new Drupal module, module reinstall, recipe reapplication or normalizer change is required for this verifier.

1. Copy `qa.example.json` or `prod.example.json` to a private environment-specific profile.
2. Fill the required target expectations below from approved deployment configuration, independently of the observed report.
3. Run from the selected Drupal site with the correct Drush alias/`--uri` where needed:

```bash
PERF_VERIFY_PROFILE=/absolute/qa.json PERF_VERIFY_FORMAT=json \
  drush php:script /absolute/verification/drupal/audit.php > qa-settings.json

PERF_VERIFY_PROFILE=/absolute/qa.json PERF_VERIFY_FORMAT=markdown \
  drush php:script /absolute/verification/drupal/audit.php > qa-settings.md
```

JSON is the default format. Repeat the invocation to obtain the other format; each captures the current CLI state. Keep reports and custom profiles in a private operational location and do not commit them. Local `.gitignore` covers named QA/Prod/local profiles and reports; files created elsewhere require equivalent care. They contain safe operational paths, hashes, proxy ranges and prefixes, but no Redis hostname, passwords, raw ENV dump, cookies or database credentials.

Exit0 means no required FAIL/BLOCKED rows (optional WARN may remain). Exit1 means required mismatches or missing expectations, with the complete report still printed. Exit2 means invalid profile/format or failure to collect; errors expose only a class/category. Check `summary`, `settings_result` and individual rows. `deployment_readiness` always remains `NOT_ASSESSED_EXTERNAL_EVIDENCE_REQUIRED`: a CLI pass cannot certify Apache, Azure or Front Door.

## Profile schema

Unknown keys and incorrectly typed values are rejected. The example files are templates; null target expectations cause BLOCKED rather than guessing or accepting whatever happens to be observed.

| Field | Type / default | Meaning |
|---|---|---|
| environment | `qa` or `prod`; default `qa` | Report label; does not auto-select endpoints. |
| page_max_age | Nonnegative integer;900 | Expected core performance max-age. |
| redis_port | Integer1–65535;null | Required environment target. QA example6378 is the working exposed port, with target/listener6379. Production port must be supplied explicitly. |
| redis_db | Nonnegative integer;0 | Expected Redis logical DB. |
| redis_host_sha256 |64lowercase hex characters;null | Required SHA256 of intended effective hostname without `tcp://`/`tls://`; compare without disclosing hostname in output. |
| redis_prefix | Nonempty string;null | Required unique environment/site prefix. |
| reverse_proxy | Boolean;true | Expected effective reverse proxy enablement. |
| trusted_proxy_addresses | Nonempty ordered string list;null | Required approved proxy list; match is exact, including order. Use OIG's existing settings base variables rather than replacing its trust chain. |
| browser_language_enabled | Boolean;false | Baseline disables browser negotiation. If intentionally approvedtrue, an additional WARN remains because its invocation can deny caching. Check all configured language negotiation types, not only interface. |
| bins | Nonempty machine-name list; six recipe bins | Defaults render,page,dynamic_page_cache,data,entity,menu. |
| required_modules | Nonempty machine-name list; recipe modules | Defaults mirror recipe.yml. Override only with reviewed deployment intent. |

All bin rows compare the effective selected factory service, using core precedence (settings per-bin, default per-bin backend parameters, default backend). The Redis factory class must be the fail-open decorator. These checks do not instantiate a per-bin backend or prove Redis connectivity. The existing active diagnostic backend roundtrip tests one selected bin, not all six.

Service class differences for checksum, lock and flood are WARN because reviewed custom decorators can be legitimate. Recipe baseline keeps these in the database. CLI APCu can be disabled even when Apache APCu works; that WARN requires actual web-runtime evidence. Xdebug modes account for the `XDEBUG_MODE` override ahead of INI and redact unrecognized values.

The report's `runtime.settings_performance_files` lists included files named `settings.performance.php` with SHA256 hashes. Compare the deployed working QA file with the intended artifact before swapping: the reported live broad ENV trim differs from the repository's allowlist. This tool never changes that normalizer. A file copied elsewhere, conditionally included differently, or a separate Apache worker needs its own deployment/web verification.

## What remains outside this check

- Actual Apache ENV, extension and SAPI behavior, DNS/transport, Redis use under traffic, recovery and content-invalidation tests.
- Whether a language negotiation method is actually invoked for a specific response; enabled configuration identifies risk, not a confirmed caller.
- Azure container allocation, effective Redis maxmemory/policy, ingress, secrets, replicas, origin access, WAF, Front Door rules/cache HIT/purge and purger queue/cloud authentication.
- The correctness of trusted host/proxy scope beyond the supplied expectations; required patterns are checked for presence only.

## Isolated tests

With PHP8.1+ and PhpRedis available:

```bash
php verification/drupal/tests/run.php
```

Fixtures are synthetic and load no real Drupal site or database. They check successful output, all six bin selections, a wrong single bin, quoted raw ENV with effective port6378, absent required expectations, bad profiles, Xdebug precedence, content-language browser negotiation, secret exclusion, JSON/Markdown output and entrypoint exit0/1/2. They do not claim live Drupal/Azure validation.
