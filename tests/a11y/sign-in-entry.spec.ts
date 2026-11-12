// Signed out, with the sign-in on: the top bar keeps its height, and the
// welcome block, the log-in page and the sign-up page lead with "Sign in",
// whose click goes to the top-bar sign-in (localnodes_web3's SignIn).
//
// Runs in the anonymous project. It skips only when LN_WEB3_OFF=1 says the
// site has the sign-in off; otherwise a missing sign-in fails it. It never
// starts a real sign-in: the click checks swap the top-bar button for a
// stand-in with the same class and count the clicks that reach it.
//
// The connector's script loads from elsewhere and can fail to arrive. The
// "script blocked" tests abort it and check that every control still leads
// to the password form, and the "button gone" tests remove the connector's
// button after the controls became ready.
import { test, expect, type Page } from '@playwright/test';

const WIDTHS = [1280, 390];
const WEB3_OFF = process.env.LN_WEB3_OFF === '1';
const CONNECTOR_SCRIPT = '**/siwe_login_waap/js/siwe-login-waap.js*';

/** The top bar's height, and its height with the sign-in block hidden. */
async function barHeights(page: Page): Promise<[number, number]> {
  const bar = () => page.evaluate(() => document.querySelector('.navbar')!.getBoundingClientRect().height);
  const shown = await bar();
  const hide = await page.addStyleTag({ content: '#block-siwe-login{display:none!important}' });
  const hidden = await bar();
  // Removed rather than overridden, so the block keeps its own display.
  await hide.evaluate((e) => e.remove());
  return [shown, hidden];
}

/** Swaps the top-bar button for a stand-in; returns a click counter. */
async function standIn(page: Page): Promise<() => Promise<number>> {
  await expect(page.locator('[data-siwe-login-waap] .siwe-login-waap__button')).toBeVisible();
  await page.evaluate(() => {
    const real = document.querySelector('[data-siwe-login-waap] .siwe-login-waap__button')!;
    const fake = real.cloneNode(true) as HTMLButtonElement;
    (window as any).__lnClicks = 0;
    fake.addEventListener('click', () => { (window as any).__lnClicks++; });
    real.replaceWith(fake);
  });
  return () => page.evaluate(() => (window as any).__lnClicks as number);
}

