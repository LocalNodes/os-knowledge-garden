// The public-record notice ("What becomes public"), shared by the local and live wallet tests and
// the governance judge.
//
// group_treasury (1.0.0-alpha8, js/public-notice.js) puts the notice above each step that makes
// something public: approving a voting setup, publishing a proposal, casting a vote, and a group
// fund's payment sign and send. localnodes_web3 switches it on in every fresh install
// (_localnodes_web3_set_public_notice). Until the signed-in account ticks the box in that group,
// a click listener on the document stops the step's own click before it runs, and writes "Tick
// the box above …" under the box. The step's routes answer 422 with code "public_notice" too.
// The tick counts once per account per group; after it, the notice renders closed
// (data-public-notice="read") and has no box.
//
// A live run pressed "Approve the setup" twice with the box unticked: nothing started,
// and the judge saw no error, as it watched only the step's own error place.
import type { Locator, Page } from '@playwright/test';
import { actThenWait, fixedStep } from './fixed-failure';

/** The tick was refused or not saved: the site wrote its own sentence under the box. */
export const PUBLIC_NOTICE_NOT_SAVED = 'public-record notice: ticking the box was not saved';
/** The notice's route did not answer the tick in time. */
export const PUBLIC_NOTICE_NO_ANSWER = 'public-record notice: the site did not answer the tick in time';
/** The notice did not show, or its script never bound the box, so a tick would save nothing. */
export const PUBLIC_NOTICE_NOT_BOUND = 'public-record notice: the box has no script bound in time';
/** A press was stopped because the box was not ticked (the notice's own error is showing). */
export const PUBLIC_NOTICE_UNTICKED = 'public-record notice: the press was stopped because the box was not ticked';

/** The notice still waiting for this account's tick, if the page has one. */
export function unreadNotice(page: Page): Locator {
  return page.locator('[data-public-notice="unread"]');
}

/** The notice's own error line, shown when a press is stopped or a tick is refused. */
export function noticeError(page: Page): Locator {
  return page.locator('[data-public-notice-error]:not([hidden])');
}

/**
 * Ticks every public-record notice on the page that is waiting for this account's tick, and
 * returns once the site has saved each one. Returns 'ticked', or 'none' when there is no unread
 * notice (none on the page, the notice is off, or this account ticked it before in this group).
 *
 * - Each unread notice binds its own document click listener for its own buttons
 *   (data-public-notice-steps), so every one is ticked, not just the first.
 * - Each tick is matched to its own notice's route (data-public-notice-url, which names the group).
 * - A press made while the tick is still saving is not lost: the script clicks it again once the
 *   save answers (group_treasury 1.0.0-alpha8 js/public-notice.js, the 'saving' branch).
 * - Every failure throws one of the fixed PUBLIC_NOTICE_* messages and nothing else.
 */
export async function tickPublicNotice(page: Page, timeoutMs = 30_000): Promise<'ticked' | 'none'> {
  await fixedStep(() => page.waitForLoadState('load', { timeout: 60_000 }), PUBLIC_NOTICE_NOT_BOUND);
  const count = await fixedStep(() => unreadNotice(page).count(), PUBLIC_NOTICE_NOT_BOUND);
  for (let index = 0; index < count; index++) await tickOne(page, index, timeoutMs);
  return count > 0 ? 'ticked' : 'none';
}

async function tickOne(page: Page, index: number, timeoutMs: number): Promise<void> {
  const notice = unreadNotice(page).nth(index);
  await fixedStep(() => notice.waitFor({ state: 'visible', timeout: timeoutMs }), PUBLIC_NOTICE_NOT_BOUND);
  // once('public-notice', …) marks the notice when the behaviour has bound its listeners.
  await fixedStep(
    () => page.waitForFunction(
      (i) => {
        const el = document.querySelectorAll('[data-public-notice="unread"]')[i];
        return !!el && /(^|\s)public-notice(\s|$)/.test(el.getAttribute('data-once') ?? '');
      },
      index,
      { timeout: timeoutMs },
    ),
    PUBLIC_NOTICE_NOT_BOUND,
  );
  const route = await fixedStep(() => notice.getAttribute('data-public-notice-url', { timeout: timeoutMs }), PUBLIC_NOTICE_NOT_BOUND);
  if (!route) throw new Error(PUBLIC_NOTICE_NOT_BOUND);
  const path = await fixedStep(async () => new URL(route, page.url()).pathname, PUBLIC_NOTICE_NOT_BOUND);
  const box = notice.getByRole('checkbox');
  const response = await actThenWait(
    page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname === path, { timeout: timeoutMs }),
    () => box.check({ timeout: timeoutMs }),
    PUBLIC_NOTICE_NOT_SAVED,
    PUBLIC_NOTICE_NO_ANSWER,
  );
  if (!response.ok()) throw new Error(PUBLIC_NOTICE_NOT_SAVED);
  const body: unknown = await fixedStep(() => response.json(), PUBLIC_NOTICE_NOT_SAVED);
  if (typeof body !== 'object' || body === null || (body as { ticked?: unknown }).ticked !== true) throw new Error(PUBLIC_NOTICE_NOT_SAVED);
  // The script refuses a failed save by unticking the box, so it must still be ticked.
  if (!(await fixedStep(() => box.isChecked({ timeout: timeoutMs }), PUBLIC_NOTICE_NOT_SAVED))) throw new Error(PUBLIC_NOTICE_NOT_SAVED);
}

/** Fails with PUBLIC_NOTICE_UNTICKED when the notice's own error is showing. */
export async function assertNotStoppedByNotice(page: Page): Promise<void> {
  if (await fixedStep(() => noticeError(page).count(), PUBLIC_NOTICE_UNTICKED)) throw new Error(PUBLIC_NOTICE_UNTICKED);
}
