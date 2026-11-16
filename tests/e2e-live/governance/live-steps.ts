// Live governance steps shared by the judge and anything else that drives the voting screens in
// real MetaMask. Log lines hold fixed fields only: the CI logs are public (failure-dump.ts).
import { randomBytes } from 'node:crypto';
import { expect, type BrowserContext, type Locator, type Page } from '@playwright/test';
import { approveAlertKey, setupRouteKey } from '../failure-dump';
import { confirmPrompts, SETUP_SIGN_PROMPT, within, type PromptLog } from '../metamask-steps';
import { actThenWait, fixedStep } from '../../e2e/helpers/fixed-failure';
import { assertNotStoppedByNotice, noticeError, tickPublicNotice } from '../../e2e/helpers/public-notice';
import { forSite } from './judge-wallet';
import { COPY, fill, pattern, setupProgress } from './pages';

/** The one-time login link didn't open the account's edit page with its reset session. */
export const SIGN_IN_NO_RESET_SESSION = 'add a sign-in: the login link did not open the account edit page';
/** Filling or saving the throwaway password failed. */
export const SIGN_IN_PASSWORD_NOT_SAVED = 'add a sign-in: the throwaway password was not saved';
/** The account confirmation did not lead to the wallet page's "Continue". */
export const SIGN_IN_NOT_CONFIRMED = 'add a sign-in: the account confirmation did not reach "Continue"';
/** The member-approval batch was not bound by its script in time. */
export const BATCH_NOT_BOUND = 'approve new members: the approval batch has no script bound in time';

const SIGN_IN_FAILURES = new Set([SIGN_IN_NO_RESET_SESSION, SIGN_IN_PASSWORD_NOT_SAVED, SIGN_IN_NOT_CONFIRMED]);

/** 144 random bits, plus the classes a password policy may ask for. Made per run, never logged. */
export function throwawayPassword(): string {
  return `${randomBytes(18).toString('base64url')}-Aa1`;
}

/**
 * Fills a password field without Playwright's fill(): fill() puts the value in its step title
 * (`Fill "<value>"`) and step parameters, which reach every reporter before any catch runs.
 * evaluate() leaves its argument out of the reported parameters (Playwright 1.63
 * protocolFormatter). A failure throws only `failure`.
 */
async function fillPassword(field: Locator, password: string, failure: string): Promise<void> {
  await fixedStep(() => field.evaluate((element, value) => {
    const input = element as HTMLInputElement;
    if (input.type !== 'password' || input.disabled || input.readOnly)
      throw new Error('password field unavailable');
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }, password, { timeout: 30_000 }), failure);
}

/**
 * Empties every password field on the page. This is the second layer: the configs that use a
 * password set PLAYWRIGHT_NO_COPY_PROMPT, so a failing test writes no page snapshot to
 * error-context.md at all, even when this fails or times out.
 */
async function emptyPasswordFields(page: Page): Promise<void> {
  await page.evaluate(() => {
    for (const el of Array.from(document.querySelectorAll('input[type="password"]'))) (el as HTMLInputElement).value = '';
  });
}

/** Test seams for the labels guard only. */
export interface SignInSeams {
  makePassword?: () => string;
  emptyFields?: (page: Page) => Promise<void>;
}

/**
 * Confirms the signed-in account for a first wallet sign-in (siwe_login 1.0.0-beta12), and
 * leaves the page on /user/{uid}/wallet with its "Continue" button, which signs with the wallet.
 *
 * - beta12's "Add a sign-in" first asks the member to confirm the account, by password or an
 *   emailed link (AttachmentConfirmForm), at /siwe/attach?address=<the address being added>.
 * - The seeded member's password is random and unknown, so this sets a throwaway one while the
 *   one-time login link's session allows it without the old one (Drupal's pass-reset token).
 *   It is made here, used once, and never logged: every step throws only a fixed SIGN_IN_*
 *   message, and on any failure the password fields are emptied first.
 * - Call it right after opening the one-time login link (`drush uli` shape, ending in /login).
 * - `seams` is for the labels guard only.
 */
