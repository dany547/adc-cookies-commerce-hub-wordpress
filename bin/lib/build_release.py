#!/usr/bin/env python3
"""Deterministic release builder for ADC Cookies & Commerce Hub.

Produces, inside a NEW output directory (nothing is ever overwritten):

  adc-marketing-<version>.zip   allowlisted runtime package, root folder
                                `adc-marketing/`, sorted entries, fixed
                                timestamps and modes -> byte-reproducible
  update-manifest.json          fields consumed by ADC_Github_Updater
  SHA256SUMS                    checksums for the zip and the manifest

Allowlist policy (single source of truth for packaging; a separate
.distignore is intentionally NOT used):

  include  adc-marketing.php, uninstall.php, LICENSE, README.txt,
           includes/**/*.php, assets/**.{css,js}, templates/**/*.php,
           languages/**.{mo,po}
  exclude  everything else (.git*, tests/, docs/, bin/, .github/, dist/,
           vendor/, node_modules/, AGENTS.md, phpcs.xml.dist, .env*,
           *.md except LICENSE/README.txt, caches, secrets)

Exit codes: 0 ok, 1 validation/refusal, 2 usage.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import sys
import zipfile
from pathlib import Path

REPO = "dany547/adc-cookies-commerce-hub-wordpress"
ZIP_ROOT = "adc-marketing"
PLUGIN_ENTRY = "adc-marketing.php"

# Fixed date_time for every entry (ZIP epoch) -> reproducible archives.
FIXED_DATE_TIME = (1980, 1, 1, 0, 0, 0)

# (path, extensions) allowlist for recursive trees.
TREE_ALLOWLIST = {
    "includes": {".php"},
    "assets": {".css", ".js"},
    "templates": {".php"},
    "languages": {".mo", ".po"},
}

# Exact top-level files.
FILE_ALLOWLIST = {"adc-marketing.php", "uninstall.php", "LICENSE", "README.txt"}

# Entries that must never appear inside the zip (defense in depth).
FORBIDDEN_COMPONENTS = {
    ".git", ".github", ".claude", ".pi", "node_modules", "vendor",
    "tests", "docs", "bin", "dist", ".version-tmp",
}
FORBIDDEN_NAMES = re.compile(
    r"(^|/)(\.env($|\.)|AGENTS\.md$|phpcs\.xml\.dist$|"
    r"[^/]*\.(log|key|pem|p12|pfx|cache|tmp|bak|sql|zip)$)"
)


def assert_allowed(rel: str) -> None:
    if any(part in FORBIDDEN_COMPONENTS for part in rel.split("/")):
        raise SystemExit(f"FAIL forbidden path component in: {rel}")
    if FORBIDDEN_NAMES.search(rel):
        raise SystemExit(f"FAIL forbidden file name in: {rel}")

HEADER_FIELDS = {
    "version": "Version",
    "requires": "Requires at least",
    "tested": "Tested up to",
    "requires_php": "Requires PHP",
    "wc_requires": "WC requires at least",
    "wc_tested": "WC tested up to",
}


def read_header(root: Path) -> dict[str, str]:
    """Parse the plugin header of the entry file into a field dict."""
    text = (root / PLUGIN_ENTRY).read_text(encoding="utf-8", errors="replace")[:8000]
    out: dict[str, str] = {}
    for key, label in HEADER_FIELDS.items():
        m = re.search(rf"^[ \t]*\*[ \t]+{re.escape(label)}:[ \t]*(.+?)[ \t]*$", text, re.M)
        if not m:
            raise SystemExit(f"FAIL plugin header field missing: {label}")
        out[key] = m.group(1)
    if not re.fullmatch(r"\d+\.\d+\.\d+", out["version"]):
        raise SystemExit(f"FAIL Version header is not SemVer X.Y.Z: {out['version']!r}")
    return out


def collect_files(root: Path) -> list[Path]:
    """Return allowlisted source files (paths relative to root), sorted."""
    found: list[Path] = []
    for name in FILE_ALLOWLIST:
        p = root / name
        if not p.is_file():
            raise SystemExit(f"FAIL required release file missing: {name}")
        found.append(p)
    for tree, exts in TREE_ALLOWLIST.items():
        base = root / tree
        if not base.is_dir():
            raise SystemExit(f"FAIL required release tree missing: {tree}/")
        for p in base.rglob("*"):
            if p.is_file() and p.suffix.lower() in exts:
                rel = p.relative_to(root).as_posix()
                assert_allowed(rel)
                found.append(p)
    # Deterministic order.
    return sorted(found, key=lambda p: p.relative_to(root).as_posix())


def zip_info(rel: str, is_dir: bool) -> zipfile.ZipInfo:
    info = zipfile.ZipInfo(f"{ZIP_ROOT}/{rel}", date_time=FIXED_DATE_TIME)
    info.compress_type = zipfile.ZIP_DEFLATED
    info.external_attr = ((0o755 if is_dir else 0o644) << 16) | (0x10 if is_dir else 0)
    info.create_system = 3  # unix, but mode comes from external_attr above
    return info


def build_zip(root: Path, zip_path: Path, files: list[Path]) -> str:
    """Write the deterministic zip; return its sha256 hex digest."""
    dirs: set[str] = set()
    # Root folder entry first (zip_info() prefixes ZIP_ROOT).
    entries: list[tuple[zipfile.ZipInfo, Path | None]] = [(zip_info("", True), None)]
    for f in files:
        rel = f.relative_to(root).as_posix()
        parts = rel.split("/")
        for i in range(1, len(parts)):
            dirs.add("/".join(parts[:i]))
    for d in sorted(dirs):
        entries.append((zip_info(f"{d}/", True), None))
    for f in files:
        rel = f.relative_to(root).as_posix()
        entries.append((zip_info(rel, False), f))

    with zipfile.ZipFile(zip_path, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        for info, src in entries:
            if src is None:
                # Directory entry name must end with '/'; zip_info added root prefix.
                info.filename = info.filename if info.filename.endswith("/") else info.filename + "/"
                zf.writestr(info, b"")
            else:
                zf.writestr(info, src.read_bytes())

    h = hashlib.sha256()
    with open(zip_path, "rb") as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def main(argv: list[str]) -> int:
    ap = argparse.ArgumentParser(description="Deterministic release builder")
    ap.add_argument("--root", default=".", help="repository root (default: cwd)")
    ap.add_argument("--out", required=True, help="output directory (must not contain the target files)")
    ap.add_argument("--version", help="override version (default: parsed from plugin header)")
    ap.add_argument("--repo", default=REPO, help="GitHub repo for package_url")
    args = ap.parse_args(argv)

    root = Path(args.root).resolve()
    out = Path(args.out).resolve()
    if not root.is_dir():
        print(f"FAIL root not a directory: {root}", file=sys.stderr)
        return 1

    header = read_header(root)
    version = args.version or header["version"]
    if not re.fullmatch(r"\d+\.\d+\.\d+", version):
        print(f"FAIL version is not SemVer X.Y.Z: {version}", file=sys.stderr)
        return 1

    out.mkdir(parents=True, exist_ok=True)
    zip_name = f"{ZIP_ROOT}-{version}.zip"
    zip_path = out / zip_name
    manifest_path = out / "update-manifest.json"
    sums_path = out / "SHA256SUMS"

    # Refusal policy: never overwrite, never delete.
    for p in (zip_path, manifest_path, sums_path):
        if p.exists():
            print(f"FAIL refusing to overwrite existing file: {p} (choose a fresh --out dir)", file=sys.stderr)
            return 1

    files = collect_files(root)
    sha256 = build_zip(root, zip_path, files)

    package_url = (
        f"https://github.com/{args.repo}/releases/download/v{version}/{zip_name}"
    )
    manifest = {
        "version": version,
        "requires": header["requires"],
        "requires_php": header["requires_php"],
        "tested": header["tested"],
        "wc_requires": header["wc_requires"],
        "package_url": package_url,
        "sha256": sha256,
    }
    manifest_path.write_text(json.dumps(manifest, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

    sums_path.write_text(
        f"{sha256}  {zip_name}\n{hashlib.sha256(manifest_path.read_bytes()).hexdigest()}  {manifest_path.name}\n",
        encoding="utf-8",
    )

    print(f"OK built   {zip_path} ({zip_path.stat().st_size} bytes, {len(files)} files)")
    print(f"OK sha256 {sha256}")
    print(f"OK wrote   {manifest_path.name}, {sums_path.name}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
