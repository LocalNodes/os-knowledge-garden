#!/usr/bin/env python3
"""Read-outs over a live-run jsonl and a two-model labels file (22.2-15).

Python standard library only. Run with `python3 -I`.

Usage:
  python3 -I scripts/eval/rerun_stats.py spend <jsonl> [--from-line=N] [--to-line=M] [--price-in=USD] [--price-out=USD]
  python3 -I scripts/eval/rerun_stats.py cited <jsonl> --cases=RQ-01,RQ-18 [--from-line=N] [--to-line=M]
  python3 -I scripts/eval/rerun_stats.py gate <labels.json> [--sublabels=F] [--jsonl=P --from-line=N --to-line=M [--door4=F]]
  python3 -I scripts/eval/rerun_stats.py pooled <manifest.json>

spend
  One line over the run records in range:
  `lines A-B: runs R; chat sent C refused X; embeddings sent E; tokens in I out O reasoning T; est $D.DDDD`
  Chat is priced per model from the PRICES table, which has one entry: as
  ai_health_report.php prices models/gemini-3.8-flash, $0.75 per 1M input
  tokens, $3.75 per 1M output plus reasoning tokens. Embedding calls are
  counted, not priced. Exit 0.

  The first line's est $ is the sum over the models that have a price (all
  of them if --price-in and --price-out are given), so it is unchanged for a
  jsonl from before the runner recorded the model.

  After it, one line per model:
  `  <model>: tokens in I out O reasoning T; est $D.DDDD`, or
  `; unpriced (no rate on file)` when the model has no entry in PRICES. Tokens
  are grouped by chat_calls[].model, each call's own token counts. Whatever
  the record's `tokens` hold beyond its calls' sum, and every record or call
  with no model (a jsonl from before 22.2-ORCH-INBOX "timing of the flash-lite
  backup gate"), counts under `unknown model (assumed models/gemini-3.8-flash)`
  and is priced at the 3.8-flash rate. No rate is ever guessed for another model.

  --price-in=USD and --price-out=USD (dollars per 1M tokens) replace the rate
  for every model printed, for when the orchestrator supplies one. Giving only
  one replaces just that side; a model with no rate on the other side stays
  unpriced.

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
  computed from the items, never read from `counts`.

  Criteria (Tom ruling 2026-11-08, 23-RULINGS-1108.md; replaced the 10%
  partly limit). Zero tolerance, any one fails the run:
    - an item labelled `not`;
    - a `partly` item sub-labelled `misattribution`;
    - a `partly` item with no sub-label: treated as unsure and counted
      strictly, as a zero-tolerance hit (an old label file with partly items
      therefore fails until its partly items are sub-labelled);
    - a `partly` item in a members-only answer (kept from the old gate).
  Allowed: `partly` items up to 20% of the labelled items (20.0% passes;
  checked in whole numbers: partly x 100 > 20 x labelled fails).

  Legacy rule (orchestrator note, 2026-11-08): a label file with no
  `sublabel` on any item, given without --sublabels, predates the sub-label
  and is scored under the rule of its own date (22.2-15): no item `not`,
  `partly` at most 10% of the labelled items, no members-only `partly`, and
  no sub-label requirement. Its result line says `legacy rule`, for example
  `Gate: PASS (legacy rule)`. A file with no `partly` items scores the same
  under either rule; it still prints `legacy rule` when it has no sub-labels.

  Sub-labels: a `partly` item carries `sublabel` (`loose` or
  `misattribution`), either inside the labels item or in a sidecar file
  given as --sublabels=FILE: an object with `items[]` of {`id`, `sublabel`}
  (the ...-PARTLY-SUBLABELS-CONSENSUS.json files). A sub-label on an item
  that isn't `partly`, an unknown id or value, or two different values for
  one id exit 2.

  With --jsonl=PATH --from-line=N --to-line=M (one run's lines of a
  verify_cited_answers.php jsonl) the gate also fails the run on:
    - any `leaks` or `invariant_violations` entry;
    - any record `critical` line except the waived pair (E1 positive
      control on RQ-11 P-MEM and on RQ-20 P-MEM);
    - fewer than 10 records with outcome `cited`;
    - not exactly one NONSENSE record without an error in range (Door 4
      can't be checked);
    - a Door 4 hit without a `hedged` verdict.
  Door 4 hits are recomputed from the jsonl by the rule in
  verify_cited_answers.php crossChecks(): a record with door `answer`,
  expect `no_answer`, no error and a scope, whose answer differs from the
  NONSENSE record's answer. The runner's CRITICAL and E5 lines are not
  changed; the judged verdicts are a separate input, --door4=FILE: an
  object with `items[]` of {`line` (jsonl line number), `case`, `persona`,
  `answer_sha256` (sha256 of the answer text), `verdict` (`hedged` or
  `presented_as_answer`)}. A verdict that matches no hit, or whose case,
  persona or hash differs from the hit's record, exits 2. The other
  cross-run checks (E2 forged-scope sameness, the RQ-20 overview cache)
  are not in the jsonl and are not recomputed here.

  Prints `Gate: PASS` and exits 0, or `Gate: FAIL (<why>)` and exits 1.

pooled
  `pooled <manifest.json>`: {"models": [{"name", "runs": [{"labels",
  "sublabels"?, "door4"?, "jsonl", "from_line", "to_line"}]}]}, paths
  relative to the manifest. Runs the per-run gate on every run, then per
  model prints two readings (23-MODEL-GATE.md, "Current criteria"):
    - deciding (pooled counts): every run zero-tolerance clean, pooled
      partly share at most 20%, at least 30 cited answers pooled;
    - stricter (every run): every run passes the per-run gate on its own.
  Exits 0 only if every model passes the deciding reading. A run scored
  under the legacy rule says so on its line; a model with any legacy run
  uses the legacy 10% share for its pooled reading.
"""

