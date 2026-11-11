// Phase 25 keyboard-only walk and screen-reader evidence (25-26 Task 2,
// UI Considerations "Keyboard and screen-reader pass of V1 to V10").
//
// Runs in the steward project; the vote blocks switch to the voter. Each
// test drives the screen with the keyboard only (Tab, Shift+Tab, arrows,
// Enter, Space, Escape), records what a screen reader is told (the live
// regions and focus moves, helpers/live-regions.ts) and the accessibility
// tree of the panel (locator.ariaSnapshot()), saves both to
// .planning/phases/25-group-governance-snapshot-zodiac-hats/a11y/keyboard-*.txt,
// and fails on a focus or announcement mismatch. Wallet prompts come from a
// stub wallet in the page, never a real one; the network guard fails any
// request to another host.
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { test, expect, type Page } from '@playwright/test';
import { focused, tabUntil } from './helpers/keyboard';
import { find, recordAnnouncements, type Announcement } from './helpers/live-regions';
import {
  GOVERNANCE_RESULTS_DIR,
  assertSiteOnly,
  authFile,
  guardNetwork,
  scan,
  seedGovernance,
  sessionFor,
  stubWallet,
  type Governance,
} from './helpers/governance-states';

const BASE_URL = process.env.LN_BASE_URL ?? 'https://fresh3.ddev.site';
const CHAIN_ID_HEX = '0xaa36a7';

let gov: Governance;

/** Writes one evidence file: the tree snapshots, then the announcements. */
function evidence(name: string, trees: Record<string, string>, log: Announcement[], notes: string[] = []): void {
  const lines: string[] = [`# ${name}`, ''];
  for (const note of notes) lines.push(note);
  if (notes.length) lines.push('');
  for (const [label, tree] of Object.entries(trees)) {
    lines.push(`## Accessibility tree: ${label}`, '', tree.trimEnd(), '');
  }
  lines.push('## What a screen reader is told (in order)', '');
  for (const a of log) {
    if (a.kind === 'title') continue;
    lines.push(`- [${a.kind}${a.politeness ? ' ' + a.politeness : ''}] ${a.source}: ${a.text.replace(/\s+/g, ' ').trim()}`);
  }
  writeFileSync(join(GOVERNANCE_RESULTS_DIR, `keyboard-${name}.txt`), lines.join('\n') + '\n');
}

/** Live-region writes (polite or assertive) whose text matches. */
const spoken = (log: Announcement[], text: string | RegExp) => find(log, 'live', text);

/**
 * Everything a screen reader says that matches: live-region writes plus
 * focus moves. A message that takes focus and is also a live region (or
 * role="alert") is read twice (25-26 follow-up, F1 and F2).
 */
const heard = (log: Announcement[], text: string | RegExp) => find(log, ['live', 'focus'], text);

