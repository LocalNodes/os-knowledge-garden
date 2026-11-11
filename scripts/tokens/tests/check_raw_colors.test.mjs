/**
 * Guard tests for the raw-colour guard (22.1-06, DES-01, D-03, T-22.1-17).
 *
 * Every rule in scripts/tokens/check_raw_colors.mjs has a test that shows it
 * firing: the test writes a bad file in a temp dir, runs the script as a child
 * process with --paths and asserts the exit code and the lines it prints. A
 * paired test shows the near miss that must stay quiet. No test writes inside
 * the repo; the last one reads the real tree.
 *
 * Run: node --test scripts/tokens/tests/*.test.mjs
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync, execFileSync } from 'node:child_process';
import { appendFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { copyRepo } from './helpers/repo_copy.mjs';
import { testTmpDir } from './helpers/tmp_dir.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, '../../..');
const guard = resolve(repoRoot, 'scripts/tokens/check_raw_colors.mjs');

/** A temp tree: { 'a/b.css': '...' } -> its root dir. */
function tree(files) {
  const root = testTmpDir('rawcolor');
  for (const [rel, body] of Object.entries(files)) {
    const abs = join(root, rel);
    mkdirSync(dirname(abs), { recursive: true });
    writeFileSync(abs, body);
  }
  return root;
}

function run(args, cwd = tmpdir()) {
  const r = spawnSync(process.execPath, [guard, ...args], { cwd, encoding: 'utf8' });
  return { code: r.status, out: `${r.stdout}${r.stderr}` };
}

const scan = (files) => run(['--paths', tree(files)]);
const lines = (out, prefix) => out.split('\n').filter((l) => l.startsWith(prefix));
/** Hits as "line match" pairs for one file name. */
const hits = (out, file) => lines(out, 'RAW').filter((l) => l.includes(file)).map((l) => l.replace(/^RAW \S*?([^/\s]+:\d+) /, '$1 '));

test('hex colours of 3, 4, 6 and 8 digits are hits, with file and line, and the run fails', () => {
  const r = scan({ 'a.css': '.a { color: #abc; }\n.b { color: #abcd; }\n.c { color: #aabbcc; }\n.d { color: #aabbccdd; }\n' });
  assert.equal(r.code, 1, r.out);
  assert.deepEqual(hits(r.out, 'a.css'), ['a.css:1 #abc', 'a.css:2 #abcd', 'a.css:3 #aabbcc', 'a.css:4 #aabbccdd']);
  assert.match(r.out, /RESULT: FAIL \(4 hits\)/);
  // Each occurrence counts, even the same value twice on one line.
  assert.match(scan({ 'b.css': '.a { color: #abc; background: #abc; }\n' }).out, /RESULT: FAIL \(2 hits\)/);
});

test('a 5- or 7-digit run, an HTML entity and a page fragment are not colours', () => {
  const r = scan({ 'a.css': '.a { content: "#12345"; }\n.b { content: "#1234567"; }\n.c { content: "&#123456;"; }\n.d { content: "x#abc"; }\n', 'a.twig': '<a href="#main-content">x</a><a href="#add-comment">y</a>\n' });
  assert.equal(r.code, 0, r.out);
  assert.match(r.out, /RESULT: PASS \(2 files\)/);
});

test('rgb(, rgba(, hsl( and hsla( are hits, whatever the spacing or case', () => {
  const r = scan({ 'a.css': '.a { color: rgb(1,2,3); }\n.b { color: RGBA (1,2,3,.5); }\n.c { color: hsl(1,2%,3%); }\n.d { color: hsla(1,2%,3%,.5); }\n' });
  assert.equal(r.code, 1, r.out);
  assert.deepEqual(hits(r.out, 'a.css').map((h) => h.split(' ')[0]), ['a.css:1', 'a.css:2', 'a.css:3', 'a.css:4']);
});

test('a named colour is a hit as the value of a colour property, in css and in a quoted js or yml value', () => {
  const r = scan({
    'a.css': '.a { color: white; }\n.b { border: 1px solid black; }\n.c { fill: Red; }\n.d { background: linear-gradient(to right, navy, white); }\n',
    'b.js': "el.style.backgroundColor = 'white';\nconst s = { color: 'black' };\nel.style.color = 'red';\nel.style.fill = 'navy';\nconst o = { borderColor: 'teal' };\n",
    'c.yml': 'style:\n  color: "white"\n',
  });
  assert.equal(r.code, 1, r.out);
  assert.deepEqual(hits(r.out, 'a.css').map((h) => h.split(' ')[0]), ['a.css:1', 'a.css:2', 'a.css:3', 'a.css:4', 'a.css:4']);
  assert.deepEqual(hits(r.out, 'b.js').map((h) => h.split(' ')[0]), ['b.js:1', 'b.js:2', 'b.js:3', 'b.js:4', 'b.js:5']);
  assert.deepEqual(hits(r.out, 'c.yml').map((h) => h.split(' ')[0]), ['c.yml:2']);
});

