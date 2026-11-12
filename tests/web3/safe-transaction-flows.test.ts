/**
 * Phase 21 plan 20: approve and send in the browser (transaction-manager.js),
 * run in a node `vm` with the real safe-sdk-manager.js network checks and
 * wallet-errors.js copy, a stub flow panel, a fake wallet and stub fetch.
 *
 * Orchestrator ruling A (after 21-16): sign and execute call
 * Drupal.safeSDK.isCorrectNetwork(provider, <the Safe's registry network
 * id>), from the payment's /data response. Without that id they are refused
 * with zero wallet calls. The sign endpoint's 403 (21-34) shows the
 * not-an-approver copy; "Payment sent." waits for receipt_status confirmed
 * (21-13). After a propose, the page's success sentence takes focus.
 *
 * Skips when the gitignored drupal.org clone is absent.
 */
import { describe, expect, it } from 'vitest';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import vm from 'node:vm';

const ROOT = resolve(__dirname, '../..');
const JS = resolve(ROOT, 'repos/safe_smart_accounts/js');
const present = existsSync(resolve(JS, 'transaction-manager.js'));

const SAFE = '0x00000000000000000000000000000000000005af';
const APPROVER = '0x00000000000000000000000000000000000000a1';
const OTHER = '0x00000000000000000000000000000000000000b2';
const RECIPIENT = '0x00000000000000000000000000000000000000c3';
const SIGNATURE = '0x' + '1'.repeat(128) + '1b';
const HASH = '0x' + 'ab'.repeat(32);
const OTHER_HASH = '0x' + 'cd'.repeat(32);
// 21-25 fix 12: once the wallet may have sent, no copy says nothing was sent.
const CHECKING = 'Your wallet may have sent this. Checking…';
const UNCHECKED = "Your wallet may have sent this, and we couldn't check it yet. Look at your wallet's activity before you try again.";
const MOVED_ON = 'This was already sent, or another action replaced it. Reload this page to see which.';
/** MetaMask's -32603 "Failed to fetch" as ethers 6.16 wraps it (21-45 RCA). */
const WRAPPED_RPC = () => Object.assign(new Error('could not coalesce error (error={ "code": -32603, "message": "Failed to fetch" }, code=UNKNOWN_ERROR, version=6.16.0)'), { code: 'UNKNOWN_ERROR', error: { code: -32603, message: 'Failed to fetch' } });
const NETWORK = 'base_sepolia';

/** Drupal.t with @placeholder substitution, like core's. */
function t(str: string, args: Record<string, string> = {}) {
  return Object.entries(args).reduce((s, [k, v]) => s.split(k).join(String(v)), str);
}

type Opts = {
  network?: string | null;
  walletChain?: bigint;
  connected?: string;
  signStatus?: number;
  receiptStatus?: string;
  receiptStatuses?: string[];
  waitThrows?: boolean;
  executeFailsFrom?: number;
  executeConflict?: boolean;
  statusAnswer?: Record<string, unknown> | number;
  rejectSign?: boolean;
  rejectSend?: boolean;
  signatures?: number;
  /** executeTransaction throws this after the wallet was asked (21-25 fix 12). */
  sendThrows?: unknown;
  /** The Safe's nonce the kit reads afterwards (default the payment's 4). */
  nonceAfter?: number;
  /** Only the first N /execute posts fail (500). */
  executeFailCount?: number;
  /** drupalSettings.safeSmartAccounts.sponsored (R5, 25-15). */
  sponsored?: boolean;
  /**
   * The answer to the 25-29 send pre-check (a POST to /execute with
   * {"check": true}, asked right before the wallet is). Default: the
   * proceed answer, 200 {may_send: true}.
   */
  precheckAnswer?: { status: number; body: unknown };
  /** /execute answers in order (the last repeats), instead of the defaults. */
  executeAnswers?: Array<{ status: number; body: unknown }>;
  /** /status answers in order (the last repeats). */
  statusAnswers?: Array<Record<string, unknown>>;
};

type Button = {
  hidden?: boolean;
  className: string;
  attrs: Record<string, string>;
  listeners: Record<string, (e: unknown) => Promise<void>>;
  classList: { add(c: string): void; remove(c: string): void; contains(c: string): boolean };
  getAttribute(n: string): string | null;
  addEventListener(type: string, fn: (e: unknown) => Promise<void>): void;
  closest(): null;
};

function makeButton(kind: 'sign' | 'execute'): Button {
  const classes = new Set(['btn', 'btn-primary', `safe-transaction-${kind}`]);
  return {
    className: [...classes].join(' '),
    attrs: { 'data-safe-account-id': '5', 'data-transaction-id': '9' },
    listeners: {},
    classList: { add: (c) => classes.add(c), remove: (c) => classes.delete(c), contains: (c) => classes.has(c) },
    getAttribute(n) { return this.attrs[n] ?? null; },
    addEventListener(type, fn) { this.listeners[type] = fn; },
    closest: () => null,
  };
}

