// The Phase 23 screen states (23-29), shared by keyboard-phase23.spec.ts and
// sr-proxy-phase23.spec.ts. Each state names its project (persona), its
// 23-UI-SPEC accessibility checklist row, how to reach it with key presses
// once the page is open, and what must hold right after (focus after a
// submit, for one).
//
// What is set up without keys: opening a page by its address (as a link in a
// mail does), the mocked API answers (helpers/onboarding-mocks.ts), and the
// sign-up form's invited details (openForm, because the link page's real
// "Continue" does a full page load that needs Redis on the server).
import { execFileSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, type Locator, type Page } from '@playwright/test';
import { focused, tabUntil } from './keyboard';
import {
  CODE_REFUSED,
  FAKE_KEY,
  HELD_UNTIL,
  INVITED,
  SESSION,
  SITE,
  mockAiKey,
  mockCheckCode,
  mockInviteRequest,
  mockInviteVerify,
  mockStatus,
  mockSuggest,
  open,
  openForm,
  statusReply,
} from './onboarding-mocks';

const REPO_ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');
const SUGGESTED = ['Meeting notes', 'How-to', 'Repair logs', 'Parts list', 'Events'];

export type Persona = 'onboarding' | 'anonymous' | 'steward';

export interface Phase23State {
  key: string;
  persona: Persona;
  /** 23-UI-SPEC accessibility checklist row. */
  row: number;
  /** Gets the page into the state. Key presses only, once the page is open. */
  enter: (page: Page) => Promise<void>;
  /** Checks right after enter(), before the walk (focus after a submit, for one). */
  after?: (page: Page) => Promise<void>;
}

/** Fills the focused field by typing, as a person does. */
export async function type(page: Page, text: string) {
  await page.keyboard.type(text);
}

export const isField = (id: string) => (f: Awaited<ReturnType<typeof focused>>) => f.id === id;

// The sign-up form's fields carry no fixed id since 23-39 (F2b): each keeps the
// id its UFormField gives it. They are found by their label, and a Tab walk
// stops when focus is on the id that field has now.
export const communityName = (page: Page) => page.getByRole('textbox', { name: 'Community name' });
export const describeField = (page: Page) => page.getByRole('textbox', { name: 'Describe your community (optional)' });
export const pageTypeRow = (page: Page, n: number) => page.getByRole('textbox', { name: `Page type ${n}`, exact: true });
export const keyField = (page: Page) => page.getByRole('textbox', { name: 'Gemini key', exact: true });
export const codeField = (page: Page) => page.getByRole('textbox', { name: 'Code', exact: true });

/** Tab (or Shift+Tab) until `field` has focus, matched by its live id. */
export async function tabToField(page: Page, field: Locator, opts: { cap?: number, back?: boolean, what?: string } = {}) {
  const id = await field.getAttribute('id');
  expect(id, `${opts.what ?? 'the field'} has an id`).toBeTruthy();
  await tabUntil(page, (f) => f.id === id, opts);
}
export const isButton = (name: string) => (f: Awaited<ReturnType<typeof focused>>) => f.tag === 'BUTTON' && f.name === name;

// The imported web page from the Phase 22 Source box seed (public, in "A11y
// Garden", whose manager is the steward persona). Set by seedImportedPage().
let webNid = '';
export const importedPage = () => webNid;

