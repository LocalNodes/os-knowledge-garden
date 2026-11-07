/**
 * Phase 21 plan 16: the pure logic of the Safe SDK manager and the wallet
 * error mapping, run in a node `vm` with stub Drupal/once/drupalSettings/
 * window globals (no jsdom in the project; DOM behaviour is checked in the
 * Playwright run, 21-28).
 *
 * The D-33 deploy cases also load the real protocol-kit bundle the module
 * ships, against a fake EIP-1193 wallet, so they prove which factory the
 * deployment transaction targets.
 *
 * Skips when the gitignored drupal.org clone is absent.
 */
import { beforeAll, describe, expect, it } from 'vitest';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import vm from 'node:vm';

// 21-25 fix 12: once the wallet may have sent, no copy says nothing was sent.
const CHECKING = 'Your wallet may have sent this. Checking…';
const UNCHECKED = "Your wallet may have sent this, and we couldn't check it yet. Look at your wallet's activity before you try again.";
const MOVED_ON = 'This was already sent, or another action replaced it. Reload this page to see which.';
const ON_RECORD = "The account is on the public record, but this site hasn't saved it yet. Reload this page, and if it still isn't set up, tell whoever looks after your community's site.";
const WALLET_RPC = 'Your wallet ran into a problem. Check your wallet, then try again.';
/** MetaMask's -32603 "Failed to fetch" as ethers 6.16 wraps it (21-45 RCA). */
const WRAPPED_RPC = () => Object.assign(new Error('could not coalesce error (error={ "code": -32603, "message": "Failed to fetch" }, code=UNKNOWN_ERROR, version=6.16.0)'), { code: 'UNKNOWN_ERROR', error: { code: -32603, message: 'Failed to fetch' } });

const ROOT = resolve(__dirname, '../..');
const SSA = resolve(ROOT, 'repos/safe_smart_accounts');
const JS = resolve(SSA, 'js');
const BUNDLE = resolve(SSA, 'libraries/safe-sdk/protocol-kit.bundle.js');
const present = existsSync(JS);

// Safe 1.4.1 (safe-global/safe-deployments; CanonicalDeployments, 21-13).
const FACTORY_141 = '0x4e1DCf7AD4e460CfD30791CCC4F9c8a4f820ec67';
const L2_SINGLETON_141 = '0x29fcB43b46531BcA003ddC8FCB67FFE91900C762';
const FALLBACK_141 = '0xfd0732Dc9E303f09fCEf3a7388Ad10A83459Ec99';
const OWNER = '0x00000000000000000000000000000000000000a1';
const HASH = '0x' + 'ab'.repeat(32);

/** The deploy data PHP sends for a shipped 1.4.1 entry (JsSettingsTest). */
function deployment141(overrides: Record<string, unknown> = {}) {
  return {
    safeVersion: '1.4.1',
    safeProxyFactory: FACTORY_141,
    safeSingleton: L2_SINGLETON_141,
    fallbackHandler: FALLBACK_141,
    canonical: { safeProxyFactory: [FACTORY_141], safeSingleton: [L2_SINGLETON_141] },
    ...overrides,
  };
}

/** drupalSettings.safeSmartAccounts.networks as the hook builds it. */
function networks(baseDeployment: Record<string, unknown> = deployment141()) {
  return {
    ethereum_sepolia: {
      chainId: 11155111,
      label: 'Ethereum Sepolia',
      isTestnet: true,
      nativeSymbol: 'ETH',
      explorer: {
        name: 'Blockscout',
        addressUrl: 'https://eth-sepolia.blockscout.com/address/{address}',
        txUrl: 'https://eth-sepolia.blockscout.com/tx/{hash}',
      },
      deployment: deployment141(),
    },
    base_sepolia: {
      chainId: 84532,
      label: 'Base Sepolia',
      isTestnet: true,
      nativeSymbol: 'ETH',
      explorer: {
        name: 'Blockscout',
        addressUrl: 'https://base-sepolia.blockscout.com/address/{address}',
        txUrl: 'https://base-sepolia.blockscout.com/tx/{hash}',
      },
      deployment: baseDeployment,
    },
  };
}

/** Drupal.t with @placeholder substitution, like core's. */
function t(str: string, args: Record<string, string> = {}) {
  return Object.entries(args).reduce((s, [k, v]) => s.split(k).join(String(v)), str);
}