/** Loads the three scripts and returns a runner for one click. */
function harness(opts: Opts = {}) {
  const calls: string[] = [];
  const posted: Record<string, unknown> = {};
  const record: Record<string, unknown[]> = { mount: [], setState: [], done: [], waitingForRecord: [], error: [], inFlight: [], restore: [], timers: [] };
  const focused: string[] = [];

  class BrowserProvider {
    async getNetwork() { calls.push('wallet:getNetwork'); return { chainId: opts.walletChain ?? 84532n }; }
    async getSigner() { calls.push('wallet:getSigner'); return { getAddress: async () => opts.connected ?? APPROVER }; }
  }
  const json = (status: number, body: unknown) => ({ ok: status >= 200 && status < 300, status, json: async () => body, text: async () => 'tok' });
  const data: Record<string, unknown> = {
    transaction_id: 9,
    safe_address: SAFE,
    safe_account_id: 5,
    to: RECIPIENT,
    value: '50000000000000000',
    data: '0x',
    operation: 0,
    nonce: 4,
    threshold: 2,
    signers: [APPROVER, OTHER],
    signatures: Array.from({ length: opts.signatures ?? 0 }, (_, i) => ({ signer: i === 0 ? OTHER : APPROVER, signature: SIGNATURE, signed_at: 1 })),
    status: 'pending',
    can_execute: false,
  };
  if (opts.network !== null) data.network = opts.network ?? NETWORK;

  const protocolKit = {
    createTransaction: async (args: unknown) => { calls.push('kit:createTransaction'); posted.createTransaction = args; return { signatures: new Map(), addSignature() {} }; },
    getNonce: async () => { calls.push('kit:getNonce'); return opts.nonceAfter ?? 4; },
    signTransaction: async () => {
      calls.push('kit:signTransaction');
      if (opts.rejectSign) throw Object.assign(new Error('user rejected'), { code: 'ACTION_REJECTED' });
      return { signatures: new Map([[APPROVER, { data: SIGNATURE }]]) };
    },
    executeTransaction: async () => {
      calls.push('kit:executeTransaction');
      if (opts.rejectSend) throw Object.assign(new Error('user rejected'), { code: 4001 });
      if (opts.sendThrows) throw opts.sendThrows;
      return { hash: HASH, transactionResponse: { wait: async () => {
        calls.push('tx:wait');
        if (opts.waitThrows) throw new Error('could not coalesce error');
        return { status: 1 };
      } } };
    },
  };

  const ctx: Record<string, any> = {
    console: { log() {}, warn() {}, error() {} },
    Drupal: { behaviors: {}, t, announce() {} },
    // Waits resolve at once; each delay asked for is recorded.
    setTimeout: (fn: () => void, ms: number) => { record.timers.push(ms); fn(); return 0; },
    drupalSettings: {
      safeSmartAccounts: {
        ...(opts.sponsored === undefined ? {} : { sponsored: opts.sponsored }),
        networks: {
          base_sepolia: {
            chainId: 84532,
            label: 'Base Sepolia',
            isTestnet: true,
            nativeSymbol: 'ETH',
            explorer: { name: 'Blockscout', addressUrl: 'https://base-sepolia.blockscout.com/address/{address}', txUrl: 'https://base-sepolia.blockscout.com/tx/{hash}' },
          },
        },
      },
    },
    once: () => [],
    ethers: { BrowserProvider },
    ethereum: {
      request: async ({ method }: { method: string }) => { calls.push(`ethereum:${method}`); return [opts.connected ?? APPROVER]; },
    },
    fetch: async (url: string, init?: { method?: string; body?: string }) => {
      if (url.includes('/session/token')) return json(200, {});
      if (url.endsWith('/data')) { calls.push('fetch:data'); return json(200, data); }
      if (url.endsWith('/sign')) {
        calls.push('fetch:sign');
        posted.sign = init?.body ? JSON.parse(init.body) : null;
        const status = opts.signStatus ?? 200;
        const count = (opts.signatures ?? 0) + 1;
        return json(status, status === 200 ? { success: true, signature_count: count, threshold: 2, can_execute: count >= 2 } : { error: "This approval can't be accepted." });
      }
      if (url.endsWith('/status')) {
        calls.push('fetch:status');
        if (opts.statusAnswers) {
          const n = calls.filter((c) => c === 'fetch:status').length;
          return json(200, opts.statusAnswers[Math.min(n - 1, opts.statusAnswers.length - 1)]);
        }
        const answer = opts.statusAnswer ?? 500;
        return typeof answer === 'number' ? json(answer, { error: 'Something went wrong on our side.' }) : json(200, answer);
      }
      if (url.endsWith('/execute') && init?.body && JSON.parse(init.body).check === true) {
        // SSA 0dc5af4: the pre-check is its own call. It is not an execute
        // post, so it stays out of 'fetch:execute' and posted.executeAll.
        calls.push('fetch:precheck');
        posted.precheckAll = [...((posted.precheckAll as unknown[]) ?? []), JSON.parse(init.body)];
        const answer = opts.precheckAnswer ?? { status: 200, body: { may_send: true } };
        return json(answer.status, answer.body);
      }
      if (url.endsWith('/execute')) {
        calls.push('fetch:execute');
        posted.execute = init?.body ? JSON.parse(init.body) : null;
        posted.executeAll = [...(posted.executeAll ?? []), posted.execute];
        if (opts.executeAnswers) {
          const n = (posted.executeAll as unknown[]).length;
          const answer = opts.executeAnswers[Math.min(n - 1, opts.executeAnswers.length - 1)];
          return json(answer.status, answer.body);
        }
        if (opts.executeConflict) {
          // Another tab already stored its hash for this payment (21-25 fix 10).
          return json(409, { error: 'This payment was already sent. Wait for the public record to confirm it.' });
        }
        if (opts.executeFailCount !== undefined && posted.executeAll.length <= opts.executeFailCount) {
          return json(500, { error: 'Something went wrong on our side.' });
        }
        if (opts.executeFailsFrom !== undefined && posted.executeAll.length >= opts.executeFailsFrom) {
          return json(500, { error: 'Something went wrong on our side.' });
        }
        const answers = opts.receiptStatuses;
        const status = answers ? answers[Math.min(posted.executeAll.length - 1, answers.length - 1)] : (opts.receiptStatus ?? 'confirmed');
        return json(200, { success: status !== 'failed', receipt_status: status, blockchain_tx_hash: HASH, status: status === 'confirmed' ? 'executed' : status === 'failed' ? 'failed' : 'pending' });
      }
      throw new Error(`unexpected fetch ${url}`);
    },
    document: {
      querySelector: () => ({ appendChild() {} }),
      querySelectorAll: () => [],
    },
  };
  ctx.window = ctx;
  vm.createContext(ctx);
  vm.runInContext(readFileSync(resolve(JS, 'safe-sdk-manager.js'), 'utf8'), ctx);
  vm.runInContext(readFileSync(resolve(JS, 'wallet-errors.js'), 'utf8'), ctx);
  const drupal = ctx.Drupal;
  const sdk = drupal.safeSDK;
  const realIsCorrect = sdk.isCorrectNetwork.bind(sdk);
  const networkArgs: unknown[] = [];
  sdk.isCorrectNetwork = async (provider: unknown, networkId?: string) => {
    calls.push('sdk:isCorrectNetwork');
    networkArgs.push([provider instanceof BrowserProvider, networkId]);
    return realIsCorrect(provider, networkId);
  };
  sdk.init = async () => { calls.push('sdk:init'); return protocolKit; };
  const panel = {
    setState(n: number, o?: { help?: string }) { calls.push(`panel:setState:${n}`); record.setState.push(o?.help ? [n, o.help] : n); },
    done(r: unknown) { calls.push('panel:done'); record.done.push(r); },
    waitingForRecord() { calls.push('panel:waitingForRecord'); record.waitingForRecord.push(true); },
    error(text: string) { calls.push('panel:error'); record.error.push(text); },
    panel: {},
  };
  drupal.safeSmartAccounts.flowPanel = {
    mount(_c: unknown, o: unknown) { record.mount.push(o); return panel; },
    inFlight(_b: unknown, text: string) { record.inFlight.push(text); },
    restore() { record.restore.push(true); },
  };
  vm.runInContext(readFileSync(resolve(JS, 'transaction-manager.js'), 'utf8'), ctx);

  const sign = makeButton('sign');
  const execute = makeButton('execute');
  const focusTarget = { classList: { add() {}, contains: () => false }, focus: () => focused.push('proposed') };
  const context = {
    querySelectorAll: (sel: string) => {
      if (sel.includes('safe-transaction-sign')) return [sign];
      if (sel.includes('safe-transaction-execute')) return [execute];
      if (sel.includes('data-safe-focus-on-load')) return [focusTarget];
      return [];
    },
  };
  drupal.behaviors.safeTransactionManager.attach(context);
  const click = (b: Button) => b.listeners.click({ preventDefault() {}, currentTarget: b });
  const walletCalls = () => calls.filter((c) => c.startsWith('ethereum:') || c.startsWith('wallet:') || c.startsWith('kit:'));
  return { calls, posted, record, networkArgs, focused, sign, execute, approve: () => click(sign), send: () => click(execute), walletCalls };
}

