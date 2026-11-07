// Chain reads and controls for the wallet e2e specs (21-42).
//
// Every read and every control call runs `cast` inside the suite's own anvil container
// (21-39), against http://127.0.0.1:8545 inside that container. The container name comes
// from the run state fork.sh wrote (tests/e2e/.state/forks-run.json); nothing here holds a
// chain id or a contract address. The registry (chain id, factory, tokens) comes from the
// site's active config.
//
// Lane rules (R-D): only anvil's well-known dev accounts send, with `cast send --unlocked`
// (anvil signs for them; no key exists here), and only on the local anvil chains.
//
// The results file contract (21-42, used by 21-43, 21-44 and 21-30):
//   tests/e2e/.state/results-<network>.json is one JSON array of
//   {runId, step, at, result, page, chain, finding?}; result is "pass", "fail" or "skipped".
//   record() reads, appends and writes the whole array. Entries from another runId are
//   dropped first, so the file holds only the latest invocation.
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { devAccounts, readForksRun, toChecksumAddress, type ForkRunEntry } from '../wallet/test-wallet';
import { siteDrush } from './site';

const HERE = dirname(fileURLToPath(import.meta.url));
const STATE_DIR = join(HERE, '..', '.state');

/** The ProxyCreation(address proxy, address singleton) event topic (21-RESEARCH). */
export const PROXY_CREATION_TOPIC = '0x4f51faf6c4561ff95f067657e43439f0f856d97c04d9ec9070a6199ad418e235';
const ZERO = '0x0000000000000000000000000000000000000000';

export interface E2eUser { uid: number; name: string; address: string }
export interface E2eRun {
  runId: string;
  network: string;
  mode: string;
  users: Record<'organizer' | 'approver' | 'member', E2eUser>;
}

/** The run global-setup.ts set in process.env.LN_E2E. */
export function e2eRun(): E2eRun {
  const run = JSON.parse(process.env.LN_E2E ?? 'null') as E2eRun | null;
  if (!run) throw new Error('LN_E2E is not set: run through tests/e2e/playwright.config.ts (global-setup.ts)');
  return run;
}

function entry(network: string): ForkRunEntry {
  const e = readForksRun()[network];
  if (!e || !e.container) throw new Error(`no running suite chain for ${network}: bash tests/e2e/fork/fork.sh up ${network}`);
  return e;
}

const OFFLINE = new Set(['calldata', 'sig-event']);

/** `cast <args> --rpc-url http://127.0.0.1:8545` inside the network's anvil container. */
export function cast(network: string, args: string[]): string {
  const container = entry(network).container as string;
  if (!/^fresh3-e2e-anvil-[a-z-]+$/.test(container)) throw new Error(`not a suite chain container: ${container}`);
  try {
    // Offline subcommands (calldata, sig-event) take no RPC URL: cast would read it as two more values.
    const rpc = OFFLINE.has(args[0]) ? [] : ['--rpc-url', 'http://127.0.0.1:8545'];
    return execFileSync('docker', ['exec', container, 'cast', ...args, ...rpc], {
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
      timeout: 60_000,
    }).trim();
  } catch (err) {
    const e = err as { stderr?: string; message: string };
    throw new Error(`cast ${args[0]} failed on ${network}: ${(e.stderr ?? e.message).trim()}`);
  }
}

/** The first token of a cast uint output ("2" or "1250500000 [1.25e9]"). */
function uint(out: string): bigint {
  return BigInt(out.trim().split(/\s+/)[0]);
}

export function owners(network: string, safe: string): string[] {
  const out = cast(network, ['call', safe, 'getOwners()(address[])']);
  return out.replace(/^\[|\]$/g, '').split(',').map((a) => a.trim()).filter(Boolean).map((a) => toChecksumAddress(a));
}

export function threshold(network: string, safe: string): number {
  return Number(uint(cast(network, ['call', safe, 'getThreshold()(uint256)'])));
}

