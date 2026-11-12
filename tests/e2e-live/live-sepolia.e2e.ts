// The live-Sepolia MetaMask run (21-45 Task B). CI only: the nightly workflow
// (.github/workflows/nightly-wallet-e2e.yml, 21-46) runs it once a night against the site it
// built and started on http://localhost:8080, on live Ethereum Sepolia. live-global-setup.ts
// refuses anywhere else.
//
// One serial test, with real MetaMask 13.13.1 and a throwaway wallet whose seed exists only as
// a CI secret (imported by wallet-setup/live/live.setup.ts when cache-live.ts builds the
// profile; this file never reads it):
//   sign in with SIWE -> switch the site's network to Ethereum Sepolia -> set up the group fund
//   (threshold 1) -> fund it with 0.0002 ETH from the wallet -> propose 0.0001 ETH to the wallet
//   itself -> approve -> send ("Sent") -> "Refresh balances" shows what eth_getBalance says.
//
// The page steps are 21-42/21-43's helpers (tests/e2e/helpers/treasury.ts) through their
// WalletDriver seam, so this suite clicks the same screens as the local suites. Only page-level
// helpers are used: the database, site and chain helpers call ddev and docker, which CI doesn't
// have. The MetaMask prompt steps are Task A's (metamask-steps.ts); signInWithMetaMask,
// metaMaskDriver and prompts are copied from local-anvil.e2e.ts (Task A), which this lane
// couldn't re-run after a refactor.
//
// Environment (set by the workflow): LN_LIVE_BASE_URL (playwright.config.ts), LN_LIVE_GROUP (the
// group seed-live.php made), LN_LIVE_RPC_URL (Ethereum Sepolia RPC for the Node-side reads),
// LN_LIVE_WALLET_ADDRESS (optional: the wallet's public address, checked against MetaMask's).
// No trace, video or screenshot (playwright.config.ts); nothing here logs a secret.
//
// No assertion on the SIWE message's chain line (Phase 25). MetaMask warnings are logged as
// findings, not failures.
import { cpSync, existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium, expect, test as base, type BrowserContext, type Page, type TestInfo } from '@playwright/test';
import { CACHE_DIR_NAME, prepareExtension } from '@synthetixio/synpress-cache';
import { getExtensionId } from '@synthetixio/synpress/playwright';
import { LIVE_CACHE_NAME, liveBrowserArgs } from './cache-live';
import { LIVE_WALLET_PASSWORD } from './wallet-setup/live/live.setup';
import { confirmPrompts, openHome, type PromptLog } from './metamask-steps';
import { toChecksumAddress } from '../e2e/wallet/test-wallet';
import type { RegistryEntry } from '../e2e/helpers/chain';
import {
  accountId,
  approvalsSentence,
  approvePayment,
  balanceText,
  formatUnits,
  openFund,
  paymentPanel,
  paymentStatus,
  proposePayment,
  receiptPanel,
  refreshBalances,
  sendPayment,
  setUpGroupFund,
  type WalletDriver,
} from '../e2e/helpers/treasury';
import { blockCategory, blockValue, categorized, FailureDump, hexQuantity, receiptOutcome, txHashText } from './failure-dump';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = join(HERE, '..', '..');

/** The registry entry the site ships (localnodes_web3 config/install), read from the checkout. */
const REGISTRY_FILE = join(
  REPO_ROOT,
  'html/modules/custom/localnodes_platform/modules/localnodes_web3/config/install/safe_smart_accounts.network.ethereum_sepolia.yml',
);

/** The parts of the Ethereum Sepolia registry entry this suite needs, from its YAML. */
function sepoliaEntry(): RegistryEntry {
  const yml = readFileSync(REGISTRY_FILE, 'utf8');
  const top = (key: string) => yml.match(new RegExp(`^${key}:\\s*'?([^'\\n]+?)'?\\s*$`, 'm'))?.[1];
  const nested = (key: string) => yml.match(new RegExp(`^native_currency:[\\s\\S]*?^\\s+${key}:\\s*'?([^'\\n]+?)'?\\s*$`, 'm'))?.[1];
  const label = top('label');
  const chainId = Number(top('chain_id'));
  const name = nested('name');
  const symbol = nested('symbol');
  const decimals = Number(nested('decimals'));
  if (!label || chainId !== 11155111 || !name || !symbol || decimals !== 18) {
    throw new Error(`unexpected Ethereum Sepolia registry entry in ${REGISTRY_FILE}`);
  }
  return {
    id: 'ethereum_sepolia',
    label,
    chain_id: chainId,
    contracts: {},
    tokens: [],
    native_currency: { name, symbol, decimals },
  };
}

