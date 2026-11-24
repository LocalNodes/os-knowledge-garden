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
import { categorized } from './failure-dump.ts';

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

/** Buttons on an alert over a request; allowed on any request a call expects. */
const ALERT_BUTTONS = ['confirm-alert-modal-submit-button', 'alert-modal-button'];

/**
 * The one kind of request a call may confirm. `route` is how MetaMask routes it:
 * `confirmation` (add or switch network), `connect` (share an account, or a switch to a
 * network the site may not use yet) or `confirm-transaction` (sends, signatures and, in
 * 13.13.1, add-network). `buttons` are the buttons that confirm it; the alert buttons are
 * allowed on top. The rest narrows the request inside its route (see promptRefusal):
 * `origin` is the site's host name (no port), which the prompt must name; `network` is the network name a
 * switch prompt must show; `signatureOnly` refuses anything on the route but a signature.
 */
export interface ExpectedPrompt {
  route: 'confirmation' | 'connect' | 'confirm-transaction';
  /** Other routes the same request can take (a switch can open on `connect`). */
  alsoRoutes?: ReadonlyArray<'confirmation' | 'connect' | 'confirm-transaction'>;
  buttons: readonly string[];
  origin?: string;
  network?: string;
  signatureOnly?: boolean;
}

/**
 * A network switch the site asked for. MetaMask 13.13.1 (local recording, 11-12) opens a
 * switch to a network the site may not use yet as connect/.../confirm-permissions ("Use your
 * enabled networks", the network's name, Confirm = page-container-footer-next).
 */
export const SWITCH_PROMPT: ExpectedPrompt = { route: 'confirmation', alsoRoutes: ['connect'], buttons: ['confirmation-submit-button', 'page-container-footer-next'] };
/** A signature or a send (MetaMask routes both as confirm-transaction). */
export const SIGN_PROMPT: ExpectedPrompt = { route: 'confirm-transaction', buttons: ['confirm-footer-button'] };
/** A signature only: the voting-setup approval signs and never sends. */
export const SETUP_SIGN_PROMPT: ExpectedPrompt = { ...SIGN_PROMPT, signatureOnly: true };
/** Sharing an account with the site (wallet_requestPermissions). */
export const SHARE_PROMPT: ExpectedPrompt = { route: 'connect', buttons: ['confirm-btn'] };

/** What an add-network prompt says (13.13.1 routes it as confirm-transaction). */
const ADD_NETWORK_TEXT = /suggesting additional network details/i;
/** What an account-sharing prompt says (connect route, like a switch to a new network). */
const SHARE_TEXT = /connect this website/i;

/**
 * Why an open prompt on the expected route is still not the expected request, or undefined
 * when it is. The markers come from the local recording of MetaMask 13.13.1 (11-12):
 * - every prompt names the site's host ("Request from fresh3.test", "fresh3.test is asking");
 * - signatures (typed data and personal_sign) end their address in /signature-request; a send
 *   and an add-network prompt share the route and the Confirm button but not that ending;
 * - a switch names the network; add-network says "suggesting additional network details";
 *   sharing an account says "Connect this website".
 */
/** A refusal that can be a passing state: a window caught between two signatures. */
const NOT_A_SIGNATURE = 'it is not a signature request (a send or a network request)';
const NOT_THE_SITE = 'it does not name the site ';
/**
 * Refusals that are read again for up to SETTLE_MS before they stand: a window caught between
 * two signatures, or a share window caught between two screens before it names the site. Only
 * a share: its page carries nothing the requesting site wrote. A signature or a send shows the
 * site's own message, which can carry any host name, so there the missing name stands at once.
 * Every other refusal stands at once.
 */
