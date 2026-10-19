#!/usr/bin/env bash
# ADC Cookies & Commerce Hub — SemVer single-source tooling.
#
# The authoritative version is the `Version:` header of adc-marketing.php.
# This script syncs and verifies the derived locations:
#   - ADC_MARKETING_VERSION constant   (adc-marketing.php)
#   - Stable tag:                       (README.txt)
#   - top entry ## [X.Y.Z]              (CHANGELOG.md)
#
# Usage:
#   bin/version.sh current                 print authoritative version
#   bin/version.sh check [--tag vX.Y.Z]    exit 1 unless every location matches
#                                          (and the tag matches, when given)
#   bin/version.sh sync                    rewrite constant + Stable tag from header
#   bin/version.sh bump patch|minor|major  rewrite header + constant + Stable tag,
#                                          insert CHANGELOG skeleton for the new version
#
# Exit codes: 0 ok, 1 mismatch/invalid input, 2 usage.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 2

MAIN="adc-marketing.php"
README_TXT="README.txt"
CHANGELOG="CHANGELOG.md"

for f in "$MAIN" "$README_TXT" "$CHANGELOG"; do
    [ -f "$f" ] || { echo "FAIL missing file: $f" >&2; exit 1; }
done

header_version() {
    grep -m1 -E '^[[:space:]]*\*[[:space:]]+Version:[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+[[:space:]]*$' "$MAIN" \
        | sed -E 's/.*Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+)[[:space:]]*$/\1/'
}

const_version() {
    grep -m1 -E "^[[:space:]]*define\('ADC_MARKETING_VERSION', '[0-9]+\.[0-9]+\.[0-9]+'\);[[:space:]]*$" "$MAIN" \
        | sed -E "s/.*ADC_MARKETING_VERSION', '([0-9]+\.[0-9]+\.[0-9]+)'.*/\1/"
}

stable_tag() {
    grep -m1 -E '^Stable tag:[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+[[:space:]]*$' "$README_TXT" \
        | sed -E 's/^Stable tag:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+)[[:space:]]*$/\1/'
}

changelog_top() {
    grep -m1 -E '^## \[[0-9]+\.[0-9]+\.[0-9]+\]' "$CHANGELOG" \
        | sed -E 's/^## \[([0-9]+\.[0-9]+\.[0-9]+)\].*/\1/'
}

is_semver() { [[ "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; }

bump_semver() {
    local kind="$1" ver="$2" major minor patch
    IFS=. read -r major minor patch <<<"$ver"
    case "$kind" in
        major) echo "$((major + 1)).0.0" ;;
        minor) echo "${major}.$((minor + 1)).0" ;;
        patch) echo "${major}.${minor}.$((patch + 1))" ;;
        *) return 1 ;;
    esac
}

# Rewrite exactly one well-known line per file; abort if the line is not unique.
rewrite_file_line() {
    local file="$1" regex="$2" replacement="$3" count
    count=$(grep -cE "$regex" "$file" || true)
    if [ "$count" -ne 1 ]; then
        echo "FAIL expected exactly 1 line matching '$regex' in $file (found $count)" >&2
        exit 1
    fi
    sed -i -E "s|${regex}|${replacement}|" "$file"
}

cmd="${1:-}"
case "$cmd" in
    current)
        ver="$(header_version)"
        is_semver "$ver" || { echo "FAIL cannot parse Version: header of $MAIN" >&2; exit 1; }
        echo "$ver"
        ;;

    check)
        ver="$(header_version)"
        is_semver "$ver" || { echo "FAIL cannot parse Version: header of $MAIN" >&2; exit 1; }
        fail=0
        c="$(const_version   || true)"; [ "$c" = "$ver" ] || { echo "FAIL ADC_MARKETING_VERSION=$c != header $ver" >&2; fail=1; }
        s="$(stable_tag      || true)"; [ "$s" = "$ver" ] || { echo "FAIL README.txt Stable tag=$s != header $ver" >&2; fail=1; }
        g="$(changelog_top   || true)"; [ "$g" = "$ver" ] || { echo "FAIL CHANGELOG.md top entry=$g != header $ver" >&2; fail=1; }
        tag="${3:-}"
        if [ "${2:-}" = "--tag" ] && [ -n "$tag" ]; then
            t="${tag#v}"
            [ "$t" = "$ver" ] || { echo "FAIL tag $tag != header $ver" >&2; fail=1; }
        fi
        [ "$fail" -eq 0 ] && echo "OK version $ver consistent (header, constant, Stable tag, CHANGELOG)"
        exit "$fail"
        ;;

    sync)
        ver="$(header_version)"
        is_semver "$ver" || { echo "FAIL cannot parse Version: header" >&2; exit 1; }
        rewrite_file_line "$MAIN" "define\('ADC_MARKETING_VERSION', '[0-9.]+'\)" "define('ADC_MARKETING_VERSION', '$ver')"
        rewrite_file_line "$README_TXT" '^Stable tag:[0-9. ]+' "Stable tag: $ver"
        echo "OK synced constant + Stable tag from header ($ver). CHANGELOG.md top entry stays as-is."
        ;;

    bump)
        kind="${2:-}"
        ver="$(header_version)"
        new="$(bump_semver "$kind" "$ver")" || { echo "FAIL usage: bin/version.sh bump patch|minor|major" >&2; exit 2; }
        is_semver "$new" || { echo "FAIL bad bump result" >&2; exit 1; }
        rewrite_file_line "$MAIN" '^[[:space:]]*\*[[:space:]]+Version:[[:space:]]*[0-9.]+' " * Version: $new"
        rewrite_file_line "$MAIN" "define\('ADC_MARKETING_VERSION', '[0-9.]+'\)" "define('ADC_MARKETING_VERSION', '$new')"
        rewrite_file_line "$README_TXT" '^Stable tag:[0-9. ]+' "Stable tag: $new"
        # Insert a CHANGELOG skeleton above the current top entry so `check` keeps passing.
        if ! grep -qE "^## \[$new\]" "$CHANGELOG"; then
            top_line=$(grep -nE '^## \[[0-9]+\.[0-9]+\.[0-9]+\]' "$CHANGELOG" | head -1 | cut -d: -f1)
            [ -n "$top_line" ] || { echo "FAIL CHANGELOG.md has no version entry to bump from" >&2; exit 1; }
            awk -v new="$new" -v top="$top_line" '
                NR == top && !done {
                    printf "## [%s] - în lucru\n\n### Adăugat\n- (de completat înainte de publicare)\n\n", new
                    done = 1
                }
                { print }
            ' "$CHANGELOG" > "$CHANGELOG.new.$$"
            mv "$CHANGELOG.new.$$" "$CHANGELOG"
            echo "OK CHANGELOG.md skeleton entry inserted for $new"
        fi
        echo "OK bumped $ver -> $new (header, constant, Stable tag, CHANGELOG). Review, commit and tag v$new."
        ;;

    *)
        echo "Usage: bin/version.sh current|check [--tag vX.Y.Z]|sync|bump patch|minor|major" >&2
        exit 2
        ;;
esac