type Ctx = Record<string, any>;

/** A vm context with stub Drupal globals; loads the named module files. */
function load(files: string[], opts: { settings?: Ctx; ctx?: Ctx } = {}): Ctx {
  const errors: unknown[][] = [];
  const ctx: Ctx = opts.ctx ?? {};
  Object.assign(ctx, {
    console: { log() {}, warn() {}, error: (...a: unknown[]) => errors.push(a) },
    Drupal: ctx.Drupal ?? { behaviors: {}, t },
    drupalSettings: { safeSmartAccounts: { networks: networks() }, ...(opts.settings ?? {}) },
    once: () => [],
  });
  ctx.errors = errors;
  if (!ctx.window) ctx.window = ctx;
  if (!vm.isContext(ctx)) vm.createContext(ctx);
  for (const f of files) vm.runInContext(readFileSync(resolve(JS, f), 'utf8'), ctx);
  return ctx;
}

/** A fake ethers BrowserProvider on a chain. */
function providerOn(chainId: bigint) {
  return { getNetwork: async () => ({ chainId }) };
}

describe.skipIf(!present)('safe-sdk-manager.js: networks from the registry (CHAIN-04, U22, U25)', () => {
  it('isCorrectNetwork matches the wallet chain with the Safe network', async () => {
    const { Drupal } = load(['safe-sdk-manager.js']);
    expect(await Drupal.safeSDK.isCorrectNetwork(providerOn(84532n), 'base_sepolia')).toBe(true);
  });

  it('isCorrectNetwork is false for another registry network', async () => {
    const { Drupal } = load(['safe-sdk-manager.js']);
    expect(await Drupal.safeSDK.isCorrectNetwork(providerOn(84532n), 'ethereum_sepolia')).toBe(false);
  });

  it('isCorrectNetwork is false for an unknown or missing id (no default network)', async () => {
    const { Drupal } = load(['safe-sdk-manager.js']);
    expect(await Drupal.safeSDK.isCorrectNetwork(providerOn(84532n), 'sepolia')).toBe(false);
    expect(await Drupal.safeSDK.isCorrectNetwork(providerOn(11155111n), undefined)).toBe(false);
    expect(await Drupal.safeSDK.isCorrectNetwork(providerOn(84532n), 'toString')).toBe(false);
  });

  it('explorerTxUrl substitutes a 0x+64 hex hash and refuses anything else', () => {
    const { Drupal } = load(['safe-sdk-manager.js']);
    expect(Drupal.safeSDK.explorerTxUrl('base_sepolia', HASH)).toBe(`https://base-sepolia.blockscout.com/tx/${HASH}`);
    expect(Drupal.safeSDK.explorerTxUrl('base_sepolia', '0x1234')).toBeNull();
    expect(Drupal.safeSDK.explorerTxUrl('base_sepolia', HASH + '/../evil')).toBeNull();
    expect(Drupal.safeSDK.explorerTxUrl('nowhere', HASH)).toBeNull();
  });

  it('explorerAddressUrl, network and networkLabel read the registry entry', () => {
    const { Drupal } = load(['safe-sdk-manager.js']);
    expect(Drupal.safeSDK.explorerAddressUrl('ethereum_sepolia', OWNER)).toBe(`https://eth-sepolia.blockscout.com/address/${OWNER}`);
    expect(Drupal.safeSDK.explorerAddressUrl('ethereum_sepolia', 'not-an-address')).toBeNull();
    expect(Drupal.safeSDK.network('base_sepolia').chainId).toBe(84532);
    expect(Drupal.safeSDK.network('nowhere')).toBeNull();
    expect(Drupal.safeSDK.networkLabel('base_sepolia')).toBe('Base Sepolia');
    expect(Drupal.safeSDK.networkLabel('nowhere')).toBe('');
  });
});

/**
 * A fake EIP-1193 wallet for the real protocol-kit bundle. eth_getCode
 * returns code for the contracts protocol-kit probes before it builds a
 * deploy (MultiSend, MultiSendCallOnly, factory, singleton, fallback
 * handler) and '0x' for anything else, so the predicted Safe reads as not
 * deployed yet. eth_call answers proxyCreationCode() with one byte.
 */
