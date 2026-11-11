// 23-29 Task 1: a keyboard-only run over every Phase 23 screen state, with
// trace and video (D-24, ROADMAP SC9; 23-UI-SPEC.md "Accessibility checklist").
//
// For each state, reached with key presses (Tab, Shift+Tab, Enter, Space,
// arrow keys, Escape; no click and no mouse):
//   - Tab from the top of the page reaches every visible interactive element,
//     in document order, and comes back round (no keyboard trap);
//   - Shift+Tab walks the same stops in reverse;
//   - every stop shows a focus indicator (its focused style differs from its
//     unfocused style) and is on screen, not under another element;
//   - where the state comes from a failed submit, focus is on the error
//     summary (or the alert) right after the key press;
// and the stops are saved as a11y/focus-<state>.json. helpers/keyboard.ts is
// the 22-30 walker, used unchanged.
//
// What is set up without keys: opening a page by its address (as a link in a
// mail does), the mocked API answers, and the sign-up form's invited details
// (openForm in helpers/onboarding-mocks.ts, because the link page's real
// "Continue" does a full page load that needs Redis on the server).
//
// Projects: the Nuxt states run in "onboarding" (the sign-up site, mocked
// APIs, no Drupal); /terms, the register form and the report form in
// "anonymous"; /guide and the takedown confirmation in "steward". Each test
// skips in the other projects.
//
// axe: the report form and the takedown confirmation (no earlier plan scans
// them) are scanned here, scoped to the form as 23-13 and 23-26 did, because
// socialblue's main navigation has a site-wide "listitem" violation (23-13).
//
// Record: LN_RECORD=1 (or --trace on) keeps a trace and a video per test in
// test-results/, which stays on desktop-dev and out of git.
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { test, expect, type Page, type Route } from '@playwright/test';
import { checkA11y, PHASE23_RESULTS_DIR } from './helpers/axe';
import { expectedTabStops, focused, tabUntil, walkTabOrder } from './helpers/keyboard';
import { DEVTOOLS, SCAN, SESSION, mockStatus, open, openForm, requireOnboardingSite, statusReply } from './helpers/onboarding-mocks';
import { STATES, codeField, describeField, hold, importedPage, isButton, keyField, seedImportedPage, tabToField } from './helpers/phase23-states';

test.use({ viewport: { width: 1280, height: 900 }, reducedMotion: 'reduce' });

function save(name: string, data: unknown) {
  mkdirSync(PHASE23_RESULTS_DIR, { recursive: true });
  writeFileSync(join(PHASE23_RESULTS_DIR, name), JSON.stringify(data, null, 2) + '\n');
}

test.beforeAll(async ({}, testInfo) => {
  if (testInfo.project.name === 'onboarding') await requireOnboardingSite(test.skip);
  else seedImportedPage();
});

/** Kbd ids of the stops inside Nuxt DevTools' panel (dev server only; never ships). */
async function devtoolsIds(page: Page): Promise<string[]> {
  return page.evaluate((selectors) => {
    const ids: string[] = [];
    for (const sel of selectors) {
      document.querySelectorAll(sel).forEach((root) => {
        if (root.getAttribute('data-kbd-id')) ids.push(root.getAttribute('data-kbd-id')!);
        root.querySelectorAll('[data-kbd-id]').forEach((el) => ids.push(el.getAttribute('data-kbd-id')!));
      });
    }
    return ids;
  }, DEVTOOLS);
}

/**
 * Reka's radio group (Nuxt UI URadioGroup) is a roving-focus group: its root
 * has tabindex="0" and hands focus straight to the checked radio, so Tab stops
 * on the radio, never on the root. Maps each radio's kbd id to its root's, so
 * the walk and the expected list name the group the same way.
 */
