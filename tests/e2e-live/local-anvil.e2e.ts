// The local MetaMask recording (21-45 Task A, ruling R-D).
//
// Real MetaMask 13.13.1, driven by Synpress 4.1.2, goes through the kept flows on the local
// anvil chain with chain id 84532 (base_sepolia), on https://fresh3.test only: SIWE sign-in,
// group fund set-up from the group tab, propose, approve (organizer), approve (approver) and
// send. Every test records a video of every page (the site and MetaMask's own prompts) and a
// trace. The page steps are 21-42/21-43's helpers (tests/e2e/helpers/treasury.ts), through their
// WalletDriver seam, so this suite clicks the same screens as the injected-wallet suite.
//
// Which steps are Synpress's and which are ours (orchestrator ruling, path 2): Synpress
// downloads the extension (prepareExtension), finds its id (getExtensionId) and imports the
// wallet (in the setup). Our steps (metamask-steps.ts) unlock it, switch accounts and confirm
// every prompt (connect, SIWE signature, typed-data approvals, transactions), because Synpress
// 4.1.2's selectors don't match MetaMask 13.13.1's screens.
//
// The browser is launched here, not by Synpress's metaMaskFixtures: its context fixture
// ignores launch arguments and the video option. This one copies the cached profile
// (cache-local.ts), loads the same extension, and adds the host block (browser-args.ts), a
// video and a trace. It never patches node_modules.
//
// Keys: only anvil's public dev mnemonic (wallet-setup/local/anvil-local.setup.ts), only on
// the loopback anvil chain. Nothing here signs on Sepolia.
import { cpSync, existsSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium, expect, test as base, type BrowserContext, type Page, type TestInfo } from '@playwright/test';
import { CACHE_DIR_NAME, prepareExtension } from '@synthetixio/synpress-cache';
import { getExtensionId } from '@synthetixio/synpress/playwright';
import { ACCOUNT_DEV1, HOST_RESOLVER_RULES, LOCAL_CACHE_NAME, metaMaskBrowserArgs } from './browser-args';
import { LOCAL_WALLET_PASSWORD } from './wallet-setup/local/anvil-local.setup';
import { confirmPrompts, openHome, switchAccount, type PromptLog } from './metamask-steps';
import { devAccounts, toChecksumAddress } from '../e2e/wallet/test-wallet';
import { e2eRun, ethBalance, registry, safeNonce, sendEth, type E2eRun, type RegistryEntry } from '../e2e/helpers/chain';
import {
  approvalsSentence,
  approvePayment,
  paymentPanel,
  paymentStatus,
  proposePayment,
  receiptPanel,
  safeAddressForGroup,
  seedRunGroups,
  sendPayment,
  setUpGroupFund,
  type RunGroups,
  type WalletDriver,
} from '../e2e/helpers/treasury';

interface MetaMaskFixtures {
  extensionId: string;
  metamaskPage: Page;
}

const HERE = dirname(fileURLToPath(import.meta.url));

/**
 * MetaMask service calls answered locally (see e2e-evidence/SYNPRESS.md for each shape's
 * source in the 13.13.1 bundle).
 * - tx-sentinel /networks: read by app/scripts/lib/transaction/sentinel-api.ts
 *   (isSendBundleSupported, getSentinelNetworkFlags) in the publish hook after Confirm, and by
 *   transaction-controller's simulation-api. It's an object keyed by decimal chain id; `{}`
 *   means "no sentinel features on any chain": sendBundle false, no relay, no simulation, so
 *   MetaMask publishes through the network's own RPC.
 */
const METAMASK_STUBS = [
  { url: 'https://tx-sentinel-ethereum-mainnet.api.cx.metamask.io/networks', file: 'tx-sentinel-networks.json' },
];

const wallets = new WeakMap<BrowserContext, { extensionId: string; page: Page }>();