describe.skipIf(!present)('Approve: the Safe\'s registry network first (ruling A)', () => {
  it('checks isCorrectNetwork(provider, data.network) after /data and before the wallet is asked anything', async () => {
    const h = harness();
    await h.approve();
    expect(h.networkArgs).toEqual([[true, NETWORK]]);
    const order = ['fetch:data', 'sdk:isCorrectNetwork', 'ethereum:eth_requestAccounts', 'kit:signTransaction', 'fetch:sign'].map((c) => h.calls.indexOf(c));
    expect(order.every((i) => i >= 0)).toBe(true);
    expect([...order].sort((a, b) => a - b)).toEqual(order);
    expect(h.posted.sign).toEqual({ signature: SIGNATURE, signer: APPROVER });
  });

  it('is refused with zero wallet calls when the payment has no network id', async () => {
    const h = harness({ network: null });
    await h.approve();
    // No network in /data: the check gets no id (an empty string) and is false.
    expect(h.networkArgs).toEqual([[true, '']]);
    expect(h.walletCalls()).toEqual([]);
    expect(h.calls).not.toContain('fetch:sign');
    expect(h.record.error).toHaveLength(1);
    expect(h.record.done).toEqual([]);
    expect(h.record.restore).toEqual([true]);
  });

  it('names the Safe\'s network when the wallet is on another one', async () => {
    const h = harness({ walletChain: 11155111n });
    await h.approve();
    expect(h.calls).not.toContain('ethereum:eth_requestAccounts');
    expect(h.record.error).toEqual(['Your wallet is on a different network. Switch it to Base Sepolia, then approve again.']);
  });

  it('uses the three-step approval panel and says how many approved', async () => {
    const h = harness();
    await h.approve();
    expect(h.record.mount).toEqual([expect.objectContaining({ kind: 'approval' })]);
    expect(h.record.inFlight).toEqual(['Approving…']);
    expect(h.record.setState).toEqual([1, 2]);
    expect(h.record.done).toHaveLength(1);
    const done = h.record.done[0] as Record<string, unknown>;
    expect(done.text).toBe('You approved this payment. Approved by 1 of 2.');
    expect(done).not.toHaveProperty('explorerHref');
    // Approved: the button goes, it isn't left spinning or offered again.
    expect(h.sign.hidden).toBe(true);
    expect(h.record.restore).toEqual([]);
  });

  it('adds "It\'s ready to send." once the approvals reach the number needed', async () => {
    const h = harness({ signatures: 1 });
    await h.approve();
    expect((h.record.done[0] as Record<string, unknown>).text).toBe("You approved this payment. Approved by 2 of 2. It's ready to send.");
  });

  it('refuses a wallet account that is not an approver before signing', async () => {
    const h = harness({ connected: RECIPIENT });
    await h.approve();
    expect(h.calls).not.toContain('kit:signTransaction');
    expect(h.record.error).toEqual(["The account in your wallet isn't one of this group fund's approvers. Switch to the account you use on this site, then approve again."]);
  });

  it('shows the server\'s 403 as the not-an-approver message (21-34)', async () => {
    const h = harness({ signStatus: 403 });
    await h.approve();
    expect(h.record.error).toEqual(["The account in your wallet isn't one of this group fund's approvers. Switch to the account you use on this site, then approve again."]);
    expect(h.record.done).toEqual([]);
    expect(h.record.restore).toEqual([true]);
    expect(h.sign.hidden).toBeFalsy();
  });

  it('shows any other refusal as the approval\'s unknown-error copy', async () => {
    const h = harness({ signStatus: 500 });
    await h.approve();
    expect(h.record.error).toEqual(["Something went wrong on our side, so your approval wasn't saved."]);
  });

  it('maps a refused wallet prompt to plain copy', async () => {
    const h = harness({ rejectSign: true });
    await h.approve();
    expect(h.record.error).toEqual(["You didn't confirm it in your wallet, so it isn't approved yet."]);
  });
});

