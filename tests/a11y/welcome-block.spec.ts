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
      expect(Math.round(m.cover.bottom - m.panel.bottom)).toBe(COVER_PAD);
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
