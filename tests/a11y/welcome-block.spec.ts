// The signed-out welcome block. Without a picture (the platform leaves out
// Open Social's stock photo) the block is only as tall as its words and
// buttons; with a community's picture it keeps Open Social's height. On a
// phone the top bar does not cover the block's panel.
//
// Runs in the anonymous project, with web3 on or off. The picture case adds
// the class and background the block template writes when it has an image,
// since the test site's block has none.
import { test, expect, type Page } from '@playwright/test';

const WIDTHS = [1280, 390];
// The cover's own padding (socialbase hero.css), above and below the panel.
const COVER_PAD = 40;

async function measure(page: Page) {
  return page.evaluate(() => {
    const cover = document.querySelector('#hero .cover-with-canvas')!;
    const box = (e: Element | null) => e!.getBoundingClientRect();
    return {
      cover: box(cover),
      wrap: box(cover.querySelector('.cover-wrap')),
      panel: box(cover.querySelector('.hero-canvas')),
      bar: box(document.querySelector('.navbar-fixed-top')),
      hasImage: cover.classList.contains('cover-img'),
      // The hairline under the block (the app look's edge), not padding.
      borderBottom: parseFloat(getComputedStyle(cover).borderBottomWidth) || 0,
      scrollWidth: document.documentElement.scrollWidth,
    };
  });
}

test.describe('welcome block height', () => {
  test.beforeEach(async ({}, testInfo) => {
    test.skip(testInfo.project.name !== 'anonymous', 'runs signed out');
  });

  for (const width of WIDTHS) {
    test(`no picture: as tall as its words and buttons, ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto('/');
      await expect(page.locator('#hero .hero-canvas')).toBeVisible();
      const m = await measure(page);
      expect(m.hasImage, 'the stock photo is left out').toBe(false);
      // The wrap holds only the panel: no held-open height under it.
      expect(Math.abs(m.wrap.height - m.panel.height)).toBeLessThanOrEqual(1);
      // Comfortable padding under the panel, and no more.
      expect(Math.round(m.cover.bottom - m.borderBottom - m.panel.bottom)).toBe(COVER_PAD);
      // The panel starts below the fixed top bar, with room to spare.
      expect(m.panel.top - m.bar.bottom).toBeGreaterThanOrEqual(16);
      expect(m.scrollWidth).toBeLessThanOrEqual(width);
      // With web3 on, "Use a browser wallet" sits on the line of the other
      // links when they fit on one line.
      const wallet = page.locator('#hero .ln-signin-wallet__button');
      if (width >= 600 && await wallet.isVisible()) {
        const mid = async (sel: string) => { const b = (await page.locator(sel).boundingBox())!; return b.y + b.height / 2; };
        expect(Math.abs(await mid('#hero .ln-signin-wallet__button') - await mid('#hero .ln-signin-hero__password'))).toBeLessThanOrEqual(1);
      }
      if (width >= 600) {
        // Open Social held this block at 410px plus padding; well under it now.
        expect(m.wrap.height).toBeLessThan(410);
      }
    });

    test(`a community's picture keeps the full height, ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto('/');
      await expect(page.locator('#hero .hero-canvas')).toBeVisible();
      const before = await measure(page);
      // What the block template writes when the block has an image.
      await page.evaluate(() => {
        const cover = document.querySelector('#hero .cover-with-canvas') as HTMLElement;
        cover.classList.add('cover-img');
        cover.style.backgroundImage = 'linear-gradient(#556, #223)';
      });
      const m = await measure(page);
      expect(m.hasImage).toBe(true);
      // socialbase hero.css: 300px, and 410px from 600px wide.
      expect(m.wrap.height).toBeGreaterThanOrEqual(width >= 600 ? 410 : 300);
      // The picture keeps socialbase's padding; the main column, not the
      // block, clears the bar (ln-overrides.css).
      expect(Math.round(m.panel.top - m.cover.top)).toBe(COVER_PAD);
      if (width >= 600) {
        expect(m.cover.height).toBeGreaterThan(before.cover.height);
      }
    });
  }
});

