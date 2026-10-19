#!/usr/bin/env bash
# ADC Cookies & Commerce Hub — release artifact verifier.
#
# Validates a built release zip WITHOUT modifying it:
#   * opens and CRC-checks every entry (zipfile.testzip)
#   * every entry lives under the single root folder adc-marketing/
#   * required runtime/user-doc paths are present
#   * forbidden paths (tests, docs, bin, .github, dist, caches, secrets) absent
#   * plugin header inside the zip is valid and version matches the file name
#   * update-manifest.json (when present next to the zip) matches: canonical
#     package_url, sha256 of this exact zip, requires/requires_php/tested/
#     wc_requires consistent with the header
#
# Usage:
#   bin/verify-release.sh <path/to/adc-marketing-X.Y.Z.zip> [--dir <asset-dir>]
# Exit codes: 0 pass, 1 fail, 2 usage.
set -euo pipefail
export PYTHONDONTWRITEBYTECODE=1

ZIP_PATH="${1:-}"
[ -n "$ZIP_PATH" ] || { echo "Usage: bin/verify-release.sh <zip> [--dir <asset-dir>]" >&2; exit 2; }
[ -f "$ZIP_PATH" ] || { echo "FAIL zip not found: $ZIP_PATH" >&2; exit 1; }
ASSET_DIR="$(dirname "$ZIP_PATH")"
if [ "${2:-}" = "--dir" ]; then
    ASSET_DIR="${3:-$ASSET_DIR}"
fi

python3 - "$ZIP_PATH" "$ASSET_DIR" <<'PYEOF'
import hashlib, json, re, sys, zipfile

zip_path, asset_dir = sys.argv[1], sys.argv[2]
errors: list[str] = []
root = "adc-marketing/"

def fail(msg: str) -> None:
    errors.append(msg)

try:
    zf = zipfile.ZipFile(zip_path)
except Exception as exc:  # noqa: BLE001
    print(f"FAIL cannot open zip: {exc}")
    sys.exit(1)

bad = zf.testzip()
if bad is not None:
    fail(f"CRC failure on entry: {bad}")

names = [n for n in zf.namelist()]
if not names:
    fail("zip is empty")
for n in names:
    if not n.startswith(root):
        fail(f"entry outside root folder: {n}")
    if "\\" in n or n.startswith("/") or ".." in n.split("/"):
        fail(f"unsafe entry name: {n}")

def want(suffix: str, exact: bool = True) -> None:
    target = root + suffix
    if exact:
        if target not in names:
            fail(f"missing required entry: {target}")
    elif not any(n == target or n.startswith(target) for n in names):
        fail(f"missing required path: {target}")

for f in ("adc-marketing.php", "uninstall.php", "LICENSE", "README.txt",
          "includes/class-adc-marketing.php",
          "includes/core/interface-adc-module.php"):
    want(f)
for d in ("includes/", "assets/css/", "assets/js/", "templates/", "languages/"):
    want(d, exact=False)
if not any(n.startswith(root + "languages/") and n.endswith(".mo") for n in names):
    fail("missing languages/*.mo")

forbidden_dirs = ("tests/", "docs/", "bin/", ".github/", ".git", "dist/", "vendor/", "node_modules/", "AGENTS.md", "phpcs.xml.dist")
for n in names:
    rel = n[len(root):] if n.startswith(root) else n
    for f in forbidden_dirs:
        if rel.startswith(f):
            fail(f"forbidden path packaged: {n}")

m = re.search(r"adc-marketing-(\d+\.\d+\.\d+)\.zip$", zip_path)
if not m:
    fail("zip file name must be adc-marketing-X.Y.Z.zip")
name_version = m.group(1) if m else None

text = zf.read(root + "adc-marketing.php").decode("utf-8", "replace").replace("\r\n", "\n")[:8000]
def header(label: str) -> str | None:
    mm = re.search(rf"^[ \t]*\*[ \t]+{re.escape(label)}:[ \t]*(.+?)[ \t]*$", text, re.M)
    return mm.group(1) if mm else None
hv = header("Version")
if hv is None:
    fail("plugin header Version missing inside zip")
elif name_version and hv != name_version:
    fail(f"zip name version {name_version} != header {hv}")
for label in ("Requires at least", "Requires PHP", "Tested up to", "WC requires at least"):
    if header(label) is None:
        fail(f"plugin header {label} missing inside zip")
if header("Update URI") is None:
    fail("plugin header Update URI missing inside zip")

import os
manifest_path = os.path.join(asset_dir, "update-manifest.json")
if os.path.exists(manifest_path):
    manifest = json.load(open(manifest_path, encoding="utf-8"))
    digest = hashlib.sha256(open(zip_path, "rb").read()).hexdigest()
    zip_base = os.path.basename(zip_path)
    checks = {
        "version": (manifest.get("version"), name_version),
        "requires": (manifest.get("requires"), header("Requires at least")),
        "requires_php": (manifest.get("requires_php"), header("Requires PHP")),
        "tested": (manifest.get("tested"), header("Tested up to")),
        "wc_requires": (manifest.get("wc_requires"), header("WC requires at least")),
        "sha256": (manifest.get("sha256"), digest),
    }
    for key, (mv, expect) in checks.items():
        if mv != expect:
            fail(f"manifest.{key}={mv!r} != expected {expect!r}")
    url = manifest.get("package_url", "")
    expect_url = re.search(r"/releases/download/v[^/]+/adc-marketing-[^/]+\.zip$", url)
    if not expect_url or not url.startswith("https://github.com/"):
        fail(f"manifest.package_url not canonical: {url!r}")
    sums_path = os.path.join(asset_dir, "SHA256SUMS")
    if os.path.exists(sums_path):
        lines = dict((line.split()[1], line.split()[0]) for line in open(sums_path) if line.strip())
        if lines.get(zip_base) != digest:
            fail("SHA256SUMS does not carry the correct digest for the zip")
    else:
        fail("SHA256SUMS missing next to manifest")

if errors:
    for e in errors:
        print("FAIL", e)
    sys.exit(1)
print(f"OK release artifact verified: {os.path.basename(zip_path)} ({len(names)} entries, root={root}, version={name_version})")
PYEOF
