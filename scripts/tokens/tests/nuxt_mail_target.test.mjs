/**
 * Guard tests for the Nuxt mail-colours target (22.1-14, DES-01, D-03, D-08, WINDOWS 44, inbox Q6 (a)).
 *
 * The target writes <sibling>/server/utils/mail-colors.generated.ts: a MAIL_COLORS const with the
 * nine keys server/utils/mail-copy.ts uses, each value an existing targets.email role. It writes
 * only where the Nuxt repo has adopted the file (mail-copy.ts imports it, or the file exists), so
 * no 22.1 run creates a file in Phase 23's checkout.
 *
 * Every test here uses a fake sibling in a temp dir (--sibling) or --out-dir. The real Nuxt repo
 * is only read, with `git show`, and never written to. Each guard is shown firing: tamper, missing,
 * not adopted, a value that is not a colour.
 *
 * The read-only match against the real mail-copy.ts reads the ref in LN_SWAP_NUXT_REF (default
 * origin/main) of the repo in LN_SWAP_NUXT_REPO (default ../localnodes-onboarding). It skips when
 * the ref or the file is missing (CI has no sibling) and inside the swap proof (LN_SWAP_INNER).
 *
 * Run: node --test scripts/tokens/tests/*.test.mjs
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { copyRepo } from './helpers/repo_copy.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, '../../..');
// The generator runs in write mode here: run it from a private copy of the repo so a test never rewrites this repo's
// generated files (WINDOWS 51). Reads of the repo's own files (flat.json, the token source) stay on the real one.
const build = join(copyRepo().repo, 'scripts/tokens/build_tokens.mjs');
const parity = resolve(repoRoot, 'scripts/check_token_parity.mjs');
const flat = JSON.parse(readFileSync(resolve(repoRoot, 'html/modules/custom/localnodes_platform/tokens/ln-tokens.flat.json'), 'utf8'));

const GENERATED_REL = 'server/utils/mail-colors.generated.ts';
const MAIL_COPY_REL = 'server/utils/mail-copy.ts';
/** mail-copy.ts's keys, in its order, and the targets.email role each one reads. */
const KEY_TO_ROLE = [
  ['canvas', 'body'],
  ['card', 'card'],
  ['border', 'divider'],
  ['heading', 'heading'],
  ['text', 'text'],
  ['button', 'button'],
  ['buttonText', 'button-text'],
  ['link', 'link'],
  ['footer', 'footer-text'],
];
const IMPORT_LINE = "import { MAIL_COLORS } from './mail-colors.generated'\n";

function run(script, args, cwd = tmpdir()) {
  const r = spawnSync(process.execPath, [script, ...args], { cwd, encoding: 'utf8' });
  return { code: r.status, out: `${r.stdout}${r.stderr}` };
}

/** A directory that looks like a Nuxt checkout (it has nuxt.config.ts). Not adopted. */
function fakeSibling() {
  const dir = mkdtempSync(join(tmpdir(), 'ln-nuxt-mail-'));
  writeFileSync(join(dir, 'nuxt.config.ts'), 'export default defineNuxtConfig({})\n');
  return dir;
}

function writeMailCopy(dir, text) {
  mkdirSync(join(dir, 'server/utils'), { recursive: true });
  writeFileSync(join(dir, MAIL_COPY_REL), text);
}

/** A sibling whose mail-copy.ts imports the generated file, with no generated file yet. */
function wiredSibling() {
  const dir = fakeSibling();
  writeMailCopy(dir, `${IMPORT_LINE}export { MAIL_COLORS }\n`);
  return dir;
}

/** A wired sibling that already holds the generated file. */
function generatedSibling() {
  const dir = wiredSibling();
  const r = run(build, ['--sibling', dir]);
  assert.equal(r.code, 0, r.out);
  return dir;
}

const generatedPath = (dir) => join(dir, GENERATED_REL);
const read = (dir) => readFileSync(generatedPath(dir), 'utf8');

/** The { key: value } pairs of `export const MAIL_COLORS = { ... } as const`, in order. */
function parseMailColors(text) {
  const m = text.match(/export const MAIL_COLORS = \{([\s\S]*?)\n\} as const/);
  assert.ok(m, 'an `export const MAIL_COLORS = { ... } as const` block exists');
  return [...m[1].matchAll(/^\s*([A-Za-z]+): '(#[0-9a-f]{6})',?$/gm)].map((x) => [x[1], x[2]]);
}

