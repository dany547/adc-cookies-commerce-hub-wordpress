#!/usr/bin/env python3
"""ADC Marketing i18n build (stdlib only — no xgettext/msgfmt/wp-cli needed).

Pipeline (default: `python3 bin/i18n-build.py`):
  1. extract  — regex-parse the PHP sources for gettext calls with the
                'adc-marketing' domain and regenerate languages/adc-marketing.pot
                (supports __(), _e(), esc_html__(), esc_attr__(), esc_html_e(),
                esc_attr_e(), _n(), _x(), _nx(); single/double-quoted literals
                with escapes; `translators:` comments; #: file:line refs).
  2. check    — verify languages/adc-marketing-en_US.po covers every .pot msgid
                (no empty msgstr, no missing entries). Fails loudly otherwise.
  3. derive   — regenerate languages/adc-marketing-en_GB.po from en_US with a
                small US→GB spelling dictionary applied to msgstr values.
  4. compile  — write en_US.mo and en_GB.mo (GNU MO, little-endian,
                magic 0x950412de, sorted tables, msgctxt + plural support).

Sub-commands: extract | check | derive | compile | all (default).
"""

from __future__ import annotations

import re
import struct
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
LANG_DIR = ROOT / "languages"
DOMAIN = "adc-marketing"

POT_PATH = LANG_DIR / "adc-marketing.pot"
EN_US_PO = LANG_DIR / "adc-marketing-en_US.po"
EN_US_MO = LANG_DIR / "adc-marketing-en_US.mo"
EN_GB_PO = LANG_DIR / "adc-marketing-en_GB.po"
EN_GB_MO = LANG_DIR / "adc-marketing-en_GB.mo"

SOURCE_FILES = [ROOT / "adc-marketing.php", ROOT / "uninstall.php"]
SOURCE_FILES += sorted((ROOT / "includes").rglob("*.php"))
SOURCE_FILES += sorted((ROOT / "templates").rglob("*.php"))

GETTEXT_FUNCS = [
    "esc_html_e", "esc_attr_e", "esc_html__", "esc_attr__",
    "esc_html_x", "esc_attr_x",
    "_e", "__", "_n", "_x", "_nx",
]
FUNC_RE = re.compile(r"(?<![\w$])(" + "|".join(GETTEXT_FUNCS) + r")\s*\(")

SINGLE_ESCAPES = {"\\": "\\", "'": "'", "n": "\n", "t": "\t", "r": "\r"}
DOUBLE_ESCAPES = {
    "\\": "\\", '"': '"', "n": "\n", "t": "\t", "r": "\r", "e": "\x1b",
    "f": "\f", "v": "\v", "0": "\0", "$": "$", "l": "\x0c",
}


# --------------------------------------------------------------------------
# PHP argument scanning
# --------------------------------------------------------------------------

def skip_ws_and_comments(src: str, i: int, n: int) -> int:
    while i < n:
        c = src[i]
        if c in " \t\r\n":
            i += 1
        elif src.startswith("/*", i):
            end = src.find("*/", i + 2)
            i = n if end == -1 else end + 2
        elif src.startswith("//", i):
            end = src.find("\n", i)
            i = n if end == -1 else end + 1
        elif c == "#":
            end = src.find("\n", i)
            i = n if end == -1 else end + 1
        else:
            break
    return i


def read_string(src: str, i: int, n: int):
    """Read a quoted PHP literal at src[i]. Returns (decoded, end_index) or None."""
    quote = src[i]
    if quote not in "'\"":
        return None
    escapes = SINGLE_ESCAPES if quote == "'" else DOUBLE_ESCAPES
    out = []
    j = i + 1
    while j < n:
        c = src[j]
        if c == "\\":
            nxt = src[j + 1] if j + 1 < n else ""
            out.append(escapes.get(nxt, "\\" + nxt))
            j += 2
        elif c == quote:
            return "".join(out), j + 1
        else:
            out.append(c)
            j += 1
    return None  # unterminated


