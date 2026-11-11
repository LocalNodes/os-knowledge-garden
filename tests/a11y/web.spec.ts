// S3 "Import web pages" and the "Refresh from source" confirmation (22-24):
// axe over S3 empty, filled and error, and over the refresh confirmation,
// plus what the screens promise (D-05, D-25, D-26; UI-SPEC Part 8 S3,
// Part 3 "Confirmation").
//
// Runs as the steward (group manager of "A11y Garden").
//
// Live server-side fetches (orchestrator default, Tom to confirm): the
// filled state presses "Check licences" with two en.wikipedia.org addresses
// and https://example.com/, and the DDEV site detects their licences through
// the real SafeFetcher. Playwright's route mocks only see browser requests,
// so they can't stand in for these. The spec checks exactly one licence line
// or no-licence alert per host without asserting which, so it stays stable
// offline (a failed fetch shows the alert). The lines' content is covered
// deterministically by ImportWebPagesFormTest. The refresh confirmation is
// opened but never submitted with the box ticked, so the P2P wiki (which
// challenges server clients, D-23) is never fetched here.
//
// Axe is scoped to the screen's content block and its h1, as for S2:
// socialblue's nav and the DeepChat widget have pre-existing violations
// (22-11-SUMMARY.md) that aren't these screens'.
import { execFileSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect, type Page } from '@playwright/test';
import { checkA11y } from './helpers/axe';

const REPO_ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const SCOPE = { include: ['#block-socialblue-content', 'h1'] };
const RIGHTS = "I have the right to share these here, because they're openly licensed or I have permission.";
const RIGHTS_ERROR = 'Confirm you have the right to share these here before importing.';
const ENTRIES = '.localnodes-import-web__licence, .localnodes-import-alert';

let refreshRun = '';
let refreshNid = '';

function groupId(): string {
  const gid = process.env.LN_GROUP;
  expect(gid, 'global setup exports LN_GROUP').toMatch(/^\d+$/);
  return gid!;
}

function drushScript(script: string): string {
  return execFileSync('ddev', ['drush', 'php:script', script], {
    cwd: REPO_ROOT,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    timeout: 120_000,
  });
}

async function openForm(page: Page) {
  await page.goto(`/group/${groupId()}/import/web`);
  await expect(page.getByRole('heading', { level: 1, name: 'Import web pages' })).toBeVisible();
}

const addresses = (page: Page) => page.getByLabel('Page addresses');
const primary = (page: Page) => page.locator('form.localnodes-import-web .form-actions [type="submit"]');
const checkLicences = (page: Page) => page.getByRole('button', { name: 'Check licences' });

/**
 * Fills the textarea and waits for the change-triggered refresh to settle.
 */
async function fillAddresses(page: Page, lines: string[], hosts: number) {
  await addresses(page).fill(lines.join('\n'));
  await checkLicences(page).click();
  await expect(page.locator(`.localnodes-import-web__detected-lines`).locator(ENTRIES)).toHaveCount(hosts, { timeout: 90_000 });
  // No request still in flight (core's throbber is gone).
  await expect(page.locator('.ajax-progress')).toHaveCount(0, { timeout: 30_000 });
}

test.beforeAll(() => {
  drushScript('tests/a11y/seed-source-box.php');
  const output = drushScript('tests/a11y/seed-refresh.php');
  refreshRun = output.match(/^LN_REFRESH_RUN=(\d+)\s*$/m)?.[1] ?? '';
  refreshNid = output.match(/^LN_REFRESH_NID=(\d+)\s*$/m)?.[1] ?? '';
  expect(refreshRun, `seed-refresh.php printed LN_REFRESH_RUN. Output:\n${output}`).toMatch(/^\d+$/);
  expect(refreshNid, 'seed-refresh.php printed LN_REFRESH_NID').toMatch(/^\d+$/);
});

