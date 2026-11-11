// The "Hacked or Phished?" help page and its footer link (22.1-15; Tom's
// ruling of 2026-11-09): axe over the page, as an anonymous visitor and as a
// member, and what the page promises (22.1-15-UI-SPEC-PROPOSAL.md, approved
// as written in the orchestrator's Q13 answer).
//
// Runs in the anonymous and member projects: someone locked out of their
// account reads it signed out, a member reads it from the footer. axe results
// go to the 22.1 phase folder.
//
// axe is scoped to the page's own regions, as terms.spec.ts is: socialblue's
// main navigation has a pre-existing serious violation (rule "listitem") that
// every page of the site shares. The scope is the help text and the footer
// line (which gains the "Hacked or phished?" link).
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';
import { checkA11y } from './helpers/axe';

const RESULTS_DIR = join(
  dirname(fileURLToPath(import.meta.url)),
  '..',
  '..',
  '.planning',
  'phases',
  '22.1-localnodes-brand-design-system',
  'a11y',
);

const PATH = '/help/hacked-or-phished';

test.describe('hacked or phished help page', () => {
  test.beforeEach(({}, testInfo) => {
    test.skip(!['anonymous', 'member'].includes(testInfo.project.name), 'runs signed out and as a member');
  });

  test('help page: h1, six steps, the site-down section, the footer link', async ({ page }, testInfo) => {
    const response = await page.goto(PATH);
    expect(response?.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1, name: 'Hacked or Phished?' })).toBeVisible();
    expect((await page.title()).split(' | ')[0]).toBe('Hacked or Phished?');

    const help = page.locator('.localnodes-safety-help');
    await expect(help.locator('section > h2')).toHaveText(['What to do now', 'If this site is down']);
    await expect(help.locator('ol > li')).toHaveCount(6);
    await expect(help.getByRole('link', { name: "SEAL 911's website" })).toHaveAttribute('href', 'https://securityalliance.org/our-work/seal-911');
    await expect(help.getByRole('link', { name: 'hello@localnodes.xyz' })).toHaveAttribute('href', 'mailto:hello@localnodes.xyz');
    await expect(help.locator('section#if-this-site-is-down')).toContainText('Safe app at app.safe.global');

    // The steps are numbered on screen, not only in the markup.
    const marker = await help.locator('ol').evaluate((el) => getComputedStyle(el).listStyleType);
    expect(marker).toBe('decimal');

    const footer = page.locator('.ln-site-footer');
    await expect(footer.getByRole('link', { name: 'Hacked or phished?' })).toHaveAttribute('href', PATH);

    // 72ch reading width, as the terms page.
    const box = await help.boundingBox();
    const fontSize = await help.locator('p').first().evaluate((el) => parseFloat(getComputedStyle(el).fontSize));
    expect(box).not.toBeNull();
    expect(box!.width).toBeLessThanOrEqual(72 * fontSize * 0.7);

    await checkA11y(page, 'safety-help', testInfo.project.name, { include: ['.localnodes-safety-help', '.ln-site-footer'], resultsDir: RESULTS_DIR });
  });
});
