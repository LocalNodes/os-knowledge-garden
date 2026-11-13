// The organizer guide at /guide (23-13, ONB-03, D-24): axe over the page, and
// what the page promises.
//
// Runs as the steward, who is signed in and may see content. The guide is for
// signed-in people, so an anonymous visit is a redirect to the sign-in page.
// axe results go to the Phase 23 folder (PHASE23_RESULTS_DIR), which 23-26 and
// 23-20 reuse for their pages.
//
// axe is scoped to the guide's own region, as 22's specs are: socialblue's
// main navigation has a pre-existing serious violation (an <li> of
// ul[role=navigation] outside a list, rule "listitem") that is not the guide's
// and that every page of the site shares.
import { test, expect } from '@playwright/test';
import { checkA11y, PHASE23_RESULTS_DIR } from './helpers/axe';

test.describe('organizer guide', () => {
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== 'steward', 'runs as the steward');
  });

  test('default: h1, six numbered steps, then the assistant section and the help line', async ({ page }) => {
    await page.goto('/guide');
    await expect(page.getByRole('heading', { level: 1, name: 'Organizer Guide' })).toBeVisible();
    expect((await page.title()).split(' | ')[0]).toBe('Organizer Guide');

    const steps = page.locator('.localnodes-guide__steps > li');
    await expect(steps).toHaveCount(6);
    await expect(steps.locator('h2')).toHaveText([
      'Sign in',
      'Open your group',
      'Upload your first files',
      'Wait while your documents are prepared',
      'Ask the AI assistant a question',
      'Bring in your members',
    ]);
    // The fund and fees sections show only on a site with localnodes_web3 on.
    await expect(page.getByRole('heading', { level: 2, name: 'Using the assistant' })).toBeVisible();
    await expect(page.locator('.localnodes-guide__help')).toHaveText('Stuck? Get help is in the footer of every page.');
    await expect(page.locator('.localnodes-guide__help').getByRole('link', { name: 'Get help' })).toHaveAttribute('href', /\/help$/);

    await checkA11y(page, 'guide', 'default', { include: ['.localnodes-guide'], resultsDir: PHASE23_RESULTS_DIR });
  });

  test('reading width holds to 72 characters', async ({ page }) => {
    await page.goto('/guide');
    const box = await page.locator('.localnodes-guide').boundingBox();
    const fontSize = await page.locator('.localnodes-guide__intro').evaluate((el) => parseFloat(getComputedStyle(el).fontSize));
    expect(box).not.toBeNull();
    // 72ch is 72 times the width of "0" in the font, about 0.5 to 0.6 em.
    expect(box!.width).toBeLessThanOrEqual(72 * fontSize * 0.7);
  });

  test('anonymous visitors are sent to sign in', async ({ browser, baseURL }) => {
    const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true, storageState: { cookies: [], origins: [] } });
    const page = await context.newPage();
    await page.goto('/guide');
    expect(new URL(page.url()).pathname).toBe('/user/login');
    await context.close();
  });
});
