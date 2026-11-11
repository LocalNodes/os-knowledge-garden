// S1 Import tab and S5 "Preparing documents for the assistant's search": axe over S1 empty, S5
// populated and S5 paged, plus what the screens promise (D-05, D-12, D-21,
// D-22; UI-SPEC Part 8 S1 and S5).
//
// Runs as the steward. seed-status.php gives the steward two groups of
// their own, so "A11y Garden" (the other specs' group) stays as it is:
// - "A11y Status Garden": 5 imported pages (2 searchable, 2 waiting for two
//   hours, 1 scanned PDF) and 52 runs, so Past imports has a page 2;
// - "A11y Empty Garden": nothing imported.
// The seed resets the fixture's tracker rows on every run, so a cron run on
// DDEV between runs doesn't change what is checked here.
//
// Axe is scoped to the screen's content block and its h1, as for S2 and S3:
// socialblue's nav and the DeepChat widget have pre-existing violations
// (22-11-SUMMARY.md) that aren't these screens'.
import { execFileSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect, type Page } from '@playwright/test';
import { checkA11y } from './helpers/axe';

const REPO_ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const SCOPE = { include: ['#block-socialblue-content', 'h1'] };
const STALE =
  'Some documents have waited more than an hour. Preparing them may have stopped. Use Get help to tell the people who host this site.';

let statusGroup = '';
let emptyGroup = '';

function drushScript(script: string): string {
  return execFileSync('ddev', ['drush', 'php:script', script], {
    cwd: REPO_ROOT,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    timeout: 120_000,
  });
}

async function openLanding(page: Page, gid: string) {
  await page.goto(`/group/${gid}/import`);
  await expect(page.getByRole('heading', { level: 1, name: "Bring in your group's documents" })).toBeVisible();
}

const pastPager = (page: Page) => page.getByRole('navigation', { name: 'Past imports pages' });
const panel = (page: Page) => page.locator('.localnodes-import-status');

test.beforeAll(() => {
  const output = drushScript('tests/a11y/seed-status.php');
  statusGroup = output.match(/^LN_STATUS_GROUP=(\d+)\s*$/m)?.[1] ?? '';
  emptyGroup = output.match(/^LN_EMPTY_GROUP=(\d+)\s*$/m)?.[1] ?? '';
  expect(statusGroup, `seed-status.php printed LN_STATUS_GROUP. Output:\n${output}`).toMatch(/^\d+$/);
  expect(emptyGroup, 'seed-status.php printed LN_EMPTY_GROUP').toMatch(/^\d+$/);
});

test('S1 empty: no status panel, no Past imports, the empty card', async ({ page }) => {
  await openLanding(page, emptyGroup);
  await expect(page.getByRole('heading', { level: 2, name: 'Nothing imported yet' })).toBeVisible();
  // No promise that every item becomes searchable (22-30, Codex CX-05).
  await expect(page.getByText("Add files or web pages to this group. Each one becomes its own page in the group, and most are ready for search and the group's AI assistant within a few minutes.", { exact: true })).toBeVisible();
  await expect(page.getByRole('heading', { name: "Preparing documents for the assistant's search" })).toHaveCount(0);
  await expect(page.getByRole('heading', { name: 'Past imports' })).toHaveCount(0);
  await expect(page.getByRole('link', { name: /Upload files/ }).first()).toBeVisible();
  await expect(page.getByRole('link', { name: /Import web pages/ }).first()).toBeVisible();
  await checkA11y(page, 'S1', 'empty', SCOPE);
});

test('S5 populated: counts, stale alert, both lists open, refresh', async ({ page }) => {
  await openLanding(page, statusGroup);
  const status = panel(page);
  await expect(status.getByRole('heading', { level: 2, name: "Preparing documents for the assistant's search" })).toBeVisible();
  await expect(status.locator('.localnodes-import-status__summary')).toHaveText("2 of 5 documents are prepared for the assistant's search.");
  await expect(status.locator('.localnodes-import-status__breakdown li')).toHaveText([
    '2 still being prepared',
    "1 of these can't be prepared: it has too little readable text (listed below).",
  ]);
  const alert = status.getByRole('alert');
  await expect(alert).toHaveCount(1);
  await expect(alert).toHaveText(STALE);

  // The panel sits above the choice cards.
  const panelBox = await status.boundingBox();
  const cardsBox = await page.locator('.localnodes-import-landing__cards').boundingBox();
  expect(panelBox && cardsBox && panelBox.y < cardsBox.y, 'The status panel is above the cards').toBeTruthy();

  for (const label of ["See what's still waiting (2)", "See documents that can't be prepared (1)"]) {
    await status.locator('summary', { hasText: label }).click();
  }
  await expect(status.locator('.localnodes-import-status__waiting .localnodes-import-status__item')).toHaveCount(2);
  await expect(status.locator('.localnodes-import-status__no-text .localnodes-import-status__reason')).toHaveText(
    "This may be an image-only scan. Members can open it, but we couldn't read enough text for search and the AI assistant. Add a copy whose text you can select and copy.",
  );

  // "Check progress" reads the JSON route in place (no reload).
  await expect(status.locator('.localnodes-import-status__updated')).toContainText('Updated just now');
  const response = page.waitForResponse((r) => r.url().endsWith(`/group/${statusGroup}/import/status`));
  await status.getByRole('button', { name: 'Check progress' }).click();
  const json = await (await response).json();
  expect(json).toMatchObject({ total: 5, searchable: 2, waiting: 2, no_text: 1, stale: true });
  await expect(status.locator('.localnodes-import-status__summary')).toHaveText("2 of 5 documents are prepared for the assistant's search.");
  await expect(page).toHaveURL(new RegExp(`/group/${statusGroup}/import$`));

  // Past imports, page 1.
  await expect(page.getByRole('heading', { level: 2, name: 'Past imports' })).toBeVisible();
  await expect(page.locator('.localnodes-import-past__row')).toHaveCount(50);
  await expect(pastPager(page).locator('a[aria-current="page"]')).toHaveText('1');
  await expect(pastPager(page).getByRole('link', { name: 'Next page' })).toBeVisible();

  await checkA11y(page, 'S5', 'populated', SCOPE);
});

test('S5 paged: page 2 of Past imports', async ({ page }) => {
  await openLanding(page, statusGroup);
  await pastPager(page).getByRole('link', { name: 'Next page' }).click();
  await expect(pastPager(page).locator('a[aria-current="page"]')).toHaveText('2');
  await expect(pastPager(page).getByRole('link', { name: 'Previous page' })).toBeVisible();
  await expect(pastPager(page).getByRole('link', { name: 'Next page' })).toHaveCount(0);
  await expect(page.locator('.localnodes-import-past__row')).toHaveCount(2);
  // An entry reopens its report.
  const report = page.locator('.localnodes-import-past__row').first().getByRole('link');
  await expect(report).toHaveAttribute('href', new RegExp(`/group/${statusGroup}/import/runs/\\d+$`));

  await checkA11y(page, 'S5', 'paged', SCOPE);
});
