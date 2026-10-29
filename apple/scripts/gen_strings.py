#!/usr/bin/env python3
"""Erzeugt/aktualisiert apple/App/Resources/Localizable.xcstrings (Quellsprache Deutsch).

Sammelt die festen Texte aus SwiftUI-Aufrufen mit LocalizedStringKey (Text, Label, Button,
Toggle, Section, navigationTitle, …) in App/, Shared/ und Widgets/. Texte mit Interpolation
legt Xcode beim Bauen selbst an (SWIFT_EMIT_LOC_STRINGS). Bestehende Übersetzungen bleiben
erhalten. Aufruf: python3 apple/scripts/gen_strings.py
"""
import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, "App", "Resources", "Localizable.xcstrings")
DIRS = ["App", "Shared", "Widgets"]

CALLS = r"(?:Text|Label|Button|Toggle|Section|TextField|SecureField|Picker|LabeledContent|ProgressView|DatePicker|Link|Tab|" \
        r"ContentUnavailableView|\.navigationTitle|\.confirmationDialog|\.configurationDisplayName|\.description|" \
        r"\.searchable\(text: \$\w+(?:, placement: [^,]+)?, prompt:)"
PATTERN = re.compile(CALLS + r"\(\s*\"((?:[^\"\\]|\\.)*)\"")
PROMPT = re.compile(r"prompt: \"((?:[^\"\\]|\\.)*)\"")


def collect():
    found = {}
    for d in DIRS:
        for base, _, files in os.walk(os.path.join(ROOT, d)):
            for f in sorted(files):
                if not f.endswith(".swift"):
                    continue
                path = os.path.join(base, f)
                rel = os.path.relpath(path, ROOT)
                src = open(path, encoding="utf-8").read()
                for rx in (PATTERN, PROMPT):
                    for m in rx.finditer(src):
                        s = m.group(1)
                        if "\\(" in s or not s.strip():
                            continue
                        s = s.encode().decode("unicode_escape").encode("latin-1").decode("utf-8")
                        found.setdefault(s, set()).add(rel)
    return found


def main():
    existing = {}
    if os.path.exists(OUT):
        existing = json.load(open(OUT, encoding="utf-8")).get("strings", {})
    found = collect()
    strings = {}
    for key in sorted(set(found) | set(existing)):
        entry = dict(existing.get(key, {}))
        if key in found:
            entry["comment"] = "Verwendet in " + ", ".join(sorted(found[key]))
            entry.pop("extractionState", None)
        else:
            entry["extractionState"] = "stale"
        strings[key] = entry
    doc = {"sourceLanguage": "de", "strings": strings, "version": "1.0"}
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, "w", encoding="utf-8") as fh:
        json.dump(doc, fh, ensure_ascii=False, indent=2, sort_keys=True)
        fh.write("\n")
    print(f"{len(strings)} Texte → {os.path.relpath(OUT, os.path.dirname(ROOT))}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
