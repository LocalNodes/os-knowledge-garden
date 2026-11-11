// Phase 23 sign-up screens on the Nuxt site (23-20; D-24, ROADMAP SC9;
// 23-UI-SPEC.md "Accessibility checklist" rows 1 to 7).
//
// axe (WCAG 2.2 AA tags) over every new or changed sign-up screen state:
// invite entry, link landing, the sign-up form, /success (progress,
// failure and retry, stopped, welcome; 23-37: the payment step, held for the
// owner and for another session, declined, payment not confirmed; 23-43 Task 7:
// removing, refunding, refunded) and
// /unsubscribe (23-19 follow-up).
// Each result is saved to the Phase 23 folder as axe-<screen>-<state>.json.
//
// Runs in the "onboarding" project against the Nuxt dev server, with no
// Drupal and no DDEV:
//   NUXT_LAUNCH_OPEN=true npm --prefix ../localnodes-onboarding run dev
//   npx --no-install playwright test -c tests/a11y/playwright.config.ts --project onboarding
// LN_ONBOARDING_URL overrides http://localhost:3000.
//
// No live calls: the dev server runs with no Stripe, Resend, Upstash, Gemini,
// OpenRouter, Coolify or GitHub keys, and every sign-up API the browser calls
// is answered with page.route (helpers/onboarding-mocks.ts). Two reads happen while the server renders
// /onboarding (room and price, 23-19): with no Redis the room read fails and the
// page shows "Waiting for Room". So the form states load /invite first, answer
// the room and price reads in the browser, and move to /onboarding with the
// client router, which runs those reads in the browser.
//
// F8 (23-39; orchestrator 2026-11-08; 23-UI-SPEC sections 2 and 5): the
// expired-link box and the failure box take focus and carry no alert role, so
// the focus move reads them once. They are found by their text inside their
// tabindex -1 div (named by tag: main also has tabindex -1 since 23-39 and
// contains every alert's text). Alerts that take no focus keep the role.
//
// The mouse is moved off any button before a scan, so a state is scanned as it
// is, not as a hover left over from a click shows it. The hover colours have
// their own check at the end ("pointer hover keeps text contrast"): Nuxt UI's
// primary hover was 3.49:1 (23-19 finding), fixed in 23-29.
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import AxeBuilder from '@axe-core/playwright';
import { test, expect, type Page } from '@playwright/test';
import { AXE_TAGS, PHASE23_RESULTS_DIR, checkA11y } from './helpers/axe';
import { startSignupServer, startUpstashStub, type SignupServer, type UpstashStub } from './helpers/onboarding-ssr';
import {
  CODE_REFUSED,
  HELD_UNTIL,
  INVITED,
  SCAN,
  SESSION,
  SITE,
  json,
  mockAiKey,
  mockCheckCode,
  mockInviteRequest,
  mockInviteVerify,
  mockStatus,
  mockSuggest,
  mockUnsubscribePost,
  mouseOff,
  ONBOARDING_URL,
  open,
  openForm,
  pilotPrice,
  requireOnboardingSite,
  statusReply,
} from './helpers/onboarding-mocks';

// The sign-up API answers, open() (which waits for hydration) and openForm()
// live in helpers/onboarding-mocks.ts, shared with 23-29's keyboard and
// screen-reader specs.

// Picked with --project onboarding, a sign-up site that isn't running fails the
// run. In a run of every project (the Drupal suite), it skips instead, so the
// Drupal suite doesn't need the Nuxt server (playwright.config.ts sets
// LN_ONBOARDING_PICKED).
test.beforeAll(async () => {
  await requireOnboardingSite(test.skip);
});

/** A box that takes focus (tabindex -1), found by its text, not by role (F8, 23-39). */
const focusedBox = (page: Page, text: string) => page.locator('div[tabindex="-1"]').filter({ hasText: text });

// --- 0. Landing page (/) -----------------------------------------------------------
// The v5 home page: hero with the
// "Show me a …" switcher in the prototype's order, then Decide, Fund, Yours and the close.
// The page's motion, CLS, JS-off and contrast checks live in landing-v5.spec.ts; this keeps the
// shape scan wide and at 320 CSS px. The old "Who it's for" and "See it in action" sections
// left the page with v5 (their copy test stays in the sign-up site for now).

test.describe('landing page', () => {
  const SWITCHER = ['neighborhood group', 'pop-up village', 'housing co-op', 'guild'];
  const SECTIONS = [
    'Decide in the open, with everyone who has a say.',
    'A fund the group controls, and can see.',
    "It's your site. Take it with you.",
    'Be one of the first communities in.',
  ];

  async function openLanding(page: Page) {
    await open(page, '/');
    await expect(page.getByRole('heading', { level: 1, name: 'Where your community thinks, decides and funds together.' })).toBeVisible();
    await expect(page.getByText('Your community owns it, not a platform.')).toBeVisible();
  }
  const switcher = (page: Page) => page.getByRole('group', { name: 'Example community' }).getByRole('button').allInnerTexts();
  const sectionHeadings = (page: Page) => page.locator('main').getByRole('heading', { level: 2 }).allInnerTexts();

  test('default', async ({ page }) => {
    await openLanding(page);
    expect((await switcher(page)).map((t) => t.trim())).toEqual(SWITCHER);
    expect((await sectionHeadings(page)).map((t) => t.trim())).toEqual(SECTIONS);
    await checkA11y(page, 'landing', 'default', SCAN);
  });

  // Scoped to main: the site header at 320 CSS px is tracked separately.
  test('narrow (320 CSS px): nothing in main reaches past the viewport', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 800 });
    await openLanding(page);
    expect((await sectionHeadings(page)).map((t) => t.trim())).toEqual(SECTIONS);
    const past = await page.evaluate(() => {
      const width = document.documentElement.clientWidth;
      // The hero's rings and source paper reach past the window by design (prototype); the hero
      // band clips them (overflow hidden), so a box counts only when no ancestor clips it.
      const clipped = (el: Element) => {
        for (let e = el.parentElement; e; e = e.parentElement) if (getComputedStyle(e).overflowX !== 'visible') return true;
        return false;
      };
      return [...document.querySelectorAll('main *')]
        .filter((el) => { const r = el.getBoundingClientRect(); return r.width > 0 && r.right > width + 1 && !clipped(el); })
        .map((el) => `${el.tagName.toLowerCase()}: ${(el.textContent ?? '').trim().slice(0, 40)}`);
    });
    expect(past, 'nothing in main reaches past 320 CSS px').toEqual([]);
    await checkA11y(page, 'landing', 'narrow', SCAN);
  });
});

