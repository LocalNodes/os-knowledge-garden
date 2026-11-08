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

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

/** True when an error comes from a page, context or browser that has closed. */
function isClosedError(e: unknown): boolean {
  return e instanceof Error && /has been closed/i.test(e.message);
}

/**
 * Confirms MetaMask prompts until `done()` says the dapp step finished, or until no prompt
 * has shown for `idleMs` after at least one click. MetaMask may chain prompts in one window
 * (connect, then sign; switch network, then send), so each pass confirms what's on screen.
 * MetaMask often closes the prompt window after a confirm, sometimes while this loop is still
 * on it: a closed prompt counts as done, and the loop looks for the next prompt in the
 * context's pages. No call is made on a page known to be closed.
 *
 * Success is never assumed:
 * - only a "has been closed" error means a prompt is done; any other error is thrown;
 * - only a click that ran counts: it resolved, or the window closed on it;
 * - with `done`, the loop returns only when `done()` is true (the idle exit is off), so a
 *   required follow-up prompt that never appears ends in an error;
 * - without `done`, it returns after `idleMs` with no prompt open, once a click succeeded.
 * The deadline is checked at the top of every pass. At `timeoutMs` it throws: nothing showed
 * up, `done()` is still false, or a prompt is still open and unconfirmed.
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
    const open = notificationPage(context, extensionId);
    if (Date.now() - start > timeoutMs) {
      if (opts.done) throw new Error(`${kind}: not done within ${timeoutMs} ms (${clicks} MetaMask click(s))`);
      if (open) throw new Error(`${kind}: a MetaMask prompt is still open and unconfirmed after ${timeoutMs} ms`);
      if (clicks === 0) throw new Error(`no MetaMask prompt to confirm for ${kind} within ${timeoutMs} ms`);
      return;
    }
    const prompt = open;
    if (prompt) {
      // Each step on the prompt goes through `on`: it skips the step once the page is closed,
      // and turns a "has been closed" error into `gone`, so the closed page is left alone.
      // Any other error is thrown: it isn't evidence that the prompt was confirmed.
      let gone = false;
      const on = async <T>(fallback: T, step: () => Promise<T>): Promise<T> => {
        if (gone || prompt.isClosed()) {
          gone = true;
          return fallback;
        }
        try {
          return await step();
        } catch (e) {
          if (prompt.isClosed() || isClosedError(e)) {
            gone = true;
            return fallback;
          }
          throw e;
        }
      };
      await on(undefined, () => prompt.waitForLoadState('domcontentloaded'));
      // A plain timer, not prompt.waitForTimeout: the window may close during this wait.
      if (!gone) await sleep(1_500);
      const text = (await on('', () => prompt.locator('body').innerText({ timeout: 10_000 }))).replace(/[ \t]+/g, ' ');
      const scroll = prompt.getByTestId('confirm-scroll-to-bottom__button');
      if (await on(false, () => scroll.isVisible())) {
        await on(undefined, () => scroll.click());
        await sleep(1_000);
        continue; // the deadline check at the top bounds a scroll button that stays visible
      }
      const ack = prompt.getByTestId('alert-modal-acknowledge-checkbox');
      if (await on(false, () => ack.isVisible())) await on(undefined, () => ack.check());
      let clicked = '';
      for (const id of CONFIRM_BUTTONS) {
        if (gone) break;
        const button = prompt.getByTestId(id);
        if ((await on(false, () => button.isVisible())) && (await on(false, () => button.isEnabled()))) {
          // Counts if the click resolved, or if it ran and the window closed on it (MetaMask
          // closes the prompt on the confirm). A click skipped because the page was already
          // closed doesn't count, and any other error is thrown by `on`.
          let ran = false;
          const resolved = await on(false, () => {
            ran = true;
            return button.click().then(() => true);
          });
          if (resolved || (ran && gone)) clicked = id;
          break;
        }
      }
      if (gone && !clicked) {
        // The prompt closed under us (MetaMask closes it after a confirm): it's done. Restart
        // the idle clock so the next prompt in a chain has `idleMs` to open.
        last = Date.now();
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
    } else if (!opts.done && clicks > 0 && Date.now() - last > idleMs) {
      return;
    }
    await sleep(1_000);
  }
}
