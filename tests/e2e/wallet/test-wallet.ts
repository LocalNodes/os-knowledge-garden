// Injected EIP-1193 test wallet for the Phase 21 e2e lane (21-41).
//
// The wallet holds no key. It forwards personal_sign, eth_signTypedData_v4 and
// eth_sendTransaction to the suite's local anvil chain (21-39), which signs with its own
// well-known dev accounts. Nothing here ever talks to Sepolia or any real chain.
//
// The safety rule is a set of mechanisms, each proven by tests/e2e/wallet-guard.e2e.ts and a
// planted mutation (gates/e2e-wallet-guard-proof.txt):
//   - the init script defines window.ethereum only in a frame on a .test or loopback host;
//   - the Node-side bridge refuses (4100, reason host) unless both the calling page and the
//     calling frame are on such a host;
//   - before installing, and before every signature or send, the chain must be the loopback
//     chain this suite started: hostUrl on loopback and not remote (checked before any
//     network call), anvil clientVersion, the recorded instanceId and the recorded chain id;
//   - a method allowlist: chain-control methods (anvil_*, evm_*, hardhat_*, debug_*),
//     eth_sign, eth_signTransaction, eth_sendRawTransaction and anything unlisted get 4200;
//   - signatures and sends only for the selected account (4100, reason account);
//   - wallet_switchEthereumChain to any other chain gets 4902.
//
// This module has no side effects at import: playwright.config.ts imports isAllowedTestHost
// from it, and `playwright test --list` loads the config without the global setup.
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import type { BrowserContext, Page } from '@playwright/test';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = join(HERE, '..', '..', '..');
export const FORKS_RUN_FILE = join(REPO_ROOT, 'tests', 'e2e', '.state', 'forks-run.json');
export const DEFAULT_BASE_URL = 'https://fresh3.test';

/** One chain's entry in tests/e2e/.state/forks-run.json (written by fork.sh, 21-39). */
export interface ForkRunEntry {
  chainId: number;
  mode: string;
  block?: number;
  snapshotId?: string;
  hostUrl: string;
  siteUrl?: string;
  container?: string;
  remote?: boolean;
  instanceId: string;
  startedAt?: string;
  network?: string;
}

export type RefusalReason = 'host' | 'fork' | 'chain' | 'account' | 'method';

/** A refusal by the wallet's safety rule. */
export class TestWalletRefused extends Error {
  readonly reason: RefusalReason;

  constructor(reason: RefusalReason, message: string) {
    super(`test wallet refused (${reason}): ${message}`);
    this.name = 'TestWalletRefused';
    this.reason = reason;
  }

  /** The EIP-1193 code a page sees: 4200 for a method, else 4100. */
  get code(): number {
    return this.reason === 'method' ? 4200 : 4100;
  }
}

/** Any other EIP-1193 error (4902, or an error the chain returned). */
class WalletRpcError extends Error {
  readonly code: number;
  readonly data?: unknown;
  readonly reason?: RefusalReason;

  constructor(code: number, message: string, data?: unknown, reason?: RefusalReason) {
    super(message);
    this.name = 'WalletRpcError';
    this.code = code;
    this.data = data;
    this.reason = reason;
  }
}

/**
 * True for a hostname the test wallet may serve: one ending in ".test", or exactly
 * localhost, 127.0.0.1 or ::1 (also the bracketed [::1] that URL parsers report).
 *
 * The init script embeds this function's source, so it must stay self-contained.
 */
export function isAllowedTestHost(hostname: string): boolean {
  const h = String(hostname || '').toLowerCase();
  if (h === 'localhost' || h === '127.0.0.1' || h === '::1' || h === '[::1]') {
    return true;
  }
  return h.length > 5 && h.endsWith('.test');
}

const LOOPBACK = new Set(['127.0.0.1', 'localhost', '::1', '[::1]']);

function hostOf(url: string | undefined | null): string {
  try {
    return new URL(String(url ?? '')).hostname;
  } catch {
    return '';
  }
}

/** The e2e base URL: LN_E2E_BASE_URL, else https://fresh3.test. */
export function e2eBaseUrl(): string {
  return process.env.LN_E2E_BASE_URL || DEFAULT_BASE_URL;
}

/** Reads the run state fork.sh wrote. */
export function readForksRun(): Record<string, ForkRunEntry> {
  let raw: string;
  try {
    raw = readFileSync(FORKS_RUN_FILE, 'utf8');
  } catch {
    return {};
  }
  return JSON.parse(raw) as Record<string, ForkRunEntry>;
}

