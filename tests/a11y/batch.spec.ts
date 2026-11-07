// S4 "Import run and per-file report" (22-17): the progress page in the
// community theme with an accessible bar, then the report, both through axe
// (D-05; UI-SPEC Part 8 S4).
//
// Runs as the steward (group manager of "A11y Garden"). Three files go up
// through S2: the scanned PDF, a spreadsheet (refused before upload) and a
// text PDF. The batch's first progress request is held so the in-progress
// page can be checked, then released so the run finishes and redirects to
// the report.
//
// Axe is scoped as for S2: on the report, the content block and the h1
// (socialblue's nav and the DeepChat widget have pre-existing violations,
// 22-11); on the batch page, which has no blocks, main and the h1.
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect, type Page } from '@playwright/test';
import { checkA11y } from './helpers/axe';

const FIXTURES = join(
  dirname(fileURLToPath(import.meta.url)),
  '..',
  '..',
  'html/modules/custom/localnodes_import/tests/fixtures/import/files',
);
const fixture = (name: string) => join(FIXTURES, name);

const SCOPE = { include: ['#block-socialblue-content', 'h1'] };
// The batch page has no block layout (no content block, no DeepChat): its
// main element holds the bar and the hint.
const BATCH_SCOPE = { include: ['main', 'h1'] };
// "Keep this tab open until the import finishes": the batch runs from this
// page, so leaving it stops the import (22-30, Codex review).
const HINT = "Keep this tab open until the import finishes. Each item becomes a page in the group as it's done.";
const SPREADSHEET = "Spreadsheets and slides can't be imported yet. Save it as a PDF, then add it again.";

function uploadPath(): string {
  const gid = process.env.LN_GROUP;
  expect(gid, 'global setup exports LN_GROUP').toMatch(/^\d+$/);
  return `/group/${gid}/import/files`;
}

async function startImport(page: Page) {
  await page.goto(uploadPath());
  await expect(page.getByRole('heading', { level: 1, name: 'Upload files' })).toBeVisible();
  await expect(page.locator('.localnodes-import-dropzone')).toBeVisible();
  await page.locator('input[type="file"].localnodes-import-upload__input').setInputFiles([
    fixture('scan_0042_final(2).pdf'),
    fixture('budget.xlsx'),
    fixture('pdf-06-repair-cafe-flyer.pdf'),
  ]);
  await expect(page.locator('.localnodes-import-chosen__count')).toHaveText('3 files chosen. 2 can be imported.');
  await page.locator('label[for="edit-rights-confirmed"]').click();
  await page.locator('form.localnodes-import-upload .form-actions [type="submit"]').click();
}