// --- 1. Invite entry (/invite) --------------------------------------------------

test.describe('invite entry', () => {
  test.beforeEach(async ({ page }) => {
    await open(page, '/invite');
    await expect(page.getByRole('heading', { level: 1, name: 'Use Your Invite' })).toBeVisible();
    await expect(page).toHaveTitle('Use Your Invite - LocalNodes');
  });

  test('empty', async ({ page }) => {
    await checkA11y(page, 'invite', 'empty', SCAN);
  });

  test('error (address)', async ({ page }) => {
    await page.getByLabel('Email').fill('not-an-address');
    await page.getByRole('button', { name: 'Send my sign-in link' }).click();
    await expect(page.getByText('Fix these to continue')).toBeVisible();
    await mouseOff(page);
    await checkA11y(page, 'invite', 'error-address', SCAN);
  });

  test('error (too many tries)', async ({ page }) => {
    await mockInviteRequest(page, 429, { message: 'Too Many Requests' });
    await page.getByLabel('Email').fill(INVITED.email);
    await page.getByRole('button', { name: 'Send my sign-in link' }).click();
    await expect(page.getByText('Too many tries from this connection. Wait a few minutes, then try again.')).toBeVisible();
    await mouseOff(page);
    await checkA11y(page, 'invite', 'too-many', SCAN);
  });

  test('sent', async ({ page }) => {
    await mockInviteRequest(page, 200, { ok: true });
    await page.getByLabel('Email').fill(INVITED.email);
    await page.getByRole('button', { name: 'Send my sign-in link' }).click();
    await expect(page.getByRole('heading', { level: 2, name: 'Check your email' })).toBeVisible();
    await expect(page.getByText('You can send it again in a minute.')).toBeVisible();
    await mouseOff(page);
    await checkA11y(page, 'invite', 'sent', SCAN);
  });
});

// --- 2. Link landing (/invite/verify) --------------------------------------------

