// The live governance judge. CI only:
// .github/workflows/governance-judge.yml runs it on demand against the site it built and started
// on http://localhost:8080, on live Ethereum Sepolia, with the relayer built from the commit the
// run names. live-global-setup.ts refuses anywhere else.
//
// One serial test with real MetaMask 13.13.1 and the CI test-operator wallet (never a personal wallet),
// whose seed exists only as a CI secret and is imported by wallet-setup/live/governance.setup.ts
// when cache-governance.ts builds the profile (this file never reads it). Accounts:
//   1  ci-organizer, a group manager and approver; pays the fund's deploy and funding;
//   2  ci-member, the voting member; never holds ETH;
//   3  ci-approver, the second group manager and approver.
// Every voting step on the public record is sent and paid by the CI relayer (gas_sponsor).
//
// The flow, list to carried out, from the screens' labels (governance/pages.ts):
//   sign in as 1; set up the group fund (2 of 2: accounts 1 and 3) and fund it with 0.0002 ETH;
//   Set up group voting: 2 votes, 1 day, "Any member who can vote", a 1-hour safety check, the
//   new-members box as it starts (checked), and the admin-only overrides 10 minutes, 600 s
//   and 300 s, which make it a test group; approve the setup as 1 then 3, each
//   reading "Confirmation 1 of 3" to "Confirmation 3 of 3"; wait for "Group voting is
//   ready."; account 2 connects its wallet (its "Let them vote" row is queued already selected,
//   "Selected because new members are added automatically."); 1 and 3 approve it; 3
//   proposes paying 0.0001 ETH to account 1 ("Confirm in your wallet"); 2 and 1
//   vote For; "Carried out", with the Payment rows' "Pay to" reading the organizer's name.
// Account 2's balance is exactly 0 before it votes and after the payment goes out.
// Criterion 7: the Safe's owners, the top hat's wearer, the voting page name's owner and the
// space's admins are read from the chain and the hub; any host power fails the run.
//
// Output lines (public chain data and the testnet sequencer's own words, nothing else):
//   HASH <step> <hash>         every transaction hash the pages show or the run reads;
//   RECORD <fact> <value>      addresses, for the host-power check;
//   CAPTURE <name> <value>     the live sequencer captures (see captureSequencer()).
// The workflow copies these to the job summary. No trace, video or screenshot.
//
// Environment (set by the workflow): LN_LIVE_BASE_URL (playwright.config.ts), LN_LIVE_GROUP,
// LN_LIVE_MEMBER_UID and LN_LIVE_MEMBER_LOGIN (seed-governance-live.php), LN_ACCOUNT_1 to
// LN_ACCOUNT_3 (cache-governance.ts), LN_LIVE_RPC_URL (Ethereum Sepolia, Node-side reads),
// LN_LIVE_RELAYER_ADDRESS (the relayer's GET /status), LN_LIVE_WALLET_ADDRESS (optional; the
// repository variable, checked against account 1).
//
// Steps that aren't voting screens use their own helpers: SIWE sign-in (the
// #siwe-login-button, copied from live-sepolia.e2e.ts), the group fund set-up
// (tests/e2e/helpers/treasury.ts) and siwe_login's own "Connect your wallet" button.
import { cpSync, existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium, expect, test as base, type BrowserContext, type Page, type Request, type TestInfo } from '@playwright/test';
import { CACHE_DIR_NAME, prepareExtension } from '@synthetixio/synpress-cache';
import { getExtensionId } from '@synthetixio/synpress/playwright';
import { decodeFunctionResult, encodeFunctionData, getAddress, keccak256, toHex, type Hex } from 'viem';
import { generatePrivateKey, privateKeyToAccount } from 'viem/accounts';
import { liveBrowserArgs } from './cache-live';
import { GOVERNANCE_CACHE_NAME } from './cache-governance';
import { ACCOUNT_NAMES, GOVERNANCE_WALLET_PASSWORD } from './wallet-setup/live/governance.setup';
import { confirmPrompts, openHome, SWITCH_PROMPT, within, type PromptLog } from './metamask-steps';
import { bounded, forSite, networkOf, REOPEN, signInAs as signInAsAccount, signInWithMetaMask as signInWithWallet, useAccount as shareAccount, WALLET_READ_MS, type JudgeWallet } from './governance/judge-wallet';
import { categorized, FailureDump, hexQuantity, receiptOutcome, txHashText } from './failure-dump';
import { approveSetup } from './governance/live-steps';
import { setupReason } from './setup-reason';
import { until } from './wait-loop';
import { openFund, accountId, receiptPanel, setUpGroupFund, type WalletDriver } from '../e2e/helpers/treasury';
import type { RegistryEntry } from '../e2e/helpers/chain';
import {
  COPY,
  approveMembers,
  carryOut,
  createProposal,
  proposalPage,
  setupForm,
  setupProgress,
  votesTab,
} from './governance/pages';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = join(HERE, '..', '..');

/** The registry entry the site ships (localnodes_web3 config/install), read from the checkout. */
const REGISTRY_FILE = join(
  REPO_ROOT,
  'html/modules/custom/localnodes_platform/modules/localnodes_web3/config/install/safe_smart_accounts.network.ethereum_sepolia.yml',
);