describe.skipIf(!present)('Send: the Safe\'s registry network first (ruling A)', () => {
  it('checks isCorrectNetwork(provider, data.network) before the wallet, then runs the four receipt states', async () => {
    const h = harness({ signatures: 2 });
    await h.send();
    expect(h.networkArgs).toEqual([[true, NETWORK]]);
    // 21-25 fix 10: the hash is posted as soon as the wallet returns it.
    const order = ['fetch:data', 'sdk:isCorrectNetwork', 'ethereum:eth_requestAccounts', 'kit:executeTransaction', 'fetch:execute', 'panel:done'].map((c) => h.calls.indexOf(c));
    expect(order.every((i) => i >= 0)).toBe(true);
    expect([...order].sort((a, b) => a - b)).toEqual(order);
    expect(h.record.mount).toEqual([expect.objectContaining({ kind: 'receipt' })]);
    expect(h.record.inFlight).toEqual(['Sending…']);
    expect(h.record.setState).toEqual([1, [2, 'Sending the payment…'], 3]);
    expect(h.posted.execute).toEqual({ blockchain_tx_hash: HASH });
    expect(h.record.done).toEqual([{
      text: 'Payment sent.',
      explorerHref: `https://base-sepolia.blockscout.com/tx/${HASH}`,
      explorerName: 'Blockscout',
      shortId: HASH,
      copyLabel: 'Copy ID for this payment',
    }]);
    expect(h.execute.hidden).toBe(true);
    expect(h.record.restore).toEqual([]);
  });

  it('posts the hash the moment the wallet returns it, then waits and asks again (21-25 fix 10)', async () => {
    const h = harness({ signatures: 2, receiptStatuses: ['pending', 'confirmed'] });
    await h.send();
    const first = h.calls.indexOf('fetch:execute');
    const second = h.calls.lastIndexOf('fetch:execute');
    expect(h.calls.indexOf('kit:executeTransaction')).toBeLessThan(first);
    expect(first).toBeLessThan(h.calls.indexOf('tx:wait'));
    expect(h.calls.indexOf('tx:wait')).toBeLessThan(second);
    expect(second).toBeLessThan(h.calls.indexOf('panel:done'));
    expect(h.posted.executeAll).toEqual([{ blockchain_tx_hash: HASH }, { blockchain_tx_hash: HASH }]);
    expect(h.execute.hidden).toBe(true);
    expect(h.record.restore).toEqual([]);
  });

  it('keeps "Send payment" hidden when the re-check fails after the hash is stored', async () => {
    const h = harness({ signatures: 2, receiptStatuses: ['pending'], executeFailsFrom: 2 });
    await h.send();
    expect(h.calls.filter((c) => c === 'fetch:execute')).toHaveLength(2);
    expect(h.record.restore).toEqual([]);
    expect(h.execute.hidden).toBe(true);
    expect(h.record.waitingForRecord).toEqual([true]);
    expect(h.record.error).toEqual([]);
  });

  it('never restores the button when the first post fails: the wallet sent it (21-25 fix 12)', async () => {
    // Fix 10 restored "Send payment" here because the server had no hash.
    // The wallet had returned one, so the payment may be on its way.
    const h = harness({ signatures: 2, executeFailsFrom: 1 });
    await h.send();
    expect(h.calls.filter((c) => c === 'fetch:execute')).toHaveLength(2);
    expect(h.record.restore).toEqual([]);
    expect(h.execute.hidden).toBe(true);
    expect(h.record.error).toEqual([UNCHECKED]);
  });

  it('a first post that failed is posted again, and its answer is shown (21-25 fix 12)', async () => {
    const h = harness({ signatures: 2, executeFailCount: 1, receiptStatuses: ['ignored', 'confirmed'] });
    await h.send();
    expect(h.posted.executeAll).toEqual([{ blockchain_tx_hash: HASH }, { blockchain_tx_hash: HASH }]);
    expect(h.record.done).toHaveLength(1);
    expect(h.record.restore).toEqual([]);
  });

  it('MetaMask\'s wrapped -32603 from the send says "may have sent" and reads the Safe\'s nonce (21-25 fix 12)', async () => {
    const h = harness({ signatures: 2, sendThrows: WRAPPED_RPC() });
    await h.send();
    expect(h.record.setState).toContainEqual([2, CHECKING]);
    expect(h.calls).toContain('kit:getNonce');
    expect(h.calls).not.toContain('fetch:execute');
    expect(h.record.error).toEqual([UNCHECKED]);
    expect(h.record.error.join(' ')).not.toMatch(/on our side|nothing was sent|nothing changed|no money moved/i);
    expect(h.record.restore).toEqual([]);
    expect(h.execute.hidden).toBe(true);
  });

  it('a send that threw after the Safe moved past the payment says it was sent or replaced (21-25 fix 12)', async () => {
    const h = harness({ signatures: 2, sendThrows: new Error('timeout'), nonceAfter: 5 });
    await h.send();
    expect(h.record.error).toEqual([MOVED_ON]);
    expect(h.record.restore).toEqual([]);
  });

  it('posts once when the first answer is already final', async () => {
    const h = harness({ signatures: 2, receiptStatuses: ['confirmed'] });
    await h.send();
    expect(h.calls.filter((c) => c === 'fetch:execute')).toHaveLength(1);
    expect(h.record.done).toHaveLength(1);
  });

  it('never brings "Send payment" back once the hash is posted (21-25 fix 10)', async () => {
    const h = harness({ signatures: 2, receiptStatuses: ['pending'], waitThrows: true });
    await h.send();
    expect(h.calls.indexOf('fetch:execute')).toBeLessThan(h.calls.indexOf('tx:wait'));
    expect(h.record.restore).toEqual([]);
    expect(h.execute.hidden).toBe(true);
    expect(h.record.waitingForRecord).toEqual([true]);
    expect(h.record.error).toEqual([]);
  });

  it('reloads the payment row on a 409 instead of offering "Send payment" again (two tabs, 21-26 ruling)', async () => {
    const h = harness({ signatures: 2, executeConflict: true, statusAnswer: { status: 'pending', receipt_status: 'pending', blockchain_tx_hash: OTHER_HASH } });
    await h.send();
    expect(h.calls.filter((c) => c === 'fetch:execute')).toHaveLength(1);
    expect(h.calls.indexOf('fetch:execute')).toBeLessThan(h.calls.indexOf('fetch:status'));
    expect(h.record.restore).toEqual([]);
    expect(h.record.error).toEqual([]);
    expect(h.record.waitingForRecord).toEqual([true]);
    expect(h.execute.hidden).toBe(true);
  });

  it('shows the other tab\'s confirmed send from the reloaded row after a 409', async () => {
    const h = harness({ signatures: 2, executeConflict: true, statusAnswer: { status: 'executed', receipt_status: null, blockchain_tx_hash: OTHER_HASH } });
    await h.send();
    expect(h.record.done).toEqual([expect.objectContaining({
      text: 'Payment sent.',
      explorerHref: `https://base-sepolia.blockscout.com/tx/${OTHER_HASH}`,
      shortId: OTHER_HASH,
    })]);
    expect(h.execute.hidden).toBe(true);
    expect(h.record.restore).toEqual([]);
  });

  it('keeps "Send payment" hidden after a 409 even when the row can\'t be reloaded', async () => {
    const h = harness({ signatures: 2, executeConflict: true, statusAnswer: 500 });
    await h.send();
    expect(h.calls).toContain('fetch:status');
    expect(h.record.restore).toEqual([]);
    expect(h.record.error).toEqual([]);
    expect(h.record.waitingForRecord).toEqual([true]);
    expect(h.execute.hidden).toBe(true);
  });

  it('is refused with zero wallet calls when the payment has no network id', async () => {
    const h = harness({ network: null, signatures: 2 });
    await h.send();
    expect(h.walletCalls()).toEqual([]);
    expect(h.calls).not.toContain('fetch:execute');
    expect(h.record.error).toHaveLength(1);
    expect(h.record.restore).toEqual([true]);
  });

  it('names the Safe\'s network when the wallet is on another one', async () => {
    const h = harness({ walletChain: 1n, signatures: 2 });
    await h.send();
    expect(h.calls).not.toContain('kit:executeTransaction');
    expect(h.record.error).toEqual(['Your wallet is on a different network. Switch it to Base Sepolia, then send it again.']);
  });

  it('waits for the public record when the receipt is pending or unverified', async () => {
    for (const status of ['pending', 'unverified']) {
      const h = harness({ signatures: 2, receiptStatus: status });
      await h.send();
      expect(h.record.done).toEqual([]);
      expect(h.record.waitingForRecord).toEqual([true]);
      expect(h.execute.hidden).toBe(true);
    }
  });

  it('says it did not go through only from a failed receipt', async () => {
    const h = harness({ signatures: 2, receiptStatus: 'failed' });
    await h.send();
    expect(h.record.done).toEqual([]);
    expect(h.record.error).toEqual(["This payment didn't go through. No money moved."]);
    // A failed receipt is final: the payment can't be sent again.
    expect(h.execute.hidden).toBe(true);
    expect(h.record.restore).toEqual([]);
  });

  it('maps a refused wallet prompt to plain copy and restores the button', async () => {
    const h = harness({ signatures: 2, rejectSend: true });
    await h.send();
    expect(h.calls).not.toContain('fetch:execute');
    expect(h.record.error).toEqual(["You didn't confirm it in your wallet. Nothing changed."]);
    expect(h.record.restore).toEqual([true]);
  });
});

