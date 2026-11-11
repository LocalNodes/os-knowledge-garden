// 22-30 Task 3: screen-reader proxy for every Phase 22 screen state.
//
// What a person with VoiceOver would hear can't be recorded here, so this
// records the two things a screen reader works from:
//   - the accessibility tree: locator('body').ariaSnapshot(), saved as
//     a11y/sr-<screen>-<state>.yml;
//   - what changes without the person moving: every live-region update,
//     progress value and focus move (helpers/live-regions.ts), saved as
//     a11y/sr-<screen>-<state>-announcements.json;
// and checks them against what UI-SPEC promises:
//   - an error summary is announced once per submit (it takes focus; it's
//     not also a live region);
//   - choosing files announces the count line (S2, Part 5);
//   - upload progress is announced per file, never per percent (S2), and
//     run progress at most once every 5 seconds (S4); the run's end is the
//     report page, titled "Import finished";
//   - citation links are named "Source n: ‹title›", and the Sources list is
//     a list after the answer (S7, S8);
//   - the answer, or the no-answer copy, is announced once (S7; Part 5:
//     changes without a page load go through a polite live region);
//   - the report table's cells sit under column headers (S4);
//   - external links carry "(opens another site)" (S6, Part 5);
//   - the overview announces that it's loading, then its result, once (S8).
//
// Same personas, seeds and no-live-AI guard as keyboard.spec.ts.
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { test, expect, type Page } from '@playwright/test';
import { RESULTS_DIR } from './helpers/axe';
import { assertNoLiveAi } from './helpers/ai-guard';
import { find, gaps, recordAnnouncements, type Announcement } from './helpers/live-regions';
import { tabUntil } from './helpers/keyboard';
import { STATES, chooseFiles, enterState, pressPrimary, seedAll, tickRights, type Seeds, type StateRun, type UiState } from './helpers/states';
import { NO_ANSWER_GROUP } from './fixtures/canned-ai';

test.use({ viewport: { width: 1280, height: 900 }, reducedMotion: 'reduce' });

const ERROR_HEADING = 'Fix these to continue';
const RQ01_FIRST = 'Bioregionalism organizes human, social, and economic life';
const OVERVIEW_LOADING = 'Writing an overview of the results';
const OVERVIEW_DONE = 'AI overview. Written by AI from pages you can see, using your search words. Check the sources.';

let seeds: Seeds;
test.beforeAll(() => {
  seeds = seedAll();
});

let run: StateRun | undefined;
test.afterEach(async () => {
  if (run) {
    const guard = run.guard;
    run.release();
    await expect.poll(() => guard.fulfilled.length >= guard.seen.length, { timeout: 5_000 }).toBe(true).catch(() => null);
    assertNoLiveAi(guard);
  }
  run = undefined;
});

function save(name: string, data: string) {
  mkdirSync(RESULTS_DIR, { recursive: true });
  writeFileSync(join(RESULTS_DIR, name), data);
}