import hashlib
import json
import os
import sys

ASSUMED_MODEL = "models/gemini-3.8-flash"
UNKNOWN_LABEL = f"unknown model (assumed {ASSUMED_MODEL})"
# USD per token, by model id: (input, output and reasoning). Only models whose
# rate is known belong here; never add a guessed price.
PRICES = {
    ASSUMED_MODEL: (0.75 / 1_000_000, 3.75 / 1_000_000),
}
CITED_RUNS = 5
CITED_MIN = 4
PARTLY_MAX_PERCENT = 20
LEGACY_PARTLY_MAX_PERCENT = 10
FLOOR_RUN = 10
FLOOR_POOLED = 30
VERDICTS = ("supported", "partly", "not")
SUBLABELS = ("loose", "misattribution")
DOOR4_VERDICTS = ("hedged", "presented_as_answer")
WAIVED = {("RQ-11", "P-MEM"), ("RQ-20", "P-MEM")}
WAIVED_PREFIX = "E1 positive control"


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


TOKEN_KEYS = ("input", "output", "reasoning")


def float_opt(opts, key):
    """A non-negative dollar amount, or None when the option is absent."""
    if key not in opts:
        return None
    try:
        value = float(opts[key])
    except ValueError:
        usage(f"--{key} must be a number (USD per 1M tokens), got {opts[key]!r}")
    if value < 0:
        usage(f"--{key} must not be negative, got {opts[key]!r}")
    return value


def add_tokens(by_model, model, amounts):
    """Adds [input, output, reasoning] to a model's totals (None is no model)."""
    if not any(amounts):
        return
    total = by_model.setdefault(model, [0, 0, 0])
    for index, amount in enumerate(amounts):
        total[index] += amount


def rate_for(model, override_in, override_out):
    """(USD per input token, USD per output or reasoning token) or None if unpriced.

    No model means a jsonl from before the runner recorded it: priced as the
    assumed model. A --price-in or --price-out replaces that side of the rate."""
    known = PRICES.get(ASSUMED_MODEL if model is None else model)
    rate_in = override_in / 1_000_000 if override_in is not None else (known[0] if known else None)
    rate_out = override_out / 1_000_000 if override_out is not None else (known[1] if known else None)
    if rate_in is None or rate_out is None:
        return None
    return rate_in, rate_out