test.describe('S3 Import web pages', () => {
  test('empty: no licence picker, Reference by default, rights unticked', async ({ page }) => {
    await openForm(page);
    await expect(addresses(page)).toBeVisible();
    // The form names where the pages go (22-30, Codex CX-04/07).
    await expect(page.getByText('Importing into A11y Garden', { exact: true })).toBeVisible();
    await expect(page.getByText('Paste one address per line. Each must start with http:// or https://. Up to 50 at a time.')).toBeVisible();
    // 22-30 (Codex review): the more private default, as on S2.
    await expect(page.locator('input[name="visibility"]:checked')).toHaveValue('group');
    await expect(page.locator('select[name="topic_type"] option:checked')).toHaveText('Reference');
    await expect(page.getByLabel(RIGHTS)).not.toBeChecked();
    await expect(checkLicences(page)).toBeVisible();
    await expect(primary(page)).toHaveText('Import pages');
    await expect(page.getByRole('link', { name: 'Back to import options' })).toBeVisible();

    // D-25: no licence picker. The only select is the page type; the only
    // radios are the visibility options.
    const form = page.locator('form.localnodes-import-web');
    await expect(form.locator('select')).toHaveCount(1);
    expect(await form.locator('input[type="radio"]').evaluateAll((radios) => [...new Set(radios.map((r) => (r as HTMLInputElement).name))])).toEqual(['visibility']);
    await expect(form.locator('input:not([type="submit"]), select, textarea').and(form.locator('[name*="licen" i]'))).toHaveCount(0);

    await checkA11y(page, 'S3', 'empty', SCOPE);
  });

  test('filled: one licence line or alert per site, and the Source box preview', async ({ page }) => {
    test.setTimeout(180_000);
    await openForm(page);
    await fillAddresses(page, [
      'https://en.wikipedia.org/wiki/Bioregionalism',
      'https://en.wikipedia.org/wiki/Watershed_management',
      'https://example.com/',
    ], 2);
    await expect(page.locator('.localnodes-import-web__preview .localnodes-import-source')).toBeVisible();
    await expect(page.locator('.localnodes-import-web__preview .localnodes-import-source__attribution')).toContainText('https://en.wikipedia.org/wiki/Bioregionalism');
    // A preview, labelled as one, with nothing in the past tense (22-30).
    await expect(page.locator('.localnodes-import-web__preview').getByText('Preview of the first page', { exact: true })).toBeVisible();
    await expect(page.locator('.localnodes-import-web__preview')).not.toContainText('Imported');
    await expect(page.locator('.localnodes-import-web__preview')).not.toContainText('Formatting was converted');
    await expect(primary(page)).toHaveText('Import 3 pages');
    await expect(addresses(page)).toHaveValue(/example\.com/);
    await expect(page.locator('form.localnodes-import-web select')).toHaveCount(1);
    await checkA11y(page, 'S3', 'filled', SCOPE);
  });

  test('filled without the button: the licence lines update when the addresses change (22-30 WK-02)', async ({ page }) => {
    test.setTimeout(180_000);
    await openForm(page);
    // UI-SPEC S3: with JavaScript the lines update as addresses are entered;
    // "Check licences" is the no-JavaScript route. Leaving the box is the
    // change; nothing presses the button.
    await addresses(page).fill(['https://en.wikipedia.org/wiki/Bioregionalism', 'https://example.com/'].join('\n'));
    await addresses(page).blur();
    await expect(page.locator('.localnodes-import-web__detected-lines').locator(ENTRIES)).toHaveCount(2, { timeout: 90_000 });
    await expect(page.locator('.localnodes-import-web__preview .localnodes-import-source__attribution')).toContainText('https://en.wikipedia.org/wiki/Bioregionalism');
  });

  test('a site that blocks automatic imports says so before importing (22-30 Q5)', async ({ page }) => {
    test.setTimeout(180_000);
    // Live: the P2P Foundation Wiki answers server fetches with a bot check
    // (D-23). S3 now shows S4's sentence for it, not the no-licence warning.
    await openForm(page);
    await addresses(page).fill('https://wiki.p2pfoundation.net/Bioregionalism');
    await addresses(page).blur();
    const lines = page.locator('.localnodes-import-web__detected-lines');
    await expect(lines.locator(ENTRIES)).toHaveCount(1, { timeout: 90_000 });
    // Worded as what will happen, not as a failed import (22-30 N-01).
    await expect(lines).toContainText('This site blocks automatic imports. Save these pages as PDFs and use Upload files.');
    await expect(lines).not.toContainText("This site doesn't say its pages can be reused");
    await expect(lines.getByRole('link', { name: 'Upload files' })).toHaveAttribute('href', new RegExp(`/group/${groupId()}/import/files$`));
    // Every address is on a blocked site: "Upload files" is the primary next
    // step, and the import button steps down (N-01).
    const actions = page.locator('form.localnodes-import-web .form-actions');
    await expect(actions.getByRole('link', { name: 'Upload files' })).toHaveClass(/button--primary/);
    await expect(primary(page)).not.toHaveClass(/button--primary/);
  });

  test('error: private address, repeated line and rights unticked', async ({ page }) => {
    test.setTimeout(180_000);
    await openForm(page);
    await fillAddresses(page, ['http://10.0.0.5/x', 'https://example.com/', 'https://example.com/'], 1);
    await primary(page).click();

    const summary = page.locator('[data-ln-error-summary]');
    await expect(summary).toBeVisible({ timeout: 60_000 });
    await expect(summary).toBeFocused();
    await expect(summary.getByRole('heading', { name: 'Fix these to continue' })).toBeVisible();
    await expect(summary.getByRole('link', { name: "Line 1 points to a private network address, so it can't be imported." })).toHaveAttribute('href', '#edit-addresses');
    await expect(summary.getByRole('link', { name: 'Line 3 is the same as line 2.' })).toHaveAttribute('href', '#edit-addresses');
    await expect(summary.getByRole('link', { name: RIGHTS_ERROR })).toHaveAttribute('href', '#edit-rights-confirmed');
    await expect(addresses(page)).toHaveAttribute('aria-invalid', 'true');
    await expect(page.getByLabel(RIGHTS)).not.toBeChecked();
    await checkA11y(page, 'S3', 'error', SCOPE);
  });
});