test('transparent, currentColor, inherit, none, a token, a url and a non-colour property are not hits', () => {
  const r = scan({
    'a.css': [
      '.a { color: inherit; background: transparent; border-color: currentColor; outline: none; }',
      '.b { color: var(--ln-color-white); background: url("white.png") no-repeat; }',
      '.c { white-space: nowrap; font-family: Tan, serif; content: "white"; }',
      '.d { border: 1px solid var(--ln-color-divider); fill: currentcolor; stroke: none; }',
      '',
    ].join('\n'),
  });
  assert.equal(r.code, 0, r.out);
});

test('comments are stripped in css, js, php, twig and yml; the same text in code is a hit', () => {
  const r = scan({
    'a.css': '/* #aabbcc old teal */\n.a { color: var(--ln-color-text); }\n',
    'b.js': '// #aabbcc\n/* rgb(1,2,3)\n   white */\nconst x = 1; // #aabbcc\n',
    'c.php': "<?php\n// #aabbcc\n# #aabbcc\n/** @see #aabbcc rgb(0,0,0) */\n$x = 1; // hsl(1,2%,3%)\n",
    'd.twig': '{# #aabbcc #}\n<!-- #aabbcc -->\n<p>x</p>\n',
    'e.yml': '# #aabbcc\nkey: value # #aabbcc\n',
  });
  assert.equal(r.code, 0, r.out);
  const bad = scan({
    'a.css': '.a { color: #aabbcc; }\n',
    'b.js': "const x = '#aabbcc';\n",
    'c.php': "<?php\n$x = '#aabbcc';\n",
    'd.twig': '<p style="color: #aabbcc">x</p>\n',
    'e.yml': 'key: "#aabbcc"\n',
  });
  assert.equal(bad.code, 1, bad.out);
  assert.equal(lines(bad.out, 'RAW').length, 5, bad.out);
});

test('a // or # inside a string is not a comment, so a colour after it on the line is still a hit', () => {
  const r = scan({
    'a.js': "const u = 'https://example.org/x'; const c = '#aabbcc';\n",
    'b.php': "<?php\n$u = 'http://example.org/x'; $c = '#aabbcc';\n$h = \"#\"; $d = '#ddeeff';\n$m = 'a\n// not a comment #112233\n';\n",
    'c.yml': 'a: "x # y"\nb: "#aabbcc"\nc: [#ddeeff]\n',
  });
  assert.equal(r.code, 1, r.out);
  assert.deepEqual(hits(r.out, 'a.js'), ['a.js:1 #aabbcc']);
  assert.deepEqual(hits(r.out, 'b.php'), ['b.php:2 #aabbcc', 'b.php:3 #ddeeff', 'b.php:5 #112233']);
  assert.deepEqual(hits(r.out, 'c.yml'), ['c.yml:2 #aabbcc', 'c.yml:3 #ddeeff']);
});

test('inside a yaml block scalar a # is css, not a comment: the colour is a hit', () => {
  const r = scan({ 'a.yml': ['name: X', 'auxiliaryStyle: |', '  @keyframes p {', '    0% { color: #555555; }', '  }', '# trailing #aabbcc comment', 'other: 1 # #aabbcc', ''].join('\n') });
  assert.equal(r.code, 1, r.out);
  assert.deepEqual(hits(r.out, 'a.yml'), ['a.yml:4 #555555']);
});

test('the module, inc, install, php, yaml and svg extensions are scanned; other extensions are not', () => {
  const r = scan({ 'a.module': "<?php\n$a = '#aabbcc';\n", 'b.inc': "<?php\n$a = '#aabbcc';\n", 'c.install': "<?php\n$a = '#aabbcc';\n", 'd.php': "<?php\n$a = '#aabbcc';\n", 'e.yaml': 'a: "#aabbcc"\n' });
  assert.equal(r.code, 1, r.out);
  assert.equal(lines(r.out, 'RAW').length, 5, r.out);
  assert.equal(scan({ 'c.svg': '<rect fill="#aabbcc"/>\n' }).code, 1, 'svg is scanned');
  const quiet = scan({ 'a.md': 'color: #aabbcc\n', 'b.json': '{"c": "#aabbcc"}\n', 'd.txt': '#aabbcc\n' });
  assert.equal(quiet.code, 0, quiet.out);
  assert.match(quiet.out, /RESULT: PASS \(0 files\)/);
});