/** The Ethereum Sepolia registry entry and the governance addresses and URLs it holds. */
function registry(): { entry: RegistryEntry; hats: string; universalHelper: string; hub: string; sequencer: string } {
  const yml = readFileSync(REGISTRY_FILE, 'utf8');
  const top = (key: string) => yml.match(new RegExp(`^${key}:\\s*'?([^'\\n]+?)'?\\s*$`, 'm'))?.[1];
  const nested = (key: string) => yml.match(new RegExp(`^\\s+${key}:\\s*'?([^'\\n]+?)'?\\s*$`, 'm'))?.[1];
  const native = (key: string) => yml.match(new RegExp(`^native_currency:[\\s\\S]*?^\\s+${key}:\\s*'?([^'\\n]+?)'?\\s*$`, 'm'))?.[1];
  const label = top('label');
  const chainId = Number(top('chain_id'));
  const [hats, universalHelper, hub, sequencer] = ['hats', 'ens_universal_helper', 'snapshot_hub', 'snapshot_sequencer'].map(nested);
  const name = native('name');
  const symbol = native('symbol');
  if (!label || chainId !== 11155111 || !name || symbol !== 'ETH' || !hats || !universalHelper || !hub || !sequencer) {
    throw new Error(`unexpected Ethereum Sepolia registry entry in ${REGISTRY_FILE}`);
  }
  return {
    entry: { id: 'ethereum_sepolia', label, chain_id: chainId, contracts: {}, tokens: [], native_currency: { name, symbol, decimals: 18 } },
    hats,
    universalHelper,
    hub,
    sequencer: sequencer.replace(/\/+$/, ''),
  };
}

const SEPOLIA_HEX = '0xaa36a7';
const FUND_WEI = 2n * 10n ** 14n; // 0.0002 ETH
const PAY_TEXT = '0.0001';
const ORGANIZER_NAME = 'Nightly Organizer';
const MEMBER_NAME = 'Nightly Member';

/** The admin-only test-group values: voting minutes, safety check s, waiting s. */
const TEST_GROUP = { votingMinutes: '10', safetyCheckSeconds: '600', waitingSeconds: '300' };

/** Prints one public line for the job summary. */
function out(kind: 'HASH' | 'RECORD' | 'CAPTURE', key: string, value: string): void {
  console.log(`${kind} ${key} ${value}`);
}

/** The sequencer's or the hub's words, printable and short: public testnet text. */
function words(value: unknown): string {
  return JSON.stringify(String(value ?? '').replace(/[^\x20-\x7e]/g, '?').slice(0, 200));
}

/** A required environment value. */
function env(name: string, shape?: RegExp): string {
  const value = (process.env[name] ?? '').trim();
  if (!value) throw new Error(`${name} is not set (the workflow sets it)`);
  if (shape && !shape.test(value)) throw new Error(`${name} has an unexpected form`);
  return value;
}

const ADDRESS = /^0x[0-9a-fA-F]{40}$/;

/**
 * One JSON-RPC call to Ethereum Sepolia from Node (LN_LIVE_RPC_URL; never logged), as
 * live-sepolia.e2e.ts: errors carry the method, a fixed category and a number at most.
 */
async function rpc(method: string, params: unknown[]): Promise<unknown> {
  const url = env('LN_LIVE_RPC_URL');
  let res: Response;
  try {
    res = await fetch(url, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ jsonrpc: '2.0', id: 1, method, params }) });
  }
  catch (e) {
    throw new Error(`${method}: ${categorized(e).message}`);
  }
  if (!res.ok) throw new Error(`${method}: rpc_error (HTTP ${res.status})`);
  const body = (await res.json().catch(() => null)) as { result?: unknown; error?: { code?: unknown } } | null;
  if (!body) throw new Error(`${method}: rpc_error (not JSON)`);
  if (body.error) throw new Error(`${method}: rpc_error (code ${Number.isInteger(body.error.code) ? String(body.error.code) : '-'})`);
  return body.result;
}

const fixed = (e: unknown): never => {
  throw categorized(e);
};

async function balanceWei(address: string): Promise<bigint> {
  return hexQuantity(await rpc('eth_getBalance', [address, 'latest']));
}

async function waitForReceipt(hash: string, timeoutMs = 300_000): Promise<void> {
  const end = Date.now() + timeoutMs;
  for (;;) {
    const outcome = receiptOutcome(await rpc('eth_getTransactionReceipt', [hash]).catch(() => null));
    if (outcome === 'success') return;
    if (outcome === 'failed') throw new Error(`transaction ${txHashText(hash)} failed on chain (status 0x0)`);
    if (outcome === 'bad_result') throw new Error(`transaction ${txHashText(hash)}: rpc_error (bad_result)`);
    if (Date.now() > end) throw new Error(`no receipt for ${txHashText(hash)} within ${timeoutMs} ms`);
    await new Promise((resolve) => setTimeout(resolve, 5_000));
  }
}

// ---------------------------------------------------------------------------------------------
// Chain reads for the host-power check (viem encodes; the selectors are checked against the module's).
// ---------------------------------------------------------------------------------------------