async function rovingGroups(page: Page): Promise<Record<string, string>> {
  return page.evaluate(() => {
    const map: Record<string, string> = {};
    document.querySelectorAll('[role="radiogroup"][data-kbd-id]').forEach((root) => {
      root.querySelectorAll('[data-kbd-id]').forEach((el) => {
        map[el.getAttribute('data-kbd-id')!] = root.getAttribute('data-kbd-id')!;
      });
    });
    return map;
  });
}

test.describe('keyboard: every Phase 23 state', () => {
  for (const state of STATES) {
    test(`${state.key}: Tab order, focus visible, no trap`, async ({ page }, testInfo) => {
      test.skip(testInfo.project.name !== state.persona, `${state.key} runs in the ${state.persona} project`);
      test.setTimeout(180_000);
      await state.enter(page);
      const afterSetup = await focused(page);
      if (state.after) await state.after(page);

      const expected = await expectedTabStops(page);
      const walk = await walkTabOrder(page);
      const devtools = new Set(await devtoolsIds(page));
      const groups = await rovingGroups(page);

      // A group's root and its checked radio both count as tabbable, so after
      // mapping, one group can appear twice in a row: keep it once.
      const ours = <T extends { preexisting: string | null, path: string }>(list: T[]) =>
        list
          .filter((s) => !s.preexisting && !devtools.has(s.path))
          .map((s) => (groups[s.path] ? { ...s, path: groups[s.path] } : s))
          .filter((s, i, all) => i === 0 || all[i - 1].path !== s.path);
      const preexisting = walk.forward.filter((s) => s.preexisting);
      const forwardIds = ours(walk.forward).map((s) => s.path);
      const expectedIds = ours(expected).map((s) => s.path);
      const missing = ours(expected).filter((e) => !forwardIds.includes(e.path));
      const extra = ours(walk.forward).filter((s) => !expectedIds.includes(s.path));
      const backwardIds = ours(walk.backward).map((s) => s.path).reverse();
      const noIndicator = ours(walk.forward).filter((s) => !s.indicator);
      const hidden = [
        ...ours(walk.forward).map((s) => ({ ...s, key: 'Tab' })),
        ...ours(walk.backward).map((s) => ({ ...s, key: 'Shift+Tab' })),
      ].filter((s) => !s.visibleInViewport);

      save(`focus-${state.key}.json`, {
        state: state.key,
        row: state.row,
        url: page.url(),
        persona: state.persona,
        recorded: new Date().toISOString(),
        focusAfterSetup: afterSetup,
        wrapped: walk.wrapped,
        wrappedBack: walk.wrappedBack,
        stops: forwardIds.length,
        expectedStops: expectedIds.length,
        preexistingStops: preexisting.length,
        preexistingComponents: [...new Set(preexisting.map((s) => s.preexisting))],
        missing,
        extra,
        orderMatchesDom: JSON.stringify(forwardIds) === JSON.stringify(expectedIds),
        expectedOrder: expectedIds,
        tabOrder: forwardIds,
        shiftTabMatches: JSON.stringify(backwardIds) === JSON.stringify(forwardIds),
        noIndicator,
        hiddenWhenFocused: hidden,
        forward: walk.forward,
        backward: walk.backward,
      });

      // 23-39 (C2): on the sign-up site the first Tab stop is the skip link.
      if (state.persona === 'onboarding') {
        expect.soft(ours(walk.forward)[0]?.name, 'the first Tab stop is the skip link').toBe('Skip to main content');
      }
      expect.soft(walk.wrapped, 'Tab comes back round to the start (no trap)').toBe(true);
      expect.soft(walk.wrappedBack, 'Shift+Tab comes back round to the start (no trap)').toBe(true);
      expect.soft(missing, 'every visible interactive element is reached by Tab').toEqual([]);
      expect.soft(extra, 'Tab stops only on visible interactive elements').toEqual([]);
      expect.soft(forwardIds, 'Tab follows document order').toEqual(expectedIds);
      expect.soft(backwardIds, 'Shift+Tab walks the same stops in reverse').toEqual(forwardIds);
      expect.soft(noIndicator.map((s) => `${s.tag}#${s.id} "${s.name}"`), 'every stop has a visible focus indicator').toEqual([]);
      expect.soft(hidden.map((s) => `${s.key}: ${s.tag}#${s.id} "${s.name}" (under ${s.coveredBy})`), 'no stop is hidden off screen or under another element').toEqual([]);
    });
  }
});