/** Per-state checks, on top of saving the tree and the log. */
const CHECKS: Record<string, (page: Page, log: Announcement[], state: UiState) => Promise<void>> = {
  'S1-populated': async (page, log) => {
    // The stale-worker alert is rendered once by the server; nothing
    // re-announces it.
    expect.soft(find(log, 'initial', 'Some documents have waited'), 'stale alert present at load').toHaveLength(1);
    expect.soft(find(log, ['live', 'suppressed'], 'Some documents have waited'), 'stale alert not re-announced').toEqual([]);
  },
  'S2-error': async (page, log) => {
    const focusOnSummary = find(log, 'focus', ERROR_HEADING);
    expect.soft(focusOnSummary.length, 'the error summary takes focus once (one submit)').toBe(1);
    expect.soft(focusOnSummary[0]?.source ?? '', 'it is the error summary').toContain('localnodes-import-error-summary');
    expect.soft(find(log, ['live', 'suppressed'], ERROR_HEADING), 'and is not also a live region').toEqual([]);
  },
  'S3-error': async (page, log) => {
    const focusOnSummary = find(log, 'focus', ERROR_HEADING);
    expect.soft(focusOnSummary.length, 'the error summary takes focus once (one submit)').toBe(1);
    expect.soft(find(log, ['live', 'suppressed'], ERROR_HEADING), 'and is not also a live region').toEqual([]);
  },
  'S2-filled': async (page, log) => {
    // Focus stays on "Choose files" after the file dialog, so the count line
    // is announced (22-30 SR-07).
    expect.soft(find(log, 'live', '3 files chosen. 2 can be imported.'), 'the count line is announced once').toHaveLength(1);
  },
  'S2-uploading': async (page, log) => {
    // The summary takes focus on submit, so the first count is heard once,
    // by the focus move; it isn't also a live region (22-30 Q3, SR-04).
    expect.soft(find(log, 'focus', 'Uploading 1 of 2'), '"Uploading 1 of 2" heard once, on focus').toHaveLength(1);
    expect.soft(find(log, 'live', 'Uploading 1 of 2'), 'and not again through a live region').toEqual([]);
    expect.soft(find(log, 'live', /\d+%/), 'never a percentage').toEqual([]);
  },
  'S4-report': async (page, log) => {
    expect.soft(find(log, 'title', /^Import Finished/), 'the run ends on a page titled "Import finished"').not.toHaveLength(0);
    await checkReportTable(page);
  },
  'S2-stopped': async (page, log) => {
    expect.soft(find(log, 'title', /^Import Finished/), 'stopping ends on the report, titled "Import finished"').not.toHaveLength(0);
    await checkReportTable(page);
  },
  'S6-web': async (page) => {
    const box = page.locator('.localnodes-import-source');
    const external = await box.locator('a[href^="http"]').evaluateAll((as) => as.map((a) => a.getAttribute('href') ?? ''));
    expect(external.length).toBeGreaterThan(0);
    for (const href of external) {
      const link = box.locator(`a[href="${href}"]`);
      const name = await link.evaluate((a) => a.getAttribute('aria-label') ?? a.textContent ?? '');
      const description = await link.evaluate((a) => {
        const ids = (a.getAttribute('aria-describedby') ?? '').split(/\s+/).filter(Boolean);
        return ids.map((id) => document.getElementById(id)?.textContent ?? '').join(' ');
      });
      expect.soft(`${name} ${description}`, `${href} says it opens another site (name or description)`).toContain('(opens another site)');
    }
  },
  'S7-answered': async (page, log) => {
    await checkCitations(page.locator('deep-chat'), 'a.ln-cite', 'ol.ln-sources');
    const answer = find(log, 'live', RQ01_FIRST);
    expect.soft(answer.length, 'the answer is announced once through a polite live region').toBe(1);
    expect.soft(answer[0]?.politeness ?? '', 'politely').toBe('polite');
  },
  'S7-no-answer': async (page, log) => {
    const answer = find(log, 'live', NO_ANSWER_GROUP);
    expect.soft(answer.length, 'the no-answer copy is announced once').toBe(1);
  },
  'S8-loading': async (page, log) => {
    const loading = find(log, 'live', OVERVIEW_LOADING);
    expect.soft(loading.length, `"${OVERVIEW_LOADING}" is announced once (not held back by aria-busy)`).toBe(1);
  },
  'S8-loaded': async (page, log) => {
    // Here the canned answer comes back at once, before the 400 ms skeleton
    // delay, so there's no loading message (Part 3); the loading-then-result
    // order is the flow test below.
    await checkCitations(page.getByRole('complementary', { name: 'AI overview' }), '.ai-overview__marker', 'ol.ai-overview__source-list');
    expect.soft(find(log, 'live', OVERVIEW_DONE).length, 'the result announced once').toBe(1);
  },
};

/** Markers named "Source n: title"; a Sources list (a list) after the answer. */
async function checkCitations(scope: ReturnType<Page['locator']>, markerSelector: string, listSelector: string) {
  const markers = scope.locator(markerSelector);
  const count = await markers.count();
  expect(count, 'the answer has citation markers').toBeGreaterThan(0);
  for (let i = 0; i < count; i++) {
    const text = (await markers.nth(i).textContent())?.trim() ?? '';
    const n = /^\[(\d+)\]$/.exec(text)?.[1];
    await expect.soft(markers.nth(i), `marker ${text}`).toHaveAccessibleName(new RegExp(`^Source ${n}: \\S`));
  }
  const list = scope.locator(listSelector);
  await expect.soft(list, 'the Sources list is an ordered list').toHaveCount(1);
  expect.soft(await list.evaluate((el) => el.tagName), 'an <ol>').toBe('OL');
  const after = await list.evaluate((ol, sel) => {
    const first = (ol.getRootNode() as Document | ShadowRoot).querySelector(sel);
    return !!first && !!(first.compareDocumentPosition(ol) & Node.DOCUMENT_POSITION_FOLLOWING);
  }, markerSelector);
  expect.soft(after, 'the Sources list comes after the answer').toBe(true);
}

