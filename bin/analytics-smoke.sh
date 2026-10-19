#!/usr/bin/env bash
# Operational analytics smoke harness.
#
# Usage:
#   bin/analytics-smoke.sh              php -l for analytics PHP + tests, every
#                                       standalone analytics unit test in its own
#                                       PHP process, then the repository smoke
#                                       harness (bin/smoke.sh, syntax/phpcs).
#   bin/analytics-smoke.sh <wp_path>    Additionally runs the analytics
#                                       integration scripts with wp-cli inside a
#                                       DISPOSABLE WordPress + WooCommerce install
#                                       containing this plugin.
#
# Never point <wp_path> at production or a shared site. Integration is skipped
# (never failed) when no runtime path, no wp-cli, or no plugin is available.
set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1

FAIL=0

echo "== php -l (analytics sources and tests) =="
shopt -s nullglob
# Only the analytics module and its tests are linted here; bin/smoke.sh below
# already lints every PHP file in the repository (including the admin tab and
# the dashboard template), so nothing is skipped, just not linted twice.
LINT_FILES=(includes/analytics/*.php tests/analytics/*.php)
if [ "${#LINT_FILES[@]}" -eq 0 ]; then
    echo "FAIL no analytics PHP files found"
    FAIL=1
fi
for f in "${LINT_FILES[@]}"; do
    if OUT=$(php -l "$f" 2>&1); then
        echo "OK   $f"
    else
        echo "FAIL $f"
        echo "$OUT"
        FAIL=1
    fi
done

echo "== standalone analytics unit tests (isolated PHP processes) =="
UNIT_TESTS=(tests/analytics/*-test.php)
if [ "${#UNIT_TESTS[@]}" -eq 0 ]; then
    echo "FAIL no analytics unit tests found"
    FAIL=1
fi
for t in "${UNIT_TESTS[@]}"; do
    echo "-- $t"
    if php "$t"; then
        echo "OK   $t"
    else
        echo "FAIL $t"
        FAIL=1
    fi
done
shopt -u nullglob

echo "== repository smoke (bin/smoke.sh) =="
if [ -f bin/smoke.sh ]; then
    if bash bin/smoke.sh; then
        echo "OK   bin/smoke.sh"
    else
        echo "FAIL bin/smoke.sh"
        FAIL=1
    fi
else
    echo "SKIP bin/smoke.sh not found"
fi

# --- integration (opt-in: disposable WordPress path as first argument) ---
WP="${1:-}"
echo "== integration (disposable WordPress) =="
if [ -z "$WP" ]; then
    echo "SKIP no WordPress path given (pass a disposable wp_path as \$1)"
else
    INTEGRATION=()
    shopt -s nullglob
    INTEGRATION=(tests/analytics/integration-*.php tests/analytics/e2e.php)
    shopt -u nullglob
    if [ "${#INTEGRATION[@]}" -eq 0 ]; then
        echo "SKIP no analytics integration scripts present"
    elif ! command -v wp >/dev/null 2>&1; then
        echo "SKIP wp-cli (wp) not found in PATH"
    elif [ ! -d "$WP/wp-content/plugins/adc-marketing" ]; then
        echo "SKIP plugin not installed at $WP/wp-content/plugins/adc-marketing"
    else
        for t in "${INTEGRATION[@]}"; do
            echo "-- $t"
            if wp --path="$WP" eval-file "$ROOT/$t"; then
                echo "OK   $t"
            else
                echo "FAIL $t"
                FAIL=1
            fi
        done
    fi
fi

echo "== result =="
if [ "$FAIL" -ne 0 ]; then
    echo "FAIL"
    exit 1
fi
echo "PASS"
exit 0