/** The generated text for a palette overlay, rendered with --out-dir (the sibling stays untouched). */
function renderWith(overlay) {
  const dir = fakeSibling();
  const palette = join(dir, 'overlay.json');
  writeFileSync(palette, JSON.stringify(overlay));
  const out = join(dir, 'out');
  const r = run(build, ['--palette', palette, '--out-dir', out, '--sibling', dir]);
  assert.equal(r.code, 0, r.out);
  return readFileSync(join(out, '_external', 'localnodes-onboarding', GENERATED_REL), 'utf8');
}

/* ---- what the generator writes ---- */

test('a sibling that imports the generated file gets mail-colors.generated.ts, with a GENERATED header', () => {
  const dir = generatedSibling();
  const text = read(dir);
  const head = text.split('\n').slice(0, 3);
  for (const line of head) assert.match(line, /^\/\/ /, 'the header lines are comments');
  assert.match(head.join('\n'), /GENERATED/);
  assert.match(head.join('\n'), /html\/modules\/custom\/localnodes_platform\/tokens\/localnodes\.tokens\.json/);
  assert.match(head.join('\n'), /scripts\/tokens\/build_tokens\.mjs/);
});

test('MAIL_COLORS holds exactly canvas, card, border, heading, text, button, buttonText, link, footer, in that order', () => {
  const pairs = parseMailColors(read(generatedSibling()));
  assert.deepEqual(pairs.map(([k]) => k), KEY_TO_ROLE.map(([k]) => k));
});

test('each MAIL_COLORS value is its targets.email role, so no role is added and no value changes (D-13)', () => {
  const pairs = new Map(parseMailColors(read(generatedSibling())));
  for (const [key, role] of KEY_TO_ROLE) {
    assert.ok(typeof flat.email[role] === 'string', `the flat token file has the email role ${role}`);
    assert.equal(pairs.get(key), flat.email[role], `${key} is the email ${role} role`);
  }
});

