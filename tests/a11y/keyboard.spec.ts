// 22-30 Task 1: a keyboard-only run over every Phase 22 screen state (S1 to
// S8), with trace and video (UI-SPEC Part 3 focus rules, Part 5).
//
// For each state in helpers/states.ts, reached with the keyboard only:
//   - Tab from the top of the page reaches every visible interactive element,
//     in document (section) order, and comes back round (no keyboard trap);
//   - Shift+Tab walks the same stops in reverse;
//   - every stop shows a focus indicator (its focused style differs from its
//     unfocused style once transitions finish) and isn't hidden off screen or
//     under a sticky or floating element (SC 2.4.11);
// and the per-state stops are saved as a11y/focus-<state>.json.
// Behaviour tests then check what Part 3 and Part 8 promise: the error
// summary's links move focus to their field, the refresh page's initial
// focus and its way back, and the chat opening and closing by keyboard with
// focus on its launcher.
//
// Record: LN_RECORD=1 turns on trace and video for every test (see
// playwright.config.ts); `--trace on` works too.
//
// Runs as the steward and as the member; each state test skips in the other
// persona's project. Reduced motion is on so focus rings are read without a
// transition in flight; helpers/keyboard.ts also waits for transitions.
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { test, expect } from '@playwright/test';
import { checkA11y, RESULTS_DIR } from './helpers/axe';
import { assertNoLiveAi } from './helpers/ai-guard';
import { expectedTabStops, focused, tabUntil, walkTabOrder } from './helpers/keyboard';
import { STATES, enterState, openChat, seedAll, type Seeds, type StateRun } from './helpers/states';

test.use({ viewport: { width: 1280, height: 900 }, reducedMotion: 'reduce' });

let seeds: Seeds;
test.beforeAll(() => {
  seeds = seedAll();
});

let run: StateRun | undefined;
test.afterEach(async () => {
  if (run) {
    const guard = run.guard;
    run.release();
    // A held canned answer is released here; let the route fulfil it first.
    await expect.poll(() => guard.fulfilled.length >= guard.seen.length, { timeout: 5_000 }).toBe(true).catch(() => null);
    assertNoLiveAi(guard);
  }
  run = undefined;
});

function save(name: string, data: unknown) {
  mkdirSync(RESULTS_DIR, { recursive: true });
  writeFileSync(join(RESULTS_DIR, name), JSON.stringify(data, null, 2) + '\n');
}

