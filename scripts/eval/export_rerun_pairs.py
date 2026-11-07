#!/usr/bin/env python3
"""Exports every cited (sentence, excerpt) pair from a live-run rerun (22-27).

Orchestrator ruling 2026-11-07: the judge is advisory (12/23), so the
sign-off gate is measured against two-model consensus labels on every
cited pair of the rerun. This writes those pairs in the v2 label-set
format (22-29-LABEL-SET-v2.json), one pair per sentence and citation.

The excerpt is exactly what the model saw for that citation: the registry
excerpt for the cited key in the same jsonl line. Sentences are split the
way JudgeMath::sentences() splits them, so pair ids line up with what the
judge sees. Overview answers (HTML, no registry in the jsonl) take their
excerpts from the [R#] blocks of the logged prompt, so log_input must have
been on for the run.

Only lines from --from-line on are read (the rerun's own lines), and per
case, persona and variant only the latest cited, non-INCOMPLETE line is
kept: the same choice the judge makes.

Usage:
  python3 -I scripts/eval/export_rerun_pairs.py <live-run.jsonl> <out.json> --from-line=N [--to-line=M] [--per-sentence]

--to-line bounds the range (inclusive), so a later run's records don't
replace the ones being exported.

--per-sentence writes one item per cited sentence (ids S01...), with its
cited excerpts under "citations", for labelling against their union.

Exits 1 if a citation has no registry excerpt in its own line.
"""

import json
import re
import sys

# Answers given to members from members-only content (22-27-CITATION-DIAGNOSIS).
# RQ-20's member run (P-MEM) is the overview's positive control: it cites
# the members-only ZQ7 note, so it is a members-only answer too.
MEMBERS_ONLY_CASES = {"RQ-07", "RQ-11", "RQ-14", "RQ-15"}
MEMBERS_ONLY_RUNS = {("RQ-20", "P-MEM")}


def plain(text):
    text = text.replace("**", "").replace("__", "")
    return re.sub(r"\s+", " ", text).strip()


def sentences(answer):
    """Port of JudgeMath::sentences()."""
    body = re.split(r"^\s*\*{0,2}Sources\*{0,2}\s*$", answer, flags=re.M)[0]
    out = []
    for line in re.split(r"(?:\r\n|\n|\r)+", body):
        line = re.sub(r"^\s*(?:[-*+]|\d+\.)\s+", "", line).strip()
        if not line:
            continue
        parts = re.split(r"(?<=[.!?])((?:\s*\[\d+\])*)\s+(?=\S)", line)
        pieces = []
        for i in range(0, len(parts), 2):
            pieces.append(parts[i] + (parts[i + 1] if i + 1 < len(parts) and parts[i + 1] else ""))
        for piece in pieces:
            labels = []
            for n in re.findall(r"\[(\d+)\]", piece):
                if int(n) not in labels:
                    labels.append(int(n))
            text = plain(re.sub(r"\s*\[\d+\]", "", piece))
            if text:
                out.append({"text": text, "labels": labels})
    return out


def _norm(text):
    text = re.sub(r"<[^>]+>", " ", text)
    text = re.sub(r"\[\s*R?\d+(?:\s*,\s*R?\d+)*\s*\]", " ", text)
    return re.sub(r"[^a-z0-9]+", " ", text.lower()).strip()


def overview_excerpts(record):
    """Citation number -> (citation, excerpt) for an overview record.

    The overview keeps no registry in the jsonl. Its excerpts are the
    [R#] blocks of the logged prompt (llm_input), and the answer's [n] are
    renumbered by first use, so [n] can't be read off the prompt order.
    - When every cited title is unique among the blocks, the title maps it.
    - When two blocks share a title, the rewriter adds " (k)" by CITATION
      order, not prompt order. Rerun 6 line 199 cited R2 first, and an
      earlier version of this function, which assumed prompt order,
      swapped [1] and [2]. The exact map then comes from the support
      check's logged prompt, which lists every cited sentence with its
      original [R#] labels: each answer sentence is matched to its line
      there, and its [n] pair up with that line's [R#] in order.
    - Anything that can't be mapped exactly is an error, never a guess.
    """
    inputs = [x for x in record.get("llm_input") or [] if isinstance(x, str)]
    prompts = [x for x in inputs if "Search query:" in x]
    prompt = prompts[-1] if prompts else ""
    blocks = re.findall(r"\n\[R(\d+)\] ([^\n]*)\n<<<SOURCE_TEXT\n(.*?)\nSOURCE_TEXT>>>", prompt, re.S)
    by_label = {int(n): (title, text) for n, title, text in blocks}
    titles = [t for _, t, _ in blocks]
    out, errors = {}, []
    base = lambda title: re.sub(r" \(\d+\)$", "", title)
    if all(titles.count(base(c["title"])) == 1 for c in record["citations"]):
        for c in record["citations"]:
            text = next(t for _, title, t in blocks if title == base(c["title"]))
            out[int(c["n"])] = (c, text)
        return out, errors
    # Shared titles: map [n] -> R# through the support check's prompt.
    checks = [x for x in inputs if "Sentences:\n<<<SOURCE_TEXT" in x]
    lines = {}
    for chk in checks:
        for body, labels in re.findall(r"^\d+\. (.*?)((?:\s*\[R\d+\])+)\s*$", chk.split("Cited passages:")[0], re.M):
            lines[_norm(body)] = [int(x) for x in re.findall(r"\[R(\d+)\]", labels)]
    answer = re.sub(r"</p>\s*", "\n", record.get("answer", ""))
    n_to_r = {}
    # Labels sit before the full stop in the answer ("... us [1].") and
    # after it in the check prompt ("... us. [R2]"); _norm drops both.
    plain = re.sub(r"<[^>]+>", "", answer)
    for sentence in re.findall(r"[^.!?\n]*?(?:\s*\[\d+\])+\s*[.!?]|[^.!?\n]*[.!?](?:\s*\[\d+\])+", plain):
        ns = [int(x) for x in re.findall(r"\[(\d+)\]", sentence)]
        rs = lines.get(_norm(sentence))
        if rs is None or len(rs) != len(ns):
            continue
        for n, r in zip(ns, rs):
            if n_to_r.setdefault(n, r) != r:
                errors.append(f"[{n}] maps to both R{n_to_r[n]} and R{r}")
    for c in record["citations"]:
        n = int(c["n"])
        if n not in n_to_r or n_to_r[n] not in by_label:
            errors.append(f"[{n}] ({c['title']}) can't be mapped to a prompt block exactly (shared title, no matching check line)")
            continue
        out[n] = (c, by_label[n_to_r[n]][1])
    return out, errors


