#!/usr/bin/env python3
"""Tests for `rerun_stats.py gate`, `pooled` (23-16, Tom ruling 2026-11-08) and `spend`.

Python standard library only. Run with:
  python3 -I scripts/eval/tests/test_rerun_stats.py

Every test builds its own fixture files in a temporary directory and runs the
tool as a subprocess, so the exit code is what is checked, exactly as the gate
is used.
"""

import hashlib
import json
import os
import subprocess
import sys
import tempfile
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
TOOL = os.path.join(os.path.dirname(HERE), "rerun_stats.py")

NONSENSE = "I couldn't find anything about that in this group's pages."


def labels(supported=0, loose=0, misattribution=0, unsure=0, not_=0, members_only_loose=0):
    """A rerun-6 labels object. Returns (data, sublabels) where sublabels maps id to sub-label.

    `loose` and `misattribution` items are partly items with a sub-label;
    `unsure` items are partly items with none.
    """
    items, sub = [], {}
    n = 0

    def add(verdict, members_only=False, sublabel=None):
        nonlocal n
        n += 1
        item_id = f"S{n:03d}"
        items.append({
            "id": item_id, "case": "RQ-01", "door": "answer",
            "members_only": members_only, "verdict": verdict,
        })
        if sublabel:
            sub[item_id] = sublabel

    for _ in range(supported):
        add("supported")
    for _ in range(loose):
        add("partly", sublabel="loose")
    for _ in range(misattribution):
        add("partly", sublabel="misattribution")
    for _ in range(unsure):
        add("partly")
    for _ in range(not_):
        add("not")
    for _ in range(members_only_loose):
        add("partly", members_only=True, sublabel="loose")
    return {"note": "fixture", "counts": {}, "items": items}, sub


def sublabel_file(sub):
    return {
        "note": "fixture",
        "counts": {},
        "items": [{"id": i, "sublabel": s, "claude": s, "second": s} for i, s in sub.items()],
    }


def run_records(cited=10, door4_answer=None, leak=False, critical=None, waived=True, nonsense=True):
    """One 25-line run, shaped like verify_cited_answers.php's jsonl records."""
    recs = []

    def rec(case, persona, outcome, expect, crit=None, answer="", scope="G-P2P", door="answer"):
        recs.append({
            "case": case, "persona": persona, "status": "CRITICAL" if crit else "PASS",
            "outcome": outcome, "expect": expect, "door": door, "scope": scope,
            "error": None, "answer": answer, "critical": crit or [], "failures": [],
            "leaks": [], "invariant_violations": [], "variant": "",
        })

    for i in range(cited):
        rec(f"RQ-C{i:02d}", "P-ANON", "cited", "cited", answer=f"Cited answer {i} [1].")
    rec("RQ-05", "P-ANON", "cited" if door4_answer else "no_answer", "no_answer",
        answer=door4_answer if door4_answer else NONSENSE)
    if waived:
        rec("RQ-11", "P-MEM", "no_answer", "cited",
            crit=["E1 positive control: expected a cited answer, got no_answer"], answer=NONSENSE)
        rec("RQ-20", "P-MEM", "no_overview", "cited",
            crit=["E1 positive control: expected a cited answer, got no_overview"], door="overview", answer="")
    if critical:
        rec("RQ-07", "P-MEM", "cited", "cited", crit=[critical], answer="x [1].")
    while len(recs) < 24:
        rec(f"RQ-N{len(recs):02d}", "P-NON", "no_answer", "no_answer", answer=NONSENSE, scope=None)
    if nonsense:
        rec("NONSENSE", "P-NON", "no_answer", "no_answer", answer=NONSENSE, scope="G")
    if leak:
        recs[0]["leaks"] = ["ZQ7-BODY"]
    return recs


def sha(text):
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


