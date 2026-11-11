// Guard for the Reality Arbitrator stand-in (25-29).
//
// safe_governance's setup "prepare" fetches the real GPL-3.0 Arbitrator init code from GitHub unless
// the test-only module safe_governance_arbitrator_fixture swaps the code service. So:
//   - the a11y governance seed and the e2e seed (when safe_governance is on) must enable the
//     fixture, or any run that reaches setup prepare makes a real GitHub GET;
//   - the LIVE judge seed must never enable it, and must refuse to run while it is enabled: a
//     live run has to use the real code.
// This reads the seed scripts as text, so it runs in `npm test` with no site. The behaviour
// (enable, refuse) is proven on a DDEV site and recorded in the 25-29 notes.
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const FIXTURE = 'safe_governance_arbitrator_fixture';
const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (rel: string): string => readFileSync(join(ROOT, rel), 'utf8');

/** PHP source without comments, so a mention in a docblock proves nothing. */
function code(rel: string): string {
  return read(rel)
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/^\s*(\/\/|#).*$/gm, '');
}

/** The module installer is asked to install the fixture. */
const INSTALLS_FIXTURE = new RegExp(`module_installer'\\)\\s*->\\s*install\\(\\s*\\[[^\\]]*['"]${FIXTURE}['"]`);

describe('the a11y governance seed', () => {
  const seed = code('tests/a11y/seed-governance.php');

  it('enables the Arbitrator fixture', () => {
    expect(seed).toMatch(INSTALLS_FIXTURE);
  });

  it('asserts the fixture is enabled once seeding is done', () => {
    expect(seed).toMatch(new RegExp(`moduleExists\\(\\s*['"]${FIXTURE}['"]\\s*\\)`));
  });
});

describe('the e2e seed', () => {
  const seed = code('tests/e2e/seed-e2e.php');

  it('enables the Arbitrator fixture when safe_governance is on the site', () => {
    expect(seed).toMatch(INSTALLS_FIXTURE);
    expect(seed).toMatch(/moduleExists\(\s*['"]safe_governance['"]\s*\)/);
  });
});

describe('the live judge seed', () => {
  const seed = code('tests/e2e-live/seed-governance-live.php');

  it('refuses to run while the fixture is enabled, before it reads its arguments or changes anything', () => {
    const check = seed.search(new RegExp(`moduleExists\\(\\s*['"]${FIXTURE}['"]\\s*\\)`));
    expect(check, 'a moduleExists check for the fixture').toBeGreaterThanOrEqual(0);
    // The if block alone: up to its closing brace at the start of a line.
    const guard = seed.slice(check, seed.indexOf('\n}', check));
    expect(guard).toMatch(/exit\(\s*[1-9]\d*\s*\)|throw new/);
    expect(guard).toContain(FIXTURE);
    expect(check).toBeLessThan(seed.indexOf('$args = '));
    expect(check).toBeLessThan(seed.indexOf('module_installer'));
  });

  it('never installs the fixture', () => {
    expect(seed).not.toMatch(INSTALLS_FIXTURE);
    expect(seed).not.toMatch(new RegExp(`install\\([^)]*${FIXTURE}`));
  });
});

describe('the live suite and its CI path', () => {
  it('no live file enables the fixture', () => {
    for (const rel of [
      'tests/e2e-live/seed-live.php',
      'tests/e2e-live/governance-judge.e2e.ts',
      'tests/e2e-live/live-global-setup.ts',
      'tests/e2e-live/compose.governance.yaml',
      '.github/workflows/governance-judge.yml',
    ]) {
      expect(code(rel).replace(/^\s*#.*$/gm, ''), rel).not.toMatch(
        new RegExp(`(drush\\s+(en|pm:enable|pm:install)|install\\()[^\\n]*${FIXTURE}`),
      );
    }
  });
});

describe('the old vendored Arbitrator bytecode', () => {
  it('is gone from tests/governance/vendor/reality', () => {
    let present = true;
    try {
      readFileSync(join(ROOT, 'tests/governance/vendor/reality/Arbitrator.bin'));
    } catch {
      present = false;
    }
    expect(present).toBe(false);
  });
});
