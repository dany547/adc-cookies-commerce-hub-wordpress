#!/usr/bin/env bash
# ADC Cookies & Commerce Hub — release builder (thin wrapper).
#
# Runs bin/version.sh check first, then the deterministic Python builder
# (bin/lib/build_release.py). Outputs into a fresh directory and REFUSES to
# overwrite or delete anything that already exists.
#
# Usage:
#   bin/build-release.sh [--out DIR] [--version X.Y.Z]
#
# Defaults: --out dist, version from the plugin header.
# Exit codes: 0 ok, 1 validation/refusal, 2 usage.
set -euo pipefail
export PYTHONDONTWRITEBYTECODE=1

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 2

OUT=""
VERSION_ARGS=()
while [ $# -gt 0 ]; do
    case "$1" in
        --out)
            [ $# -ge 2 ] || { echo "FAIL --out needs a directory" >&2; exit 2; }
            OUT="$2"; shift 2 ;;
        --version)
            [ $# -ge 2 ] || { echo "FAIL --version needs X.Y.Z" >&2; exit 2; }
            VERSION_ARGS=(--version "$2"); shift 2 ;;
        -h|--help)
            sed -n '2,12p' "$0"; exit 0 ;;
        *)
            echo "FAIL unknown argument: $1" >&2; exit 2 ;;
    esac
done

OUT="${OUT:-dist}"
VERSION="$(bash bin/version.sh current)"

if [ "${#VERSION_ARGS[@]}" -gt 0 ]; then
    VERSION="${VERSION_ARGS[1]}"
fi

echo "== version consistency =="
if ! bash bin/version.sh check; then
    echo "FAIL version check failed; refusing to build (fix with bin/version.sh sync / bump)" >&2
    exit 1
fi

echo "== build (out: $OUT, version: $VERSION) =="
python3 bin/lib/build_release.py --root . --out "$OUT" ${VERSION_ARGS[@]+"${VERSION_ARGS[@]}"}

echo "== verify =="
bash bin/verify-release.sh "$OUT/adc-marketing-${VERSION}.zip" --dir "$OUT"