function fakeWallet(chainId: number, deployed: string[]) {
  const calls: string[] = [];
  const code = new Set(deployed.map((a) => a.toLowerCase()));
  const ethereum = {
    request: async ({ method, params }: { method: string; params?: any[] }) => {
      calls.push(method);
      if (method === 'eth_chainId') return '0x' + chainId.toString(16);
      if (method === 'eth_getCode') return code.has(String(params?.[0]).toLowerCase()) ? '0x6080' : '0x';
      if (method === 'eth_call') return '0x' + '20'.padStart(64, '0') + '1'.padStart(64, '0') + 'aa'.padEnd(64, '0');
      if (method === 'eth_accounts' || method === 'eth_requestAccounts') return [OWNER];
      throw new Error(`unexpected ${method}`);
    },
  };
  const provider = { getSigner: async () => { calls.push('getSigner'); return { getAddress: async () => OWNER }; } };
  return { ethereum, provider, calls };
}

// MultiSend and MultiSendCallOnly 1.4.1, which protocol-kit checks on init.
const MULTISEND_141 = ['0x38869bf66a61cF6bDB996A6aE40D5853Fd43B526', '0x9641d764fc13c8B624c04430C7356C1C7C8102e2'];
const DEPLOYED = [...MULTISEND_141, FACTORY_141, L2_SINGLETON_141, FALLBACK_141];
const CONFIG = { network: 'base_sepolia', signers: [OWNER], threshold: 1, salt_nonce: '7' };