test.describe('link landing', () => {
  test('ready', async ({ page }) => {
    // A well-formed token in the fragment; nothing is posted until the button.
    await open(page, `/invite/verify#t=${'A'.repeat(43)}`);
    await expect(page.getByRole('heading', { level: 1, name: 'Your Invite Link' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Continue to sign-up' })).toBeVisible();
    await checkA11y(page, 'verify', 'ready', SCAN);
  });

  test('expired or used', async ({ page }) => {
    await mockInviteVerify(page, 400, { message: 'Bad Request' });
    await open(page, `/invite/verify#t=${'B'.repeat(43)}`);
    await page.getByRole('button', { name: 'Continue to sign-up' }).click();
    await expect(page.getByText('This link has expired or was already used. Ask for a new one.')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Get a new sign-in link' })).toBeVisible();
    // F8: focused, read once by the focus move, no alert role.
    const box = focusedBox(page, 'This link has expired or was already used.');
    await expect(box).toBeFocused();
    expect(await box.getAttribute('role'), 'the focused box has no role').toBeNull();
    await expect(page.getByRole('alert').filter({ hasText: 'This link has expired or was already used.' })).toHaveCount(0);
    await mouseOff(page);
    await checkA11y(page, 'verify', 'expired', SCAN);
  });
});

// --- 3. Sign-up (/onboarding) ----------------------------------------------------

test.describe('sign-up', () => {
  test('full server (read while the server renders)', async ({ page }) => {
    await open(page, '/onboarding');
    await expect(page.getByRole('heading', { level: 1, name: 'Waiting for Room' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Continue to card details' })).toHaveCount(0);
    await checkA11y(page, 'onboarding', 'full', SCAN);
  });

  test('filled', async ({ page }) => {
    await openForm(page);
    await page.getByLabel('Community name').fill('Repair Collective');
    await expect(page.getByRole('status').filter({ hasText: 'Available' })).toHaveText('Available');
    // The invited address shows read-only (ln-invite-details).
    await expect(page.locator('input[readonly]')).toHaveValue(INVITED.email);
    await checkA11y(page, 'onboarding', 'filled', SCAN);
  });

  test('error summary', async ({ page }) => {
    await openForm(page);
    await page.getByRole('button', { name: 'Continue to card details' }).click();
    await expect(page.getByText('Fix these to continue')).toBeVisible();
    await mouseOff(page);
    await checkA11y(page, 'onboarding', 'error-summary', SCAN);
  });

  test('code error', async ({ page }) => {
    await mockCheckCode(page, 409, { message: CODE_REFUSED });
    await openForm(page);
    // A pilot invite's code is already in the field ("Your invite includes this code.").
    await expect(page.getByText('Your invite includes this code.')).toBeVisible();
    await page.getByRole('button', { name: 'Apply code' }).click();
    await expect(page.getByText(CODE_REFUSED)).toBeVisible();
    await mouseOff(page);
    await checkA11y(page, 'onboarding', 'code-error', SCAN);
  });

  // The server says what the invite's code offers when the price is read, so a
  // pilot invite opens a form that already reads as a pilot, before anyone
  // presses "Apply code". In page order the price line comes after the code field.
  test('pilot invite reads as a pilot before Apply code', async ({ page }) => {
    await openForm(page, pilotPrice(3));
    await expect(page.getByText('Your invite includes this code.')).toBeVisible();
    const priceLine = page.getByText('Free for three months, then $20 a month only if you choose to continue.', { exact: true });
    await expect(priceLine).toBeVisible();
    await expect(page.getByText('We save your card now; your three-month pilot costs nothing, and we charge it only if you choose to keep the site.')).toBeVisible();
    await expect(page.getByText('We keep it so you won\'t need to enter it again if you choose to continue.')).toBeVisible();
    await expect(page.getByText('We\'ll save your card now and charge it only once your site is live.')).toHaveCount(0);
    const before = await page.evaluate(() => {
      const code = document.querySelector('#promo-code-panel');
      const line = [...document.querySelectorAll('p')].find((p) => p.textContent?.startsWith('Free for three months'));
      return !!code && !!line && !!(code.compareDocumentPosition(line) & Node.DOCUMENT_POSITION_FOLLOWING);
    });
    expect(before, 'the price line follows the code field in the page').toBe(true);
    await expect(page.getByRole('button', { name: 'Apply code' })).toBeVisible();
    await mouseOff(page);
    await checkA11y(page, 'onboarding', 'pilot-invite', SCAN);
  });

  // The code is asked about only when there is one: an empty field, or one that
  // holds only spaces, goes straight to the card page with no check.
  for (const [name, typed] of [['an empty code', ''], ['a code of only spaces', '   ']] as const) {
    test(`submitting with ${name} sends no code check and goes to the card page`, async ({ page }) => {
      const checks: string[] = [];
      const checkouts: Array<Record<string, unknown>> = [];
      await page.route('**/api/check-code', (route) => { checks.push(route.request().url()); return json(route, 200, { ok: true, offer: 'paid' }); });
      const cardPage = new URL('/card-page-stub', ONBOARDING_URL).href;
      await page.route('**/api/create-checkout', (route) => { checkouts.push(route.request().postDataJSON()); return json(route, 200, { url: cardPage }); });
      await page.route('**/card-page-stub', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<title>Card page</title><h1>Card page</h1>' }));
      await openForm(page);
      await page.getByLabel('Community name').fill('Repair Collective');
      await expect(page.getByRole('status').filter({ hasText: 'Available' })).toHaveText('Available');
      // The invite's own code is in the field; clear it to what this case types.
      await page.getByLabel('Code', { exact: true }).fill(typed);
      await page.getByRole('button', { name: 'Continue to card details' }).click();
      await page.waitForURL('**/card-page-stub');
      expect(checks, 'no request to check a code').toEqual([]);
      expect(checkouts).toHaveLength(1);
      expect(String(checkouts[0]!.code ?? '').trim()).toBe('');
    });
  }

  // With the launch open the invite details are not given to the page, yet the
  // server still reads the invite cookie at checkout. The price reply says the
  // session is live, so a typed code is checked before anyone is sent on.
  test('launch open with an invite cookie: a typed code is checked on submit', async ({ page }) => {
    const checks: unknown[] = [];
    let checkouts = 0;
    await page.route('**/api/check-code', (route) => { checks.push(route.request().postDataJSON()); return json(route, 200, { ok: true, offer: 'pilot', months: 3 }); });
    await page.route('**/api/create-checkout', (route) => { checkouts += 1; return json(route, 200, { url: new URL('/card-page-stub', ONBOARDING_URL).href }); });
    await openForm(page, { line: '$20 a month', invited: true }, null);
    await page.getByLabel('Community name').fill('Repair Collective');
    await expect(page.getByRole('status').filter({ hasText: 'Available' })).toHaveText('Available');
    await page.getByLabel('Email').fill('organizer@example.org');
    await page.getByRole('button', { name: 'I have a code' }).click();
    await page.getByLabel('Code', { exact: true }).fill('PILOT-TYPED');
    await page.getByRole('button', { name: 'Continue to card details' }).click();
    await expect(page.getByText('Free for three months, then $20 a month only if you choose to continue.', { exact: true })).toBeVisible();
    expect(checks).toEqual([{ code: 'PILOT-TYPED' }]);
    expect(checkouts, 'nothing is sent to checkout until the new words are shown and the button is pressed again').toBe(0);
  });

  test('launch open with no invite cookie: a typed code is not checked', async ({ page }) => {
    const checks: unknown[] = [];
    await page.route('**/api/check-code', (route) => { checks.push(1); return json(route, 404, { message: 'Not Found' }); });
    const checkouts: Array<Record<string, unknown>> = [];
    await page.route('**/api/create-checkout', (route) => { checkouts.push(route.request().postDataJSON()); return json(route, 200, { url: new URL('/card-page-stub', ONBOARDING_URL).href }); });
    await page.route('**/card-page-stub', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<title>Card page</title>' }));
    await openForm(page, { line: '$20 a month' }, null);
    await page.getByLabel('Community name').fill('Repair Collective');
    await expect(page.getByRole('status').filter({ hasText: 'Available' })).toHaveText('Available');
    await page.getByLabel('Email').fill('organizer@example.org');
    await page.getByRole('button', { name: 'I have a code' }).click();
    await page.getByLabel('Code', { exact: true }).fill('PILOT-TYPED');
    await page.getByRole('button', { name: 'Continue to card details' }).click();
    await page.waitForURL('**/card-page-stub');
    expect(checks).toEqual([]);
    expect(checkouts).toHaveLength(1);
  });

  test('AI key checked', async ({ page }) => {
    await mockAiKey(page, 200, { ok: true });
    await openForm(page);
    await page.getByRole('radio', { name: 'Your own Gemini account' }).check();
    await page.getByRole('textbox', { name: 'Gemini key' }).fill('test-key-not-real-0000000000000000');
    await page.getByLabel('This key is from a Google Cloud project with billing turned on').check();
    await page.getByRole('button', { name: 'Check key' }).click();
    await expect(page.getByText('Key checked', { exact: true }).first()).toBeVisible();
    await expect(page.getByRole('button', { name: 'Replace key' })).toBeVisible();
    await mouseOff(page);
    await checkA11y(page, 'onboarding', 'key-checked', SCAN);
  });
});

// --- 4 to 7. /success: progress, failure and retry, stopped, welcome ---------------

test.describe('success page', () => {
  const status = mockStatus;
  const heading = (page: Page, name: string) => page.getByRole('heading', { level: 1, name });

  test('progress', async ({ page }) => {
    await status(page, { status: 'provisioning', siteUrl: null, reason: null, canRetry: false, stopped: false });
    await open(page, `/success?session_id=${SESSION}`);
    await expect(heading(page, 'Setting Up Your Site')).toBeVisible();
    await expect(page).toHaveTitle('Setting Up Your Site - LocalNodes');
    // The first poll lands about 3 seconds after the page starts.
    await expect(page.locator('[aria-live="polite"]')).toHaveText('Setting up your server.', { timeout: 15_000 });
    await expect(page.getByText('Card saved')).toBeVisible();
    await expect(page.getByText('We\'ll email your sign-in link when your site is ready.')).toBeVisible();
    // No number of minutes is promised and no countdown shows.
    await expect(page.getByText('This usually takes a few minutes. We\'ll email you when it\'s ready.', { exact: true })).toBeVisible();
    await expect(page.getByText(/minutes? left|about 4 minutes/i)).toHaveCount(0);
    await checkA11y(page, 'success', 'progress', SCAN);
  });

  test('progress, no session', async ({ page }) => {
    await open(page, '/success');
    await expect(heading(page, 'Nothing to Show Here')).toBeVisible();
    await expect(page.getByText('There\'s nothing to show yet. Open the sign-in link from your email to set up your community\'s site.')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Back to the home page' })).toBeVisible();
    await checkA11y(page, 'success', 'no-session', SCAN);
  });

  // 23-20 follow-up (2026-11-08): a session id the server does not know (no
  // state and no checkout marker) answers notFound, and the page shows the
  // same empty state, never the build steps.
  test('progress, unknown session', async ({ page }) => {
    await status(page, { status: 'unknown', notFound: true });
    await open(page, `/success?session_id=${SESSION}`);
    await expect(heading(page, 'Nothing to Show Here')).toBeVisible({ timeout: 15_000 });
    await expect(page).toHaveTitle('Nothing to Show Here - LocalNodes');
    await expect(page.getByText('There\'s nothing to show yet. Open the sign-in link from your email to set up your community\'s site.')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Back to the home page' })).toBeVisible();
    await expect(page.getByText('Card saved')).toHaveCount(0);
    await checkA11y(page, 'success', 'unknown-session', SCAN);
  });

  test('progress, status cannot be read', async ({ page }) => {
    await status(page, { message: 'Internal Server Error' }, 500);
    await open(page, `/success?session_id=${SESSION}`);
    await expect(page.getByText('Couldn\'t check your site\'s progress right now. We\'ll keep trying, and we\'ll email you.')).toBeVisible({ timeout: 15_000 });
    await checkA11y(page, 'success', 'status-unreadable', SCAN);
  });

  test('failed, retry available', async ({ page }) => {
    await status(page, { status: 'failed', siteUrl: null, reason: 'deploy_failed', canRetry: true, stopped: false });
    await open(page, `/success?session_id=${SESSION}`);
    const alert = focusedBox(page, 'Setting up your site didn\'t finish. You haven\'t been charged.');
    await expect(alert).toBeVisible({ timeout: 15_000 });
    await expect(alert).toContainText('We couldn\'t start your site.');
    await expect(alert).toBeFocused();
    expect(await alert.getAttribute('role'), 'the focused box has no role (F8)').toBeNull();
    await expect(page.getByRole('alert')).toHaveCount(0);
    await expect(page.getByText('Try setting up your site once more. If it still doesn\'t work, we\'ll stop. You won\'t be charged.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Retry site setup' })).toBeVisible();
    await expect(page.getByText('deploy_failed')).toHaveCount(0);
    await checkA11y(page, 'success', 'failed', SCAN);
  });

  test('failed, reason unknown', async ({ page }) => {
    await status(page, { status: 'failed', siteUrl: null, reason: null, canRetry: true, stopped: false });
    await open(page, `/success?session_id=${SESSION}`);
    const alert = focusedBox(page, 'Something went wrong on our side.');
    await expect(alert).toBeVisible({ timeout: 15_000 });
    await expect(alert).toBeFocused();
    expect(await alert.getAttribute('role'), 'the focused box has no role (F8)').toBeNull();
    await checkA11y(page, 'success', 'reason-unknown', SCAN);
  });

  test('failed, retrying', async ({ page }) => {
    await status(page, { status: 'failed', siteUrl: null, reason: 'deploy_failed', canRetry: true, stopped: false });
    // The retry request stays in flight for the scan.
    await page.route('**/api/retry-provisioning', () => new Promise<void>(() => {}));
    await open(page, `/success?session_id=${SESSION}`);
    await page.getByRole('button', { name: 'Retry site setup' }).click({ timeout: 15_000 });
    const button = page.getByRole('button', { name: 'Retrying…' });
    await expect(button).toBeVisible();
    await expect(button).toBeDisabled();
    await expect(page.locator('[aria-live="polite"]')).toHaveText('Retrying site setup.');
    await mouseOff(page);
    await checkA11y(page, 'success', 'retrying', SCAN);
  });

  test('failed, retry request failed', async ({ page }) => {
    await status(page, { status: 'failed', siteUrl: null, reason: 'deploy_failed', canRetry: true, stopped: false });
    await page.route('**/api/retry-provisioning', (route) => json(route, 503, { message: 'Service Unavailable' }));
    await open(page, `/success?session_id=${SESSION}`);
    await page.getByRole('button', { name: 'Retry site setup' }).click({ timeout: 15_000 });
    await expect(page.getByText('We couldn\'t start the retry. Try again in a moment.')).toBeVisible();
    // This alert takes no focus (the button comes back), so it keeps the alert role.
    await expect(page.getByRole('alert').filter({ hasText: 'We couldn\'t start the retry.' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Retry site setup' })).toBeEnabled();
    await mouseOff(page);
    await checkA11y(page, 'success', 'retry-failed', SCAN);
  });

  test('failed, no retry yet and not stopped', async ({ page }) => {
    // Attempt 2: a site's own failure inside its 10-minute window, or a stop
    // still running (23-14). The next-step line shows under the alert (23-38;
    // orchestrator rule C and ruling point 1 (c), 2026-11-08).
    await status(page, { status: 'failed', siteUrl: null, reason: 'install_failed', canRetry: false, stopped: false, attempt: 2 });
    await open(page, `/success?session_id=${SESSION}`);
    const alert = focusedBox(page, 'We couldn\'t finish setting up your site.');
    await expect(alert).toBeVisible({ timeout: 15_000 });
    await expect(alert).toBeFocused();
    expect(await alert.getAttribute('role'), 'the focused box has no role (F8)').toBeNull();
    const line = page.getByText('We\'re finishing up and will email you about what happens next.', { exact: true });
    await expect(line).toBeVisible();
    await expect(line).toHaveCount(1);
    // The line sits under the alert, outside it, so the alert still ends with the reason.
    await expect(alert).not.toContainText('finishing up');
    await expect(page.getByText('Try setting up your site once more.', { exact: false })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Retry site setup' })).toHaveCount(0);
    await checkA11y(page, 'success', 'failed-no-retry', SCAN);
  });

  test('failed, no retry yet and not stopped, attempt 1', async ({ page }) => {
    // Attempt 1 inside its window: the alert only; the window ends in the free retry (23-38).
    await status(page, { status: 'failed', siteUrl: null, reason: 'install_failed', canRetry: false, stopped: false, attempt: 1 });
    await open(page, `/success?session_id=${SESSION}`);
    const alert = focusedBox(page, 'We couldn\'t finish setting up your site.');
    await expect(alert).toBeVisible({ timeout: 15_000 });
    await expect(alert).toBeFocused();
    expect(await alert.getAttribute('role'), 'the focused box has no role (F8)').toBeNull();
    await expect(page.getByText('finishing up', { exact: false })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Retry site setup' })).toHaveCount(0);
    await checkA11y(page, 'success', 'failed-no-retry-attempt-1', SCAN);
  });

  test('stopped', async ({ page }) => {
    await status(page, { status: 'failed', siteUrl: null, reason: 'deploy_failed', canRetry: false, stopped: true });
    await open(page, `/success?session_id=${SESSION}`);
    await expect(heading(page, 'We Couldn\'t Set Up Your Site')).toBeVisible({ timeout: 15_000 });
    await expect(page).toHaveTitle('We Couldn\'t Set Up Your Site - LocalNodes');
    await expect(page.getByRole('alert')).toHaveText('We couldn\'t set up your site. You weren\'t charged, and we\'ve removed your saved card.');
    await expect(page.getByText('We\'ve emailed you the details.')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Back to the LocalNodes home page' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Retry site setup' })).toHaveCount(0);
    await checkA11y(page, 'success', 'stopped', SCAN);
  });

  // --- 23-37: the first payment (B-1; 23-UI-SPEC sections 4, 6a and 6b) ----------
  // The held screen's "Confirm my payment" is a form POST shown only to the
  // browser that made the sign-up (canConfirm, Tom's answer 3); the hosted
  // invoice address is in no page's HTML, for either session (T-23-188).

  test('payment step (ready)', async ({ page }) => {
    await status(page, statusReply('ready', { pilot: false }));
    await open(page, `/success?session_id=${SESSION}`);
    await expect(page.locator('[aria-live="polite"]')).toHaveText('Taking your first payment.', { timeout: 15_000 });
    await expect(heading(page, 'Setting Up Your Site')).toBeVisible();
    await expect(page.getByText('Taking your first payment', { exact: true })).toBeVisible();
    await checkA11y(page, 'success', 'payment', SCAN);
  });

  test('payment step (ready, a free pilot)', async ({ page }) => {
    await status(page, statusReply('ready', { pilot: true }));
    await open(page, `/success?session_id=${SESSION}`);
    await expect(page.locator('[aria-live="polite"]')).toHaveText('Confirming your free pilot.', { timeout: 15_000 });
    await expect(page.getByText('Confirming your free pilot', { exact: true })).toBeVisible();
    await expect(page.getByText('Taking your first payment')).toHaveCount(0);
  });

  const heldBody = /^Your bank needs you to confirm the first payment\. We've emailed you a link\. Your site is ready and waits for you until November (8|9|10), 2026 at \d{1,2}:\d{2}(am|pm) \S+\.$/;

  test('held, the browser that made the sign-up', async ({ page }) => {
    await status(page, statusReply('held', { heldUntil: HELD_UNTIL, canConfirm: true }));
    await open(page, `/success?session_id=${SESSION}`);
    const h1 = heading(page, 'Confirm Your Payment');
    await expect(h1).toBeVisible({ timeout: 15_000 });
    await expect(h1).toBeFocused();
    await expect(page).toHaveTitle('Confirm Your Payment - LocalNodes');
    await expect(page.getByText(heldBody)).toBeVisible();
    const button = page.getByRole('button', { name: 'Confirm my payment' });
    await expect(button).toBeVisible();
    await expect(button).toHaveAttribute('type', 'submit');
    const form = page.locator('form[method="post"][action="/api/confirm-payment"]');
    await expect(form).toHaveCount(1);
    await expect(form.locator('input[type="hidden"][name="session_id"]')).toHaveValue(SESSION);
    await expect(page.getByRole('alert')).toHaveCount(0);
    expect(await page.content(), 'no payment page address in the HTML').not.toContain('invoice.stripe.com');
    await mouseOff(page);
    await checkA11y(page, 'success', 'held-owner', SCAN);
  });

  test('held, another session', async ({ page }) => {
    await status(page, statusReply('held', { heldUntil: HELD_UNTIL, canConfirm: false }));
    await open(page, `/success?session_id=${SESSION}`);
    const h1 = heading(page, 'Confirm Your Payment');
    await expect(h1).toBeVisible({ timeout: 15_000 });
    await expect(h1).toBeFocused();
    await expect(page.getByText(heldBody)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Confirm my payment' })).toHaveCount(0);
    await expect(page.locator('form[action="/api/confirm-payment"]')).toHaveCount(0);
    expect(await page.content(), 'no payment page address in the HTML').not.toContain('invoice.stripe.com');
    await checkA11y(page, 'success', 'held-other', SCAN);
  });

  test('declined', async ({ page }) => {
    await status(page, statusReply('declined', { heldExpired: false }));
    await open(page, `/success?session_id=${SESSION}`);
    const h1 = heading(page, 'Your Card Was Declined');
    await expect(h1).toBeVisible({ timeout: 15_000 });
    await expect(h1).toBeFocused();
    await expect(page).toHaveTitle('Your Card Was Declined - LocalNodes');
    await expect(page.getByText('Your card was declined, so we didn\'t finish setting up your site. You weren\'t charged.')).toBeVisible();
    await expect(page.getByRole('alert')).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Try again' })).toHaveAttribute('href', '/invite');
    await expect(page.getByRole('button', { name: 'Retry site setup' })).toHaveCount(0);
    await mouseOff(page);
    await checkA11y(page, 'success', 'declined', SCAN);
  });

  test('payment not confirmed (the 3DS hold ran out)', async ({ page }) => {
    // 23-43 Task 7: "removed" only once the delete is confirmed; "You weren't charged" only with noCapture.
    await status(page, statusReply('declined', { heldExpired: true, removed: true, noCapture: true, refundProven: false }));
    await open(page, `/success?session_id=${SESSION}`);
    const h1 = heading(page, 'We\'ve Removed Your Site');
    await expect(h1).toBeVisible({ timeout: 15_000 });
    await expect(h1).toBeFocused();
    await expect(page).toHaveTitle('We\'ve Removed Your Site - LocalNodes');
    await expect(page.getByText('We didn\'t get your payment confirmation in time, so we\'ve removed your site. You weren\'t charged.')).toBeVisible();
    await expect(page.getByRole('alert')).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Try again' })).toHaveAttribute('href', '/invite');
    await expect(page.getByText('Your card was declined', { exact: false })).toHaveCount(0);
    await mouseOff(page);
    await checkA11y(page, 'success', 'not-confirmed', SCAN);
  });

  // 23-43 Task 7 (23-UI-SPEC.md "Billing copy additions for 23-43", P3, P6, P2).
  test('removing (the 3DS hold ran out, the delete not yet confirmed)', async ({ page }) => {
    await status(page, statusReply('declined', { heldExpired: true, removed: false, noCapture: true, refundProven: false }));
    await open(page, `/success?session_id=${SESSION}`);
    const h1 = heading(page, 'Removing Your Site');
    await expect(h1).toBeVisible({ timeout: 15_000 });
    await expect(h1).toBeFocused();
    await expect(page).toHaveTitle('Removing Your Site - LocalNodes');
    await expect(page.getByText('We didn\'t get your payment confirmation in time, so we\'re removing your site. You weren\'t charged.')).toBeVisible();
    await expect(page.getByRole('alert')).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Try again' })).toHaveAttribute('href', '/invite');
    await mouseOff(page);
    await checkA11y(page, 'success', 'removing', SCAN);
  });

  test('refunding (P6, the waiting screen)', async ({ page }) => {
    await status(page, statusReply('refunding', { lateActivation: false }));
    await open(page, `/success?session_id=${SESSION}`);
    const h1 = heading(page, 'Refunding Your Payment');
    await expect(h1).toBeVisible({ timeout: 15_000 });
    await expect(h1).toBeFocused();
    await expect(page).toHaveTitle('Refunding Your Payment - LocalNodes');
    await expect(page.getByText('We couldn\'t finish setting up your site, so we\'re refunding your payment.')).toBeVisible();
    await expect(page.getByRole('alert')).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Try again' })).toHaveCount(0);
    await expect(page.getByText('Taking your first payment')).toHaveCount(0);
    await mouseOff(page);
    await checkA11y(page, 'success', 'refunding', SCAN);
  });

  test('refunded (P2)', async ({ page }) => {
    await status(page, statusReply('refunded', { lateActivation: false }));
    await open(page, `/success?session_id=${SESSION}`);
    const h1 = heading(page, 'We\'ve Refunded Your Payment');
    await expect(h1).toBeVisible({ timeout: 15_000 });
    await expect(h1).toBeFocused();
    await expect(page).toHaveTitle('We\'ve Refunded Your Payment - LocalNodes');
    await expect(page.getByText('We couldn\'t finish setting up your site, so we\'ve refunded your payment in full.')).toBeVisible();
    await expect(page.getByRole('alert')).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Try again' })).toHaveAttribute('href', '/invite');
    await mouseOff(page);
    await checkA11y(page, 'success', 'refunded', SCAN);
  });

  test('welcome', async ({ page }) => {
    await status(page, { status: 'complete', siteUrl: SITE, reason: null, canRetry: false, stopped: false });
    await open(page, `/success?session_id=${SESSION}`);
    const h1 = heading(page, 'Your Community\'s Site Is Ready');
    await expect(h1).toBeVisible({ timeout: 15_000 });
    await expect(h1).toBeFocused();
    await expect(page).toHaveTitle('Your Community\'s Site Is Ready - LocalNodes');
    await expect(page.getByText('Your site: repair.localnodes.xyz')).toBeVisible();
    await expect(page.getByText('Check your email for your sign-in link.')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Open your site (opens another site)' })).toHaveAttribute('href', SITE);
    await expect(page.getByRole('link', { name: /Read the organizer guide/ })).toHaveAttribute('href', `${SITE}/guide`);
    // No sign-in link or password button anywhere (D-13).
    await expect(page.getByRole('link', { name: /sign in|log in|password/i })).toHaveCount(0);
    await expect(page.getByRole('button', { name: /password/i })).toHaveCount(0);
    await checkA11y(page, 'success', 'welcome', SCAN);
  });

  test('welcome, no site address', async ({ page }) => {
    await status(page, { status: 'complete', siteUrl: null, reason: null, canRetry: false, stopped: false });
    await open(page, `/success?session_id=${SESSION}`);
    await expect(heading(page, 'Your Community\'s Site Is Ready')).toBeVisible({ timeout: 15_000 });
    await expect(page.getByRole('link', { name: 'Back to the LocalNodes home page' })).toBeVisible();
    await expect(page.getByRole('link', { name: /Open your site/ })).toHaveCount(0);
    await checkA11y(page, 'success', 'welcome-no-address', SCAN);
  });
});

// --- /unsubscribe (23-19 follow-up; 23-UI-SPEC "Unsubscribe page") ---------------

test.describe('unsubscribe page', () => {
  // A token-shaped value: the page only checks the shape, and every POST is
  // answered here, so Resend is never called.
  const LINK = `/unsubscribe?t=${'U'.repeat(60)}`;
  const DONE = 'You\'re unsubscribed. We won\'t send you early-access emails anymore.';
  const REFUSED = 'This unsubscribe link doesn\'t work. Reply to any of our emails, or write to hello@localnodes.xyz, and we\'ll take you off the list.';
  const SERVER = 'Something went wrong on our side. Try again in a minute.';

  const answerPost = mockUnsubscribePost;

  async function openPage(page: Page) {
    await open(page, LINK);
    await expect(page.getByRole('heading', { level: 1, name: 'Unsubscribe' })).toBeVisible();
    await expect(page).toHaveTitle('Unsubscribe - LocalNodes');
  }

  test('default (opening the link sends nothing)', async ({ page }) => {
    const posts: string[] = [];
    page.on('request', (r) => { if (r.method() === 'POST' && r.url().includes('/unsubscribe')) posts.push(r.url()); });
    await openPage(page);
    await expect(page.getByText('Stop getting LocalNodes early-access emails at this address.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Unsubscribe' })).toBeVisible();
    await page.waitForTimeout(500);
    expect(posts).toEqual([]);
    await checkA11y(page, 'unsubscribe', 'default', SCAN);
  });

  test('done', async ({ page }) => {
    await answerPost(page, 200, { ok: true });
    await openPage(page);
    await page.getByRole('button', { name: 'Unsubscribe' }).click();
    const line = page.getByText(DONE);
    await expect(line).toBeVisible();
    await expect(line).toBeFocused();
    await expect(page.getByRole('button', { name: /Unsubscrib/ })).toHaveCount(0);
    await checkA11y(page, 'unsubscribe', 'done', SCAN);
  });

  test('link doesn\'t work', async ({ page }) => {
    await answerPost(page, 400, { ok: false, message: 'Bad Request' });
    await openPage(page);
    await page.getByRole('button', { name: 'Unsubscribe' }).click();
    // F8 (23-39, orchestrator Q1): focused, read once by the focus move, no alert role.
    const alert = focusedBox(page, REFUSED);
    await expect(alert).toBeVisible();
    await expect(alert).toBeFocused();
    expect(await alert.getAttribute('role'), 'the focused box has no role (F8)').toBeNull();
    await expect(page.getByRole('alert')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Unsubscribe' })).toHaveCount(0);
    await mouseOff(page);
    await checkA11y(page, 'unsubscribe', 'broken-link', SCAN);
  });

  test('server problem', async ({ page }) => {
    await answerPost(page, 503, { ok: false, message: 'Service Unavailable' });
    await openPage(page);
    await page.getByRole('button', { name: 'Unsubscribe' }).click();
    // F8 (23-39, orchestrator Q1): focused, read once by the focus move, no alert role.
    const alert = focusedBox(page, SERVER);
    await expect(alert).toBeVisible();
    await expect(alert).toBeFocused();
    expect(await alert.getAttribute('role'), 'the focused box has no role (F8)').toBeNull();
    await expect(page.getByRole('alert')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Unsubscribe' })).toBeEnabled();
    await mouseOff(page);
    await checkA11y(page, 'unsubscribe', 'server-problem', SCAN);
  });
});

// --- Before scripts run (23-29 finding 2; 23-20 hand-off) ------------------------
//
// What a screen reader gets from the server's HTML before Nuxt hydrates it (or
// with scripts off): every field on /invite has its label.

test.describe('server HTML, before scripts', () => {
  test.use({ javaScriptEnabled: false });

  test('invite: the Email field has its label', async ({ page }) => {
    await page.goto('/invite');
    await expect(page.getByRole('heading', { level: 1, name: 'Use Your Invite' })).toBeVisible();
    await expect(page.getByRole('textbox', { name: 'Email' })).toHaveCount(1);
    const unlabelled = await page.locator('label[for]').evaluateAll((labels) =>
      labels.map((l) => l.getAttribute('for')!).filter((id) => !document.getElementById(id)));
    expect(unlabelled, 'every label points at a field that exists').toEqual([]);
  });

  // 23-39 (F2b): the sign-up form's fields keep UFormField's ids, so every
  // label matches its field in the server HTML. The form only renders when the
  // room read during server rendering succeeds, so this test brings its own
  // loopback Upstash stub (it reports room) and a second built sign-up server
  // pointed at it (helpers/onboarding-ssr.ts). The main server on :3000 stays
  // without Redis.
  test.describe('sign-up form (second server, loopback Upstash stub)', () => {
    let stub: UpstashStub | undefined;
    let server: SignupServer | undefined;

    test.beforeAll(async () => {
      test.setTimeout(60_000);
      stub = await startUpstashStub();
      server = await startSignupServer({ redisUrl: stub.url, redisToken: stub.token });
    });

    test.afterAll(async () => {
      await server?.stop();
      await stub?.close();
      if (stub) {
        mkdirSync(PHASE23_RESULTS_DIR, { recursive: true });
        writeFileSync(join(PHASE23_RESULTS_DIR, 'ssr-upstash-stub-commands.json'), JSON.stringify({ recorded: new Date().toISOString(), commands: stub.commands }, null, 2) + '\n');
      }
    });

    test('sign-up form: every field has its label', async ({ page }) => {
      await page.goto(`${server!.url}/onboarding`);
      await expect(page.getByRole('heading', { level: 1, name: 'Name Your Community' })).toBeVisible();
      for (const name of ['Community name', 'Email', 'Describe your community (optional)', 'New page type']) {
        await expect(page.getByRole('textbox', { name }), `"${name}" is found by its label`).toHaveCount(1);
      }
      const unlabelled = await page.locator('label[for]').evaluateAll((labels) =>
        labels.map((l) => l.getAttribute('for')!).filter((id) => !document.getElementById(id)));
      expect(unlabelled, 'every label points at a field that exists').toEqual([]);
      const ids = await Promise.all(['Community name', 'Email', 'Describe your community (optional)', 'New page type'].map((name) =>
        page.getByRole('textbox', { name }).getAttribute('id')));
      for (const id of ids) {
        expect(id ?? '', 'no fixed field id').not.toMatch(/^(community-name|signup-email|community-description|page-type-\d+|page-type-new|ai-key|promo-code)$/);
      }
      // The room check only reads: no write reached the stub.
      const ops = stub!.commands.map((c) => (c[0] ?? '').toUpperCase());
      expect(ops.length, 'the room check read the stub').toBeGreaterThan(0);
      expect(ops.filter((op) => /^(SET|SETEX|SETNX|PSETEX|MSET|DEL|UNLINK|EXPIRE|PEXPIRE|EXPIREAT|PERSIST|INCR|INCRBY|DECR|DECRBY|HSET|HMSET|HDEL|HINCRBY|ZADD|ZREM|ZREMRANGEBYSCORE|ZINCRBY|LPUSH|RPUSH|SADD|SREM|GETDEL|GETSET|EVAL|EVALSHA|FLUSHDB|FLUSHALL)$/.test(op)), 'no write command').toEqual([]);
    });
  });
});

// --- Pointer hover (23-29 finding 1; 23-19 deferred item) -----------------------
//
// Every other state above moves the mouse away before its scan. This block does
// the opposite: it rests the pointer on each button and link of the page in
// turn and asks axe's color-contrast rule about that one element, so a hover
// colour under 4.5:1 (Nuxt UI's hover:bg-primary/75 and hover:text-primary/75)
// fails here. Results: a11y/axe-hover-<state>.json.

test.describe('pointer hover keeps text contrast', () => {
  const states: Record<string, (page: Page) => Promise<void>> = {
    'invite': async (page) => {
      await open(page, '/invite');
    },
    'verify': async (page) => {
      await open(page, `/invite/verify#t=${'A'.repeat(43)}`);
    },
    'signup': async (page) => {
      await mockSuggest(page, ['Meeting notes', 'How-to']);
      await openForm(page);
      await page.getByLabel('Describe your community').fill('Neighbours who fix bikes, lamps and radios together.');
      await page.getByRole('button', { name: 'Suggest page types' }).click();
      await expect(page.getByRole('textbox', { name: 'Page type 2', exact: true })).toHaveValue('How-to');
    },
    'signup-key-checked': async (page) => {
      await mockAiKey(page, 200, { ok: true });
      await openForm(page);
      await page.getByRole('radio', { name: 'Your own Gemini account' }).check();
      await page.getByRole('textbox', { name: 'Gemini key' }).fill('test-key-not-real-0000000000000000');
      await page.getByLabel('This key is from a Google Cloud project with billing turned on').check();
      await page.getByRole('button', { name: 'Check key' }).click();
      await expect(page.getByRole('button', { name: 'Replace key' })).toBeVisible();
    },
    'success-failed': async (page) => {
      await mockStatus(page, { status: 'failed', siteUrl: null, reason: 'deploy_failed', canRetry: true, stopped: false });
      await open(page, `/success?session_id=${SESSION}`);
      await expect(page.getByRole('button', { name: 'Retry site setup' })).toBeVisible({ timeout: 15_000 });
    },
    'success-welcome': async (page) => {
      await mockStatus(page, { status: 'complete', siteUrl: SITE, reason: null, canRetry: false, stopped: false });
      await open(page, `/success?session_id=${SESSION}`);
      await expect(page.getByRole('link', { name: 'Open your site (opens another site)' })).toBeVisible({ timeout: 15_000 });
    },
    'unsubscribe': async (page) => {
      await open(page, `/unsubscribe?t=${'U'.repeat(60)}`);
    },
  };

  for (const [state, enter] of Object.entries(states)) {
    test(`${state}: every button and link under the pointer`, async ({ page }) => {
      test.setTimeout(120_000);
      await enter(page);
      const targets = page.locator('a:visible, button:visible');
      const count = await targets.count();
      const checked: { name: string, violations: unknown[] }[] = [];
      for (let i = 0; i < count; i++) {
        const target = targets.nth(i);
        if (await target.evaluate((el) => !!el.closest('#nuxt-devtools-container, nuxt-devtools-frame'))) continue;
        // The skip link (23-39) is hidden until it has focus, so no pointer can
        // rest on it. Its focused look has its own axe scan (keyboard-phase23.spec.ts,
        // axe-skip-link-focused.json).
        if (await target.evaluate((el) => el.getAttribute('href') === '#main-content')) continue;
        const name = ((await target.getAttribute('aria-label')) ?? (await target.innerText())).trim().slice(0, 80);
        await target.scrollIntoViewIfNeeded();
        await target.hover();
        // Let the hover colour's transition finish.
        await page.waitForTimeout(250);
        await target.evaluate((el) => el.setAttribute('data-ln-hovered', ''));
        const results = await new AxeBuilder({ page }).withTags(AXE_TAGS).withRules(['color-contrast']).include('[data-ln-hovered]').analyze();
        await target.evaluate((el) => el.removeAttribute('data-ln-hovered'));
        checked.push({
          name,
          violations: results.violations.flatMap((v) => v.nodes.map((n) => ({ id: v.id, impact: v.impact, summary: n.failureSummary, target: n.target.join(' ') }))),
        });
      }
      mkdirSync(PHASE23_RESULTS_DIR, { recursive: true });
      writeFileSync(join(PHASE23_RESULTS_DIR, `axe-hover-${state}.json`), JSON.stringify({ state, recorded: new Date().toISOString(), checked }, null, 2) + '\n');
      expect(checked.length, 'the page has buttons or links to hover').toBeGreaterThan(0);
      expect(checked.filter((c) => c.violations.length), `hover contrast on ${state}`).toEqual([]);
    });
  }
});