const SEPOLIA_HEX = '0xaa36a7';
const FUND_WEI = 2n * 10n ** 14n; // 0.0002 ETH
const PAY_WEI = 10n ** 14n; // 0.0001 ETH, back to the wallet itself
const PAY_TEXT = '0.0001';

/**
 * One JSON-RPC call to Ethereum Sepolia from Node (LN_LIVE_RPC_URL; never logged). Its errors
 * carry the method, a fixed category and a number at most (Codex round 2): never the provider's
 * message or the fetch error's, either of which can hold the RPC URL.
 */
async function rpc(method: string, params: unknown[]): Promise<unknown> {
  const url = process.env.LN_LIVE_RPC_URL;
  if (!url) throw new Error('LN_LIVE_RPC_URL is not set (the workflow passes the Ethereum Sepolia RPC)');
  let res: Response;
  try {
    res = await fetch(url, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ jsonrpc: '2.0', id: 1, method, params }),
    });
  }
  catch (e) {
    throw new Error(`${method}: ${categorized(e).message}`);
  }
  if (!res.ok) throw new Error(`${method}: rpc_error (HTTP ${res.status})`);
  const body = (await res.json().catch(() => null)) as { result?: unknown; error?: { code?: unknown } } | null;
  if (!body) throw new Error(`${method}: rpc_error (not JSON)`);
  if (body.error) {
    const code = Number.isInteger(body.error.code) ? String(body.error.code) : '-';
    throw new Error(`${method}: rpc_error (code ${code})`);
  }
  return body.result;
}

/** Rethrows a wallet or Playwright error as "<category> (<name>)" (failure-dump.ts). */
const fixed = (e: unknown): never => {
  throw categorized(e);
};

/**
 * Failure probe (21-46, run 42485195245): the latest block as MetaMask reports it to the page
 * (what ethers' tx.wait() polls) and as the Node-side RPC reports it, twice, 6 s apart. A
 * MetaMask number that lags or doesn't move while the RPC's does means tx.wait() can't see the
 * deploy's block. It prints integers, or a fixed category (timeout, rpc_error, no_provider),
 * never an error message. Each read is bounded at 5 s and its timer cleared; about 16 s at most.
 */
async function blockProbe(page: Page): Promise<void> {
  const within = async <T>(work: Promise<T>, ms: number): Promise<T> => {
    let timer: ReturnType<typeof setTimeout> | undefined;
    const late = new Promise<never>((_, reject) => {
      timer = setTimeout(() => reject(new Error(`no answer in ${ms} ms`)), ms);
    });
    try {
      return await Promise.race([work, late]);
    }
    finally {
      clearTimeout(timer);
    }
  };
  const read = (work: () => Promise<unknown>) => within(work(), 5_000).then(blockValue, blockCategory);
  const sample = async () => {
    const [wallet, node] = await Promise.all([
      read(() =>
        page.evaluate(() => {
          const eth = (window as unknown as { ethereum?: { request(a: unknown): Promise<string> } }).ethereum;
          if (!eth) throw new Error('no ethereum provider');
          return eth.request({ method: 'eth_blockNumber' });
        }).catch((e) => {
          throw new Error(/ethereum provider/.test(String(e)) ? 'no ethereum provider' : 'rpc error');
        }),
      ),
      read(() => rpc('eth_blockNumber', [])),
    ]);
    return `MetaMask=${wallet} RPC=${node}`;
  };
  const first = await sample();
  await new Promise((r) => setTimeout(r, 6_000));
  console.log(`[live] latest block: ${first}; 6 s later: ${await sample()}`);
}