test('svg: a brand mark must use a token or currentColor; hex, rgb() and a named fill or stroke are hits, XML comments and currentColor are not (carried note 3, 22.1-09)', () => {
  const r = scan({
    'a.svg': [
      '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48">',
      '  <circle cx="24" cy="24" r="15" fill="none" stroke="#176c93" stroke-width="3"/>',
      '  <circle cx="24" cy="9" r="5" fill="white"/>',
      '  <circle cx="11" cy="31" r="5" style="fill: rgb(1, 2, 3)"/>',
      '  <circle cx="37" cy="31" r="5" fill="currentColor"/>',
      '  <!-- #aabbcc in a comment is prose, not a colour -->',
      '</svg>',
      '',
    ].join('\n'),
  });
  assert.equal(r.code, 1, r.out);
  assert.deepEqual(hits(r.out, 'a.svg'), ['a.svg:2 #176c93', 'a.svg:3 white', 'a.svg:4 rgb(']);
  const clean = scan({ 'b.svg': '<svg><circle fill="currentColor" stroke="none"/><!-- #aabbcc --></svg>\n' });
  assert.equal(clean.code, 0, clean.out);
});

test('svg: a raw-color-allow comment with a reason exempts that line and prints ALLOW; with no reason it is a hit', () => {
  const ok = scan({ 'a.svg': '<circle fill="#176c93"/> <!-- raw-color-allow: logo asset, fixed brand colours -->\n' });
  assert.equal(ok.code, 0, ok.out);
  assert.ok(lines(ok.out, 'ALLOW').some((l) => l.includes('a.svg:1') && l.endsWith('logo asset, fixed brand colours')), ok.out);
  const bad = scan({ 'b.svg': '<circle fill="#176c93"/> <!-- raw-color-allow: -->\n' });
  assert.equal(bad.code, 1, bad.out);
  assert.match(bad.out, /marker has no reason/);
});

test('the two brand-mark svgs under localnodes_platform/images carry raw colours and every one is allowed with a reason (a logo asset keeps its fixed brand colours)', () => {
  const r = run(['--paths', 'html/modules/custom/localnodes_platform/images'], repoRoot);
  assert.equal(r.code, 0, r.out);
  const allow = lines(r.out, 'ALLOW');
  assert.equal(allow.length, 10, r.out);
  for (const l of allow) assert.match(l, /^ALLOW html\/modules\/custom\/localnodes_platform\/images\/localnodes-mark(-reversed)?\.svg:\d+ logo asset, fixed brand colours$/, l);
  assert.equal(allow.filter((l) => l.includes('localnodes-mark.svg')).length, 5);
  assert.equal(allow.filter((l) => l.includes('localnodes-mark-reversed.svg')).length, 5);
});

test('a file with the generator header in its first five lines is skipped and named; the header later in the file does not skip it', () => {
  const header = '/**\n * @file\n * GENERATED by scripts/tokens/build_tokens.mjs from the source\n */\n';
  const skipped = scan({ 'gen.css': `${header}:root { --a: #aabbcc; }\n` });
  assert.equal(skipped.code, 0, skipped.out);
  assert.match(skipped.out, /SKIP-GENERATED .*gen\.css/);
  const late = scan({ 'late.css': `${'\n'.repeat(6)}/* GENERATED by scripts/tokens/build_tokens.mjs */\n:root { --a: #aabbcc; }\n` });
  assert.equal(late.code, 1, late.out);
  const other = scan({ 'other.css': '/* GENERATED by hand */\n:root { --a: #aabbcc; }\n' });
  assert.equal(other.code, 1, 'only the generator\'s own header exempts a file');
});

test('a file under tests/fixtures/ is skipped; a tests/ file next to it is not', () => {
  const r = scan({ 'm/tests/fixtures/page.css': '.a { color: #aabbcc; }\n', 'm/tests/page.css': '.a { color: #112233; }\n' });
  assert.equal(r.code, 1, r.out);
  assert.deepEqual(hits(r.out, 'page.css').map((h) => h.split(' ')[1]), ['#112233']);
  const only = scan({ 'm/tests/fixtures/page.css': '.a { color: #aabbcc; }\n' });
  assert.equal(only.code, 0, only.out);
});

test('raw-color-allow with a reason is an ALLOW line and not a hit; the reason is printed', () => {
  const r = scan({
    'a.css': '.a { color: #aabbcc; } /* raw-color-allow: brand mark of a third party */\n',
    'b.php': "<?php\n$a = '#aabbcc'; // raw-color-allow: test asserts the stock colour is gone\n",
    'c.twig': '<p style="color: #aabbcc">x</p> {# raw-color-allow: mail clients ignore custom properties #}\n',
    'd.yml': 'a: "#aabbcc" # raw-color-allow: fixture value\n',
  });
  assert.equal(r.code, 0, r.out);
  const allow = lines(r.out, 'ALLOW');
  assert.equal(allow.length, 4, r.out);
  assert.ok(allow.some((l) => l.includes('a.css:1') && l.endsWith('brand mark of a third party')), r.out);
  assert.ok(allow.some((l) => l.includes('c.twig:1') && l.endsWith('mail clients ignore custom properties')), 'the closing #} is not part of the reason');
});