const SPONSORED_SENDING = 'The community is sending the payment.';
const sent = (receipt: string) => ({
  status: 200,
  body: { success: receipt !== 'failed', receipt_status: receipt, blockchain_tx_hash: HASH, status: receipt === 'confirmed' ? 'executed' : 'pending' },
});

describe.skipIf(!present)('Send when the community covers sending (R5, 25-15)', () => {
  it('posts {"sponsored": true}, never asks the wallet, and starts the panel at Sending', async () => {
    const h = harness({ sponsored: true, signatures: 2, executeAnswers: [sent('confirmed')] });
    await h.send();
    expect(h.posted.executeAll).toEqual([{ sponsored: true }]);
    expect(h.walletCalls()).toEqual([]);
    expect(h.calls).not.toContain('sdk:isCorrectNetwork');
    expect(h.calls).not.toContain('sdk:init');
    // The 'sponsored' panel (SSA 25-29(i)) has no wallet step: Sending,
    // Waiting for confirmation, Done. It starts at Sending, step 1.
    expect(h.record.mount).toEqual([expect.objectContaining({ kind: 'sponsored' })]);
    expect(h.record.setState[0]).toEqual([1, SPONSORED_SENDING]);
    expect(h.record.setState).toEqual([[1, SPONSORED_SENDING], 2]);
    expect(h.record.done).toEqual([{
      text: 'Payment sent.',
      explorerHref: `https://base-sepolia.blockscout.com/tx/${HASH}`,
      explorerName: 'Blockscout',
      shortId: HASH,
      copyLabel: 'Copy ID for this payment',
    }]);
    expect(h.execute.hidden).toBe(true);
    expect(h.record.restore).toEqual([]);
  });

  it('without the flag, the kept wallet path runs unchanged', async () => {
    const h = harness({ sponsored: false, signatures: 2 });
    await h.send();
    expect(h.calls).toContain('kit:executeTransaction');
    expect(h.posted.execute).toEqual({ blockchain_tx_hash: HASH });
    expect(h.record.setState).toEqual([1, [2, 'Sending the payment…'], 3]);
  });

  it('a sent payment not yet on the record is read again from /status until confirmed', async () => {
    const h = harness({
      sponsored: true,
      signatures: 2,
      executeAnswers: [sent('pending')],
      statusAnswers: [
        { status: 'pending', receipt_status: 'pending', blockchain_tx_hash: HASH },
        { status: 'executed', receipt_status: 'confirmed', blockchain_tx_hash: HASH },
      ],
    });
    await h.send();
    expect(h.posted.executeAll).toEqual([{ sponsored: true }]);
    expect(h.calls.filter((c) => c === 'fetch:status')).toHaveLength(2);
    expect(h.record.timers.length).toBeGreaterThan(0);
    // Waiting for confirmation is step 2 of the 'sponsored' panel.
    expect(h.record.setState).toContainEqual(2);
    expect(h.walletCalls()).toEqual([]);
    expect(h.record.done).toHaveLength(1);
    expect(h.execute.hidden).toBe(true);
    expect(h.record.restore).toEqual([]);
  });

  it('stops reading after a while and waits for the record, never offering Send again', async () => {
    const h = harness({
      sponsored: true,
      signatures: 2,
      executeAnswers: [sent('pending')],
      statusAnswers: [{ status: 'pending', receipt_status: 'pending', blockchain_tx_hash: HASH }],
    });
    await h.send();
    const reads = h.calls.filter((c) => c === 'fetch:status').length;
    expect(reads).toBeGreaterThan(1);
    expect(reads).toBeLessThanOrEqual(30);
    expect(h.record.waitingForRecord).toEqual([true]);
    expect(h.record.error).toEqual([]);
    expect(h.execute.hidden).toBe(true);
    expect(h.record.restore).toEqual([]);
  });

  it('a 429 shows the fee-limit copy with the server\'s own time text (D-30) and keeps Send', async () => {
    const h = harness({
      sponsored: true,
      signatures: 2,
      executeAnswers: [{ status: 429, body: { error: 'ignored', until: '2026-11-09T21:30:00Z', until_text: 'SERVER TIME TEXT' } }],
    });
    await h.send();
    expect(h.record.error).toEqual(["The community's network fee limit is reached. Send it again after SERVER TIME TEXT."]);
    expect(h.record.restore).toEqual([true]);
    expect(h.execute.hidden).toBeFalsy();
    expect(h.walletCalls()).toEqual([]);
  });

  it('a refusal shows the server\'s fixed sentence and keeps Send', async () => {
    for (const status of [400, 403, 422, 503]) {
      const h = harness({ sponsored: true, signatures: 2, executeAnswers: [{ status, body: { error: `Refused ${status}.` } }] });
      await h.send();
      expect(h.record.error).toEqual([`Refused ${status}.`]);
      expect(h.record.restore).toEqual([true]);
      expect(h.record.done).toEqual([]);
    }
  });

  it('a 409 means it was already sent: the row is read again, Send never comes back', async () => {
    const h = harness({
      sponsored: true,
      signatures: 2,
      executeAnswers: [{ status: 409, body: { error: 'This payment was already sent. Wait for the public record to confirm it.' } }],
      statusAnswers: [{ status: 'executed', receipt_status: null, blockchain_tx_hash: OTHER_HASH }],
    });
    await h.send();
    expect(h.walletCalls()).toEqual([]);
    expect(h.posted.executeAll).toEqual([{ sponsored: true }]);
    expect(h.record.done).toEqual([expect.objectContaining({ text: 'Payment sent.', shortId: OTHER_HASH })]);
    expect(h.record.restore).toEqual([]);
    expect(h.execute.hidden).toBe(true);
  });

  it('a server error may come after the send: Send stays hidden and the page is to be reloaded', async () => {
    const h = harness({ sponsored: true, signatures: 2, executeAnswers: [{ status: 500, body: { error: 'Something went wrong on our side.' } }] });
    await h.send();
    expect(h.record.error).toEqual(['Something went wrong on our side. Reload this page to see whether the payment was sent.']);
    expect(h.record.restore).toEqual([]);
    expect(h.execute.hidden).toBe(true);
  });

  it('never formats a date itself', () => {
    const src = readFileSync(resolve(JS, 'transaction-manager.js'), 'utf8');
    expect(src).not.toMatch(/new Date\(|toLocale|Intl\.DateTimeFormat/);
  });
});

describe.skipIf(!present)('Payment page focus and source rules', () => {
  it('moves focus to the propose success sentence once', () => {
    const h = harness();
    expect(h.focused).toEqual(['proposed']);
  });

  it('has no alert(), innerHTML, network literal or loading toast', () => {
    const src = readFileSync(resolve(JS, 'transaction-manager.js'), 'utf8');
    expect(src).not.toMatch(/alert\(|innerHTML|[Ss]epolia|11155111|etherscan|Drupal\.message\(/);
  });
});

describe.skipIf(!present)('Send: the server is asked before the wallet (SSA 25-29 pre-check)', () => {
  const BUSY = 'Another payment from this fund is still being sent. Wait for it to be confirmed, then send this one.';

  it('posts {"check": true} to /execute right before the wallet sends, then goes on', async () => {
    const h = harness({ signatures: 2 });
    await h.send();
    expect(h.posted.precheckAll).toEqual([{ check: true }]);
    const order = ['fetch:data', 'sdk:isCorrectNetwork', 'fetch:precheck', 'kit:executeTransaction', 'fetch:execute', 'panel:done'].map((c) => h.calls.indexOf(c));
    expect(order.every((i) => i >= 0)).toBe(true);
    expect([...order].sort((a, b) => a - b)).toEqual(order);
    // The pre-check saves nothing: the one real execute post is the hash.
    expect(h.posted.executeAll).toEqual([{ blockchain_tx_hash: HASH }]);
  });

  it('a busy 409 stops the send: no wallet send, no hash post, the server\'s sentence shown, button restored', async () => {
    const h = harness({ signatures: 2, precheckAnswer: { status: 409, body: { error: BUSY } } });
    await h.send();
    expect(h.calls).toContain('fetch:precheck');
    expect(h.calls).not.toContain('kit:executeTransaction');
    expect(h.calls).not.toContain('tx:wait');
    expect(h.calls).not.toContain('fetch:execute');
    expect(h.record.error).toEqual([BUSY]);
    expect(h.record.done).toEqual([]);
    expect(h.record.restore).toEqual([true]);
  });

  it.each([
    ['a server error', { status: 500, body: { error: 'Something went wrong on our side.' } }],
    ['a 200 that is not a clear yes', { status: 200, body: { success: true } }],
    ['a 403', { status: 403, body: {} }],
  ])('fails closed on %s: the wallet is never asked to send', async (_name, precheckAnswer) => {
    const h = harness({ signatures: 2, precheckAnswer });
    await h.send();
    expect(h.calls).not.toContain('kit:executeTransaction');
    expect(h.calls).not.toContain('fetch:execute');
    expect(h.record.error).toHaveLength(1);
    expect(h.record.done).toEqual([]);
    expect(h.record.restore).toEqual([true]);
  });

  it('a community-covered send does not use the wallet, so it asks no pre-check', async () => {
    const h = harness({ sponsored: true, signatures: 2, executeAnswers: [{ status: 200, body: { success: true, receipt_status: 'confirmed', blockchain_tx_hash: HASH, status: 'executed' } }] });
    await h.send();
    expect(h.calls).not.toContain('fetch:precheck');
    expect(h.posted.executeAll).toEqual([{ sponsored: true }]);
  });
});