def parse_call_args(src: str, i: int, n: int):
    """Parse a parenthesised argument list starting at '(' index i.

    Returns (args, end) where args is a list of dicts:
      {"kind": "str", "value": "..."} or {"kind": "other", "raw": "..."}.
    """
    i += 1  # skip '('
    args = []
    depth = 0
    buf: list[str] = []
    kind = "other"
    while i < n:
        i = skip_ws_and_comments(src, i, n)
        if i >= n:
            break
        c = src[i]
        if c in ")]}":
            if depth == 0:
                if buf or kind != "other":
                    args.append({"kind": kind, "value": "".join(buf), "raw": "".join(buf)})
                return args, i + 1
            depth -= 1
            buf.append(c)
            i += 1
        elif c in "([{":
            depth += 1
            buf.append(c)
            i += 1
        elif c == "," and depth == 0:
            args.append({"kind": kind, "value": "".join(buf), "raw": "".join(buf)})
            buf = []
            kind = "other"
            i += 1
        elif c in "'\"":
            parsed = read_string(src, i, n)
            if parsed is None:
                return None, i
            text, i = parsed
            if kind == "other" and not buf:
                kind = "str"
            elif kind == "str" and buf:
                kind = "other"  # concatenation with non-literal part
            buf.append(text)
        else:
            if depth == 0:
                kind = "other"  # concatenation / variable / expression
            buf.append(c)
            i += 1
    return None, i


def translators_comment(src: str, start: int) -> str:
    prefix = src[max(0, start - 400):start]
    # Last /* translators: ... */ block that ends the window (only ws after it).
    matches = list(re.finditer(r"/\*\s*translators:(?P<c>.*?)\*/", prefix, re.S))
    if matches:
        m = matches[-1]
        if prefix[m.end():].strip() == "":
            return " ".join(m.group("c").split())
    # Last // translators: ... line comment, same trailing-whitespace rule.
    matches = list(re.finditer(r"//[ \t]*translators:(?P<c>[^\n]*)", prefix))
    if matches and prefix[matches[-1].end():].strip() == "":
        return " ".join(matches[-1].group("c").split())
    return ""


# --------------------------------------------------------------------------
# Extraction
# --------------------------------------------------------------------------

class Entry:
    __slots__ = ("msgctxt", "msgid", "msgid_plural", "refs", "comment")

    def __init__(self, msgctxt, msgid, msgid_plural=""):
        self.msgctxt = msgctxt
        self.msgid = msgid
        self.msgid_plural = msgid_plural
        self.refs: list[str] = []
        self.comment = ""

    @property
    def key(self):
        return (self.msgctxt, self.msgid, self.msgid_plural)


def mask_php_comments(src: str) -> str:
    """Blank out PHP/HTML comments (keeping offsets) so `__()` written in a
    docblock is never treated as a real gettext call."""
    out = list(src)
    i, n = 0, len(src)
    in_str = None
    while i < n:
        c = src[i]
        if in_str:
            if c == "\\" and in_str != "'":
                i += 2
                continue
            if c == in_str:
                in_str = None
            i += 1
            continue
        if c in "'\"":
            in_str = c
            i += 1
            continue
        if src.startswith("//", i) or src.startswith("#", i):
            j = src.find("\n", i)
            j = n if j == -1 else j
            for k in range(i, j):
                if out[k] not in "\n":
                    out[k] = " "
            i = j
            continue
        if src.startswith("/*", i):
            j = src.find("*/", i + 2)
            j = n if j == -1 else j + 2
            for k in range(i, j):
                if out[k] not in "\n":
                    out[k] = " "
            i = j
            continue
        i += 1
    return "".join(out)