export function safeNonce(network: string, safe: string): number {
  return Number(uint(cast(network, ['call', safe, 'nonce()(uint256)'])));
}

export function safeVersion(network: string, safe: string): string {
  return cast(network, ['call', safe, 'VERSION()(string)']).replace(/^"|"$/g, '');
}

export function code(network: string, address: string): string {
  return cast(network, ['code', address]);
}

export function ethBalance(network: string, address: string): bigint {
  return uint(cast(network, ['balance', address]));
}

export function tokenBalance(network: string, token: string, holder: string): bigint {
  return uint(cast(network, ['call', token, 'balanceOf(address)(uint256)', holder]));
}

export function tokenDecimals(network: string, token: string): number {
  return Number(uint(cast(network, ['call', token, 'decimals()(uint8)'])));
}

export function tokenSymbol(network: string, token: string): string {
  return cast(network, ['call', token, 'symbol()(string)']).replace(/^"|"$/g, '');
}

export interface TxReceipt {
  status: string;
  transactionHash: string;
  logs: Array<{ address: string; topics: string[]; data: string }>;
}

export function receipt(network: string, hash: string): TxReceipt {
  return JSON.parse(cast(network, ['receipt', hash, '--json'])) as TxReceipt;
}

async function devAddress(network: string, index: number): Promise<string> {
  const a = (await devAccounts(network))[index];
  if (!a) throw new Error(`no dev account ${index} on ${network}`);
  return a;
}

/** `cast send --unlocked` from anvil dev account `fromIndex`; returns the receipt. */
export async function sendFrom(network: string, fromIndex: number, to: string, args: string[] = [], value?: bigint): Promise<TxReceipt> {
  const from = await devAddress(network, fromIndex);
  const extra = value === undefined ? [] : ['--value', value.toString()];
  const r = JSON.parse(cast(network, ['send', '--unlocked', '--from', from, '--json', ...extra, to, ...args])) as TxReceipt;
  if (r.status !== '0x1' && r.status !== '1') throw new Error(`send from dev ${fromIndex} to ${to} reverted (${r.transactionHash})`);
  return r;
}

export async function sendEth(network: string, fromIndex: number, to: string, wei: bigint): Promise<TxReceipt> {
  return sendFrom(network, fromIndex, to, [], wei);
}

/**
 * Mints the registry's test USDC to `to`. Local mode: MockUSDC's public mint, from dev
 * account 9. Fork mode: impersonate the token's masterMinter, configureMinter(dev 9, units),
 * stop impersonating, then mint from dev 9. Throws if the mint reverts (the caller records).
 */
export async function mintTestUsdc(network: string, to: string, units: bigint): Promise<TxReceipt> {
  const token = (await registry(network)).tokens[0]?.address;
  if (!token) throw new Error(`no token in the ${network} registry entry`);
  const minter = await devAddress(network, 9);
  if (mode(network) === 'fork') {
    const master = cast(network, ['call', token, 'masterMinter()(address)']);
    cast(network, ['rpc', 'anvil_impersonateAccount', master]);
    try {
      cast(network, ['rpc', 'anvil_setBalance', master, '0xde0b6b3a7640000']);
      cast(network, ['send', '--unlocked', '--from', master, '--json', token, 'configureMinter(address,uint256)', minter, units.toString()]);
    } finally {
      cast(network, ['rpc', 'anvil_stopImpersonatingAccount', master]);
    }
  }
  return sendFrom(network, 9, token, ['mint(address,uint256)', to, units.toString()]);
}

/**
 * Makes a Safe straight through the registry's factory, outside the app (as Safe{Wallet}
 * would): setup() calldata with the given owners and threshold, no module call, the 1.4.1
 * fallback handler and no payment; createProxyWithNonce(singleton, setup, salt) from dev
 * account 4. Returns the proxy address from the ProxyCreation log.
 */