describe.skipIf(!present)('safe-sdk-manager.js: the registry drives new deploys (D-33)', () => {
  let bundle = '';
  beforeAll(() => { bundle = readFileSync(BUNDLE, 'utf8'); });

  /** A context with the real protocol-kit bundle and a fake wallet. */
  function withBundle(settingsNetworks: Ctx, chainId: number, deployed: string[]) {
    const wallet = fakeWallet(chainId, deployed);
    const ctx: Ctx = {
      setTimeout, clearTimeout, queueMicrotask, TextEncoder, TextDecoder, URL,
      crypto: globalThis.crypto,
      fetch: async () => { throw new Error('no network in tests'); },
      ethereum: wallet.ethereum,
    };
    ctx.window = ctx; ctx.self = ctx; ctx.globalThis = ctx;
    vm.createContext(ctx);
    vm.runInContext(bundle, ctx);
    load(['safe-sdk-manager.js'], { ctx, settings: { safeSmartAccounts: { networks: settingsNetworks } } });
    return { ctx, ...wallet };
  }

  it("the deployment transaction's to is the entry's safe_proxy_factory, with safeVersion 1.4.1", async () => {
    const { ctx, provider } = withBundle(networks(), 84532, DEPLOYED);
    const kit = await ctx.Drupal.safeSDK.initForDeployment(CONFIG, provider);
    expect(kit.getContractVersion()).toBe('1.4.1');
    const tx = await kit.createSafeDeploymentTransaction();
    expect(tx.to.toLowerCase()).toBe(FACTORY_141.toLowerCase());
  });

  it('the entry, not protocol-kit defaults, picks the factory (contractNetworks reaches the kit)', async () => {
    // A test-only canonical table naming another factory: the transaction
    // must follow it, which only happens through contractNetworks.
    const other = '0x00000000000000000000000000000000000fac70';
    const net = networks(deployment141({ safeProxyFactory: other, canonical: { safeProxyFactory: [other], safeSingleton: [L2_SINGLETON_141] } }));
    const { ctx, provider } = withBundle(net, 84532, [...DEPLOYED, other]);
    const kit = await ctx.Drupal.safeSDK.initForDeployment(CONFIG, provider);
    const tx = await kit.createSafeDeploymentTransaction();
    expect(tx.to.toLowerCase()).toBe(other);
  });

  it('deploymentConfig builds safeVersion and contractNetworks keyed by chain id', () => {
    const { Drupal } = load(['safe-sdk-manager.js']);
    const cfg = Drupal.safeSDK.deploymentConfig('base_sepolia');
    expect(JSON.parse(JSON.stringify(cfg))).toEqual({
      safeVersion: '1.4.1',
      contractNetworks: {
        84532: {
          safeProxyFactoryAddress: FACTORY_141,
          safeSingletonAddress: L2_SINGLETON_141,
          fallbackHandlerAddress: FALLBACK_141,
        },
      },
    });
  });

  const REFUSALS: [string, Record<string, unknown>][] = [
    ['no safe_version', { safeVersion: '' }],
    ['an empty factory', { safeProxyFactory: '' }],
    ['a factory not canonical for the version', { safeProxyFactory: '0xC22834581EbC8527d974F8a1c97E1bEA4EF910BC' }],
    ['a singleton not canonical for the version', { safeSingleton: '0xfb1bffC9d739B8D520DaF37dF666da4C687191EA' }],
    ['no deployment data at all', { __drop: true }],
  ];
  for (const [name, change] of REFUSALS) {
    it(`refuses a deploy with ${name}, before any wallet prompt, in plain language`, async () => {
      const dep = change.__drop ? undefined : deployment141(change);
      const net: Ctx = networks(dep as Record<string, unknown>);
      if (change.__drop) delete net.base_sepolia.deployment;
      const { ctx, provider, calls } = withBundle(net, 84532, DEPLOYED);
      let thrown: any = null;
      try {
        await ctx.Drupal.safeSDK.initForDeployment(CONFIG, provider);
      } catch (e) {
        thrown = e;
      }
      expect(thrown, 'initForDeployment must refuse').not.toBeNull();
      expect(thrown.code).toBe(ctx.Drupal.safeSDK.NETWORK_NOT_READY);
      expect(thrown.message).toBe("This network isn't ready for new accounts on this site, so nothing was sent. An administrator can check its settings.");
      // Nothing reached the wallet: no signer request, no RPC.
      expect(calls).toEqual([]);
    });
  }

  it('refuses an unknown network the same way', () => {
    const { Drupal } = load(['safe-sdk-manager.js']);
    expect(() => Drupal.safeSDK.deploymentConfig('nowhere')).toThrow(/isn't ready for new accounts/);
    expect(() => Drupal.safeSDK.deploymentConfig(undefined)).toThrow(/isn't ready for new accounts/);
  });

  it('an existing Safe initialises version-agnostic: no safeVersion, no contractNetworks', async () => {
    const ctx: Ctx = {};
    const seen: Ctx[] = [];
    ctx.Safe = { init: async (o: Ctx) => { seen.push(o); return { getThreshold: async () => 1, getOwners: async () => [OWNER] }; } };
    ctx.ethereum = {};
    load(['safe-sdk-manager.js'], { ctx });
    await ctx.Drupal.safeSDK.init('0x00000000000000000000000000000000000005af', { getSigner: async () => ({ getAddress: async () => OWNER }) });
    expect(seen).toHaveLength(1);
    expect(Object.keys(seen[0]).sort()).toEqual(['provider', 'safeAddress', 'signer']);
  });
});

describe.skipIf(!present)('wallet-errors.js: plain-language wallet errors (U21, U26)', () => {
  /** walletErrors with a wallet present, or none. */
  function errors(opts: { wallet?: boolean } = {}) {
    const ctx: Ctx = {};
    if (opts.wallet !== false) ctx.ethereum = { request: async () => [] };
    return load(['wallet-errors.js'], { ctx });
  }

  it('classifies a refused request (4001 or ACTION_REJECTED, also wrapped by ethers) as rejected', () => {
    const w = errors().Drupal.safeSmartAccounts.walletErrors;
    expect(w.classify({ code: 4001 })).toBe('rejected');
    expect(w.classify({ code: 'ACTION_REJECTED' })).toBe('rejected');
    expect(w.classify({ code: 'UNKNOWN_ERROR', info: { error: { code: 4001, message: 'User rejected the request.' } } })).toBe('rejected');
    expect(w.classify({ error: { code: 4001 } })).toBe('rejected');
  });

  it('classifies any error with no wallet in the browser as no_wallet', () => {
    const w = errors({ wallet: false }).Drupal.safeSmartAccounts.walletErrors;
    expect(w.classify(new Error('no ethereum'))).toBe('no_wallet');
  });

  it('classifies NETWORK_ERROR and the wrong-network marker as wrong_network', () => {
    const { Drupal } = errors();
    const w = Drupal.safeSmartAccounts.walletErrors;
    expect(w.classify({ code: 'NETWORK_ERROR' })).toBe('wrong_network');
    expect(w.classify({ code: w.WRONG_NETWORK })).toBe('wrong_network');
    expect(w.WRONG_NETWORK).toBe('SSA_WRONG_NETWORK');
  });

  it('classifies a fee shortfall as insufficient_funds', () => {
    const w = errors().Drupal.safeSmartAccounts.walletErrors;
    expect(w.classify({ code: -32000, message: 'insufficient funds for gas * price + value' })).toBe('insufficient_funds');
    expect(w.classify({ code: 'INSUFFICIENT_FUNDS' })).toBe('insufficient_funds');
  });

  it('classifies the not-approver and network-not-ready markers', () => {
    const w = errors().Drupal.safeSmartAccounts.walletErrors;
    expect(w.classify({ code: w.NOT_APPROVER })).toBe('not_approver');
    expect(w.classify({ code: w.NETWORK_NOT_READY })).toBe('network_not_ready');
    // The SDK manager throws the same code (D-33 refusal).
    expect(w.NETWORK_NOT_READY).toBe('SSA_NETWORK_NOT_READY');
  });

  it('classifies anything else as unknown', () => {
    const w = errors().Drupal.safeSmartAccounts.walletErrors;
    expect(w.classify(new Error('execution reverted: GS026'))).toBe('unknown');
    expect(w.classify(null)).toBe('unknown');
    expect(w.classify('boom')).toBe('unknown');
    // ethers' UNKNOWN_ERROR with no wallet or RPC code inside stays unknown.
    expect(w.classify({ code: 'UNKNOWN_ERROR', message: 'could not coalesce error' })).toBe('unknown');
  });

  it('classifies a wallet-internal or RPC error as wallet_rpc, also wrapped by ethers (21-25 fix 12)', () => {
    const w = errors().Drupal.safeSmartAccounts.walletErrors;
    expect(w.classify({ code: -32603, message: 'Failed to fetch' })).toBe('wallet_rpc');
    expect(w.classify({ code: -32000, message: 'nonce too low' })).toBe('wallet_rpc');
    expect(w.classify({ code: -32099, message: 'whatever' })).toBe('wallet_rpc');
    // MetaMask's -32603 as ethers 6.16 wraps it (21-45 RCA).
    expect(w.classify(WRAPPED_RPC())).toBe('wallet_rpc');
    // Controls: a fee shortfall and a refusal keep their kinds.
    expect(w.classify({ code: -32000, message: 'insufficient funds for gas * price + value' })).toBe('insufficient_funds');
    expect(w.classify({ code: 4001, message: 'User rejected the request.' })).toBe('rejected');
    expect(w.classify({ code: -31999 })).toBe('unknown');
  });

  it('once the send started, only a refusal or a fee shortfall may say nothing changed (21-25 fix 12)', () => {
    const w = errors().Drupal.safeSmartAccounts.walletErrors;
    for (const flow of ['setup', 'send']) {
      for (const kind of ['unknown', 'wallet_rpc', 'wrong_network', 'no_wallet', 'not_approver', 'no-such-kind']) {
        const text = w.message(kind, { flow, sendStarted: true, networkLabel: 'Base Sepolia', nativeSymbol: 'ETH' });
        expect(text, `${flow} ${kind}`).toBe(UNCHECKED);
        expect(w.mayHaveSent(kind, { sendStarted: true }), kind).toBe(true);
        expect(w.message(kind, { flow, hasSendId: true, networkLabel: 'Base Sepolia', nativeSymbol: 'ETH' }), `${flow} ${kind} with an ID`).toBe(UNCHECKED);
      }
      expect(w.message('rejected', { flow, sendStarted: true })).toBe("You didn't confirm it in your wallet. Nothing changed.");
      expect(w.mayHaveSent('rejected', { sendStarted: true })).toBe(false);
      expect(w.mayHaveSent('insufficient_funds', { sendStarted: true })).toBe(false);
      expect(w.mayHaveSent('unknown', {})).toBe(false);
    }
    expect(w.message('checking', { flow: 'setup' })).toBe(CHECKING);
    expect(w.message('unchecked', { flow: 'send' })).toBe(UNCHECKED);
    expect(w.message('moved_on', { flow: 'send' })).toBe(MOVED_ON);
    expect(w.message('on_record', { flow: 'setup' })).toBe(ON_RECORD);
    expect(w.message('wallet_rpc', { flow: 'setup' })).toBe(WALLET_RPC);
    for (const text of [CHECKING, UNCHECKED, MOVED_ON, ON_RECORD, WALLET_RPC]) {
      expect(text).not.toMatch(/on our side|nothing was sent|nothing changed/i);
    }
  });

  it('refused: setup and approve copy', () => {
    const w = errors().Drupal.safeSmartAccounts.walletErrors;
    expect(w.message('rejected', { flow: 'setup' })).toBe("You didn't confirm it in your wallet. Nothing changed.");
    expect(w.message('rejected', { flow: 'send' })).toBe("You didn't confirm it in your wallet. Nothing changed.");
    expect(w.message('rejected', { flow: 'approve' })).toBe("You didn't confirm it in your wallet, so it isn't approved yet.");
  });

  it('wrong network names the registry label with the flow ending', () => {
    const w = errors().Drupal.safeSmartAccounts.walletErrors;
    expect(w.message('wrong_network', { flow: 'send', networkLabel: 'Base Sepolia' })).toBe('Your wallet is on a different network. Switch it to Base Sepolia, then send it again.');
    expect(w.message('wrong_network', { flow: 'setup', networkLabel: 'Ethereum Sepolia' })).toBe('Your wallet is on a different network. Switch it to Ethereum Sepolia, then finish setting up.');
    expect(w.message('wrong_network', { flow: 'approve', networkLabel: 'Base Sepolia' })).toBe('Your wallet is on a different network. Switch it to Base Sepolia, then approve again.');
  });

  it('not enough for the fee names the native symbol', () => {
    const w = errors().Drupal.safeSmartAccounts.walletErrors;
    expect(w.message('insufficient_funds', { flow: 'setup', nativeSymbol: 'ETH' })).toBe("Your wallet doesn't have enough ETH to pay the network fee. Add some, then finish setting up.");
    expect(w.message('insufficient_funds', { flow: 'send', nativeSymbol: 'ETH' })).toBe("Your wallet doesn't have enough ETH to pay the network fee. Add some, then send it again.");
  });

  it('unknown: send with a send ID, setup without one, approve', () => {
    const w = errors().Drupal.safeSmartAccounts.walletErrors;
    // With a send ID the wallet may have sent it (21-25 fix 12).
    expect(w.message('unknown', { flow: 'send', hasSendId: true })).toBe(UNCHECKED);
    expect(w.message('unknown', { flow: 'setup', hasSendId: false })).toBe('Something went wrong on our side, and nothing was sent.');
    expect(w.message('unknown', { flow: 'approve' })).toBe("Something went wrong on our side, so your approval wasn't saved.");
    expect(w.message('no-such-kind', { flow: 'setup' })).toBe('Something went wrong on our side, and nothing was sent.');
  });

  it('no wallet, not an approver and network not ready copy', () => {
    const w = errors().Drupal.safeSmartAccounts.walletErrors;
    expect(w.message('no_wallet', { flow: 'setup' })).toBe("Your wallet isn't connected in this browser. Connect it, then come back to this page.");
    expect(w.message('not_approver', { flow: 'approve' })).toBe("The account in your wallet isn't one of this group fund's approvers. Switch to the account you use on this site, then approve again.");
    expect(w.message('network_not_ready', { flow: 'setup' })).toBe("This network isn't ready for new accounts on this site, so nothing was sent. An administrator can check its settings.");
  });

  it('describe() logs the raw error to console.error only and returns the copy', () => {
    const ctx = errors();
    const raw = { code: -32603, message: 'Internal JSON-RPC error: secret-node-detail' };
    const text = ctx.Drupal.safeSmartAccounts.walletErrors.describe(raw, { flow: 'setup', hasSendId: false });
    // A wallet or RPC error before any send: the wallet's problem, not the
    // site's, and no claim about what was sent (21-25 fix 12).
    expect(text).toBe(WALLET_RPC);
    expect(text).not.toContain('secret-node-detail');
    expect(ctx.errors.some((args: unknown[]) => args.includes(raw))).toBe(true);
  });
});