test.describe('keyboard: the sign-up site', () => {
  test('header: one logo link, named "LocalNodes home", and no link inside a link', async ({ page }, testInfo) => {
    // Codex review, 23-29 finding C1: UHeader wrapped our logo link in its own
    // link named "Nuxt UI", so Tab stopped twice on one logo. Since 23-39 the
    // skip link comes first, then the logo once.
    test.skip(testInfo.project.name !== 'onboarding', 'runs in the onboarding project');
    await open(page, '/invite');
    await expect(page.locator('header a a')).toHaveCount(0);
    await page.keyboard.press('Tab');
    expect((await focused(page)).name).toBe('Skip to main content');
    await page.keyboard.press('Tab');
    expect((await focused(page)).name).toBe('LocalNodes home');
    await page.keyboard.press('Tab');
    expect((await focused(page)).name).not.toBe('LocalNodes home');
  });

  test('AI key: "Check key" with no key moves focus to the key field, which names the problem', async ({ page }, testInfo) => {
    // Codex review, 23-29 finding C3: a refused key was shown but not heard.
    test.skip(testInfo.project.name !== 'onboarding', 'runs in the onboarding project');
    await openForm(page);
    await tabUntil(page, (f) => f.name.startsWith('The LocalNodes default'), { what: 'the AI choice group', cap: 60 });
    await hold(page, 'ArrowDown');
    await tabUntil(page, isButton('Check key'), { what: 'Check key', cap: 10 });
    await page.keyboard.press('Enter');
    const field = keyField(page);
    await expect(field).toBeFocused();
    await expect(field).toHaveAccessibleDescription(/\S/);
    await expect(field).toHaveAttribute('aria-invalid', 'true');
  });
});

// --- The skip link (23-39; C2, orchestrator rule C, 2026-11-08; 23-UI-SPEC "Skip link") ---
//
// On every sign-up page that uses the default layout, the first Tab stop is the
// skip link. It shows while focused and isn't under the sticky header. Enter
// moves focus to main and leaves the address as it was (path, query and
// fragment: /invite/verify keeps its sign-in token). The next Tab leaves the
// header behind.

const SKIP_PAGES: { name: string, path: string, setup?: (page: Page) => Promise<void> }[] = [
  { name: 'landing page', path: '/' },
  { name: 'invite entry', path: '/invite' },
  { name: 'link landing (token in the fragment)', path: `/invite/verify#t=${'A'.repeat(43)}` },
  { name: 'sign-up, full server', path: '/onboarding' },
  { name: 'success, in progress', path: `/success?session_id=${SESSION}`, setup: (page) => mockStatus(page, statusReply('provisioning')) },
  { name: 'success, no session', path: '/success' },
  { name: 'unsubscribe', path: `/unsubscribe?t=${'U'.repeat(60)}` },
  { name: 'cancel', path: '/cancel' },
];

