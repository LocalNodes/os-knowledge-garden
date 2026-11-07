#!/usr/bin/env node
// Appends a run's rows to the Phase 21 e2e evidence index (21-42).
//
// Usage: node tests/e2e/evidence-index.mjs <report.json> <network> <runId> <mode>
//
// Reads the Playwright JSON report (tests/e2e/.state/report-<network>.json) and appends one
// "## Run <runId> · <network> · <mode>" block (once per run and network) and one row per
// test to .planning/phases/21-real-safe-reads-balances/e2e-evidence/INDEX.md:
//   | network | flow | spec › test | trace .zip | video .webm | result |
// Trace and video paths are written relative to the phase folder (e2e-evidence/runs/…).
// The big files stay under the gitignored e2e-evidence/runs/; only INDEX.md is committed.
// Plain Node, no dependencies.
import { existsSync, mkdirSync, readFileSync, writeFileSync, appendFileSync } from 'node:fs';
import { dirname, join, relative, isAbsolute, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = join(HERE, '..', '..');
const PHASE = join(ROOT, '.planning', 'phases', '21-real-safe-reads-balances');
const INDEX = join(PHASE, 'e2e-evidence', 'INDEX.md');

const HEADER = `# Phase 21 e2e evidence index

Each block is one run of the wallet e2e suite (tests/e2e) on one local chain, written by
\`tests/e2e/evidence-index.mjs\` from the Playwright JSON report.

- One row per test: network, flow, spec › test, its trace (.zip), its video (.webm) and the
  result.
- Paths are relative to this phase folder. The traces and videos themselves are big and
  stay local, under the gitignored \`e2e-evidence/runs/\`. Open a trace with
  \`npx --no-install playwright show-trace <path>\`.
- "skipped (fork mode only)" marks a test that needs a fork of the real chain (the real
  pre-existing Safe); the local-mode run has nothing to link there.
- Page and chain values for each step are in \`tests/e2e/.state/results-<network>.json\`
  (local, not committed).
`;

const FLOWS = ['link (a)', 'link (b)', 'set up', 'balances', 'fund'];

function die(msg) {
  process.stderr.write(`evidence-index: ${msg}\n`);
  process.exit(1);
}

const [reportPath, network, runId, mode] = process.argv.slice(2);
if (!reportPath || !network || !runId || !mode) {
  process.stderr.write('usage: node tests/e2e/evidence-index.mjs <report.json> <network> <runId> <mode>\n');
  process.exit(2);
}
if (!/^[a-z0-9_]+$/.test(network) || !/^[A-Za-z0-9_.-]+$/.test(runId) || !/^[a-z]+$/.test(mode)) {
  die('network, runId or mode has unexpected characters');
}

let report;
try {
  report = JSON.parse(readFileSync(isAbsolute(reportPath) ? reportPath : resolve(process.cwd(), reportPath), 'utf8'));
} catch (err) {
  die(`cannot read the report ${reportPath}: ${err.message}`);
}

/** Every {spec, test} pair in the report, through nested describe suites. */
function* walk(suite, titles) {
  const here = suite.title && !/\.ts$/.test(suite.title) ? [...titles, suite.title] : titles;
  for (const spec of suite.specs ?? []) {
    for (const t of spec.tests ?? []) yield { spec, test: t, titles: here, file: spec.file ?? suite.file };
  }
  for (const child of suite.suites ?? []) yield* walk(child, here);
}

const cell = (s) => String(s).replace(/\|/g, '\\|').replace(/\s+/g, ' ').trim();

function attachmentPath(results, name) {
  for (const r of results) {
    for (const a of r.attachments ?? []) {
      if (a.name === name && a.path && existsSync(a.path)) return relative(PHASE, a.path);
    }
  }
  return '';
}

function resultOf(t) {
  const last = (t.results ?? [])[t.results.length - 1] ?? {};
  const status = last.status ?? t.status ?? 'unknown';
  if (status === 'passed') return 'pass';
  if (status === 'skipped' || t.status === 'skipped') {
    const reason = [...(t.annotations ?? []), ...(last.annotations ?? [])].find((a) => a.type === 'skip')?.description ?? '';
    return /fork/i.test(reason) ? 'skipped (fork mode only)' : `skipped${reason ? ` (${reason})` : ''}`;
  }
  return status === 'failed' || status === 'timedOut' ? `fail (${status})` : status;
}

const rows = [];
for (const top of report.suites ?? []) {
  for (const { spec, test: t, file } of walk(top, [])) {
    const title = spec.title;
    const flow = FLOWS.find((f) => title.startsWith(f) || title.includes(f)) ?? title;
    const trace = attachmentPath(t.results ?? [], 'trace');
    const video = attachmentPath(t.results ?? [], 'video');
    rows.push(`| ${network} | ${cell(flow)} | ${cell(`${file} › ${title}`)} | ${trace ? cell(trace) : 'none'} | ${video ? cell(video) : 'none'} | ${cell(resultOf(t))} |`);
  }
}
if (rows.length === 0) die(`no tests in ${reportPath}`);

const heading = `## Run ${runId} · ${network} · ${mode}`;
let text = existsSync(INDEX) ? readFileSync(INDEX, 'utf8') : '';
if (text === '') {
  mkdirSync(dirname(INDEX), { recursive: true });
  writeFileSync(INDEX, HEADER);
  text = HEADER;
}
let block = '';
if (!text.split('\n').includes(heading)) {
  block += `\n${heading}\n\n| network | flow | spec › test | trace .zip | video .webm | result |\n|---|---|---|---|---|---|\n`;
}
appendFileSync(INDEX, `${block}${rows.join('\n')}\n`);
process.stdout.write(`evidence-index: ${rows.length} rows for ${network} (${runId}) in ${relative(ROOT, INDEX)}\n`);
