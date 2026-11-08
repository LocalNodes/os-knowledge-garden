/**
 * Run 1 regression (Phase 21, 2026-11-06): the group treasury deploy and the
 * personal Safe deploy, run in a node `vm` with stub Drupal/ethers/wallet
 * globals and a fake step panel.
 *
 * Bug 1: group_treasury called Drupal.safeDeployment.SafeDeployer, which
 * safe_smart_accounts no longer exports, so every group deploy failed.
 * Bug 2: step 4 was pre-rendered as "Deployment complete!" with a check mark,
 * so a failed deploy still showed success.
 *
 * Both flows moved to the receipt panel (SafeUi::receiptPanel, driven by
 * Drupal.safeSmartAccounts.flowPanel): the personal flow in 21-18, the group
 * flow in 21-22. Their four steps are built from the panel's labels in
 * SafeUi.php and a stub flowPanel moves them: the same assertions hold for
 * both flows.
 *
 * 21-18 (orchestrator ruling A): the personal deploy reads the Safe's
 * registry network from getConfiguration, calls
 * Drupal.safeSDK.deploymentConfig(network) before eth_requestAccounts,
 * checks isCorrectNetwork(provider, network), and a network that can't
 * deploy is refused with zero wallet calls.
 *
 * 21-22 (ruling A): the group deploy follows the same order, with its own
 * copy, and the legacy Drupal.safeDeployment step helpers are gone.
 *
 * Skips when the gitignored drupal.org clones are absent.
 */
import { describe, expect, it, vi } from 'vitest';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import vm from 'node:vm';

const ROOT = resolve(__dirname, '../..');
const SSA = resolve(ROOT, 'repos/safe_smart_accounts');
const GT = resolve(ROOT, 'repos/group_treasury');
const present = existsSync(SSA) && existsSync(GT);

const OWNER = '0x00000000000000000000000000000000000000a1';
const PREDICTED = '0x00000000000000000000000000000000000005af';
const HASH = '0x' + 'ab'.repeat(32);
// 21-25 fix 12: once the wallet may have sent, no copy says nothing was sent.
const CHECKING = 'Your wallet may have sent this. Checking…';
const UNCHECKED = "Your wallet may have sent this, and we couldn't check it yet. Look at your wallet's activity before you try again.";
const ON_RECORD = "The account is on the public record, but this site hasn't saved it yet. Reload this page, and if it still isn't set up, tell whoever looks after your community's site.";
/** MetaMask's -32603 "Failed to fetch" as ethers 6.16 wraps it (21-45 RCA). */
const WRAPPED_RPC = () => Object.assign(new Error('could not coalesce error (error={ "code": -32603, "message": "Failed to fetch" }, code=UNKNOWN_ERROR, version=6.16.0)'), { code: 'UNKNOWN_ERROR', error: { code: -32603, message: 'Failed to fetch' } });
const NETWORK = 'base_sepolia';

type Step = {
  id: string;
  status: string;
  icon: { textContent: string };
  text: { textContent: string };
  details: { innerHTML: string; textContent?: string };
  setAttribute(n: string, v: string): void;
  getAttribute(n: string): string | null;
  querySelector(sel: string): unknown;
};

function makeStep(id: string, status: string, icon: string, text: string): Step {
  return {
    id,
    status,
    icon: { textContent: icon },
    text: { textContent: text },
    details: { innerHTML: '' },
    setAttribute(n, v) { if (n === 'data-status') this.status = v; },
    getAttribute(n) { return n === 'data-status' ? this.status : null; },
    querySelector(sel) {
      return sel === '.step-icon' ? this.icon : sel === '.step-text' ? this.text : sel === '.step-details' ? this.details : null;
    },
  };
}

/**
 * Builds the four steps of a flow from the receipt panel its create form
 * renders (SafeUi::panelSteps('receipt')), as the server shows them before
 * the deploy: no icon, waiting.
 */
