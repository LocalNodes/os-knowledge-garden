#!/usr/bin/env python3
"""Read-outs over a live-run jsonl and a two-model labels file (22.2-15).

Python standard library only. Run with `python3 -I`.

Usage:
  python3 -I scripts/eval/rerun_stats.py spend <jsonl> [--from-line=N] [--to-line=M]
  python3 -I scripts/eval/rerun_stats.py cited <jsonl> --cases=RQ-01,RQ-18 [--from-line=N] [--to-line=M]
  python3 -I scripts/eval/rerun_stats.py gate <labels.json>

spend
  One line over the run records in range:
  `lines A-B: runs R; chat sent C refused X; embeddings sent E; tokens in I out O reasoning T; est $D.DDDD`
  Chat is priced as ai_health_report.php prices gemini-3.8-flash: $0.75 per
  1M input tokens, $3.75 per 1M output plus reasoning tokens. Embedding
  calls are counted, not priced. Exit 0.

cited
  Per case: the complete records (status other than INCOMPLETE) in range,
  in line order. Only the FIRST 5 complete records count: an INCOMPLETE
  record may be replaced by the next run, a complete one never. Exit 0 only
  if every case has exactly 5 complete records and at least 4 of them have
  outcome `cited`. Exit 1 otherwise, including more than 5 complete records
  (no retrying until the tail looks good).

gate
  Needs the rerun-6 labels shape: an object with `note`, `counts` and
  `items[]`, each item with `id`, `case`, `door`, `members_only` (bool) and
  `verdict` (supported, partly or not). Any other shape exits 2. Counts are
  computed from the items, never read from `counts`. Prints `Gate: PASS`
  and exits 0 only when no item is `not`, `partly` is at or below 10% of
  the labelled items, and no members-only item is `partly` or `not`.
  Otherwise prints `Gate: FAIL (<why>)` and exits 1.
"""

import json
import sys

PRICE_IN = 0.75 / 1_000_000
PRICE_OUT = 3.75 / 1_000_000
CITED_RUNS = 5
CITED_MIN = 4
PARTLY_MAX = 0.10
VERDICTS = ("supported", "partly", "not")


def usage(message):
    print(message, file=sys.stderr)
    print(__doc__.split("\n\n")[1], file=sys.stderr)
    sys.exit(2)


def options(args):
    """Splits --key=value options from positional arguments."""
    opts, pos = {}, []
    for arg in args:
        if arg.startswith("--"):
            key, _, value = arg[2:].partition("=")
            opts[key] = value
        else:
            pos.append(arg)
    return opts, pos


def int_opt(opts, key, default):
    if key not in opts:
        return default
    try:
        return int(opts[key])
    except ValueError:
        usage(f"--{key} must be a whole number, got {opts[key]!r}")


def records(path, from_line, to_line):
    """Yields (line number, record) for the lines in range, 1-based, inclusive."""
    with open(path, encoding="utf-8") as handle:
        for number, line in enumerate(handle, 1):
            if number < from_line:
                continue
            if to_line is not None and number > to_line:
                break
            line = line.strip()
            if not line:
                continue
            yield number, json.loads(line)


def is_run(record):
    """A run record carries a status; RQ-13 'mutate' notes don't."""
    return isinstance(record, dict) and "status" in record


def count(requests, kind, key):
    if not isinstance(requests, dict):
        return 0
    value = (requests.get(kind) or {}).get(key) or 0
    return int(value)


def spend(args):
    opts, pos = options(args)
    if len(pos) != 1:
        usage("spend needs one jsonl path")
    from_line = int_opt(opts, "from-line", 1)
    to_line = int_opt(opts, "to-line", None)
    first = last = None
    runs = chat = refused = embeddings = 0
    tok_in = tok_out = tok_reason = 0
    for number, record in records(pos[0], from_line, to_line):
        first = number if first is None else first
        last = number
        if not is_run(record):
            continue
        runs += 1
        requests = record.get("requests")
        chat += count(requests, "chat", "sent")
        refused += count(requests, "chat", "refused")
        embeddings += count(requests, "embeddings", "sent")
        tokens = record.get("tokens") or {}
        tok_in += int(tokens.get("input") or 0)
        tok_out += int(tokens.get("output") or 0)
        tok_reason += int(tokens.get("reasoning") or 0)
    dollars = tok_in * PRICE_IN + (tok_out + tok_reason) * PRICE_OUT
    span = f"{first}-{last}" if first is not None else "none"
    print(
        f"lines {span}: runs {runs}; chat sent {chat} refused {refused}; "
        f"embeddings sent {embeddings}; tokens in {tok_in} out {tok_out} "
        f"reasoning {tok_reason}; est ${dollars:.4f}"
    )
    return 0


