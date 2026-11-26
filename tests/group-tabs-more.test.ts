/**
 * The group tab bar's "More" control, in a real browser.
 *
 * From 900px wide, socialblue folds the group tabs that don't fit into a
 * list it opens from a caret; group-tabs.js puts a button in that caret and
 * ln-overrides.css draws it. This loads the real script and styles (with
 * socialblue's sky navbar styles and the app look) into the markup
 * socialblue builds, and checks the control: a button named "More" that
 * shows its word and an arrow on the white bar, at least 44px square,
 * reached by Tab with a visible ring, and opened by keyboard.
 *
 * Uses the installed Google Chrome (LN_BROWSER_CHANNEL=chromium for
 * Playwright's own). Skips when the Open Social theme is not installed
 * (composer install).
 */
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { chromium, type Browser, type Page } from '@playwright/test';

const ROOT = resolve(__dirname, '..');
const PLATFORM = resolve(ROOT, 'html/modules/custom/localnodes_platform');
const SKY = resolve(ROOT, 'html/profiles/contrib/social/themes/socialblue/assets/css/navbar--sky.css');
const CSS = [SKY, `${PLATFORM}/css/ln-tokens.css`, `${PLATFORM}/css/ln-overrides.css`, `${PLATFORM}/css/ln-app-look.css`];

const tab = (name: string) => `<li><a href="#${name}">${name}</a></li>`;
const MARKUP = `
<nav class="navbar navbar-secondary" role="navigation">
  <div class="navbar-scrollable">
    <ul class="nav navbar-nav">
      <div class="visible-list">${['Stream', 'About', 'Events'].map(tab).join('')}</div>
      <div class="hidden-list card">${['Votes', 'Members'].map(tab).join('')}</div>
      <span class="caret"></span>
    </ul>
  </div>
</nav>`;

// WCAG relative-luminance contrast of two "rgb(…)" colours.
function contrast(a: string, b: string): number {
  const lum = (c: string) => {
    const [r, g, bl] = (c.match(/[\d.]+/g) ?? []).slice(0, 3).map(Number).map((v) => {
      const s = v / 255;
      return s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * r + 0.7152 * g + 0.0722 * bl;
  };
  const [hi, lo] = [lum(a), lum(b)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
}

describe.skipIf(!existsSync(SKY))('group tab bar "More" control', () => {
  let browser: Browser;
  let page: Page;

  beforeAll(async () => {
    browser = await chromium.launch({ channel: process.env.LN_BROWSER_CHANNEL ?? 'chrome' });
    page = await browser.newPage({ viewport: { width: 1280, height: 600 } });
    // Bootstrap 3's .navbar is the folded list's positioned ancestor on the
    // real page; it is the only rule taken from outside the files above.
    const styles = '<style>.navbar { position: relative; }</style>'
      + CSS.map((file) => `<style>${readFileSync(file, 'utf8')}</style>`).join('');
    await page.setContent(`<!doctype html><html><head>${styles}</head><body class="socialblue--sky">${MARKUP}</body></html>`);
    // socialblue's click handler on the caret, as navbar-secondary.js binds it.
    await page.evaluate(() => {
      const caret = document.querySelector('.caret')!;
      const list = document.querySelector<HTMLElement>('.hidden-list')!;
      caret.addEventListener('click', () => {
        list.style.display = list.style.display === 'block' ? 'none' : 'block';
        caret.classList.toggle('active');
      });
    });
    await page.addScriptTag({ content: `window.Drupal = {t: (s) => s, behaviors: {}};
      window.once = (id, selector, context) => Array.from(context.querySelectorAll(selector));` });
    await page.addScriptTag({ content: readFileSync(`${PLATFORM}/js/group-tabs.js`, 'utf8') });
    await page.evaluate(() => (window as any).Drupal.behaviors.localnodesGroupTabs.attach(document));
  });

  afterAll(async () => {
    await browser?.close();
  });

  it('is a button named "More" that shows its word and an arrow', async () => {
    const button = page.getByRole('button', { name: 'More', exact: true });
    await expect(button.count()).resolves.toBe(1);
    const paint = await button.evaluate((b) => {
      const cs = getComputedStyle(b);
      const arrow = getComputedStyle(b, '::after');
      return {
        text: b.textContent?.trim(),
        color: cs.color,
        bg: getComputedStyle(b.closest('.navbar-secondary')!).backgroundColor,
        arrow: arrow.content !== 'none' && arrow.borderRightStyle === 'solid' ? arrow.borderRightColor : null,
      };
    });
    expect(paint.text).toBe('More');
    expect(contrast(paint.color, paint.bg)).toBeGreaterThanOrEqual(4.5);
    expect(paint.arrow, 'the arrow is drawn').not.toBeNull();
    expect(contrast(paint.arrow!, paint.bg)).toBeGreaterThanOrEqual(3);
  });

  it('is at least 44px square', async () => {
    const box = await page.getByRole('button', { name: 'More', exact: true }).boundingBox();
    expect(box!.width).toBeGreaterThanOrEqual(44);
    expect(box!.height).toBeGreaterThanOrEqual(44);
  });

  it('is reached by Tab with a visible ring and opens the folded tabs', async () => {
    await page.locator('.visible-list a').last().focus();
    await page.keyboard.press('Tab');
    const ring = await page.evaluate(() => {
      const b = document.activeElement as HTMLElement;
      b.getAnimations().forEach((a) => a.finish());
      const cs = getComputedStyle(b);
      return {
        name: b.textContent?.trim(),
        style: cs.outlineStyle,
        width: parseFloat(cs.outlineWidth),
        color: cs.outlineColor,
        bg: getComputedStyle(b.closest('.navbar-secondary')!).backgroundColor,
      };
    });
    expect(ring.name).toBe('More');
    expect(ring.style).toBe('solid');
    expect(ring.width).toBeGreaterThanOrEqual(2);
    expect(contrast(ring.color, ring.bg)).toBeGreaterThanOrEqual(3);

    await page.keyboard.press('Enter');
    const button = page.getByRole('button', { name: 'More', exact: true });
    await expect(button.getAttribute('aria-expanded')).resolves.toBe('true');
    // Open means a folded tab can be pointed at, not only that it is displayed.
    const reachable = await page.locator('.hidden-list a[href="#Members"]').evaluate((a) => {
      const r = a.getBoundingClientRect();
      const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
      return r.height > 0 && (hit === a || a.contains(hit));
    });
    expect(reachable).toBe(true);
  });
});