def spend(args):
    opts, pos = options(args)
    if len(pos) != 1:
        usage("spend needs one jsonl path")
    from_line = int_opt(opts, "from-line", 1)
    to_line = int_opt(opts, "to-line", None)
    override_in = float_opt(opts, "price-in")
    override_out = float_opt(opts, "price-out")
    first = last = None
    runs = chat = refused = embeddings = 0
    tok_in = tok_out = tok_reason = 0
    by_model = {}
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
        record_tokens = [int(tokens.get(key) or 0) for key in TOKEN_KEYS]
        tok_in += record_tokens[0]
        tok_out += record_tokens[1]
        tok_reason += record_tokens[2]
        # Per model: each call's own tokens under the call's model; whatever
        # the record's tokens hold beyond its calls (an old file, which has no
        # per-call model, or a record with no calls) goes under no model.
        calls = record.get("chat_calls")
        calls = [call for call in calls if isinstance(call, dict)] if isinstance(calls, list) else []
        in_calls = [0, 0, 0]
        for call in calls:
            call_tokens = call.get("tokens") or {}
            amounts = [int(call_tokens.get(key) or 0) for key in TOKEN_KEYS]
            add_tokens(by_model, call.get("model") or None, amounts)
            in_calls = [a + b for a, b in zip(in_calls, amounts)]
        add_tokens(by_model, None, [max(0, whole - part) for whole, part in zip(record_tokens, in_calls)])
    rows = []
    for model, amounts in by_model.items():
        rate = rate_for(model, override_in, override_out)
        dollars = None if rate is None else amounts[0] * rate[0] + (amounts[1] + amounts[2]) * rate[1]
        rows.append((UNKNOWN_LABEL if model is None else model, amounts, dollars))
    rows.sort(key=lambda row: row[0])
    total = sum(dollars for _, _, dollars in rows if dollars is not None)
    span = f"{first}-{last}" if first is not None else "none"
    print(
        f"lines {span}: runs {runs}; chat sent {chat} refused {refused}; "
        f"embeddings sent {embeddings}; tokens in {tok_in} out {tok_out} "
        f"reasoning {tok_reason}; est ${total:.4f}"
    )
    for label, amounts, dollars in rows:
        price = f"est ${dollars:.4f}" if dollars is not None else "unpriced (no rate on file)"
        print(f"  {label}: tokens in {amounts[0]} out {amounts[1]} reasoning {amounts[2]}; {price}")
    if any(dollars is None for _, _, dollars in rows):
        print("  est $ on the first line leaves out the unpriced models")
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


class ShapeError(Exception):
    """An input file isn't the shape the gate reads: exit 2."""


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
        if "sublabel" in item and item["sublabel"] not in SUBLABELS:
            return f"items[{index}].sublabel is {item['sublabel']!r}, not one of {', '.join(SUBLABELS)}"
        if "sublabel" in item and item["verdict"] != "partly":
            return f"items[{index}] has a sub-label but is {item['verdict']}, not partly"
        if item["id"] in ids:
            return f"id {item['id']} appears twice"
        ids.add(item["id"])
    return None


def load_json(path):
    try:
        with open(path, encoding="utf-8") as handle:
            return json.load(handle)
    except (OSError, ValueError) as error:
        raise ShapeError(f"{path}: {error}") from error


def load_labels(path, sublabels_path):
    """(items, legacy): the labels items, each with its sub-label (or None)
    under 'sublabel', and whether the file is scored under the legacy rule (no
    sub-label on any item and no sidecar)."""
    data = load_json(path)
    problem = shape_error(data)
    if problem:
        raise ShapeError(problem)
    legacy = not sublabels_path and not any("sublabel" in item for item in data["items"])
    items = [dict(item) for item in data["items"]]
    by_id = {item["id"]: item for item in items}
    if sublabels_path:
        side = load_json(sublabels_path)
        entries = side.get("items") if isinstance(side, dict) else None
        if not isinstance(entries, list):
            raise ShapeError(f"{sublabels_path}: not an object with an 'items' list")
        seen = set()
        for entry in entries:
            if not isinstance(entry, dict) or "id" not in entry or "sublabel" not in entry:
                raise ShapeError(f"{sublabels_path}: an entry without 'id' and 'sublabel'")
            item_id, value = entry["id"], entry["sublabel"]
            if item_id in seen:
                raise ShapeError(f"{sublabels_path}: id {item_id} appears twice")
            seen.add(item_id)
            if value not in SUBLABELS:
                raise ShapeError(f"{sublabels_path}: {item_id} sub-label {value!r}, not one of {', '.join(SUBLABELS)}")
            if item_id not in by_id:
                raise ShapeError(f"{sublabels_path}: id {item_id} is not in the labels file")
            item = by_id[item_id]
            if item["verdict"] != "partly":
                raise ShapeError(f"{sublabels_path}: {item_id} is {item['verdict']}, not partly")
            if item.get("sublabel") not in (None, value):
                raise ShapeError(
                    f"{sublabels_path}: {item_id} sub-label {value} differs from the labels file's {item['sublabel']}"
                )
            item["sublabel"] = value
    for item in items:
        item.setdefault("sublabel", None)
    return items, legacy