const SAFE_ABI = [{ type: 'function', name: 'getOwners', stateMutability: 'view', inputs: [], outputs: [{ type: 'address[]' }] }] as const;
const OWNER_ABI = [{ type: 'function', name: 'owner', stateMutability: 'view', inputs: [], outputs: [{ type: 'address' }] }] as const;
const HATS_ABI = [{
  type: 'function', name: 'isWearerOfHat', stateMutability: 'view',
  inputs: [{ name: '_user', type: 'address' }, { name: '_hatId', type: 'uint256' }], outputs: [{ type: 'bool' }],
}] as const;
const HELPER_ABI = [{ type: 'function', name: 'findExactOwner', stateMutability: 'view', inputs: [{ name: 'name', type: 'bytes' }], outputs: [{ type: 'address' }] }] as const;
/** safe_governance HatsCodec::IS_WEARER_OF_HAT and EnsV2Codec::FIND_EXACT_OWNER. */
const MODULE_SELECTORS = { isWearerOfHat: '0x4352409a', findExactOwner: '0x78b8187f' };

async function call(to: string, data: Hex): Promise<Hex> {
  const result = await rpc('eth_call', [{ to, data }, 'latest']);
  if (typeof result !== 'string' || !/^0x[0-9a-fA-F]*$/.test(result)) throw new Error('eth_call: rpc_error (bad_result)');
  return result as Hex;
}

async function safeOwners(safe: string): Promise<string[]> {
  const data = encodeFunctionData({ abi: SAFE_ABI, functionName: 'getOwners' });
  return [...decodeFunctionResult({ abi: SAFE_ABI, functionName: 'getOwners', data: await call(safe, data) })].map((a) => getAddress(a));
}

async function ownerOf(contract: string): Promise<string> {
  const data = encodeFunctionData({ abi: OWNER_ABI, functionName: 'owner' });
  return getAddress(decodeFunctionResult({ abi: OWNER_ABI, functionName: 'owner', data: await call(contract, data) }));
}

async function wearsHat(hats: string, user: string, hat: bigint): Promise<boolean> {
  const data = encodeFunctionData({ abi: HATS_ABI, functionName: 'isWearerOfHat', args: [user as Hex, hat] });
  if (!data.startsWith(MODULE_SELECTORS.isWearerOfHat)) throw new Error('isWearerOfHat selector differs from safe_governance HatsCodec');
  return decodeFunctionResult({ abi: HATS_ABI, functionName: 'isWearerOfHat', data: await call(hats, data) });
}

/** A name in DNS wire format (safe_governance EnsV2Codec::dnsEncode). */
function dnsEncode(name: string): Hex {
  let hex = '';
  for (const label of name.split('.')) {
    const bytes = Buffer.from(label, 'utf8');
    if (bytes.length === 0 || bytes.length > 63) throw new Error('a DNS label is 1 to 63 bytes');
    hex += bytes.length.toString(16).padStart(2, '0') + bytes.toString('hex');
  }
  return `0x${hex}00`;
}

async function nameOwner(helper: string, name: string): Promise<string> {
  const data = encodeFunctionData({ abi: HELPER_ABI, functionName: 'findExactOwner', args: [dnsEncode(name)] });
  if (!data.startsWith(MODULE_SELECTORS.findExactOwner)) throw new Error('findExactOwner selector differs from safe_governance EnsV2Codec');
  return getAddress(decodeFunctionResult({ abi: HELPER_ABI, functionName: 'findExactOwner', data: await call(helper, data) }));
}

/** The hub's record of a space: its admins and moderators (public GraphQL, with variables). */
async function hubSpace(hub: string, space: string): Promise<{ admins: string[]; moderators: string[] } | null> {
  const res = await fetch(hub, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ query: 'query($id: String!) { space(id: $id) { id admins moderators } }', variables: { id: space } }),
  });
  if (!res.ok) throw new Error(`hub: HTTP ${res.status}`);
  const body = (await res.json()) as { data?: { space?: { admins?: string[]; moderators?: string[] } | null } };
  const found = body.data?.space;
  return found ? { admins: found.admins ?? [], moderators: found.moderators ?? [] } : null;
}

// ---------------------------------------------------------------------------------------------
// The sequencer captures.
// ---------------------------------------------------------------------------------------------

/** safe_governance TypedData: the Snapshot domain and the vote and proposal types, verbatim. */
const SNAPSHOT_DOMAIN = { name: 'snapshot', version: '0.1.4' } as const;
const VOTE_TYPES = [
  { name: 'from', type: 'string' }, { name: 'space', type: 'string' }, { name: 'timestamp', type: 'uint64' },
  { name: 'proposal', type: 'string' }, { name: 'choice', type: 'uint32' }, { name: 'reason', type: 'string' },
  { name: 'app', type: 'string' }, { name: 'metadata', type: 'string' },
] as const;
const PROPOSAL_TYPES = [
  { name: 'from', type: 'string' }, { name: 'space', type: 'string' }, { name: 'timestamp', type: 'uint64' },
  { name: 'type', type: 'string' }, { name: 'title', type: 'string' }, { name: 'body', type: 'string' },
  { name: 'discussion', type: 'string' }, { name: 'choices', type: 'string[]' }, { name: 'labels', type: 'string[]' },
  { name: 'start', type: 'uint64' }, { name: 'end', type: 'uint64' }, { name: 'snapshot', type: 'uint64' },
  { name: 'plugins', type: 'string' }, { name: 'privacy', type: 'string' }, { name: 'app', type: 'string' },
] as const;

/**
 * A throwaway key made for this run only: it holds nothing, wears no hat, and nothing it signs
 * can pass. Its envelopes are posted straight to the testnet sequencer to record its answers.
 */
const stranger = privateKeyToAccount(generatePrivateKey());

