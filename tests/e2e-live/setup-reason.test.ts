import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { DRUSH_ARGS, linesFrom, READ_MS, READ_PHP, reasonLine, REFUSAL_KEYS, REFUSED_MESSAGE, setupReason, stepLine, type Runner } from './setup-reason';

const json = (state: object) => JSON.stringify(state);

describe('the reason line', () => {
  it('prints a reason only when it is exactly one of the fixed refusal keys', () => {
    for (const key of REFUSAL_KEYS) expect(reasonLine(key)).toBe(`reason: ${key}`);
    expect(reasonLine('signature_invalid')).toBe('reason: signature_invalid');
    expect(reasonLine('other')).toBe('reason: other');
  });

  it('the keys are short snake-case words, and none can carry a value', () => {
    for (const key of REFUSAL_KEYS) expect(key).toMatch(/^[a-z]+(_[a-z]+)*$/);
    expect(new Set(REFUSAL_KEYS).size).toBe(REFUSAL_KEYS.length);
  });

  it('prints no character of any other value, only that it is there and its length', () => {
    const cases: Array<[unknown, number]> = [
      ['sample_secret_token', 19],
      ['ab'.repeat(16), 32],
      ['https:once.sampletoken.example.test', 35],
      ['0x' + 'ab'.repeat(32), 66],
      ['wrong signature', 15],
      ['Signature_invalid', 17],
      ['signature_invalid ', 18],
      ['other\n', 6],
      [42, 2],
    ];
    for (const [value, length] of cases) expect(reasonLine(value)).toBe(`reason: present, not printed (${length} characters)`);
  });

  it('says when there is no reason', () => {
    expect(reasonLine(null)).toBe('reason: no log entry');
    expect(reasonLine(undefined)).toBe('reason: no log entry');
    expect(reasonLine('')).toBe('reason: empty');
  });
});

describe('the step line', () => {
  it('prints the runner own values', () => {
    expect(stepLine({ status: 'approving', detail: 'rules_refused' })).toBe('setup step: approving, stopped: rules_refused');
    expect(stepLine({ status: 'ready', detail: '' })).toBe('setup step: ready, stopped: none');
    expect(stepLine({ status: 'failed', detail: null })).toBe('setup step: failed, stopped: none');
  });

  it('never prints a value outside the lists', () => {
    expect(stepLine({ status: 'approving 0xabc', detail: 'rules_refused' })).toBe('setup step: not printable, stopped: rules_refused');
    expect(stepLine({ status: 'Approving', detail: 'held' })).toBe('setup step: not printable, stopped: held');
    expect(stepLine({ status: 'naming', detail: 'wrong signature' })).toBe('setup step: naming, stopped: not printable');
    expect(stepLine({ status: 7, detail: ['cap'] })).toBe('setup step: not printable, stopped: not printable');
  });
});

describe('reading the site', () => {
  it('reads the last JSON line of the output', () => {
    const first = json({ status: 'ready', detail: '', reason: 'other' });
    const last = json({ status: 'approving', detail: 'rules_refused', reason: '' });
    expect(linesFrom(`[notice] warming\n${first}\n${last}\n`)).toEqual([
      'setup step: approving, stopped: rules_refused',
      'reason: empty',
    ]);
  });

  it('prints not read on no output or malformed JSON', () => {
    const notRead = ['setup step: not read', 'reason: not read'];
    expect(linesFrom(null)).toEqual(notRead);
    expect(linesFrom('')).toEqual(notRead);
    expect(linesFrom('{"status": "approving"')).toEqual(notRead);
    expect(linesFrom('null\n[1]')).toEqual(notRead);
    expect(linesFrom('{}x')).toEqual(notRead);
  });

  it('a failed, timed-out or throwing read prints not read and never throws', async () => {
    const notRead = ['setup step: not read', 'reason: not read'];
    expect(await setupReason(async () => null)).toEqual(notRead);
    expect(await setupReason(async () => { throw new Error('docker: not found'); })).toEqual(notRead);
    expect(await setupReason(() => { throw new Error('spawn failed'); })).toEqual(notRead);
  });

  it('makes one fixed drush read in the test site, within its time limit', async () => {
    const calls: Array<[string, string[], number]> = [];
    const runner: Runner = async (file, args, ms) => {
      calls.push([file, args, ms]);
      return json({ status: 'approving', detail: 'rules_refused', reason: 'wrong_types' });
    };
    expect(await setupReason(runner)).toEqual(['setup step: approving, stopped: rules_refused', 'reason: wrong_types']);
    expect(calls).toHaveLength(1);
    const [file, args, ms] = calls[0];
    expect(file).toBe('docker');
    expect(ms).toBe(20_000);
    expect(args.slice(0, -1)).toEqual([
      'compose', '-f', 'docker-compose.yaml', '-f', 'tests/e2e-live/compose.ci.yaml', '-f', 'tests/e2e-live/compose.governance.yaml',
      'exec', '-T', 'opensocial', '/var/www/html/vendor/bin/drush', '-r', '/var/www/html/html', '--uri=http://localhost:8080',
      'php:eval',
    ]);
    const php = args[args.length - 1];
    expect(php).toContain("\\Drupal::entityQuery('safe_governance')->accessCheck(FALSE)->sort('id', 'DESC')->range(0, 1)");
    expect(php).toContain("getStorage('safe_governance')");
    expect(php).toContain("->get('status')->value");
    expect(php).toContain("->get('step_detail')->value");
    expect(php).toContain("->condition('type', 'safe_governance')");
    expect(php).toContain("->condition('message', 'The voting service refused the settings of voting record @id (@reason).')");
    expect(php).toContain("->orderBy('wid', 'DESC')->range(0, 1)");
    expect(php).toContain("@unserialize($row, ['allowed_classes' => FALSE])");
    expect(php).not.toMatch(/\bunserialize\((?![^)]*'allowed_classes' => FALSE)/);
    expect(php).not.toMatch(/\/\/|\/\*|#/);
    expect(args).toBe(DRUSH_ARGS);
    expect(php).toBe(READ_PHP);
  });

  it('reads the message safe_governance logs, word for word', () => {
    expect(REFUSED_MESSAGE).toBe('The voting service refused the settings of voting record @id (@reason).');
    expect(REFUSED_MESSAGE).not.toContain("'");
  });
});

describe('the judge prints the reason when the setup wait fails', () => {
  it('reads it in the catch of the setup wait, then throws the same error', () => {
    const judge = readFileSync(join(dirname(fileURLToPath(import.meta.url)), 'governance-judge.e2e.ts'), 'utf8');
    expect(judge).toMatch(/import \{ setupReason \} from '\.\/setup-reason';/);
    const at = judge.indexOf("await until(page, 'Group voting is ready.', ");
    const wait = judge.slice(judge.lastIndexOf('try {', at), judge.indexOf('throw error;', at) + 'throw error;'.length);
    expect(wait.startsWith('try {')).toBe(true);
    expect(wait).toMatch(/catch \(error\) \{\s*\/\/[^\n]*\n\s*for \(const line of await setupReason\(\)\) console\.log\(`\[judge\] \$\{line\}`\);\s*throw error;$/);
    expect(judge.match(/setupReason\(/g)?.length).toBe(1);
  });
});
