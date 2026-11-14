// The search pill in the top bar: the round search icon becomes a pill that
// says what the search does. Members, who get the AI answer on the search
// page, read "Search or ask a question"; signed-out visitors read "Search".
//
// From 900px up the pill is in the bar. Clicking it, or Enter on it, opens
// Open Social's search form over the page with the field focused, and Esc
// closes it. Below 900px Open Social hides the pill and the search field is in
// the opened Menu, as before.
//
// Checks: the label per persona and that it is the link's accessible name;
// the form opens, covers the page and closes; what the browser paints for the
// label, the edge and the focus rings, measured against the bar; the bar stays
// one 50px row at 900, 1024 and 1280 with the longer label; at 320 the Menu's
// search field is reachable and nothing scrolls sideways; axe on the pill.
//
// LN_SHOTS_DIR=<folder> also saves screenshots there.
//   LN_BASE_URL=... npx --no-install playwright test search-pill.spec.ts --project member --project anonymous
import { join } from 'node:path';
import AxeBuilder from '@axe-core/playwright';
import { test, expect, type Page } from '@playwright/test';
import { AXE_TAGS } from './helpers/axe';

const PILL = '.navbar-fixed-top .ln-search-pill';
const BAR_MAX = 50;
const SHOTS = process.env.LN_SHOTS_DIR;

const labelFor = (project: string) => (project === 'member' ? 'Search or ask a question' : 'Search');

async function open(page: Page, width: number, height = 800) {
  await page.setViewportSize({ width, height });
  const response = await page.goto('/');
  expect(response?.status()).toBe(200);
  await expect(page.locator('.navbar-fixed-top')).toBeVisible();
}

/** The focused element is the open form's search field. */
async function fieldFocused(page: Page) {
  await expect.poll(() => page.evaluate(() => !!document.activeElement?.matches('.search-take-over .form-text'))).toBe(true);
  return page.locator('.search-take-over .form-text:focus');
}

async function shot(page: Page, name: string) {
  if (SHOTS) await page.screenshot({ path: join(SHOTS, `${name}.png`) });
}