/** Posts one signed envelope; returns 'accepted' or the sequencer's error_description. */
async function post(sequencer: string, primaryType: 'Vote' | 'Proposal', message: Record<string, unknown>): Promise<{ accepted: boolean; text: string }> {
  const types = primaryType === 'Vote' ? { Vote: VOTE_TYPES } : { Proposal: PROPOSAL_TYPES };
  const sig = await stranger.signTypedData({ domain: SNAPSHOT_DOMAIN, types: types as any, primaryType, message: message as any });
  const res = await fetch(sequencer, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ address: stranger.address, sig, data: { domain: SNAPSHOT_DOMAIN, types, message } }),
  });
  const body = (await res.json().catch(() => ({}))) as { error?: unknown; error_description?: unknown; id?: unknown };
  if (res.ok && !body.error) return { accepted: true, text: String(body.id ?? '') };
  return { accepted: false, text: String(body.error_description ?? body.error ?? `HTTP ${res.status}`) };
}

function strangerVote(space: string, proposal: string, timestamp: number): Record<string, unknown> {
  return { from: stranger.address, space, timestamp, proposal, choice: 1, reason: '', app: '', metadata: '{}' };
}

// ---------------------------------------------------------------------------------------------
// The browser, MetaMask and the accounts.
// ---------------------------------------------------------------------------------------------

const wallets = new WeakMap<BrowserContext, { extensionId: string }>();

const test = base.extend<{ extensionId: string }>({
  // Our own launch, as the live suite does: the cached profile is copied, so the cache stays clean.
  context: async ({}, use, testInfo) => {
    const cache = join(process.cwd(), CACHE_DIR_NAME, GOVERNANCE_CACHE_NAME);
    if (!existsSync(cache)) throw new Error(`no governance MetaMask cache at ${cache}: npm --prefix tests/e2e-live run cache:governance`);
    const profile = mkdtempSync(join(tmpdir(), 'ln-e2e-live-governance-'));
    cpSync(cache, profile, { recursive: true });
    const extensionPath = await prepareExtension();
    const context = await chromium.launchPersistentContext(profile, {
      headless: false,
      args: liveBrowserArgs(extensionPath),
      baseURL: testInfo.project.use.baseURL,
      viewport: { width: 1280, height: 720 },
    });
    // A persistent context does not take the config's action and navigation limits; set them here.
    context.setDefaultTimeout(testInfo.project.use.actionTimeout ?? 60_000);
    context.setDefaultNavigationTimeout(testInfo.project.use.navigationTimeout ?? 60_000);
    try {
      const extensionId = await getExtensionId(context, 'MetaMask');
      const page = context.pages()[0] ?? (await context.newPage());
      await openHome(page, extensionId, GOVERNANCE_WALLET_PASSWORD);
      wallets.set(context, { extensionId });
      await use(context);
    } finally {
      await context.close();
      rmSync(profile, { recursive: true, force: true });
    }
  },
  extensionId: async ({ context }, use) => {
    await use(wallets.get(context)!.extensionId);
  },
});

function metaMaskDriver(context: BrowserContext, extensionId: string, log: PromptLog): WalletDriver {
  return {
    async confirm(kind) {
      await confirmPrompts(context, extensionId, kind, log);
    },
  };
}

function judgeWallet(context: BrowserContext, extensionId: string, log: PromptLog): JudgeWallet {
  return { context, extensionId, password: GOVERNANCE_WALLET_PASSWORD, log };
}

/** SIWE through the site's own button (governance/judge-wallet.ts). */
async function signInWithMetaMask(page: Page, context: BrowserContext, extensionId: string, log: PromptLog): Promise<void> {
  await signInWithWallet(page, judgeWallet(context, extensionId, log));
}

/** Asks MetaMask, from the site's page, to put this site on Ethereum Sepolia (EIP-3326). */
async function switchSiteToSepolia(page: Page, context: BrowserContext, extensionId: string, log: PromptLog): Promise<string> {
  const chain = () =>
    bounded(
      page.evaluate(() => (window as unknown as { ethereum: { request(a: unknown): Promise<string> } }).ethereum.request({ method: 'eth_chainId' })).catch(fixed),
      WALLET_READ_MS,
      'the wallet (eth_chainId)',
    );
  if ((await chain()) === SEPOLIA_HEX) return SEPOLIA_HEX;
  let done = false;
  const ask = page
    .evaluate((chainId) => (window as unknown as { ethereum: { request(a: unknown): Promise<unknown> } }).ethereum.request({ method: 'wallet_switchEthereumChain', params: [{ chainId }] }), SEPOLIA_HEX)
    .catch(fixed)
    .finally(() => { done = true; });
  await Promise.all([ask, confirmPrompts(context, extensionId, 'switchNetwork', log, { done: () => done, timeoutMs: 120_000, ...REOPEN, expect: { ...forSite(SWITCH_PROMPT, page), ...networkOf(SEPOLIA_HEX) } })]);
  return chain();
}

/** Makes `index` the account MetaMask shares with the site first (governance/judge-wallet.ts). */
async function useAccount(page: Page, context: BrowserContext, extensionId: string, log: PromptLog, index: 0 | 1 | 2, address: string): Promise<void> {
  await shareAccount(page, judgeWallet(context, extensionId, log), ACCOUNT_NAMES[index], address);
}