export async function createSafeViaFactory(
  network: string,
  safeOwners: string[],
  safeThreshold: number,
  salt: bigint,
  contracts: { singleton: string; fallbackHandler: string },
): Promise<string> {
  const factory = (await registry(network)).contracts.safe_proxy_factory;
  const setup = cast(network, [
    'calldata',
    'setup(address[],uint256,address,bytes,address,address,uint256,address)',
    `[${safeOwners.join(',')}]`,
    String(safeThreshold),
    ZERO,
    '0x',
    contracts.fallbackHandler,
    ZERO,
    '0',
    ZERO,
  ]);
  const r = await sendFrom(network, 4, factory, ['createProxyWithNonce(address,bytes,uint256)', contracts.singleton, setup, salt.toString()]);
  const log = r.logs.find((l) => l.address.toLowerCase() === factory.toLowerCase() && l.topics[0]?.toLowerCase() === PROXY_CREATION_TOPIC);
  if (!log || !log.topics[1]) throw new Error(`no ProxyCreation log from ${factory} in ${r.transactionHash}`);
  return toChecksumAddress(`0x${log.topics[1].slice(-40)}`);
}

export function setAutomine(network: string, on: boolean): void {
  cast(network, ['rpc', 'evm_setAutomine', on ? 'true' : 'false']);
}

export function mine(network: string): void {
  cast(network, ['rpc', 'evm_mine']);
}

export function increaseTime(network: string, seconds: number): void {
  cast(network, ['rpc', 'evm_increaseTime', String(seconds)]);
}

export interface RegistryEntry {
  id: string;
  label: string;
  chain_id: number;
  contracts: Record<string, string>;
  tokens: Array<{ address: string; label?: string }>;
  native_currency: { name: string; symbol: string; decimals: number };
  explorer?: { name?: string; address_url?: string; tx_url?: string };
}

const registryCache = new Map<string, RegistryEntry>();

/** The site's active registry entry (config:get once per run). */
export async function registry(network: string): Promise<RegistryEntry> {
  let r = registryCache.get(network);
  if (!r) {
    if (!/^[a-z0-9_]+$/.test(network)) throw new Error(`not a network id: ${network}`);
    r = JSON.parse(siteDrush(['config:get', `safe_smart_accounts.network.${network}`, '--format=json'])) as RegistryEntry;
    registryCache.set(network, r);
  }
  return r;
}

/** "local" or "fork", from the run state. */
export function mode(network: string): string {
  return entry(network).mode;
}

export type StepResult = 'pass' | 'fail' | 'skipped';

export interface ResultEntry {
  runId: string;
  step: string;
  at: string;
  result: StepResult;
  page: Record<string, unknown>;
  chain: Record<string, unknown>;
  finding?: string | Record<string, unknown>;
}

export function resultsFile(network: string): string {
  return join(STATE_DIR, `results-${network}.json`);
}

/** Appends one entry to results-<network>.json (the contract above). */
export function record(
  network: string,
  step: string,
  data: { result: StepResult; page?: Record<string, unknown>; chain?: Record<string, unknown>; finding?: ResultEntry['finding'] },
): void {
  const runId = e2eRun().runId;
  const file = resultsFile(network);
  let entries: ResultEntry[] = [];
  try {
    const parsed = JSON.parse(readFileSync(file, 'utf8')) as unknown;
    if (Array.isArray(parsed)) entries = parsed as ResultEntry[];
  } catch {
    entries = [];
  }
  entries = entries.filter((e) => e.runId === runId);
  const row: ResultEntry = {
    runId,
    step,
    at: new Date().toISOString(),
    result: data.result,
    page: data.page ?? {},
    chain: data.chain ?? {},
  };
  if (data.finding !== undefined) row.finding = data.finding;
  entries.push(row);
  mkdirSync(STATE_DIR, { recursive: true });
  writeFileSync(file, `${JSON.stringify(entries, (_k, v) => (typeof v === 'bigint' ? v.toString() : v), 2)}\n`);
}