def extract():
    """Return (entries, skipped) — entries in source order, skipped = report lines."""
    entries: dict[tuple, Entry] = {}
    order: list[tuple] = []
    skipped: list[str] = []

    def add(msgctxt, msgid, msgid_plural, ref, comment):
        key = (msgctxt, msgid, msgid_plural)
        if key not in entries:
            entries[key] = Entry(msgctxt, msgid, msgid_plural)
            order.append(key)
        e = entries[key]
        if ref not in e.refs:
            e.refs.append(ref)
        if comment and not e.comment:
            e.comment = comment

    for path in SOURCE_FILES:
        if not path.exists():
            continue
        raw = path.read_text(encoding="utf-8")
        src = mask_php_comments(raw)
        n = len(src)
        rel = path.relative_to(ROOT).as_posix()
        for m in FUNC_RE.finditer(src):
            func = m.group(1)
            line = src.count("\n", 0, m.start()) + 1
            ref = f"{rel}:{line}"
            args, _end = parse_call_args(src, m.end() - 1, n)
            if args is None:
                skipped.append(f"{ref} — {func}(...) — arguments nerezolvabile (șir concatenat sau dinamic); refactorizați la sprintf() cu text literal.")
                continue
            if not args:
                skipped.append(f"{ref} — {func}() — apel fără argumente (cod în curs de editare?).")
                continue
            str_args = [a for a in args if a["kind"] == "str"]
            if not str_args or args[0]["kind"] != "str":
                skipped.append(f"{ref} — {func}({args[0].get('raw','')[:60]}) — argument dinamic (variabilă); treceți textul literal cu domeniul '{DOMAIN}'.")
                continue
            # Domain: last string arg if it looks like a textdomain, else default.
            domain = None
            if len(args) > 1 and args[-1]["kind"] == "str" and re.fullmatch(r"[a-z0-9_-]+", args[-1]["value"]):
                domain = args[-1]["value"]
            if domain is not None and domain != DOMAIN:
                skipped.append(f"{ref} — domeniu '{domain}' în loc de '{DOMAIN}'.")
                continue
            if domain is None:
                skipped.append(f"{ref} — {func}('{str_args[0]['value'][:60]}...') — fără domeniu; adăugați '{DOMAIN}'.")
            comment = translators_comment(raw, m.start())

            if func == "_n":
                if len(args) < 3 or args[1]["kind"] != "str":
                    skipped.append(f"{ref} — _n() cu plural dinamic; folosiți literal.")
                    continue
                add("", args[0]["value"], args[1]["value"], ref, comment)
            elif func in ("_x", "esc_html_x", "esc_attr_x"):
                if len(args) < 2 or args[1]["kind"] != "str":
                    skipped.append(f"{ref} — {func}() cu text dinamic.")
                    continue
                add(args[0]["value"], args[1]["value"], "", ref, comment)
            elif func == "_nx":
                if len(args) < 4 or args[1]["kind"] != "str" or args[3]["kind"] != "str":
                    skipped.append(f"{ref} — _nx() cu argumente dinamice.")
                    continue
                add(args[3]["value"], args[0]["value"], args[1]["value"], ref, comment)
            else:
                add("", args[0]["value"], "", ref, comment)

    return [entries[k] for k in order], skipped


# --------------------------------------------------------------------------
# PO / POT serialisation
# --------------------------------------------------------------------------

def po_escape(s: str) -> str:
    return (s.replace("\\", "\\\\").replace('"', '\\"')
             .replace("\n", "\\n").replace("\t", "\\t").replace("\r", "\\r"))


def wrap_po_string(s: str, indent: str) -> list[str]:
    """Emit a quoted PO string, wrapping on whitespace near column 77."""
    if len(s) <= 70:
        return [f'{indent}"{po_escape(s)}"']
    lines: list[str] = []
    cur = ""
    for word in s.split(" "):
        candidate = (cur + " " + word) if cur else word
        if len(indent) + len(candidate) + 2 > 77 and cur:
            lines.append(f'{indent}"{po_escape(cur)} "')
            cur = word
        else:
            cur = candidate
    if cur:
        lines.append(f'{indent}"{po_escape(cur)}"')
    return lines


def po_unescape(s: str) -> str:
    out = []
    i, n = 0, len(s)
    mapping = {"n": "\n", "t": "\t", "r": "\r", "\\": "\\", '"': '"'}
    while i < n:
        c = s[i]
        if c == "\\" and i + 1 < n:
            out.append(mapping.get(s[i + 1], s[i + 1]))
            i += 2
        else:
            out.append(c)
            i += 1
    return "".join(out)


def write_pot(entries: list[Entry]) -> None:
    lines = [
        '# Translation template for ADC Marketing.',
        '# Generated by bin/i18n-build.py — do not edit by hand.',
        '#',
        'msgid ""',
        'msgstr ""',
        '"Project-Id-Version: ADC Marketing 2.2.1\\n"',
        '"Report-Msgid-Bugs-To: \\n"',
        '"POT-Creation-Date: ' + _pot_date() + '\\n"',
        '"MIME-Version: 1.0\\n"',
        '"Content-Type: text/plain; charset=UTF-8\\n"',
        '"Content-Transfer-Encoding: 8bit\\n"',
        '"Plural-Forms: nplurals=2; plural=(n != 1);\\n"',
        '',
    ]
    for e in entries:
        if e.comment:
            lines.append(f"#. translators: {e.comment}")
        lines.append(f"#: {' '.join(e.refs)}")
        if e.msgctxt:
            lines.extend(_po_field("msgctxt", e.msgctxt))
        lines.extend(_po_field("msgid", e.msgid))
        if e.msgid_plural:
            lines.extend(_po_field("msgid_plural", e.msgid_plural))
            lines.append('msgstr[0] ""')
            lines.append('msgstr[1] ""')
        else:
            lines.append('msgstr ""')
        lines.append("")
    POT_PATH.write_text("\n".join(lines), encoding="utf-8")