export const NUXT_STATES: Phase23State[] = [
  {
    key: 'invite-empty',
    persona: 'onboarding',
    row: 1,
    enter: async (page) => {
      await open(page, '/invite');
      await expect(page.getByRole('heading', { level: 1, name: 'Use Your Invite' })).toBeVisible();
    },
  },
  {
    key: 'invite-error',
    persona: 'onboarding',
    row: 1,
    enter: async (page) => {
      await open(page, '/invite');
      await tabUntil(page, isButton('Send my sign-in link'), { what: 'Send my sign-in link', cap: 40 });
      await page.keyboard.press('Enter');
      await expect(page.getByText('Fix these to continue')).toBeVisible();
    },
    after: async (page) => {
      // Focus is on the error summary, and its link takes focus to the field.
      await expect(page.locator('div[tabindex="-1"]').filter({ hasText: 'Fix these to continue' })).toBeFocused();
      await page.keyboard.press('Tab');
      expect((await focused(page)).name).toBe('This isn\'t an email address. Check it has an @ and a domain, like name@example.org.');
      await page.keyboard.press('Enter');
      await expect(page.getByRole('textbox', { name: 'Email' })).toBeFocused();
    },
  },
  {
    key: 'invite-sent',
    persona: 'onboarding',
    row: 1,
    enter: async (page) => {
      await mockInviteRequest(page, 200, { ok: true });
      await open(page, '/invite');
      await tabUntil(page, (f) => f.tag === 'INPUT' && f.cls.includes('rounded-md'), { what: 'the Email field', cap: 40 });
      await type(page, INVITED.email);
      await page.keyboard.press('Enter');
      await expect(page.getByRole('heading', { level: 2, name: 'Check your email' })).toBeVisible();
    },
    after: async (page) => {
      await expect(page.getByRole('heading', { level: 2, name: 'Check your email' })).toBeFocused();
    },
  },
  {
    key: 'verify-ready',
    persona: 'onboarding',
    row: 2,
    enter: async (page) => {
      await open(page, `/invite/verify#t=${'A'.repeat(43)}`);
      await expect(page.getByRole('button', { name: 'Continue to sign-up' })).toBeVisible();
    },
  },
  {
    key: 'verify-expired',
    persona: 'onboarding',
    row: 2,
    enter: async (page) => {
      await mockInviteVerify(page, 400, { message: 'Bad Request' });
      await open(page, `/invite/verify#t=${'B'.repeat(43)}`);
      await tabUntil(page, isButton('Continue to sign-up'), { what: 'Continue to sign-up', cap: 40 });
      await page.keyboard.press('Enter');
      await expect(page.getByText('This link has expired or was already used. Ask for a new one.')).toBeVisible();
    },
    after: async (page) => {
      // F8 (23-39): the box takes focus and has no alert role; the focus move reads it once.
      const box = page.locator('div[tabindex="-1"]').filter({ hasText: 'This link has expired or was already used.' });
      await expect(box).toBeFocused();
      expect(await box.getAttribute('role'), 'the focused box has no role').toBeNull();
      await page.keyboard.press('Tab');
      expect((await focused(page)).name).toBe('Get a new sign-in link');
    },
  },
  {
    key: 'onboarding-full',
    persona: 'onboarding',
    row: 3,
    enter: async (page) => {
      // No Redis on the test server, so the room read during server rendering fails: full.
      await open(page, '/onboarding');
      await expect(page.getByRole('heading', { level: 1, name: 'Waiting for Room' })).toBeVisible();
    },
  },
  {
    key: 'signup-page-types',
    persona: 'onboarding',
    row: 3,
    enter: async (page) => {
      await mockSuggest(page, SUGGESTED);
      await openForm(page);
      await tabToField(page, communityName(page), { what: 'Community name', cap: 40 });
      await type(page, 'Repair Collective');
      await expect(page.getByRole('status').filter({ hasText: 'Available' })).toHaveText('Available');
      await tabToField(page, describeField(page), { what: 'Describe your community', cap: 20 });
      await type(page, 'Neighbours who fix bikes, lamps and radios together every month.');
      await page.keyboard.press('Tab');
      expect((await focused(page)).name).toBe('Suggest page types');
      await page.keyboard.press('Enter');
      await expect(pageTypeRow(page, 5)).toHaveValue('Events');
    },
    after: async (page) => {
      // Focus moves to the first suggested row.
      await expect(pageTypeRow(page, 1)).toBeFocused();
    },
  },
  {
    key: 'signup-key-checked',
    persona: 'onboarding',
    row: 3,
    enter: async (page) => {
      await mockAiKey(page, 200, { ok: true });
      await openForm(page);
      // The radio group is one Tab stop; arrow keys move inside it.
      await tabUntil(page, (f) => f.name.startsWith('Use the included AI service'), { what: 'the AI choice group', cap: 60 });
      await hold(page, 'ArrowDown');
      await expect(page.getByRole('radio', { name: 'Your own Gemini account' })).toBeChecked();
      await tabToField(page, keyField(page), { what: 'the key field', cap: 10 });
      await type(page, FAKE_KEY);
      await tabUntil(page, isField('ai-billing'), { what: 'the billing box', cap: 10 });
      await page.keyboard.press('Space');
      await expect(page.locator('#ai-billing')).toBeChecked();
      await tabUntil(page, isButton('Check key'), { what: 'Check key', cap: 10, back: true });
      await page.keyboard.press('Enter');
      await expect(page.getByText('Key checked', { exact: true }).first()).toBeVisible();
    },
    after: async (page) => {
      // "Check key" is gone once the key is checked; focus moves to "Replace key"
      // instead of falling to the page (Codex review, 23-29 finding C4).
      await expect(page.getByRole('button', { name: 'Replace key' })).toBeFocused();
    },
  },
  {
    key: 'signup-code-error',
    persona: 'onboarding',
    row: 3,
    enter: async (page) => {
      await mockCheckCode(page, 409, { message: CODE_REFUSED });
      await openForm(page);
      await tabUntil(page, isButton('Apply code'), { what: 'Apply code', cap: 80 });
      await page.keyboard.press('Enter');
      await expect(page.getByText(CODE_REFUSED)).toBeVisible();
    },
    after: async (page) => {
      // The refusal is heard: focus moves to the code field, whose description
      // carries the message (Codex review, 23-29 findings C3 and C4).
      await expect(codeField(page)).toBeFocused();
      await expect(codeField(page)).toHaveAccessibleDescription(new RegExp(CODE_REFUSED.slice(0, 30)));
    },
  },
  {
    key: 'signup-error',
    persona: 'onboarding',
    row: 3,
    enter: async (page) => {
      await openForm(page);
      await tabUntil(page, isButton('Continue to card details'), { what: 'Continue to card details', cap: 80 });
      await page.keyboard.press('Enter');
      await expect(page.getByText('Fix these to continue')).toBeVisible();
    },
    after: async (page) => {
      // Tag-scoped: main also carries tabindex -1 since 23-39 and contains the summary.
      await expect(page.locator('div[tabindex="-1"]').filter({ hasText: 'Fix these to continue' }).first()).toBeFocused();
      // The summary's first link takes focus to the community name. Its href
      // is '#' plus that field's live id (23-39, F2b: keyed by field name).
      await page.keyboard.press('Tab');
      const link = await focused(page);
      await page.keyboard.press('Enter');
      const field = communityName(page);
      await expect(field).toBeFocused();
      expect(link.href, 'the summary link points at the field by its live id').toBe(`#${await field.getAttribute('id')}`);
    },
  },
  {
    key: 'success-progress',
    persona: 'onboarding',
    row: 4,
    enter: async (page) => {
      await mockStatus(page, statusReply('provisioning'));
      await open(page, `/success?session_id=${SESSION}`);
      await expect(page.locator('[aria-live="polite"]')).toHaveText('Setting up your server.', { timeout: 15_000 });
    },
  },
  {
    key: 'success-no-session',
    persona: 'onboarding',
    row: 4,
    enter: async (page) => {
      await open(page, '/success');
      await expect(page.getByRole('heading', { level: 1, name: 'Nothing to Show Here' })).toBeVisible();
    },
  },
  {
    key: 'success-failed',
    persona: 'onboarding',
    row: 5,
    enter: async (page) => {
      await mockStatus(page, statusReply('failed', { reason: 'deploy_failed', canRetry: true }));
      await open(page, `/success?session_id=${SESSION}`);
      await expect(page.getByRole('button', { name: 'Retry site setup' })).toBeVisible({ timeout: 15_000 });
    },
    after: async (page) => {
      // F8 (23-39): the box takes focus and has no alert role; the focus move reads it once.
      const box = page.locator('div[tabindex="-1"]').filter({ hasText: 'Setting up your site didn\'t finish.' });
      await expect(box).toBeFocused();
      expect(await box.getAttribute('role'), 'the focused box has no role').toBeNull();
      await page.keyboard.press('Tab');
      expect((await focused(page)).name).toBe('Retry site setup');
    },
  },
  {
    key: 'success-stopped',
    persona: 'onboarding',
    row: 6,
    enter: async (page) => {
      await mockStatus(page, statusReply('failed', { reason: 'deploy_failed', stopped: true }));
      await open(page, `/success?session_id=${SESSION}`);
      await expect(page.getByRole('heading', { level: 1, name: 'We Couldn\'t Set Up Your Site' })).toBeVisible({ timeout: 15_000 });
    },
    after: async (page) => {
      await expect(page.getByRole('heading', { level: 1, name: 'We Couldn\'t Set Up Your Site' })).toBeFocused();
    },
  },
  // 23-37 (B-1; 23-UI-SPEC rows 6a and 6b): the held screen for the browser
  // that made the sign-up (skip link, header, Confirm my payment, footer) and
  // for another session (no button), and the declined screen (skip link,
  // header, Try again, footer). Focus lands on the h1.
  {
    key: 'success-held-owner',
    persona: 'onboarding',
    row: 6,
    enter: async (page) => {
      await mockStatus(page, statusReply('held', { heldUntil: HELD_UNTIL, canConfirm: true }));
      await open(page, `/success?session_id=${SESSION}`);
      await expect(page.getByRole('heading', { level: 1, name: 'Confirm Your Payment' })).toBeVisible({ timeout: 15_000 });
    },
    after: async (page) => {
      await expect(page.getByRole('heading', { level: 1, name: 'Confirm Your Payment' })).toBeFocused();
      await page.keyboard.press('Tab');
      expect((await focused(page)).name).toBe('Confirm my payment');
    },
  },
  {
    key: 'success-held-other',
    persona: 'onboarding',
    row: 6,
    enter: async (page) => {
      await mockStatus(page, statusReply('held', { heldUntil: HELD_UNTIL, canConfirm: false }));
      await open(page, `/success?session_id=${SESSION}`);
      await expect(page.getByRole('heading', { level: 1, name: 'Confirm Your Payment' })).toBeVisible({ timeout: 15_000 });
    },
    after: async (page) => {
      await expect(page.getByRole('heading', { level: 1, name: 'Confirm Your Payment' })).toBeFocused();
      await expect(page.getByRole('button', { name: 'Confirm my payment' })).toHaveCount(0);
    },
  },
  {
    key: 'success-declined',
    persona: 'onboarding',
    row: 6,
    enter: async (page) => {
      await mockStatus(page, statusReply('declined', { heldExpired: false }));
      await open(page, `/success?session_id=${SESSION}`);
      await expect(page.getByRole('heading', { level: 1, name: 'Your Card Was Declined' })).toBeVisible({ timeout: 15_000 });
    },
    after: async (page) => {
      await expect(page.getByRole('heading', { level: 1, name: 'Your Card Was Declined' })).toBeFocused();
      await page.keyboard.press('Tab');
      expect((await focused(page)).name).toBe('Try again');
    },
  },
  {
    // 23-37 follow-up: the 3DS hold ran out (skip link, header, Try again, footer).
    key: 'success-not-confirmed',
    persona: 'onboarding',
    row: 6,
    enter: async (page) => {
      await mockStatus(page, statusReply('declined', { heldExpired: true, removed: true, noCapture: true, refundProven: false }));
      await open(page, `/success?session_id=${SESSION}`);
      await expect(page.getByRole('heading', { level: 1, name: 'We\'ve Removed Your Site' })).toBeVisible({ timeout: 15_000 });
    },
    after: async (page) => {
      await expect(page.getByRole('heading', { level: 1, name: 'We\'ve Removed Your Site' })).toBeFocused();
      await page.keyboard.press('Tab');
      expect((await focused(page)).name).toBe('Try again');
    },
  },
  {
    // 23-43 Task 7, P3: the delete not yet confirmed (skip link, header, Try again, footer).
    key: 'success-removing',
    persona: 'onboarding',
    row: 6,
    enter: async (page) => {
      await mockStatus(page, statusReply('declined', { heldExpired: true, removed: false, noCapture: true, refundProven: false }));
      await open(page, `/success?session_id=${SESSION}`);
      await expect(page.getByRole('heading', { level: 1, name: 'Removing Your Site' })).toBeVisible({ timeout: 15_000 });
    },
    after: async (page) => {
      await expect(page.getByRole('heading', { level: 1, name: 'Removing Your Site' })).toBeFocused();
      await page.keyboard.press('Tab');
      expect((await focused(page)).name).toBe('Try again');
    },
  },
  {
    // 23-43 Task 7, P6: the refund in progress; no button (skip link, header, footer).
    key: 'success-refunding',
    persona: 'onboarding',
    row: 6,
    enter: async (page) => {
      await mockStatus(page, statusReply('refunding', { lateActivation: false }));
      await open(page, `/success?session_id=${SESSION}`);
      await expect(page.getByRole('heading', { level: 1, name: 'Refunding Your Payment' })).toBeVisible({ timeout: 15_000 });
    },
    after: async (page) => {
      await expect(page.getByRole('heading', { level: 1, name: 'Refunding Your Payment' })).toBeFocused();
      await expect(page.getByRole('link', { name: 'Try again' })).toHaveCount(0);
    },
  },
  {
    // 23-43 Task 7, P2: the refund succeeded (skip link, header, Try again, footer).
    key: 'success-refunded',
    persona: 'onboarding',
    row: 6,
    enter: async (page) => {
      await mockStatus(page, statusReply('refunded', { lateActivation: false }));
      await open(page, `/success?session_id=${SESSION}`);
      await expect(page.getByRole('heading', { level: 1, name: 'We\'ve Refunded Your Payment' })).toBeVisible({ timeout: 15_000 });
    },
    after: async (page) => {
      await expect(page.getByRole('heading', { level: 1, name: 'We\'ve Refunded Your Payment' })).toBeFocused();
      await page.keyboard.press('Tab');
      expect((await focused(page)).name).toBe('Try again');
    },
  },
  {
    key: 'success-welcome',
    persona: 'onboarding',
    row: 7,
    enter: async (page) => {
      await mockStatus(page, statusReply('complete', { siteUrl: SITE }));
      await open(page, `/success?session_id=${SESSION}`);
      await expect(page.getByRole('heading', { level: 1, name: 'Your Community\'s Site Is Ready' })).toBeVisible({ timeout: 15_000 });
    },
    after: async (page) => {
      await expect(page.getByRole('heading', { level: 1, name: 'Your Community\'s Site Is Ready' })).toBeFocused();
      await page.keyboard.press('Tab');
      expect((await focused(page)).name).toBe('Open your site (opens another site)');
    },
  },
  {
    key: 'unsubscribe-default',
    persona: 'onboarding',
    row: 1,
    enter: async (page) => {
      await open(page, `/unsubscribe?t=${'U'.repeat(60)}`);
      await expect(page.getByRole('button', { name: 'Unsubscribe' })).toBeVisible();
    },
  },
];