/** Every report cell sits under a column header. */
async function checkReportTable(page: Page) {
  const table = page.locator('table.localnodes-import-report');
  await expect(table).toBeVisible();
  const headers = table.getByRole('columnheader');
  await expect.soft(headers, 'column headers').toHaveText(['File', 'Result', 'What you can do']);
  const shape = await table.evaluate((t: HTMLTableElement) => ({
    thead: !!t.tHead,
    scopes: Array.from(t.querySelectorAll('thead th')).map((th) => th.getAttribute('scope')),
    rows: Array.from(t.tBodies[0]?.rows ?? []).map((r) => r.cells.length),
    headerCount: t.tHead?.rows[0]?.cells.length ?? 0,
  }));
  expect.soft(shape.thead, 'headers are in <thead>').toBe(true);
  expect.soft(shape.rows.every((n) => n === shape.headerCount), 'every row has one cell per header').toBe(true);
}

test.describe('screen-reader proxy: every state', () => {
  for (const state of STATES) {
    test(`${state.key}: tree snapshot, announcements`, async ({ page }, testInfo) => {
      test.skip(testInfo.project.name !== state.persona, `${state.key} runs as the ${state.persona}`);
      test.setTimeout(240_000);
      const log = await recordAnnouncements(page);
      run = await enterState(page, state, seeds);
      // Let pending announcements land (Drupal.announce waits 200 ms; the
      // log reaches the test asynchronously).
      await page.waitForTimeout(800);
      const tree = await page.locator('body').ariaSnapshot();
      save(`sr-${state.key}.yml`, tree + '\n');
      save(`sr-${state.key}-announcements.json`, JSON.stringify({ state: state.key, url: page.url(), log }, null, 2) + '\n');
      await CHECKS[state.key]?.(page, log, state);
    });
  }
});

