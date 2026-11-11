// 23-29 Task 2: screen-reader proxy for every Phase 23 screen state (D-24,
// ROADMAP SC9; 23-UI-SPEC.md "Accessibility checklist", column "Screen reader
// hears").
//
// No screen reader runs here, so this records the two things a screen reader
// works from, as 22-30 did for Phase 22:
//   - the accessibility tree: locator('body').ariaSnapshot(), saved as
//     a11y/sr-<state>.yml;
//   - what changes without the person moving: every live-region update and
//     focus move (helpers/live-regions.ts, the 22-30 recorder, unchanged),
//     saved as a11y/sr-<state>-announcements.json;
// and checks them against what the checklist promises:
//   - the invite's "sent" reply is announced once;
//   - each progress step change is announced once, and nothing else is (not
//     the time line, not a percentage);
//   - the failure reason is announced once;
//   - form errors are named by their field (the field's description carries
//     the message);
//   - the AI key field is a password field, and the key is never in the tree
//     after "Key checked";
//   - the guide's steps are an ordered list of five, each with an h2.
// What this can't check: how a real screen reader phrases, orders or times
// what it reads (verbosity, pronunciation, double readings it merges). The
// person's pass is recorded as deferred in 23-A11Y-EVIDENCE.md.
//
// Same states, projects and mocks as keyboard-phase23.spec.ts
// (helpers/phase23-states.ts).
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { test, expect, type Page } from '@playwright/test';
import { PHASE23_RESULTS_DIR } from './helpers/axe';
import { find, recordAnnouncements, type Announcement } from './helpers/live-regions';
import { tabUntil } from './helpers/keyboard';
import { FAKE_KEY, SESSION, mockStatusSequence, openForm, requireOnboardingSite, statusReply, open } from './helpers/onboarding-mocks';
import { STATES, hold, isField, isButton, keyField, seedImportedPage, tabToField, type, type Phase23State } from './helpers/phase23-states';

test.use({ viewport: { width: 1280, height: 900 }, reducedMotion: 'reduce' });

// INVITE_COPY.entry.sentReply (app/utils/invite-flow.ts in the sign-up site).
const SENT_REPLY = 'If this address is invited, we\'ve sent a sign-in link. It works once, for 15 minutes.';

function save(name: string, data: string) {
  mkdirSync(PHASE23_RESULTS_DIR, { recursive: true });
  writeFileSync(join(PHASE23_RESULTS_DIR, name), data);
}

/**
 * helpers/live-regions.ts logs a change inside a live region, not a region
 * added with its text already in it. Browsers fire an "alert" event when an
 * element with role="alert" is added, and screen readers read it, so this
 * logs those too (kind "alert-shown"), into the same log.
 */
const ALERT_INIT = `(() => {
  if (window.__lnAlertInstalled) return;
  window.__lnAlertInstalled = true;
  const norm = (s) => (s || '').replace(/\\s+/g, ' ').trim();
  const seen = new WeakSet();
  const check = (root) => {
    if (root.nodeType !== 1) return;
    const found = root.matches('[role="alert"]') ? [root] : Array.from(root.querySelectorAll('[role="alert"]'));
    for (const el of found) {
      if (seen.has(el)) continue;
      seen.add(el);
      const text = norm(el.textContent);
      if (text) window.__lnAnnounce({ t: Date.now(), kind: 'alert-shown', politeness: 'assertive', source: el.tagName.toLowerCase() + '[role=alert]', text });
    }
  };
  const start = () => {
    document.querySelectorAll('[role="alert"]').forEach((el) => seen.add(el));
    new MutationObserver((ms) => ms.forEach((m) => m.addedNodes.forEach(check))).observe(document.body, { childList: true, subtree: true });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})()`;

async function recordAll(page: Page): Promise<Announcement[]> {
  const log = await recordAnnouncements(page);
  await page.addInitScript(ALERT_INIT);
  return log;
}

