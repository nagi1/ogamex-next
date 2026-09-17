#!/usr/bin/env python3
"""Compare the capacity-run reports: hybrid vs native at 2/5/10 accounts.

Reads the six `storage/capacity/*.json` reports and prints one row per run plus a
native-vs-hybrid delta per population. This is the comparison REV-004 records.
"""

from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "storage" / "capacity"


def load(name: str) -> dict:
    path = OUT / f"{name}.json"
    if not path.exists():
        return {}
    return json.loads(path.read_text())


def main() -> None:
    rows = []
    for mode, db in (("hybrid", "ogamex-cap"), ("native", "ogamex-cap-native")):
        for n in (2, 5, 10):
            r = load(f"{db}-{n}")
            if not r:
                print(f"missing {db}-{n}.json")
                continue
            rows.append(
                {
                    "mode": mode,
                    "n": n,
                    "profiles": r["profiles"],
                    "completed": r["work"]["completed"],
                    "accepted": r["actions"].get("Accepted", 0),
                    "p50": round(r["lateness"]["p50"], 2),
                    "p95": round(r["lateness"]["p95"], 2),
                    "sessions": r["lateness"]["sessions"],
                    "attempts": r["language"]["attempts"],
                    "read_ms": round(r["read_cost"]["milliseconds"], 1),
                }
            )

    print(f"{'mode':7} {'n':>2} {'prof':>4} {'done':>4} {'acc':>4} {'p50min':>6} {'p95min':>6} {'sess':>5} {'llm':>3} {'read_ms':>7}")
    for r in rows:
        print(
            f"{r['mode']:7} {r['n']:>2} {r['profiles']:>4} {r['completed']:>4} {r['accepted']:>4} "
            f"{r['p50']:>6} {r['p95']:>6} {r['sessions']:>5} {r['attempts']:>3} {r['read_ms']:>7}"
        )

    # native vs hybrid delta per population
    hybrid = {r["n"]: r for r in rows if r["mode"] == "hybrid"}
    native = {r["n"]: r for r in rows if r["mode"] == "native"}
    print("\ndelta (native - hybrid):")
    for n in (2, 5, 10):
        h, v = hybrid.get(n), native.get(n)
        if not h or not v:
            continue
        print(
            f"  n={n}: completed {v['completed']-h['completed']:>+3}, accepted {v['accepted']-h['accepted']:>+3}, "
            f"p95 {v['p95']-h['p95']:>+.2f} min, read {v['read_ms']-h['read_ms']:>+.1f} ms"
        )


if __name__ == "__main__":
    main()