test.describe('keyboard: the skip link (23-39)', () => {
  for (const target of SKIP_PAGES) {
    test(`${target.name}: the first Tab is "Skip to main content", Enter focuses main and keeps the address, the next Tab is past the header`, async ({ page }, testInfo) => {
      test.skip(testInfo.project.name !== 'onboarding', 'runs in the onboarding project');
      if (target.setup) await target.setup(page);
      await open(page, target.path);
      await page.keyboard.press('Tab');
      const link = page.getByRole('link', { name: 'Skip to main content' });
      await expect(link).toBeFocused();

      // Shown while focused, on screen, and on top of the sticky header.
      const box = await link.boundingBox();
      expect(box, 'the focused link has a box').not.toBeNull();
      expect(box!.width, 'at least 8px wide').toBeGreaterThanOrEqual(8);
      expect(box!.height, 'at least 8px tall').toBeGreaterThanOrEqual(8);
      const viewport = page.viewportSize()!;
      expect(box!.x >= 0 && box!.y >= 0 && box!.x + box!.width <= viewport.width && box!.y + box!.height <= viewport.height, 'inside the viewport').toBe(true);
      const onTop = await link.evaluate((el) => {
        const r = el.getBoundingClientRect();
        const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
        return hit === el || el.contains(hit);
      });
      expect(onTop, 'the element at its centre is the link, not the header').toBe(true);

      const before = page.url();
      await page.keyboard.press('Enter');
      await expect(page.locator('main#main-content')).toBeFocused();
      expect(page.url(), 'the address is unchanged').toBe(before);

      await page.keyboard.press('Tab');
      const next = await page.evaluate(() => {
        const a = document.activeElement;
        return {
          real: !!a && a !== document.body && a !== document.documentElement,
          inHeader: !!a?.closest('header'),
          skip: a?.getAttribute('href') === '#main-content',
        };
      });
      expect(next, 'the next Tab stop is outside the header and is not the skip link').toEqual({ real: true, inHeader: false, skip: false });
    });
  }

  test('error page: no layout and no skip link; its own content is the first Tab stop', async ({ page }, testInfo) => {
    // Orchestrator Q4 answer (2026-11-08): app/error.vue has no layout and no
    // header, so it needs no skip link, provided its main content is the first
    // focusable region.
    test.skip(testInfo.project.name !== 'onboarding', 'runs in the onboarding project');
    const reply = await page.goto('/no-such-page-23-39');
    expect(reply?.status()).toBe(404);
    await page.waitForFunction(() => {
      const app = (document.querySelector('#__nuxt') as unknown as { __vue_app__?: { config: { globalProperties: { $nuxt: { isHydrating?: boolean } } } } } | null)?.__vue_app__;
      return app?.config.globalProperties.$nuxt.isHydrating === false;
    });
    await expect(page.getByRole('heading', { name: '404' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Skip to main content' })).toHaveCount(0);
    await expect(page.locator('header')).toHaveCount(0);
    await page.keyboard.press('Tab');
    expect((await focused(page)).name, 'the first Tab stop is the error page\'s own link').toBe('Go Home');
  });

  test('focused skip link: axe', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'onboarding', 'runs in the onboarding project');
    await open(page, '/invite');
    await page.keyboard.press('Tab');
    await expect(page.getByRole('link', { name: 'Skip to main content' })).toBeFocused();
    await checkA11y(page, 'skip-link', 'focused', SCAN);
  });
});

/**
 * Pastes `text` into the focused field: a paste event the page could cancel,
 * then the text inserted as a paste would. Returns whether the page cancelled
 * the paste (23-UI-SPEC "Paste check" column).
 */
async function paste(page: Page, text: string): Promise<boolean> {
  const cancelled = await page.evaluate((value) => {
    const el = document.activeElement as HTMLInputElement;
    const data = new DataTransfer();
    data.setData('text/plain', value);
    const event = new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true });
    return !el.dispatchEvent(event);
  }, text);
  if (!cancelled) await page.keyboard.insertText(text);
  return cancelled;
}