def cited(args):
    opts, pos = options(args)
    if len(pos) != 1:
        usage("cited needs one jsonl path")
    cases = [c.strip() for c in opts.get("cases", "").split(",") if c.strip()]
    if not cases:
        usage("cited needs --cases=RQ-01,RQ-18")
    from_line = int_opt(opts, "from-line", 1)
    to_line = int_opt(opts, "to-line", None)
    seen = {case: {"complete": [], "incomplete": []} for case in cases}
    for number, record in records(pos[0], from_line, to_line):
        if not is_run(record) or record.get("case") not in seen:
            continue
        bucket = "incomplete" if record.get("status") == "INCOMPLETE" else "complete"
        seen[record["case"]][bucket].append((number, record.get("outcome")))
    ok = True
    for case in cases:
        complete = seen[case]["complete"]
        counted = complete[:CITED_RUNS]
        hits = sum(1 for _, outcome in counted if outcome == "cited")
        lines = ",".join(str(n) for n, _ in counted) or "none"
        outcomes = ",".join(str(o) for _, o in counted) or "none"
        print(
            f"{case} cited {hits} of {len(counted)} "
            f"(first {CITED_RUNS} complete; lines {lines}; outcomes {outcomes}); "
            f"complete in range {len(complete)}; incomplete {len(seen[case]['incomplete'])}"
        )
        if len(complete) < CITED_RUNS:
            print(f"{case}: FAIL, fewer than {CITED_RUNS} complete runs in range")
            ok = False
        elif len(complete) > CITED_RUNS:
            print(f"{case}: FAIL, more than {CITED_RUNS} complete runs in range")
            ok = False
        elif hits < CITED_MIN:
            print(f"{case}: FAIL, cited in fewer than {CITED_MIN} of {CITED_RUNS}")
            ok = False
    return 0 if ok else 1


def shape_error(data):
    """Returns why the labels file isn't the rerun-6 shape, or None."""
    if not isinstance(data, dict):
        return f"top level is a {type(data).__name__}, not an object with note, counts and items"
    for key in ("note", "counts", "items"):
        if key not in data:
            return f"no '{key}' key"
    items = data["items"]
    if not isinstance(items, list) or not items:
        return "'items' is not a non-empty list"
    ids = set()
    for index, item in enumerate(items):
        if not isinstance(item, dict):
            return f"items[{index}] is not an object"
        for key in ("id", "case", "door", "members_only", "verdict"):
            if key not in item:
                return f"items[{index}] has no '{key}'"
        if not isinstance(item["members_only"], bool):
            return f"items[{index}].members_only is not true or false"
        if item["verdict"] not in VERDICTS:
            return f"items[{index}].verdict is {item['verdict']!r}, not one of {', '.join(VERDICTS)}"
        if item["id"] in ids:
            return f"id {item['id']} appears twice"
        ids.add(item["id"])
    return None


def gate(args):
    _, pos = options(args)
    if len(pos) != 1:
        usage("gate needs one labels file")
    try:
        with open(pos[0], encoding="utf-8") as handle:
            data = json.load(handle)
    except (OSError, ValueError) as error:
        print(f"Shape rejected: {error}")
        return 2
    problem = shape_error(data)
    if problem:
        print(f"Shape rejected: {problem}")
        return 2
    items = data["items"]
    counts = {verdict: 0 for verdict in VERDICTS}
    for item in items:
        counts[item["verdict"]] += 1
    total = len(items)
    share = counts["partly"] / total
    print(
        f"labelled {total}: supported {counts['supported']}, partly {counts['partly']}, "
        f"not {counts['not']}; partly share {share:.1%}"
    )
    flagged = [item for item in items if item["verdict"] != "supported"]
    members_bad = [item for item in flagged if item["members_only"]]
    for item in flagged:
        mark = " (members-only)" if item["members_only"] else ""
        print(f"  {item['id']} {item['case']} {item['door']}: {item['verdict']}{mark}")
    reasons = []
    if counts["not"]:
        reasons.append(f"{counts['not']} not supported")
    if share > PARTLY_MAX:
        reasons.append(f"partly {share:.1%} is over {PARTLY_MAX:.0%}")
    if members_bad:
        reasons.append(
            "members-only " + ", ".join(f"{i['id']} {i['verdict']}" for i in members_bad)
        )
    if reasons:
        print(f"Gate: FAIL ({'; '.join(reasons)})")
        return 1
    print("Gate: PASS")
    return 0


def main(argv):
    if len(argv) < 2 or argv[1] not in ("spend", "cited", "gate"):
        usage("first argument must be spend, cited or gate")
    return {"spend": spend, "cited": cited, "gate": gate}[argv[1]](argv[2:])


if __name__ == "__main__":
    sys.exit(main(sys.argv))