test.describe('sign-in entry points', () => {
  test.beforeEach(async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'anonymous', 'runs signed out');
    test.skip(WEB3_OFF, 'LN_WEB3_OFF=1: the sign-in is off on this site');
    await page.goto('/');
    await expect(page.locator('[data-siwe-login-waap]'), 'the top-bar sign-in is on the page').toHaveCount(1);
  });

  for (const width of WIDTHS) {
    test(`top bar is no taller with the sign-in, ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      for (const path of ['/', '/user/login', '/user/register']) {
        await page.goto(path);
        await expect(page.locator('[data-siwe-login-waap] .siwe-login-waap__button')).toBeVisible();
        const [shown, hidden] = await barHeights(page);
        expect(shown, path).toBe(hidden);
        await expect(page.locator('.navbar .ln-safety-help-link')).toHaveCount(0);
        // The button sits on the menu's line, centred in the bar.
        const button = (await page.locator('[data-siwe-login-waap] .siwe-login-waap__button').boundingBox())!;
        expect(Math.abs(button.y + button.height / 2 - hidden / 2), path).toBeLessThanOrEqual(2);
      }
    });

    test(`the "Opening sign-in" line shows under the top bar, ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto('/');
      // A stand-in for the line the connector adds to the top of <main>.
      await page.evaluate(() => {
        const line = document.createElement('div');
        line.className = 'siwe-login-waap__waiting';
        line.innerHTML = '<p role="status">Opening sign-in…</p><button type="button">Cancel sign-in</button>';
        document.querySelector('main, [role="main"]')!.prepend(line);
      });
      const cancel = page.getByRole('button', { name: 'Cancel sign-in' });
      await expect(cancel).toBeVisible();
      const place = await page.evaluate(() => {
        const line = document.querySelector('.siwe-login-waap__waiting')!.getBoundingClientRect();
        const bar = document.querySelector('.navbar')!.getBoundingClientRect();
        const b = document.querySelector('.siwe-login-waap__waiting button')!.getBoundingClientRect();
        const hit = document.elementFromPoint(b.x + b.width / 2, b.y + b.height / 2);
        return { top: line.top, barBottom: bar.bottom, hit: hit?.textContent };
      });
      expect(Math.abs(place.top - place.barBottom)).toBeLessThanOrEqual(1);
      expect(place.hit, 'nothing covers Cancel').toBe('Cancel sign-in');
    });

    test(`welcome block: Sign in and Use a password, ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto('/');
      const cta = page.locator('.hero-cta-wrapper');
      // Once the connector's button is there, "Sign in" acts as a button.
      await expect(cta.getByRole('button', { name: 'Sign in' })).toHaveAttribute('data-ln-signin-ready', '');
      await expect(cta.getByRole('link')).toHaveText(['Use a password']);
      await expect(cta.getByRole('link', { name: 'Use a password' })).toHaveAttribute('href', '/user/login');
      await expect(page.locator('.hero-cta-wrapper a[href="/user/register"]')).toHaveCount(0);
      await expect(page.locator('h3', { hasText: /^Welcome to / })).toBeVisible();

      const clicks = await standIn(page);
      await cta.getByRole('button', { name: 'Sign in' }).click();
      expect(await clicks()).toBe(1);
      // Enter and Space open it too.
      await cta.getByRole('button', { name: 'Sign in' }).focus();
      await page.keyboard.press('Enter');
      await page.keyboard.press('Space');
      expect(await clicks()).toBe(3);
      expect(new URL(page.url()).pathname).toBe('/');
      // The password link is a plain link.
      await cta.getByRole('link', { name: 'Use a password' }).click();
      await expect(page).toHaveURL(/\/user\/login$/);
      expect(await page.evaluate(() => (window as any).__lnClicks ?? 0)).toBe(0);
    });

    for (const path of ['/user/login', '/user/register']) {
      test(`${path} leads with Sign in above the password form, ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(path);
        const wrap = page.locator('.ln-signin');
        await expect(wrap).toBeVisible();
        await expect(wrap.getByRole('button', { name: 'Sign in' })).toBeVisible();
        await expect(wrap.getByText('Or use a password')).toBeVisible();
        await expect(wrap.getByRole('link', { name: 'Hacked or phished? Get help' })).toBeVisible();
        // The form stays, below the button (sign-up asks for an email and
        // sends a link; log-in asks for a password).
        const pass = page.locator(path === '/user/login' ? 'input[type="password"]' : 'input[type="email"]').first();
        await expect(pass).toBeVisible();
        const w = (await wrap.boundingBox())!;
        expect((await pass.boundingBox())!.y).toBeGreaterThan(w.y + w.height);

        const clicks = await standIn(page);
        await wrap.getByRole('button', { name: 'Sign in' }).click();
        expect(await clicks()).toBe(1);
        expect(new URL(page.url()).pathname).toBe(path);
      });
    }

    test(`script blocked: every control leads to the password form, ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.route(CONNECTOR_SCRIPT, (route) => route.abort());
      await page.goto('/');
      await expect(page.locator('[data-siwe-login-waap] .siwe-login-waap__button')).toHaveCount(0);
      // The welcome block's "Sign in" stays a plain link to the log-in page.
      const link = page.locator('.hero-cta-wrapper').getByRole('link', { name: 'Sign in' });
      await expect(link).toHaveAttribute('href', '/user/login');
      await expect(link).not.toHaveAttribute('data-ln-signin-ready', '');
      await link.click();
      await expect(page).toHaveURL(/\/user\/login$/);
      // The two pages show no "Sign in" button, only their forms.
      for (const [path, field] of [['/user/login', 'input[type="password"]'], ['/user/register', 'input[type="email"]']]) {
        await page.goto(path);
        await expect(page.locator(field).first()).toBeVisible();
        await expect(page.locator('.ln-signin')).toBeHidden();
        await expect(page.getByRole('button', { name: 'Sign in' })).toHaveCount(0);
      }
    });

    test(`button gone after ready: the controls lead to the password form, ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      const gone = async () => {
        await expect(page.locator('[data-ln-signin]').first()).toHaveAttribute('data-ln-signin-ready', '');
        // Remove the button and click in one go, before the observer that
        // would mark the controls plain again has run.
        await page.evaluate(() => {
          document.querySelector('[data-siwe-login-waap] .siwe-login-waap__button')!.remove();
          (document.querySelector('[data-ln-signin]') as HTMLElement).click();
        });
      };
      await page.goto('/');
      await gone();
      await expect(page).toHaveURL(/\/user\/login$/);
      // On the log-in page the wrapper goes and the form gets the focus.
      await gone();
      await expect(page.locator('.ln-signin')).toBeHidden();
      await expect(page.locator('form:has(.ln-signin) input:not([type="hidden"])').first()).toBeFocused();
      // On the sign-up page it goes to the log-in page.
      await page.goto('/user/register');
      await gone();
      await expect(page).toHaveURL(/\/user\/login$/);
    });

    test(`top-bar block removed after ready: the controls lead to the password form, ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      const removeMount = async () => {
        await expect(page.locator('[data-ln-signin]').first()).toHaveAttribute('data-ln-signin-ready', '');
        // The mount goes with its button inside; the click comes in the same
        // tick, before any observer has run.
        await page.evaluate(() => {
          document.querySelector('[data-siwe-login-waap]')!.remove();
          (document.querySelector('[data-ln-signin]') as HTMLElement).click();
        });
      };
      await page.goto('/');
      await removeMount();
      await expect(page).toHaveURL(/\/user\/login$/);
      await removeMount();
      await expect(page.locator('.ln-signin')).toBeHidden();
      await expect(page.locator('form:has(.ln-signin) input:not([type="hidden"])').first()).toBeFocused();
      // Removed with no click: the welcome link goes back to a plain link.
      await page.goto('/');
      await expect(page.locator('[data-ln-signin]')).toHaveAttribute('data-ln-signin-ready', '');
      await page.evaluate(() => document.querySelector('[data-siwe-login-waap]')!.remove());
      const link = page.locator('.hero-cta-wrapper').getByRole('link', { name: 'Sign in' });
      await expect(link).not.toHaveAttribute('data-ln-signin-ready', '');
      await expect(link).toHaveAttribute('href', '/user/login');
    });

    test(`focus on Sign in moves to the form when the sign-in goes, ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto('/user/login');
      const button = page.locator('.ln-signin').getByRole('button', { name: 'Sign in' });
      await expect(button).toHaveAttribute('data-ln-signin-ready', '');
      await button.focus();
      await page.evaluate(() => document.querySelector('[data-siwe-login-waap] .siwe-login-waap__button')!.remove());
      await expect(page.locator('.ln-signin')).toBeHidden();
      await expect(page.locator('form:has(.ln-signin) input:not([type="hidden"])').first()).toBeFocused();
    });
  }
});
