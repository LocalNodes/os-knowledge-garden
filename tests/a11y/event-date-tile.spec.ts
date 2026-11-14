// The date tile on an event card (the event teaser), on the real theme
// (socialblue sky) with the seeded fixtures in A11y Garden (LN_GROUP):
//   - LN_EVENT: Saturday 16 March 2030, 12:00 UTC, with a picture.
//   - LN_EVENT_LONG: Monday 18 March 2030, 12:00 UTC, no picture, a long title.
// Checks: the tile shows weekday, day and month; it is hidden from screen
// readers and holds nothing focusable; its text is readable on its fill; it
// sits left of the title at 1280px and 320px; the stock date line stays;
// nothing scrolls sideways at 320px; axe finds nothing on the cards.
//   LN_BASE_URL=... npx --no-install playwright test event-date-tile.spec.ts --project member
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import AxeBuilder from '@axe-core/playwright';
import { test, expect, type Browser, type Page } from '@playwright/test';
import { AXE_TAGS } from './helpers/axe';

const AUTH = join(dirname(fileURLToPath(import.meta.url)), '.auth');

const env = (name: 'LN_GROUP' | 'LN_EVENT' | 'LN_EVENT_LONG') => {
  const id = process.env[name] ?? '';
  expect(id, `global-setup printed ${name}`).toMatch(/^\d+$/);
  return id;
};

async function open(browser: Browser, baseURL: string | undefined, width: number) {
  const context = await browser.newContext({ baseURL, viewport: { width, height: 900 }, storageState: join(AUTH, 'member.json') });
  const page = await context.newPage();
  const response = await page.goto(`/group/${env('LN_GROUP')}/events`);
  expect(response?.status(), 'the group events page loads').toBe(200);
  return { context, page };
}

// The card that links to an event.
const card = (page: Page, nid: string) => page.locator('.teaser').filter({ has: page.locator(`.teaser__title a[href$="/node/${nid}"]`) });

test.describe('event date tile', () => {
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== 'member', 'runs once, as the member');
  });

  test('the tile shows weekday, day and month, hidden from screen readers', async ({ browser, baseURL }) => {
    const { context, page } = await open(browser, baseURL, 1280);
    for (const [nid, parts] of [[env('LN_EVENT'), ['Sat', '16', 'Mar']], [env('LN_EVENT_LONG'), ['Mon', '18', 'Mar']]] as const) {
      const tile = card(page, nid).locator('.ln-event-date');
      await expect(tile).toHaveCount(1);
      await expect(tile.locator('span')).toHaveText([...parts]);
      await expect(tile).toHaveAttribute('aria-hidden', 'true');
      await expect(tile.locator('a, button, [tabindex]')).toHaveCount(0);
      // The stock date line stays, with its screen-reader label.
      await expect(card(page, nid).getByText('Event date', { exact: false }).first()).toBeAttached();
    }
    await context.close();
  });

  test('the tile text is readable on its fill', async ({ browser, baseURL }) => {
    const { context, page } = await open(browser, baseURL, 1280);
    const ratios = await card(page, env('LN_EVENT')).locator('.ln-event-date span').evaluateAll((spans) => {
      const parse = (c: string) => (c.match(/[\d.]+/g) ?? []).map(Number);
      const lum = (rgb: number[]) => {
        const [r, g, b] = rgb.slice(0, 3).map((c) => { const v = c / 255; return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; });
        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
      };
      const tile = spans[0].parentElement!;
      const bg = parse(getComputedStyle(tile).backgroundColor);
      return spans.map((s) => {
        const fg = parse(getComputedStyle(s).color);
        const [hi, lo] = [lum(fg), lum(bg)].sort((x, y) => y - x);
        return { text: s.textContent, alpha: bg[3] ?? 1, ratio: (hi + 0.05) / (lo + 0.05) };
      });
    });
    for (const r of ratios) {
      expect(r.alpha, `${r.text}: the tile fill is opaque`).toBe(1);
      expect(r.ratio, `${r.text} on the tile`).toBeGreaterThanOrEqual(4.5);
    }
    await context.close();
  });

  for (const width of [1280, 320]) {
    test(`at ${width}px the tile sits left of the title, and nothing scrolls sideways`, async ({ browser, baseURL }) => {
      const { context, page } = await open(browser, baseURL, width);
      for (const nid of [env('LN_EVENT'), env('LN_EVENT_LONG')]) {
        const tile = (await card(page, nid).locator('.ln-event-date').boundingBox())!;
        const title = (await card(page, nid).locator('.teaser__title').boundingBox())!;
        expect(tile, 'the tile is visible').toBeTruthy();
        expect(tile.x + tile.width, 'the tile ends before the title starts').toBeLessThanOrEqual(title.x);
        // The tile starts level with the first line of the text column (the
        // card's type tag), so the tag, title and date line run beside it.
        const first = (await card(page, nid).locator('.teaser__content > :not(.ln-event-date)').first().boundingBox())!;
        expect(Math.abs(tile.y - first.y), 'the tile starts level with the first line beside it').toBeLessThanOrEqual(2);
        expect(tile.y, 'the tile starts above the title').toBeLessThan(title.y);
        expect(title.x + title.width, 'the title fits the window').toBeLessThanOrEqual(width);
      }
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      expect(overflow, 'the page does not scroll sideways').toBeLessThanOrEqual(0);
      await context.close();
    });
  }

  test('axe finds nothing on the event cards', async ({ browser, baseURL }) => {
    const { context, page } = await open(browser, baseURL, 1280);
    await expect(page.locator('.ln-event-date').first()).toBeVisible();
    const results = await new AxeBuilder({ page }).include('.teaser').withTags(AXE_TAGS).analyze();
    // One known Open Social issue is let through, and only on its own node:
    // socialbase's card puts the picture-corner type link (and, with no
    // picture, the placeholder link) inside aria-hidden while it stays
    // focusable. It is on every node card, with or without the tile; the
    // fix is a known open item for the upstream card template.
    // Anything else, or this rule on any other node, fails.
    const stock = (target: string) => /^a\[title="Event: /.test(target) || /^a\[href="\/node\/\d+"\]\[aria-hidden="true"\]$/.test(target);
    const found = results.violations.flatMap((v) => v.nodes
      .map((n) => n.target.join(' '))
      .filter((target) => !(v.id === 'aria-hidden-focus' && stock(target)))
      .map((target) => `${v.id}: ${target}`));
    expect(found).toEqual([]);
    await context.close();
  });
});
