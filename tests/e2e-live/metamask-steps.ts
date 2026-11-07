// Our own MetaMask 13.13.1 steps for the local recording (21-45 Task A, orchestrator ruling
// path 2, 2026-11-07).
//
// Synpress 4.1.2's helpers for these screens target an older MetaMask UI:
// - accounts: it waits for the account popover; 13.13.1 shows the full-page Accounts list. Its
//   multichain "state 2" UI is always on: isMultichainAccountsFeatureEnabled() returns true for
//   feature version 2 whatever the remote flags say (shared/lib/multichain-accounts/
//   remote-feature-flag.ts in the bundle, common-12.js), so answering the flag request can't
//   bring the popover back;
// - networks: its network dropdown selectors are gone;
// - prompts: it clicks `page-container-footer-next` and the old signature buttons; 13.13.1's
//   confirmations use `confirm-btn` (connect) and `confirm-footer-button`.
// Synpress still does the extension download, the wallet import and the extension id. These
// steps use MetaMask's own data-testid values from the 13.13.1 bundle. No step reads page
// state with page.evaluate: LavaMoat's scuttling blocks it on extension pages.
import { expect, type BrowserContext, type Locator, type Page } from '@playwright/test';

/** The wallet's home page, unlocked. */
export async function openHome(page: Page, extensionId: string, password: string): Promise<void> {
  await page.goto(`chrome-extension://${extensionId}/home.html#`);
  const unlock = page.getByTestId('unlock-password');
  const menu = page.getByTestId('account-menu-icon');
  await expect(unlock.or(menu).first()).toBeVisible({ timeout: 60_000 });
  if (await unlock.isVisible()) {
    await unlock.fill(password);
    await page.getByTestId('unlock-submit').click();
  }
  await expect(menu).toBeVisible({ timeout: 60_000 });
  await page.keyboard.press('Escape');
}

/** Adds the next account ("Account 2") from the Accounts list, then returns home. */
export async function addAccount(page: Page, extensionId: string, expectedName: string): Promise<void> {
  await page.getByTestId('account-menu-icon').click();
  await page.getByTestId('add-multichain-account-button').click();
  await expect(page.getByText(expectedName, { exact: true }).first()).toBeVisible({ timeout: 30_000 });
  await page.goto(`chrome-extension://${extensionId}/home.html#`);
  await expect(page.getByTestId('account-menu-icon')).toBeVisible({ timeout: 30_000 });
}

/** Selects an account by its name in the Accounts list; MetaMask returns home. */
export async function switchAccount(page: Page, name: string): Promise<void> {
  await page.getByTestId('account-menu-icon').click();
  await page.getByText(name, { exact: true }).first().click();
  await expect(page.getByTestId('account-menu-icon')).toContainText(name, { timeout: 30_000 });
}

/** Fills a field whose test id sits on the input itself or on its wrapper (13.13.1 does both). */
async function fillField(field: Locator, value: string): Promise<void> {
  await field.waitFor({ state: 'visible', timeout: 30_000 });
  const inner = field.locator('input');
  if (await inner.count()) await inner.first().fill(value);
  else await field.fill(value);
}

/** Adds a custom network through "Add a custom network" (settings/networks/add-network). */
export async function addNetwork(
  page: Page,
  extensionId: string,
  network: { name: string; rpcUrl: string; chainId: number; symbol: string },
): Promise<void> {
  await page.goto(`chrome-extension://${extensionId}/home.html#settings/networks/add-network`);
  await fillField(page.getByTestId('network-form-name-input'), network.name);
  await page.getByTestId('test-add-rpc-drop-down').click();
  await page.getByText('Add RPC URL', { exact: true }).click();
  await fillField(page.getByTestId('rpc-url-input-test'), network.rpcUrl);
  await page.getByRole('button', { name: 'Add URL' }).click();
  await fillField(page.getByTestId('network-form-chain-id-input'), String(network.chainId));
  await fillField(page.getByTestId('network-form-ticker-input'), network.symbol);
  const save = page.getByRole('button', { name: 'Save' });
  await expect(save).toBeEnabled({ timeout: 30_000 });
  await save.click();
  await expect(page.getByText('Add a custom network')).toHaveCount(0, { timeout: 30_000 });
}