class Base(unittest.TestCase):
    """Fixture helpers; holds no tests."""

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.dir = self.tmp.name

    def tearDown(self):
        self.tmp.cleanup()

    def write(self, name, data):
        path = os.path.join(self.dir, name)
        with open(path, "w", encoding="utf-8") as handle:
            if name.endswith(".jsonl"):
                for record in data:
                    handle.write(json.dumps(record) + "\n")
            else:
                json.dump(data, handle)
        return path

    def run_tool(self, *args):
        proc = subprocess.run(
            [sys.executable, "-I", TOOL, *args],
            capture_output=True, text=True, check=False,
        )
        return proc.returncode, proc.stdout + proc.stderr

    def gate(self, data, sub=None, records=None, door4=None, sub_in_items=False):
        if sub and sub_in_items:
            for item in data["items"]:
                if item["id"] in sub:
                    item["sublabel"] = sub[item["id"]]
        args = ["gate", self.write("labels.json", data)]
        if sub and not sub_in_items:
            args.append("--sublabels=" + self.write("sub.json", sublabel_file(sub)))
        if records is not None:
            args += ["--jsonl=" + self.write("run.jsonl", records), "--from-line=1", f"--to-line={len(records)}"]
        if door4 is not None:
            args.append("--door4=" + self.write("door4.json", door4))
        return self.run_tool(*args)



