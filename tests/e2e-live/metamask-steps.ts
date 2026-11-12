// Our own MetaMask 13.13.1 steps for the local recording and the live suites.
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
import { categorized } from './failure-dump';

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

/** The value of `p`, or 'timeout' if it hasn't settled within `ms`. A rejection is passed on. */
export async function within<T>(p: Promise<T>, ms: number): Promise<{ value: T } | 'timeout'> {
  let timer: ReturnType<typeof setTimeout> | undefined;
  try {
    return await Promise.race([
      p.then((value) => ({ value })),
      new Promise<'timeout'>((r) => {
        timer = setTimeout(() => r('timeout'), ms);
      }),
    ]);
  } finally {
    clearTimeout(timer);
  }
}

/** How long closing one page opened here may take. */
const CLOSE_MS = 5_000;

/**
 * How long a call on a prompt window may take beyond its own Playwright timeout, and how long a
 * check with no timeout of its own (isVisible) may take. A window that doesn't answer within
 * this has stopped answering: the rest of that pass is skipped, never retried in the same pass.
 */
const SLACK_MS = 2_000;
const PROBE_MS = 5_000;
/** How long the caller's own `done()` check may take; a slower answer counts as "not yet". */
const DONE_MS = 10_000;
/** How long promptState waits on each of its checks. */
const STATE_MS = 1_000;

/**
 * The request a prompt window shows, from its address. MetaMask routes each request by its id
 * (confirm-transaction/<id> for sends and signatures, connect/<id>, confirmation/<id>), and the
 * id stays the same while fee estimates and other screen text change. An address without an
 * id stands for itself.
 */
function requestOf(page: Page): string {
  const url = page.url();
  const match = /#\/?(confirm-transaction|confirmation|connect)\/([^/?#]+)/.exec(url);
  return match ? `${match[1]}/${match[2]}` : url;
}

/** Test ids a stuck prompt is checked for; only their names are ever printed. */
const STATE_IDS = [...CONFIRM_BUTTONS, 'confirm-scroll-to-bottom__button', 'alert-modal-acknowledge-checkbox'];

/** Whether a prompt is open and which known test ids it shows: fixed names, never its text. */
async function promptState(open: Page | undefined): Promise<string> {
  if (!open || open.isClosed()) return 'no prompt open';
  const shown: string[] = [];
  for (const id of STATE_IDS) {
    const seen = await within(open.getByTestId(id).isVisible(), STATE_MS).catch(() => ({ value: false }));
    if (seen === 'timeout') return 'a prompt is open and not answering';
    if (seen.value) shown.push(id);
  }
  return `a prompt is open, showing ${shown.join(' ') || 'none of the known buttons'}`;
}

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
 * - a button is pressed at most once per request in a window: the request is known by the id in
 *   the window's address, never by its text, so changing fee text doesn't bring a second press.
 *   A second button on the same request (an alert's "Confirm" after the footer's) is allowed;
 * - with `done`, the loop returns only when `done()` is true (the idle exit is off), so a
 *   required follow-up prompt that never appears ends in an error;
 * - without `done`, it returns after `idleMs` with no prompt open, once a click succeeded.
 * Every awaited call on a page has a timeout capped by the time left, and the deadline is
 * checked at the top of every pass. At `timeoutMs` it throws: nothing showed up, `done()` is
 * still false, or a prompt is still open and unconfirmed. Opening and closing a page are bounded
 * too, and it never returns while a page it opened is still open.
 *
 * A prompt window can stop answering: a call on it neither returns nor fails. So every call on
 * it is also raced against a timer of our own. A call that loses the race is a stall: the rest
 * of that pass is skipped and the stall is counted, never taken as closed, done or clicked.
 * A click that stalls is not counted as a press, but it may have run, so that button is never
 * pressed again on that request. `done()` is raced too; a slow answer counts as "not yet". At
 * the deadline the error says how many times the window stopped answering. Closing the window
 * to get it answering again is not done: MetaMask refuses the pending request when its window
 * is closed by anything but itself.
 */
