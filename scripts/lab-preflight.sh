#!/usr/bin/env bash
# LAB ONLY. Refuse to run the test suite when the lab does not match the repo.
# lando-test.sh and ddev-test.sh run this inside the lab container, from the
# app root, straight from the repo mount (/opt/perf-recipe-src), before any test.
#
# (a) Patches: the ones declared in composer.json = the ones in
#     patches.lock.json = the ones applied to the installed package. Applied is
#     checked on a copy: the declared stack must come off cleanly (last patch
#     first), and no other known patch file may still be in what is left.
# (b) The installed copies of the modules package, the recipe and
#     tests/performance/ (each if there) match the repo (__ARCHIVED_* ignored).
#
# Prints one "FAIL preflight: ..." line per problem and exits 1, or prints
# "preflight OK" and exits 0.

set -uo pipefail
SRC="${PERF_RECIPE_SRC:-/opt/perf-recipe-src}"
bad=0
no() { echo "FAIL preflight: $*"; bad=1; }

# One line per patch: "<package> <file name> <sha256>".
declared() { php -r '$j = json_decode(file_get_contents("composer.json"), TRUE); foreach ($j["extra"]["patches"] ?? [] as $p => $l) foreach ($l as $v) { $f = is_array($v) ? $v["url"] : $v; echo "$p ", basename($f), " ", @hash_file("sha256", $f), "\n"; }'; }
locked() { php -r '$j = json_decode(@file_get_contents("patches.lock.json") ?: "{}", TRUE); foreach ($j["patches"] ?? [] as $p => $l) foreach ($l as $v) echo "$p ", basename($v["url"]), " ", $v["sha256"], "\n";'; }

d=$(declared)
[[ "$(sort <<<"$d")" == "$(locked | sort)" ]] || no "composer.json patches and patches.lock.json differ. Fix: composer patches-relock, then composer reinstall <package>."
for pkg in $(cut -d' ' -f1 <<<"$d" | sort -u); do
  dir="web/modules/contrib/${pkg#drupal/}"
  tmp="/tmp/perf-preflight-${pkg#drupal/}"
  mkdir -p "$tmp" && cp -a "$dir/." "$tmp/" || { no "$pkg: cannot copy $dir"; continue; }
  mine=$(awk -v p="$pkg" '$1 == p {print $2}' <<<"$d")
  for f in $(tac <<<"$mine"); do
    [[ ! -f "$SRC/patches/$f" ]] || cmp -s "patches/$f" "$SRC/patches/$f" || no "$pkg: patches/$f differs from the repo's copy."
    patch -R -p1 -s -f --no-backup-if-mismatch -r - -d "$tmp" < "patches/$f" >/dev/null 2>&1 || no "$pkg: declared patch $f is not applied to $dir. Fix: composer reinstall $pkg."
  done
  for f in patches/*.patch "$SRC"/patches/*.patch; do
    [[ "$f" == "$SRC"/* && -f "patches/$(basename "$f")" ]] && continue
    grep -qx "$(basename "$f")" <<<"$mine" && continue
    patch --dry-run -R -p1 -s -f -d "$tmp" < "$f" >/dev/null 2>&1 && no "$pkg: $(basename "$f") is applied to $dir but not declared. Fix: composer reinstall $pkg."
  done
done

for pair in "drupal-performance-modules web/modules/custom/drupal-performance-modules simplyshipley/drupal-performance-modules" \
            ". recipes/drupal-performance-recipe simplyshipley/drupal-performance-recipe"; do
  read -r from to pkg <<<"$pair"
  [[ "$to" == recipes/* && ! -d "$to" ]] && continue
  out=$(diff -rq -x '__ARCHIVED_*' -x .git -x .claude -x .DS_Store "$SRC/$from" "$to" 2>&1) || no "$to is not the repo's current code: $(head -3 <<<"$out" | tr '\n' ' ')Fix: composer reinstall $pkg (composer update $pkg if it is not installed)."
done
for sub in edge mock-afd scripts docs; do
  [[ -d tests/performance ]] || break
  out=$(diff -rq -x '__ARCHIVED_*' -x __pycache__ -x .DS_Store "$SRC/$sub" "tests/performance/$sub" 2>&1) || no "tests/performance/$sub is not the repo's current copy: $(head -3 <<<"$out" | tr '\n' ' ')Fix: copy $sub/ again from the repo."
done

[[ $bad == 0 ]] && echo "preflight OK: patches declared = locked = applied ($(wc -l <<<"$d") patches); installed copies match $SRC"
exit "$bad"