test.describe('S4 Import run and report', () => {
  test('progress page in socialblue with an accessible bar, then the report', async ({ page }) => {
    test.setTimeout(240_000);

    // Hold the batch's processing requests until the in-progress page has
    // been checked.
    // The first step waits for `gate`, every later one for `rest`.
    let release: () => void = () => {};
    let releaseRest: () => void = () => {};
    const gate = new Promise<void>((resolve) => {
      release = resolve;
    });
    const rest = new Promise<void>((resolve) => {
      releaseRest = resolve;
    });
    // The batch runs as many items per request as fit in a second, so the
    // first response may already be the last. It is shown with 99% so the
    // page stays put for the checks; the next request then finishes the run.
    let held = 0;
    await page.route(/\/batch\?.*op=do(&|$)/, async (route) => {
      held += 1;
      if (held === 1) {
        await gate;
        const response = await route.fetch();
        const body = await response.json();
        await route.fulfill({ response, json: { ...body, percentage: body.percentage === '100' ? '99' : body.percentage } });
        return;
      }
      await rest;
      await route.continue();
    });

    await startImport(page);
    await page.waitForURL(/\/batch\?/, { timeout: 90_000 });

    // In progress: the community theme, not the admin theme.
    await expect(page.getByRole('heading', { level: 1, name: 'Importing your files' })).toBeVisible();
    const theme = await page.evaluate(() => (window as unknown as { drupalSettings: { ajaxPageState: { theme: string } } }).drupalSettings.ajaxPageState.theme);
    expect(theme, 'socialblue renders the batch page').toBe('socialblue');
    await expect(page.locator('body[class*="socialblue--"]'), 'socialblue body class').toHaveCount(1);
    await expect(page.locator('body[class*="gin"], html[class*="gin"]'), 'Gin does not render the batch page').toHaveCount(0);

    // batch.js has built its bar and asked for the first step.
    await expect.poll(() => held, { timeout: 30_000 }).toBeGreaterThan(0);
    const bar = page.getByRole('progressbar', { name: 'Importing your files' });
    await expect(bar).toHaveCount(1);
    await expect(bar).toHaveAttribute('aria-valuetext', /^\d+ of \d+$/);
    await expect(bar).toHaveAttribute('aria-valuemin', '0');
    await expect(bar).toHaveAttribute('aria-valuemax', '3');
    await expect(bar).toHaveAttribute('aria-valuenow', '0');
    await expect(page.locator('.progress-wrapper .message')).toHaveText(/^0 of 3/);
    // One polite region announces finished items; the bar itself is quiet.
    await expect(page.locator('.progress-wrapper[aria-live]')).toHaveCount(0);
    await expect(page.locator('[data-ln-progress-live][aria-live="polite"]')).toHaveCount(1);
    await expect(page.getByText(HINT)).toBeVisible();

    await checkA11y(page, 'S4', 'in-progress', BATCH_SCOPE);

    // A step done: "‹i› of ‹N›: ‹name›" on screen (one line), the numbers in
    // ARIA, and the finished item in the polite region.
    release();
    const message = page.locator('.progress-wrapper .message');
    await expect(message).toHaveText(/^[1-3] of 3: \S/);
    const done = (await message.textContent())!.trim();
    const current = done.split(' ')[0];
    await expect(bar).toHaveAttribute('aria-valuenow', current);
    await expect(bar).toHaveAttribute('aria-valuetext', `${current} of 3`);
    await expect(page.locator('.progress-wrapper .progress-label')).toHaveText('');
    await expect(page.locator('[data-ln-progress-live]')).toHaveText(done);

    // Finish the run.
    releaseRest();
    await page.waitForURL(/\/import\/runs\/\d+/, { timeout: 180_000 });

    await expect(page.getByRole('heading', { level: 1, name: 'Import finished' })).toBeVisible();
    await expect(page.locator('.localnodes-import-report__summary')).toHaveText(/^\d+ of 3 imported\.$/);
    await expect(page.getByRole('heading', { level: 2, name: 'Results' })).toBeVisible();
    const rows = page.locator('tr.localnodes-import-report__row');
    await expect(rows, 'one row per chosen file, the refused one included').toHaveCount(3);
    // Problems first: the refused spreadsheet leads.
    await expect(rows.first()).toContainText('budget.xlsx');
    await expect(rows.first()).toContainText('Not imported');
    await expect(rows.first()).toContainText(SPREADSHEET);
    // "View imported pages" only when the run made a page (22-30): on a
    // rerun these files are already here, and nothing new is imported.
    const imported = Number(/^(\d+) of/.exec((await page.locator('.localnodes-import-report__summary').textContent()) ?? '')?.[1]);
    await expect(page.getByRole('link', { name: 'View imported pages' })).toHaveCount(imported > 0 ? 1 : 0);
    await expect(page.getByRole('link', { name: 'Import more documents', exact: true })).toBeVisible();
    // "Not searchable" is permanent, so its badge isn't the clock that
    // "In progress" uses (22-30 Codex).
    const clock = 'M12 3a9 9 0 1 0 0 18a9 9 0 1 0 0-18M12 7v5l3 2';
    for (const d of await page.locator('.localnodes-import-badge--attention path').evaluateAll((ps) => ps.map((x) => x.getAttribute('d')))) {
      expect(d, 'no clock on a Not searchable badge').not.toBe(clock);
    }

    await checkA11y(page, 'S4', 'finished', SCOPE);
  });
});
