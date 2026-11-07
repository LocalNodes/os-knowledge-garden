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
  const record: Record<string, unknown[]> = { mount: [], setState: [], done: [], waitingForRecord: [], error: [], inFlight: [], restore: [] };
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
    drupalSettings: {
      safeSmartAccounts: {
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
        const answer = opts.statusAnswer ?? 500;
        return typeof answer === 'number' ? json(answer, { error: 'Something went wrong on our side.' }) : json(200, answer);
      }
      if (url.endsWith('/execute')) {
        calls.push('fetch:execute');
        posted.execute = init?.body ? JSON.parse(init.body) : null;
        posted.executeAll = [...(posted.executeAll ?? []), posted.execute];
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
    expect(h.record.setState).toEqual([1, [2, 'Your wallet is sending the payment.'], 3]);
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