function stepsFromReceiptPanel(formFile: string = resolve(SSA, 'src/Form/SafeAccountCreateForm.php')): Map<string, Step> {
  const php = readFileSync(resolve(SSA, 'src/Ui/SafeUi.php'), 'utf8');
  const receipt = php.slice(php.indexOf("'receipt' => ["), php.indexOf("'approval' => ["));
  const labels = [...receipt.matchAll(/\[\s*new TranslatableMarkup\('([^']*)'\)/g)].map((m) => m[1]);
  const steps = new Map<string, Step>();
  labels.forEach((label, i) => {
    const s = makeStep(`step-${i + 1}`, 'pending', '', label);
    steps.set(s.id, s);
  });
  expect(steps.size).toBe(4);
  expect(readFileSync(formFile, 'utf8')).toContain("SafeUi::receiptPanel('receipt'");
  return steps;
}

type Opts = {
  sendFails?: boolean;
  receiptStatus?: string;
  notReady?: boolean;
  wrongNetwork?: boolean;
  /** sendTransaction throws this after the wallet was asked (21-25 fix 12). */
  sendThrows?: unknown;
  /** getCode's answer once the send started (default '0x'). */
  codeAfterSend?: string;
  /** The hash comes back, then wait() throws. */
  waitThrows?: boolean;
  /** update-deployment answers this HTTP status (default 200). */
  updateStatus?: number;
  /** The configuration read fails (before the wallet is asked). */
  configFails?: boolean;
  /** update-deployment's receipt_status per call, in order; the last repeats. */
  receiptStatuses?: string[];
  /**
   * The page gets the test realm's setTimeout, clearTimeout and Date, so
   * vi.useFakeTimers() drives group_treasury's re-check schedule (4 s, then
   * 8 s, within 5 minutes; 21-pending-repoll). Default: a setTimeout stub
   * that never fires.
   */
  realTimers?: boolean;
  /** getCode's answer before any send (default '0x'). */
  codeBeforeSend?: string;
};

/**
 * A stub of Drupal.safeSmartAccounts.flowPanel that records every call and
 * moves the four steps the way the receipt panel does: state n means the
 * set-up was saved (step 1) and panel step n is current (step n + 1 in
 * progress, the earlier ones completed); done() completes every step;
 * error() marks the step in progress as failed.
 */
function stubFlowPanel(steps: Map<string, Step>, calls: string[]) {
  const record: Record<string, unknown[]> = { setState: [], help: [], done: [], waitingForRecord: [], error: [], inFlight: [], restore: [] };
  const step = (n: number) => steps.get(`step-${n}`)!;
  const panel = {
    setState(n: number, o?: { help?: string }) {
      calls.push(`panel:setState:${n}`);
      record.setState.push(n);
      if (o?.help) record.help.push(o.help);
      step(1).status = 'completed';
      for (let i = 2; i <= 4; i++) {
        step(i).status = i <= n ? 'completed' : i === n + 1 ? 'in-progress' : 'pending';
      }
    },
    done(result: unknown) {
      calls.push('panel:done');
      record.done.push(result);
      for (const s of steps.values()) s.status = 'completed';
    },
    waitingForRecord() {
      calls.push('panel:waitingForRecord');
      record.waitingForRecord.push(true);
    },
    error(text: string) {
      calls.push('panel:error');
      record.error.push(text);
      const current = [...steps.values()].find((s) => s.status === 'in-progress') ?? step(2);
      current.status = 'error';
    },
    panel: { querySelector: () => null, appendChild: () => undefined },
  };
  return {
    record,
    api: {
      mount(_container: unknown, options: unknown) {
        calls.push('panel:mount');
        record.mount = [options];
        return panel;
      },
      inFlight(_button: unknown, text: string) { record.inFlight.push(text); },
      restore() { record.restore.push(true); },
    },
  };
}

/** Drupal.t with @placeholder substitution, like core's. */
function t(str: string, args: Record<string, string> = {}) {
  return Object.entries(args).reduce((s, [k, v]) => s.split(k).join(String(v)), str);
}

/** Loads SSA's deploy JS (+ group JS) and returns a runner for one deploy. */
function harness(steps: Map<string, Step>, opts: Opts = {}) {
  const handlers: Record<string, (e: unknown) => Promise<void>> = {};
  const sent: unknown[] = [];
  const calls: string[] = [];
  const posted: unknown[] = [];
  const signer = {
    getAddress: async () => OWNER,
    sendTransaction: async (tx: unknown) => {
      calls.push('wallet:sendTransaction');
      sent.push(tx);
      if (opts.sendFails) throw Object.assign(new Error('user rejected transaction'), { code: 'ACTION_REJECTED' });
      if (opts.sendThrows) throw opts.sendThrows;
      return { hash: HASH, wait: async () => {
        calls.push('tx:wait');
        if (opts.waitThrows) throw new Error('could not coalesce error');
        return { logs: [], status: 1 };
      } };
    },
  };
  class BrowserProvider {
    async getNetwork() { calls.push('wallet:getNetwork'); return { chainId: 84532n }; }
    async getSigner() { calls.push('wallet:getSigner'); return signer; }
    async getCode() { calls.push('wallet:getCode'); return sent.length > 0 ? (opts.codeAfterSend ?? '0x') : (opts.codeBeforeSend ?? '0x'); }
  }
  class Interface { parseLog() { return null; } }
  const json = (body: unknown, status = 200) => ({ ok: status >= 200 && status < 300, status, json: async () => body, text: async () => 'tok' });
  const ctx: Record<string, unknown> = {
    console: { log() {}, warn() {}, error() {} },
    Drupal: { behaviors: {}, t, announce() {} },
    // core/once, which the group deploy JS loads with (21-22).
    once: () => [],
    drupalSettings: {},
    ethers: { BrowserProvider, Interface, getAddress: (a: string) => a },
    ethereum: {
      request: async ({ method }: { method: string }) => {
        calls.push(`ethereum:${method}`);
        return [OWNER];
      },
    },
    location: { href: '' },
    setTimeout: opts.realTimers ? (fn: () => void, ms: number) => setTimeout(fn, ms) : () => 0,
    clearTimeout: opts.realTimers ? (id: ReturnType<typeof setTimeout>) => clearTimeout(id) : () => undefined,
    ...(opts.realTimers ? { Date } : {}),
    CustomEvent: class { constructor(public type: string, public init: unknown) {} },
    fetch: async (url: string, init?: { body?: string }) => {
      if (url.includes('/configuration')) {
        calls.push('fetch:configuration');
        if (opts.configFails) return json({ error: 'Something went wrong on our side.' }, 500);
        return json({ network: NETWORK, signers: [OWNER], threshold: 1, salt_nonce: '7' });
      }
      if (url.includes('/session/token')) return json({});
      if (url.includes('/update-deployment')) {
        calls.push('fetch:update-deployment');
        posted.push(init?.body ? JSON.parse(init.body) : null);
        if (opts.updateStatus && opts.updateStatus !== 200) return json({ error: 'Something went wrong on our side.' }, opts.updateStatus);
        const sequence = opts.receiptStatuses;
        const seen = calls.filter((c) => c === 'fetch:update-deployment').length;
        const status = sequence ? sequence[Math.min(seen - 1, sequence.length - 1)] : (opts.receiptStatus ?? 'confirmed');
        return json({ success: status !== 'failed', receipt_status: status, safe_address: PREDICTED, status: status === 'confirmed' ? 'active' : 'deploying' });
      }
      throw new Error(`unexpected fetch ${url}`);
    },
    document: {
      getElementById: (id: string) => steps.get(id) ?? null,
      querySelector: () => null,
      querySelectorAll: () => [],
      createElement: () => ({ setAttribute() {}, appendChild() {}, classList: { add() {} }, style: {} }),
      createTextNode: (text: string) => ({ textContent: text }),
      addEventListener: (type: string, fn: (e: unknown) => Promise<void>) => { handlers[type] = fn; },
      dispatchEvent: () => true,
    },
  };
  ctx.window = ctx;
  vm.createContext(ctx);
  // The plain-language wallet error copy (21-16), loaded for real.
  vm.runInContext(readFileSync(resolve(SSA, 'js/wallet-errors.js'), 'utf8'), ctx);
  const flow = stubFlowPanel(steps, calls);
  const drupal = ctx.Drupal as Record<string, any>;
  drupal.safeSmartAccounts.flowPanel = flow.api;
  vm.runInContext(readFileSync(resolve(SSA, 'js/safe-deployment.js'), 'utf8'), ctx);
  // Drupal.safeSDK comes from safe-sdk-manager.js, which wraps the
  // protocol-kit bundle; stub it at the same seam.
  const sdkArgs: Record<string, unknown[]> = { deploymentConfig: [], isCorrectNetwork: [] };
  drupal.safeSDK = {
    NETWORK_NOT_READY: 'SSA_NETWORK_NOT_READY',
    deploymentConfig: (networkId: string) => {
      calls.push('sdk:deploymentConfig');
      sdkArgs.deploymentConfig.push(networkId);
      if (opts.notReady) {
        throw Object.assign(new Error("This network isn't ready for new accounts on this site."), { code: 'SSA_NETWORK_NOT_READY' });
      }
      return { safeVersion: '1.4.1', contractNetworks: {} };
    },
    isCorrectNetwork: async (provider: unknown, networkId?: string) => {
      calls.push('sdk:isCorrectNetwork');
      sdkArgs.isCorrectNetwork.push([provider instanceof BrowserProvider, networkId]);
      return !opts.wrongNetwork;
    },
    network: (id: string) => (id === NETWORK ? { label: 'Base Sepolia', nativeSymbol: 'ETH', explorer: { name: 'Blockscout' } } : null),
    networkLabel: (id: string) => (id === NETWORK ? 'Base Sepolia' : ''),
    explorerAddressUrl: (id: string, address: string) => (id === NETWORK ? `https://base-sepolia.blockscout.com/address/${address}` : null),
    initForDeployment: async () => {
      calls.push('sdk:initForDeployment');
      return {
        getAddress: async () => PREDICTED,
        createSafeDeploymentTransaction: async () => ({ to: '0xfac', data: '0xdead', value: '0' }),
      };
    },
  };
  vm.runInContext(readFileSync(resolve(GT, 'js/treasury-deployment.js'), 'utf8'), ctx);
  return { handlers, sent, ctx, calls, flow: flow.record, sdkArgs, posted };
}

/** No step may look finished (check mark or "complete"/"success") unless it is. */
function expectNoFalseSuccess(steps: Map<string, Step>) {
  for (const s of steps.values()) {
    if (s.status === 'completed') continue;
    expect(s.icon.textContent, `${s.id} icon while ${s.status}`).not.toBe('✅');
    expect(s.text.textContent, `${s.id} text while ${s.status}`).not.toMatch(/complete|success/i);
  }
}

const FLOWS = [
  {
    name: 'group treasury',
    steps: () => stepsFromReceiptPanel(resolve(GT, 'src/Form/TreasuryCreateForm.php')),
    event: 'treasuryCreated',
    detail: { safeAccountId: 5, groupId: 7 },
    redirect: '/group/7/treasury',
  },
  {
    name: 'personal Safe',
    steps: () => stepsFromReceiptPanel(),
    event: 'safeAccountCreated',
    detail: { safeAccountId: 5, userId: 2 },
    redirect: '/user/2/safe-accounts/5',
  },
];

describe.skipIf(!present)('Safe deploy flows (Run 1 regression)', () => {
  for (const f of FLOWS) {
    it(`${f.name}: a successful deploy sends the transaction and finishes step 4`, async () => {
      const steps = f.steps();
      steps.get('step-1')!.status = 'completed';
      const h = harness(steps);
      await h.handlers[f.event]({ detail: f.detail });
      const errors = [...steps.values()].filter((s) => s.status === 'error').map((s) => s.text.textContent);
      expect(errors).toEqual([]);
      expect(h.sent).toEqual([{ to: '0xfac', data: '0xdead', value: '0' }]);
      expect(steps.get('step-4')!.status).toBe('completed');
    });

    it(`${f.name}: before the deploy starts, nothing reads as done`, () => {
      expectNoFalseSuccess(f.steps());
    });

    it(`${f.name}: a failed deploy never shows success`, async () => {
      const steps = f.steps();
      steps.get('step-1')!.status = 'completed';
      const h = harness(steps, { sendFails: true });
      await h.handlers[f.event]({ detail: f.detail });
      expect(steps.get('step-2')!.status).toBe('error');
      expect(steps.get('step-4')!.status).not.toBe('completed');
      expectNoFalseSuccess(steps);
      expect((h.ctx.location as { href: string }).href).toBe('');
    });
  }
});

describe.skipIf(!present)('Personal deploy: the registry network first (21-18, ruling A)', () => {
  const run = async (opts: Opts = {}) => {
    const steps = stepsFromReceiptPanel();
    const h = harness(steps, opts);
    await h.handlers.safeAccountCreated({ detail: { safeAccountId: 5, userId: 2, accountUrl: '/user/2/safe-accounts/5' } });
    return h;
  };

  it('asks deploymentConfig and isCorrectNetwork for the Safe\'s own network before eth_requestAccounts', async () => {
    const h = await run();
    expect(h.sdkArgs.deploymentConfig).toEqual([NETWORK]);
    expect(h.sdkArgs.isCorrectNetwork).toEqual([[true, NETWORK]]);
    const config = h.calls.indexOf('sdk:deploymentConfig');
    const network = h.calls.indexOf('sdk:isCorrectNetwork');
    const connect = h.calls.indexOf('ethereum:eth_requestAccounts');
    expect(h.calls.indexOf('fetch:configuration')).toBeLessThan(config);
    expect(config).toBeGreaterThanOrEqual(0);
    expect(config).toBeLessThan(connect);
    expect(network).toBeLessThan(connect);
    expect(connect).toBeLessThan(h.calls.indexOf('wallet:sendTransaction'));
  });

  it('refuses a network that is not ready with zero wallet calls', async () => {
    const h = await run({ notReady: true });
    expect(h.calls.filter((c) => c.startsWith('ethereum:') || c.startsWith('wallet:'))).toEqual([]);
    expect(h.calls).not.toContain('sdk:isCorrectNetwork');
    expect(h.sent).toEqual([]);
    expect(h.flow.error).toEqual(["This network isn't ready for new accounts on this site, so nothing was sent. An administrator can check its settings."]);
    expect(h.flow.done).toEqual([]);
  });

  it('refuses the wrong network before the connect prompt, naming the Safe\'s network', async () => {
    const h = await run({ wrongNetwork: true });
    expect(h.calls).not.toContain('ethereum:eth_requestAccounts');
    expect(h.sent).toEqual([]);
    expect(h.flow.error).toEqual(['Your wallet is on a different network. Switch it to Base Sepolia, then finish setting up.']);
  });

  it('says Done only when update-deployment returns receipt_status confirmed, with no redirect', async () => {
    const h = await run();
    expect(h.flow.setState).toEqual([1, 2, 3]);
    expect(h.flow.done).toHaveLength(1);
    expect(h.flow.done[0]).toMatchObject({
      text: 'Your shared account is ready.',
      explorerHref: `https://base-sepolia.blockscout.com/address/${PREDICTED}`,
      explorerName: 'Blockscout',
      shortId: PREDICTED,
    });
    expect(h.posted).toEqual([{ safe_address: PREDICTED, deployment_tx_hash: HASH }]);
    expect((h.ctx.location as { href: string }).href).toBe('');
  });

  it('waits for the public record when the receipt is pending', async () => {
    const h = await run({ receiptStatus: 'pending' });
    expect(h.flow.done).toEqual([]);
    expect(h.flow.waitingForRecord).toHaveLength(1);
    expect(h.flow.error).toEqual([]);
  });

  it('reports a failed receipt in plain words', async () => {
    const h = await run({ receiptStatus: 'failed' });
    expect(h.flow.done).toEqual([]);
    expect(h.flow.error).toEqual(["Setting up didn't go through, so no shared account was created. Try setting it up again."]);
  });

  it('maps a refused wallet prompt to plain copy', async () => {
    const h = await run({ sendFails: true });
    expect(h.flow.error).toEqual(["You didn't confirm it in your wallet. Nothing changed."]);
  });
});

describe.skipIf(!present)('Group deploy: the registry network first (21-22, ruling A)', () => {
  const GROUP_FORM = resolve(GT, 'src/Form/TreasuryCreateForm.php');
  const run = async (opts: Opts = {}) => {
    const steps = stepsFromReceiptPanel(GROUP_FORM);
    const h = harness(steps, opts);
    await h.handlers.treasuryCreated({ detail: { safeAccountId: 5, groupId: 7, fundUrl: '/group/7/treasury' } });
    return h;
  };

  it('asks deploymentConfig and isCorrectNetwork for the Safe\'s own network before eth_requestAccounts', async () => {
    const h = await run();
    expect(h.sdkArgs.deploymentConfig).toEqual([NETWORK]);
    expect(h.sdkArgs.isCorrectNetwork).toEqual([[true, NETWORK]]);
    const config = h.calls.indexOf('sdk:deploymentConfig');
    const network = h.calls.indexOf('sdk:isCorrectNetwork');
    const connect = h.calls.indexOf('ethereum:eth_requestAccounts');
    expect(h.calls.indexOf('fetch:configuration')).toBeLessThan(config);
    expect(config).toBeGreaterThanOrEqual(0);
    expect(config).toBeLessThan(network);
    expect(network).toBeLessThan(connect);
    expect(connect).toBeLessThan(h.calls.indexOf('wallet:sendTransaction'));
  });

  it('refuses a network that is not ready with zero wallet calls', async () => {
    const h = await run({ notReady: true });
    expect(h.calls.filter((c) => c.startsWith('ethereum:') || c.startsWith('wallet:'))).toEqual([]);
    expect(h.calls).not.toContain('sdk:isCorrectNetwork');
    expect(h.sent).toEqual([]);
    expect(h.flow.error).toEqual(["This network isn't ready for new accounts on this site, so nothing was sent. An administrator can check its settings."]);
    expect(h.flow.done).toEqual([]);
  });

  it('refuses the wrong network before the connect prompt, naming the Safe\'s network', async () => {
    const h = await run({ wrongNetwork: true });
    expect(h.calls).not.toContain('ethereum:eth_requestAccounts');
    expect(h.sent).toEqual([]);
    expect(h.flow.error).toEqual(['Your wallet is on a different network. Switch it to Base Sepolia, then finish setting up.']);
  });

  it('uses the group panel and says Done only when update-deployment returns receipt_status confirmed, with no redirect', async () => {
    const h = await run();
    expect(h.flow.mount).toEqual([{ kind: 'receipt', heading: 'Setting up the group fund' }]);
    expect(h.flow.setState).toEqual([1, 2, 3]);
    expect(h.flow.done).toHaveLength(1);
    expect(h.flow.done[0]).toMatchObject({
      text: 'The group fund is ready.',
      explorerHref: `https://base-sepolia.blockscout.com/address/${PREDICTED}`,
      explorerName: 'Blockscout',
      shortId: PREDICTED,
      copyLabel: 'Copy ID for the group fund',
    });
    expect(h.flow.error).toEqual([]);
    expect(h.posted).toEqual([{ safe_address: PREDICTED, deployment_tx_hash: HASH }]);
    expect((h.ctx.location as { href: string }).href).toBe('');
  });

  // group_treasury 21-pending-repoll (nightly run 42489172114): a pending receipt is checked
  // again after 4 s, then every 8 s, for up to 5 minutes. Fake timers drive the real schedule.
  const updates = (h: { calls: string[] }) => h.calls.filter((c) => c === 'fetch:update-deployment').length;
  const start = (opts: Opts) => {
    const h = harness(stepsFromReceiptPanel(GROUP_FORM), { ...opts, realTimers: true });
    const finished = h.handlers.treasuryCreated({ detail: { safeAccountId: 5, groupId: 7, fundUrl: '/group/7/treasury' } });
    return { h, finished };
  };

  it('a pending receipt is asked again after 4 s and 8 s more, then a confirmed answer shows Done once', async () => {
    vi.useFakeTimers();
    try {
      const { h, finished } = start({ receiptStatuses: ['pending', 'pending', 'confirmed'] });
      await vi.advanceTimersByTimeAsync(0);
      expect(updates(h)).toBe(1);
      expect(h.flow.setState.at(-1)).toBe(3);
      expect(h.flow.done).toEqual([]);
      expect(h.flow.error).toEqual([]);
      await vi.advanceTimersByTimeAsync(3_999);
      expect(updates(h)).toBe(1);
      await vi.advanceTimersByTimeAsync(1);
      expect(updates(h)).toBe(2);
      expect(h.flow.done).toEqual([]);
      // The next re-check comes 8 s later, not sooner (Codex round 4): none at 7.999 s.
      await vi.advanceTimersByTimeAsync(7_999);
      expect(updates(h)).toBe(2);
      expect(h.flow.done).toEqual([]);
      await vi.advanceTimersByTimeAsync(1);
      expect(updates(h)).toBe(3);
      await finished;
      expect(h.flow.done).toHaveLength(1);
      expect(h.flow.done[0]).toMatchObject({ text: 'The group fund is ready.' });
      expect(h.flow.waitingForRecord).toEqual([]);
      expect(h.flow.error).toEqual([]);
      expect(vi.getTimerCount()).toBe(0);
    }
    finally {
      vi.useRealTimers();
    }
  });

  it('a receipt still pending at the 5-minute limit ends on "waiting", with no error and no timer left', async () => {
    vi.useFakeTimers();
    try {
      const { h, finished } = start({ receiptStatus: 'pending' });
      await vi.advanceTimersByTimeAsync(300_000);
      await finished;
      // The first report, then re-checks at 4 s, 12 s, ... 300 s (4 s + 37 × 8 s).
      expect(updates(h)).toBe(39);
      expect(h.flow.waitingForRecord).toHaveLength(1);
      expect(h.flow.done).toEqual([]);
      expect(h.flow.error).toEqual([]);
      expect(vi.getTimerCount()).toBe(0);
    }
    finally {
      vi.useRealTimers();
    }
  });

  it('reports a failed receipt in plain words', async () => {
    const h = await run({ receiptStatus: 'failed' });
    expect(h.flow.done).toEqual([]);
    expect(h.flow.error).toEqual(["Setting up didn't go through, so no group fund was created. Try setting it up again."]);
  });

  it('maps a refused wallet prompt to plain copy', async () => {
    const h = await run({ sendFails: true });
    expect(h.flow.error).toEqual(["You didn't confirm it in your wallet. Nothing changed."]);
    expect(h.flow.done).toEqual([]);
  });

  it('no longer has the legacy step helpers (21-18 kept them for this flow)', () => {
    const h = harness(stepsFromReceiptPanel(GROUP_FORM));
    const deployment = (h.ctx.Drupal as Record<string, any>).safeDeployment;
    expect(deployment.updateDeploymentStep).toBeUndefined();
    expect(deployment.getCurrentStep).toBeUndefined();
    expect(typeof deployment.runDeploy).toBe('function');
  });
});

describe.skipIf(!present)('Set-up: no "nothing was sent" once the wallet may have sent (21-25 fix 12)', () => {
  const FLOWS12 = [
    { name: 'personal', form: resolve(SSA, 'src/Form/SafeAccountCreateForm.php'), event: 'safeAccountCreated', detail: { safeAccountId: 5, userId: 2, accountUrl: '/user/2/safe-accounts/5' } },
    { name: 'group', form: resolve(GT, 'src/Form/TreasuryCreateForm.php'), event: 'treasuryCreated', detail: { safeAccountId: 5, groupId: 7, fundUrl: '/group/7/treasury' } },
  ];
  const never = (texts: unknown[]) => {
    for (const text of texts) expect(String(text)).not.toMatch(/on our side|nothing was sent|nothing changed|no shared account was created|no group fund was created/i);
  };

  for (const f of FLOWS12) {
    const run = async (opts: Opts) => {
      const h = harness(stepsFromReceiptPanel(f.form), opts);
      await h.handlers[f.event]({ detail: f.detail });
      return h;
    };

    it(`${f.name}: MetaMask's wrapped -32603 from the send says "may have sent", checks the address, keeps the button away`, async () => {
      const h = await run({ sendThrows: WRAPPED_RPC() });
      expect(h.flow.help).toContain(CHECKING);
      expect(h.flow.error).toEqual([UNCHECKED]);
      never(h.flow.error);
      expect(h.calls.filter((c) => c === 'wallet:getCode')).toHaveLength(2);
      expect(h.flow.restore).toEqual([]);
      expect(h.posted).toEqual([]);
    });

    it(`${f.name}: a send that threw but whose account is on the public record says so`, async () => {
      const h = await run({ sendThrows: new Error('timeout'), codeAfterSend: '0x6080' });
      expect(h.flow.error).toEqual([ON_RECORD]);
      never(h.flow.error);
      expect(h.flow.restore).toEqual([]);
    });

    it(`${f.name}: a hash, then wait() fails: the hash goes to the site and the page waits`, async () => {
      // The group flow checks a pending report again for up to 5 minutes (21-pending-repoll);
      // fake timers run that schedule to its end. The personal flow sets no timer.
      vi.useFakeTimers();
      try {
        const h = harness(stepsFromReceiptPanel(f.form), { waitThrows: true, receiptStatus: 'pending', realTimers: true });
        const finished = h.handlers[f.event]({ detail: f.detail });
        await vi.advanceTimersByTimeAsync(300_000);
        await finished;
        const report = { safe_address: PREDICTED, deployment_tx_hash: HASH };
        if (f.name === 'group') {
          // The group flow reports the same hash again on its schedule: the first report plus
          // re-checks at 4 s, 12 s ... 300 s.
          expect(h.posted).toEqual(Array(39).fill(report));
        }
        else {
          // The personal flow (safe_smart_accounts) reports once and doesn't re-check.
          expect(h.posted).toEqual([report]);
        }
        expect(h.flow.waitingForRecord).toHaveLength(1);
        expect(h.flow.error).toEqual([]);
        expect(h.flow.restore).toEqual([]);
        expect(vi.getTimerCount()).toBe(0);
      }
      finally {
        vi.useRealTimers();
      }
    });

    it(`${f.name}: a hash, then wait() fails, then a later confirmed answer shows Done once`, async () => {
      vi.useFakeTimers();
      try {
        const h = harness(stepsFromReceiptPanel(f.form), { waitThrows: true, receiptStatuses: ['pending', 'confirmed'], realTimers: true });
        const finished = h.handlers[f.event]({ detail: f.detail });
        await vi.advanceTimersByTimeAsync(4_000);
        await finished;
        // Only the group flow re-checks; the personal flow (safe_smart_accounts) stays on "waiting".
        expect(h.flow.done).toHaveLength(f.name === 'group' ? 1 : 0);
        expect(h.flow.error).toEqual([]);
        expect(vi.getTimerCount()).toBe(0);
      }
      finally {
        vi.useRealTimers();
      }
    });

    it(`${f.name}: a hash the site couldn't record is checked again, never "nothing was sent"`, async () => {
      const h = await run({ updateStatus: 500 });
      expect(h.posted).toHaveLength(2);
      expect(h.flow.error).toEqual([UNCHECKED]);
      never(h.flow.error);
      expect(h.flow.restore).toEqual([]);
    });

    it(`${f.name}: an account already at the address before any send is on the public record, with no button`, async () => {
      const h = await run({ codeBeforeSend: '0x6080' });
      expect(h.sent).toEqual([]);
      expect(h.flow.error).toEqual([ON_RECORD]);
    });

    it(`${f.name}: a refusal in the wallet may still say nothing changed`, async () => {
      const h = await run({ sendFails: true });
      expect(h.flow.error).toEqual(["You didn't confirm it in your wallet. Nothing changed."]);
    });

    it(`${f.name}: a failure before the wallet is asked may still say nothing was sent`, async () => {
      const h = await run({ configFails: true });
      expect(h.sent).toEqual([]);
      expect(h.flow.error).toEqual(['Something went wrong on our side, and nothing was sent.']);
    });
  }
});