def _po_field(field: str, value: str) -> list[str]:
    wrapped = wrap_po_string(value, "")
    wrapped[0] = f'{field} ' + wrapped[0].lstrip()
    return wrapped


def _pot_date() -> str:
    import datetime
    return datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%d %H:%M+0000")


# --------------------------------------------------------------------------
# PO parsing
# --------------------------------------------------------------------------

class POEntry:
    def __init__(self):
        self.msgctxt = ""
        self.msgid = ""
        self.msgid_plural = ""
        self.msgstr: dict[int, str] = {}
        self.fuzzy = False


def parse_po(path: Path) -> list[POEntry]:
    entries: list[POEntry] = []
    cur = POEntry()
    field = None
    fuzzy = False
    started = False

    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.rstrip("\n")
        stripped = line.strip()
        if stripped.startswith("#,") and "fuzzy" in stripped:
            fuzzy = True
            continue
        if stripped.startswith("#"):
            continue
        if not stripped:
            if started:
                cur.fuzzy = fuzzy
                entries.append(cur)
                cur = POEntry()
                field = None
                fuzzy = False
                started = False
            continue
        m = re.match(r'^(msgctxt|msgid_plural|msgid|msgstr\[\d+\]|msgstr)\s+"(.*)"$', stripped)
        if m:
            field = m.group(1)
            value = po_unescape(m.group(2))
            started = True
            if field == "msgctxt":
                cur.msgctxt = value
            elif field == "msgid":
                cur.msgid = value
            elif field == "msgid_plural":
                cur.msgid_plural = value
            elif field == "msgstr":
                cur.msgstr[0] = value
            else:
                cur.msgstr[int(field[7:-1])] = value
        elif stripped.startswith('"') and field:
            value = po_unescape(stripped[1:-1])
            if field == "msgctxt":
                cur.msgctxt += value
            elif field == "msgid":
                cur.msgid += value
            elif field == "msgid_plural":
                cur.msgid_plural += value
            elif field == "msgstr":
                cur.msgstr[0] = cur.msgstr.get(0, "") + value
            else:
                idx = int(field[7:-1])
                cur.msgstr[idx] = cur.msgstr.get(idx, "") + value
        # ignore anything else
    if started:
        cur.fuzzy = fuzzy
        entries.append(cur)
    return entries


def write_po(path: Path, entries: list[POEntry], language: str, extra_header: str = "") -> None:
    plural = 'nplurals=2; plural=(n != 1);'
    lines = [
        f'# Translation file for ADC Marketing ({language}).',
        '# Generated by bin/i18n-build.py — edit the source of truth:',
        f'#   en_US: {EN_US_PO.name} (hand-translated)',
        '#   en_GB: derived from en_US via the spelling dictionary in bin/i18n-build.py',
        '#',
        'msgid ""',
        'msgstr ""',
        f'"Project-Id-Version: ADC Marketing 2.2.1\\n"',
        '"Report-Msgid-Bugs-To: \\n"',
        f'"PO-Revision-Date: {_pot_date()}\\n"',
        '"Last-Translator: ADC Marketing build\\n"',
        '"Language-Team: English\\n"',
        f'"Language: {language}\\n"',
        '"MIME-Version: 1.0\\n"',
        '"Content-Type: text/plain; charset=UTF-8\\n"',
        '"Content-Transfer-Encoding: 8bit\\n"',
        f'"Plural-Forms: {plural}\\n"',
        '',
    ]
    for e in entries:
        if e.msgctxt:
            lines.extend(_po_field("msgctxt", e.msgctxt))
        lines.extend(_po_field("msgid", e.msgid))
        if e.msgid_plural:
            lines.extend(_po_field("msgid_plural", e.msgid_plural))
            n = max(e.msgstr.keys(), default=0) + 1
            for i in range(n):
                lines.extend(_po_field(f"msgstr[{i}]", e.msgstr.get(i, "")))
        else:
            lines.extend(_po_field("msgstr", e.msgstr.get(0, "")))
        lines.append("")
    path.write_text("\n".join(lines), encoding="utf-8")