test('the file is in mail-copy.ts style: single quotes, no semicolons, no trailing comma, as const, final newline', () => {
  const text = read(generatedSibling());
  assert.ok(text.endsWith('} as const\n'), 'ends with "} as const" and a newline');
  assert.doesNotMatch(text, /;\s*$/m, 'no semicolons');
  assert.doesNotMatch(text, /"/, 'no double quotes');
  assert.doesNotMatch(text, /',\n\}/, 'no trailing comma after the last key');
  assert.match(text, /footer: '#[0-9a-f]{6}'\n\} as const/);
  assert.match(text, /only exporter of MAIL_COLORS under server\/utils/, 'the comment explains the single-exporter rule');
});

/* ---- the guards: tamper, missing, not adopted ---- */

test('--check prints OK for nuxt-mail on a fresh file', () => {
  const dir = generatedSibling();
  const r = run(build, ['--check', '--sibling', dir]);
  assert.equal(r.code, 0, r.out);
  assert.match(r.out, /^OK Nuxt mail colours/m);
  assert.doesNotMatch(r.out, /^SKIP nuxt-mail/m);
});

test('--check exits 1 and names the file when one hex in the generated mail file changes', () => {
  const dir = generatedSibling();
  const file = generatedPath(dir);
  const original = readFileSync(file, 'utf8');
  const tampered = original.replace(/#[0-9a-f]{6}/, '#000001');
  assert.notEqual(tampered, original);
  writeFileSync(file, tampered);
  const r = run(build, ['--check', '--sibling', dir]);
  assert.equal(r.code, 1, r.out);
  assert.ok(r.out.includes(file), r.out);
  assert.match(r.out, /DIFF/);
  assert.match(r.out, /RESULT: FAIL/);
});

test('a plain run writes a hand-edited mail file back to the source values', () => {
  const dir = generatedSibling();
  const file = generatedPath(dir);
  const original = readFileSync(file, 'utf8');
  writeFileSync(file, original.replace(/#[0-9a-f]{6}/, '#000001'));
  assert.equal(run(build, ['--sibling', dir]).code, 0);
  assert.equal(readFileSync(file, 'utf8'), original);
});

test('the import is present and the file is missing: --check prints MISSING for nuxt-mail and exits 1', () => {
  const r = run(build, ['--check', '--sibling', wiredSibling()]);
  assert.equal(r.code, 1, r.out);
  assert.match(r.out, /^MISSING .*mail-colors\.generated\.ts/m);
  assert.match(r.out, /RESULT: FAIL/);
});

test('not adopted (no import, no generated file): a plain run and --check print SKIP nuxt-mail, pass, and create no file', () => {
  const dir = fakeSibling();
  writeMailCopy(dir, "export const MAIL_COLORS = {\n  canvas: '#f3f3f3'\n} as const\n");
  const write = run(build, ['--sibling', dir]);
  assert.equal(write.code, 0, write.out);
  assert.match(write.out, /^SKIP nuxt-mail \(not adopted/m);
  assert.equal(existsSync(generatedPath(dir)), false, 'the generator does not create the file in a checkout that has not adopted it');
  const check = run(build, ['--check', '--sibling', dir]);
  assert.equal(check.code, 0, check.out);
  assert.match(check.out, /^SKIP nuxt-mail \(not adopted/m);
  assert.match(check.out, /RESULT: PASS/);
  assert.equal(existsSync(generatedPath(dir)), false);
});

test('a sibling with no mail-copy.ts at all is not adopted either', () => {
  const dir = fakeSibling();
  const r = run(build, ['--sibling', dir]);
  assert.equal(r.code, 0, r.out);
  assert.match(r.out, /^SKIP nuxt-mail \(not adopted/m);
  assert.equal(existsSync(join(dir, 'server')), false, 'no server/ directory is created');
});

test('an import that only appears in a comment does not count as adoption', () => {
  const dir = fakeSibling();
  writeMailCopy(dir, `// ${IMPORT_LINE.trim()}\n/* ${IMPORT_LINE.trim()} */\nexport const MAIL_COLORS = {} as const\n`);
  const r = run(build, ['--sibling', dir]);
  assert.equal(r.code, 0, r.out);
  assert.match(r.out, /^SKIP nuxt-mail \(not adopted/m);
  assert.equal(existsSync(generatedPath(dir)), false);
});

test('an import with a file extension counts as adoption', () => {
  const dir = fakeSibling();
  writeMailCopy(dir, "import { MAIL_COLORS } from './mail-colors.generated.ts'\n");
  const r = run(build, ['--sibling', dir]);
  assert.equal(r.code, 0, r.out);
  assert.match(r.out, /^WROTE .*mail-colors\.generated\.ts/m);
  assert.ok(existsSync(generatedPath(dir)));
});

test('the generated file present without the import counts as adopted: it is checked, and a changed hex is a DIFF', () => {
  const dir = generatedSibling();
  writeMailCopy(dir, "export const MAIL_COLORS = {} as const\n"); // the import is gone, the file stays
  assert.match(run(build, ['--check', '--sibling', dir]).out, /^OK Nuxt mail colours/m);
  const file = generatedPath(dir);
  writeFileSync(file, readFileSync(file, 'utf8').replace(/#[0-9a-f]{6}/, '#000001'));
  const r = run(build, ['--check', '--sibling', dir]);
  assert.equal(r.code, 1, r.out);
  assert.match(r.out, /DIFF/);
  assert.ok(r.out.includes(file), r.out);
});

test('a missing sibling prints SKIP nuxt-mail (sibling not present), passes, and writes nothing', () => {
  const absent = join(mkdtempSync(join(tmpdir(), 'ln-nuxt-mail-absent-')), 'no-such-dir');
  const check = run(build, ['--check', '--sibling', absent]);
  assert.equal(check.code, 0, check.out);
  assert.match(check.out, /^SKIP nuxt-mail \(sibling not present\)/m);
  assert.match(check.out, /RESULT: PASS/);
  const write = run(build, ['--sibling', absent]);
  assert.equal(write.code, 0, write.out);
  assert.match(write.out, /^SKIP nuxt-mail \(sibling not present\)/m);
  assert.equal(existsSync(absent), false, 'the generator does not create the sibling directory');
});

/* ---- a palette swap reaches the mail colours ---- */

test('an overlay that moves brand 700 changes the button colour', () => {
  const pairs = new Map(parseMailColors(renderWith({ primitive: { color: { brand: { 700: { $value: '#123456' } } } } })));
  assert.equal(pairs.get('button'), '#123456', 'button follows semantic.primary, which is brand 700');
  assert.equal(pairs.get('link'), flat.email.link, 'link is semantic.link, a different role');
});

test('an overlay that gives semantic.link its own value changes link and nothing else', () => {
  const pairs = new Map(parseMailColors(renderWith({ semantic: { link: { $value: '#14769b' } } })));
  assert.equal(pairs.get('link'), '#14769b');
  for (const [key, role] of KEY_TO_ROLE.filter(([k]) => k !== 'link')) {
    assert.equal(pairs.get(key), flat.email[role], `${key} is unchanged`);
  }
});

/* ---- code injection through a token value (T-22.1-43) ---- */

test('a targets.email value that is not a 6-digit hex is ERROR naming targets.email.button, exit 1, and nothing is written', () => {
  const dir = wiredSibling();
  const palette = join(dir, 'bad.json');
  writeFileSync(palette, JSON.stringify({ targets: { email: { button: "red', evil: '1" } } }));
  // --out-dir keeps a failed guard from touching this repo's own generated files; the out dir must stay empty.
  const out = join(dir, 'out');
  const r = run(build, ['--palette', palette, '--out-dir', out, '--sibling', dir]);
  assert.equal(r.code, 1, r.out);
  assert.match(r.out, /^ERROR .*targets\.email\.button/m);
  assert.match(r.out, /RESULT: FAIL/);
  assert.equal(existsSync(out), false, 'a guard failure writes nothing');
  assert.equal(existsSync(generatedPath(dir)), false, 'a guard failure writes nothing');
});

test('a missing email role is ERROR naming it, exit 1', () => {
  const dir = wiredSibling();
  const palette = join(dir, 'gone.json');
  writeFileSync(palette, JSON.stringify({ targets: { email: { 'footer-text': null } } }));
  const out = join(dir, 'out');
  const r = run(build, ['--palette', palette, '--out-dir', out, '--sibling', dir]);
  assert.equal(r.code, 1, r.out);
  assert.match(r.out, /^ERROR .*targets\.email\.footer-text/m);
  assert.equal(existsSync(out), false, 'a guard failure writes nothing');
});

/* ---- --out-dir ---- */

test('--out-dir renders the mail file under _external/localnodes-onboarding whatever the adoption, and the sibling stays untouched', () => {
  for (const dir of [fakeSibling(), wiredSibling()]) {
    const before = readdirSync(dir).sort();
    const out = mkdtempSync(join(tmpdir(), 'ln-nuxt-mail-out-'));
    const r = run(build, ['--out-dir', out, '--sibling', dir]);
    assert.equal(r.code, 0, r.out);
    const file = join(out, '_external', 'localnodes-onboarding', GENERATED_REL);
    assert.ok(existsSync(file), `${file} exists`);
    assert.match(readFileSync(file, 'utf8').split('\n').slice(0, 3).join('\n'), /GENERATED/);
    assert.equal(existsSync(generatedPath(dir)), false, 'the sibling holds no generated mail file');
    assert.deepEqual(readdirSync(dir).sort(), before, 'the sibling is untouched');
  }
});

/* ---- the real sign-up site, read only (git show) ---- */

const nuxtRepo = process.env.LN_SWAP_NUXT_REPO ?? resolve(repoRoot, '../localnodes-onboarding');
const nuxtRef = process.env.LN_SWAP_NUXT_REF ?? 'origin/main';

function gitShow(path) {
  const r = spawnSync('git', ['-C', nuxtRepo, 'show', `${nuxtRef}:${path}`], { encoding: 'utf8' });
  return r.status === 0 ? r.stdout : null;
}

/** Why the read-only match cannot run here, or false. */
function skipReason() {
  if (process.env.LN_SWAP_INNER) return 'inside the swap proof (no recursion)';
  if (spawnSync('git', ['-C', nuxtRepo, 'rev-parse', '--verify', '-q', `${nuxtRef}^{commit}`], { encoding: 'utf8' }).status !== 0) {
    return `Nuxt ref ${nuxtRef} not found in ${nuxtRepo} (CI has no sibling)`;
  }
  if (gitShow(MAIL_COPY_REL) === null) return `${nuxtRef} has no ${MAIL_COPY_REL}`;
  return false;
}
const skip = skipReason();

test('the real mail-copy.ts: its MAIL_COLORS keys and values equal the generated ones, or its committed generated file is byte for byte the output', { skip }, () => {
  const mailCopy = gitShow(MAIL_COPY_REL);
  const wired = /\b(?:from|import)\s*['"]\.\/mail-colors\.generated(?:\.[cm]?[jt]s)?['"]/.test(mailCopy.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, ''));
  const out = mkdtempSync(join(tmpdir(), 'ln-nuxt-mail-real-'));
  const gen = run(build, ['--out-dir', out, '--sibling', join(out, 'no-sibling')]);
  assert.equal(gen.code, 0, gen.out);
  const generated = readFileSync(join(out, '_external', 'localnodes-onboarding', GENERATED_REL), 'utf8');
  if (wired) {
    const committed = gitShow(GENERATED_REL);
    assert.notEqual(committed, null, `${nuxtRef}'s mail-copy.ts imports ${GENERATED_REL}, so the file must be committed there`);
    assert.equal(committed, generated, `${GENERATED_REL} at ${nuxtRef} is not what the generator renders`);
  } else {
    const literal = parseMailColors(mailCopy);
    assert.deepEqual(literal, parseMailColors(generated), `MAIL_COLORS in ${nuxtRef}'s mail-copy.ts (keys, order, values) must equal the generated block`);
  }
});

/* ---- the parity wrapper (22.1-14 Task 2) ---- */

test('check_token_parity.mjs on an adopted sibling prints OK for nuxt-mail', () => {
  const dir = generatedSibling();
  const r = run(parity, ['--sibling', dir]);
  assert.equal(r.code, 0, r.out);
  assert.match(r.out, /^OK Nuxt mail colours/m);
  assert.doesNotMatch(r.out, /^SKIP/m);
  assert.match(r.out, /RESULT: PASS/);
});

test('check_token_parity.mjs exits 1 and names the file when one hex in the generated mail file changes', () => {
  const dir = generatedSibling();
  const file = generatedPath(dir);
  writeFileSync(file, readFileSync(file, 'utf8').replace(/#[0-9a-f]{6}/, '#000001'));
  const r = run(parity, ['--sibling', dir]);
  assert.equal(r.code, 1, r.out);
  assert.match(r.out, /^DIFF Nuxt mail colours/m);
  assert.ok(r.out.includes(file), r.out);
  assert.match(r.out, /RESULT: FAIL/);
});

test('check_token_parity.mjs prints MISSING when the import is there and the file is not', () => {
  const dir = generatedSibling();
  // Remove the mail file through the filesystem, keeping the import: adopted by import, file gone.
  rmSync(generatedPath(dir));
  const r = run(parity, ['--sibling', dir]);
  assert.equal(r.code, 1, r.out);
  assert.match(r.out, /^MISSING file mail-colors: .*mail-colors\.generated\.ts/m);
});

test('check_token_parity.mjs on a sibling that has not adopted the file prints SKIP nuxt-mail and passes', () => {
  const dir = fakeSibling();
  assert.equal(run(build, ['--sibling', dir]).code, 0); // the stylesheet is written; the mail file is skipped
  const r = run(parity, ['--sibling', dir]);
  assert.equal(r.code, 0, r.out);
  assert.match(r.out, /^SKIP nuxt-mail \(not adopted/m);
  assert.match(r.out, /RESULT: PASS/);
  assert.equal(existsSync(generatedPath(dir)), false, 'the parity check writes nothing');
});

test('check_token_parity.mjs prints SKIP nuxt-mail (sibling not present) and passes when the sibling is absent', () => {
  const absent = join(mkdtempSync(join(tmpdir(), 'ln-nuxt-mail-absent-')), 'no-such-dir');
  const r = run(parity, ['--sibling', absent]);
  assert.equal(r.code, 0, r.out);
  assert.match(r.out, /^SKIP nuxt-mail \(sibling not present\)/m);
  assert.match(r.out, /RESULT: PASS/);
});

test('check_token_parity.mjs --mail-colors checks the file it is given', () => {
  const dir = generatedSibling();
  const copy = join(dir, 'copy.ts');
  const text = readFileSync(generatedPath(dir), 'utf8');
  writeFileSync(copy, text);
  const ok = run(parity, ['--sibling', dir, '--mail-colors', copy]);
  assert.equal(ok.code, 0, ok.out);
  assert.match(ok.out, /^OK Nuxt mail colours/m);
  writeFileSync(copy, text.replace(/#[0-9a-f]{6}/, '#000001'));
  const bad = run(parity, ['--sibling', dir, '--mail-colors', copy]);
  assert.equal(bad.code, 1, bad.out);
  assert.ok(bad.out.includes(copy), bad.out);
  assert.match(bad.out, /DIFF/);
});

test('check_token_parity.mjs --mail-colors without a value exits 2', () => {
  assert.equal(run(parity, ['--mail-colors']).code, 2);
});