const settles = (why: string, url: string) =>
  why === NOT_A_SIGNATURE || (why.startsWith(NOT_THE_SITE) && /#\/?connect\//.test(url));

export function promptRefusal(expected: ExpectedPrompt, url: string, text: string): string | undefined {
  if (expected.origin && !text.includes(expected.origin)) return `${NOT_THE_SITE}${expected.origin}`;
  if (expected.signatureOnly && !/\/signature-request(?:[/?#]|$)/.test(url)) return NOT_A_SIGNATURE;
  if (expected.network !== undefined) {
    if (ADD_NETWORK_TEXT.test(text)) return 'it adds a network instead of switching';
    if (SHARE_TEXT.test(text)) return 'it shares an account instead of switching';
    if (!text.includes(expected.network)) return `it does not name the network ${expected.network}`;
  }
  return undefined;
}

/**
 * How long a signature-only prompt on the expected route may show an address without the
 * /signature-request ending before it is refused. MetaMask moves the same window from one signature to the next (or
 * closes it) a moment after a confirm; read in that moment, its address can lack the
 * /signature-request ending. Nothing is pressed while it waits, and after the wait anything that
 * is still not the expected request is refused, unconfirmed, as before.
 */
export const SETTLE_MS = 5_000;

/** The fixed labels a prompt is reported by; the CI log is public, so never its text or address. */
export type PromptLabel = 'signature_request' | 'send' | 'network_switch' | 'network_add' | 'connect' | 'unlock' | 'other';

/**
 * What kind of request a prompt window shows, as one fixed label, from its address and (only to
 * choose between labels) its text. Uses the markers promptRefusal documents. A confirm-transaction
 * address without the /signature-request ending reads as `send`: a send, or a window caught
 * between two signatures.
 */
export function promptLabel(url: string, text: string): PromptLabel {
  const hash = url.slice(url.indexOf('#') + 1 || url.length);
  if (/^\/?unlock(?:[/?]|$)/.test(hash)) return 'unlock';
  const route = /^\/?(confirm-transaction|confirmation|connect)\//.exec(hash)?.[1];
  if (route === 'confirm-transaction') {
    if (/\/signature-request(?:[/?#]|$)/.test(hash)) return 'signature_request';
    return ADD_NETWORK_TEXT.test(text) ? 'network_add' : 'send';
  }
  if (route === 'confirmation') return ADD_NETWORK_TEXT.test(text) ? 'network_add' : 'network_switch';
  if (route === 'connect') return SHARE_TEXT.test(text) ? 'connect' : 'network_switch';
  return 'other';
}

const WARNING_WORDS = /warning|suspicious|deceptive|doesn.t match|mismatch|not the same|risk|unknown address|alert/i;

export interface PromptLog {
  /** Each click: the prompt's kind, the button's test id and the prompt text before it. */
  clicks: Array<{ kind: string; button: string; text: string }>;
  /** Lines that look like MetaMask warnings (domain, chain), kept as findings. */
  warnings: string[];
}

/** How many wallet prompt windows are open: a count for the log. */
function walletWindows(context: BrowserContext, extensionId: string): number {
  const url = `chrome-extension://${extensionId}/notification.html`;
  return context.pages().filter((p) => !p.isClosed() && p.url().startsWith(url)).length;
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
/** The route of the request a prompt shows, or undefined while its address has none yet. */
function routeOf(page: Page): string | undefined {
  return /#\/?(confirm-transaction|confirmation|connect)\//.exec(page.url())?.[1];
}

/** The route and request id in a prompt address, or undefined when it names no request. */
function requestIdOf(url: string): string | undefined {
  const match = /#\/?(confirm-transaction|confirmation|connect)\/([^/?#]+)/.exec(url);
  return match ? `${match[1]}/${match[2]}` : undefined;
}

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
  opts: { done?: () => Promise<boolean> | boolean; idleMs?: number; timeoutMs?: number; reopenIdleMs?: number; reopenBeforeClick?: boolean; expect?: ExpectedPrompt } = {},
): Promise<void> {
  // Opening the prompt page shows whatever request is pending, which may not be this call's.
  // So a call that opens it must say which request it expects, and nothing else is clicked.
  if (opts.reopenIdleMs && !opts.expect) throw new Error(`${kind}: opening the wallet's prompt page needs the expected request`);
  const expected = opts.expect;
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
  // still false), open the wallet's prompt page ourselves; reopenBeforeClick does the same
  // before any click, for a request sent just after another step's prompt closed (for example
  // the network switch right after the sign-in signature, with no window for 120 s); it shows the pending request. Every
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
  // Errors written here carry fixed text only. Anything else (a page call, the done check) leaves
  // as a fixed category, never its message: the CI log is public.
  const own = new WeakSet<Error>();
  const mine = (e: Error): Error => {
    own.add(e);
    return e;
  };
  const notClosed = () => mine(new Error(`${kind}: a wallet page opened here could not be closed within ${CLOSE_MS} ms`));
  let failed = false;
  // A prompt on the expected route that is not yet the expected request: since when, why, and
  // what it showed (a fixed label). Refused, unconfirmed, once SETTLE_MS has passed.
  // Kept per window and request: a new request, or a closed window, starts over.
  const promptPage = `chrome-extension://${extensionId}/notification.html`;
  let refused: { page: Page; request: string; since: number; why: string; label: PromptLabel } | undefined;
  /**
   * The refusal that still applies: its window is open on the prompt page and shows no other
   * request. An address with no request id (a window between two screens) is not another
   * request, so the clock keeps running.
   */
  const settling = () => {
    const r = refused;
    if (!r || r.page.isClosed() || !r.page.url().startsWith(promptPage)) return undefined;
    const id = requestIdOf(r.page.url());
    return id === undefined || id === r.request ? r : undefined;
  };
  /** Time left before a settling request is refused, for that window only (or any, if omitted). */
  const settleLeft = (page?: Page) => {
    const r = settling();
    return r && (!page || r.page === page) ? r.since + SETTLE_MS - Date.now() : Infinity;
  };
  // What a refused prompt showed, for the error and the public log: a fixed label and a count.
  const seen = (p: Page, text: string) => `it shows ${promptLabel(p.url(), text)}; wallet windows open ${walletWindows(context, extensionId)}`;
  const refusal = () =>
    mine(new Error(`${kind}: the open wallet prompt is not the expected request (${refused!.why}); it shows ${refused!.label} after ${Date.now() - refused!.since} ms; wallet windows open ${walletWindows(context, extensionId)}; it was not confirmed`));
  // Calls on the prompt window that neither returned nor failed in time, and `done()` checks
  // that didn't answer in time.
  let stalls = 0;
  let doneStalls = 0;
  const pastDeadline = () => Date.now() - start > timeoutMs;
  const isDone = async (): Promise<boolean> => {
    if (!opts.done) return false;
    const check = opts.done;
    // Never past the settle time of a request being read again.
    const answer = await within(Promise.resolve().then(() => check()), Math.max(1, Math.min(DONE_MS, settleLeft())));
    if (answer !== 'timeout') return answer.value;
    doneStalls++;
    return false;
  };
  const stallNote = () =>
    (stalls ? `; the prompt window stopped answering ${stalls} time(s)` : '') + (doneStalls ? `; the done check didn't answer ${doneStalls} time(s)` : '');
  try {
    for (;;) {
      if (await isDone()) {
        // Passed, but another wallet window may still be open, unpressed: say so with fixed
        // fields only (the count, and the label of a window still being read again).
        const still = walletWindows(context, extensionId);
        const shows = settling()?.label;
        if (still > 0) console.log(`[metamask] ${kind}: done; wallet windows still open ${still}${shows ? `; one shows ${shows}` : ''}`);
        return;
      }
      if (!settling()) refused = undefined;
      // The settle time is absolute: once it has passed, the request is refused before any read.
      if (settleLeft() <= 0) throw refusal();
      const open = notificationPage(context, extensionId);
      if (pastDeadline()) {
        if (settling()) throw refusal();
        if (opts.done) throw mine(new Error(`${kind}: not done within ${timeoutMs} ms (${clicks} MetaMask click(s); ${await promptState(open)}${stallNote()})`));
        if (open) throw mine(new Error(`${kind}: a MetaMask prompt is still open and unconfirmed after ${timeoutMs} ms${stallNote()}`));
        if (clicks === 0) throw mine(new Error(`no MetaMask prompt to confirm for ${kind} within ${timeoutMs} ms`));
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
            throw mine(categorized(e));
          }
        };
        // A Playwright call with its own timeout is raced a little longer, so its own timeout
        // normally ends it first; a check with no timeout of its own gets PROBE_MS.
        const timed = (ms: number) => {
          // Never past the settle time of a request being read again.
          const timeout = left(Math.min(ms, Math.max(1, settleLeft(prompt))));
          return { timeout, capMs: timeout + SLACK_MS };
        };
        const load = timed(30_000);
        await on(undefined, () => prompt.waitForLoadState('domcontentloaded', { timeout: load.timeout }), load.capMs);
        // A plain timer, not prompt.waitForTimeout: the window may close during this wait.
        if (!gone && !hung) await sleep(Math.max(0, Math.min(1_500, settleLeft(prompt))));
        // The address this pass checks, taken before the text is read and checked again after it:
        // text and address must come from the same request. Every press below first checks the
        // window still shows it: a window that moved to another request is read again, never
        // pressed.
        const seenUrl = prompt.url();
        let moved = false;
        const read = timed(10_000);
        const text = (await on('', () => prompt.locator('body').innerText({ timeout: read.timeout }), read.capMs)).replace(/[ \t]+/g, ' ');
        if (!prompt.isClosed() && prompt.url() !== seenUrl) moved = true;
        const request = requestIdOf(seenUrl) ?? seenUrl;
        const act = async (step: () => Promise<unknown>, capMs: number): Promise<unknown> => {
          if (!prompt.isClosed() && prompt.url() !== seenUrl) {
            moved = true;
            return undefined;
          }
          return on(undefined, step, capMs);
        };
        const pressed = confirmed?.page === prompt && confirmed.request === request ? confirmed.buttons : new Set<string>();
        let clicked = '';
        // With an expected request, nothing is pressed until the prompt's address shows its
        // route, and a prompt for any other request is refused, unconfirmed, with an error.
        let allowed = true;
        if (expected && !gone && !hung && !moved) {
          const route = routeOf(prompt);
          if (route === undefined || !text.trim()) allowed = false;
          else if (route !== expected.route && !expected.alsoRoutes?.includes(route as ExpectedPrompt['route'])) throw mine(new Error(`${kind}: the open wallet prompt is a ${route} request, not the expected ${expected.route} request; ${seen(prompt, text)}; it was not confirmed`));
          else {
            const why = promptRefusal(expected, seenUrl, text);
            const prior = settling();
            const id = requestIdOf(seenUrl);
            const same = prior !== undefined && prior.page === prompt && (id === undefined || id === prior.request);
            // After the wait the request is refused, whatever it shows by now.
            if (same && Date.now() - prior.since >= SETTLE_MS) throw refusal();
            // A request that passes ends its own wait only, never another window's.
            if (!why) {
              if (same) refused = undefined;
            }
            // Another network, an add-network or a share in place of a switch is no passing state:
            // refused at once.
            else if (!settles(why, seenUrl)) throw mine(new Error(`${kind}: the open wallet prompt is not the expected request (${why}); ${seen(prompt, text)}; it was not confirmed`));
            else {
              const label = promptLabel(seenUrl, text);
              if (!same) {
                refused = { page: prompt, request: id ?? seenUrl, since: Date.now(), why, label };
                console.log(`[metamask] ${kind}: the open prompt shows ${label}, not the expected request; reading it again within ${SETTLE_MS} ms`);
              }
              else prior.label = label;
              allowed = false;
            }
          }
        }
        if (!gone && !hung && !moved && allowed) {
          const scroll = prompt.getByTestId('confirm-scroll-to-bottom__button');
          if (await on(false, () => scroll.isVisible(), PROBE_MS)) {
            const press = timed(10_000);
            await act(() => scroll.click({ timeout: press.timeout }), press.capMs);
            await sleep(1_000);
            continue; // the deadline check at the top bounds a scroll button that stays visible
          }
          const ack = prompt.getByTestId('alert-modal-acknowledge-checkbox');
          if (await on(false, () => ack.isVisible(), PROBE_MS)) {
            const tick = timed(10_000);
            await act(() => ack.check({ timeout: tick.timeout }), tick.capMs);
          }
          for (const id of CONFIRM_BUTTONS) {
            if (gone || hung || moved) break;
            if (expected && !ALERT_BUTTONS.includes(id) && !expected.buttons.includes(id)) continue;
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
              // Wait for the button without pressing it, then check the address once more and
              // press at once: the press goes to the request that was read.
              const trial = timed(10_000);
              await on(undefined, () => button.click({ trial: true, timeout: trial.timeout }), trial.capMs);
              if (gone || hung) break;
              if (!prompt.isClosed() && prompt.url() !== seenUrl) {
                moved = true;
                break;
              }
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
        if (moved) console.log(`[metamask] ${kind}: the prompt moved to another request before the press; reading it again`);
        if (hung) console.log(`[metamask] ${kind}: the prompt window stopped answering (${stalls} time(s) so far)`);
        if (gone && !clicked) {
          // The prompt closed under us (MetaMask closes it after a confirm): it's done. Restart
          // the idle clock so the next prompt in a chain has `idleMs` to open.
          last = Date.now();
        }
        // A page whose request is settling stays open until it is confirmed or refused.
        if (!clicked && !gone && owned.includes(prompt) && Date.now() - openedAt > 8_000 && settling()?.page !== prompt) {
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
          const named = expected?.origin ? '; site named yes' : '';
          console.log(`[metamask] ${kind}: clicked ${clicked}; warning lines ${warned.length}${named}`);
        }
      } else if (!opts.done && clicks > 0 && Date.now() - last > idleMs) {
        return;
      } else if (opts.done && opts.reopenIdleMs && (clicks > 0 || opts.reopenBeforeClick) && owned.length === 0 && Date.now() - last > opts.reopenIdleMs) {
        console.log(`[metamask] ${kind}: no prompt for ${opts.reopenIdleMs} ms; opening the wallet's prompt page`);
        const openMs = left(10_000);
        const opening = context.newPage();
        const made = await within(opening, openMs);
        if (made === 'timeout') {
          // A page that opens after this gave up is closed as soon as it appears.
          void opening.then((late) => within(late.close(), CLOSE_MS)).catch(() => undefined);
          throw mine(new Error(`${kind}: the wallet's prompt page did not open within ${openMs} ms`));
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
    throw e instanceof Error && own.has(e) ? e : categorized(e);
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
