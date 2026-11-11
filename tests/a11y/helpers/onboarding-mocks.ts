// The Nuxt sign-up site's API answers and page helpers, shared by the Phase 23
// specs that run in the "onboarding" project (23-20 onboarding.spec.ts; 23-29
// keyboard-phase23.spec.ts and sr-proxy-phase23.spec.ts).
//
// No live calls: the sign-up site runs with no Stripe, Resend, Upstash, Gemini,
// OpenRouter, Coolify or GitHub keys, and every sign-up API the browser calls
// is answered here with page.route. Two reads happen while the server renders
// /onboarding (room and price, 23-19): with no Redis the room read fails and the
// page shows "Waiting for Room". So the form states load /invite first, answer
// the room and price reads in the browser, and move to /onboarding with the
// client router, which runs those reads in the browser (openForm).
import { expect, type Page, type Route } from '@playwright/test';
import { PHASE23_RESULTS_DIR } from './axe';

// The same default as the onboarding project's baseURL in playwright.config.ts.
export const ONBOARDING_URL = process.env.LN_ONBOARDING_URL ?? 'http://localhost:3000';

// The dev server adds Nuxt DevTools' own panel to the page (devtools.enabled in
// nuxt.config.ts). It isn't part of the site and never ships, so it is left
// out of the scan.
export const DEVTOOLS = ['#nuxt-devtools-container', 'nuxt-devtools-frame'];
export const SCAN = { resultsDir: PHASE23_RESULTS_DIR, exclude: DEVTOOLS };

export const SESSION = 'cs_test_a11y_2320';
export const SITE = 'https://repair.localnodes.xyz';
/** The end of a 3DS hold in the mocked held replies (23-37). */
export const HELD_UNTIL = '2026-11-09T16:15:00.000Z';
export const INVITED = { email: 'organizer@example.org', promoCode: 'PILOT-A11Y' };
// The server's one refusal for a code (server/utils/promo.ts PROMO_REFUSED_MESSAGE).
export const CODE_REFUSED = 'This code has already been used. Ask whoever gave it to you for a new one.';
// A mocked key only: it never leaves the browser, because /api/ai-key is answered here.
export const FAKE_KEY = 'test-key-not-real-0000000000000000';

export const json = (route: Route, status: number, body: unknown) =>
  route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });

/** Moves the mouse off every button, so a hover left over from a click isn't scanned. */
export async function mouseOff(page: Page) {
  await page.mouse.move(0, 0);
}

export type NuxtWindowApp = { config: { globalProperties: { $nuxt: { isHydrating?: boolean, payload: { state: Record<string, unknown> }, $router: { push: (to: string) => Promise<unknown> } } } } };

/**
 * Opens a page and waits until Nuxt has hydrated it. Before that, the server's
 * HTML is on screen without its scripts, and a scan or a key press would test
 * the wrong thing (see the hand-off on /invite's label in 23-20-SUMMARY.md).
 */
export async function open(page: Page, path: string) {
  await page.goto(path);
  await page.waitForFunction(() => {
    const app = (document.querySelector('#__nuxt') as unknown as { __vue_app__?: NuxtWindowApp } | null)?.__vue_app__;
    return app?.config.globalProperties.$nuxt.isHydrating === false;
  });
}

/** Fails the run when the sign-up site was picked by name and isn't answering; skips otherwise. */
export async function requireOnboardingSite(skip: (condition: boolean, reason: string) => void) {
  const up = await fetch(`${ONBOARDING_URL}/invite`).then((r) => r.ok, () => false);
  const hint = `The Nuxt sign-up site isn't answering at ${ONBOARDING_URL}. Start it with NUXT_LAUNCH_OPEN=true npm --prefix ../localnodes-onboarding run dev (or set LN_ONBOARDING_URL).`;
  if (process.env.LN_ONBOARDING_PICKED === '1') expect(up, hint).toBe(true);
  else skip(!up, hint);
}

