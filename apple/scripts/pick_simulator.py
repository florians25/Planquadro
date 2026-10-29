#!/usr/bin/env python3
"""Wählt das neueste verfügbare Simulator-Gerät (für CI).

Aufruf: xcrun simctl list devices available -j | python3 pick_simulator.py iphone|ipad
Ausgabe: <UDID>\t<Name>\t<Laufzeit>

iPhone: bevorzugt „iPhone NN Pro“ (nicht Max) mit der höchsten Nummer auf der neuesten
iOS-Laufzeit, sonst irgendein neuestes iPhone. iPad: „iPad Pro 13-inch“, sonst neuestes iPad.
"""
import json
import re
import sys


def version(runtime):
    m = re.search(r"iOS-(\d+)-(\d+)(?:-(\d+))?", runtime)
    return tuple(int(x or 0) for x in m.groups()) if m else (0, 0, 0)


def number(name):
    m = re.search(r"(\d+)", name)
    return int(m.group(1)) if m else 0


def main():
    kind = (sys.argv[1] if len(sys.argv) > 1 else "iphone").lower()
    data = json.load(sys.stdin)["devices"]
    candidates = []
    for runtime, devices in data.items():
        if "iOS" not in runtime:
            continue
        for d in devices:
            if not d.get("isAvailable", True):
                continue
            n = d["name"]
            if kind == "iphone" and n.startswith("iPhone"):
                score = (2 if re.fullmatch(r"iPhone \d+ Pro", n) else 1 if "Pro" in n and "Max" not in n else 0, number(n))
            elif kind == "ipad" and n.startswith("iPad"):
                score = (2 if n.startswith("iPad Pro 13") else 1 if n.startswith("iPad Pro") else 0, n)
            else:
                continue
            candidates.append((version(runtime), score, d["udid"], n, runtime))
    if not candidates:
        sys.exit(f"Kein {kind}-Simulator gefunden")
    best = max(candidates, key=lambda c: (c[0], c[1]))
    print(f"{best[2]}\t{best[3]}\t{best[4]}")


if __name__ == "__main__":
    main()