function forkEntry(network: string): ForkRunEntry {
  const entry = readForksRun()[network];
  if (!entry) {
    throw new TestWalletRefused('fork', `no running suite chain for ${network} in tests/e2e/.state/forks-run.json`);
  }
  return entry;
}

/** The loopback and remote check. It never touches the network. */
function assertLoopback(entry: ForkRunEntry): void {
  if (!LOOPBACK.has(hostOf(entry.hostUrl)) || entry.remote) {
    throw new TestWalletRefused('fork', `not a loopback suite chain: ${entry.hostUrl}${entry.remote ? ' (remote)' : ''}`);
  }
}

async function jsonRpc(
  fetchImpl: typeof fetch,
  url: string,
  method: string,
  params: unknown[] = [],
): Promise<unknown> {
  const response = await fetchImpl(url, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ jsonrpc: '2.0', id: 1, method, params }),
    signal: AbortSignal.timeout(15_000),
  });
  const body = (await response.json()) as { result?: unknown; error?: { code: number; message: string; data?: unknown } };
  if (body.error) {
    throw new WalletRpcError(body.error.code, body.error.message, body.error.data);
  }
  return body.result;
}

/**
 * Proves the chain is the loopback anvil chain this suite started, or throws
 * TestWalletRefused. Order: (1) loopback and not remote, before any network call;
 * (2) anvil clientVersion; (3) the recorded instanceId; (4) the recorded chain id.
 * fetchImpl is a test seam (wallet-guard.e2e.ts); it defaults to the global fetch.
 */
export async function assertSuiteFork(
  network: string,
  entry?: ForkRunEntry,
  opts: { fetchImpl?: typeof fetch } = {},
): Promise<ForkRunEntry> {
  const e = entry ?? forkEntry(network);
  assertLoopback(e);
  const fetchImpl = opts.fetchImpl ?? fetch;

  let meta: { clientVersion?: unknown; instanceId?: unknown };
  try {
    meta = (await jsonRpc(fetchImpl, e.hostUrl, 'anvil_metadata')) as typeof meta;
  } catch (err) {
    throw new TestWalletRefused('fork', `anvil_metadata failed at ${e.hostUrl}: ${(err as Error).message}`);
  }
  if (typeof meta?.clientVersion !== 'string' || !meta.clientVersion.startsWith('anvil')) {
    throw new TestWalletRefused('fork', `not an anvil node: ${String(meta?.clientVersion)}`);
  }
  if (String(meta.instanceId ?? '').toLowerCase() !== String(e.instanceId ?? '').toLowerCase()) {
    throw new TestWalletRefused('fork', `instanceId ${String(meta.instanceId)} isn't the chain this suite started (${e.instanceId})`);
  }

  let chainHex: unknown;
  try {
    chainHex = await jsonRpc(fetchImpl, e.hostUrl, 'eth_chainId');
  } catch (err) {
    throw new TestWalletRefused('chain', `eth_chainId failed: ${(err as Error).message}`);
  }
  if (typeof chainHex !== 'string' || parseInt(chainHex, 16) !== Number(e.chainId)) {
    throw new TestWalletRefused('chain', `chain id ${String(chainHex)} isn't the recorded ${e.chainId}`);
  }
  return e;
}

/** A JSON-RPC call to the running suite chain's hostUrl (Node side). */
export async function forkRpc(network: string, method: string, params: unknown[] = []): Promise<unknown> {
  const entry = forkEntry(network);
  assertLoopback(entry);
  return jsonRpc(fetch, entry.hostUrl, method, params);
}

// --- keccak-256 (for EIP-55 checksums; Node's sha3-256 is NIST SHA-3, not keccak) ---

const KECCAK_RC = [
  0x0000000000000001n, 0x0000000000008082n, 0x800000000000808an, 0x8000000080008000n,
  0x000000000000808bn, 0x0000000080000001n, 0x8000000080008081n, 0x8000000000008009n,
  0x000000000000008an, 0x0000000000000088n, 0x0000000080008009n, 0x000000008000000an,
  0x000000008000808bn, 0x800000000000008bn, 0x8000000000008089n, 0x8000000000008003n,
  0x8000000000008002n, 0x8000000000000080n, 0x000000000000800an, 0x800000008000000an,
  0x8000000080008081n, 0x8000000000008080n, 0x0000000080000001n, 0x8000000080008008n,
];
// Rotation offsets, indexed x + 5y.
const KECCAK_ROT = [0, 1, 62, 28, 27, 36, 44, 6, 55, 20, 3, 10, 43, 25, 39, 41, 45, 15, 21, 8, 18, 2, 61, 56, 14];
const MASK64 = (1n << 64n) - 1n;