class Gate(Base):

    # Labels: the zero-tolerance items.

    def test_one_misattribution_fails(self):
        code, out = self.gate(*labels(supported=19, misattribution=1))
        self.assertEqual(code, 1, out)
        self.assertIn("misattribution", out)

    def test_one_not_supported_fails(self):
        code, out = self.gate(*labels(supported=19, not_=1))
        self.assertEqual(code, 1, out)
        self.assertIn("not supported", out)

    def test_partly_without_sub_label_is_unsure_and_fails(self):
        # The file carries a sub-label elsewhere, so it is scored under the new
        # rule (a file with none at all gets the legacy rule, below).
        code, out = self.gate(*labels(supported=18, loose=1, unsure=1))
        self.assertEqual(code, 1, out)
        self.assertIn("without a sub-label", out)

    # The legacy rule (orchestrator note, 2026-11-08): a label file with no
    # sub-label on any item, and no --sublabels sidecar, is scored under the
    # rule of its own date: no `not`, partly at most 10%, no members-only
    # partly, and no sub-label requirement. The result line says so.

    def test_file_without_any_sub_label_is_scored_under_the_legacy_rule(self):
        code, out = self.gate(*labels(supported=18, unsure=2))
        self.assertEqual(code, 0, out)
        self.assertIn("Gate: PASS (legacy rule)", out)
        self.assertNotIn("without a sub-label", out)
        code, out = self.gate(*labels(supported=17, unsure=3))
        self.assertEqual(code, 1, out)
        self.assertIn("Gate: FAIL (legacy rule:", out)
        self.assertIn("over 10%", out)

    def test_file_with_sub_labels_or_a_sidecar_gets_the_new_rule(self):
        # 15% loose: over the legacy 10%, inside the new 20%.
        data, sub = labels(supported=17, loose=3)
        for in_items in (False, True):
            code, out = self.gate(json.loads(json.dumps(data)), dict(sub), sub_in_items=in_items)
            self.assertEqual(code, 0, out)
            self.assertIn("Gate: PASS", out)
            self.assertNotIn("legacy rule", out)

    def test_old_label_file_without_sub_labels_is_still_read(self):
        code, out = self.gate(*labels(supported=20))
        self.assertEqual(code, 0, out)
        self.assertIn("Gate: PASS", out)

    def test_members_only_loose_partly_fails(self):
        code, out = self.gate(*labels(supported=19, members_only_loose=1))
        self.assertEqual(code, 1, out)
        self.assertIn("members-only", out)

    # Labels: the 20% loose allowance.

    def test_19_percent_loose_passes(self):
        code, out = self.gate(*labels(supported=81, loose=19))
        self.assertEqual(code, 0, out)
        self.assertIn("Gate: PASS", out)

    def test_20_percent_loose_passes(self):
        code, out = self.gate(*labels(supported=80, loose=20))
        self.assertEqual(code, 0, out)

    def test_21_percent_loose_fails(self):
        code, out = self.gate(*labels(supported=79, loose=21))
        self.assertEqual(code, 1, out)
        self.assertIn("over 20%", out)

    def test_sub_label_inside_the_label_item_is_read(self):
        data, sub = labels(supported=19, misattribution=1)
        code, out = self.gate(data, sub, sub_in_items=True)
        self.assertEqual(code, 1, out)
        self.assertIn("misattribution", out)

    def test_sub_label_for_a_supported_item_is_rejected(self):
        data, sub = labels(supported=20)
        sub["S001"] = "loose"
        code, out = self.gate(data, sub)
        self.assertEqual(code, 2, out)

    def test_unknown_sub_label_is_rejected(self):
        data, sub = labels(supported=19, loose=1)
        sub["S020"] = "sloppy"
        code, out = self.gate(data, sub)
        self.assertEqual(code, 2, out)

    # Run checks from the jsonl.

    def test_clean_run_passes(self):
        code, out = self.gate(*labels(supported=20), records=run_records())
        self.assertEqual(code, 0, out)

    def test_leak_fails(self):
        code, out = self.gate(*labels(supported=20), records=run_records(leak=True))
        self.assertEqual(code, 1, out)
        self.assertIn("leak", out)

    def test_critical_outside_the_waived_pair_fails(self):
        code, out = self.gate(*labels(supported=20), records=run_records(critical="E3: wrong group"))
        self.assertEqual(code, 1, out)
        self.assertIn("E3", out)

    def test_floor_of_ten_cited_answers(self):
        code, out = self.gate(*labels(supported=20), records=run_records(cited=9))
        self.assertEqual(code, 1, out)
        self.assertIn("cited", out)

    def test_run_without_a_nonsense_reply_fails(self):
        code, out = self.gate(*labels(supported=20), records=run_records(nonsense=False))
        self.assertEqual(code, 1, out)

    # Door 4.

    HEDGED = "The pages don't cover seed saving; the closest thing is the Skill-Share Circle [1]."

    def door4(self, verdict, answer=None, line=11):
        answer = self.HEDGED if answer is None else answer
        return {"note": "fixture", "items": [{
            "line": line, "case": "RQ-05", "persona": "P-ANON",
            "answer_sha256": sha(answer), "verdict": verdict,
        }]}

    def test_door4_critical_without_a_verdict_fails(self):
        code, out = self.gate(*labels(supported=20), records=run_records(door4_answer=self.HEDGED))
        self.assertEqual(code, 1, out)
        self.assertIn("Door 4", out)

    def test_door4_critical_judged_hedged_passes(self):
        code, out = self.gate(*labels(supported=20), records=run_records(door4_answer=self.HEDGED),
                              door4=self.door4("hedged"))
        self.assertEqual(code, 0, out)
        self.assertIn("Gate: PASS", out)

    def test_door4_critical_judged_not_hedged_fails(self):
        code, out = self.gate(*labels(supported=20), records=run_records(door4_answer=self.HEDGED),
                              door4=self.door4("presented_as_answer"))
        self.assertEqual(code, 1, out)
        self.assertIn("Door 4", out)

    def test_door4_verdict_for_a_different_answer_is_rejected(self):
        code, out = self.gate(*labels(supported=20), records=run_records(door4_answer=self.HEDGED),
                              door4=self.door4("hedged", answer="another reply"))
        self.assertEqual(code, 2, out)

    def test_door4_verdict_for_a_line_that_is_no_hit_is_rejected(self):
        code, out = self.gate(*labels(supported=20), records=run_records(),
                              door4=self.door4("hedged"))
        self.assertEqual(code, 2, out)

    def test_door4_hedged_does_not_excuse_other_criticals(self):
        code, out = self.gate(*labels(supported=20),
                              records=run_records(door4_answer=self.HEDGED, critical="E2: forged"),
                              door4=self.door4("hedged"))
        self.assertEqual(code, 1, out)