/** WCAG contrast ratio of two rgb()/rgba() colours (alpha ignored; both are opaque here). */
function contrast(a: string, b: string): number {
  const lum = (c: string) => {
    const [r, g, bl] = c.match(/[\d.]+/g)!.slice(0, 3).map(Number).map((v) => {
      const s = v / 255;
      return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * r + 0.7152 * g + 0.0722 * bl;
  };
  const [hi, lo] = [lum(a), lum(b)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
}

/** The colour a token resolves to, in the browser's rgb() form. */
async function token(page: Page, name: string): Promise<string> {
  return page.evaluate((n) => {
    const probe = document.createElement('i');
    probe.style.color = `var(${n})`;
    document.body.append(probe);
    const c = getComputedStyle(probe).color;
    probe.remove();
    return c;
  }, name);
}

test.describe('search pill', () => {
  test.beforeEach(async ({}, testInfo) => {
    test.skip(!['member', 'anonymous'].includes(testInfo.project.name), 'runs signed out and as the member');
  });

  test('the label says what the search does, and names the link', async ({ page }, testInfo) => {
    await open(page, 1280);
    const pill = page.locator(PILL);
    await expect(pill).toBeVisible();
    const label = labelFor(testInfo.project.name);
    await expect(pill.locator('.ln-search-pill__label')).toHaveText(label);
    await expect(pill).toHaveAccessibleName(label);
    await expect(pill).toHaveAttribute('href', '/search/all');
    await shot(page, `pill-1280-${testInfo.project.name}`);
  });

  test('the bar stays one 50px row with the pill', async ({ page }, testInfo) => {
    for (const width of [900, 1024, 1280]) {
      await open(page, width);
      const bar = (await page.locator('.navbar-fixed-top').boundingBox())!;
      const pill = (await page.locator(PILL).boundingBox())!;
      expect(bar.height, `bar at ${width}`).toBeLessThanOrEqual(BAR_MAX);
      expect(pill.y, `pill inside the bar at ${width}`).toBeGreaterThanOrEqual(bar.y);
      expect(pill.y + pill.height).toBeLessThanOrEqual(bar.y + bar.height + 0.5);
      // One line of text: the label does not wrap.
      expect(pill.height).toBeLessThanOrEqual(40);
      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
      if (width === 900) await shot(page, `pill-900-${testInfo.project.name}`);
    }
  });

  test('a long community name gives way: still one row at 900 to 1280', async ({ page }) => {
    for (const width of [900, 960, 1024, 1280]) {
      await open(page, width);
      // The longest name the header shows uncut is about 280px; this one is longer.
      await page.locator('.ln-site-name').evaluate((el) => { el.textContent = 'Boulder Creek Watershed Commons Garden'; });
      const bar = (await page.locator('.navbar-fixed-top').boundingBox())!;
      expect(bar.height, `bar at ${width}`).toBeLessThanOrEqual(BAR_MAX);
      // Every item in the bar (the name, the menu, the pill, Sign in or the account icons) is inside it.
      const bottoms = await page.locator('.navbar-fixed-top .container--navbar > *').evaluateAll((els) =>
        els.map((el) => el.getBoundingClientRect()).filter((r) => r.width > 0 && r.height > 0).map((r) => r.bottom));
      expect(bottoms.length).toBeGreaterThan(1);
      for (const b of bottoms) expect(b).toBeLessThanOrEqual(bar.y + bar.height + 0.5);
      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    }
  });

  test('the search form covers a tall window', async ({ page }) => {
    await open(page, 3840, 2160);
    await page.locator(PILL).click();
    await expect.poll(() => page.evaluate(() => {
      const r = document.querySelector('.navbar-fixed-top .ln-search-pill')!.getBoundingClientRect();
      return r.left <= 0 && r.top <= 0 && r.right >= innerWidth && r.bottom >= innerHeight;
    })).toBe(true);
  });

  test('clicking opens the search form over the page; Esc closes it', async ({ page }, testInfo) => {
    await open(page, 1280);
    await page.locator(PILL).click();
    await expect(page.locator('body')).toHaveClass(/mode-search/);
    const field = await fieldFocused(page);
    await page.keyboard.type('garden');

    // The backdrop is the pill's own fill scaled up (socialbase animates it):
    // once it settles it covers the whole window.
    await expect.poll(() => page.evaluate(() => {
      const r = document.querySelector('.navbar-fixed-top .ln-search-pill')!.getBoundingClientRect();
      return r.left <= 0 && r.top <= 0 && r.right >= innerWidth && r.bottom >= innerHeight;
    })).toBe(true);
    await shot(page, `form-open-${testInfo.project.name}`);

    // The field is see-through, its words and its line light on the dark cover.
    const bg = await page.locator(PILL).evaluate((el) => getComputedStyle(el).backgroundColor);
    const f = await field.evaluate((el) => {
      const cs = getComputedStyle(el);
      return { bg: cs.backgroundColor, color: cs.color, line: cs.borderBottomColor };
    });
    expect(f.bg).toBe('rgba(0, 0, 0, 0)');
    expect(contrast(f.color, bg)).toBeGreaterThanOrEqual(4.5);
    expect(contrast(f.line, bg)).toBeGreaterThanOrEqual(3);
    // The search button is only its icon; the icon is light on the cover too.
    const icon = await page.locator('.search-take-over:has(.form-text:focus) [type="submit"] svg').evaluate((el) => getComputedStyle(el).fill);
    expect(contrast(icon, bg)).toBeGreaterThanOrEqual(3);

    await page.keyboard.press('Escape');
    await expect(page.locator('body')).not.toHaveClass(/mode-search/);
  });

  test('Enter on the pill opens the form; the rings are the light focus colour', async ({ page }) => {
    await open(page, 1280);
    const pill = page.locator(PILL);
    await pill.focus();
    const onDark = await token(page, '--ln-color-focus-on-dark');
    expect(await pill.evaluate((el) => el.matches(':focus-visible'))).toBe(true);
    // socialbase animates the search link, so the ring is read once it settles.
    await expect.poll(() => pill.evaluate((el) => `${getComputedStyle(el).outlineStyle} ${getComputedStyle(el).outlineColor}`)).toBe(`solid ${onDark}`);

    await page.keyboard.press('Enter');
    await expect(page.locator('body')).toHaveClass(/mode-search/);
    await fieldFocused(page);
    // Tab to the search button: its ring is drawn on the dark cover.
    await page.keyboard.press('Tab');
    const next = page.locator(':focus');
    expect(await next.evaluate((el) => !!el.closest('.search-take-over') && el.matches(':focus-visible'))).toBe(true);
    await expect.poll(() => next.evaluate((el) => `${getComputedStyle(el).outlineStyle} ${getComputedStyle(el).outlineColor}`)).toBe(`solid ${onDark}`);
  });

  test('the label and the edge stand out from the bar', async ({ page }) => {
    await open(page, 1280);
    const pill = page.locator(PILL);
    const c = await pill.evaluate((el) => {
      const cs = getComputedStyle(el);
      const bar = getComputedStyle(document.querySelector('.navbar-fixed-top')!).backgroundColor;
      return { text: cs.color, edge: cs.borderTopColor, fill: cs.backgroundColor, bar };
    });
    expect(contrast(c.text, c.fill)).toBeGreaterThanOrEqual(4.5);
    expect(contrast(c.edge, c.fill)).toBeGreaterThanOrEqual(3);
    expect(contrast(c.edge, c.bar)).toBeGreaterThanOrEqual(3);
    await pill.hover();
    const hover = await pill.evaluate((el) => getComputedStyle(el).borderTopColor);
    expect(contrast(hover, c.bar)).toBeGreaterThanOrEqual(3);
  });

  test('axe finds nothing on the pill', async ({ page }) => {
    await open(page, 1280);
    const { violations } = await new AxeBuilder({ page }).include('.ln-search-pill-control').withTags(AXE_TAGS).analyze();
    expect(violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
  });

  test('320px: no pill in the bar, the search field is in the Menu', async ({ page }, testInfo) => {
    await open(page, 320);
    await expect(page.locator(PILL)).toBeHidden();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    await page.locator('.navbar-toggle').click();
    const field = page.locator('#main-navigation .navbar-search input[type="search"], #main-navigation .navbar-search .form-text').first();
    await expect(field).toBeVisible();
    await field.focus();
    await expect(field).toBeFocused();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    await shot(page, `menu-320-${testInfo.project.name}`);
  });
});
