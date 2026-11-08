// Unit guard for the live suite's failure dump (21-46). The dump goes to a public CI log.
// Ruling after Codex (5629e71..610091c): an allowlist. Only fixed fields and known keys are
// printed; redact() stays as defence in depth for the one free-form path left (none today).
import { describe, expect, it } from 'vitest';
import {
  blockCategory,
  blockValue,
  categorized,
  consoleKey,
  errorCategory,
  FailureDump,
  hexQuantity,
  panelSummary,
  receiptOutcome,
  redact,
  routeKey,
  txHashText,
  updateDeploymentLine,
} from './failure-dump';

const SEED = 'test test test test test test test test test test test junk';

describe('redact', () => {
  it('removes a 12-word seed phrase, alone or inside a line', () => {
    expect(redact(SEED)).not.toContain('junk');
    // A lowercase word next to the phrase may go with it: redacting more is the safe side.
    const out = redact(`console: imported ${SEED} OK`);
    expect(out).toMatch(/^console: (imported )?\[redacted words\] OK$/);
    expect(out).not.toMatch(/\btest\b|junk/);
  });

  it('removes cookie, CSRF token and authorization values', () => {
    const line = 'Cookie: SSESSabc123=s3cr3t; X-CSRF-Token: tok-en_1 authorization=Bearer xyz set-cookie=SESSf00=val';
    const out = redact(line);
    for (const secret of ['s3cr3t', 'tok-en_1', 'Bearer xyz', 'xyz', 'val']) expect(out).not.toContain(secret);
    expect(out).toContain('[redacted]');
  });

  it('removes a Drupal session cookie pair anywhere', () => {
    expect(redact('saw SSESS0123abcd=AbC-dEf_123 in a header')).toBe('saw SSESS0123abcd=[redacted] in a header');
  });

  it('keeps addresses, hashes and ordinary sentences', () => {
    const line = 'update-deployment 200 receipt_status=pending for 0x977c68971e548626640025a9209454167b2726aba64e5d919f82cd706f700b91 at 0xc0eE95AE5a54b0AFa511c453459f4d29e22aA4c9';
    expect(redact(line)).toBe(line);
    expect(redact('Waiting for the public record to confirm this deployment.')).toBe('Waiting for the public record to confirm this deployment.');
  });

  it('caps a long line', () => {
    expect(redact('x'.repeat(5000)).length).toBeLessThanOrEqual(600);
  });
});

// Codex on 5629e71..610091c (three P1s); ruling: an allowlist. The dump prints fixed fields
// and known keys only, never text from MetaMask, the console or Drupal. Each hostile input below
// must leave no trace in the output.
const HOSTILE = [
  Buffer.from(SEED).toString('base64'), // an encoded mnemonic
  encodeURIComponent(SEED),
  JSON.stringify(SEED.split(' ')), // a JSON-array mnemonic
  '"x-csrf-token":"abc123secretTOKEN"',
  'https://user:SECRETKEY@rpc.example/v3/APIKEY123',
  'password=hunter2 field',
  'MetaMask - RPC Error: request to https://sepolia.infura.io/v3/INFURAKEY99 failed, reason: timeout',
];
const TRACES = ['dGVzd', 'test', 'junk', 'abc123secret', 'SECRETKEY', 'APIKEY123', 'rpc.example', 'hunter2', 'INFURAKEY99', 'infura', 'x-csrf-token', 'password'];
function noTrace(out: string): void {
  for (const t of TRACES) expect(out.toLowerCase()).not.toContain(t.toLowerCase());
}