export const DRUPAL_STATES: Phase23State[] = [
  {
    key: 'guide',
    persona: 'steward',
    row: 8,
    enter: async (page) => {
      await page.goto('/guide');
      await expect(page.getByRole('heading', { level: 1, name: 'Organizer Guide' })).toBeVisible();
    },
  },
  {
    key: 'terms',
    persona: 'anonymous',
    row: 9,
    enter: async (page) => {
      await page.goto('/terms');
      await expect(page.getByRole('heading', { level: 1, name: 'Site Terms' })).toBeVisible();
    },
  },
  {
    key: 'register-consent-error',
    persona: 'anonymous',
    row: 9,
    enter: async (page) => {
      await page.goto('/user/register');
      await tabUntil(page, isButton('Create new account'), { what: 'Create new account', cap: 120 });
      await page.keyboard.press('Enter');
      await page.waitForLoadState('domcontentloaded');
      await expect(page.locator('#edit-terms-consent--error-message')).toBeVisible();
    },
    after: async (page) => {
      await expect(page.locator('.alert-danger').filter({ has: page.locator('a[href="#edit-terms-consent"]') })).toBeFocused();
    },
  },
  {
    key: 'report-form',
    persona: 'anonymous',
    row: 10,
    enter: async (page) => {
      await page.goto(`/node/${webNid}/report-problem`);
      await expect(page.getByRole('heading', { level: 1, name: 'Report a Problem with This Page' })).toBeVisible();
    },
  },
  {
    key: 'report-error',
    persona: 'anonymous',
    row: 10,
    enter: async (page) => {
      await page.goto(`/node/${webNid}/report-problem`);
      // An address that isn't one, and no reason chosen: nothing is sent.
      await tabUntil(page, (f) => f.id.startsWith('edit-contact-email'), { what: 'Your email', cap: 120 });
      await type(page, 'not-an-address');
      await page.keyboard.press('Tab');
      expect((await focused(page)).name).toBe('Send report');
      await page.keyboard.press('Enter');
      await page.waitForLoadState('domcontentloaded');
      await expect(page.getByText('Choose what the problem is.').first()).toBeVisible();
    },
    after: async (page) => {
      await expect(page.locator('[data-ln-error-summary]')).toBeFocused();
    },
  },
  {
    key: 'takedown',
    persona: 'steward',
    row: 11,
    enter: async (page) => {
      await page.goto(`/node/${webNid}/takedown`);
      await expect(page.getByRole('heading', { level: 1, name: /^Unpublish .+\?$/ })).toBeVisible();
    },
    after: async (page) => {
      // The safe choice has focus when the page opens.
      expect((await focused(page)).name).toBe('Keep page published');
    },
  },
];

export const STATES = [...NUXT_STATES, ...DRUPAL_STATES];

/** Seeds the imported page (Phase 22 Source box fixture) and returns its id. */
export function seedImportedPage(): string {
  const output = execFileSync('ddev', ['drush', 'php:script', 'tests/a11y/seed-source-box.php'], {
    cwd: REPO_ROOT,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    timeout: 120_000,
  });
  const nid = output.match(/^LN_SOURCE_WEB=(\d+)\s*$/m)?.[1] ?? '';
  expect(nid, `seed-source-box.php printed LN_SOURCE_WEB. Output:\n${output}`).toMatch(/^\d+$/);
  webNid = nid;
  return nid;
}

/** Presses a key and holds it 60 ms, as a person does (Reka checks a radio only while the arrow key is down). */
export async function hold(page: Page, key: string) {
  await page.keyboard.down(key);
  await page.waitForTimeout(60);
  await page.keyboard.up(key);
}

