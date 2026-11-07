#!/usr/bin/env python3
"""Builds the 22-29 judge label set from the live run (v2, 2026-11-07).

Each pair is one cited sentence and one citation from a recorded answer.
The excerpt shown to the labelers must be exactly the text the model saw
for that citation in *that answer's own record*: the registry excerpt for
the cited key, in the jsonl line for the same case and persona.

Why v2: the v1 label set (22-29-LABEL-SET.json, commit 99d123d, builder
not committed) looked excerpts up by page across all records. For P03-P11,
P13 and P14 it took another case's retrieval of the same page (RQ-05 or
RQ-06 chunks), and for P02 it showed a 600-character window that cut off
the supporting text. Those labels were made against text the model never
saw.

Usage:
  python3 -I scripts/eval/build_label_set.py <live-run.jsonl> <v1.json> <out.json>

Keeps every v1 field and the v1 pair ids and sentences; replaces
source_excerpt with the own-record excerpt; adds excerpt_source,
record_line, source_key, v1_window_from_own_record. Exits 1 if a pair's sentence isn't in its
own record's answer, or its citation isn't in that record.
"""

import json
import re
import sys


def norm(text):
    return re.sub(r"\s+", " ", text).strip()


def plain(text):
    """Text without markdown emphasis, [n] markers or punctuation spacing."""
    text = re.sub(r"\[\d+\]", "", text)
    text = re.sub(r"&\w+;", " ", text)
    text = re.sub(r"\]\([^)]*\)", " ", text)
    text = re.sub(r"[^A-Za-z0-9]+", " ", text)
    return norm(text).lower()


def main(jsonl_path, v1_path, out_path):
    records = [json.loads(line) for line in open(jsonl_path, encoding="utf-8")]
    v1 = json.load(open(v1_path, encoding="utf-8"))
    out = []
    errors = []
    for pair in v1:
        lines = [
            i for i, r in enumerate(records, 1)
            if r["case"] == pair["case"] and r["persona"] == pair["persona"]
            and r.get("status") != "INCOMPLETE" and r.get("outcome") == "cited"
        ]
        if not lines:
            errors.append(f"{pair['id']}: no cited record for {pair['case']} {pair['persona']}")
            continue
        line = lines[-1]
        record = records[line - 1]
        if plain(pair["sentence"]) not in plain(record["answer"]):
            errors.append(f"{pair['id']}: sentence not in the answer of line {line}")
            continue
        keys = [c["key"] for c in record["citations"] if c["url"] == pair["citation_url"]]
        if len(keys) != 1:
            errors.append(f"{pair['id']}: citation {pair['citation_url']} not found once in line {line}")
            continue
        excerpts = [g["excerpt"] for g in record["registry"] if g["key"] == keys[0]]
        if len(excerpts) != 1:
            errors.append(f"{pair['id']}: {len(excerpts)} registry entries for {keys[0]} in line {line}")
            continue
        excerpt = excerpts[0]
        new = dict(pair)
        new["source_excerpt"] = excerpt
        new["excerpt_is_whole"] = True
        new["excerpt_chars_model_saw"] = len(excerpt)
        new["excerpt_source"] = "own record: registry excerpt for the cited key"
        new["record_line"] = line
        new["source_key"] = keys[0]
        # v1 showed shortened windows ("…" between fragments), so every
        # excerpt differs; what matters is whether v1's window came from
        # this record's excerpt at all.
        # Whole fragments, minus their first and last word (cut mid-word).
        fragments = [" ".join(plain(f).split()[1:-1]) for f in pair["source_excerpt"].split("…")]
        fragments = [f for f in fragments if len(f) > 40]
        new["v1_window_from_own_record"] = bool(fragments) and all(f in plain(excerpt) for f in fragments)
        out.append(new)
    if errors:
        print("\n".join(errors), file=sys.stderr)
        return 1
    with open(out_path, "w", encoding="utf-8") as fh:
        json.dump(out, fh, ensure_ascii=False, indent=1)
        fh.write("\n")
    foreign = [p["id"] for p in out if not p["v1_window_from_own_record"]]
    print(f"{len(out)} pairs written; v1 window not from the pair's own record for {len(foreign)}: {', '.join(foreign)}")
    return 0


if __name__ == "__main__":
    if len(sys.argv) != 4:
        print(__doc__, file=sys.stderr)
        sys.exit(2)
    sys.exit(main(*sys.argv[1:]))
