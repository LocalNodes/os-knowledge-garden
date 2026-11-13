// The fixed top bar on a narrow phone. At 320px it stays one 50px row:
// the community's name gives way, Sign in (signed out) or the account icons
// (signed in) do not, and nothing scrolls sideways. Every page's main content
// starts below the bar, at every width (ln-overrides.css keeps the main
// column's 50px top padding that Open Social's navbar script removes under
// 976px).
//
// Runs in the anonymous project; the member block switches its own session.
// On a site with the sign-in off, set LN_WEB3_OFF=1 to skip the Sign in check.
import { test, expect, type Page } from '@playwright/test';
import { authFile } from './helpers/governance-states';

const BAR_MAX = 50;
const TARGET_MIN = 44;
const PAGES = ['/', '/user/login'];

async function measure(page: Page) {
  return page.evaluate(() => {
    const box = (sel: string) => document.querySelector(sel)?.getBoundingClientRect() ?? null;
    // The first thing the main column shows: its first visible box.
    const main = document.querySelector('.main-container');
    let first: DOMRect | null = null;
    if (main) {
      for (const el of Array.from(main.querySelectorAll('*'))) {
        const r = el.getBoundingClientRect();
        if (r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden') { first = r; break; }
      }
    }
    return {
      bar: box('.navbar-fixed-top'),
      first,
      scrollWidth: document.documentElement.scrollWidth,
    };
  });
}

async function open(page: Page, path: string, width: number) {
  await page.setViewportSize({ width, height: 800 });
  await page.goto(path);
  await expect(page.locator('.navbar-fixed-top')).toBeVisible();
}

test.describe('top bar, signed out', () => {
  test.beforeEach(async ({}, testInfo) => {
    test.skip(testInfo.project.name !== 'anonymous', 'runs signed out');
  });

  for (const path of PAGES) {
    test(`320px: one row, Sign in a full target, no sideways scroll (${path})`, async ({ page }) => {
      await open(page, path, 320);
      const m = await measure(page);
      expect(m.bar!.height).toBeLessThanOrEqual(BAR_MAX);
      expect(m.scrollWidth).toBeLessThanOrEqual(320);
      if (process.env.LN_WEB3_OFF !== '1') {
        const signIn = page.locator('.ln-signin-topbar .siwe-login-waap__button');
        await expect(signIn).toBeVisible();
        const b = (await signIn.boundingBox())!;
        expect(b.height).toBeGreaterThanOrEqual(TARGET_MIN);
        // Inside the bar, and on the screen.
        expect(b.y).toBeGreaterThanOrEqual(m.bar!.top);
        expect(b.y + b.height).toBeLessThanOrEqual(m.bar!.bottom + 0.5);
        expect(b.x + b.width).toBeLessThanOrEqual(320);
      }
    });

    for (const width of [320, 390, 1280]) {
      test(`main content starts below the bar, ${width}px (${path})`, async ({ page }) => {
        await open(page, path, width);
        const m = await measure(page);
        expect(m.first, 'the main column shows something').not.toBeNull();
        expect(m.first!.top).toBeGreaterThanOrEqual(m.bar!.bottom - 0.5);
        expect(m.scrollWidth).toBeLessThanOrEqual(width);
      });
    }
  }
});

test.describe('top bar, signed in', () => {
  test.use({ storageState: authFile('member') });
  test.beforeEach(async ({}, testInfo) => {
    test.skip(testInfo.project.name !== 'anonymous', 'switches its own session');
  });

  test('320px: one row with the account icons, no sideways scroll', async ({ page }) => {
    await open(page, '/', 320);
    const m = await measure(page);
    expect(m.bar!.height).toBeLessThanOrEqual(BAR_MAX);
    expect(m.scrollWidth).toBeLessThanOrEqual(320);
    const user = (await page.locator('.navbar-user').boundingBox())!;
    expect(user.y + user.height).toBeLessThanOrEqual(m.bar!.bottom + 0.5);
    expect(user.x + user.width).toBeLessThanOrEqual(320);
    expect(m.first!.top).toBeGreaterThanOrEqual(m.bar!.bottom - 0.5);
  });
});