// The focus ring on the panel's controls (Sign in, Use a password, the email
// link, and with web3 on the wallet choice). The panel is dark, so the ring is
// --ln-color-focus-on-dark (ln-overrides.css), at 3:1 or more against the
// panel. The panel's fill is translucent; it is composited over white, the
// lightest the block can be under it and so the worst case for a light ring.
test.describe('welcome panel focus ring', () => {
  test.beforeEach(async ({}, testInfo) => {
    test.skip(testInfo.project.name !== 'anonymous', 'runs signed out');
  });

  for (const width of WIDTHS) {
    for (const picture of [false, true]) {
      test(`every panel control rings focus-on-dark at 3:1 or more, ${picture ? 'picture' : 'no picture'}, ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 900 });
        await page.goto('/');
        await expect(page.locator('#hero .hero-canvas')).toBeVisible();
        if (picture) {
          await page.evaluate(() => {
            const cover = document.querySelector('#hero .cover-with-canvas') as HTMLElement;
            cover.classList.add('cover-img');
            cover.style.backgroundImage = 'linear-gradient(#556, #223)';
          });
        }
        const onDark = (await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--ln-color-focus-on-dark').trim())).toLowerCase();
        expect(onDark).toMatch(/^#[0-9a-f]{6}$/);
        const controls = await page.evaluate(() => {
          const sel = 'a[href], button:not([disabled]), input:not([type=hidden]):not([disabled]), [tabindex]:not([tabindex="-1"])';
          return [...document.querySelectorAll('#hero .hero-canvas')].flatMap((p) => [...p.querySelectorAll(sel)])
            .filter((e) => (e as HTMLElement).offsetParent !== null).length;
        });
        expect(controls, 'the panel has controls').toBeGreaterThan(0);

        const seen: string[] = [];
        for (let i = 0; i < 80 && seen.length < controls; i++) {
          await page.keyboard.press('Tab');
          const ring = await page.evaluate(() => {
            const el = document.activeElement as HTMLElement;
            if (!el.closest('#hero .hero-canvas')) return null;
            // socialblue eases buttons over 0.3s; read the ring once it settles.
            el.getAnimations().forEach((a) => a.finish());
            const cs = getComputedStyle(el);
            const parse = (c: string) => (c.match(/[\d.]+/g) ?? []).map(Number);
            const hex = (rgb: number[]) => '#' + rgb.slice(0, 3).map((n) => Math.round(n).toString(16).padStart(2, '0')).join('');
            const [r, g, b, a = 1] = parse(getComputedStyle(el.closest('.hero-canvas')!).backgroundColor);
            // The panel's fill over white.
            const fill = [r, g, b].map((c) => c * a + 255 * (1 - a));
            const lum = (rgb: number[]) => {
              const [lr, lg, lb] = rgb.map((c) => { const s = c / 255; return s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4; });
              return 0.2126 * lr + 0.7152 * lg + 0.0722 * lb;
            };
            const outline = parse(cs.outlineColor);
            const [hi, lo] = [lum(outline), lum(fill)].sort((x, y) => y - x);
            return {
              name: (el.textContent || el.getAttribute('aria-label') || el.tagName).trim().slice(0, 40),
              visible: el.matches(':focus-visible'),
              colour: hex(outline),
              style: cs.outlineStyle,
              ratio: (hi + 0.05) / (lo + 0.05),
              fill: hex(fill),
            };
          });
          if (!ring) continue;
          seen.push(ring.name);
          expect.soft(ring.visible, `${ring.name}: :focus-visible`).toBe(true);
          expect.soft(ring.style, `${ring.name}: a drawn outline`).not.toBe('none');
          expect.soft(ring.colour, `${ring.name}: the ring is --ln-color-focus-on-dark`).toBe(onDark);
          expect.soft(ring.ratio, `${ring.name}: ring on ${ring.fill}`).toBeGreaterThanOrEqual(3);
        }
        expect(seen.length, `Tab reached every panel control (${seen.join(', ')})`).toBe(controls);
      });
    }
  }
});
