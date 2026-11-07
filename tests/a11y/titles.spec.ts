// Phase 22 page titles in Title Case (Tom, 2026-11-07; UI-SPEC Part 6).
//
// Page titles only: the browser <title> and the page's main title (the h1).
// Tabs, in-page headings, buttons and body copy stay sentence case, so
// "Search status" and "Past imports" (h2s on the Import tab) don't change.
// Each Phase 22 route is pinned here: the Import tab (S1), Upload Files
// (S2), Import Web Pages (S3), the batch page and the report (S4) and the
// refresh page.
//
// Runs as the steward, on 22-28's seeds. No AI endpoint is called.
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { test, expect, type Page } from '@playwright/test';
import { RESULTS_DIR } from './helpers/axe';
import { chooseFiles, pressPrimary, seedAll, tickRights, type Seeds } from './helpers/states';

let seeds: Seeds;
test.beforeAll(() => {
  seeds = seedAll();
});

const seen: { screen: string; route: string; title: string; h1: string }[] = [];

test.afterAll(() => {
  mkdirSync(RESULTS_DIR, { recursive: true });
  writeFileSync(join(RESULTS_DIR, 'titles.json'), JSON.stringify(seen, null, 2) + '\n');
});

async function pin(page: Page, screen: string, route: string, expected: string) {
  const h1 = page.locator('h1').first();
  await expect(h1).toBeVisible();
  const text = ((await h1.textContent()) ?? '').replace(/\s+/g, ' ').trim();
  const title = await page.title();
  seen.push({ screen, route, title, h1: text });
  expect(text, `${screen} main title`).toBe(expected);
  expect(title.split(' | ')[0], `${screen} <title>`).toBe(expected);
}

test.describe('page titles', () => {
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== 'steward', 'runs as the steward');
  });

  test('S1 Import tab', async ({ page }) => {
    await page.goto(`/group/${seeds.emptyGroup}/import`);
    await pin(page, 'S1', '/group/{group}/import', "Bring In Your Group's Documents");
  });

  test('S2 Upload Files', async ({ page }) => {
    await page.goto(`/group/${seeds.group}/import/files`);
    await pin(page, 'S2', '/group/{group}/import/files', 'Upload Files');
  });

  test('S3 Import Web Pages', async ({ page }) => {
    await page.goto(`/group/${seeds.group}/import/web`);
    await pin(page, 'S3', '/group/{group}/import/web', 'Import Web Pages');
  });

  test('S4 report', async ({ page }) => {
    await page.goto(`/group/${seeds.group}/import/runs/${seeds.refreshRun}`);
    await pin(page, 'S4', '/group/{group}/import/runs/{run}', 'Import Finished');
  });

  test('S4 refresh page', async ({ page }) => {
    await page.goto(`/group/${seeds.group}/import/refresh/${seeds.refreshNid}?run=${seeds.refreshRun}`);
    await pin(page, 'S4', '/group/{group}/import/refresh/{topic}', 'Refresh Bioregionalism (Source box fixture) from Its Source?');
  });

  test('S4 batch page', async ({ page }) => {
    // Hold the batch so its page stays up.
    await page.route(/\/batch\?.*op=do(&|$)/, async () => {});
    await page.goto(`/group/${seeds.group}/import/files`);
    await chooseFiles(page, ['pdf-01-tool-share-rules.pdf']);
    await tickRights(page);
    await pressPrimary(page);
    await page.waitForURL(/\/batch\?/, { timeout: 90_000 });
    await pin(page, 'S4', '/batch', 'Importing Your Files');
  });
});