export async function confirmAccountForSignIn(page: Page, uid: number | string, address: string, seams: SignInSeams = {}): Promise<void> {
  const { makePassword = throwawayPassword, emptyFields = emptyPasswordFields } = seams;
  await fixedStep(() => page.waitForLoadState('load'), SIGN_IN_NO_RESET_SESSION);
  const at = new URL(page.url());
  if (at.pathname !== `/user/${uid}/edit` || !at.searchParams.has('pass-reset-token')) {
    throw new Error(SIGN_IN_NO_RESET_SESSION);
  }
  const password = makePassword();
  try {
    await fillPassword(page.locator('input[name="pass[pass1]"]'), password, SIGN_IN_PASSWORD_NOT_SAVED);
    await fillPassword(page.locator('input[name="pass[pass2]"]'), password, SIGN_IN_PASSWORD_NOT_SAVED);
    // A saved form leaves the edit page; a refused one renders it again at the same address.
    const editPath = `/user/${uid}/edit`;
    await actThenWait(
      page.waitForURL((u) => u.pathname !== editPath, { timeout: 60_000, waitUntil: 'load' }),
      () => page.getByRole('button', { name: 'Save', exact: true }).click({ timeout: 30_000 }),
      SIGN_IN_PASSWORD_NOT_SAVED,
    );
    await fixedStep(() => page.goto(`/siwe/attach?address=${address.toLowerCase()}`), SIGN_IN_NOT_CONFIRMED);
    await fillPassword(page.getByLabel('Your password', { exact: true }), password, SIGN_IN_NOT_CONFIRMED);
    // A confirmed account is sent on to its wallet page (AttachmentFormBase::finish).
    const walletPath = `/user/${uid}/wallet`;
    await actThenWait(
      page.waitForURL((u) => u.pathname === walletPath, { timeout: 60_000, waitUntil: 'load' }),
      () => page.getByRole('button', { name: 'Confirm with password', exact: true }).click({ timeout: 30_000 }),
      SIGN_IN_NOT_CONFIRMED,
    );
    const shown = await fixedStep(() => page.getByRole('button', { name: 'Continue', exact: true }).isVisible(), SIGN_IN_NOT_CONFIRMED);
    if (!shown) throw new Error(SIGN_IN_NOT_CONFIRMED);
  } catch (error) {
    await within(emptyFields(page), 5_000).catch(() => undefined);
    const message = error instanceof Error && SIGN_IN_FAILURES.has(error.message) ? error.message : SIGN_IN_NOT_CONFIRMED;
    throw new Error(message);
  }
}

/**
 * Waits until Drupal has bound the member-approval batch (approve-members.js marks
 * [data-safe-governance-batch-approve] with once('safe-governance-batch-approve')). Its "Approve
 * these changes" is a plain button, so a press made before that does nothing at all.
 */
export async function batchApproveBound(page: Page, timeoutMs = 90_000): Promise<void> {
  await fixedStep(() => page.waitForLoadState('load', { timeout: timeoutMs }), BATCH_NOT_BOUND);
  await fixedStep(
    () => expect(page.locator('[data-safe-governance-batch-approve]'))
      .toHaveAttribute('data-once', /(^|\s)safe-governance-batch-approve(\s|$)/, { timeout: timeoutMs }),
    BATCH_NOT_BOUND,
  );
}

/** How long one check on the page may take; a slower one counts as "nothing seen yet". */
const CHECK_MS = 5_000;

/** `p`'s value, or `fallback` if it fails or hasn't settled within `ms`. */
async function settle<T>(p: Promise<T>, ms: number, fallback: T): Promise<T> {
  const r = await within(p, ms).catch(() => 'timeout' as const);
  return r === 'timeout' ? fallback : r.value;
}

/** Page errors a second round may follow: a passing fault on the site or in the wallet's RPC. */
const RETRYABLE = ['server', 'wallet_rpc'];

/**
 * Approves the setup as the signed-in approver, and returns each "Confirmation n of 3" the
 * page showed, sorted.
 *
 * - The page after "Start" shows the button before Drupal binds its click handler, and a click
 *   then does nothing at all. So this waits for the load and for once() to mark the panel.
 * - A round has started when the button hides, an error shows, or the messages route answers.
 *   A press that starts nothing is pressed once more.
 * - Done only when the page says "You approved the setup.", which it shows once the server has
 *   accepted all three approvals; status codes alone never count.
 * - An error on the page ends the wait at once. Only a passing fault gets one more round.
 * - The public-record notice above the button is ticked first, when this account hasn't ticked it
 *   in this group (tests/e2e/helpers/public-notice.ts). If a press is still stopped by it, the
 *   notice's own error shows and this fails at once with PUBLIC_NOTICE_UNTICKED.
 * - It logs one line of fixed fields: route keys with status codes, the error as a known key,
 *   the button's state, the notice and the confirmations seen.
 */
