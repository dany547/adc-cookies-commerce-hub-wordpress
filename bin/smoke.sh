#!/usr/bin/env bash
# ADC Marketing smoke harness (docs/ARCHITECTURE.md §5).
#
# Usage:
#   bin/smoke.sh              Syntax checks only: php -l over all *.php,
#                              node --check over all *.js, phpcs if available.
#   bin/smoke.sh <wp_path>    Additionally runs the wp-cli C1 smoke checks
#                              (plugin activate, tables, db version, settings,
#                              ADC_Event final). The plugin must be present in
#                              <wp_path>/wp-content/plugins/adc-marketing.
set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1

FAIL=0

echo "== php -l =="
while IFS= read -r -d '' f; do
    if OUT=$(php -l "$f" 2>&1); then
        echo "OK   $f"
    else
        echo "FAIL $f"
        echo "$OUT"
        FAIL=1
    fi
done < <(find . -type f -name '*.php' \
    -not -path './vendor/*' \
    -not -path './node_modules/*' \
    -not -path './.git/*' \
    -print0 | sort -z)

echo "== node --check =="
JS_COUNT=0
while IFS= read -r -d '' f; do
    JS_COUNT=$((JS_COUNT + 1))
    if command -v node >/dev/null 2>&1; then
        if OUT=$(node --check "$f" 2>&1); then
            echo "OK   $f"
        else
            echo "FAIL $f"
            echo "$OUT"
            FAIL=1
        fi
    else
        echo "FAIL $f (node not found)"
        FAIL=1
    fi
done < <(find . -type f -name '*.js' \
    -not -path './vendor/*' \
    -not -path './node_modules/*' \
    -not -path './.git/*' \
    -print0 | sort -z)
if [ "$JS_COUNT" -eq 0 ]; then
    echo "(no .js files found)"
fi

echo "== phpcs =="
PHPCS=""
if [ -x "$ROOT/vendor/bin/phpcs" ]; then
    PHPCS="$ROOT/vendor/bin/phpcs"
elif command -v phpcs >/dev/null 2>&1; then
    PHPCS="phpcs"
fi
if [ -n "$PHPCS" ]; then
    if ! "$PHPCS" --standard=phpcs.xml.dist; then
        echo "FAIL phpcs reported violations"
        FAIL=1
    fi
else
    echo "phpcs unavailable"
fi

# --- wp-cli checks (only when a WordPress path argument is given) ---
WP="${1:-}"
if [ -n "$WP" ]; then
    echo "== wp-cli ($WP) =="
    if ! command -v wp >/dev/null 2>&1; then
        echo "FAIL wp-cli (wp) not found in PATH"
        FAIL=1
    else
        wp --path="$WP" plugin activate adc-marketing || FAIL=1

        TABLES=$(wp --path="$WP" db query "SHOW TABLES LIKE '%adc_%'" --skip-column-names 2>/dev/null || true)
        echo "$TABLES" | grep -q "adc_consent_log"   || { echo "FAIL missing table adc_consent_log";   FAIL=1; }
        echo "$TABLES" | grep -q "adc_event_outbox"  || { echo "FAIL missing table adc_event_outbox";  FAIL=1; }

        DBVER=$(wp --path="$WP" option get adc_db_version 2>/dev/null || true)
        [ "$DBVER" = "1" ] || { echo "FAIL adc_db_version = '$DBVER' (expected '1')"; FAIL=1; }

        # Requires the consent group registered by C2 (int(180) once built).
        COOKIE_DAYS=$(wp --path="$WP" eval 'echo var_export(ADC_Settings::get("consent", "cookie_days"), true);' 2>/dev/null || true)
        echo "ADC_Settings::get('consent','cookie_days') = $COOKIE_DAYS (expected int(180) after C2)"

        IS_FINAL=$(wp --path="$WP" eval 'echo var_export((new ReflectionClass("ADC_Event"))->isFinal(), true);' 2>/dev/null || true)
        [ "$IS_FINAL" = "true" ] || { echo "FAIL ADC_Event is not final"; FAIL=1; }
    fi
fi

echo "== result =="
if [ "$FAIL" -ne 0 ]; then
    echo "FAIL"
    exit 1
fi
echo "PASS"
exit 0