class Pooled(Base):
    """`pooled <manifest.json>`: the deciding (pooled counts) and stricter (every run) readings."""

    def manifest(self, runs):
        entries = []
        for n, (data, sub, records) in enumerate(runs, 1):
            entry = {
                "labels": self.write(f"l{n}.json", data),
                "jsonl": self.write(f"r{n}.jsonl", records),
                "from_line": 1, "to_line": len(records),
            }
            if sub:
                entry["sublabels"] = self.write(f"s{n}.json", sublabel_file(sub))
            entries.append(entry)
        return self.write("manifest.json", {"models": [{"name": "m", "runs": entries}]})

    def test_pooled_share_passes_when_one_run_is_over(self):
        # 2 + 6 + 2 loose over 50 labelled = 20% pooled; run 2 alone is 6/20 = 30%.
        runs = [
            (*labels(supported=13, loose=2), run_records()),
            (*labels(supported=14, loose=6), run_records()),
            (*labels(supported=13, loose=2), run_records()),
        ]
        code, out = self.run_tool("pooled", self.manifest(runs))
        self.assertEqual(code, 0, out)
        self.assertIn("m: deciding PASS", out)
        self.assertIn("stricter FAIL", out)

    def test_zero_tolerance_in_one_run_fails_both_readings(self):
        runs = [
            (*labels(supported=20), run_records()),
            (*labels(supported=19, misattribution=1), run_records()),
            (*labels(supported=20), run_records()),
        ]
        code, out = self.run_tool("pooled", self.manifest(runs))
        self.assertEqual(code, 1, out)
        self.assertIn("m: deciding FAIL", out)
        self.assertIn("stricter FAIL", out)

    def test_pooled_floor_is_thirty(self):
        runs = [
            (*labels(supported=20), run_records(cited=11)),
            (*labels(supported=20), run_records(cited=9)),
            (*labels(supported=20), run_records(cited=10)),
        ]
        code, out = self.run_tool("pooled", self.manifest(runs))
        self.assertEqual(code, 0, out)
        self.assertIn("m: deciding PASS", out)
        self.assertIn("stricter FAIL", out)

    def test_pooled_floor_misses_at_twenty_nine(self):
        runs = [
            (*labels(supported=20), run_records(cited=10)),
            (*labels(supported=20), run_records(cited=9)),
            (*labels(supported=20), run_records(cited=10)),
        ]
        code, out = self.run_tool("pooled", self.manifest(runs))
        self.assertEqual(code, 1, out)
        self.assertIn("m: deciding FAIL", out)


