// Builds the local MetaMask profile cache for the recording (21-45 Task A).
//
// Synpress's own cache CLI launches Chromium with fixed arguments, so MetaMask would run the
// wallet import and network set-up with no host block. This script does the same job with
// Synpress's exported pieces and our arguments (browser-args.ts), without patching
// node_modules:
//   1. prepareExtension() downloads MetaMask's pinned release zip (Synpress's
//      DEFAULT_METAMASK_VERSION, from MetaMask's GitHub releases) into .cache-synpress and
//      unzips it. The script prints its version, URL and sha256.
//   2. A persistent Chromium profile in .cache-synpress/local-anvil loads the extension behind
//      the host block and runs wallet-setup/local/anvil-local.setup.ts.
//
// Run from tests/e2e-live (npm --prefix tests/e2e-live run cache:local), headed under xvfb-run,
// with the base_sepolia suite chain up: MetaMask checks the chain id over the RPC when the
// network is added.
import { createHash } from 'node:crypto';
import { existsSync, readFileSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { chromium } from '@playwright/test';
import { CACHE_DIR_NAME, DEFAULT_METAMASK_VERSION, EXTENSION_DOWNLOAD_URL, prepareExtension } from '@synthetixio/synpress-cache';
import { getExtensionId } from '@synthetixio/synpress/playwright';
import { LOCAL_CACHE_NAME, metaMaskBrowserArgs } from './browser-args.ts';
import setup from './wallet-setup/local/anvil-local.setup.ts';

const cacheRoot = join(process.cwd(), CACHE_DIR_NAME);
const extensionPath = await prepareExtension();
const zip = join(cacheRoot, `metamask-chrome-${DEFAULT_METAMASK_VERSION}.zip`);
if (!existsSync(zip)) throw new Error(`cache-local: no extension zip at ${zip}`);
const sha256 = createHash('sha256').update(readFileSync(zip)).digest('hex');
console.log(`LN_METAMASK_VERSION=${DEFAULT_METAMASK_VERSION}`);
console.log(`LN_METAMASK_URL=${EXTENSION_DOWNLOAD_URL}`);
console.log(`LN_METAMASK_ZIP_SHA256=${sha256}`);

const profile = join(cacheRoot, LOCAL_CACHE_NAME);

/**
 * One attempt on a fresh profile. Synpress types the phrase into MetaMask's 12 word fields
 * with a 10 ms key delay, and about one build in three a letter is lost when focus moves to
 * the next field (word 2 read "est"). The phrase is then refused and the attempt fails; a
 * new attempt starts from an empty profile. Three attempts at most.
 */
async function buildOnce(): Promise<void> {
  rmSync(profile, { recursive: true, force: true });
  const context = await chromium.launchPersistentContext(profile, {
    headless: false,
    args: metaMaskBrowserArgs(extensionPath),
  });
  try {
    const extensionId = await getExtensionId(context, 'MetaMask');
    console.log(`LN_METAMASK_EXTENSION_ID=${extensionId}`);
    // MetaMask opens its onboarding tab itself; use it, or open home.html.
    let walletPage = context.pages().find((p) => p.url().startsWith(`chrome-extension://${extensionId}/`));
    if (!walletPage) {
      walletPage = await context.newPage();
      await walletPage.goto(`chrome-extension://${extensionId}/home.html`);
    }
    await walletPage.waitForLoadState('domcontentloaded');
    // MetaMask sometimes opens a second onboarding tab. Keep only this one, so the setup
    // types into the tab in front.
    await walletPage.waitForTimeout(3_000);
    for (const p of context.pages()) {
      if (p !== walletPage && p.url().startsWith(`chrome-extension://${extensionId}/`)) await p.close();
    }
    await walletPage.bringToFront();
    // MetaMask's own console errors and failed requests (the host block makes some fail by
    // design), for the report.
    walletPage.on('console', (m) => { if (m.type() === 'error') console.log(`LN_METAMASK_CONSOLE ${m.text().slice(0, 300)}`); });
    walletPage.on('requestfailed', (r) => console.log(`LN_METAMASK_REQUEST_FAILED ${r.url().slice(0, 200)} ${r.failure()?.errorText ?? ''}`));
    try {
      await setup.fn(context, walletPage);
    } catch (err) {
      // Keep what MetaMask showed, for the report (gitignored .cache-synpress).
      for (const [i, p] of context.pages().entries()) {
        const shot = join(cacheRoot, `setup-failure-${i}.png`);
        await p.screenshot({ path: shot }).catch(() => undefined);
        console.log(`LN_METAMASK_FAILURE_PAGE=${i} ${p.url()} ${shot}`);
        console.log((await p.locator('body').innerText().catch(() => '')).slice(0, 1500));
      }
      throw err;
    }
    // Let MetaMask persist its state before the profile closes (Synpress waits 3 s too).
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
    console.log(`LN_METAMASK_CACHE_ATTEMPT_FAILED=${attempt} ${(err as Error).message.split('\n')[0]}`);
    if (attempt >= 3) throw err;
  }
}
