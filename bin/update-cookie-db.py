#!/usr/bin/env python3
"""Build the bundled offline Open Cookie Database index from its CSV export."""
import argparse
import csv
import json
import re
from datetime import date
from pathlib import Path

LICENSE = "Apache-2.0"
CATEGORY = {"Functional": "necessary", "Security": "necessary", "Personalization": "preferences", "Analytics": "statistics", "Marketing": "marketing"}
RETENTION = {"session": "Sesiune", "1 year": "1 an", "2 years": "2 ani", "1 day": "1 zi", "30 days": "30 de zile", "1 month": "1 lună", "13 months": "13 luni"}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--csv", required=True, type=Path)
    parser.add_argument("--out", required=True, type=Path)
    args = parser.parse_args()
    rows, seen = [], set()
    with args.csv.open(encoding="utf-8-sig", newline="") as source:
        for item in csv.DictReader(source):
            name = item.get("Cookie / Data Key name", "").strip()
            wildcard = item.get("Wildcard match", "").strip() == "1"
            base = name[:-1] if wildcard and name.endswith("*") else name
            if not name or not re.fullmatch(r"[A-Za-z0-9_.-]{1,64}", base):
                continue
            key = (name, wildcard)
            if key in seen:
                continue
            seen.add(key)
            category = CATEGORY.get(item.get("Category", ""))
            if category is None:
                continue
            description = " ".join(item.get("Description", "").split())[:160].strip()
            retention = " ".join(item.get("Retention period", "").split())
            retention = RETENTION.get(retention.lower(), retention)
            rows.append([name, int(wildcard), category, item.get("Platform", "").strip(), description, retention])
    payload = {"meta": {"source": "https://github.com/jkwakman/Open-Cookie-Database", "license": LICENSE, "generated": date.today().isoformat(), "count": len(rows)}, "rows": rows}
    args.out.parent.mkdir(parents=True, exist_ok=True)
    args.out.write_text(json.dumps(payload, ensure_ascii=False, separators=(",", ":")) + "\n", encoding="utf-8")
    print(f"Generated {len(rows)} rows: {args.out} ({args.out.stat().st_size} bytes)")


if __name__ == "__main__":
    main()