test.describe('S4 Refresh from source', () => {
  test('confirmation: rights unticked, focus on Keep current version, back to the report', async ({ page }) => {
    const report = `/group/${groupId()}/import/runs/${refreshRun}`;
    await page.goto(report);
    await expect(page.getByText('This page is already in the group.')).toBeVisible();
    await page.getByRole('link', { name: /^Replace with the website's current text/ }).click();

    await expect(page).toHaveURL(new RegExp(`/group/${groupId()}/import/refresh/${refreshNid}\\?run=${refreshRun}$`));
    await expect(page.getByRole('heading', { level: 1, name: 'Refresh Bioregionalism (Source box fixture) from its source?' })).toBeVisible();
    await expect(page.getByText('This replaces the page\'s text with the current version from P2P Foundation Wiki.')).toBeVisible();
    await expect(page.getByLabel(RIGHTS)).not.toBeChecked();
    const keep = page.getByRole('link', { name: 'Keep current version' });
    await expect(keep).toBeFocused();
    await expect(page.getByRole('button', { name: 'Replace page text' })).toBeVisible();
    await checkA11y(page, 'S4', 'refresh', SCOPE);

    // "Replace page text" unticked: the error, and nothing is fetched or changed.
    await page.getByRole('button', { name: 'Replace page text' }).click();
    const summary = page.locator('[data-ln-error-summary]');
    await expect(summary.getByRole('link', { name: RIGHTS_ERROR })).toHaveAttribute('href', '#edit-rights-confirmed');
    await expect(summary).toBeFocused();

    // Escape cancels: back to the report.
    await page.keyboard.press('Escape');
    await expect(page).toHaveURL(new RegExp(`${report}$`));

    // So does the cancel link.
    await page.goto(`/group/${groupId()}/import/refresh/${refreshNid}?run=${refreshRun}`);
    await page.getByRole('link', { name: 'Keep current version' }).click();
    await expect(page).toHaveURL(new RegExp(`${report}$`));
  });
});