def judge_labels(items, legacy=False):
    """Counts and zero-tolerance hits for one run's labels. Under the legacy
    rule a partly item needs no sub-label."""
    counts = {verdict: 0 for verdict in VERDICTS}
    kinds = {"loose": 0, "misattribution": 0, "unsure": 0}
    hits = []
    for item in items:
        counts[item["verdict"]] += 1
        where = f"{item['id']} {item['case']}"
        if item["verdict"] == "not":
            hits.append(f"{where} not supported")
        elif item["verdict"] == "partly":
            kind = item["sublabel"] or "unsure"
            kinds[kind] += 1
            if kind == "misattribution":
                hits.append(f"{where} misattribution")
            elif kind == "unsure" and not legacy:
                hits.append(f"{where} partly without a sub-label (unsure, counted strictly)")
            if item["members_only"]:
                hits.append(f"{where} partly in a members-only answer")
    return counts, kinds, hits


def answer_hash(text):
    return hashlib.sha256((text or "").encode("utf-8")).hexdigest()


def door4_hits(numbered):
    """Door 4 hits by verify_cited_answers.php's crossChecks() rule, or None if not checkable."""
    nonsense = [record for _, record in numbered if record.get("case") == "NONSENSE"]
    if len(nonsense) != 1 or nonsense[0].get("error") is not None:
        return None
    reply = nonsense[0].get("answer")
    return [
        (number, record) for number, record in numbered
        if record.get("door") == "answer" and record.get("expect") == "no_answer"
        and record.get("error") is None and record.get("scope") is not None
        and record.get("answer") != reply
    ]


def load_door4(path):
    """Judged Door 4 verdicts by jsonl line number."""
    if not path:
        return {}
    data = load_json(path)
    entries = data.get("items") if isinstance(data, dict) else None
    if not isinstance(entries, list):
        raise ShapeError(f"{path}: not an object with an 'items' list")
    verdicts = {}
    for entry in entries:
        if not isinstance(entry, dict):
            raise ShapeError(f"{path}: an entry is not an object")
        for key in ("line", "case", "persona", "answer_sha256", "verdict"):
            if key not in entry:
                raise ShapeError(f"{path}: an entry has no '{key}'")
        if entry["verdict"] not in DOOR4_VERDICTS:
            raise ShapeError(
                f"{path}: line {entry['line']} verdict {entry['verdict']!r}, not one of {', '.join(DOOR4_VERDICTS)}"
            )
        if entry["line"] in verdicts:
            raise ShapeError(f"{path}: line {entry['line']} appears twice")
        verdicts[entry["line"]] = entry
    return verdicts