describe('allowlist: no input echoes', () => {
  it('updateDeploymentLine prints the status, enums and an error flag only', () => {
    expect(updateDeploymentLine(200, { success: true, status: 'deploying', receipt_status: 'pending', message: 'x' })).toBe(
      'update-deployment 200 status=deploying receipt_status=pending error=no',
    );
    expect(updateDeploymentLine(500, null)).toBe('update-deployment 500 status=- receipt_status=- error=no');
    for (const h of HOSTILE) {
      const out = updateDeploymentLine(403, { status: h, receipt_status: h, error: h });
      expect(out).toBe('update-deployment 403 status=other receipt_status=other error=yes');
      noTrace(out);
    }
  });

  it('panelSummary prints known keys and a count of unrecognised characters', () => {
    const known = 'Setting up the group fund Waiting for your approval Sending Waiting for confirmation This usually takes less than a minute. Done';
    expect(panelSummary({ state: '3', hidden: false, text: known, hasDone: false, hasError: false })).toBe(
      'receipt panel: state=3 shown done=no error=no keys=heading,step_approval,step_sending,step_confirmation,help_confirmation,step_done unrecognised=0',
    );
    for (const h of HOSTILE) {
      const out = panelSummary({ state: h, hidden: true, text: `Setting up the group fund ${h}`, hasDone: false, hasError: true });
      expect(out).toMatch(/^receipt panel: state=other hidden done=no error=yes keys=heading unrecognised=\d+$/);
      noTrace(out);
    }
  });

  it('consoleKey maps lines to fixed categories', () => {
    expect(consoleKey('error', '[Safe Deployment] update-deployment refused: 403 nope')).toBe('safe:update_refused');
    expect(consoleKey('error', '[Safe Deployment] Entity update error: TypeError')).toBe('safe:entity_update_error');
    expect(consoleKey('log', '[Safe SDK] something')).toBe('safe:other');
    expect(consoleKey('log', 'hello')).toBeNull();
    for (const h of HOSTILE) {
      const out = String(consoleKey('error', h));
      expect(out).toBe('error');
      noTrace(out);
    }
  });

  it('block numbers are integers, and errors fixed categories', () => {
    expect(blockValue('0xb87e17')).toBe('12090903');
    expect(blockCategory(new Error('no answer in 6000 ms'))).toBe('timeout');
    expect(blockCategory(new Error('window.ethereum is undefined'))).toBe('no_provider');
    for (const h of HOSTILE) {
      noTrace(blockValue(h));
      expect(blockValue(h)).toBe('rpc_error');
      expect(blockCategory(new Error(h))).toMatch(/^(timeout|rpc_error|no_provider)$/);
      noTrace(blockCategory(new Error(h)));
    }
  });

  it('FailureDump prints fixed lines only, whatever the page sent', () => {
    const dump = new FailureDump();
    for (const h of HOSTILE) {
      dump.console('error', h);
      dump.console('warning', h);
      dump.pageError();
      dump.requestFailed();
    }
    dump.console('error', '[Safe Deployment] update-deployment refused: 403 x');
    const out = dump.lines().join('\n');
    expect(out).toContain(`console: errors=${HOSTILE.length + 1} warnings=${HOSTILE.length}`);
    expect(out).toContain('safe:update_refused=1');
    expect(out).toContain(`pageerrors=${HOSTILE.length}`);
    noTrace(out);
  });
});

// Codex round 2 on 610091c..7db5cac (FIX): route keys, never path segments; provider and
// Playwright errors as a fixed category plus the error's name, never its message.
describe('routeKey', () => {
  it('maps known routes to fixed keys', () => {
    expect(routeKey('http://localhost:8080/group/1/treasury/create')).toBe('group_treasury_create');
    expect(routeKey('http://localhost:8080/group/12/treasury')).toBe('group_treasury');
    // The exact routes the live spec visits (Codex round 3: the payment route is singular).
    expect(routeKey('http://localhost:8080/group/1/treasury/propose')).toBe('group_treasury_propose');
    expect(routeKey('http://localhost:8080/group/1/treasury/transaction/7')).toBe('group_treasury_payment');
    expect(routeKey('http://localhost:8080/group/1/treasury/transactions/7')).toBe('other');
    expect(routeKey('http://localhost:8080/user/login?destination=/x')).toBe('user_login');
  });

  it('prints "other" for anything else, with no segment of it', () => {
    for (const h of [...HOSTILE, 'http://localhost:8080/user/1/edit', 'not a url', `http://localhost:8080/${encodeURIComponent(SEED)}`]) {
      const out = routeKey(h);
      expect(out).toBe('other');
      noTrace(out);
    }
  });
});