test.describe('keyboard: every state', () => {
  for (const state of STATES) {
    test(`${state.key}: Tab order, focus visible, no trap`, async ({ page }, testInfo) => {
      test.skip(testInfo.project.name !== state.persona, `${state.key} runs as the ${state.persona}`);
      test.setTimeout(240_000);
      run = await enterState(page, state, seeds);

      const afterSetup = await focused(page);
      const expected = await expectedTabStops(page);
      const walk = await walkTabOrder(page);

      // Known pre-existing components that aren't Phase 22's (Open Social's
      // emoji picker) are recorded in the JSON but left out of the
      // assertions; see 22-A11Y-MANUAL.md.
      const ours = <T extends { preexisting: string | null }>(list: T[]) => list.filter((s) => !s.preexisting);
      const preexisting = walk.forward.filter((s) => s.preexisting);
      const forwardIds = ours(walk.forward).map((s) => s.path);
      const expectedIds = ours(expected).map((s) => s.path);
      const missing = ours(expected).filter((e) => !forwardIds.includes(e.path));
      const extra = ours(walk.forward).filter((s) => !expectedIds.includes(s.path));
      const backwardIds = ours(walk.backward).map((s) => s.path).reverse();
      const noIndicator = ours(walk.forward).filter((s) => !s.indicator);
      // A stop under the open "AI assistant" panel is allowed by SC 2.4.11's
      // note on content the person opened, because Escape closes the panel
      // without moving focus (tested below); it's recorded, not failed.
      const chatOpen = await page.evaluate(() => document.querySelector('.ai-deepchat--header')?.getAttribute('aria-expanded') === 'true');
      const allHidden = [
        ...ours(walk.forward).map((s) => ({ ...s, key: 'Tab' })),
        ...ours(walk.backward).map((s) => ({ ...s, key: 'Shift+Tab' })),
      ].filter((s) => !s.visibleInViewport);
      const underOpenChat = allHidden.filter((s) => chatOpen && s.coveredBy.startsWith('ai-deepchat'));
      const hidden = allHidden.filter((s) => !underOpenChat.includes(s));

      save(`focus-${state.key}.json`, {
        state: state.key,
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
        shiftTabMatches: JSON.stringify(backwardIds) === JSON.stringify(forwardIds),
        noIndicator,
        hiddenWhenFocused: hidden,
        underOpenChat,
        forward: walk.forward,
        backward: walk.backward,
      });

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

test.describe('keyboard: behaviour', () => {
  test('S1 entry: the group\'s Import tab can be reached by keyboard from the group page', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'steward', 'runs as the steward');
    // 22-30 KB-08 (Q1 = C): Import is among the visible tabs from 900 px
    // wide (tab weight 55), so Tab reaches it.
    run = await enterState(page, STATES.find((s) => s.key === 'S7-closed')!, seeds);
    await tabUntil(page, (f) => (f.href ?? '').endsWith(`/group/${seeds.group}/import`), { what: 'the Import tab', cap: 80 });
  });

  for (const width of [900, 1280]) {
    test(`group tabs: Import is a visible tab at ${width} px`, async ({ page }, testInfo) => {
      test.skip(testInfo.project.name !== 'steward', 'runs as the steward');
      await page.setViewportSize({ width, height: 900 });
      run = await enterState(page, STATES.find((s) => s.key === 'S7-closed')!, seeds);
      await expect(page.locator('.navbar-secondary').getByRole('link', { name: 'Import', exact: true })).toBeVisible();
    });
  }

  test('group tabs: "More group pages" is a button; Enter opens the list, Tab enters it, Escape closes it and focus returns', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'steward', 'runs as the steward');
    // 22-30 KB-08 (Q1 = C): socialblue's overflow arrow was a bare
    // <span class="caret">. It now holds a real button.
    run = await enterState(page, STATES.find((s) => s.key === 'S7-closed')!, seeds);
    const button = page.locator('.navbar-secondary').getByRole('button', { name: 'More group pages' });
    const list = page.locator('.navbar-secondary .hidden-list');
    await tabUntil(page, (f) => f.tag === 'BUTTON' && f.name === 'More group pages', { what: '"More group pages"', cap: 80 });
    await expect(button).toHaveAttribute('aria-expanded', 'false');
    await page.keyboard.press('Enter');
    await expect(button).toHaveAttribute('aria-expanded', 'true');
    await expect(list).toBeVisible();
    await page.keyboard.press('Tab');
    expect(await page.evaluate(() => !!document.activeElement?.closest('.navbar-secondary .hidden-list')), 'Tab goes from the button into the list').toBe(true);
    // The list's links: palette A's muted text (#555555), at least 4.5:1 on white.
    const colours = await list.locator('a').evaluateAll((as) => as.map((a) => getComputedStyle(a).color));
    expect(colours.length).toBeGreaterThan(0);
    for (const colour of colours) {
      expect(colour, 'overflow link colour').toBe('rgb(85, 85, 85)');
    }
    await checkA11y(page, 'group-tabs', 'overflow-open', { include: ['.navbar-secondary'] });
    await page.keyboard.press('Escape');
    await expect(button).toHaveAttribute('aria-expanded', 'false');
    await expect(list).toBeHidden();
    expect((await focused(page)).name, 'Escape returns focus to the button').toBe('More group pages');
    save('focus-group-tabs.json', { colours, afterEscape: await focused(page) });
  });

  test('S2 error: the summary has focus, and each link moves focus to its field', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'steward', 'runs as the steward');
    run = await enterState(page, STATES.find((s) => s.key === 'S2-error')!, seeds);
    const summary = page.locator('[data-ln-error-summary]');
    await expect(summary).toBeFocused();
    const links = await summary.getByRole('link').evaluateAll((as) => as.map((a) => ({ name: a.textContent?.trim() ?? '', href: a.getAttribute('href') ?? '' })));
    expect(links.map((l) => l.name)).toEqual([
      'Choose at least one file to import.',
      'Confirm you have the right to share these here before importing.',
    ]);
    const results: { link: string; focus: string }[] = [];
    for (const link of links) {
      await summary.focus();
      await tabUntil(page, (f) => f.name === link.name, { what: link.name, cap: 5 });
      await page.keyboard.press('Enter');
      const now = await focused(page);
      results.push({ link: link.name, focus: `#${now.id}` });
      expect.soft(`#${now.id}`, `"${link.name}" moves focus to its field`).toBe(link.href);
    }
    save('focus-S2-error-links.json', results);
  });

  test('S3 error: the summary has focus, and each link moves focus to its field', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'steward', 'runs as the steward');
    run = await enterState(page, STATES.find((s) => s.key === 'S3-error')!, seeds);
    const summary = page.locator('[data-ln-error-summary]');
    await expect(summary).toBeFocused();
    const links = await summary.getByRole('link').evaluateAll((as) => as.map((a) => ({ name: a.textContent?.trim() ?? '', href: a.getAttribute('href') ?? '' })));
    expect(links.length).toBeGreaterThan(0);
    const results: { link: string; focus: string }[] = [];
    for (const link of links) {
      await summary.focus();
      await tabUntil(page, (f) => f.name === link.name, { what: link.name, cap: 5 });
      await page.keyboard.press('Enter');
      const now = await focused(page);
      results.push({ link: link.name, focus: `#${now.id}` });
      expect.soft(`#${now.id}`, `"${link.name}" moves focus to its field`).toBe(link.href);
    }
    save('focus-S3-error-links.json', results);
  });

  test('refresh page: initial focus on "Keep current version", which goes back to the report', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'steward', 'runs as the steward');
    run = await enterState(page, STATES.find((s) => s.key === 'S4-refresh')!, seeds);
    const start = await focused(page);
    const report = `/group/${seeds.group}/import/runs/${seeds.refreshRun}`;
    // UI-SPEC Part 3 "Confirmation": the safe button gets initial focus.
    expect.soft(start.name, 'initial focus').toBe('Keep current version');
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(new RegExp(`${report}(#.*)?$`));
    const back = await focused(page);
    save('focus-S4-refresh-return.json', { initialFocus: start, afterKeep: { url: page.url(), focus: back } });
  });

  test('S7: the chat opens and closes by keyboard (Enter and Escape), and focus returns to its launcher', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'member', 'runs as the member');
    run = await enterState(page, STATES.find((s) => s.key === 'S7-closed')!, seeds);
    const header = page.locator('.ai-deepchat--header');
    await openChat(page);
    expect((await focused(page)).cls).toContain('ai-deepchat--header');
    // Into the question box and back.
    await tabUntil(page, (f) => f.id === 'text-input', { what: 'the question box', cap: 5 });
    await tabUntil(page, (f) => f.cls.includes('ai-deepchat--header'), { back: true, what: 'the launcher', cap: 5 });
    await page.keyboard.press('Enter');
    await expect(header).toHaveAttribute('aria-expanded', 'false');
    const after = await focused(page);
    expect(after.cls, 'focus is on the launcher after closing').toContain('ai-deepchat--header');
    // Escape in the question box closes the panel and puts focus on the
    // launcher (22-30 KB-07).
    await page.keyboard.press('Enter');
    await expect(header).toHaveAttribute('aria-expanded', 'true');
    await tabUntil(page, (f) => f.id === 'text-input', { what: 'the question box', cap: 5 });
    await page.keyboard.press('Escape');
    const escapeInChat = { expanded: await header.getAttribute('aria-expanded'), focus: await focused(page) };
    expect.soft(escapeInChat.expanded, 'Escape in the question box closes the panel').toBe('false');
    expect.soft(escapeInChat.focus.cls, 'then focus is on the launcher').toContain('ai-deepchat--header');

    // Escape on the page while the panel is open closes it without moving
    // focus, so content under the panel can always be uncovered (SC 2.4.11).
    await page.keyboard.press('Enter');
    await expect(header).toHaveAttribute('aria-expanded', 'true');
    await tabUntil(page, (f) => f.tag === 'A' && !f.cls.includes('ai-deepchat'), { back: true, what: 'a link on the page', cap: 40 });
    const before = await focused(page);
    await page.keyboard.press('Escape');
    const escapeOnPage = { expanded: await header.getAttribute('aria-expanded'), before, focus: await focused(page) };
    expect.soft(escapeOnPage.expanded, 'Escape on the page closes the panel').toBe('false');
    expect.soft(escapeOnPage.focus, 'without moving focus').toEqual(before);
    save('focus-S7-launcher.json', { afterClose: after, escapeInQuestionBox: escapeInChat, escapeOnPage });
  });
});