const test = base.extend<MetaMaskFixtures>({
  context: async ({}, use, testInfo) => {
    const cache = join(process.cwd(), CACHE_DIR_NAME, LOCAL_CACHE_NAME);
    if (!existsSync(cache)) throw new Error(`no MetaMask cache at ${cache}: npm --prefix tests/e2e-live run cache:local`);
    const profile = mkdtempSync(join(tmpdir(), 'ln-e2e-live-'));
    cpSync(cache, profile, { recursive: true });
    const extensionPath = await prepareExtension();
    const videoDir = testInfo.outputPath('videos');
    const context = await chromium.launchPersistentContext(profile, {
      headless: false,
      args: metaMaskBrowserArgs(extensionPath),
      baseURL: testInfo.project.use.baseURL,
      ignoreHTTPSErrors: true,
      recordVideo: { dir: videoDir, size: { width: 1280, height: 720 } },
      viewport: { width: 1280, height: 720 },
    });
    // A persistent context does not take the config's action and navigation limits; set them here.
    context.setDefaultTimeout(testInfo.project.use.actionTimeout ?? 60_000);
    context.setDefaultNavigationTimeout(testInfo.project.use.navigationTimeout ?? 60_000);
    // Diagnostics (21-45 MetaMask set-up RCA): every request and failure from pages and, with
    // PW_EXPERIMENTAL_SERVICE_WORKER_NETWORK_EVENTS=1, from MetaMask's service worker; and
    // every console line. Written next to the trace, pass or fail.
    const diag: string[] = [];
    const stamp = () => new Date().toISOString().slice(11, 23);
    context.on('request', (r) => {
      // For the local chain, also the JSON-RPC method names in the body.
      let methods = '';
      if (r.url().startsWith('http://localhost:18546')) {
        try {
          const body = JSON.parse(r.postData() ?? 'null');
          methods = ' ' + (Array.isArray(body) ? body : [body]).map((b) => b?.method).join(',');
        } catch {
          methods = ' (body not JSON)';
        }
      }
      diag.push(`${stamp()} REQ ${r.method()} ${r.url().slice(0, 300)}${methods}`);
    });
    context.on('requestfailed', (r) => diag.push(`${stamp()} FAIL ${r.method()} ${r.url().slice(0, 300)} ${r.failure()?.errorText ?? ''}`));
    context.on('console', (m) => diag.push(`${stamp()} CONSOLE ${m.type()} ${m.location().url.slice(0, 80)} ${m.text().slice(0, 2000)}`));
    // Local stubs for MetaMask services that a step can't do without (orchestrator ruling,
    // 2026-11-07). The host block stays: these answer in the browser, nothing leaves the box.
    // Service-worker requests are routable with PW_EXPERIMENTAL_SERVICE_WORKER_NETWORK_EVENTS=1
    // (set in local.config.ts). Each hit is logged in diagnostics.txt.
    for (const stub of METAMASK_STUBS) {
      await context.route(stub.url, async (route) => {
        diag.push(`${stamp()} STUB ${route.request().method()} ${route.request().url()} -> ${stub.file}`);
        await route.fulfill({ status: 200, contentType: 'application/json', body: readFileSync(join(HERE, 'stubs', stub.file), 'utf8') });
      });
    }
    // Playwright may already trace this context (trace: 'on'); start our own only if not.
    let ownTrace = false;
    try {
      await context.tracing.start({ screenshots: true, snapshots: true, sources: true });
      ownTrace = true;
    } catch {
      // Already tracing: Playwright writes the trace itself.
    }
    try {
      const extensionId = await getExtensionId(context, 'MetaMask');
      const page = context.pages()[0] ?? (await context.newPage());
      await openHome(page, extensionId, LOCAL_WALLET_PASSWORD);
      wallets.set(context, { extensionId, page });
      await use(context);
    } finally {
      if (ownTrace) {
        const trace = testInfo.outputPath('trace.zip');
        await context.tracing.stop({ path: trace }).catch(() => undefined);
        if (existsSync(trace)) await testInfo.attach('trace', { path: trace, contentType: 'application/zip' });
      }
      await context.close();
      const diagFile = testInfo.outputPath('diagnostics.txt');
      writeFileSync(diagFile, diag.join('\n') + '\n');
      await testInfo.attach('diagnostics', { path: diagFile, contentType: 'text/plain' });
      if (existsSync(videoDir)) {
        for (const f of readdirSync(videoDir).filter((n) => n.endsWith('.webm'))) {
          await testInfo.attach(`video ${f}`, { path: join(videoDir, f), contentType: 'video/webm' });
        }
      }
      rmSync(profile, { recursive: true, force: true });
    }
  },
  extensionId: async ({ context }, use) => {
    await use(wallets.get(context)!.extensionId);
  },
  metamaskPage: async ({ context }, use) => {
    await use(wallets.get(context)!.page);
  },
});