describe('categorized errors', () => {
  const named = (name: string, message: string, extra: Record<string, unknown> = {}) => Object.assign(new Error(message), { name }, extra);

  it('maps errors to fixed categories', () => {
    expect(errorCategory(named('TimeoutError', 'locator.click: Timeout 30000ms exceeded.'))).toBe('playwright_timeout');
    expect(errorCategory(new Error('page.evaluate: Target page, context or browser has been closed'))).toBe('wallet_closed');
    expect(errorCategory(named('Error', 'MetaMask Tx Signature: User denied transaction signature.', { code: 4001 }))).toBe('user_rejected');
    expect(errorCategory(new Error('eth_getBalance: HTTP 429 from the Sepolia RPC'))).toBe('rpc_error');
    expect(errorCategory(new Error('no answer in 5000 ms'))).toBe('timeout');
    expect(errorCategory(new Error('something odd'))).toBe('other');
  });

  it('keeps only the category and the error name; a URL in the message comes out as neither', () => {
    const e = named('FetchError', 'request to https://user:SECRETKEY@rpc.example/v3/APIKEY123 failed, reason: connect ECONNREFUSED');
    const out = categorized(e);
    expect(out).toBeInstanceOf(Error);
    expect(out.message).toBe('rpc_error (FetchError)');
    noTrace(out.message);
    // The stack holds frames (file paths, including this test file's name), never the old message.
    for (const t of ['SECRETKEY', 'APIKEY123', 'rpc.example', 'ECONNREFUSED']) expect(String(out.stack ?? '')).not.toContain(t);
    for (const h of HOSTILE) {
      const c = categorized(named(h.slice(0, 30), h));
      expect(c.message).toMatch(/^(rpc_error|timeout|user_rejected|wallet_closed|playwright_timeout|other) \([A-Za-z]{1,40}\)$/);
      noTrace(c.message);
    }
    expect(categorized('a string with https://x/KEY').message).toBe('other (unknown)');
    // Only known error names print; a letters-only name of any other kind prints as Error.
    expect(categorized(named('SECRETKEYname', 'x')).message).toBe('other (Error)');
  });
});

// Codex round 3 on 7db5cac..1d9f759 (FIX): RPC results are checked before use, so a malformed
// result can't reach an error message.
describe('RPC result checks', () => {
  it('hexQuantity accepts a hex quantity only', () => {
    expect(hexQuantity('0x0')).toBe(0n);
    expect(hexQuantity('0xDE0B6B3A7640000')).toBe(10n ** 18n);
    for (const bad of [...HOSTILE, '', '0x', '12', 12, null, undefined, { result: '0x1' }, '0x1 extra']) {
      let message = '';
      try {
        hexQuantity(bad);
      }
      catch (e) {
        message = e instanceof Error ? e.message : String(e);
      }
      expect(message).toBe('rpc_error (bad_result)');
    }
  });

  it('receiptOutcome reads only a 0x0 or 0x1 status', () => {
    expect(receiptOutcome({ status: '0x1' })).toBe('success');
    expect(receiptOutcome({ status: '0x0' })).toBe('failed');
    expect(receiptOutcome(null)).toBe('pending');
    expect(receiptOutcome({})).toBe('pending');
    for (const h of HOSTILE) {
      const out = receiptOutcome({ status: h });
      expect(out).toBe('bad_result');
      noTrace(out);
    }
  });

  it('txHashText prints a 32-byte hash, else bad_hash', () => {
    const hash = '0x977c68971e548626640025a9209454167b2726aba64e5d919f82cd706f700b91';
    expect(txHashText(hash)).toBe(hash);
    for (const h of HOSTILE) {
      expect(txHashText(h)).toBe('bad_hash');
    }
    expect(txHashText(42)).toBe('bad_hash');
  });
});