function rotl64(v: bigint, n: number): bigint {
  if (n === 0) return v;
  return ((v << BigInt(n)) | (v >> BigInt(64 - n))) & MASK64;
}

function keccakF(a: bigint[]): void {
  const b = new Array<bigint>(25);
  const c = new Array<bigint>(5);
  for (let round = 0; round < 24; round++) {
    for (let x = 0; x < 5; x++) c[x] = a[x] ^ a[x + 5] ^ a[x + 10] ^ a[x + 15] ^ a[x + 20];
    for (let x = 0; x < 5; x++) {
      const d = c[(x + 4) % 5] ^ rotl64(c[(x + 1) % 5], 1);
      for (let y = 0; y < 25; y += 5) a[x + y] ^= d;
    }
    for (let x = 0; x < 5; x++) {
      for (let y = 0; y < 5; y++) {
        b[y + 5 * ((2 * x + 3 * y) % 5)] = rotl64(a[x + 5 * y], KECCAK_ROT[x + 5 * y]);
      }
    }
    for (let y = 0; y < 25; y += 5) {
      for (let x = 0; x < 5; x++) a[x + y] = b[x + y] ^ (~b[((x + 1) % 5) + y] & MASK64 & b[((x + 2) % 5) + y]);
    }
    a[0] ^= KECCAK_RC[round];
  }
}

/** keccak-256 of the bytes, as lowercase hex without 0x. */
export function keccak256Hex(data: Uint8Array): string {
  const rate = 136;
  const padded = new Uint8Array(Math.ceil((data.length + 1) / rate) * rate);
  padded.set(data);
  padded[data.length] ^= 0x01;
  padded[padded.length - 1] ^= 0x80;
  const state = new Array<bigint>(25).fill(0n);
  for (let off = 0; off < padded.length; off += rate) {
    for (let i = 0; i < rate / 8; i++) {
      let lane = 0n;
      for (let k = 7; k >= 0; k--) lane = (lane << 8n) | BigInt(padded[off + i * 8 + k]);
      state[i] ^= lane;
    }
    keccakF(state);
  }
  let out = '';
  for (let i = 0; i < 4; i++) {
    let lane = state[i];
    for (let k = 0; k < 8; k++) {
      out += Number(lane & 0xffn).toString(16).padStart(2, '0');
      lane >>= 8n;
    }
  }
  return out;
}

let checksumSelfChecked = false;

/** EIP-55 checksum address. */
export function toChecksumAddress(address: string): string {
  if (!checksumSelfChecked) {
    checksumSelfChecked = true;
    // Anvil's dev account 0 has a well-known checksum: a wrong keccak can't pass.
    if (toChecksumAddress('0xf39fd6e51aad88f6f4ce6ab8827279cfffb92266') !== '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266') {
      checksumSelfChecked = false;
      throw new Error('keccak-256 self-check failed');
    }
  }
  const lower = address.toLowerCase().replace(/^0x/, '');
  if (!/^[0-9a-f]{40}$/.test(lower)) throw new Error(`not an address: ${address}`);
  const hash = keccak256Hex(new TextEncoder().encode(lower));
  let out = '0x';
  for (let i = 0; i < 40; i++) out += parseInt(hash[i], 16) >= 8 ? lower[i].toUpperCase() : lower[i];
  return out;
}

/** The chain's dev accounts (eth_accounts), checksummed. */
export async function devAccounts(network: string): Promise<string[]> {
  const accounts = (await forkRpc(network, 'eth_accounts')) as string[];
  return accounts.map((a) => toChecksumAddress(a));
}

// --- the injected wallet ---

/** Read-only methods forwarded to the chain as they are. */
const READ_METHODS = new Set([
  'eth_blockNumber',
  'eth_call',
  'eth_estimateGas',
  'eth_feeHistory',
  'eth_gasPrice',
  'eth_maxPriorityFeePerGas',
  'eth_getBalance',
  'eth_getBlockByHash',
  'eth_getBlockByNumber',
  'eth_getCode',
  'eth_getLogs',
  'eth_getStorageAt',
  'eth_getTransactionByHash',
  'eth_getTransactionCount',
  'eth_getTransactionReceipt',
  'web3_clientVersion',
]);