def judge_run(path, from_line, to_line, door4_path):
    """Leaks, invariants, CRITICAL lines, Door 4 and the cited count for one run's lines."""
    try:
        numbered = [(n, r) for n, r in records(path, from_line, to_line) if is_run(r)]
    except (OSError, ValueError) as error:
        raise ShapeError(f"{path}: {error}") from error
    verdicts = load_door4(door4_path)
    hits, notes, door4_rows = [], [], []
    cited_count = sum(1 for _, record in numbered if record.get("outcome") == "cited")
    for number, record in numbered:
        where = f"line {number} {record.get('case')} {record.get('persona')}"
        for leak in record.get("leaks") or []:
            hits.append(f"{where} leak {leak}")
        for violation in record.get("invariant_violations") or []:
            hits.append(f"{where} invariant {violation}")
        for line in record.get("critical") or []:
            if (record.get("case"), record.get("persona")) in WAIVED and str(line).startswith(WAIVED_PREFIX):
                notes.append(f"{where} waived: {line}")
            else:
                hits.append(f"{where} CRITICAL {line}")
    door4 = door4_hits(numbered)
    if door4 is None:
        hits.append("Door 4 not checkable: not exactly one NONSENSE record without an error in range")
        door4 = []
    hit_lines = {number for number, _ in door4}
    for line in verdicts:
        if line not in hit_lines:
            raise ShapeError(f"{door4_path}: line {line} is not a Door 4 hit in this range")
    for number, record in door4:
        where = f"line {number} {record.get('case')} {record.get('persona')}"
        entry = verdicts.get(number)
        if entry is None:
            hits.append(f"Door 4 {where}: reply differs from the nonsense reply, no verdict")
            door4_rows.append((number, record.get("case"), None))
            continue
        if (entry["case"], entry["persona"]) != (record.get("case"), record.get("persona")):
            raise ShapeError(
                f"{door4_path}: line {number} is {record.get('case')} {record.get('persona')}, "
                f"the verdict says {entry['case']} {entry['persona']}"
            )
        if entry["answer_sha256"] != answer_hash(record.get("answer")):
            raise ShapeError(f"{door4_path}: line {number} answer_sha256 does not match the record's answer")
        door4_rows.append((number, record.get("case"), entry["verdict"]))
        if entry["verdict"] == "hedged":
            notes.append(f"Door 4 {where}: judged hedged, passes")
        else:
            hits.append(f"Door 4 {where}: judged {entry['verdict']}")
    return {"cited": cited_count, "hits": hits, "notes": notes, "door4": door4_rows}


def evaluate(labels_path, sublabels_path=None, jsonl=None, from_line=1, to_line=None, door4_path=None):
    """One run's gate result. Raises ShapeError on a bad input."""
    items, legacy = load_labels(labels_path, sublabels_path)
    counts, kinds, hits = judge_labels(items, legacy)
    result = {
        "labelled": len(items), "counts": counts, "kinds": kinds, "hits": hits,
        "legacy": legacy, "partly_limit": LEGACY_PARTLY_MAX_PERCENT if legacy else PARTLY_MAX_PERCENT,
        "members_only_partly": sum(1 for i in items if i["verdict"] == "partly" and i["members_only"]),
        "cited": None, "notes": [], "door4": [], "floor_ok": True,
    }
    if jsonl:
        run = judge_run(jsonl, from_line, to_line, door4_path)
        result["hits"] = hits + run["hits"]
        result["cited"] = run["cited"]
        result["notes"] = run["notes"]
        result["door4"] = run["door4"]
        result["floor_ok"] = run["cited"] >= FLOOR_RUN
    elif door4_path:
        raise ShapeError("--door4 needs --jsonl, --from-line and --to-line")
    result["share_ok"] = counts["partly"] * 100 <= result["partly_limit"] * result["labelled"]
    result["passed"] = not result["hits"] and result["share_ok"] and result["floor_ok"]
    return result


def describe(result):
    """The reasons a run fails, in print order."""
    reasons = list(result["hits"])
    if not result["share_ok"]:
        share = result["counts"]["partly"] / result["labelled"]
        reasons.append(f"partly {share:.1%} is over {result['partly_limit']}%")
    if not result["floor_ok"]:
        reasons.append(f"cited {result['cited']} is under {FLOOR_RUN}")
    return reasons


def verdict_text(result):
    """`PASS`, `FAIL (<why>)`, and under the legacy rule `PASS (legacy rule)` or `FAIL (legacy rule: <why>)`."""
    if result["passed"]:
        return "PASS (legacy rule)" if result["legacy"] else "PASS"
    why = "; ".join(describe(result))
    return f"FAIL (legacy rule: {why})" if result["legacy"] else f"FAIL ({why})"


