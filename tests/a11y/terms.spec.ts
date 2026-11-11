// The site terms at /terms and the consent box on the register form (23-26,
// ONB-05, D-24, D-25): axe over each state, and what each state promises
// (23-UI-SPEC section 9 and its accessibility row).
//
// Runs as an anonymous visitor: the terms are read, and the box ticked,
// before anyone has an account. axe results go to the Phase 23 folder
// (PHASE23_RESULTS_DIR).
//
// axe is scoped to the page's own regions, as 22's and 23-13's specs are:
// socialblue's main navigation has a pre-existing serious violation (an <li>
// of ul[role=navigation] outside a list, rule "listitem") that every page of
// the site shares. On /terms the scope is the terms text and the footer line
// (which gains the "Site terms" link); on the register form it is the form,
// plus the error summary in the error state.
import { test, expect, type Page } from '@playwright/test';
import { checkA11y, PHASE23_RESULTS_DIR } from './helpers/axe';

const SECTIONS = [
  'Who runs this site',
  'What you can share',
  'Taking content down',
  'The AI assistant',
  'Your personal data',
  'Changes',
];

async function openRegister(page: Page) {
  await page.goto('/user/register');
  await expect(page.locator('#user-register-form')).toBeVisible();
}

test.describe('site terms and consent', () => {
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== 'anonymous', 'runs as an anonymous visitor');
  });

  test('terms page: h1, six sections, the version line, the footer link', async ({ page }) => {
    const response = await page.goto('/terms');
    expect(response?.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1, name: 'Site Terms' })).toBeVisible();
    expect((await page.title()).split(' | ')[0]).toBe('Site Terms');

    const terms = page.locator('.localnodes-site-terms');
    await expect(terms.locator('section > h2')).toHaveText(SECTIONS);
    await expect(terms.locator('section ul > li')).toHaveCount(4);
    await expect(terms.locator('.localnodes-site-terms__version')).toHaveText(/^Draft for review, [A-Z][a-z]+ \d{1,2}, \d{4}$/);
    await expect(terms.getByRole('link', { name: 'hello@localnodes.xyz' })).toHaveAttribute('href', 'mailto:hello@localnodes.xyz');

    const footer = page.locator('.ln-site-footer');
    await expect(footer.getByRole('link', { name: 'Site terms' })).toHaveAttribute('href', '/terms');

    // 72ch reading width, as the guide.
    const box = await terms.boundingBox();
    const fontSize = await terms.locator('p').first().evaluate((el) => parseFloat(getComputedStyle(el).fontSize));
    expect(box).not.toBeNull();
    expect(box!.width).toBeLessThanOrEqual(72 * fontSize * 0.7);

    await checkA11y(page, 'terms', 'default', { include: ['.localnodes-site-terms', '.ln-site-footer'], resultsDir: PHASE23_RESULTS_DIR });
  });

  test('register form, default: the box is unticked, required, above the button', async ({ page }) => {
    await openRegister(page);
    const box = page.getByRole('checkbox', { name: 'I agree to the site terms' });
    await expect(box).toBeVisible();
    await expect(box).not.toBeChecked();
    await expect(box).toHaveAttribute('required', 'required');
    await expect(page.locator('label[for="edit-terms-consent"] a')).toHaveAttribute('href', '/terms');
    await expect(page.locator('label[for="edit-terms-consent"] a')).toHaveText('site terms');

    // The box comes before the Create new account button in the page.
    const before = await page.evaluate(() => {
      const input = document.querySelector('#edit-terms-consent');
      const button = document.querySelector('#user-register-form [type="submit"][value="Create new account"]');
      return !!input && !!button && !!(input.compareDocumentPosition(button) & Node.DOCUMENT_POSITION_FOLLOWING);
    });
    expect(before).toBe(true);

    // Space toggles it.
    await box.focus();
    await page.keyboard.press('Space');
    await expect(box).toBeChecked();
    await page.keyboard.press('Space');
    await expect(box).not.toBeChecked();

    await checkA11y(page, 'register', 'default', { include: ['#user-register-form'], resultsDir: PHASE23_RESULTS_DIR });
  });

  test('register form, consent error: summary link, message under the box, focus on the summary', async ({ page }) => {
    await openRegister(page);
    await page.locator('#user-register-form').getByRole('button', { name: 'Create new account' }).click();
    await page.waitForLoadState('domcontentloaded');

    const summary = page.locator('.alert-danger').filter({ has: page.locator('a[href="#edit-terms-consent"]') });
    await expect(summary).toBeVisible();
    await expect(summary.locator('a[href="#edit-terms-consent"]')).toHaveText('I agree to the site terms');
    // No link inside the summary link.
    await expect(summary.locator('a a')).toHaveCount(0);

    const box = page.locator('#edit-terms-consent');
    await expect(box).not.toBeChecked();
    await expect(box).toHaveAttribute('aria-invalid', 'true');
    const describedBy = (await box.getAttribute('aria-describedby')) ?? '';
    expect(describedBy.split(' ')).toContain('edit-terms-consent--error-message');
    await expect(page.locator('#edit-terms-consent--error-message')).toHaveText('Agree to the site terms to create your account.');

    // 23-UI-SPEC section 9 accessibility row: focus is on the error summary.
    await expect(summary).toBeFocused();

    await checkA11y(page, 'register', 'consent-error', {
      include: ['#user-register-form', '.alert-danger.card-radius'],
      resultsDir: PHASE23_RESULTS_DIR,
    });
  });
});