/** Signs out of the site (its session cookie), then signs in as `index` with SIWE. */
async function signInAs(page: Page, context: BrowserContext, extensionId: string, log: PromptLog, index: 0 | 1 | 2, address: string): Promise<void> {
  await signInAsAccount(page, judgeWallet(context, extensionId, log), ACCOUNT_NAMES[index], address);
}

function reportPrompts(info: TestInfo, step: string, log: PromptLog): number {
  const clicks = log.clicks.map((c) => `${c.kind}: ${c.button}`);
  console.log(`[judge] ${step}: MetaMask clicks ${JSON.stringify(clicks)}; warning lines ${log.warnings.length}`);
  if (log.warnings.length) info.annotations.push({ type: 'finding', description: `MetaMask showed ${log.warnings.length} warning line(s) (${step})` });
  const count = log.clicks.length;
  log.clicks.length = 0;
  log.warnings.length = 0;
  return count;
}

// ---------------------------------------------------------------------------------------------
// The run.
// ---------------------------------------------------------------------------------------------

test.describe.serial('live governance judge', () => {
  let dump = new FailureDump();
  test.beforeEach(async ({ page }) => {
    dump = new FailureDump();
    dump.watch(page);
  });
  test.afterEach(async ({ page }, info) => {
    if (info.status !== info.expectedStatus) {
      const printed = await within(dump.print(page), 60_000).catch(() => 'failed' as const);
      if (printed !== 'timeout' && printed !== 'failed') return;
      console.log(`[judge] failure dump: page read ${printed === 'timeout' ? 'did not finish within 60000 ms' : 'failed'}`);
    }
  });

  test('judge: list, proposal, vote, carried out', async ({ page, context, extensionId }, info) => {
    const gid = Number(env('LN_LIVE_GROUP', /^\d+$/));
    const memberUid = Number(env('LN_LIVE_MEMBER_UID', /^\d+$/));
    const memberLogin = env('LN_LIVE_MEMBER_LOGIN', /^https?:\/\/(localhost|[a-z0-9.-]+\.test)(:\d+)?\/user\/reset\//);
    const [acc1, acc2, acc3] = ['LN_ACCOUNT_1', 'LN_ACCOUNT_2', 'LN_ACCOUNT_3'].map((n) => getAddress(env(n, ADDRESS)));
    const relayer = getAddress(env('LN_LIVE_RELAYER_ADDRESS', ADDRESS));
    const net = registry();
    const log: PromptLog = { clicks: [], warnings: [] };
    const driver = metaMaskDriver(context, extensionId, log);
    const votesUrl = `/group/${gid}/votes`;
    const name = `ln-judge-${Date.now().toString(36)}`;
    const space = `${name}.eth`;
    out('RECORD', 'account_1', acc1);
    out('RECORD', 'account_2', acc2);
    out('RECORD', 'account_3', acc3);
    out('RECORD', 'relayer', relayer);
    const expected = process.env.LN_LIVE_WALLET_ADDRESS;
    if (expected) expect(acc1.toLowerCase(), 'account 1 is the repository variable SYNPRESS_WALLET_ADDRESS').toBe(expected.toLowerCase());
    expect(new Set([acc1, acc2, acc3, relayer]).size, 'the three accounts and the relayer are four addresses').toBe(4);

    const memberBalance = await test.step('account 2 holds no ETH', async () => {
      const wei = await balanceWei(acc2);
      console.log(`[judge] account 2 holds ${wei} wei before anything`);
      expect(wei, 'account 2 starts with exactly 0').toBe(0n);
      return wei;
    });

    await test.step('sign in as the organizer (account 1), on Ethereum Sepolia', async () => {
      await page.goto('/user/login');
      await useAccount(page, context, extensionId, log, 0, acc1);
      await signInWithMetaMask(page, context, extensionId, log);
      expect(await switchSiteToSepolia(page, context, extensionId, log), 'MetaMask has the site on Ethereum Sepolia').toBe(SEPOLIA_HEX);
      reportPrompts(info, 'sign in', log);
      expect(await balanceWei(acc1), 'account 1 can pay the fund deploy and funding (README: top it up)').toBeGreaterThan(5n * 10n ** 15n);
    });

    const safe = await test.step('set up the group fund: 2 of 2, accounts 1 and 3', async () => {
      await setUpGroupFund(page, gid, { threshold: 2, driver, network: net.entry });
      await expect(receiptPanel(page).getByText('The group fund is ready.')).toBeVisible({ timeout: 300_000 });
      const deploy = await receiptPanel(page).getByRole('link', { name: /public record/ }).first().getAttribute('href').catch(() => null);
      const hash = deploy?.match(/\/tx\/(0x[0-9a-fA-F]{64})/)?.[1];
      if (hash) out('HASH', 'fund_deploy', hash.toLowerCase());
      reportPrompts(info, 'fund set-up', log);
      await openFund(page, gid);
      const id = getAddress((await accountId(page)).trim());
      out('RECORD', 'safe', id);
      expect((await safeOwners(id)).sort(), 'the fund is owned by accounts 1 and 3').toEqual([acc1, acc3].sort());
      return id;
    });

    await test.step('fund it from account 1 (0.0002 ETH)', async () => {
      let done = false;
      const send = page
        .evaluate(({ from, to, value }) => (window as unknown as { ethereum: { request(a: unknown): Promise<string> } }).ethereum.request({ method: 'eth_sendTransaction', params: [{ from, to, value }] }),
          { from: acc1, to: safe, value: '0x' + FUND_WEI.toString(16) })
        .catch(fixed)
        .finally(() => { done = true; });
      const [hash] = await Promise.all([send, confirmPrompts(context, extensionId, 'send', log, { done: () => done, timeoutMs: 180_000 })]);
      reportPrompts(info, 'funding', log);
      out('HASH', 'fund_transfer', String(hash).toLowerCase());
      await waitForReceipt(hash as string);
    });

    await test.step('Set up group voting (V6), with the test-group overrides', async () => {
      await page.goto(votesUrl);
      await votesTab(page).setUpGroupVoting.click();
      const v6 = setupForm(page);
      await v6.votingPageName.fill(name);
      await v6.votesNeeded.fill('2');
      await v6.votingLength(COPY.oneDay).check();
      await v6.anyMemberCanPropose.check();
      // Every organizer fills the safety check; the override below wins only for a test group.
      await v6.safetyCheckHours.fill('1');
      // People join this group only when added, so the box starts checked; left as it is.
      await expect(v6.newMembersAutomatically, 'the new-members box starts checked').toBeChecked();
      await v6.adminDetails.click();
      await v6.votingPeriodMinutes.fill(TEST_GROUP.votingMinutes);
      await v6.safetyCheckSeconds.fill(TEST_GROUP.safetyCheckSeconds);
      await v6.waitingSeconds.fill(TEST_GROUP.waitingSeconds);
      await v6.start.click();
      await expect(setupProgress(page).region).toBeVisible({ timeout: 60_000 });
      await expect(setupProgress(page).approvals(0, 2, 2)).toBeVisible();
    });

    await test.step('approve the setup as account 1 (Confirmation 1 to 3 of 3)', async () => {
      await approveSetup(page, context, extensionId, log, 'account 1');
      reportPrompts(info, 'setup approval 1', log);
    });

    await test.step('approve the setup as account 3 (Confirmation 1 to 3 of 3)', async () => {
      await signInAs(page, context, extensionId, log, 2, acc3);
      await page.goto(`${votesUrl}/setup`);
      await approveSetup(page, context, extensionId, log, 'account 3');
      reportPrompts(info, 'setup approval 3', log);
    });

    const { module, topHatDomain } = await test.step('wait for "Group voting is ready."', async () => {
      const v7 = setupProgress(page);
      try {
        await until(page, 'Group voting is ready.', () => v7.ready.isVisible(), [
          [COPY.setupFailed, () => v7.setupFailed.isVisible()],
          [COPY.rulesRefused, () => v7.rulesRefused.isVisible()],
        ], 20 * 60_000);
      }
      catch (error) {
        // Why the setup stopped, from the site's own record, before the site is torn down.
        for (const line of await setupReason()) console.log(`[judge] ${line}`);
        throw error;
      }
      for (const href of await page.getByRole('link', { name: /public record/ }).evaluateAll((a) => a.map((x) => (x as HTMLAnchorElement).href))) {
        const hash = href.match(/\/tx\/(0x[0-9a-fA-F]{64})/)?.[1];
        if (hash) out('HASH', 'voting_setup', hash.toLowerCase());
      }
      const link = await v7.votingPageLink.getAttribute('href');
      out('CAPTURE', 'voting_page_url', words(link));
      await v7.technicalDetails.click();
      await expect(v7.votingPage).toContainText(space);
      const id = getAddress(await v7.carryOutModule());
      out('RECORD', 'carry_out_module', id);
      const domain = await v7.topHatDomain();
      out('RECORD', 'top_hat_domain', domain);
      return { module: id, topHatDomain: domain };
    });

    await test.step('account 2 connects its wallet (siwe_login), and is queued already selected', async () => {
      await context.clearCookies();
      await page.goto(memberLogin);
      await page.goto(`/user/${memberUid}/wallet`);
      await useAccount(page, context, extensionId, log, 1, acc2);
      const connect = page.getByRole('button', { name: 'Connect your wallet', exact: true });
      let navigated = false;
      const nav = page.waitForEvent('framenavigated', { predicate: (f) => f === page.mainFrame(), timeout: 180_000 }).then(() => { navigated = true; }, () => undefined);
      await connect.click();
      await confirmPrompts(context, extensionId, 'connectWallet', log, { done: () => navigated, timeoutMs: 180_000 });
      await nav;
      await page.waitForLoadState('load');
      await expect(page.getByText(acc2, { exact: true }), 'the Wallet tab shows account 2').toBeVisible({ timeout: 30_000 });
      reportPrompts(info, 'member wallet', log);
    });

    await test.step('Approve new members (V8): the row starts selected; accounts 1 and 3 approve it', async () => {
      await signInAs(page, context, extensionId, log, 0, acc1);
      await page.goto(`${votesUrl}/setup`);
      await setupProgress(page).approveNewMembers.click();
      const v8 = approveMembers(page);
      const row = v8.row(MEMBER_NAME, COPY.letThemVote);
      await expect(row, 'the member\'s row loads already selected').toBeChecked();
      await expect(v8.selectedBecauseNewMembers).toBeVisible();
      await v8.approveSelected.click();
      await v8.approveTheseChanges.click();
      await confirmPrompts(context, extensionId, 'approveMembers', log, { done: () => v8.youApprovedTheseChanges.isVisible(), timeoutMs: 180_000 });
      reportPrompts(info, 'member approval 1', log);
      await signInAs(page, context, extensionId, log, 2, acc3);
      await page.goto(`${votesUrl}/members`);
      await v8.approveTheseChanges.click();
      await confirmPrompts(context, extensionId, 'approveMembers', log, {
        done: async () => (await v8.youApprovedTheseChanges.isVisible()) || (await v8.approveTheseChanges.count()) === 0,
        timeoutMs: 180_000,
      });
      reportPrompts(info, 'member approval 3', log);
      await until(page, 'three members can vote', () => v8.votingMembers(3).count().then((n) => n === 1), [
        [COPY.changesDidntGoThrough, () => v8.changesDidntGoThrough.isVisible()],
      ], 15 * 60_000);
    });

    const proposalUrl = await test.step('account 3 proposes paying 0.0001 ETH to account 1 (V5)', async () => {
      await page.goto(votesUrl);
      await votesTab(page).createProposal.click();
      const v5 = createProposal(page);
      await v5.proposePayment.click();
      await v5.title.fill(`Judge payment ${new Date().toISOString().slice(0, 16)}`);
      await v5.currency('ETH').check();
      await v5.amount.fill(PAY_TEXT);
      await v5.payTo.fill(acc1);
      await v5.why.fill('The governance judge pays 0.0001 ETH back to the organizer, carried out by the group\'s vote.');
      let publishBody = '';
      page.on('request', (r) => {
        if (r.method() === 'POST' && /\/votes\/publish(?:\?|$)/.test(new URL(r.url()).pathname)) publishBody = r.postData() ?? '';
      });
      await v5.publish.click();
      await expect(v5.confirmInYourWallet).toBeVisible({ timeout: 60_000 });
      // The wallet is asked only from this click.
      const before = context.pages().filter((p) => p.url().startsWith(`chrome-extension://${extensionId}/notification.html`)).length;
      out('CAPTURE', 'propose_prompt_before_click', before === 0 ? 'none' : 'open');
      expect(before, 'no wallet prompt opens before "Confirm in your wallet" is clicked').toBe(0);
      await v5.confirmInYourWallet.click();
      await confirmPrompts(context, extensionId, 'propose', log, {
        done: async () => (await v5.openUntil.isVisible()) || (await v5.hubFailed.isVisible()) || (await v5.notYet.isVisible()) || (await v5.wentWrong.isVisible()),
        timeoutMs: 180_000,
      });
      const signatures = reportPrompts(info, 'propose', log);
      out('CAPTURE', 'propose_wallet_prompts', String(signatures));
      out('CAPTURE', 'propose_domain_chainid', /"chainId"/.test(publishBody) ? 'yes' : 'no');
      await expect(v5.openUntil, 'the proposal is open for voting').toBeVisible();
      return page.url();
    });

    const proposalId = await test.step('the proposal\'s public record link', async () => {
      const href = (await proposalPage(page).publicRecord.getAttribute('href')) ?? '';
      out('CAPTURE', 'vote_public_record_url', words(href));
      const id = href.match(/\/proposal\/(0x[0-9a-fA-F]{64})/)?.[1];
      expect(id, 'the vote\'s public record link names the proposal').toBeTruthy();
      return id!;
    });

    await test.step('account 2 still holds 0 before voting', async () => {
      expect(await balanceWei(acc2), 'account 2 holds exactly 0 before it votes').toBe(memberBalance);
    });

    for (const [index, address, who] of [[1, acc2, 'account 2'], [0, acc1, 'account 1']] as const) {
      await test.step(`${who} votes For (V3)`, async () => {
        await signInAs(page, context, extensionId, log, index, address);
        await page.goto(proposalUrl);
        const v3 = proposalPage(page);
        let voteBody = '';
        const watcher = (r: Request) => {
          if (r.method() === 'POST' && /\/vote$/.test(new URL(r.url()).pathname)) voteBody = r.postData() ?? '';
        };
        page.on('request', watcher);
        await v3.choice(COPY.voteFor).check();
        await v3.castYourVote.click();
        await confirmPrompts(context, extensionId, 'vote', log, {
          done: async () => (await v3.voteRecorded.isVisible()) || (await v3.noPower.isVisible()) || (await v3.ended.isVisible()) || (await v3.hubFailed.isVisible()) || (await v3.wentWrong.isVisible()),
          timeoutMs: 180_000,
        });
        page.off('request', watcher);
        const prompts = reportPrompts(info, `vote ${who}`, log);
        out('CAPTURE', `vote_wallet_prompts_${index + 1}`, String(prompts));
        out('CAPTURE', `vote_domain_chainid_${index + 1}`, /"chainId"/.test(voteBody) ? 'yes' : 'no');
        await expect(v3.voteRecorded, `${who}'s vote is recorded`).toBeVisible();
      });
    }

    await test.step('captures while voting is open: no voting power, and the oldest message age', async () => {
      const nowSec = Math.floor(Date.now() / 1000);
      const power = await post(net.sequencer, 'Vote', strangerVote(space, proposalId, nowSec));
      out('CAPTURE', 'sequencer_no_power', power.accepted ? 'ACCEPTED' : words(power.text));
      expect(power.accepted, 'the sequencer refuses a vote from an account without the member role').toBe(false);
      // ProposalPublisher::MAX_AGE assumes 900 s. The boundary is where the answer changes from the
      // voting-power refusal to a timestamp refusal.
      for (const age of [120, 240, 360, 600, 900, 1200]) {
        // A short gap between posts, so the sequencer's rate limit doesn't answer instead.
        await new Promise((r) => setTimeout(r, 2_000));
        const answer = await post(net.sequencer, 'Vote', strangerVote(space, proposalId, Math.floor(Date.now() / 1000) - age));
        out('CAPTURE', `sequencer_age_${age}`, answer.accepted ? 'ACCEPTED' : words(answer.text));
        expect(answer.accepted, `an old vote (${age} s) from a stranger is refused`).toBe(false);
      }
    });

    await test.step('wait for "Carried out" (up to 45 minutes)', async () => {
      await page.goto(proposalUrl);
      const v4 = carryOut(page);
      await until(page, 'Carried out', async () => (await v4.region.isVisible()) && (await v4.wentOut.isVisible()), [
        [COPY.paymentDidntGoThrough, () => v4.didntGoThrough.isVisible()],
      ], 45 * 60_000, 60_000);
      await expect(v4.carriedOut).toBeVisible();
      await expect(v4.amount).toBeVisible();
      await expect(v4.paidFrom).toBeVisible();
      await expect(v4.payTo).toBeVisible();
      await expect(v4.payee(ORGANIZER_NAME), 'Pay to reads the organizer\'s name').toBeVisible();
      const hash = await v4.transactionHash();
      out('HASH', 'payment_execution', hash);
      await expect(proposalPage(page).outcomePassed).toBeVisible();
      // The carry-out event's topic1: the module that carried it out.
      const receipt = (await rpc('eth_getTransactionReceipt', [hash])) as { status?: string; logs?: Array<{ address: string; topics: string[] }> } | null;
      expect(receipt?.status, 'the payment\'s receipt succeeded').toBe('0x1');
      const topic0 = keccak256(toHex('ExecutionFromModuleSuccess(address)'));
      const event = receipt?.logs?.find((l) => l.topics[0]?.toLowerCase() === topic0 && getAddress(l.address) === safe);
      out('CAPTURE', 'execution_from_module_success_topic1', event?.topics[1] ?? 'none');
      expect(event && getAddress(`0x${event.topics[1].slice(-40)}`), 'the fund\'s carry-out event names the group\'s module').toBe(module);
    });

    await test.step('account 2 still holds 0 after the payment went out', async () => {
      const wei = await balanceWei(acc2);
      console.log(`[judge] account 2 holds ${wei} wei after the payment`);
      expect(wei, 'account 2 holds exactly 0 after the payment').toBe(0n);
    });

    await test.step('no host power', async () => {
      const owners = await safeOwners(safe);
      out('RECORD', 'safe_owners', owners.join(' '));
      expect(owners, 'the CI relayer is not an owner of the fund').not.toContain(relayer);
      const top = BigInt(topHatDomain) << 224n;
      const safeWears = await wearsHat(net.hats, safe, top);
      out('RECORD', 'top_hat_wearer', safeWears ? safe : 'not the fund');
      expect(safeWears, 'the fund wears the top hat').toBe(true);
      for (const [who, address] of [['relayer', relayer], ['account_1', acc1], ['account_3', acc3]] as const) {
        expect(await wearsHat(net.hats, address, top), `${who} doesn't wear the top hat`).toBe(false);
      }
      const ens = await nameOwner(net.universalHelper, space);
      out('RECORD', 'name_owner', ens);
      expect(ens, 'the voting page name belongs to the fund').toBe(safe);
      expect(ens).not.toBe(relayer);
      const moduleOwner = await ownerOf(module);
      out('RECORD', 'module_owner', moduleOwner);
      expect(moduleOwner, 'the carry-out step belongs to the fund').toBe(safe);
      const hub = await hubSpace(net.hub, space);
      expect(hub, 'the hub knows the voting page').not.toBeNull();
      out('RECORD', 'space_admins', hub!.admins.length ? hub!.admins.join(' ') : '[]');
      out('RECORD', 'space_moderators', hub!.moderators.length ? hub!.moderators.join(' ') : '[]');
      expect(hub!.admins, 'the voting page has no admins').toEqual([]);
      expect(hub!.moderators.map((m) => m.toLowerCase()), 'the CI relayer is no moderator').not.toContain(relayer.toLowerCase());
    });

    await test.step('captures after voting ended: not in voting window, and proposal validation', async () => {
      const late = await post(net.sequencer, 'Vote', strangerVote(space, proposalId, Math.floor(Date.now() / 1000)));
      out('CAPTURE', 'sequencer_not_in_window', late.accepted ? 'ACCEPTED' : words(late.text));
      expect(late.accepted, 'a vote after the window is refused').toBe(false);
      const block = Number(hexQuantity(await rpc('eth_blockNumber', [])));
      const now = Math.floor(Date.now() / 1000);
      const proposal = await post(net.sequencer, 'Proposal', {
        from: stranger.address, space, timestamp: now, type: 'basic', title: 'Judge capture: a stranger proposes', body: '', discussion: '',
        choices: ['For', 'Against', 'Abstain'], labels: [], start: now, end: now + 600, snapshot: block, plugins: '{}', privacy: '', app: '',
      });
      out('CAPTURE', 'sequencer_validation', proposal.accepted ? `ACCEPTED ${words(proposal.text)}` : words(proposal.text));
      expect(proposal.accepted, 'a stranger cannot propose on the group\'s voting page').toBe(false);
    });
  });
});
