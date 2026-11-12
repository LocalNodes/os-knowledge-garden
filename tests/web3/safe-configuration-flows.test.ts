/**
 * Phase 21 plan 24: approval settings in the browser
 * (safe-configuration-manager.js), run in a node `vm` with the real
 * safe-sdk-manager.js network checks and wallet-errors.js copy, a stub flow
 * panel, a fake wallet and stub fetch.
 *
 * Orchestrator ruling A (after 21-16): every protocol-kit helper and the
 * send of a configuration change call Drupal.safeSDK.isCorrectNetwork(
 * provider, <the Safe's registry network id>) before any other wallet call.
 * Without the id they are refused with zero wallet calls. D-12 is DEAD: the
 * unrouted create-transaction call is gone, and saving is the Drupal form,
 * which only shows "Saving…" while it posts.
 *
 * Skips when the gitignored drupal.org clone is absent.
 */
import { describe, expect, it } from 'vitest';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import vm from 'node:vm';

const ROOT = resolve(__dirname, '../..');
const JS = resolve(ROOT, 'repos/safe_smart_accounts/js');
const present = existsSync(resolve(JS, 'safe-configuration-manager.js'));

const SAFE = '0x00000000000000000000000000000000000005af';
const APPROVER = '0x00000000000000000000000000000000000000a1';
const OTHER = '0x00000000000000000000000000000000000000b2';
const NEW_OWNER = '0x00000000000000000000000000000000000000c3';
const SIGNATURE = '0x' + '1'.repeat(128) + '1b';
const HASH = '0x' + 'ab'.repeat(32);
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
  receiptStatus?: string;
  rejectSend?: boolean;
  /** /execute answers 409: another tab already stored a send (21-27). */
  executeConflict?: boolean;
  /** The /status answer: a row, or an HTTP status for a failed read. */
  statusAnswer?: Record<string, unknown> | number;
  /** executeTransaction throws this after the wallet was asked (21-25 fix 12). */
  sendThrows?: unknown;
  /** The Safe's nonce the kit reads afterwards (default the change's 4). */
  nonceAfter?: number;
  /** The hash comes back, then wait() throws. */
  waitThrows?: boolean;
  /**
   * The answer to the 25-29 send pre-check (a POST to /execute with
   * {"check": true}, asked right before the wallet is). Default: the
   * proceed answer, 200 {may_send: true}.
   */
  precheckAnswer?: { status: number; body: unknown };
  /** /execute answers this HTTP status (default 200). */
  executeStatus?: number;
  /** The receipt status of each /execute answer in turn (the last repeats). */
  receiptStatuses?: string[];
  /** /execute answers 500 from this post on (1 = the first). */
  executeFailsFrom?: number;
};