/** Ether balance at the latest block (a normal read; no archive state needed). */
async function balanceWei(address: string): Promise<bigint> {
  // Checked before BigInt (Codex round 3): a malformed result throws "rpc_error (bad_result)".
  return hexQuantity(await rpc('eth_getBalance', [address, 'latest']));
}

/** Waits for a transaction's receipt and checks it succeeded. */
async function waitForReceipt(hash: string, timeoutMs = 300_000): Promise<void> {
  const end = Date.now() + timeoutMs;
  for (;;) {
    // Only 0x0 or 0x1 is read from the status (Codex round 3); the hash prints only if it is one.
    const outcome = receiptOutcome(await rpc('eth_getTransactionReceipt', [hash]).catch(() => null));
    if (outcome === 'success') return;
    if (outcome === 'failed') throw new Error(`transaction ${txHashText(hash)} failed on chain (status 0x0)`);
    if (outcome === 'bad_result') throw new Error(`transaction ${txHashText(hash)}: rpc_error (bad_result)`);
    if (Date.now() > end) throw new Error(`no receipt for ${txHashText(hash)} within ${timeoutMs} ms`);
    await new Promise((resolve) => setTimeout(resolve, 5_000));
  }
}

const wallets = new WeakMap<BrowserContext, { extensionId: string }>();