def plain_html(text):
    """Overview answers are HTML paragraphs: one paragraph per line."""
    text = re.sub(r"</p>\s*", "\n", text)
    return re.sub(r"<[^>]+>", "", text)


def by_sentence(pairs):
    """One item per cited sentence, its cited excerpts listed separately.

    Orchestrator ruling 2026-11-07: a multi-citation sentence is labelled
    once, against the union of its cited excerpts; each cited source must
    support at least one part of it (no decorative citations).
    """
    items, index = [], {}
    for p in pairs:
        key = (p["record_line"], p["sentence"])
        if key not in index:
            index[key] = len(items)
            items.append({
                "id": f"S{len(items) + 1:02d}",
                "case": p["case"],
                "persona": p["persona"],
                "variant": p["variant"],
                "door": p["door"],
                "question": p["question"],
                "sentence": p["sentence"],
                "record_line": p["record_line"],
                "members_only_answer": p["members_only_answer"],
                "citations": [],
            })
        items[index[key]]["citations"].append({
            "label": p["citation_label"],
            "title": p["citation_title"],
            "url": p["citation_url"],
            "source_key": p["source_key"],
            "excerpt": p["source_excerpt"],
            "excerpt_chars_model_saw": p["excerpt_chars_model_saw"],
            "excerpt_source": p["excerpt_source"],
        })
    return items


def main(jsonl_path, out_path, from_line, per_sentence=False, to_line=None):
    latest = {}
    for i, raw in enumerate(open(jsonl_path, encoding="utf-8"), 1):
        if i < from_line or (to_line is not None and i > to_line) or not raw.strip():
            continue
        r = json.loads(raw)
        if r.get("outcome") != "cited" or r.get("status") == "INCOMPLETE":
            continue
        latest[(r["case"], r["persona"], r.get("variant", ""))] = (i, r)
    pairs = []
    errors = []
    for (case, persona, variant), (line, r) in latest.items():
        by_n = {}
        if r["door"] == "overview":
            by_n, errs = overview_excerpts(r)
            errors.extend(f"{case} {persona} line {line}: {e}" for e in errs)
        for c in r["citations"] if r["door"] != "overview" else []:
            ex = [g for g in r["registry"] if g["key"] == c["key"]]
            if len(ex) != 1:
                errors.append(f"{case} {persona} line {line}: {len(ex)} registry entries for {c['key']}")
                continue
            by_n[int(c["n"])] = (c, ex[0]["excerpt"])
        answer = plain_html(r["answer"]) if r["door"] == "overview" else r["answer"]
        for s in sentences(answer):
            for n in s["labels"]:
                if n not in by_n:
                    errors.append(f"{case} {persona} line {line}: [{n}] cited but not in citations")
                    continue
                c, excerpt = by_n[n]
                pairs.append({
                    "id": f"R{len(pairs) + 1:02d}",
                    "case": case,
                    "persona": persona,
                    "variant": variant,
                    "question": r["question"],
                    "sentence": s["text"],
                    "citation_label": f"[{n}]",
                    "citation_title": c["title"],
                    "citation_url": c["url"],
                    "source_excerpt": excerpt,
                    "excerpt_is_whole": True,
                    "excerpt_chars_model_saw": len(excerpt),
                    "door": r["door"],
                    "excerpt_source": "own record: prompt block for the cited source" if r["door"] == "overview" else "own record: registry excerpt for the cited key",
                    "record_line": line,
                    "source_key": c["key"],
                    "members_only_answer": case in MEMBERS_ONLY_CASES or (case, persona) in MEMBERS_ONLY_RUNS,
                })
    if errors:
        print("\n".join(errors), file=sys.stderr)
        return 1
    if per_sentence:
        pairs = by_sentence(pairs)
    with open(out_path, "w", encoding="utf-8") as fh:
        json.dump(pairs, fh, ensure_ascii=False, indent=1)
        fh.write("\n")
    answers = len(latest)
    mo = sum(1 for p in pairs if p["members_only_answer"])
    unit = "sentences" if per_sentence else "pairs"
    print(f"{len(pairs)} {unit} from {answers} cited answers ({mo} {unit} in members-only answers) -> {out_path}")
    return 0


if __name__ == "__main__":
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    start = [int(a.split("=", 1)[1]) for a in sys.argv[1:] if a.startswith("--from-line=")]
    if len(args) != 2 or not start:
        print(__doc__, file=sys.stderr)
        sys.exit(2)
    end = [int(a.split("=", 1)[1]) for a in sys.argv[1:] if a.startswith("--to-line=")]
    sys.exit(main(args[0], args[1], start[0], "--per-sentence" in sys.argv[1:], end[0] if end else None))