/** Marks a bridge error so the init script can rebuild it with its code. */
export const WALLET_ERROR_TAG = 'LN_WALLET_ERROR';

/** Parses a bridge error message: {code, reason?, message, data?}, or null. */
export function parseWalletError(message: string): { code: number; reason?: RefusalReason; message: string; data?: unknown } | null {
  const m = /LN_WALLET_ERROR (\{.*\})/.exec(String(message ?? ''));
  return m ? JSON.parse(m[1]) : null;
}

function bridgeError(err: unknown): Error {
  let payload: { code: number; reason?: RefusalReason; message: string; data?: unknown };
  if (err instanceof TestWalletRefused) {
    payload = { code: err.code, reason: err.reason, message: err.message };
  } else if (err instanceof WalletRpcError) {
    payload = { code: err.code, reason: err.reason, message: err.message, data: err.data };
  } else {
    payload = { code: -32603, message: `test wallet error: ${(err as Error)?.message ?? String(err)}` };
  }
  return new Error(`${WALLET_ERROR_TAG} ${JSON.stringify(payload)}`);
}

function decodeSignPayload(data: unknown): string {
  const s = String(data ?? '');
  if (/^0x([0-9a-fA-F]{2})*$/.test(s)) return Buffer.from(s.slice(2), 'hex').toString('utf8');
  return s;
}

/**
 * The page-side provider. Runs in every frame; it defines window.ethereum only when the
 * frame's own hostname passes the embedded isAllowedTestHost.
 */
function walletInitScript(allowed: (hostname: string) => boolean, cfg: { chainIdHex: string }): void {
  if (!allowed(location.hostname)) return;
  const listeners: Record<string, Array<(...args: unknown[]) => void>> = {};
  const emit = (event: string, ...args: unknown[]) => {
    for (const fn of (listeners[event] || []).slice()) {
      try {
        fn(...args);
      } catch (e) {
        console.error(e);
      }
    }
  };
  let currentChain = cfg.chainIdHex;
  const provider = {
    isLocalNodesTestWallet: true,
    async request(args: { method: string; params?: unknown[] }) {
      const binding = (window as unknown as { __lnTestWallet?: (p: unknown) => Promise<unknown> }).__lnTestWallet;
      if (typeof binding !== 'function') {
        const e = new Error('test wallet bridge missing') as Error & { code?: number };
        e.code = 4900;
        throw e;
      }
      try {
        const result = await binding({ method: args && args.method, params: (args && args.params) || [] });
        if (args && args.method === 'wallet_switchEthereumChain') {
          const now = (await binding({ method: 'eth_chainId', params: [] })) as string;
          if (now !== currentChain) {
            currentChain = now;
            emit('chainChanged', now);
          }
        }
        return result;
      } catch (err) {
        const m = /LN_WALLET_ERROR (\{.*\})/.exec(String((err as Error)?.message ?? ''));
        if (!m) throw err;
        const o = JSON.parse(m[1]);
        const e = new Error(o.message) as Error & { code?: number; data?: unknown; reason?: string };
        e.code = o.code;
        e.reason = o.reason;
        e.data = o.reason ? { reason: o.reason } : o.data;
        throw e;
      }
    },
    on(event: string, fn: (...args: unknown[]) => void) {
      (listeners[event] = listeners[event] || []).push(fn);
      return provider;
    },
    removeListener(event: string, fn: (...args: unknown[]) => void) {
      listeners[event] = (listeners[event] || []).filter((f) => f !== fn);
      return provider;
    },
  };
  Object.defineProperty(window, 'ethereum', { value: provider, configurable: true, enumerable: true, writable: false });
  window.addEventListener('load', () => emit('connect', { chainId: currentChain }));
}

export interface TestWalletHandle {
  address: string;
  chainIdHex: string;
  signedMessages: string[];
  /** Guard test only: pretend the suite started a different chain. */
  overrideExpectedInstanceIdForTest(id: string): void;
}

/**
 * Installs the test wallet in a browser context, for dev account `account` of the suite
 * chain `network`. Refuses unless the chain is the suite's loopback chain and the e2e base
 * URL is on an allowed host.
 */