test.describe('governance keyboard', () => {
  test.beforeAll(() => {
    gov = seedGovernance();
  });

  test.describe('voter', () => {
    test.beforeAll(async () => {
      await sessionFor('a11y-voter', 'voter', BASE_URL);
    });
    test.use({ storageState: authFile('voter') });

    test('governance keyboard V3: arrows in the vote choice, error summary, success alert', async ({ page }) => {
      gov = seedGovernance();
      const log = await recordAnnouncements(page);
      await stubWallet(page, gov.wallets.voter, CHAIN_ID_HEX, 'sign');
      const guard = await guardNetwork(page, BASE_URL);
      await page.goto(`/group/${gov.groups.ready}/votes/${gov.proposals.open_payment}`);
      const region = page.locator('[data-safe-governance-vote]');

      // Empty submit by keyboard: focus moves to the error summary.
      await tabUntil(page, (f) => f.name === 'Cast your vote', { what: '"Cast your vote"' });
      await page.keyboard.press('Enter');
      await expect(page.getByText('Choose For, Against or Abstain.')).toBeVisible();
      const summary = await focused(page);
      expect(summary.name, 'focus is on the error summary').toContain('Fix these to continue');
      await page.waitForTimeout(300);
      expect(heard(log, 'Fix these to continue').length, `the error summary is read once (focus, no role="alert"); heard ${JSON.stringify(heard(log, 'Fix these to continue'))}`).toBe(1);

      // The choice group: Tab lands in it once, arrows move the choice.
      let presses = 0;
      while (!(await page.evaluate(() => (document.activeElement as HTMLInputElement | null)?.type === 'radio'))) {
        expect(++presses, 'Tab reaches the vote choice').toBeLessThan(60);
        await page.keyboard.press('Tab');
      }
      await page.keyboard.press('Space');
      await expect(page.getByRole('radio', { name: 'For' })).toBeChecked();
      await page.keyboard.press('ArrowDown');
      await expect(page.getByRole('radio', { name: 'Against' })).toBeChecked();
      await page.keyboard.press('ArrowDown');
      await expect(page.getByRole('radio', { name: 'Abstain' })).toBeChecked();
      await page.keyboard.press('ArrowUp');
      await expect(page.getByRole('radio', { name: 'Against' })).toBeChecked();
      const before = await region.ariaSnapshot();

      // Cast it: the panel speaks each state once, then focus goes to the
      // success alert.
      await tabUntil(page, (f) => f.name.startsWith('Cast your vote'), { what: '"Cast your vote"' });
      await page.keyboard.press('Enter');
      await expect(page.getByText('Your vote is recorded.')).toBeVisible();
      const done = await focused(page);
      expect(done.name, 'focus is on the success alert').toContain('Your vote is recorded.');
      const said = log.filter((a) => a.kind === 'live' || a.kind === 'focus').map((a) => `${a.kind}: ${a.text}`);
      expect(spoken(log, 'Waiting for your approval').length, `"Waiting for your approval" spoken once; heard ${JSON.stringify(said)}`).toBe(1);
      expect(spoken(log, /^Recording your vote$|Recording your vote$/).length, '"Recording your vote" spoken once').toBe(1);
      await page.waitForTimeout(300);
      expect(heard(log, 'Your vote is recorded.').length, `the result is read once, on focus; heard ${JSON.stringify(heard(log, 'Your vote is recorded.'))}`).toBe(1);
      expect(spoken(log, 'Your vote is recorded.').length, 'the result is not also written to the live region').toBe(0);
      // The in-page success state through axe too (25-29, review gap).
      await scan(page, 'V3', 'voted-in-page');
      evidence('V3-vote', { 'Your vote, choice made': before, 'Your vote, recorded': await region.ariaSnapshot() }, log);
      assertSiteOnly(guard);
    });

    test('governance keyboard V2 and V4: panels as a screen reader meets them', async ({ page }) => {
      const log = await recordAnnouncements(page);
      const guard = await guardNetwork(page, BASE_URL);
      const trees: Record<string, string> = {};
      await page.goto(`/group/${gov.groups.ready}/votes`);
      trees['V1 How this group votes'] = await page.locator('#block-socialblue-content').getByRole('heading', { name: 'How this group votes' }).locator('..').ariaSnapshot();
      await page.goto(`/group/${gov.groups.ready}/votes/${gov.proposals.passed_decision}`);
      trees['V2 Final results'] = await page.getByRole('heading', { name: 'Final results' }).locator('..').ariaSnapshot();
      for (const key of ['safety_check', 'carried_out', 'on_hold']) {
        await page.goto(`/group/${gov.groups.ready}/votes/${gov.proposals[key]}`);
        trees[`V4 Carrying out the payment (${key})`] = await page.getByRole('heading', { name: 'Carrying out the payment' }).locator('..').ariaSnapshot();
      }
      for (const tree of Object.values(trees)) expect(tree.length).toBeGreaterThan(20);
      evidence('V2-V4-panels', trees, log);
      assertSiteOnly(guard);
    });
  });

  test.describe('organizer', () => {
    test.use({ storageState: authFile('steward') });

    test('governance keyboard V6: error summary, fraction buttons by Enter and Space, announced once', async ({ page }) => {
      const log = await recordAnnouncements(page);
      const guard = await guardNetwork(page, BASE_URL);
      await page.goto(`/group/${gov.groups.setup}/votes/setup`);
      const form = page.locator('[data-safe-governance-setup]');

      await tabUntil(page, (f) => f.name === 'Start voting setup', { what: '"Start voting setup"' });
      await page.keyboard.press('Enter');
      await page.waitForLoadState('load');
      await expect(page.getByText('Choose how many votes a proposal needs.').first()).toBeVisible();
      // The text can show before the reloaded page's scripts run, so wait
      // for the focus move rather than read focus at once.
      await expect.poll(async () => (await focused(page)).name, { message: 'focus is on the error summary' }).toContain('Fix these to continue');
      // One error summary (25-UI-SPEC V6): core's "‹n› errors have been
      // found" message is not shown beside it (25-26 follow-up, F6).
      await expect(page.getByText('Fix these to continue')).toHaveCount(1);
      await expect(page.getByText(/errors? (has|have) been found/)).toHaveCount(0);

      const quorum = page.locator('[data-safe-governance-quorum]');
      const pressed: string[] = [];
      for (const [label, key] of [['⅓', 'Enter'], ['⅔', 'Space'], ['½', 'Enter']] as const) {
        await tabUntil(page, (f) => f.name.includes(label), { what: `the ${label} button` });
        const startAt = log.length;
        await page.keyboard.press(key);
        const value = await quorum.inputValue();
        expect(value, `${label} fills in a whole number`).toMatch(/^[1-9][0-9]*$/);
        await expect.poll(() => spoken(log.slice(startAt), /votes? needed\./).length, { message: `${label} is announced` }).toBe(1);
        await page.waitForTimeout(300);
        expect(spoken(log.slice(startAt), /votes? needed\./).length, `${label} is announced once`).toBe(1);
        pressed.push(`${label} by ${key}: field ${value}; spoken "${spoken(log.slice(startAt), /votes? needed\./)[0].text}"`);
      }
      evidence('V6-setup', { 'Set Up Group Voting form': await form.ariaSnapshot() }, log, pressed.map((p) => `- ${p}`));
      assertSiteOnly(guard);
    });

    test('governance keyboard V8: the withdraw modal traps focus, Escape cancels, focus returns', async ({ page }) => {
      gov = seedGovernance('state', 'batch');
      const log = await recordAnnouncements(page);
      const guard = await guardNetwork(page, BASE_URL);
      try {
        await page.goto(`/group/${gov.groups.ready}/votes/members`);
        await tabUntil(page, (f) => f.name === 'Withdraw these changes', { what: '"Withdraw these changes"' });
        await page.keyboard.press('Enter');
        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeVisible();
        await expect.poll(async () => dialog.evaluate((d) => d.contains(document.activeElement))).toBe(true);
        const first = await focused(page);
        // Axe on the dialog once it is fully shown, focus on the safe
        // choice. Scanned while it fades in, or while the theme's button
        // ripple runs, axe measures blended colours and reports contrast
        // failures that a settled dialog doesn't have.
        await expect.poll(() => dialog.evaluate((d) => {
          let opacity = 1;
          for (let el: Element | null = d; el; el = el.parentElement) opacity *= Number(getComputedStyle(el).opacity);
          return opacity;
        })).toBe(1);
        await page.waitForTimeout(600);
        await scan(page, 'V8', 'withdraw-dialog', ['[role="dialog"]']);
        // F4: first focus is on a control a person can see, the safe choice.
        const firstBox = await page.evaluate(() => {
          const box = (document.activeElement as HTMLElement).getBoundingClientRect();
          return box.width * box.height;
        });
        expect(firstBox, `first focus "${first.name}" is visible`).toBeGreaterThan(0);
        expect(first.name).toBe('Keep waiting for approvals');
        // F4: one of each control, and every Tab and Shift+Tab moves focus.
        const controls = await dialog.evaluate((d) => Array.from(d.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea, [tabindex]:not([tabindex="-1"])'))
          .filter((el) => (el as HTMLElement).offsetParent !== null && !(el as HTMLElement).closest('[hidden], [aria-hidden="true"]'))
          .map((el) => (el.getAttribute('aria-label') ?? el.textContent ?? (el as HTMLInputElement).value ?? '').replace(/\s+/g, ' ').trim()));
        const named = controls.filter((n) => n !== '');
        expect(new Set(named).size, `each control once in the dialog: ${JSON.stringify(controls)}`).toBe(named.length);
        const inside: string[] = [first.name];
        const stalled: number[] = [];
        for (let i = 0; i < 8; i++) {
          const before = await page.evaluateHandle(() => document.activeElement);
          await page.keyboard.press('Tab');
          expect(await dialog.evaluate((d) => d.contains(document.activeElement)), 'Tab stays in the dialog').toBe(true);
          if (await page.evaluate((b) => b === document.activeElement, before)) stalled.push(i + 1);
          await before.dispose();
          inside.push((await focused(page)).name);
        }
        expect(stalled, `every Tab moves focus; ${JSON.stringify(inside)}`).toEqual([]);
        const backwards: string[] = [];
        for (let i = 0; i < 4; i++) {
          await page.keyboard.press('Shift+Tab');
          expect(await dialog.evaluate((d) => d.contains(document.activeElement)), 'Shift+Tab stays in the dialog').toBe(true);
          backwards.push((await focused(page)).name);
        }
        expect(new Set(backwards).size, `Shift+Tab moves through the controls: ${JSON.stringify(backwards)}`).toBeGreaterThan(1);
        const tree = await dialog.ariaSnapshot();
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        const back = await focused(page);
        expect(back.name, 'focus returns to the button that opened it').toBe('Withdraw these changes');
        const trees = { 'Approve new members, batch in progress': await page.locator('#block-socialblue-content').ariaSnapshot(), 'Withdraw dialog': tree };
        evidence('V8-withdraw', trees, log, [
          `- First focus in the dialog: "${first.name}"`,
          `- Tab order inside the dialog (8 presses): ${inside.map((n) => `"${n}"`).join(' > ')}`,
          `- Shift+Tab (4 presses): ${backwards.map((n) => `"${n}"`).join(' > ')}`,
          `- After Escape, focus: "${back.name}"`,
        ]);
        assertSiteOnly(guard);
      }
      finally {
        gov = seedGovernance();
      }
    });

    test('governance keyboard walks: V1, V2, V4, V5 and V7 by Tab, and "Copied" once', async ({ page }) => {
      // F7 (25-26 review): a Tab walk of each screen's content. Every stop
      // is visible and has a name, and no press leaves focus where it was.
      const log = await recordAnnouncements(page);
      const guard = await guardNetwork(page, BASE_URL);
      const screens: Record<string, string> = {
        'V1 votes': `/group/${gov.groups.ready}/votes`,
        'V2 open proposal': `/group/${gov.groups.ready}/votes/${gov.proposals.open_payment}`,
        'V4 carried out': `/group/${gov.groups.ready}/votes/${gov.proposals.carried_out}`,
        'V5 payment form': `/group/${gov.groups.ready}/votes/new/payment`,
        'V7 progress': `/group/${gov.groups.progress}/votes/setup`,
      };
      const notes: string[] = [];
      for (const [label, path] of Object.entries(screens)) {
        await page.goto(path);
        const stops: string[] = [];
        let previous = '';
        for (let i = 0; i < 80; i++) {
          await page.keyboard.press('Tab');
          const stop = await page.evaluate(() => {
            const el = document.activeElement as HTMLElement | null;
            const content = document.querySelector('#block-socialblue-content');
            if (!el || !content || !content.contains(el)) return null;
            const box = el.getBoundingClientRect();
            const name = (el.getAttribute('aria-label') ?? el.textContent ?? (el as HTMLInputElement).value ?? '').replace(/\s+/g, ' ').trim();
            const labelled = (el as HTMLInputElement).labels?.[0]?.textContent?.replace(/\s+/g, ' ').trim() ?? '';
            return { key: `${el.tagName}#${el.id}|${name}`, name: name || labelled, visible: box.width > 0 && box.height > 0 };
          });
          if (stop === null) {
            if (stops.length) break;
            continue;
          }
          expect(stop.key, `${label}: Tab ${i + 1} moved focus`).not.toBe(previous);
          expect(stop.visible, `${label}: ${stop.name} is visible`).toBe(true);
          expect(stop.name, `${label}: Tab stop ${i + 1} has a name`).not.toBe('');
          stops.push(stop.name);
          previous = stop.key;
        }
        expect(stops.length, `${label}: the content has Tab stops`).toBeGreaterThan(0);
        notes.push(`- ${label}: ${stops.map((n) => `"${n.slice(0, 60)}"`).join(' > ')}`);
      }

      // "Copied": the Short ID's Copy ID button says it once. The lane site
      // is plain http, where the browser has no clipboard API, so a stub
      // clipboard stands in (as the stub wallet does); the announcement is
      // the page's own.
      await page.addInitScript(() => {
        Object.defineProperty(navigator, 'clipboard', { value: { writeText: () => Promise.resolve() }, configurable: true });
      });
      await page.goto(`/group/${gov.groups.ready}/votes/${gov.proposals.carried_out}`);
      const copy = page.locator('#block-socialblue-content .safe-copy-id[data-copy]').first();
      if (await copy.count()) {
        const startAt = log.length;
        await copy.focus();
        await page.keyboard.press('Enter');
        await expect.poll(() => spoken(log.slice(startAt), 'Copied').length).toBe(1);
        notes.push('- V4 carried out: Copy ID by Enter; "Copied" spoken once');
      }
      else {
        notes.push('- V4 carried out: no Copy ID button on this state');
      }
      evidence('walks', {}, log, notes);
      assertSiteOnly(guard);
    });

    test('governance reflow: the organizer and member screens fit 320 px with no sideways scrolling', async ({ page }) => {
      // WCAG 1.4.10 (25-26 review: no reflow run). 320 CSS px wide.
      await page.setViewportSize({ width: 320, height: 640 });
      const guard = await guardNetwork(page, BASE_URL);
      const screens: Record<string, string> = {
        'V1 votes': `/group/${gov.groups.ready}/votes`,
        'V2 open proposal': `/group/${gov.groups.ready}/votes/${gov.proposals.open_payment}`,
        'V4 carried out': `/group/${gov.groups.ready}/votes/${gov.proposals.carried_out}`,
        'V5 choice': `/group/${gov.groups.ready}/votes/new`,
        'V5 payment form': `/group/${gov.groups.ready}/votes/new/payment`,
        'V6 setup form': `/group/${gov.groups.setup}/votes/setup`,
        'V7 progress': `/group/${gov.groups.progress}/votes/setup`,
        'V8 members': `/group/${gov.groups.ready}/votes/members`,
      };
      const lines: string[] = ['# reflow-320', '', 'Viewport 320 x 640 CSS px. scrollWidth must not pass the viewport width (WCAG 1.4.10).', ''];
      const wide: string[] = [];
      for (const [label, path] of Object.entries(screens)) {
        const response = await page.goto(path);
        expect(response?.status(), `${label} answers 200`).toBe(200);
        const width = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, view: window.innerWidth }));
        const offenders = width.scroll > width.view + 1 ? await page.evaluate((view) => Array.from(document.querySelectorAll('#block-socialblue-content *'))
          .filter((el) => el.getBoundingClientRect().right > view + 1)
          .slice(0, 5)
          .map((el) => `${el.tagName.toLowerCase()}.${(el as HTMLElement).className}`), width.view) : [];
        lines.push(`- ${label} (${path}): scrollWidth ${width.scroll}, viewport ${width.view}${offenders.length ? `; wider: ${offenders.join(', ')}` : ''}`);
        if (width.scroll > width.view + 1) wide.push(label);
      }
      writeFileSync(join(GOVERNANCE_RESULTS_DIR, 'reflow-320.txt'), lines.join('\n') + '\n');
      expect(wide, 'screens that scroll sideways at 320 px').toEqual([]);
      assertSiteOnly(guard);
    });

    test('governance keyboard V7 and V8: panels as a screen reader meets them', async ({ page }) => {
      const log = await recordAnnouncements(page);
      const guard = await guardNetwork(page, BASE_URL);
      const trees: Record<string, string> = {};
      await page.goto(`/group/${gov.groups.progress}/votes/setup`);
      trees['V7 Setting up group voting'] = await page.getByRole('heading', { name: 'Setting up group voting' }).locator('..').ariaSnapshot();
      await page.goto(`/group/${gov.groups.ready}/votes/members`);
      trees['V8 Waiting for approval'] = await page.getByRole('table').first().ariaSnapshot();
      // F14: the members without a wallet are collapsed: closed, and their
      // text isn't exposed until the summary is opened.
      const noWallet = page.locator('details', { hasText: "Members who haven't connected a wallet" });
      if (await noWallet.count()) {
        expect(await noWallet.first().evaluate((d) => (d as HTMLDetailsElement).open), 'collapsed').toBe(false);
        await expect(noWallet.first().getByText(/can't be approved yet/)).toBeHidden();
        trees['V8 Members who haven\'t connected a wallet (collapsed)'] = await noWallet.first().ariaSnapshot();
        expect(trees['V8 Members who haven\'t connected a wallet (collapsed)']).not.toContain("can't be approved yet");
      }
      for (const tree of Object.values(trees)) expect(tree.length).toBeGreaterThan(20);
      evidence('V7-V8-panels', trees, log);
      assertSiteOnly(guard);
    });
  });
});