/** Facts per step, written next to the evidence (results-synpress.json in the run folder). */
const facts: Array<Record<string, unknown>> = [];
function note(info: TestInfo, step: string, data: Record<string, unknown>): void {
  facts.push({ step, at: new Date().toISOString(), ...data });
  writeFileSync(join(info.project.outputDir, 'results-synpress.json'), JSON.stringify(facts, null, 2) + '\n');
}

/** The MetaMask WalletDriver: confirms whatever MetaMask asks for this step (metamask-steps.ts). */
function metaMaskDriver(context: BrowserContext, extensionId: string, log: PromptLog): WalletDriver {
  return {
    async confirm(kind) {
      await confirmPrompts(context, extensionId, kind, log);
    },
  };
}

/** SIWE through the site's own button: MetaMask connects, then signs; wait for the signed-in page. */
async function signInWithMetaMask(page: Page, context: BrowserContext, extensionId: string, log: PromptLog, name: RegExp): Promise<void> {
  await page.goto('/user/login');
  const button = page.locator('#siwe-login-button');
  await button.waitFor({ state: 'visible' });
  let navigated = false;
  const nav = page
    .waitForEvent('framenavigated', { predicate: (f) => f === page.mainFrame(), timeout: 180_000 })
    .then(() => { navigated = true; }, () => undefined);
  await button.click();
  await confirmPrompts(context, extensionId, 'signIn', log, { done: () => navigated, timeoutMs: 180_000 });
  await nav;
  await page.waitForLoadState('load');
  await expect(page.locator('#siwe-login-button')).toHaveCount(0);
  await expect(page.getByRole('button', { name })).toBeVisible();
}

/** Writes the step's prompt log as an attachment and returns its facts. */
async function prompts(info: TestInfo, log: PromptLog): Promise<Record<string, unknown>> {
  await info.attach('metamask prompts', { body: JSON.stringify(log, null, 2), contentType: 'application/json' });
  return { metamaskClicks: log.clicks.map((c) => `${c.kind}: ${c.button}`), metamaskWarnings: log.warnings };
}

const DEV0_NAME = /Wren Organizer/;
const DEV1_NAME = /Linden Approver/;

let run: E2eRun;
let net: RegistryEntry;
let groups: RunGroups;
let devs: string[] = [];
let fund = '';
let paymentUrl = '';

const FUND_WEI = 10n ** 16n;
const PAY_WEI = 10n ** 15n;

