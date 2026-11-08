// Builds the live suite's MetaMask profile cache (21-45 Task B). CI only: the nightly workflow
// runs it with the two wallet secrets in this one step's environment.
//
// Same approach as cache-local.ts (Task A), for the same reason: our own browser launch around
// Synpress's exported pieces, never a patched node_modules. Differences:
//   - no host block: this browser has to reach live Sepolia;
//   - the extension zip's sha256 must equal the one Task A recorded (e2e-evidence/SYNPRESS.md),
//     so a changed MetaMask download fails the build instead of running;
//   - nothing from the pages is logged or saved (no body text, no screenshot): the import
//     screen holds the seed phrase. Only page URLs and error first lines are printed.
//
// Run: xvfb-run npm --prefix tests/e2e-live run cache (needs SYNPRESS_SEED_PHRASE and
// SYNPRESS_WALLET_PASSWORD; wallet-setup/live/live.setup.ts reads them).
import { createHash } from 'node:crypto';
import { existsSync, readFileSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { chromium } from '@playwright/test';
import { CACHE_DIR_NAME, DEFAULT_METAMASK_VERSION, EXTENSION_DOWNLOAD_URL, prepareExtension } from '@synthetixio/synpress-cache';
import { getExtensionId } from '@synthetixio/synpress/playwright';
import setup from './wallet-setup/live/live.setup.ts';

/** The MetaMask release Task A recorded with (synpress-cache 0.0.14's pinned version). */
export const METAMASK_VERSION = '13.13.1';
export const METAMASK_ZIP_SHA256 = '2a38fc72d6072d4ae0bdb460840eb6f8a1e916f70a6510f8e1728a79ea9bd747';
/** Where the live wallet's MetaMask profile is cached (gitignored .cache-synpress). */
export const LIVE_CACHE_NAME = 'live-sepolia';

/** The browser arguments for the live suite: the extension only, no host block. */
export function liveBrowserArgs(extensionPath: string): string[] {
  return [`--disable-extensions-except=${extensionPath}`, `--load-extension=${extensionPath}`];
}

async function main(): Promise<void> {
  if (process.env.GITHUB_ACTIONS !== 'true') {
    throw new Error('live suite refused: CI only (the live wallet cache is built only in the nightly workflow)');
  }
  if (DEFAULT_METAMASK_VERSION !== METAMASK_VERSION) {
    throw new Error(`cache-live: Synpress pins MetaMask ${DEFAULT_METAMASK_VERSION}, not the recorded ${METAMASK_VERSION}`);
  }
  const cacheRoot = join(process.cwd(), CACHE_DIR_NAME);
  const extensionPath = await prepareExtension();
  const zip = join(cacheRoot, `metamask-chrome-${DEFAULT_METAMASK_VERSION}.zip`);
  if (!existsSync(zip)) throw new Error(`cache-live: no extension zip at ${zip}`);
  const sha256 = createHash('sha256').update(readFileSync(zip)).digest('hex');
  console.log(`LN_METAMASK_VERSION=${DEFAULT_METAMASK_VERSION}`);
  console.log(`LN_METAMASK_URL=${EXTENSION_DOWNLOAD_URL}`);
  console.log(`LN_METAMASK_ZIP_SHA256=${sha256}`);
  if (sha256 !== METAMASK_ZIP_SHA256) {
    throw new Error(`cache-live: the MetaMask zip's sha256 is ${sha256}, not the recorded ${METAMASK_ZIP_SHA256}`);
  }

  const profile = join(cacheRoot, LIVE_CACHE_NAME);

  /** One attempt on a fresh profile (Synpress sometimes drops a letter while typing; cache-local.ts). */
  async function buildOnce(): Promise<void> {
    rmSync(profile, { recursive: true, force: true });
    const context = await chromium.launchPersistentContext(profile, {
      headless: false,
      args: liveBrowserArgs(extensionPath),
    });
    try {
      const extensionId = await getExtensionId(context, 'MetaMask');
      console.log(`LN_METAMASK_EXTENSION_ID=${extensionId}`);
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
        for (const [i, p] of context.pages().entries()) {
          console.log(`LN_METAMASK_FAILURE_PAGE=${i} ${p.url().replace(/\?.*$/, '')}`);
        }
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
      // The first line only: a full Playwright call log could echo what was typed.
      if (attempt >= 3) throw new Error(`cache-live: the wallet setup failed 3 times; last: ${first}`);
    }
  }
}

// Run only as a script (the spec imports the constants above).
if (import.meta.url === `file://${process.argv[1]}`) {
  await main();
}
