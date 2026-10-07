#!/usr/bin/env bash
# One command for the quality checks of the performance modules.
#
#   bash scripts/qa.sh            phpcs, then phpstan, then phpunit
#   bash scripts/qa.sh phpcs      Drupal + DrupalPractice coding standards
#   bash scripts/qa.sh phpstan    static analysis, level 5
#   bash scripts/qa.sh phpunit [phpunit options]   Unit + Kernel (+ Functional) tests
#
# Run it from the INSTALLED package (web/modules/custom/drupal-performance-modules)
# of a Drupal site that has the dev tools: composer require --dev drupal/coder
# drupal/core-dev mglaman/phpstan-drupal phpstan/phpstan-deprecation-rules
# phpstan/extension-installer. The tools come from that site's vendor/ folder.
#
# Kernel tests need a database: set SIMPLETEST_DB (mysql://user:pass@host/db, a
# throwaway schema; the tests create and drop their own prefixed tables).
# Without it the script tries to read the site's own connection with drush.
#
# Exit code: 0 = all selected checks passed.

set -uo pipefail

PKG="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SITE="${DRUPAL_SITE_ROOT:-}"
if [[ -z "$SITE" ]]; then
  SITE="$PKG"
  while [[ "$SITE" != "/" && ! -f "$SITE/vendor/autoload.php" ]]; do SITE="$(dirname "$SITE")"; done
fi
if [[ ! -f "$SITE/vendor/autoload.php" ]]; then
  echo "qa: no Drupal site with vendor/ above $PKG. Set DRUPAL_SITE_ROOT=<dir with composer.json and vendor/>." >&2
  exit 2
fi
BIN="$SITE/vendor/bin"
for tool in phpcs phpstan phpunit; do
  [[ -x "$BIN/$tool" ]] || { echo "qa: $BIN/$tool is missing; install the dev tools (see the header of this script)." >&2; exit 2; }
done

run_phpcs() { (cd "$PKG" && "$BIN/phpcs" --standard="$PKG/phpcs.xml.dist" "$@"); }
run_phpstan() { (cd "$PKG" && "$BIN/phpstan" analyse -c "$PKG/phpstan.neon.dist" --no-progress "$@"); }
run_phpunit() {
  if [[ -z "${SIMPLETEST_DB:-}" && -x "$BIN/drush" ]]; then
    SIMPLETEST_DB="$(cd "$SITE" && "$BIN/drush" php:eval '$i = \Drupal\Core\Database\Database::getConnectionInfo()["default"]; print $i["driver"] . "://" . $i["username"] . ":" . $i["password"] . "@" . $i["host"] . ":" . ($i["port"] ?: 3306) . "/" . $i["database"];' 2>/dev/null | tail -1)"
    export SIMPLETEST_DB
  fi
  export SIMPLETEST_BASE_URL="${SIMPLETEST_BASE_URL:-${BASE_URL:-http://localhost}}"
  export BROWSERTEST_OUTPUT_DIRECTORY="${BROWSERTEST_OUTPUT_DIRECTORY:-${TMPDIR:-/tmp}}"
  (cd "$PKG" && "$BIN/phpunit" -c "$PKG/phpunit.xml.dist" "$@")
}

what="${1:-all}"
[[ $# -gt 0 ]] && shift
case "$what" in
  phpcs) run_phpcs "$@" ;;
  phpstan) run_phpstan "$@" ;;
  phpunit) run_phpunit "$@" ;;
  all)
    status=0
    echo "== phpcs"; run_phpcs || status=1
    echo "== phpstan"; run_phpstan || status=1
    echo "== phpunit"; run_phpunit || status=1
    exit "$status"
    ;;
  *) echo "usage: qa.sh [phpcs|phpstan|phpunit|all] [options]" >&2; exit 2 ;;
esac