export async function installTestWallet(
  context: BrowserContext,
  opts: { network: string; account: number },
): Promise<TestWalletHandle> {
  const network = opts.network;
  const expected: ForkRunEntry = { ...(await assertSuiteFork(network)) };
  const baseHost = hostOf(e2eBaseUrl());
  if (!isAllowedTestHost(baseHost)) {
    throw new TestWalletRefused('host', `${baseHost} is not a .test or loopback host`);
  }
  const accounts = await devAccounts(network);
  const address = accounts[opts.account];
  if (!address) {
    throw new Error(`no dev account ${opts.account} on ${network}`);
  }
  const chainIdHex = `0x${Number(expected.chainId).toString(16)}`;
  const signedMessages: string[] = [];
  const forward = (method: string, params: unknown[]) => jsonRpc(fetch, expected.hostUrl, method, params);
  const requireSelected = (who: unknown) => {
    if (String(who ?? '').toLowerCase() !== address.toLowerCase()) {
      throw new TestWalletRefused('account', `${String(who)} isn't the selected account ${address}`);
    }
  };

  await context.exposeBinding('__lnTestWallet', async (source, payload: { method?: unknown; params?: unknown }) => {
    try {
      const pageHost = hostOf(source.page.url());
      const frameHost = hostOf(source.frame.url());
      if (!isAllowedTestHost(pageHost) || !isAllowedTestHost(frameHost)) {
        throw new TestWalletRefused('host', `page ${pageHost || '(none)'} / frame ${frameHost || '(none)'} isn't a .test or loopback host`);
      }
      const method = String(payload?.method ?? '');
      const params = Array.isArray(payload?.params) ? (payload.params as unknown[]) : [];
      switch (method) {
        case 'eth_accounts':
        case 'eth_requestAccounts':
          return [address];
        case 'eth_chainId':
          return chainIdHex;
        case 'net_version':
          return String(expected.chainId);
        case 'wallet_requestPermissions':
        case 'wallet_getPermissions':
          return [{ parentCapability: 'eth_accounts', caveats: [{ type: 'restrictReturnedAccounts', value: [address] }] }];
        case 'wallet_switchEthereumChain': {
          const want = String((params[0] as { chainId?: unknown } | undefined)?.chainId ?? '').toLowerCase();
          if (want === chainIdHex) return null;
          throw new WalletRpcError(4902, `chain ${want} isn't a running suite chain`, undefined, 'chain');
        }
        case 'personal_sign': {
          await assertSuiteFork(network, expected);
          requireSelected(params[1]);
          const signature = await forward(method, params);
          signedMessages.push(decodeSignPayload(params[0]));
          return signature;
        }
        case 'eth_signTypedData_v4': {
          await assertSuiteFork(network, expected);
          requireSelected(params[0]);
          return forward(method, params);
        }
        case 'eth_sendTransaction': {
          await assertSuiteFork(network, expected);
          requireSelected((params[0] as { from?: unknown } | undefined)?.from);
          return forward(method, params);
        }
        default:
          if (READ_METHODS.has(method)) return forward(method, params);
          throw new TestWalletRefused('method', `${method} isn't on the test wallet's allowlist`);
      }
    } catch (err) {
      throw bridgeError(err);
    }
  });

  await context.addInitScript({
    content: `(${walletInitScript.toString()})(${isAllowedTestHost.toString()}, ${JSON.stringify({ chainIdHex })});`,
  });

  return {
    address,
    chainIdHex,
    signedMessages,
    overrideExpectedInstanceIdForTest(id: string) {
      expected.instanceId = id;
    },
  };
}

/**
 * Signs in through the site's own "Sign in with Ethereum" button and waits for the
 * signed-in reload. Throws with the page's error text if the site refuses.
 */
export async function signInWithEthereum(page: Page): Promise<void> {
  await page.goto('/user/login');
  const button = page.locator('#siwe-login-button');
  await button.waitFor({ state: 'visible' });
  const navigated = page.waitForEvent('framenavigated', {
    predicate: (frame) => frame === page.mainFrame(),
    timeout: 60_000,
  });
  const refused = page
    .locator('[data-drupal-message-id="siwe-login-error"]')
    .waitFor({ state: 'visible', timeout: 60_000 })
    .then(async () => {
      const text = await page.locator('[data-drupal-message-id="siwe-login-error"]').innerText();
      throw new Error(`SIWE sign-in refused by the site: ${text.trim()}`);
    });
  refused.catch(() => undefined);
  await button.click();
  await Promise.race([navigated, refused]);
  await page.waitForLoadState('load');
}