# --------------------------------------------------------------------------
# MO compilation (GNU MO, little-endian, sorted tables)
# --------------------------------------------------------------------------

MO_MAGIC = 0x950412DE


def compile_mo(entries: list[POEntry], path: Path) -> None:
    pairs: list[tuple[bytes, bytes]] = []
    for e in entries:
        if e.fuzzy:
            continue
        if not e.msgid and not e.msgstr.get(0):
            continue
        if e.msgid_plural:
            key = (e.msgid + "\x00" + e.msgid_plural).encode("utf-8")
            vals = [e.msgstr.get(i, "") for i in range(max(e.msgstr.keys(), default=0) + 1)]
            val = "\x00".join(vals).encode("utf-8")
        else:
            text = e.msgstr.get(0, "")
            if e.msgid != "" and text == "":
                continue  # untranslated — skip (validated separately)
            key = e.msgid.encode("utf-8")
            val = text.encode("utf-8")
        pairs.append((key, val))
    pairs.sort(key=lambda kv: kv[0])

    originals = b"".join(k + b"\x00" for k, _ in pairs)
    translations = b"".join(v + b"\x00" for _, v in pairs)
    count = len(pairs)
    header_size = 7 * 4
    o_table_off = header_size
    t_table_off = o_table_off + count * 8
    str_off = t_table_off + count * 8

    out = struct.pack("<7I", MO_MAGIC, 0, count, o_table_off, t_table_off, 0, 0)
    o_table = bytearray()
    t_table = bytearray()
    o_cursor = str_off
    for k, v in pairs:
        o_table += struct.pack("<II", len(k), o_cursor)
        o_cursor += len(k) + 1
    t_cursor = str_off + len(originals)
    for _, v in pairs:
        t_table += struct.pack("<II", len(v), t_cursor)
        t_cursor += len(v) + 1
    path.write_bytes(bytes(out + o_table + t_table + originals + translations))


# --------------------------------------------------------------------------
# en_GB derivation (US → GB spelling dictionary, msgstr only)
# --------------------------------------------------------------------------

# Stem rules: replace the leading US spelling, keep the rest of the word
# (so "customize/customizing/centralization" style families all work).
_GB_STEMS: dict[str, str] = {
    "customiz": "customis",
    "organiz": "organis",
    "recogniz": "recognis",
    "minimiz": "minimis",
    "maximiz": "maximis",
    "optimiz": "optimis",
    "prioritiz": "prioritis",
    "categoriz": "categoris",
    "normaliz": "normalis",
    "stabiliz": "stabilis",
    "summariz": "summaris",
    "utilis": "utilis",
    "standardiz": "standardis",
    "authoriz": "authoris",
    "color": "colour",
    "behavior": "behaviour",
    "favor": "favour",
    "honor": "honour",
    "center": "centre",
    "analyz": "analys",
}

# Whole-word rules (irregular or context-sensitive ones).
_GB_WORDS: dict[str, str] = {
    "gray": "grey",
    "defense": "defence",
    "license": "licence",
    "licenses": "licences",
    "dialog": "dialogue",
    "dialogs": "dialogues",
    "canceled": "cancelled",
    "canceling": "cancelling",
    "labeled": "labelled",
    "labeling": "labelling",
    "traveled": "travelled",
    "traveling": "travelling",
    "modeled": "modelled",
    "modeling": "modelling",
    "signaled": "signalled",
    "signaling": "signalling",
}

# -ize family, handled by suffix so every inflection is covered.
_GB_SUFFIXES: tuple[tuple[str, str], ...] = (
    ("izations", "isations"),
    ("ization", "isation"),
    ("izing", "ising"),
    ("ized", "ised"),
    ("izes", "ises"),
    ("ize", "ise"),
)

_WORD_RE = re.compile(r"[A-Za-z][A-Za-z']*")