/** Loads the three scripts and returns the exports and what they did. */
function harness(opts: Opts = {}) {
  const calls: string[] = [];
  const posted: Record<string, unknown> = {};
  const record: Record<string, unknown[]> = { mount: [], setState: [], done: [], waitingForRecord: [], error: [], inFlight: [], restore: [] };

  class BrowserProvider {
    async getNetwork() { calls.push('wallet:getNetwork'); return { chainId: opts.walletChain ?? 84532n }; }
    async getSigner() { calls.push('wallet:getSigner'); return { getAddress: async () => APPROVER }; }
  }
  const json = (status: number, body: unknown) => ({ ok: status >= 200 && status < 300, status, json: async () => body, text: async () => 'tok' });
  const data: Record<string, unknown> = {
    transaction_id: 9,
    safe_address: SAFE,
    safe_account_id: 5,
    to: SAFE,
    value: '0',
    data: '0x694e80c3' + '2'.padStart(64, '0'),
    operation: 0,
    nonce: 4,
    threshold: 2,
    signers: [APPROVER, OTHER],
    signatures: [{ signer: OTHER, signature: SIGNATURE, signed_at: 1 }, { signer: APPROVER, signature: SIGNATURE, signed_at: 2 }],
    status: 'pending',
    can_execute: true,
  };
  if (opts.network !== null) data.network = opts.network ?? NETWORK;

  const protocolKit = {
    createAddOwnerTx: async (args: unknown) => { calls.push('kit:createAddOwnerTx'); posted.add = args; return { kind: 'add' }; },
    createRemoveOwnerTx: async (args: unknown) => { calls.push('kit:createRemoveOwnerTx'); posted.remove = args; return { kind: 'remove' }; },
    createSwapOwnerTx: async (args: unknown) => { calls.push('kit:createSwapOwnerTx'); posted.swap = args; return { kind: 'swap' }; },
    createChangeThresholdTx: async (args: unknown) => { calls.push('kit:createChangeThresholdTx'); posted.threshold = args; return { kind: 'threshold' }; },
    createTransaction: async (args: unknown) => { calls.push('kit:createTransaction'); posted.createTransaction = args; return { signatures: new Map(), addSignature() {} }; },
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
    getNonce: async () => { calls.push('kit:getNonce'); return opts.nonceAfter ?? 4; },
  };

  // window.setTimeout callbacks, run by the test (21-25 fix 14).
  const timers: Array<() => void> = [];
  const ctx: Record<string, any> = {
    console: { log() {}, warn() {}, error() {} },
    setTimeout: (fn: () => void) => { timers.push(fn); return timers.length; },
    addEventListener() {},
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
      request: async ({ method }: { method: string }) => { calls.push(`ethereum:${method}`); return [APPROVER]; },
    },
    fetch: async (url: string, init?: { method?: string; body?: string }) => {
      if (url.includes('/session/token')) return json(200, {});
      if (url.endsWith('/data')) { calls.push('fetch:data'); return json(200, data); }
      if (url.endsWith('/status')) {
        calls.push('fetch:status');
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
        posted.executeAll = [...((posted.executeAll as unknown[]) ?? []), posted.execute];
        const count = (posted.executeAll as unknown[]).length;
        if (opts.executeFailsFrom !== undefined && count >= opts.executeFailsFrom) {
          return json(500, { error: 'Something went wrong on our side.' });
        }
        if (opts.executeStatus && opts.executeStatus !== 200) {
          return json(opts.executeStatus, { error: 'Something went wrong on our side.' });
        }
        if (opts.executeConflict) {
          // Another tab already stored its send for this change (21-25 fix 10).
          return json(409, { error: 'This payment was already sent. Wait for the public record to confirm it.' });
        }
        const answers = opts.receiptStatuses;
        const status = answers ? answers[Math.min(count - 1, answers.length - 1)] : (opts.receiptStatus ?? 'confirmed');
        return json(200, { success: status !== 'failed', receipt_status: status, blockchain_tx_hash: HASH });
      }
      calls.push(`fetch:other:${url}`);
      throw new Error(`unexpected fetch ${url}`);
    },
    document: {
      querySelector: () => ({ appendChild() {} }),
      querySelectorAll: () => [],
      body: { appendChild() {} },
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
  sdk.clearCache = () => { calls.push('sdk:clearCache'); };
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
  vm.runInContext(readFileSync(resolve(JS, 'safe-configuration-manager.js'), 'utf8'), ctx);

  const walletCalls = () => calls.filter((c) => c.startsWith('ethereum:') || c.startsWith('wallet:') || c.startsWith('kit:') || c === 'sdk:init');
  return { ctx, calls, posted, record, networkArgs, walletCalls, timers, api: ctx.window.SafeConfigurationManager };
}

/** A submit button with the in-flight text the form renders. */
function saveButton() {
  return { attrs: { 'data-safe-inflight-text': 'Saving…' } as Record<string, string>, getAttribute(n: string) { return this.attrs[n] ?? null; } };
}

describe.skipIf(!present)('Configuration helpers: the Safe\'s registry network first (21-24, ruling A)', () => {
  const helpers: Array<[string, (api: any, network?: string) => Promise<unknown>, string]> = [
    ['createAddOwnerTransaction', (api, network) => api.createAddOwnerTransaction(SAFE, NEW_OWNER, 2, network), 'kit:createAddOwnerTx'],
    ['createRemoveOwnerTransaction', (api, network) => api.createRemoveOwnerTransaction(SAFE, OTHER, 1, network), 'kit:createRemoveOwnerTx'],
    ['createSwapOwnerTransaction', (api, network) => api.createSwapOwnerTransaction(SAFE, OTHER, NEW_OWNER, network), 'kit:createSwapOwnerTx'],
    ['createChangeThresholdTransaction', (api, network) => api.createChangeThresholdTransaction(SAFE, 2, network), 'kit:createChangeThresholdTx'],
  ];

  for (const [name, run, kitCall] of helpers) {
    it(`${name} checks isCorrectNetwork(provider, networkId) before protocol-kit`, async () => {
      const h = harness();
      await run(h.api, NETWORK);
      expect(h.networkArgs).toEqual([[true, NETWORK]]);
      const order = ['sdk:isCorrectNetwork', 'sdk:init', kitCall].map((c) => h.calls.indexOf(c));
      expect(order.every((i) => i >= 0)).toBe(true);
      expect([...order].sort((a, b) => a - b)).toEqual(order);
    });

    it(`${name} is refused with zero wallet calls without a network id`, async () => {
      const h = harness();
      const error = await run(h.api).then(() => null, (e: unknown) => e);
      expect(error).toMatchObject({ code: 'SSA_WRONG_NETWORK' });
      expect(h.networkArgs).toEqual([[true, undefined]]);
      expect(h.walletCalls()).toEqual([]);
    });
  }

  it('passes the owner and threshold through to protocol-kit', async () => {
    const h = harness();
    await h.api.createAddOwnerTransaction(SAFE, NEW_OWNER, 2, NETWORK);
    await h.api.createChangeThresholdTransaction(SAFE, 3, NETWORK);
    expect(h.posted.add).toEqual({ ownerAddress: NEW_OWNER, threshold: 2 });
    expect(h.posted.threshold).toBe(3);
  });
});

describe.skipIf(!present)('Send a configuration change: /data, then the network, then the wallet (21-24, ruling A)', () => {
  it('checks isCorrectNetwork(provider, data.network) after /data and before eth_requestAccounts', async () => {
    const h = harness();
    await h.api.executeConfigurationChange('5', '9');
    expect(h.networkArgs).toEqual([[true, NETWORK]]);
    // 21-25 fix 13: the hash goes to /execute right after the wallet
    // returns it. The first answer here is final, so there is no wait().
    const order = ['fetch:data', 'sdk:isCorrectNetwork', 'ethereum:eth_requestAccounts', 'kit:executeTransaction', 'fetch:execute', 'panel:done'].map((c) => h.calls.indexOf(c));
    expect(order.every((i) => i >= 0)).toBe(true);
    expect([...order].sort((a, b) => a - b)).toEqual(order);
    expect(h.calls).not.toContain('tx:wait');
    expect(h.record.mount).toEqual([expect.objectContaining({ kind: 'receipt' })]);
    expect(h.record.setState).toEqual([1, [2, 'Sending the change…'], 3]);
    expect(h.posted.execute).toEqual({ blockchain_tx_hash: HASH });
    expect(h.record.done).toEqual([{
      text: 'Change sent.',
      explorerHref: `https://base-sepolia.blockscout.com/tx/${HASH}`,
      explorerName: 'Blockscout',
      shortId: HASH,
      copyLabel: 'Copy ID for this change',
    }]);
    expect(h.record.error).toEqual([]);
  });

  it('is refused with zero wallet calls when /data has no network id', async () => {
    const button = { hidden: false };
    const h = harness({ network: null });
    await h.api.executeConfigurationChange('5', '9', { button });
    expect(h.networkArgs).toEqual([[true, '']]);
    expect(h.walletCalls()).toEqual([]);
    expect(h.calls).not.toContain('fetch:execute');
    expect(h.record.error).toHaveLength(1);
    expect(h.record.done).toEqual([]);
    expect(h.record.inFlight).toEqual(['Sending…']);
    expect(h.record.restore).toEqual([true]);
  });

  it('names the Safe\'s network when the wallet is on another one', async () => {
    const h = harness({ walletChain: 1n });
    await h.api.executeConfigurationChange('5', '9');
    expect(h.calls).not.toContain('ethereum:eth_requestAccounts');
    expect(h.record.error).toEqual(['Your wallet is on a different network. Switch it to Base Sepolia, then send it again.']);
  });

  it('maps a refused wallet prompt to plain copy', async () => {
    const h = harness({ rejectSend: true });
    await h.api.executeConfigurationChange('5', '9');
    expect(h.calls).not.toContain('fetch:execute');
    expect(h.record.error).toEqual(["You didn't confirm it in your wallet. Nothing changed."]);
  });

  it('waits for the public record unless the receipt is confirmed', async () => {
    const h = harness({ receiptStatus: 'pending' });
    await h.api.executeConfigurationChange('5', '9');
    expect(h.record.done).toEqual([]);
    expect(h.record.waitingForRecord).toEqual([true]);
  });

  it('a failed receipt says nothing changed and what to do next (21-29 copy pass, C22)', async () => {
    const h = harness({ receiptStatus: 'failed' });
    await h.api.executeConfigurationChange('5', '9');
    expect(h.record.done).toEqual([]);
    expect(h.record.error).toEqual(["This change didn't go through, so nothing changed. Propose it again if it's still needed."]);
    // A failed receipt is final: the change can't be sent again, so the
    // copy says to propose it again, not to send it.
    expect(h.record.restore).toEqual([]);
  });

  // Two tabs (21-27, the same gap 21-26 closed in transaction-manager.js):
  // a 409 from /execute means another tab's send is stored. Reload the row
  // and show its state; never restore the send button.
  const OTHER_HASH = '0x' + 'cd'.repeat(32);

  it('reloads the change\'s row on a 409 instead of restoring the button', async () => {
    const button = { hidden: false };
    const h = harness({ executeConflict: true, statusAnswer: { status: 'pending', receipt_status: 'pending', blockchain_tx_hash: OTHER_HASH } });
    await h.api.executeConfigurationChange('5', '9', { button });
    expect(h.calls.filter((c) => c === 'fetch:execute')).toHaveLength(1);
    expect(h.calls.indexOf('fetch:execute')).toBeLessThan(h.calls.indexOf('fetch:status'));
    expect(h.record.restore).toEqual([]);
    expect(h.record.error).toEqual([]);
    expect(h.record.waitingForRecord).toEqual([true]);
    expect(button.hidden).toBe(true);
  });

  it('shows the other tab\'s confirmed send from the reloaded row after a 409', async () => {
    const button = { hidden: false };
    const h = harness({ executeConflict: true, statusAnswer: { status: 'executed', receipt_status: null, blockchain_tx_hash: OTHER_HASH } });
    await h.api.executeConfigurationChange('5', '9', { button });
    expect(h.record.done).toEqual([expect.objectContaining({
      text: 'Change sent.',
      explorerHref: `https://base-sepolia.blockscout.com/tx/${OTHER_HASH}`,
      shortId: OTHER_HASH,
    })]);
    expect(button.hidden).toBe(true);
    expect(h.record.restore).toEqual([]);
  });

  // 21-25 fix 12: once the wallet may have sent, no copy says nothing changed.
  it('MetaMask\'s wrapped -32603 from the send says "may have sent" and reads the Safe\'s nonce (21-25 fix 12)', async () => {
    const button = { hidden: false };
    const h = harness({ sendThrows: WRAPPED_RPC() });
    await h.api.executeConfigurationChange('5', '9', { button });
    expect(h.record.setState).toContainEqual([2, CHECKING]);
    expect(h.calls).toContain('kit:getNonce');
    expect(h.calls).not.toContain('fetch:execute');
    expect(h.record.error).toEqual([UNCHECKED]);
    expect(h.record.error.join(' ')).not.toMatch(/on our side|nothing was sent|nothing changed/i);
    expect(h.record.restore).toEqual([]);
    expect(button.hidden).toBe(true);
  });

  it('a send that threw after the Safe moved past the change says it was sent or replaced (21-25 fix 12)', async () => {
    const h = harness({ sendThrows: new Error('timeout'), nonceAfter: 5 });
    await h.api.executeConfigurationChange('5', '9', { button: { hidden: false } });
    expect(h.record.error).toEqual([MOVED_ON]);
    expect(h.record.restore).toEqual([]);
  });

  it('a hash, then wait() fails: the hash goes to the site and the page waits (21-25 fix 12)', async () => {
    const button = { hidden: false };
    const h = harness({ waitThrows: true, receiptStatus: 'pending' });
    await h.api.executeConfigurationChange('5', '9', { button });
    expect(h.posted.execute).toEqual({ blockchain_tx_hash: HASH });
    expect(h.record.waitingForRecord).toEqual([true]);
    expect(h.record.error).toEqual([]);
    expect(h.record.restore).toEqual([]);
    expect(button.hidden).toBe(true);
  });

  it('a hash the site couldn\'t record is posted again, never "nothing changed" (21-25 fix 12)', async () => {
    const button = { hidden: false };
    const h = harness({ executeStatus: 500 });
    await h.api.executeConfigurationChange('5', '9', { button });
    expect(h.calls.filter((c) => c === 'fetch:execute')).toHaveLength(2);
    expect(h.record.error).toEqual([UNCHECKED]);
    expect(h.record.restore).toEqual([]);
    expect(button.hidden).toBe(true);
  });

  it('a refusal in the wallet may still say nothing changed, and gives the button back', async () => {
    const button = { hidden: false };
    const h = harness({ rejectSend: true });
    await h.api.executeConfigurationChange('5', '9', { button });
    expect(h.record.error).toEqual(["You didn't confirm it in your wallet. Nothing changed."]);
    expect(h.record.restore).toEqual([true]);
  });

  // 21-25 fix 13: the hash reaches the server first, as fix 10 did for
  // payments. A reload mid-send can't lose it, and the page then shows the
  // waiting state with no second send.
  it('posts the hash the moment the wallet returns it, then waits and asks again (21-25 fix 13)', async () => {
    const button = { hidden: false };
    const h = harness({ receiptStatuses: ['pending', 'confirmed'] });
    await h.api.executeConfigurationChange('5', '9', { button });
    const first = h.calls.indexOf('fetch:execute');
    const second = h.calls.lastIndexOf('fetch:execute');
    expect(h.calls.indexOf('kit:executeTransaction')).toBeLessThan(first);
    expect(first).toBeLessThan(h.calls.indexOf('tx:wait'));
    expect(h.calls.indexOf('tx:wait')).toBeLessThan(second);
    expect(second).toBeLessThan(h.calls.indexOf('panel:done'));
    expect(h.posted.executeAll).toEqual([{ blockchain_tx_hash: HASH }, { blockchain_tx_hash: HASH }]);
    expect(h.record.done).toHaveLength(1);
    expect(button.hidden).toBe(true);
    expect(h.record.restore).toEqual([]);
  });

  it('posts once when the first answer is already final (21-25 fix 13)', async () => {
    const h = harness({ receiptStatuses: ['confirmed'] });
    await h.api.executeConfigurationChange('5', '9', { button: { hidden: false } });
    expect(h.calls.filter((c) => c === 'fetch:execute')).toHaveLength(1);
    expect(h.calls).not.toContain('tx:wait');
    expect(h.record.done).toHaveLength(1);
  });

  it('the hash is stored before wait() fails, and the page waits (21-25 fix 13)', async () => {
    const button = { hidden: false };
    const h = harness({ receiptStatuses: ['pending'], waitThrows: true });
    await h.api.executeConfigurationChange('5', '9', { button });
    expect(h.calls.indexOf('fetch:execute')).toBeLessThan(h.calls.indexOf('tx:wait'));
    expect((h.posted.executeAll as unknown[])[0]).toEqual({ blockchain_tx_hash: HASH });
    expect(h.record.waitingForRecord).toEqual([true]);
    expect(h.record.error).toEqual([]);
    expect(h.record.restore).toEqual([]);
    expect(button.hidden).toBe(true);
  });

  it('keeps the button hidden when the re-check fails after the hash is stored (21-25 fix 13)', async () => {
    const button = { hidden: false };
    const h = harness({ receiptStatuses: ['pending'], executeFailsFrom: 2 });
    await h.api.executeConfigurationChange('5', '9', { button });
    expect(h.calls.filter((c) => c === 'fetch:execute')).toHaveLength(2);
    expect(h.calls.indexOf('fetch:execute')).toBeLessThan(h.calls.indexOf('tx:wait'));
    expect(h.record.waitingForRecord).toEqual([true]);
    expect(h.record.error).toEqual([]);
    expect(h.record.restore).toEqual([]);
    expect(button.hidden).toBe(true);
  });

  it('a first post that fails is posted again, never restoring the button (21-25 fix 13)', async () => {
    const button = { hidden: false };
    const h = harness({ executeFailsFrom: 1 });
    await h.api.executeConfigurationChange('5', '9', { button });
    expect(h.calls.filter((c) => c === 'fetch:execute')).toHaveLength(2);
    expect(h.calls).not.toContain('tx:wait');
    expect(h.record.error).toEqual([UNCHECKED]);
    expect(h.record.restore).toEqual([]);
    expect(button.hidden).toBe(true);
  });

  it('keeps the button hidden after a 409 even when the row can\'t be reloaded', async () => {
    const button = { hidden: false };
    const h = harness({ executeConflict: true, statusAnswer: 500 });
    await h.api.executeConfigurationChange('5', '9', { button });
    expect(h.calls).toContain('fetch:status');
    expect(h.record.restore).toEqual([]);
    expect(h.record.error).toEqual([]);
    expect(h.record.waitingForRecord).toEqual([true]);
    expect(button.hidden).toBe(true);
  });
});

describe.skipIf(!present)('Approval settings form and source rules (21-24, D-12)', () => {
  it('shows "Saving…" on the primary once the browser has read the form (21-25 fix 14)', () => {
    const h = harness();
    const button = saveButton();
    const listeners: Record<string, (e: unknown) => void> = {};
    const classes = new Set<string>();
    const attrs: Record<string, string> = {};
    const form = {
      classList: { add: (c: string) => classes.add(c) },
      addEventListener: (type: string, fn: (e: unknown) => void) => { listeners[type] = fn; },
      querySelector: (sel: string) => (sel.includes('data-safe-inflight-text') ? button : null),
      hasAttribute: (n: string) => n in attrs,
      setAttribute: (n: string, v: string) => { attrs[n] = v; },
      removeAttribute: (n: string) => { delete attrs[n]; },
    };
    const event = (submitter: unknown) => ({ currentTarget: form, submitter, preventDefault() {} });
    h.ctx.Drupal.behaviors.safeConfigurationManager.attach({ querySelectorAll: (sel: string) => (sel.includes('safe-account-manage-form') ? [form] : []) });
    expect(listeners.submit).toBeTypeOf('function');
    // Another submit button (an approver row's) isn't the save.
    listeners.submit(event({ other: true }));
    h.timers.forEach((fn) => fn());
    expect(h.record.inFlight).toEqual([]);
    listeners.submit(event(button));
    // Not inside the submit event: a disabled button isn't posted, and
    // Drupal would take the form's first button ("Remove …") instead.
    expect(h.record.inFlight).toEqual([]);
    h.timers.forEach((fn) => fn());
    expect(h.record.inFlight).toEqual(['Saving…']);
    // Nothing reaches a wallet or an endpoint.
    expect(h.walletCalls()).toEqual([]);
    expect(h.calls.filter((c) => c.startsWith('fetch:'))).toEqual([]);
  });

  it('exports no create-transaction call (D-12)', () => {
    const h = harness();
    expect(Object.keys(h.api).sort()).toEqual([
      'createAddOwnerTransaction',
      'createChangeThresholdTransaction',
      'createRemoveOwnerTransaction',
      'createSwapOwnerTransaction',
      'executeConfigurationChange',
    ]);
  });

  it('finds the setup button by its class, not the old "Deploy Safe" label', () => {
    // Approval settings' setup-not-finished button reads "Finish setting up
    // the shared account" (or the group fund); safe-deployment.js must still
    // attach to it.
    const src = readFileSync(resolve(JS, 'safe-deployment.js'), 'utf8');
    expect(src).toContain("querySelectorAll('.safe-deploy-button')");
    expect(src).not.toContain("value='Deploy Safe'");
  });

  it('has no dead endpoint, alert(), innerHTML, network literal or loading toast', () => {
    const src = readFileSync(resolve(JS, 'safe-configuration-manager.js'), 'utf8');
    expect(src).not.toMatch(/configuration\/create-transaction|createConfigurationTransaction|alert\(|innerHTML|[Ss]epolia|11155111|etherscan|Drupal\.message\(/);
  });
});

describe.skipIf(!present)('Send a configuration change: the server is asked before the wallet (SSA 25-29 pre-check)', () => {
  const BUSY = 'Another payment from this fund is still being sent. Wait for it to be confirmed, then send this one.';

  it('posts {"check": true} to /execute right before the wallet sends, then goes on', async () => {
    const h = harness();
    await h.api.executeConfigurationChange('5', '9');
    expect(h.posted.precheckAll).toEqual([{ check: true }]);
    const order = ['fetch:data', 'sdk:isCorrectNetwork', 'fetch:precheck', 'kit:executeTransaction', 'fetch:execute', 'panel:done'].map((c) => h.calls.indexOf(c));
    expect(order.every((i) => i >= 0)).toBe(true);
    expect([...order].sort((a, b) => a - b)).toEqual(order);
    // The pre-check saves nothing: the one real execute post is the hash.
    expect(h.posted.executeAll).toEqual([{ blockchain_tx_hash: HASH }]);
  });

  it('a busy 409 stops the send: no wallet send, no hash post, the server\'s sentence shown, button restored', async () => {
    const button = { hidden: false };
    const h = harness({ precheckAnswer: { status: 409, body: { error: BUSY } } });
    await h.api.executeConfigurationChange('5', '9', { button });
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
    const button = { hidden: false };
    const h = harness({ precheckAnswer });
    await h.api.executeConfigurationChange('5', '9', { button });
    expect(h.calls).not.toContain('kit:executeTransaction');
    expect(h.calls).not.toContain('fetch:execute');
    expect(h.record.error).toHaveLength(1);
    expect(h.record.done).toEqual([]);
    expect(h.record.restore).toEqual([true]);
  });
});