test.describe('screen-reader proxy: flows', () => {
  test('S2 error summary: announced once per submit, twice for two submits', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'steward', 'runs as the steward');
    const log = await recordAnnouncements(page);
    run = await enterState(page, STATES.find((s) => s.key === 'S2-error')!, seeds);
    await pressPrimary(page);
    await expect(page.locator('[data-ln-error-summary]')).toBeFocused();
    await page.waitForTimeout(300);
    const focusOnSummary = find(log, 'focus', ERROR_HEADING);
    save('sr-S2-error-two-submits.json', JSON.stringify(log, null, 2) + '\n');
    expect(focusOnSummary, 'one announcement per submit').toHaveLength(2);
    expect(find(log, ['live', 'suppressed'], ERROR_HEADING)).toEqual([]);
  });

  test('S2 upload progress: at most one announcement every 5 seconds, then the final count; never a percentage', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'steward', 'runs as the steward');
    test.setTimeout(180_000);
    // 22-30 Q3: S2 is throttled like S4. The visible summary still changes
    // with every file; a screen reader hears the latest count at most every
    // 5 seconds, then the final count when the uploads end.
    const log = await recordAnnouncements(page);
    run = await enterState(page, STATES.find((s) => s.key === 'S2-empty')!, seeds);
    await page.route('**/import/files/upload**', async (route) => {
      await new Promise((r) => setTimeout(r, 1500));
      await route.continue();
    });
    const files = ['pdf-02-orchard-pruning-notes.pdf', 'pdf-05-beekeeping-log.pdf', 'handbook-draft-v2.docx', 'newsletter-autumn.docx', 'volunteer-rota.docx', 'welcome-letter.docx'];
    await chooseFiles(page, files);
    await tickRights(page);
    await pressPrimary(page);
    await expect(page.locator('.localnodes-import-chosen__summary')).not.toHaveAttribute('aria-live', /.+/);
    await page.waitForURL(/\/batch\?|\/import\/runs\/\d+/, { timeout: 180_000 });
    await page.waitForTimeout(300);
    const progress = find(log, 'live', /Uploading \d+ of \d+|Starting the import/);
    const between = gaps(progress);
    save('sr-S2-upload-progress.json', JSON.stringify({ progress, gapsMs: between, log }, null, 2) + '\n');
    expect(find(log, 'focus', 'Uploading 1 of 6'), 'the first count, on focus').toHaveLength(1);
    expect(progress.length, 'some progress is announced').toBeGreaterThan(0);
    for (const gap of between.slice(0, -1)) {
      expect(gap, 'at most one announcement every 5 seconds (the final count aside)').toBeGreaterThanOrEqual(4900);
    }
    expect(progress[progress.length - 1].text, 'the final count').toContain('Uploading 6 of 6');
    expect(progress.length, 'fewer announcements than files').toBeLessThan(files.length);
    expect(find(log, 'live', /\d+%/), 'never a percentage').toEqual([]);
  });

  test('S4 run progress: at most one announcement every 5 seconds, then the report', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'steward', 'runs as the steward');
    test.setTimeout(240_000);
    const log = await recordAnnouncements(page);
    run = await enterState(page, STATES.find((s) => s.key === 'S2-empty')!, seeds);
    // The real batch finishes these files in one or two steps. To check the
    // throttle, the first six progress responses are stood in for, one item
    // a second ("‹i› of 6: ‹name›", the batch's own format); after that the
    // real steps run and the batch finishes and opens the report.
    const names = ['Shared kitchen guidelines', 'Volunteer rota', 'Newsletter autumn', 'Bike repair steps', 'Workshop signup sheet', 'Storytelling evening'];
    let step = 0;
    await page.route(/\/batch\?.*op=do(&|$)/, async (route) => {
      if (step < names.length) {
        step += 1;
        await new Promise((r) => setTimeout(r, 1000));
        await route.fulfill({
          contentType: 'application/json',
          body: JSON.stringify({ status: true, percentage: String(Math.min(99, Math.round((step / 6) * 100) - 1)), message: `${step} of 6`, label: `${step} of 6: ${names[step - 1]}` }),
        });
        return;
      }
      await route.continue();
    });
    await chooseFiles(page, ['shared-kitchen-guidelines.odt', 'volunteer-rota.docx', 'newsletter-autumn.docx', 'bike-repair-steps.docx', 'workshop_signup_sheet.docx', 'storytelling-evening.doc']);
    await tickRights(page);
    await pressPrimary(page);
    await page.waitForURL(/\/import\/runs\/\d+/, { timeout: 240_000 });
    await page.waitForTimeout(300);
    const progress = find(log, 'live').filter((a) => a.source.includes('localnodes-import-progress-live'));
    const between = gaps(progress);
    save('sr-S4-run-progress.json', JSON.stringify({ progress, gapsMs: between, log }, null, 2) + '\n');
    expect(progress.length, 'finished items are announced').toBeGreaterThan(1);
    for (const gap of between) {
      expect(gap, 'at most one announcement every 5 seconds').toBeGreaterThanOrEqual(4900);
    }
    expect(find(log, 'title', /^Import Finished/), 'the run ends on the report').not.toHaveLength(0);
  });

  test('S8: "Writing an overview of the results", then the result, once each', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'member', 'runs as the member');
    const log = await recordAnnouncements(page);
    run = await enterState(page, STATES.find((s) => s.key === 'S8-loading')!, seeds);
    await page.waitForTimeout(1500);
    run.release();
    await expect(page.locator('[data-ai-overview] .ai-overview__marker').first()).toBeVisible();
    await page.waitForTimeout(800);
    const loading = find(log, 'live', OVERVIEW_LOADING);
    const done = find(log, 'live', OVERVIEW_DONE);
    save('sr-S8-loading-then-loaded.json', JSON.stringify({ loading, done, log }, null, 2) + '\n');
    expect(loading, 'loading announced once').toHaveLength(1);
    expect(done, 'result announced once').toHaveLength(1);
    expect(done[0].t, 'loading first, then the result').toBeGreaterThan(loading[0].t);
    expect(find(log, 'suppressed'), 'nothing held back by aria-busy').toEqual([]);
  });

  test('S5: "Check progress" with documents still waiting announces nothing', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'steward', 'runs as the steward');
    const log = await recordAnnouncements(page);
    run = await enterState(page, STATES.find((s) => s.key === 'S1-populated')!, seeds);
    const response = page.waitForResponse((r) => r.url().endsWith(`/group/${seeds.statusGroup}/import/status`));
    await tabUntil(page, (f) => f.name === 'Check progress', { what: '"Check progress"' });
    await page.keyboard.press('Enter');
    await response;
    await page.waitForTimeout(500);
    save('sr-S5-refresh.json', JSON.stringify(log, null, 2) + '\n');
    expect(find(log, ['live', 'suppressed'], /prepared/)).toEqual([]);
  });
});