# Words that must NOT be touched even though a stem/suffix matches: "size" and
# "prize" are spelled with -ize in both variants, and GB keeps "seize".
_GB_KEEP = {"size", "sizes", "sized", "sizing", "prize", "prizes", "seize", "seized",
            "seizes", "seizing", "program", "programs", "programming"}


def _gb_word(word: str) -> str:
    low = word.lower()
    if low in _GB_KEEP:
        return word
    out = _GB_WORDS.get(low)
    if out is None:
        for suf, gb in _GB_SUFFIXES:
            if low.endswith(suf):
                out = low[: -len(suf)] + gb
                break
    if out is None:
        for stem, gb in _GB_STEMS.items():
            if low.startswith(stem):
                out = gb + low[len(stem):]
                break
    if out is None:
        return word
    if word[:1].isupper():
        out = out[:1].upper() + out[1:]
    return out


def to_gb(text: str) -> str:
    """Apply the US -> GB spelling dictionary to a translated string."""
    return _WORD_RE.sub(lambda m: _gb_word(m.group(0)), text)


def derive_en_gb(us_entries: list[POEntry]) -> list[POEntry]:
    gb: list[POEntry] = []
    for e in us_entries:
        if not e.msgid:
            continue  # header is regenerated by write_po, never copied verbatim
        n = POEntry()
        n.msgctxt = e.msgctxt
        n.msgid = e.msgid
        n.msgid_plural = e.msgid_plural
        n.msgstr = {i: to_gb(v) for i, v in e.msgstr.items()}
        gb.append(n)
    return gb


# --------------------------------------------------------------------------
# Steps
# --------------------------------------------------------------------------

def step_extract() -> list[Entry]:
    entries, skipped = extract()
    write_pot(entries)
    print(f"[extract] {len(entries)} șiruri unice -> {POT_PATH.relative_to(ROOT)}")
    if skipped:
        print(f"[extract] {len(skipped)} apeluri omise:")
        for s in skipped:
            print(f"  - {s}")
    return entries


def step_check(pot_entries: list[Entry]) -> list[POEntry]:
    if not EN_US_PO.exists():
        sys.exit(f"[check] lipsește {EN_US_PO}")
    po = parse_po(EN_US_PO)
    po_map = {(e.msgctxt, e.msgid, e.msgid_plural): e for e in po}
    missing = []
    empty = []
    for pe in pot_entries:
        key = (pe.msgctxt, pe.msgid, pe.msgid_plural)
        e = po_map.get(key)
        if e is None:
            missing.append(pe.msgid[:70])
        elif any(v == "" for v in e.msgstr.values()) or not e.msgstr:
            empty.append(pe.msgid[:70])
    if missing or empty:
        for m in missing:
            print(f"  LIPSĂ: {m}")
        for m in empty:
            print(f"  MSGSTR GOL: {m}")
        sys.exit(f"[check] FAIL — {len(missing)} lipsă, {len(empty)} goale")
    print(f"[check] OK — {len(po_map)} intrări en_US acoperă toate cele {len(pot_entries)} șiruri din .pot")
    return po


def step_derive_and_compile(us_entries: list[POEntry]) -> None:
    gb_entries = derive_en_gb(us_entries)
    write_po(EN_GB_PO, gb_entries, "en_GB")
    # The MO catalogue keeps one header entry (msgid "") carrying the metadata;
    # for en_GB the Language line is corrected, the rest is identical.
    gb_mo = [e for e in us_entries if not e.msgid]
    if gb_mo:
        hdr = POEntry()
        hdr.msgstr = {0: re.sub(r"Language: [A-Za-z_]+", "Language: en_GB", gb_mo[0].msgstr.get(0, ""))}
        gb_mo = [hdr]
    compile_mo(us_entries, EN_US_MO)
    compile_mo(gb_mo + gb_entries, EN_GB_MO)
    print(f"[compile] {EN_US_MO.name} + {EN_GB_MO.name} scrise; {EN_GB_PO.name} derivat din en_US")


def main() -> None:
    cmd = sys.argv[1] if len(sys.argv) > 1 else "all"
    LANG_DIR.mkdir(parents=True, exist_ok=True)
    if cmd in ("extract", "all"):
        pot = step_extract()
    if cmd == "extract":
        return
    if cmd in ("check", "all", "compile", "derive"):
        us = step_check(pot)
        if cmd == "check":
            return
        step_derive_and_compile(us)
    print("[done]")


if __name__ == "__main__":
    main()