def door4_text(result):
    return ", ".join(f"line {n} {c} {v or 'no verdict'}" for n, c, v in result["door4"]) or "none"


def print_run(result):
    counts, kinds = result["counts"], result["kinds"]
    share = counts["partly"] / result["labelled"]
    print(
        f"labelled {result['labelled']}: supported {counts['supported']}, partly {counts['partly']} "
        f"(loose {kinds['loose']}, misattribution {kinds['misattribution']}, unsure {kinds['unsure']}), "
        f"not {counts['not']}; partly share {share:.1%}"
    )
    if result["cited"] is not None:
        print(f"cited {result['cited']}; Door 4 hits: {door4_text(result)}")
    for note in result["notes"]:
        print(f"  {note}")
    for hit in result["hits"]:
        print(f"  {hit}")


def gate(args):
    opts, pos = options(args)
    if len(pos) != 1:
        usage("gate needs one labels file")
    try:
        result = evaluate(
            pos[0], opts.get("sublabels"), opts.get("jsonl"),
            int_opt(opts, "from-line", 1), int_opt(opts, "to-line", None), opts.get("door4"),
        )
    except ShapeError as error:
        print(f"Shape rejected: {error}")
        return 2
    print_run(result)
    print(f"Gate: {verdict_text(result)}")
    return 0 if result["passed"] else 1


def pooled(args):
    _, pos = options(args)
    if len(pos) != 1:
        usage("pooled needs one manifest file")
    base = os.path.dirname(os.path.abspath(pos[0]))

    def at(path):
        return path if path is None or os.path.isabs(path) else os.path.join(base, path)

    try:
        manifest = load_json(pos[0])
        models = manifest.get("models") if isinstance(manifest, dict) else None
        if not isinstance(models, list) or not models:
            raise ShapeError("manifest has no 'models' list")
        results = []
        for model in models:
            runs = [
                evaluate(
                    at(run["labels"]), at(run.get("sublabels")), at(run["jsonl"]),
                    int(run["from_line"]), int(run["to_line"]), at(run.get("door4")),
                )
                for run in model["runs"]
            ]
            results.append((model["name"], runs))
    except (ShapeError, KeyError, TypeError, ValueError) as error:
        print(f"Shape rejected: {error}")
        return 2
    all_ok = True
    for name, runs in results:
        for n, result in enumerate(runs, 1):
            verdict = verdict_text(result)
            counts, kinds = result["counts"], result["kinds"]
            print(
                f"{name} R{n}: cited {result['cited']}; labelled {result['labelled']}; "
                f"supported {counts['supported']} loose {kinds['loose']} "
                f"misattribution {kinds['misattribution']} unsure {kinds['unsure']} not {counts['not']}; "
                f"members-only partly {result['members_only_partly']}; Door 4 {door4_text(result)}; {verdict}"
            )
        labelled = sum(r["labelled"] for r in runs)
        partly = sum(r["counts"]["partly"] for r in runs)
        cited_total = sum(r["cited"] or 0 for r in runs)
        zero = sum(len(r["hits"]) for r in runs)
        legacy_runs = sum(1 for r in runs if r["legacy"])
        limit = min(r["partly_limit"] for r in runs)
        deciding = zero == 0 and partly * 100 <= limit * labelled and cited_total >= FLOOR_POOLED
        stricter = all(r["passed"] for r in runs)
        all_ok = all_ok and deciding
        print(
            f"{name}: deciding {'PASS' if deciding else 'FAIL'}, stricter {'PASS' if stricter else 'FAIL'}; "
            f"zero-tolerance hits {zero}; cited {cited_total} pooled; "
            f"partly {partly} of {labelled} ({partly / labelled:.1%})"
            + (f"; legacy rule in {legacy_runs} of {len(runs)} runs (pooled limit {limit}%)" if legacy_runs else "")
        )
    return 0 if all_ok else 1


def main(argv):
    commands = {"spend": spend, "cited": cited, "gate": gate, "pooled": pooled}
    if len(argv) < 2 or argv[1] not in commands:
        usage("first argument must be spend, cited, gate or pooled")
    return commands[argv[1]](argv[2:])


if __name__ == "__main__":
    sys.exit(main(sys.argv))