class Spend(Base):
    """`spend <jsonl>`: tokens and est $ per model (chat_calls[].model)."""

    FLASH38 = "models/gemini-3.8-flash"
    FLASH37 = "models/gemini-3.7-flash"
    UNKNOWN = "unknown model (assumed models/gemini-3.8-flash)"

    def run_record(self, calls):
        """A run record with chat_calls from (model or None, in, out, reasoning); a
        None model leaves the key out, as in a jsonl from before it was recorded."""
        chat_calls, totals = [], {"input": 0, "output": 0, "reasoning": 0}
        for model, tok_in, tok_out, tok_reason in calls:
            tokens = {"input": tok_in, "output": tok_out, "reasoning": tok_reason}
            call = {"tags": [], "config": None, "tokens": tokens}
            if model is not None:
                call["model"] = model
            chat_calls.append(call)
            for key, value in tokens.items():
                totals[key] += value
        return {
            "case": "RQ-01", "persona": "P-ANON", "status": "PASS",
            "requests": {"chat": {"sent": len(calls)}, "embeddings": {"sent": 1}},
            "tokens": totals, "chat_calls": chat_calls,
        }

    def spend(self, records, *extra):
        return self.run_tool("spend", self.write("run.jsonl", records), *extra)

    def line_for(self, out, label):
        lines = [line for line in out.splitlines() if line.strip().startswith(label + ":")]
        self.assertEqual(len(lines), 1, out)
        return lines[0]

    def test_old_jsonl_without_a_model_gives_the_same_est_dollars(self):
        # 1M in is $0.75; 0.5M out plus 0.5M reasoning is $3.75: $4.50, as before.
        records = [self.run_record([(None, 1_000_000, 500_000, 500_000)])]
        code, out = self.spend(records)
        self.assertEqual(code, 0, out)
        self.assertIn(
            "lines 1-1: runs 1; chat sent 1 refused 0; embeddings sent 1; "
            "tokens in 1000000 out 500000 reasoning 500000; est $4.5000", out.splitlines()[0],
        )
        line = self.line_for(out, self.UNKNOWN)
        self.assertIn("tokens in 1000000 out 500000 reasoning 500000; est $4.5000", line)
        self.assertEqual(len(out.strip().splitlines()), 2, out)

    def test_old_jsonl_without_chat_calls_is_priced_from_the_record_tokens(self):
        record = self.run_record([])
        record["tokens"] = {"input": 1_000_000, "output": 500_000, "reasoning": 500_000}
        del record["chat_calls"]
        code, out = self.spend([record])
        self.assertEqual(code, 0, out)
        self.assertIn("est $4.5000", out.splitlines()[0])
        self.assertIn("est $4.5000", self.line_for(out, self.UNKNOWN))

    def test_mixed_jsonl_prints_a_line_per_model_and_leaves_3_7_unpriced(self):
        records = [
            self.run_record([(self.FLASH38, 1_000_000, 100_000, 100_000), (self.FLASH37, 2_000_000, 200_000, 0)]),
        ]
        code, out = self.spend(records)
        self.assertEqual(code, 0, out)
        first = out.splitlines()[0]
        self.assertIn("tokens in 3000000 out 300000 reasoning 100000", first)
        # Only the priced model is in the first line's total: 0.75 + 0.75.
        self.assertIn("est $1.5000", first)
        line38 = self.line_for(out, self.FLASH38)
        self.assertIn("tokens in 1000000 out 100000 reasoning 100000; est $1.5000", line38)
        line37 = self.line_for(out, self.FLASH37)
        self.assertIn("tokens in 2000000 out 200000 reasoning 0", line37)
        self.assertIn("unpriced (no rate on file)", line37)
        self.assertNotIn("est $", line37)
        self.assertNotIn(self.UNKNOWN, out)

    def test_mixed_jsonl_keeps_old_calls_under_the_unknown_model(self):
        records = [
            self.run_record([(None, 1_000_000, 0, 0)]),
            self.run_record([(self.FLASH37, 1_000_000, 0, 0)]),
        ]
        code, out = self.spend(records)
        self.assertEqual(code, 0, out)
        self.assertIn("est $0.7500", self.line_for(out, self.UNKNOWN))
        self.assertIn("unpriced", self.line_for(out, self.FLASH37))

    def test_price_flags_price_the_unpriced_model(self):
        records = [
            self.run_record([(self.FLASH38, 1_000_000, 100_000, 100_000), (self.FLASH37, 2_000_000, 200_000, 0)]),
        ]
        code, out = self.spend(records, "--price-in=0.5", "--price-out=2")
        self.assertEqual(code, 0, out)
        # The override applies to every model printed: 3.8 is 0.5 + 0.4, 3.7 is 1.0 + 0.4.
        self.assertIn("est $0.9000", self.line_for(out, self.FLASH38))
        line37 = self.line_for(out, self.FLASH37)
        self.assertIn("est $1.4000", line37)
        self.assertNotIn("unpriced", line37)
        self.assertIn("est $2.3000", out.splitlines()[0])

    def test_one_price_flag_alone_leaves_the_unpriced_model_unpriced(self):
        records = [self.run_record([(self.FLASH37, 1_000_000, 0, 0)])]
        code, out = self.spend(records, "--price-in=0.5")
        self.assertEqual(code, 0, out)
        self.assertIn("unpriced", self.line_for(out, self.FLASH37))

    def test_bad_price_flag_exits_2(self):
        code, out = self.spend([self.run_record([(self.FLASH38, 1, 1, 1)])], "--price-in=lots")
        self.assertEqual(code, 2, out)


if __name__ == "__main__":
    unittest.main(verbosity=1)
