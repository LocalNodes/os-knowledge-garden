// Builds the governance judge's MetaMask profile cache (25-27). CI only: governance-judge.yml
// runs it with the two wallet secrets in this one step's environment.
//
// The same build as cache-live.ts (21-45 Task B), which this file reuses and doesn't change:
// its pinned MetaMask version and zip sha256, its browser arguments (no host block), and its
// rules (nothing from the pages is logged or saved; URLs and error first lines only). The
// differences: the wallet setup is wallet-setup/live/governance.setup.ts (accounts 1 to 3), the
// cache is .cache-synpress/live-governance, and the three accounts' public addresses are
// appended to $GITHUB_ENV as LN_ACCOUNT_1 to LN_ACCOUNT_3 for the seed and the judge steps.
//
// Run: xvfb-run npm --prefix tests/e2e-live run cache:governance
import { appendFileSync, existsSync, readFileSync, rmSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { join } from 'node:path';
import { chromium } from '@playwright/test';
import { CACHE_DIR_NAME, DEFAULT_METAMASK_VERSION, prepareExtension } from '@synthetixio/synpress-cache';
import { getExtensionId } from '@synthetixio/synpress/playwright';
import { METAMASK_VERSION, METAMASK_ZIP_SHA256, liveBrowserArgs } from './cache-live.ts';
import setup, { publicAddresses } from './wallet-setup/live/governance.setup.ts';

/** Where the governance wallet's MetaMask profile is cached (gitignored .cache-synpress). */
export const GOVERNANCE_CACHE_NAME = 'live-governance';

async function main(): Promise<void> {
  if (process.env.GITHUB_ACTIONS !== 'true') {
    throw new Error('live suite refused: CI only (the governance wallet cache is built only in the judge workflow)');
  }
  if (DEFAULT_METAMASK_VERSION !== METAMASK_VERSION) {
    throw new Error(`cache-governance: Synpress pins MetaMask ${DEFAULT_METAMASK_VERSION}, not the recorded ${METAMASK_VERSION}`);
  }
  const cacheRoot = join(process.cwd(), CACHE_DIR_NAME);
  const extensionPath = await prepareExtension();
  const zip = join(cacheRoot, `metamask-chrome-${DEFAULT_METAMASK_VERSION}.zip`);
  if (!existsSync(zip)) throw new Error(`cache-governance: no extension zip at ${zip}`);
  const sha256 = createHash('sha256').update(readFileSync(zip)).digest('hex');
  console.log(`LN_METAMASK_ZIP_SHA256=${sha256}`);
  if (sha256 !== METAMASK_ZIP_SHA256) {
    throw new Error(`cache-governance: the MetaMask zip's sha256 is ${sha256}, not the recorded ${METAMASK_ZIP_SHA256}`);
  }

  const profile = join(cacheRoot, GOVERNANCE_CACHE_NAME);

  async function buildOnce(): Promise<void> {
    rmSync(profile, { recursive: true, force: true });
    const context = await chromium.launchPersistentContext(profile, { headless: false, args: liveBrowserArgs(extensionPath) });
    try {
      const extensionId = await getExtensionId(context, 'MetaMask');
      let walletPage = context.pages().find((p) => p.url().startsWith(`chrome-extension://${extensionId}/`));
      if (!walletPage) {
        walletPage = await context.newPage();
        await walletPage.goto(`chrome-extension://${extensionId}/home.html`);
      }
      await walletPage.waitForLoadState('domcontentloaded');
      await walletPage.waitForTimeout(3_000);
      for (const p of context.pages()) {
        if (p !== walletPage && p.url().startsWith(`chrome-extension://${extensionId}/`)) await p.close();
      }
      await walletPage.bringToFront();
      try {
        await setup.fn(context, walletPage);
      } catch (err) {
        // URLs only: page text or a screenshot could show the phrase.
        for (const [i, p] of context.pages().entries()) console.log(`LN_METAMASK_FAILURE_PAGE=${i} ${p.url().replace(/\?.*$/, '')}`);
        throw err;
      }
      await walletPage.waitForTimeout(3_000);
      console.log(`LN_METAMASK_CACHE=${profile}`);
    } finally {
      await context.close();
    }
  }

  for (let attempt = 1; ; attempt++) {
    try {
      console.log(`LN_METAMASK_CACHE_ATTEMPT=${attempt}`);
      await buildOnce();
      break;
    } catch (err) {
      const first = (err as Error).message.split('\n')[0].slice(0, 300);
      console.log(`LN_METAMASK_CACHE_ATTEMPT_FAILED=${attempt} ${first}`);
      if (attempt >= 3) throw new Error(`cache-governance: the wallet setup failed 3 times; last: ${first}`);
    }
  }

  // Public addresses only, for the seed and the judge steps.
  const addresses = publicAddresses();
  const lines = addresses.map((address, i) => `LN_ACCOUNT_${i + 1}=${address}`);
  for (const line of lines) console.log(line);
  const env = process.env.GITHUB_ENV;
  if (!env) throw new Error('cache-governance: GITHUB_ENV is not set');
  appendFileSync(env, lines.join('\n') + '\n');
}

// Run only as a script (the judge imports GOVERNANCE_CACHE_NAME).
if (import.meta.url === `file://${process.argv[1]}`) {
  await main();
}