export async function approveSetup(page: Page, context: BrowserContext, extensionId: string, log: PromptLog, who: string): Promise<string[]> {
  const v7 = setupProgress(page);
  await expect(v7.approveTheSetup).toBeVisible({ timeout: 60_000 });
  await page.waitForLoadState('load', { timeout: 60_000 });
  const panel = page.locator('[data-safe-governance-setup-approve]');
  await expect(panel, `${who}: the approve button has its click handler`).toHaveAttribute('data-once', /(^|\s)safe-governance-setup-approve(\s|$)/, { timeout: 30_000 });
  const notice = await tickPublicNotice(page);
  const alert = panel.locator('[data-safe-governance-errors] .alert-danger');
  const routes: string[] = [];
  let messagesAnswers = 0;
  const onResponse = (r: { url(): string; status(): number }) => {
    const key = setupRouteKey(r.url());
    if (!key) return;
    if (key === 'setup_messages') messagesAnswers++;
    if (routes.length < 20) routes.push(`${key}=${r.status()}`);
  };
  page.on('response', onResponse);
  // Only the three exact sentences are kept, by number; any other "Confirmation … of …" text is
  // counted, never kept, so no page text reaches the log.
  const known = [1, 2, 3].map((n) => fill(COPY.confirmation, n, 3));
  const seen = new Set<number>();
  let others = 0;
  let watching = true;
  const watch = (async () => {
    while (watching) {
      for (const text of await settle(page.getByText(pattern(COPY.confirmation, true)).allInnerTexts(), CHECK_MS, [] as string[])) {
        const index = known.indexOf(text.trim());
        if (index >= 0) seen.add(index + 1);
        else others++;
      }
      await new Promise((r) => setTimeout(r, 250));
    }
  })();
  const notes: string[] = [];
  try {
    for (let round = 1; ; round++) {
      const answersBefore = messagesAnswers;
      await v7.approveTheSetup.click({ timeout: 30_000 });
      let started = false;
      for (const end = Date.now() + 15_000; !started && Date.now() < end; ) {
        if ((await settle(noticeError(page).count(), CHECK_MS, 0)) > 0) {
          notes.push(`round ${round}: the public-record notice stopped the press`);
          await assertNotStoppedByNotice(page);
        }
        started = messagesAnswers > answersBefore || (await settle(alert.count(), CHECK_MS, 0)) > 0 || (await settle(v7.approveTheSetup.isHidden(), CHECK_MS, false));
        if (!started) await page.waitForTimeout(200);
      }
      if (!started) {
        notes.push(`round ${round}: the press started nothing`);
        if (round < 2) continue;
        throw new Error(`${who}: pressing "${COPY.approveTheSetup}" started nothing, twice`);
      }
      let shown = '';
      try {
        await confirmPrompts(context, extensionId, 'approveSetup', log, {
          done: async () => {
            if (await settle(alert.count(), CHECK_MS, 0)) {
              shown = approveAlertKey((await alert.first().textContent({ timeout: 5_000 })) ?? '');
              throw new Error(`${who}: the page showed the "${shown}" error`);
            }
            return settle(v7.youApprovedTheSetup.isVisible(), CHECK_MS, false);
          },
          timeoutMs: 300_000,
          reopenIdleMs: 20_000,
          expect: forSite(SETUP_SIGN_PROMPT, page),
        });
        break;
      } catch (e) {
        if (!shown || round >= 2 || !RETRYABLE.includes(shown)) throw e;
        notes.push(`round ${round}: the page showed the "${shown}" error`);
        await expect(v7.approveTheSetup).toBeVisible({ timeout: 30_000 });
      }
    }
  } finally {
    watching = false;
    await settle(watch, 2 * CHECK_MS, undefined);
    page.off('response', onResponse);
    const hidden = await settle(v7.approveTheSetup.isHidden(), CHECK_MS, true);
    const alerts = await settle(alert.count(), CHECK_MS, -1);
    console.log(`[judge] ${who} approve: ${[`notice ${notice}`, ...notes, `routes ${routes.join(' ') || 'none'}`, `button ${hidden ? 'hidden' : 'shown'}`, `alerts ${alerts}`, `confirmations seen ${[...seen].sort().join(',') || 'none'}`, `other confirmation texts ${others}`].join('; ')}`);
  }
  const sorted = [...seen].sort().map((n) => known[n - 1]);
  expect(others, `${who} saw no other confirmation text`).toBe(0);
  expect(sorted, `${who} read Confirmation 1 of 3 to 3 of 3`).toEqual(known);
  return sorted;
}