test('raw-color-allow with no reason is a hit (T-22.1-17)', () => {
  for (const [name, body] of Object.entries({
    'a.css': '.a { color: #aabbcc; } /* raw-color-allow: */\n',
    'b.css': '.a { color: #aabbcc; } /* raw-color-allow:   */\n',
    'c.php': "<?php\n$a = '#aabbcc'; // raw-color-allow:\n",
    'd.twig': '<p style="color: #aabbcc">x</p> {# raw-color-allow: #}\n',
  })) {
    const r = scan({ [name]: body });
    assert.equal(r.code, 1, `${name}: ${r.out}`);
    assert.match(r.out, new RegExp(`RAW .*${name}:\\d+ #aabbcc`), r.out);
    assert.equal(lines(r.out, 'ALLOW').length, 0, `${name}: a reasonless marker prints no ALLOW line`);
    assert.match(r.out, /marker has no reason/, `${name}: the hit says why`);
  }
});

test('the marker exempts only its own line, never the file or the next line', () => {
  const r = scan({ 'a.css': '/* raw-color-allow: this line only */\n.a { color: #aabbcc; }\n.b { color: #112233; } /* raw-color-allow: ok */\n.c { color: #445566; }\n' });
  assert.equal(r.code, 1, r.out);
  assert.deepEqual(hits(r.out, 'a.css'), ['a.css:2 #aabbcc', 'a.css:4 #445566']);
  assert.equal(lines(r.out, 'ALLOW').length, 1, r.out);
});

test('usage errors exit 2: an unknown flag, --paths with no path, a missing path', () => {
  assert.equal(run(['--bogus']).code, 2);
  assert.equal(run(['--paths']).code, 2);
  const missing = run(['--paths', join(tmpdir(), 'ln-rawcolor-no-such-dir-xyz')]);
  assert.equal(missing.code, 2);
  assert.match(missing.out, /path not found/);
});

test('a path inside the repo is read from git: the import module has no raw colour', () => {
  // The default scan is html/modules/custom; this one pins the Phase 22 stylesheet that carried 98 fallbacks.
  const r = run(['--paths', 'html/modules/custom/localnodes_import'], repoRoot);
  assert.equal(r.code, 0, r.out);
  assert.match(r.out, /RESULT: PASS \(\d+ files\)/);
});

test('the whole of html/modules/custom is clean: every remaining literal is a raw-color-allow line with a reason', () => {
  const r = run([], repoRoot);
  assert.equal(r.code, 0, r.out);
  assert.match(r.out, /RESULT: PASS \(\d+ files\)/);
  // The exemptions are few and each one names its reason; a long list means the marker is being used as a switch.
  // The ten svg lines are the two brand marks' fixed colours, pinned by their own test above.
  const allow = lines(r.out, 'ALLOW').filter((l) => !/\.svg:\d+ /.test(l));
  assert.ok(allow.length <= 10, `${allow.length} ALLOW lines`);
  for (const l of lines(r.out, 'ALLOW')) assert.match(l, /^ALLOW \S+:\d+ \S.*\S/, l);
});

// ---- WR-01 (22.1 review): the default root is the script's own repo, not the caller's cwd ------------------------

/** A git repo that holds a copy of this repo's scripts and module, the way the swap proof's worktree does. */
function gitCopy() {
  const { repo } = copyRepo('rawcolor-repo');
  execFileSync('git', ['init', '-q'], { cwd: repo });
  execFileSync('git', ['add', '-A'], { cwd: repo });
  return repo;
}

test('with no --paths the guard scans the repo its script lives in, from any cwd', () => {
  const repo = gitCopy();
  const copyGuard = join(repo, 'scripts/tokens/check_raw_colors.mjs');
  const runCopy = (cwd) => {
    const r = spawnSync(process.execPath, [copyGuard], { cwd, encoding: 'utf8' });
    return { code: r.status, out: `${r.stdout}${r.stderr}` };
  };
  const clean = runCopy(tmpdir());
  assert.equal(clean.code, 0, `a clean copy passes from a cwd outside any repo:\n${clean.out}`);
  // A colour in a tracked, hand-written file of the copy, found while the caller stands in the REAL (clean) repo.
  const planted = 'html/modules/custom/localnodes_platform/css/ln-overrides.css';
  appendFileSync(join(repo, planted), '\n.planted { color: #123456; }\n');
  execFileSync('git', ['add', planted], { cwd: repo });
  const hit = runCopy(repoRoot);
  assert.equal(hit.code, 1, `the guard must scan the copy, not the caller's repo:\n${hit.out}`);
  assert.ok(hit.out.includes(`RAW ${planted}:`), hit.out);
});