/** Role="alert" elements added with their text, as logged by ALERT_INIT. */
const alertShown = (log: Announcement[], text: string) =>
  log.filter((a) => (a.kind as string) === 'alert-shown' && a.text.includes(text));

/** Live-region entries (polite or assertive) whose text matches. */
const live = (log: Announcement[], text: RegExp | string) => find(log, 'live', text);

/** Per-state checks, on top of saving the tree and the log. */
const CHECKS: Record<string, (page: Page, log: Announcement[], tree: string) => Promise<void>> = {
  'invite-error': async (page, log) => {
    await expect.soft(page.getByRole('textbox', { name: 'Email' }), 'the error is named by its field').toHaveAccessibleDescription(/This isn't an email address/);
    expect.soft(find(log, 'focus', 'Fix these to continue'), 'the summary is heard once, by taking focus').toHaveLength(1);
  },
  'invite-sent': async (page, log) => {
    expect.soft(live(log, SENT_REPLY), 'the sent reply is announced once').toHaveLength(1);
    expect.soft(find(log, 'focus', 'Check your email'), 'focus moves to "Check your email"').toHaveLength(1);
  },
  'verify-expired': async (page, log) => {
    // F8 (23-39; 23-UI-SPEC section 2): heard once, by the focus move to the
    // box, which has no alert role; never as an alert or through a live region.
    const message = 'This link has expired or was already used. Ask for a new one.';
    expect.soft(find(log, 'focus', message), 'the message is heard once, by focus').toHaveLength(1);
    expect.soft(alertShown(log, message), 'and not as an added alert').toHaveLength(0);
    expect.soft(live(log, message), 'and not through a live region').toHaveLength(0);
  },
  'signup-error': async (page, log) => {
    await expect.soft(page.getByRole('textbox', { name: 'Community name' }), 'the error is named by its field').toHaveAccessibleDescription(/Enter a name/);
    expect.soft(find(log, 'focus', 'Fix these to continue'), 'the summary is heard once, by taking focus').toHaveLength(1);
  },
  'signup-page-types': async (page, log) => {
    expect.soft(live(log, '5 page types suggested. You can edit them.'), 'the suggested count is announced once').toHaveLength(1);
    for (const name of ['Meeting notes', 'How-to', 'Repair logs', 'Parts list', 'Events']) {
      await expect.soft(page.getByRole('button', { name: `Remove ${name}` }), `the row's remove button is "Remove ${name}"`).toHaveCount(1);
    }
  },
  'signup-key-checked': async (page, log, tree) => {
    expect.soft(tree.includes(FAKE_KEY), 'the key is not in the tree after "Key checked"').toBe(false);
    expect.soft(await page.content(), 'the key is nowhere in the page').not.toContain(FAKE_KEY);
    expect.soft(live(log, 'Key checked'), '"Key checked" is announced once').toHaveLength(1);
    // The question names the group the radios sit in (the fieldset's legend).
    // Two radios: "Your own OpenRouter key" is hidden while
    // NUXT_OPENROUTER_CHOICE_ENABLED is off, its default (23-18 follow-up).
    await expect.soft(page.getByRole('group', { name: 'Which AI should your site use?' }).getByRole('radio')).toHaveCount(2);
    await expect.soft(page.getByRole('radio', { name: 'Your own OpenRouter key' }), 'Option 3 is hidden by default').toHaveCount(0);
  },
  'success-failed': async (page, log) => {
    // F8 (23-39; 23-UI-SPEC section 5): heard by the focus move to the box,
    // once. The box has no alert role, so it is never also read as an added
    // alert, and never through the polite region.
    const reason = 'We couldn\'t start your site.';
    expect.soft(find(log, 'focus', reason), 'the failure reason is heard once, by focus').toHaveLength(1);
    expect.soft(live(log, reason), 'and not through a live region').toHaveLength(0);
    expect.soft(alertShown(log, reason), 'and not as an added alert').toHaveLength(0);
  },
  'success-stopped': async (page, log) => {
    expect.soft(find(log, 'focus', 'We Couldn\'t Set Up Your Site'), 'focus moves to the h1').toHaveLength(1);
    expect.soft(alertShown(log, 'We couldn\'t set up your site. You weren\'t charged'), 'then the alert, once').toHaveLength(1);
  },
  // 23-43 Task 7: each new screen is heard by the focus move to its h1, once; its alert is
  // never an added alert (F8) and nothing goes through the polite region.
  'success-removing': async (page, log) => {
    expect.soft(find(log, 'focus', 'Removing Your Site'), 'focus moves to the h1').toHaveLength(1);
    expect.soft(alertShown(log, 'so we\'re removing your site'), 'the alert is not an added alert').toHaveLength(0);
    expect.soft(find(log, ['live', 'suppressed']), 'nothing is announced in the polite region').toHaveLength(0);
  },
  'success-refunding': async (page, log) => {
    expect.soft(find(log, 'focus', 'Refunding Your Payment'), 'focus moves to the h1').toHaveLength(1);
    expect.soft(alertShown(log, 'so we\'re refunding your payment'), 'the alert is not an added alert').toHaveLength(0);
    expect.soft(find(log, ['live', 'suppressed']), 'nothing is announced in the polite region').toHaveLength(0);
  },
  'success-refunded': async (page, log) => {
    expect.soft(find(log, 'focus', 'We\'ve Refunded Your Payment'), 'focus moves to the h1').toHaveLength(1);
    expect.soft(alertShown(log, 'so we\'ve refunded your payment in full'), 'the alert is not an added alert').toHaveLength(0);
    expect.soft(find(log, ['live', 'suppressed']), 'nothing is announced in the polite region').toHaveLength(0);
  },
  'success-welcome': async (page, log) => {
    expect.soft(find(log, 'focus', 'Your Community\'s Site Is Ready'), 'focus moves to the h1').toHaveLength(1);
    await expect.soft(page.getByRole('link', { name: 'Open your site (opens another site)' })).toHaveCount(1);
  },
  'guide': async (page) => {
    const steps = page.locator('.localnodes-guide ol').first();
    await expect.soft(steps, 'the steps are an ordered list').toHaveJSProperty('tagName', 'OL');
    const items = steps.locator(':scope > li');
    await expect.soft(items).toHaveCount(5);
    for (let i = 0; i < 5; i++) {
      await expect.soft(items.nth(i).getByRole('heading', { level: 2 }), `step ${i + 1} has an h2`).toHaveCount(1);
    }
  },
  'register-consent-error': async (page) => {
    await expect.soft(page.getByRole('checkbox', { name: 'I agree to the site terms' }), 'the error is named by its field').toHaveAccessibleDescription(/Agree to the site terms to create your account/);
  },
  'report-form': async (page) => {
    await expect.soft(page.getByRole('radiogroup', { name: 'What\'s the problem?' }), 'the reasons are one radio group named by the legend').toHaveCount(1);
    await expect.soft(page.getByRole('textbox', { name: 'Website' }), 'the honeypot is not in the tree').toHaveCount(0);
  },
  'report-error': async (page) => {
    await expect.soft(page.getByRole('textbox', { name: 'Your email (optional)' }), 'the error is named by its field').toHaveAccessibleDescription(/This isn't an email address/);
    await expect.soft(page.getByRole('radiogroup', { name: 'What\'s the problem?' }), 'the reason error is named by its group').toHaveAccessibleDescription(/Choose what the problem is/);
  },
  'takedown': async (page, log) => {
    expect.soft(find(log, 'focus', 'Keep page published'), 'the safe button has focus when the page opens').toHaveLength(1);
  },
};

/** Whether the mocked key appears anywhere in Chrome's full accessibility tree. */
async function keyInChromeTree(page: Page): Promise<boolean> {
  const cdp = await page.context().newCDPSession(page);
  const tree = await cdp.send('Accessibility.getFullAXTree');
  await cdp.detach();
  return JSON.stringify(tree).includes(FAKE_KEY);
}

test.beforeAll(async ({}, testInfo) => {
  if (testInfo.project.name === 'onboarding') await requireOnboardingSite(test.skip);
  else seedImportedPage();
});

async function record(page: Page, state: Phase23State) {
  const log = await recordAll(page);
  await state.enter(page);
  if (state.after) await state.after(page);
  // Let any announcement still queued (the recorder batches for 30 ms) land.
  await page.waitForTimeout(300);
  const tree = await page.locator('body').ariaSnapshot();
  save(`sr-${state.key}.yml`, tree + '\n');
  save(`sr-${state.key}-announcements.json`, JSON.stringify({ state: state.key, row: state.row, url: page.url(), log }, null, 2) + '\n');
  return { log, tree };
}

test.describe('screen-reader proxy: every Phase 23 state', () => {
  for (const state of STATES) {
    test(`${state.key}: tree and announcements`, async ({ page }, testInfo) => {
      test.skip(testInfo.project.name !== state.persona, `${state.key} runs in the ${state.persona} project`);
      test.setTimeout(120_000);
      const { log, tree } = await record(page, state);
      expect(tree.length, 'the tree was read').toBeGreaterThan(0);
      await CHECKS[state.key]?.(page, log, tree);
    });
  }
});

test.describe('screen-reader proxy: behaviour over time', () => {
  test('progress: each step change is announced once, and nothing else is', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'onboarding', 'runs in the onboarding project');
    test.setTimeout(120_000);
    const log = await recordAll(page);
    await mockStatusSequence(page, [
      statusReply('triggered'),
      statusReply('provisioning'),
      statusReply('provisioning'),
      statusReply('installing'),
      statusReply('creating_user'),
      statusReply('sending_email'),
    ]);
    await open(page, `/success?session_id=${SESSION}`);
    // A poll every 3 seconds: six replies, then the last one again.
    await expect(page.locator('[aria-live="polite"]')).toHaveText('Installing your site.', { timeout: 30_000 });
    await page.waitForTimeout(9_000);
    const tree = await page.locator('body').ariaSnapshot();
    save('sr-success-progress-steps.yml', tree + '\n');
    save('sr-success-progress-steps-announcements.json', JSON.stringify({ state: 'success-progress-steps', row: 4, url: page.url(), log }, null, 2) + '\n');
    const said = find(log, ['live', 'suppressed']).map((a) => a.text);
    expect(said, 'one polite announcement per step change, and nothing else').toEqual(['Setting up your server.', 'Installing your site.']);
  });

  test('AI key: a password field while it is typed; the key never reaches the tree', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'onboarding', 'runs in the onboarding project');
    await page.route('**/api/ai-key', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' }));
    await openForm(page);
    await tabUntil(page, (f) => f.name.startsWith('The LocalNodes default'), { what: 'the AI choice group', cap: 60 });
    await hold(page, 'ArrowDown');
    await tabToField(page, keyField(page), { what: 'the key field', cap: 10 });
    await expect(keyField(page)).toHaveAttribute('type', 'password');
    await type(page, FAKE_KEY);
    // Chrome's own accessibility tree, the one screen readers read. (Playwright's
    // ariaSnapshot() is its own reading of the DOM and shows a password field's
    // raw value, so it isn't used for this check, and no snapshot is saved while
    // the key is in the field.)
    expect(await keyInChromeTree(page), 'the typed key is not in Chrome\'s accessibility tree').toBe(false);
    await tabUntil(page, isField('ai-billing'), { what: 'the billing box', cap: 10 });
    await page.keyboard.press('Space');
    await tabUntil(page, isButton('Check key'), { what: 'Check key', cap: 10, back: true });
    await page.keyboard.press('Enter');
    await expect(page.getByText('Key checked', { exact: true }).first()).toBeVisible();
    expect(await keyInChromeTree(page), 'the key is not in Chrome\'s accessibility tree after "Key checked"').toBe(false);
    const after = await page.locator('body').ariaSnapshot();
    expect(after.includes(FAKE_KEY), 'nor in the snapshot after "Key checked"').toBe(false);
  });
});
