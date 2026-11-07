// 22-30 Task 2: axe at 200% zoom and at 320 px wide, for every Phase 22
// screen state (UI-SPEC Part 5 "Zoom", WCAG 1.4.4 and 1.4.10).
//
// For each state in helpers/states.ts, in a fresh browser context:
//   - zoom200: half the 1280 x 900 window in CSS pixels (640 x 450) with
//     deviceScaleFactor 2, which lays the page out as 200% browser zoom does;
//   - 320: 320 x 640 CSS pixels (WCAG 1.4.10 reflow width).
// Then axe (helpers/axe.ts, WCAG 2.2 AA tags, 22-28's scope for the screen)
// must find 0 serious and 0 critical issues, and the page must not scroll
// sideways: document.documentElement.scrollWidth <= clientWidth. A data
// table or the group's tab bar may scroll inside its own box (UI-SPEC Part 5);
// that doesn't widen the page, so the check still holds.
// Results: a11y/axe-<screen>-<state>-zoom200.json and -320.json, plus
// a11y/reflow-<screen>-<state>.json with the widths measured.
//
// Same personas, seeds and no-live-AI guard as keyboard.spec.ts.
import { mkdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { test, expect } from '@playwright/test';
import { checkA11y, RESULTS_DIR } from './helpers/axe';
import { assertNoLiveAi } from './helpers/ai-guard';
import { STATES, enterState, seedAll, type Seeds } from './helpers/states';

const VIEWS = [
  { name: 'zoom200', viewport: { width: 640, height: 450 }, deviceScaleFactor: 2 },
  { name: '320', viewport: { width: 320, height: 640 }, deviceScaleFactor: 1 },
] as const;

let seeds: Seeds;
test.beforeAll(() => {
  seeds = seedAll();
});

function record(key: string, view: string, data: unknown) {
  mkdirSync(RESULTS_DIR, { recursive: true });
  const file = join(RESULTS_DIR, `reflow-${key}.json`);
  const all = existsSync(file) ? JSON.parse(readFileSync(file, 'utf8')) : {};
  all[view] = data;
  writeFileSync(file, JSON.stringify(all, null, 2) + '\n');
}

for (const state of STATES) {
  for (const view of VIEWS) {
    test(`${state.key} at ${view.name}: axe and no sideways scroll`, async ({ browser }, testInfo) => {
      test.skip(testInfo.project.name !== state.persona, `${state.key} runs as the ${state.persona}`);
      test.setTimeout(240_000);
      const use = testInfo.project.use;
      const context = await browser.newContext({
        baseURL: use.baseURL,
        storageState: use.storageState,
        ignoreHTTPSErrors: true,
        viewport: view.viewport,
        deviceScaleFactor: view.deviceScaleFactor,
        reducedMotion: 'reduce',
      });
      const page = await context.newPage();
      const run = await enterState(page, state, seeds);
      try {
        const widths = await page.evaluate(() => ({
          scrollWidth: document.documentElement.scrollWidth,
          clientWidth: document.documentElement.clientWidth,
          bodyScrollWidth: document.body.scrollWidth,
          // Boxes wider than the window that aren't inside a scrolling box
          // of their own (what would make the page scroll sideways).
          wider: Array.from(document.querySelectorAll('body *'))
            .filter((el) => {
              const r = el.getBoundingClientRect();
              if (r.width === 0 || r.right <= document.documentElement.clientWidth + 1) return false;
              for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
                const o = getComputedStyle(p).overflowX;
                if (o === 'auto' || o === 'scroll' || o === 'hidden' || o === 'clip') return false;
              }
              return getComputedStyle(el).position !== 'fixed';
            })
            .slice(0, 10)
            .map((el) => `${el.tagName.toLowerCase()}.${String((el as HTMLElement).className).split(' ')[0]} right=${Math.round(el.getBoundingClientRect().right)}`),
        }));
        record(state.key, view.name, { url: page.url(), viewport: view.viewport, deviceScaleFactor: view.deviceScaleFactor, ...widths });
        await checkA11y(page, state.screen, `${state.state}-${view.name}`, { include: state.scope });
        expect(widths.scrollWidth, `no sideways page scroll (${JSON.stringify(widths.wider)})`).toBeLessThanOrEqual(widths.clientWidth);
      } finally {
        run.release();
        await expect.poll(() => run.guard.fulfilled.length >= run.guard.seen.length, { timeout: 5_000 }).toBe(true).catch(() => null);
        assertNoLiveAi(run.guard);
        await context.close();
      }
    });
  }
}