/** Answers POST /api/invite/request with this status and body. */
export async function mockInviteRequest(page: Page, code: number, body: unknown) {
  await page.route('**/api/invite/request', (route) => json(route, code, body));
}

/** Answers POST /api/invite/verify with this status and body. */
export async function mockInviteVerify(page: Page, code: number, body: unknown) {
  await page.route('**/api/invite/verify', (route) => json(route, code, body));
}

/** Room, price and the address check answered in the browser. */
export async function mockFormReads(page: Page) {
  await page.route('**/api/capacity**', (route) => json(route, 200, { room: true }));
  await page.route('**/api/price**', (route) => json(route, 200, { line: '$20 a month' }));
  await page.route('**/api/check-subdomain**', (route) => json(route, 200, { available: true, reason: null }));
}

/** Answers POST /api/suggest-topic-types with these page types. */
export async function mockSuggest(page: Page, types: string[]) {
  await page.route('**/api/suggest-topic-types', (route) => json(route, 200, { types }));
}

/** Answers POST /api/check-code with this status and body. */
export async function mockCheckCode(page: Page, code: number, body: unknown) {
  await page.route('**/api/check-code', (route) => json(route, code, body));
}

/** Answers POST /api/ai-key with this status and body. */
export async function mockAiKey(page: Page, code: number, body: unknown) {
  await page.route('**/api/ai-key', (route) => json(route, code, body));
}

/**
 * Room and price answered in the browser, the invited details set, then a
 * client-side move to /onboarding.
 */
export async function openForm(page: Page) {
  await mockFormReads(page);
  await open(page, '/invite');
  await expect(page.getByRole('heading', { level: 1, name: 'Use Your Invite' })).toBeVisible();
  // Nuxt keeps useState values in payload.state under "$s" + key; the form
  // reads ln-invite-details from there (23-19).
  await page.evaluate(async (details) => {
    const nuxt = (document.querySelector('#__nuxt') as unknown as { __vue_app__: NuxtWindowApp }).__vue_app__.config.globalProperties.$nuxt;
    nuxt.payload.state['$sln-invite-details'] = details;
    await nuxt.$router.push('/onboarding');
  }, INVITED);
  await expect(page.getByRole('heading', { level: 1, name: 'Name Your Community' })).toBeVisible();
  await expect(page.getByText('$20 a month')).toBeVisible();
}

/** Answers every GET /api/provision-status with this reply. */
export async function mockStatus(page: Page, reply: unknown, code = 200) {
  await page.route('**/api/provision-status**', (route) => json(route, code, reply));
}

/**
 * Answers GET /api/provision-status with each reply in turn, then keeps
 * answering with the last one.
 */
export async function mockStatusSequence(page: Page, replies: unknown[]) {
  let next = 0;
  await page.route('**/api/provision-status**', (route) => {
    const reply = replies[Math.min(next, replies.length - 1)];
    next += 1;
    return json(route, 200, reply);
  });
}

/** A status reply with the fields the page reads. */
// 23-37: a held reply adds heldUntil (ISO) and canConfirm; a declined reply adds heldExpired.
// 23-43 Task 7: a 3DS hold that ran out adds removed, noCapture and refundProven; refunding and
// refunded add lateActivation.
export const statusReply = (status: string, extra: Partial<{ siteUrl: string | null, reason: string | null, canRetry: boolean, stopped: boolean, attempt: number, heldUntil: string, canConfirm: boolean, heldExpired: boolean, removed: boolean, noCapture: boolean, refundProven: boolean, lateActivation: boolean }> = {}) => ({
  status,
  siteUrl: null,
  reason: null,
  canRetry: false,
  stopped: false,
  // The seventh key (23-38): 1 for the first build, 2 after the free retry.
  attempt: 1,
  ...extra,
});

/** Answers the unsubscribe button's POST with this status; the page's own GET goes through. */
export async function mockUnsubscribePost(page: Page, code: number, body: unknown) {
  await page.route('**/unsubscribe?**', (route) =>
    route.request().method() === 'POST' ? json(route, code, body) : route.continue());
}