test.describe.serial('MetaMask on the local 84532 chain', () => {
  test.beforeAll(async () => {
    run = e2eRun();
    expect(run.network).toBe('base_sepolia');
    net = await registry(run.network);
    expect(Number(net.chain_id)).toBe(84532);
    devs = await devAccounts(run.network);
    groups = seedRunGroups(`${run.runId}-mm`, run.network);
  });

  test('host block', async ({ page }, info) => {
    await page.goto('/');
    const probe = await page.evaluate(async () => {
      const out: Record<string, string> = {};
      try {
        const r = await fetch('https://sepolia.infura.io/', { method: 'GET', mode: 'no-cors' });
        out.infura = `reached (type ${r.type})`;
      } catch (err) {
        out.infura = `failed: ${(err as Error).message}`;
      }
      try {
        const r = await fetch('https://cdn.jsdelivr.net/npm/ethers@6.16.0/dist/ethers.umd.min.js');
        out.cdn = `status ${r.status}, ${(await r.text()).length} bytes`;
      } catch (err) {
        out.cdn = `failed: ${(err as Error).message}`;
      }
      return out;
    });
    note(info, 'host block', { rules: HOST_RESOLVER_RULES, ...probe });
    expect(probe.infura).toMatch(/^failed/);
    expect(probe.cdn).toMatch(/^status 200/);
  });

  test('SIWE sign-in (dev 0)', async ({ page, context, extensionId }, info) => {
    const log: PromptLog = { clicks: [], warnings: [] };
    await signInWithMetaMask(page, context, extensionId, log, DEV0_NAME);
    note(info, 'SIWE sign-in (dev 0)', { url: page.url(), ...(await prompts(info, log)) });
  });

  test('set up (MetaMask)', async ({ page, context, extensionId }, info) => {
    const log: PromptLog = { clicks: [], warnings: [] };
    await signInWithMetaMask(page, context, extensionId, log, DEV0_NAME);
    await setUpGroupFund(page, groups.fund, {
      threshold: 2,
      driver: metaMaskDriver(context, extensionId, log),
      network: net,
    });
    await expect(receiptPanel(page).getByText('The group fund is ready.')).toBeVisible({ timeout: 180_000 });
    const row = safeAddressForGroup(groups.fund);
    expect(row?.status).toBe('active');
    fund = toChecksumAddress(row!.safe_address);
    const tx = await sendEth(run.network, 0, fund, FUND_WEI);
    note(info, 'set up (MetaMask)', {
      group: groups.fund,
      fund,
      deploymentTx: row!.deployment_tx_hash,
      fundTx: tx.transactionHash,
      balance: String(ethBalance(run.network, fund)),
      ...(await prompts(info, log)),
    });
  });

  test('propose (MetaMask)', async ({ page, context, extensionId }, info) => {
    expect(fund, 'the set-up test made the fund').toMatch(/^0x[0-9a-fA-F]{40}$/);
    const log: PromptLog = { clicks: [], warnings: [] };
    await signInWithMetaMask(page, context, extensionId, log, DEV0_NAME);
    paymentUrl = await proposePayment(page, groups.fund, {
      to: devs[3],
      amountText: '0.001',
      description: `E2E MetaMask payment ${run.runId}`,
    });
    // As 21-43's propose test: the confirmation message, then the approvals sentence (the
    // flow panel is empty until someone approves).
    await expect(page.getByText('Payment proposed. Organizers can now approve it.')).toBeVisible();
    expect(await approvalsSentence(page)).toBe('Approved by 0 of 2. Needs 2.');
    note(info, 'propose (MetaMask)', { url: paymentUrl, ...(await prompts(info, log)) });
  });

  test('approve (organizer, MetaMask)', async ({ page, context, extensionId }, info) => {
    expect(paymentUrl, 'the propose test made the payment').not.toBe('');
    const log: PromptLog = { clicks: [], warnings: [] };
    await signInWithMetaMask(page, context, extensionId, log, DEV0_NAME);
    const panelText = await approvePayment(page, paymentUrl, metaMaskDriver(context, extensionId, log));
    expect(panelText).toContain('You approved this payment.');
    expect(panelText).toContain('Approved by 1 of 2.');
    note(info, 'approve (organizer, MetaMask)', { url: paymentUrl, ...(await prompts(info, log)) });
  });

  test('approve (approver, MetaMask)', async ({ page, context, extensionId, metamaskPage }, info) => {
    expect(paymentUrl, 'the propose test made the payment').not.toBe('');
    const log: PromptLog = { clicks: [], warnings: [] };
    await switchAccount(metamaskPage, ACCOUNT_DEV1);
    await signInWithMetaMask(page, context, extensionId, log, DEV1_NAME);
    const panelText = await approvePayment(page, paymentUrl, metaMaskDriver(context, extensionId, log));
    expect(panelText).toContain('Approved by 2 of 2.');
    note(info, 'approve (approver, MetaMask)', { url: paymentUrl, ...(await prompts(info, log)) });
  });

  test('send (MetaMask)', async ({ page, context, extensionId }, info) => {
    expect(paymentUrl, 'the propose test made the payment').not.toBe('');
    const log: PromptLog = { clicks: [], warnings: [] };
    const nonceBefore = safeNonce(run.network, fund);
    const recipientBefore = ethBalance(run.network, devs[3]);
    await signInWithMetaMask(page, context, extensionId, log, DEV0_NAME);
    await sendPayment(page, paymentUrl, metaMaskDriver(context, extensionId, log));
    await expect(paymentPanel(page)).toContainText('Payment sent.', { timeout: 180_000 });
    await page.reload();
    await page.waitForLoadState('load');
    const status = await paymentStatus(page);
    const nonceAfter = safeNonce(run.network, fund);
    const recipientAfter = ethBalance(run.network, devs[3]);
    note(info, 'send (MetaMask)', {
      url: paymentUrl,
      status,
      nonceBefore,
      nonceAfter,
      received: String(recipientAfter - recipientBefore),
      ...(await prompts(info, log)),
    });
    expect(status).toBe('Sent');
    expect(nonceAfter).toBe(nonceBefore + 1);
    expect(recipientAfter - recipientBefore).toBe(PAY_WEI);
  });
});
