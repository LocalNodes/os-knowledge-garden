// S6 Source box (22-20): axe over an imported web page and an imported file,
// plus what the box promises (D-14, D-25; UI-SPEC Part 8 S6).
//
// Runs as the member (a plain member of "A11y Garden"). seed-source-box.php
// builds both Topics directly, with their source fields and import rows, so
// nothing is fetched. Axe is scoped to the Source box and the imported text:
// socialblue's nav and the DeepChat widget have pre-existing violations
// (22-11-SUMMARY.md) that aren't S6's.
import { execFileSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';
import { checkA11y } from './helpers/axe';

const REPO_ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const SCOPE = { include: ['.localnodes-import-source', '.localnodes-import-imported .body-text'] };
const CHANGE_NOTE = 'Formatting was converted for this site. The original page may look different.';

let webNid = '';
let fileNid = '';

test.beforeAll(() => {
  const output = execFileSync('ddev', ['drush', 'php:script', 'tests/a11y/seed-source-box.php'], {
    cwd: REPO_ROOT,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    timeout: 120_000,
  });
  webNid = output.match(/^LN_SOURCE_WEB=(\d+)\s*$/m)?.[1] ?? '';
  fileNid = output.match(/^LN_SOURCE_FILE=(\d+)\s*$/m)?.[1] ?? '';
  expect(webNid, `seed-source-box.php printed LN_SOURCE_WEB. Output:\n${output}`).toMatch(/^\d+$/);
  expect(fileNid, 'seed-source-box.php printed LN_SOURCE_FILE').toMatch(/^\d+$/);
});

test.describe('S6 Source box', () => {
  test('web: attribution, licence deed, change note, quote kept', async ({ page }) => {
    await page.goto(`/node/${webNid}`);
    const box = page.locator('.localnodes-import-source');
    await expect(box.getByRole('heading', { level: 2, name: 'Source' })).toBeVisible();
    // What a sighted reader sees: the visually hidden "(opens another site)"
    // notes are for screen readers.
    const visibleLine = await box.locator('.localnodes-import-source__attribution').evaluate((element) => {
      const copy = element.cloneNode(true) as HTMLElement;
      copy.querySelectorAll('.visually-hidden').forEach((hidden) => hidden.remove());
      return (copy.textContent ?? '').replace(/\s+/g, ' ').trim();
    });
    expect(visibleLine).toBe('Source: P2P Foundation Wiki, https://wiki.p2pfoundation.net/Bioregionalism, CC BY-SA 4.0');
    await expect(box.locator('.visually-hidden')).toHaveText(['(opens another site)', '(opens another site)']);
    await expect(box.getByRole('link', { name: 'https://wiki.p2pfoundation.net/Bioregionalism (opens another site)' })).toHaveAttribute(
      'href',
      'https://wiki.p2pfoundation.net/Bioregionalism',
    );
    const licence = box.getByRole('link', { name: 'Creative Commons Attribution-ShareAlike 4.0 license', exact: true });
    await expect(licence).toHaveAttribute('href', 'https://creativecommons.org/licenses/by-sa/4.0/');
    await expect(licence).toHaveAccessibleDescription('(opens another site)');
    await expect(box).toContainText(CHANGE_NOTE);
    await expect(box.locator('.localnodes-import-source__meta')).toContainText(/^Imported \d{1,2} [A-Z][a-z]+ \d{4}$/);

    // The quoted writer stays a quote, after the page's own words and before
    // the Source box.
    const quote = page.locator('.localnodes-import-imported .body-text blockquote');
    await expect(quote).toBeVisible();
    await expect(quote).toHaveCSS('border-left-width', '4px');
    await expect(quote).toHaveCSS('border-left-color', 'rgb(138, 138, 138)');
    await expect(box).toHaveCSS('border-left-color', 'rgb(15, 118, 110)');

    await checkA11y(page, 'S6', 'web', SCOPE);
  });

  test('file: uploaded file, credit, licence as text, no change note', async ({ page }) => {
    await page.goto(`/node/${fileNid}`);
    const box = page.locator('.localnodes-import-source');
    await expect(box.getByRole('heading', { level: 2, name: 'Source' })).toBeVisible();
    await expect(box).toContainText('Source: uploaded file garden-committee-minutes.txt');
    await expect(box.getByRole('link', { name: 'garden-committee-minutes.txt' })).toBeVisible();
    await expect(box).toContainText('Credit: Garden Committee, 2024');
    const licence = box.locator('.localnodes-import-source__licence');
    await expect(licence).toHaveText('Licence: CC BY 4.0');
    await expect(licence.locator('a')).toHaveCount(0);
    await expect(box).not.toContainText(CHANGE_NOTE);

    await checkA11y(page, 'S6', 'file', { include: ['.localnodes-import-source'] });
  });
});
