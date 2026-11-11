// S2 "Upload files" (22-14): axe over the six states, plus the behaviour
// the screen promises (D-05, D-11, D-25b, D-26; UI-SPEC Part 8 S2).
//
// Runs as the steward (group manager of "A11y Garden"). Axe is scoped to the
// screen's own content block and its h1: socialblue's nav and the DeepChat
// widget have pre-existing violations (22-11-SUMMARY.md) that aren't S2's.
import { execFileSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
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
const IMPORTABLE = /\.(pdf|docx|doc|odt|txt|md)$/;

const SCOPE = { include: ['#block-socialblue-content', 'h1'] };
const UPLOAD_ROUTE = '**/import/files/upload**';
const RIGHTS = "I have the right to share these here, because they're openly licensed or I have permission.";
const RIGHTS_ERROR = 'Confirm you have the right to share these here before importing.';

function uploadPath(): string {
  const gid = process.env.LN_GROUP;
  expect(gid, 'global setup exports LN_GROUP').toMatch(/^\d+$/);
  return `/group/${gid}/import/files`;
}

async function openForm(page: Page) {
  await page.goto(uploadPath());
  await expect(page.getByRole('heading', { level: 1, name: 'Upload files' })).toBeVisible();
  // upload.js has wrapped the input.
  await expect(page.locator('.localnodes-import-dropzone')).toBeVisible();
}

const fileInput = (page: Page) => page.locator('input[type="file"].localnodes-import-upload__input');
const primary = (page: Page) => page.locator('form.localnodes-import-upload .form-actions [type="submit"]');
// socialbase draws the checkbox under its label, so people (and the test)
// click the label.
const tickRights = (page: Page) => page.locator('label[for="edit-rights-confirmed"]').click();

test.describe('S2 Upload files', () => {
  test('empty: keyboard reaches Choose files, Reference is the default type', async ({ page }) => {
    await openForm(page);
    await expect(page.getByRole('heading', { level: 2, name: 'Choose your files' })).toBeVisible();
    // The form names where the files go (22-30, Codex CX-04/07).
    await expect(page.getByText('Importing into A11y Garden', { exact: true })).toBeVisible();
    await expect(page.locator('select[name="topic_type"] option:checked')).toHaveText('Reference');
    await expect(page.getByLabel('Type of page')).toBeVisible();
    await expect(page.getByLabel(RIGHTS)).not.toBeChecked();
    await expect(page.locator('input[name="visibility"]:checked')).toHaveValue('group');
    await expect(primary(page)).toHaveText('Import files');

    // Tab from the top of the page until the file input has focus.
    let reached = false;
    for (let i = 0; i < 80 && !reached; i++) {
      await page.keyboard.press('Tab');
      reached = await page.evaluate(() => (document.activeElement as HTMLInputElement | null)?.type === 'file');
    }
    expect(reached, '"Choose files" is reachable by keyboard').toBe(true);

    await checkA11y(page, 'S2', 'empty', SCOPE);
  });

  test('filled: rows, Can\'t import badge, remove buttons named per file', async ({ page }) => {
    await openForm(page);
    await fileInput(page).setInputFiles([
      fixture('pdf-01-tool-share-rules.pdf'),
      fixture('welcome-letter.docx'),
      fixture('budget.xlsx'),
    ]);
    await expect(page.locator('.localnodes-import-chosen__count')).toHaveText('3 files chosen. 2 can be imported.');
    await expect(page.locator('.localnodes-import-chosen__row')).toHaveCount(3);
    const refused = page.locator('.localnodes-import-chosen__row.is-refused');
    await expect(refused).toContainText("Can't import");
    await expect(refused).toContainText("Spreadsheets and slides can't be imported yet. Save it as a PDF, then add it again.");
    await expect(primary(page)).toHaveText('Import 2 files');

    const removes = page.locator('.localnodes-import-chosen__remove');
    await expect(removes).toHaveCount(3);
    for (const name of await removes.evaluateAll((nodes) => nodes.map((n) => n.textContent ?? ''))) {
      expect(name.startsWith('Remove '), `remove button name "${name}"`).toBe(true);
    }
    await expect(page.getByRole('button', { name: 'Remove budget.xlsx' })).toBeVisible();

    // Same name and size again: skipped.
    await fileInput(page).setInputFiles([fixture('welcome-letter.docx')]);
    await expect(page.locator('.localnodes-import-chosen__note')).toContainText('Already in your list');
    await expect(page.locator('.localnodes-import-chosen__row')).toHaveCount(3);

    await checkA11y(page, 'S2', 'filled', SCOPE);
  });

  test('overflow: 21 files in one action are all listed', async ({ page }) => {
    await openForm(page);
    const names = readdirSync(FIXTURES).filter((n) => IMPORTABLE.test(n)).sort().slice(0, 21);
    expect(names).toHaveLength(21);
    await fileInput(page).setInputFiles(names.map(fixture));
    await expect(page.locator('.localnodes-import-chosen__count')).toHaveText('21 files chosen. 21 can be imported.');
    await expect(page.locator('.localnodes-import-chosen__row')).toHaveCount(21);
    await expect(primary(page)).toHaveText('Import 21 files');
  });

  test('error: submitting with nothing chosen', async ({ page }) => {
    await openForm(page);
    await primary(page).click();
    const summary = page.locator('[data-ln-error-summary]');
    await expect(summary.getByRole('heading', { name: 'Fix these to continue' })).toBeVisible();
    await expect(summary.getByRole('link', { name: 'Choose at least one file to import.' })).toBeVisible();
    await expect(summary).toBeFocused();
    await checkA11y(page, 'S2', 'error', SCOPE);
  });

  test('rights-unticked: nothing is sent', async ({ page }) => {
    let uploads = 0;
    await page.route(UPLOAD_ROUTE, async (route) => {
      uploads += 1;
      await route.abort();
    });
    await openForm(page);
    await fileInput(page).setInputFiles([fixture('pdf-02-orchard-pruning-notes.pdf')]);
    await primary(page).click();
    const summary = page.locator('[data-ln-error-summary]');
    await expect(summary.getByRole('link', { name: RIGHTS_ERROR })).toBeVisible();
    await expect(summary.getByRole('link', { name: RIGHTS_ERROR })).toHaveAttribute('href', '#edit-rights-confirmed');
    await expect(summary).toBeFocused();
    await page.waitForTimeout(500);
    expect(uploads, 'no request reached the upload endpoint').toBe(0);
    await checkA11y(page, 'S2', 'rights-unticked', SCOPE);
  });

  test('in-progress: per-file status and one live summary', async ({ page }) => {
    // Hold the first upload so the screen stays mid-upload.
    let held = false;
    await page.route(UPLOAD_ROUTE, async () => {
      held = true;
    });
    await openForm(page);
    await fileInput(page).setInputFiles([
      fixture('pdf-03-rainwater-barrels.pdf'),
      fixture('pdf-04-potluck-checklist.pdf'),
    ]);
    await tickRights(page);
    await expect(page.getByLabel(RIGHTS)).toBeChecked();
    await primary(page).click();
    const summary = page.locator('.localnodes-import-chosen__summary');
    await expect(summary).toHaveText('Uploading 1 of 2');
    await expect(summary).toBeFocused();
    // The summary is visible text; its announcements go through the
    // screen's own polite region, at most every 5 seconds (22-30 Q3).
    await expect(summary).not.toHaveAttribute('aria-live', /.+/);
    await expect(page.locator('[data-ln-upload-live][aria-live="polite"]')).toHaveCount(1);
    await expect(primary(page)).toBeDisabled();
    await expect(primary(page)).toHaveText('Uploading…');
    await expect(page.getByRole('button', { name: 'Stop remaining uploads' })).toBeVisible();
    await expect(page.locator('.localnodes-import-chosen__row').nth(1)).toContainText('Waiting');
    await expect.poll(() => held).toBe(true);
    await checkA11y(page, 'S2', 'in-progress', SCOPE);
  });

  test('finished: one file uploaded, the run report', async ({ page }) => {
    await openForm(page);
    await fileInput(page).setInputFiles([fixture('pdf-05-beekeeping-log.pdf')]);
    await tickRights(page);
    await expect(page.getByLabel(RIGHTS)).toBeChecked();
    await primary(page).click();
    await page.waitForURL(/\/import\/runs\/\d+/, { timeout: 90_000 });
    await expect(page.getByRole('heading', { level: 1, name: 'Import finished' })).toBeVisible();
    await expect(page.locator('table.localnodes-import-report')).toBeVisible();
    await checkA11y(page, 'S2', 'finished', SCOPE);
  });

  test('stopped before anything uploaded: the report offers no "View the documents (in Topics)"', async ({ page }) => {
    test.setTimeout(120_000);
    await page.route(UPLOAD_ROUTE, async () => {});
    await openForm(page);
    await fileInput(page).setInputFiles([fixture('pdf-07-meadow-survey.pdf')]);
    await tickRights(page);
    await primary(page).click();
    await expect(page.locator('.localnodes-import-chosen__summary')).toHaveText('Uploading 1 of 1');
    await page.getByRole('button', { name: 'Stop remaining uploads' }).click();
    await page.waitForURL(/\/import\/runs\/\d+/, { timeout: 90_000 });
    await expect(page.locator('.localnodes-import-report__summary')).toHaveText('0 of 1 imported.');
    await expect(page.getByRole('link', { name: 'View the documents (in Topics)' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Import more documents', exact: true })).toBeVisible();
  });

  test('a scanned PDF\'s page says it can\'t be searched (22-30 Codex, S6)', async ({ page }) => {
    test.setTimeout(120_000);
    // A fresh, empty group, so a rerun doesn't find the file already there.
    const seed = execFileSync('ddev', ['drush', 'php:script', 'tests/a11y/seed-walkthrough.php'], { cwd: join(dirname(fileURLToPath(import.meta.url)), '..', '..'), encoding: 'utf8' });
    const gid = seed.match(/^LN_WALK_GROUP=(\d+)\s*$/m)?.[1];
    expect(gid).toMatch(/^\d+$/);
    await page.goto(`/group/${gid}/import/files`);
    await expect(page.locator('.localnodes-import-dropzone')).toBeVisible();
    await fileInput(page).setInputFiles([fixture('scanned-notice.pdf')]);
    await tickRights(page);
    await primary(page).click();
    await page.waitForURL(/\/import\/runs\/\d+/, { timeout: 90_000 });
    const row = page.locator('tr.localnodes-import-report__row').first();
    await expect(row).toContainText('Not searchable');
    // Permanent, so not the clock "In progress" uses (22-30 Codex).
    expect(await row.locator('.localnodes-import-badge path').getAttribute('d')).not.toBe('M12 3a9 9 0 1 0 0 18a9 9 0 1 0 0-18M12 7v5l3 2');
    // Nothing is waiting for search (the scanned page never will be), so
    // there's no "still being prepared" line counting it.
    await expect(page.locator('.localnodes-import-report__search')).toHaveCount(0);
    await expect(page.getByText('readable documents are prepared')).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'View the documents (in Topics)' })).toBeVisible();
    await row.getByRole('link').first().click();
    const box = page.locator('.localnodes-import-source');
    await expect(box).toContainText("This may be an image-only scan. Members can open it, but we couldn't read enough text for search and the AI assistant. Add a copy whose text you can select and copy.");
  });
});