test.describe('paste is never blocked', () => {
  test('sign-up site: Email, description, AI key and code accept a paste', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'onboarding', 'runs in the onboarding project');
    await open(page, '/invite');
    await tabUntil(page, (f) => f.tag === 'INPUT' && f.cls.includes('rounded-md'), { what: 'the Email field', cap: 40 });
    expect(await paste(page, 'organizer@example.org'), 'Email: paste not cancelled').toBe(false);
    await expect(page.getByRole('textbox', { name: 'Email' })).toHaveValue('organizer@example.org');

    await openForm(page);
    await tabToField(page, describeField(page), { what: 'Describe your community', cap: 40 });
    expect(await paste(page, 'Neighbours who fix bikes together.'), 'description: paste not cancelled').toBe(false);
    await expect(describeField(page)).toHaveValue('Neighbours who fix bikes together.');
    await tabUntil(page, (f) => f.name.startsWith('The LocalNodes default'), { what: 'the AI choice group', cap: 60 });
    await hold(page, 'ArrowDown');
    await tabToField(page, keyField(page), { what: 'the key field', cap: 10 });
    expect(await paste(page, 'test-key-not-real-0000000000000000'), 'AI key: paste not cancelled').toBe(false);
    await expect(keyField(page)).toHaveValue('test-key-not-real-0000000000000000');
    await tabToField(page, codeField(page), { what: 'the code field', cap: 20 });
    await page.keyboard.press('ControlOrMeta+A');
    expect(await paste(page, 'PILOT-PASTE'), 'code: paste not cancelled').toBe(false);
    await expect(codeField(page)).toHaveValue('PILOT-PASTE');
  });

  test('report form: Details and Your email accept a paste', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'anonymous', 'runs as an anonymous visitor');
    await page.goto(`/node/${importedPage()}/report-problem`);
    await tabUntil(page, (f) => f.id.startsWith('edit-details'), { what: 'Details', cap: 120 });
    expect(await paste(page, 'The second paragraph is my own text.'), 'Details: paste not cancelled').toBe(false);
    await expect(page.locator('[id^="edit-details"]').first()).toHaveValue('The second paragraph is my own text.');
    await tabUntil(page, (f) => f.id.startsWith('edit-contact-email'), { what: 'Your email', cap: 10 });
    expect(await paste(page, 'owner@example.org'), 'Your email: paste not cancelled').toBe(false);
    await expect(page.locator('[id^="edit-contact-email"]').first()).toHaveValue('owner@example.org');
  });
});