const test = base.extend<{ extensionId: string }>({
  // Our own launch, as in Task A (Synpress 4.1.2's fixture ignores launch arguments). The
  // cached profile is copied, so the cache stays clean. No video, no trace, no host block.
  context: async ({}, use, testInfo) => {
    const cache = join(process.cwd(), CACHE_DIR_NAME, LIVE_CACHE_NAME);
    if (!existsSync(cache)) throw new Error(`no live MetaMask cache at ${cache}: npm --prefix tests/e2e-live run cache`);
    const profile = mkdtempSync(join(tmpdir(), 'ln-e2e-live-sepolia-'));
    cpSync(cache, profile, { recursive: true });
    const extensionPath = await prepareExtension();
    const context = await chromium.launchPersistentContext(profile, {
      headless: false,
      args: liveBrowserArgs(extensionPath),
      baseURL: testInfo.project.use.baseURL,
      viewport: { width: 1280, height: 720 },
    });
    try {
      const extensionId = await getExtensionId(context, 'MetaMask');
      const page = context.pages()[0] ?? (await context.newPage());
      await openHome(page, extensionId, LIVE_WALLET_PASSWORD);
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

/** The MetaMask WalletDriver (copied from local-anvil.e2e.ts). */
function metaMaskDriver(context: BrowserContext, extensionId: string, log: PromptLog): WalletDriver {
  return {
    async confirm(kind) {
      await confirmPrompts(context, extensionId, kind, log);
    },
  };
}

/** SIWE through the site's own button (copied from local-anvil.e2e.ts, without the name check). */
async function signInWithMetaMask(page: Page, context: BrowserContext, extensionId: string, log: PromptLog): Promise<void> {
  await page.goto('/user/login');
  const button = page.locator('#siwe-login-button');
  // The button is server-rendered: it is there at load or not at all. Without a limit a missing
  // button used the whole 10-minute test budget (run 43212766603).
  await button.waitFor({ state: 'visible', timeout: 30_000 }).catch(() => {
    throw new Error('sign-in: the browser-wallet button (#siwe-login-button) is not on /user/login within 30 s');
  });
  let navigated = false;
  const nav = page
    .waitForEvent('framenavigated', { predicate: (f) => f === page.mainFrame(), timeout: 90_000 })
    .then(() => { navigated = true; }, () => undefined);
  await button.click();
  await confirmPrompts(context, extensionId, 'signIn', log, { done: () => navigated, timeoutMs: 90_000 });
  await nav;
  await page.waitForLoadState('load');
  await expect(page.locator('#siwe-login-button')).toHaveCount(0);
}

/** Asks MetaMask, from the site's page, to put this site on Ethereum Sepolia (EIP-3326). */
async function switchSiteToSepolia(page: Page, context: BrowserContext, extensionId: string, log: PromptLog): Promise<string> {
  const current = await page.evaluate(() => (window as unknown as { ethereum: { request(a: unknown): Promise<string> } }).ethereum.request({ method: 'eth_chainId' })).catch(fixed);
  if (current === SEPOLIA_HEX) return current;
  let done = false;
  const ask = page
    .evaluate(
      (chainId) =>
        (window as unknown as { ethereum: { request(a: unknown): Promise<unknown> } }).ethereum.request({
          method: 'wallet_switchEthereumChain',
          params: [{ chainId }],
        }),
      SEPOLIA_HEX,
    )
    .catch(fixed)
    .finally(() => { done = true; });
  // MetaMask asks to add Sepolia to the site's networks, or switches without asking if it may.
  await Promise.all([ask, confirmPrompts(context, extensionId, 'switchNetwork', log, { done: () => done, timeoutMs: 120_000 })]);
  return page.evaluate(() => (window as unknown as { ethereum: { request(a: unknown): Promise<string> } }).ethereum.request({ method: 'eth_chainId' })).catch(fixed);
}

/** The wallet's prompts and warnings for the log (copied in spirit from local-anvil.e2e.ts). */
function reportPrompts(info: TestInfo, step: string, log: PromptLog): void {
  const clicks = log.clicks.map((c) => `${c.kind}: ${c.button}`);
  // Kinds and button test ids are ours; MetaMask's warning text is counted, never printed (the
  // CI log is public; 21-46 ruling after Codex).
  console.log(`[live] ${step}: MetaMask clicks ${JSON.stringify(clicks)}; warning lines ${log.warnings.length}`);
  if (log.warnings.length) info.annotations.push({ type: 'finding', description: `MetaMask showed ${log.warnings.length} warning line(s) (${step})` });
  log.clicks.length = 0;
  log.warnings.length = 0;
}

test.describe.serial('live Sepolia', () => {
  // The redacted failure dump (failure-dump.ts): what the site's page said while the test ran.
  let dump = new FailureDump();
  test.beforeEach(async ({ page }) => {
    dump = new FailureDump();
    dump.watch(page);
  });

  test.afterEach(async ({ page }, info) => {
    if (info.status !== info.expectedStatus) {
      // Fixed fields only (failure-dump.ts): no text from the page, the wallet or an RPC provider.
      await dump.print(page).catch(() => console.log('[live] failure dump: page read failed'));
      await blockProbe(page).catch(() => console.log('[live] latest block: probe failed'));
    }
  });

  test('live Sepolia: sign in, set up, fund, pay', async ({ page, context, extensionId }, info) => {
    const gid = Number(process.env.LN_LIVE_GROUP);
    expect(Number.isInteger(gid) && gid > 0, 'LN_LIVE_GROUP is the group id seed-live.php printed').toBe(true);
    const net = sepoliaEntry();
    const log: PromptLog = { clicks: [], warnings: [] };
    const driver = metaMaskDriver(context, extensionId, log);

    // Normally 16 to 32 s from test start (runs 42510466003, 42614533731, 42810302102,
    // 43012024391); 2 minutes leaves room for a slow MetaMask without eating the test budget.
    await test.step('SIWE sign-in', async () => {
      await signInWithMetaMask(page, context, extensionId, log);
      reportPrompts(info, 'sign-in', log);
    }, { timeout: 120_000 });

    const wallet = await test.step('the wallet and its network', async () => {
      const accounts = (await page.evaluate(() =>
        (window as unknown as { ethereum: { request(a: unknown): Promise<string[]> } }).ethereum.request({ method: 'eth_accounts' }),
      ).catch(fixed)) as string[];
      expect(accounts.length, 'MetaMask shares one account with the site').toBeGreaterThan(0);
      const address = toChecksumAddress(accounts[0]);
      const expected = process.env.LN_LIVE_WALLET_ADDRESS;
      if (expected) {
        expect(address.toLowerCase(), 'the imported seed is the wallet seed-live.php linked to ci-organizer').toBe(expected.toLowerCase());
      }
      const chain = await switchSiteToSepolia(page, context, extensionId, log);
      reportPrompts(info, 'switch network', log);
      expect(chain, 'MetaMask has the site on Ethereum Sepolia').toBe(SEPOLIA_HEX);
      const balance = await balanceWei(address);
      console.log(`[live] wallet ${address} holds ${formatUnits(balance, 18, 'ETH')}`);
      // Enough for the deploy, the funding and the send, with room (README: top up when low).
      expect(balance, 'the throwaway wallet has Sepolia ETH (top it up from a faucet)').toBeGreaterThan(5n * 10n ** 15n);
      return address;
    });

    const safe = await test.step('set up the group fund (threshold 1)', async () => {
      await setUpGroupFund(page, gid, { threshold: 1, driver, network: net });
      await expect(receiptPanel(page).getByText('The group fund is ready.')).toBeVisible({ timeout: 300_000 });
      reportPrompts(info, 'set up', log);
      await openFund(page, gid);
      const id = (await accountId(page)).trim();
      expect(id).toMatch(/^0x[0-9a-fA-F]{40}$/);
      console.log(`[live] group fund ${id}`);
      return toChecksumAddress(id);
    });

    await test.step('fund it from the wallet (0.0002 ETH)', async () => {
      let done = false;
      const send = page
        .evaluate(
          ({ from, to, value }) =>
            (window as unknown as { ethereum: { request(a: unknown): Promise<string> } }).ethereum.request({
              method: 'eth_sendTransaction',
              params: [{ from, to, value }],
            }),
          { from: wallet, to: safe, value: '0x' + FUND_WEI.toString(16) },
        )
        .catch(fixed)
        .finally(() => { done = true; });
      const [hash] = await Promise.all([send, confirmPrompts(context, extensionId, 'send', log, { done: () => done, timeoutMs: 180_000 })]);
      reportPrompts(info, 'fund', log);
      console.log(`[live] funding transaction ${txHashText(hash)}`);
      await waitForReceipt(hash as string);
    });

    const paymentUrl = await test.step('propose 0.0001 ETH to the wallet', async () => {
      const url = await proposePayment(page, gid, {
        to: wallet,
        amountText: PAY_TEXT,
        description: `Nightly live check ${new Date().toISOString().slice(0, 10)}`,
      });
      await expect(page.getByText('Payment proposed. Organizers can now approve it.')).toBeVisible();
      expect(await approvalsSentence(page)).toBe('Approved by 0 of 1. Needs 1.');
      return url;
    });

    await test.step('approve', async () => {
      const panelText = await approvePayment(page, paymentUrl, driver);
      reportPrompts(info, 'approve', log);
      expect(panelText).toContain('You approved this payment.');
      expect(panelText).toContain('Approved by 1 of 1.');
    });

    await test.step('send', async () => {
      await sendPayment(page, paymentUrl, driver);
      reportPrompts(info, 'send', log);
      await expect(paymentPanel(page)).toContainText('Payment sent.', { timeout: 300_000 });
      await expect
        .poll(async () => {
          await page.reload();
          await page.waitForLoadState('load');
          return paymentStatus(page);
        }, { timeout: 300_000, intervals: [15_000] })
        .toBe('Sent');
    });

    await test.step('balances: the page shows what eth_getBalance says', async () => {
      // The fund keeps 0.0002 - 0.0001 ETH: the wallet paid the gas, not the fund.
      await expect.poll(() => balanceWei(safe), { timeout: 180_000, intervals: [10_000] }).toBe(FUND_WEI - PAY_WEI);
      await openFund(page, gid);
      // 21-15: a refresh within 15 s of the last read attempt is skipped; wait it out.
      await page.waitForTimeout(16_000);
      await refreshBalances(page);
      const wei = await balanceWei(safe);
      const expected = formatUnits(wei, net.native_currency.decimals, net.native_currency.symbol);
      const shown = await balanceText(page, net.native_currency.name);
      console.log(`[live] balance: page "${shown}", eth_getBalance ${wei} wei ("${expected}")`);
      expect(shown).toBe(expected);
      expect(shown).toBe(`${PAY_TEXT} ${net.native_currency.symbol}`);
    });
  });
});