/** Buttons that confirm a MetaMask prompt, in the order they're tried. */
const CONFIRM_BUTTONS = [
  'confirm-alert-modal-submit-button', // "Confirm" after acknowledging an alert
  'alert-modal-button', // "Got it" on an alert
  'confirm-btn', // connect
  'confirmation-submit-button', // add or switch network
  'confirm-footer-button', // signatures and transactions
  'page-container-footer-next', // older pages
];

const WARNING_WORDS = /warning|suspicious|deceptive|doesn.t match|mismatch|not the same|risk|unknown address|alert/i;

export interface PromptLog {
  /** Each click: the prompt's kind, the button's test id and the prompt text before it. */
  clicks: Array<{ kind: string; button: string; text: string }>;
  /** Lines that look like MetaMask warnings (domain, chain), kept as findings. */
  warnings: string[];
}

function notificationPage(context: BrowserContext, extensionId: string): Page | undefined {
  const url = `chrome-extension://${extensionId}/notification.html`;
  return context.pages().find((p) => !p.isClosed() && p.url().startsWith(url));
}

/**
 * Confirms MetaMask prompts until `done()` says the dapp step finished, or until no prompt
 * has shown for `idleMs` after at least one click. MetaMask may chain prompts in one window
 * (connect, then sign; switch network, then send), so each pass confirms what's on screen.
 * Throws if nothing showed up within `timeoutMs`.
 */
export async function confirmPrompts(
  context: BrowserContext,
  extensionId: string,
  kind: string,
  log: PromptLog,
  opts: { done?: () => Promise<boolean> | boolean; idleMs?: number; timeoutMs?: number } = {},
): Promise<void> {
  const idleMs = opts.idleMs ?? 6_000;
  const timeoutMs = opts.timeoutMs ?? 120_000;
  const start = Date.now();
  let last = Date.now();
  let clicks = 0;
  for (;;) {
    if (opts.done && (await opts.done())) return;
    const prompt = notificationPage(context, extensionId);
    if (prompt) {
      await prompt.waitForLoadState('domcontentloaded').catch(() => undefined);
      await prompt.waitForTimeout(1_500);
      if (prompt.isClosed()) continue;
      const text = (await prompt.locator('body').innerText({ timeout: 10_000 }).catch(() => '')).replace(/[ \t]+/g, ' ');
      const scroll = prompt.getByTestId('confirm-scroll-to-bottom__button');
      if (await scroll.isVisible().catch(() => false)) {
        await scroll.click().catch(() => undefined);
        continue;
      }
      const ack = prompt.getByTestId('alert-modal-acknowledge-checkbox');
      if (await ack.isVisible().catch(() => false)) await ack.check().catch(() => undefined);
      let clicked = '';
      for (const id of CONFIRM_BUTTONS) {
        const button = prompt.getByTestId(id);
        if ((await button.isVisible().catch(() => false)) && (await button.isEnabled().catch(() => false))) {
          await button.click().catch(() => undefined);
          clicked = id;
          break;
        }
      }
      if (clicked) {
        clicks++;
        last = Date.now();
        log.clicks.push({ kind, button: clicked, text: text.slice(0, 2000) });
        console.log(`[metamask] ${kind}: clicked ${clicked}; prompt text: ${text.replace(/\s+/g, ' ').slice(0, 600)}`);
        for (const line of text.split('\n').map((l) => l.trim()).filter((l) => l && WARNING_WORDS.test(l))) {
          log.warnings.push(`${kind}: ${line}`);
        }
      }
    } else if (clicks > 0 && Date.now() - last > idleMs) {
      return;
    }
    if (Date.now() - start > timeoutMs) {
      if (clicks === 0) throw new Error(`no MetaMask prompt to confirm for ${kind} within ${timeoutMs} ms`);
      return;
    }
    await new Promise((r) => setTimeout(r, 1_000));
  }
}