test.describe('keyboard: the report form and the takedown confirmation', () => {
  test('report form: the four reasons are one Tab stop, arrow keys move inside, the honeypot is never reached', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'anonymous', 'runs as an anonymous visitor');
    await page.goto(`/node/${importedPage()}/report-problem`);
    await tabUntil(page, (f) => f.tag === 'INPUT' && f.id.startsWith('edit-reason-owner'), { what: 'the first reason', cap: 120 });
    await page.keyboard.press('ArrowDown');
    expect((await focused(page)).id).toMatch(/^edit-reason-personal-info/);
    await page.keyboard.press('Tab');
    expect((await focused(page)).id).toMatch(/^edit-details/);
    await page.keyboard.press('Tab');
    expect((await focused(page)).id).toMatch(/^edit-contact-email/);
    await page.keyboard.press('Tab');
    expect((await focused(page)).name).toBe('Send report');
    const walk = await walkTabOrder(page);
    expect(walk.forward.map((s) => s.id).filter((id) => id.startsWith('edit-website'))).toEqual([]);
  });

  test('report form: axe, empty and with errors', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'anonymous', 'runs as an anonymous visitor');
    await page.goto(`/node/${importedPage()}/report-problem`);
    await checkA11y(page, 'report', 'empty', { include: ['main'], exclude: ['.navbar', '.ln-site-footer'], resultsDir: PHASE23_RESULTS_DIR });
    await tabUntil(page, isButton('Send report'), { what: 'Send report', cap: 120 });
    await page.keyboard.press('Enter');
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('[data-ln-error-summary]')).toBeVisible();
    await checkA11y(page, 'report', 'error', { include: ['main'], exclude: ['.navbar', '.ln-site-footer'], resultsDir: PHASE23_RESULTS_DIR });
  });

  test('report form: while the report is on its way, Send report reads "Sending…" with a spinner and is disabled', async ({ page }, testInfo) => {
    // 23-UI-SPEC section 10 "Sending" (23-05 hand-off; 23-29 finding 3).
    test.skip(testInfo.project.name !== 'anonymous', 'runs as an anonymous visitor');
    await page.goto(`/node/${importedPage()}/report-problem`);
    // The POST is held here and then dropped: it never reaches the site, so no
    // report is mailed and the flood count doesn't move.
    let held: Route | null = null;
    await page.route((url) => url.pathname === `/node/${importedPage()}/report-problem`, (route) => {
      if (route.request().method() === 'POST') held = route;
      else return route.continue();
    });
    await tabUntil(page, (f) => f.tag === 'INPUT' && f.id.startsWith('edit-reason-owner'), { what: 'the first reason', cap: 120 });
    await page.keyboard.press('Space');
    await tabUntil(page, isButton('Send report'), { what: 'Send report', cap: 10 });
    // While the POST is held the page is mid-navigation, and Playwright's
    // locators wait for that to finish, so the button reports its own changes
    // through a binding instead.
    const seen: { text: string, disabled: boolean, spinner: boolean, busy: string | null }[] = [];
    await page.exposeBinding('__lnSendButton', (_source, snap: typeof seen[number]) => {
      seen.push(snap);
    });
    await page.evaluate(() => {
      const form = document.querySelector('form.localnodes-import-report-problem')!;
      const report = () => {
        const button = form.querySelector<HTMLButtonElement>('[type="submit"]')!;
        const spinner = form.querySelector<HTMLElement>('.localnodes-import-spinner');
        (window as unknown as { __lnSendButton: (s: unknown) => void }).__lnSendButton({
          text: (button.textContent ?? '').trim(),
          disabled: button.disabled,
          spinner: !!spinner && !spinner.hidden && spinner.getBoundingClientRect().width > 0,
          busy: form.getAttribute('aria-busy'),
        });
      };
      new MutationObserver(report).observe(form, { subtree: true, childList: true, characterData: true, attributes: true });
    });
    await page.keyboard.press('Enter');
    await expect.poll(() => held !== null, { message: 'the report POST left the page' }).toBe(true);
    await expect.poll(() => seen.some((s) => s.text === 'Sending…' && s.disabled && s.spinner), { message: `button states seen: ${JSON.stringify(seen)}` }).toBe(true);
    // The chosen reason still went with the POST (the button was disabled after the form data was taken).
    expect(held!.request().postData() ?? '').toContain('reason=owner');
    await held!.abort();
  });

  test('takedown: axe on the confirmation; Escape keeps the page published and goes back to it', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'steward', 'runs as the steward');
    await page.goto(`/node/${importedPage()}/takedown`);
    await expect(page.getByRole('heading', { level: 1, name: /^Unpublish .+\?$/ })).toBeVisible();
    await checkA11y(page, 'takedown', 'confirmation', { include: ['main'], exclude: ['.navbar', '.ln-site-footer'], resultsDir: PHASE23_RESULTS_DIR });

    // The safe button comes first, by focus and in the Tab order.
    const order = await page.locator('form.localnodes-import-takedown').evaluate((form) =>
      Array.from(form.querySelectorAll('a[href], button, input[type="submit"]')).map((el) => (el.textContent || (el as HTMLInputElement).value || '').trim()),
    );
    expect(order.indexOf('Keep page published')).toBeGreaterThanOrEqual(0);
    expect(order.indexOf('Keep page published'), `safe button before the destructive one: ${JSON.stringify(order)}`).toBeLessThan(order.indexOf('Unpublish this page'));

    await page.keyboard.press('Escape');
    await page.waitForURL((url) => url.pathname === `/node/${importedPage()}`);
    await expect(page.getByRole('heading', { level: 1 })).not.toHaveText(/^Unpublish/);
  });
});