export async function confirmPrompts(
  context: BrowserContext,
  extensionId: string,
  kind: string,
  log: PromptLog,
  opts: { done?: () => Promise<boolean> | boolean; idleMs?: number; timeoutMs?: number; reopenIdleMs?: number } = {},
): Promise<void> {
  const idleMs = opts.idleMs ?? 6_000;
  const timeoutMs = opts.timeoutMs ?? 120_000;
  const start = Date.now();
  /** A timeout for one awaited call: at most `cap`, never past the deadline. */
  const left = (cap: number) => Math.max(1, Math.min(cap, start + timeoutMs - Date.now()));
  let last = Date.now();
  let clicks = 0;
  // The last request confirmed, in which window, and the buttons pressed on it.
  let confirmed: { page: Page; request: string; buttons: Set<string> } | undefined;
  // reopenIdleMs: MetaMask can leave a request that arrives while the last prompt window is
  // closing pending with no window. After that long with no prompt (once a click ran, `done`
  // still false), open the wallet's prompt page ourselves; it shows the pending request. Every
  // page opened here is kept until it is closed: at once if its navigation fails, after 8 s if
  // it shows no known button, and in any case when this returns. It stays owned until it is
  // seen closed; one that won't close within CLOSE_MS is an error.
  const owned: Page[] = [];
  let openedAt = 0;
  const closeOwned = async (page: Page): Promise<boolean> => {
    if (!page.isClosed()) await within(page.close(), CLOSE_MS).catch(() => undefined);
    if (!page.isClosed()) return false;
    const index = owned.indexOf(page);
    if (index >= 0) owned.splice(index, 1);
    return true;
  };
  const notClosed = () => new Error(`${kind}: a wallet page opened here could not be closed within ${CLOSE_MS} ms`);
  let failed = false;
  // Calls on the prompt window that neither returned nor failed in time, and `done()` checks
  // that didn't answer in time.
  let stalls = 0;
  let doneStalls = 0;
  const pastDeadline = () => Date.now() - start > timeoutMs;
  const isDone = async (): Promise<boolean> => {
    if (!opts.done) return false;
    const check = opts.done;
    const answer = await within(Promise.resolve().then(() => check()), DONE_MS);
    if (answer !== 'timeout') return answer.value;
    doneStalls++;
    return false;
  };
  const stallNote = () =>
    (stalls ? `; the prompt window stopped answering ${stalls} time(s)` : '') + (doneStalls ? `; the done check didn't answer ${doneStalls} time(s)` : '');
  try {
    for (;;) {
      if (await isDone()) return;
      const open = notificationPage(context, extensionId);
      if (pastDeadline()) {
        if (opts.done) throw new Error(`${kind}: not done within ${timeoutMs} ms (${clicks} MetaMask click(s); ${await promptState(open)}${stallNote()})`);
        if (open) throw new Error(`${kind}: a MetaMask prompt is still open and unconfirmed after ${timeoutMs} ms${stallNote()}`);
        if (clicks === 0) throw new Error(`no MetaMask prompt to confirm for ${kind} within ${timeoutMs} ms`);
        return;
      }
      const prompt = open;
      if (prompt) {
        // Each step on the prompt goes through `on`: it skips the step once the page is closed,
        // and turns a "has been closed" error into `gone`, so the closed page is left alone.
        // Any other error is thrown: it isn't evidence that the prompt was confirmed. A step
        // that doesn't settle within `capMs` sets `hung`, and every later step in this pass is
        // skipped; so is every step once the deadline has passed.
        let gone = false;
        let hung = false;
        const on = async <T>(fallback: T, step: () => Promise<T>, capMs: number): Promise<T> => {
          if (gone || prompt.isClosed()) {
            gone = true;
            return fallback;
          }
          if (hung || pastDeadline()) return fallback;
          try {
            const settled = await within(step(), capMs);
            if (settled !== 'timeout') return settled.value;
            if (prompt.isClosed()) {
              gone = true;
              return fallback;
            }
            hung = true;
            stalls++;
            return fallback;
          } catch (e) {
            if (prompt.isClosed() || isClosedError(e)) {
              gone = true;
              return fallback;
            }
            // A fixed category and the error's name only: never the message.
            throw categorized(e);
          }
        };
        // A Playwright call with its own timeout is raced a little longer, so its own timeout
        // normally ends it first; a check with no timeout of its own gets PROBE_MS.
        const timed = (ms: number) => {
          const timeout = left(ms);
          return { timeout, capMs: timeout + SLACK_MS };
        };
        const load = timed(30_000);
        await on(undefined, () => prompt.waitForLoadState('domcontentloaded', { timeout: load.timeout }), load.capMs);
        // A plain timer, not prompt.waitForTimeout: the window may close during this wait.
        if (!gone && !hung) await sleep(1_500);
        const read = timed(10_000);
        const text = (await on('', () => prompt.locator('body').innerText({ timeout: read.timeout }), read.capMs)).replace(/[ \t]+/g, ' ');
        const request = requestOf(prompt);
        const pressed = confirmed?.page === prompt && confirmed.request === request ? confirmed.buttons : new Set<string>();
        let clicked = '';
        if (!gone && !hung) {
          const scroll = prompt.getByTestId('confirm-scroll-to-bottom__button');
          if (await on(false, () => scroll.isVisible(), PROBE_MS)) {
            const press = timed(10_000);
            await on(undefined, () => scroll.click({ timeout: press.timeout }), press.capMs);
            await sleep(1_000);
            continue; // the deadline check at the top bounds a scroll button that stays visible
          }
          const ack = prompt.getByTestId('alert-modal-acknowledge-checkbox');
          if (await on(false, () => ack.isVisible(), PROBE_MS)) {
            const tick = timed(10_000);
            await on(undefined, () => ack.check({ timeout: tick.timeout }), tick.capMs);
          }
          for (const id of CONFIRM_BUTTONS) {
            if (gone || hung) break;
            const button = prompt.getByTestId(id);
            const enabled = timed(5_000);
            if ((await on(false, () => button.isVisible(), PROBE_MS)) && (await on(false, () => button.isEnabled({ timeout: enabled.timeout }), enabled.capMs))) {
              // Already pressed on this request: it must close or move on before anything else.
              if (pressed.has(id)) break;
              // Counts if the click resolved, or if it ran and the window closed on it (MetaMask
              // closes the prompt on the confirm). A click skipped because the page was already
              // closed doesn't count, and any other error is thrown by `on`. A click that ran and
              // stalled isn't counted either, but it may have pressed the button, so the button
              // is latched for this request and never pressed again.
              let ran = false;
              const press = timed(30_000);
              const resolved = await on(
                false,
                () => {
                  ran = true;
                  return button.click({ timeout: press.timeout }).then(() => true);
                },
                press.capMs,
              );
              if (resolved || (ran && gone)) clicked = id;
              else if (ran && hung) {
                pressed.add(id);
                confirmed = { page: prompt, request, buttons: pressed };
              }
              break;
            }
          }
        }
        if (hung) console.log(`[metamask] ${kind}: the prompt window stopped answering (${stalls} time(s) so far)`);
        if (gone && !clicked) {
          // The prompt closed under us (MetaMask closes it after a confirm): it's done. Restart
          // the idle clock so the next prompt in a chain has `idleMs` to open.
          last = Date.now();
        }
        if (!clicked && !gone && owned.includes(prompt) && Date.now() - openedAt > 8_000) {
          if (!(await closeOwned(prompt))) throw notClosed();
          last = Date.now();
        }
        if (clicked) {
          clicks++;
          last = Date.now();
          pressed.add(clicked);
          confirmed = { page: prompt, request, buttons: pressed };
          log.clicks.push({ kind, button: clicked, text: text.slice(0, 2000) });
          const warned = text.split('\n').map((l) => l.trim()).filter((l) => l && WARNING_WORDS.test(l));
          for (const line of warned) log.warnings.push(`${kind}: ${line}`);
          // Fixed fields only: the CI log is public, so MetaMask's own text stays in `log`. The
          // local recording reads it from there.
          console.log(`[metamask] ${kind}: clicked ${clicked}; warning lines ${warned.length}`);
        }
      } else if (!opts.done && clicks > 0 && Date.now() - last > idleMs) {
        return;
      } else if (opts.done && opts.reopenIdleMs && clicks > 0 && owned.length === 0 && Date.now() - last > opts.reopenIdleMs) {
        console.log(`[metamask] ${kind}: no prompt for ${opts.reopenIdleMs} ms; opening the wallet's prompt page`);
        const openMs = left(10_000);
        const opening = context.newPage();
        const made = await within(opening, openMs);
        if (made === 'timeout') {
          // A page that opens after this gave up is closed as soon as it appears.
          void opening.then((late) => within(late.close(), CLOSE_MS)).catch(() => undefined);
          throw new Error(`${kind}: the wallet's prompt page did not open within ${openMs} ms`);
        }
        const page = made.value;
        owned.push(page);
        openedAt = Date.now();
        last = Date.now();
        const url = `chrome-extension://${extensionId}/notification.html`;
        const gotoMs = left(15_000);
        const reached = await within(page.goto(url, { timeout: gotoMs }).then(() => true, () => false), gotoMs + SLACK_MS);
        const loaded = reached !== 'timeout' && reached.value;
        // A page that didn't reach the prompt page isn't found as a prompt: close it now.
        if ((!loaded || page.isClosed() || !page.url().startsWith(url)) && !(await closeOwned(page))) throw notClosed();
      }
      await sleep(1_000);
    }
  } catch (e) {
    failed = true;
    throw e;
  } finally {
    // MetaMask doesn't close a tab it didn't open, so every page opened here is closed. One that
    // stays open turns a success into an error; after a failure, the first error is kept.
    let stuck = 0;
    for (const page of [...owned]) if (!(await closeOwned(page))) stuck++;
    if (stuck) console.log(`[metamask] ${kind}: ${stuck} wallet page(s) opened here are still open`);
    // eslint-disable-next-line no-unsafe-finally
    if (stuck && !failed) throw notClosed();
  }
}
