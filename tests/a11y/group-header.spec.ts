// The group header's one-line description and its "New post" and "Invite"
// buttons, on the real theme (socialblue sky) with the seeded fixtures:
//   - A11y Garden (LN_GROUP): the steward manages it and may invite; the
//     member is a plain member, who may post but not invite (members may not
//     invite unless the group allows it).
//   - A11y Cover Garden (LN_COVER_GROUP): a cover picture and a description;
//     the steward manages it, the member is not in it.
//   - A11y Footer Garden (LN_FOOTER_GROUP): no picture, and a description
//     whose first paragraph is blank and whose second is long.
// Checks: who sees which button; the description is the first line with
// text, cut to fit; nothing scrolls sideways at 320px; the buttons come in
// reading order after the name; over the picture each button keeps an opaque
// fill, readable text and a keyboard ring inside its fill; axe finds nothing
// in the header.
//   LN_BASE_URL=... npx --no-install playwright test group-header.spec.ts --project member
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import AxeBuilder from '@axe-core/playwright';
import { test, expect, type Browser, type Locator } from '@playwright/test';
import { AXE_TAGS } from './helpers/axe';

const AUTH = join(dirname(fileURLToPath(import.meta.url)), '.auth');
const SUMMARY_LIMIT = 140;

const gid = (name: 'LN_GROUP' | 'LN_COVER_GROUP' | 'LN_FOOTER_GROUP') => {
  const id = process.env[name] ?? '';
  expect(id, `global-setup printed ${name}`).toMatch(/^\d+$/);
  return id;
};

async function open(browser: Browser, baseURL: string | undefined, persona: 'steward' | 'member', path: string, width = 1280) {
  const context = await browser.newContext({ baseURL, viewport: { width, height: 900 }, storageState: join(AUTH, `${persona}.json`) });
  const page = await context.newPage();
  const response = await page.goto(path);
  expect(response?.status(), `${path} loads`).toBe(200);
  await expect(page.locator('.cover h1')).toBeVisible();
  return { context, page };
}

const actions = (page: import('@playwright/test').Page) => page.locator('.cover .ln-group-actions');

// What the browser paints on a control: its fill, its text, its focus ring.
const paint = (el: Locator) => el.evaluate((node) => {
  node.getAnimations().forEach((a) => a.finish());
  const cs = getComputedStyle(node);
  const parse = (c: string) => (c.match(/[\d.]+/g) ?? []).map(Number);
  const hex = (rgb: number[]) => '#' + rgb.slice(0, 3).map((n) => Math.round(n).toString(16).padStart(2, '0')).join('');
  const lum = (rgb: number[]) => {
    const [r, g, b] = rgb.slice(0, 3).map((c) => { const v = c / 255; return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
  };
  const contrast = (a: number[], b: number[]) => {
    const [hi, lo] = [lum(a), lum(b)].sort((x, y) => y - x);
    return (hi + 0.05) / (lo + 0.05);
  };
  const bg = parse(cs.backgroundColor);
  return {
    bg: hex(bg), alpha: bg[3] ?? 1, ratio: contrast(parse(cs.color), bg),
    focusVisible: node.matches(':focus-visible'), ringStyle: cs.outlineStyle, ring: hex(parse(cs.outlineColor)),
    ringWidth: parseFloat(cs.outlineWidth), ringOffset: parseFloat(cs.outlineOffset), ringRatio: contrast(parse(cs.outlineColor), bg),
  };
});

test.describe('group header', () => {
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== 'member', 'runs once, with both personas');
  });

  test('one who may invite sees New post and Invite, in reading order after the name', async ({ browser, baseURL }) => {
    const group = gid('LN_GROUP');
    const { context, page } = await open(browser, baseURL, 'steward', `/group/${group}/stream`);
    const links = actions(page).getByRole('link');
    await expect(links).toHaveText(['New post', 'Invite']);
    await expect(links.nth(0)).toHaveAttribute('href', `/group/${group}/content/create/group_node%3Atopic`);
    await expect(links.nth(1)).toHaveAttribute('href', `/group/${group}/invite-members`);
    // Reading order: the name, then the buttons, then the tabs.
    const order = await page.evaluate(() => {
      const at = (sel: string) => document.querySelector(sel);
      const follows = (a: Element | null, b: Element | null) => !!a && !!b && !!(a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING);
      return {
        afterName: follows(at('.cover h1'), at('.ln-group-actions')),
        beforeTabs: follows(at('.ln-group-actions'), at('.navbar-secondary')),
      };
    });
    expect(order).toEqual({ afterName: true, beforeTabs: true });
    // Tab goes from New post to Invite.
    await links.nth(0).focus();
    await page.keyboard.press('Tab');
    await expect(links.nth(1)).toBeFocused();
    await context.close();
  });

  test('a member who may not invite sees New post only', async ({ browser, baseURL }) => {
    const { context, page } = await open(browser, baseURL, 'member', `/group/${gid('LN_GROUP')}/stream`);
    await expect(actions(page).getByRole('link')).toHaveText(['New post']);
    await context.close();
  });

  test('a non-member sees neither button, and does see the description', async ({ browser, baseURL }) => {
    const { context, page } = await open(browser, baseURL, 'member', `/group/${gid('LN_COVER_GROUP')}/about`);
    await expect(page.locator('.cover a', { hasText: /^(New post|Invite)$/ })).toHaveCount(0);
    await expect(page.locator('.cover .ln-group-actions'), 'no empty buttons row').toHaveCount(0);
    await expect(page.locator('.cover .ln-group-summary')).toHaveText('Seed swaps, shared tools and work days by the south fence.');
    await context.close();
  });

  test('the description is its first line with text, cut at a word to fit', async ({ browser, baseURL }) => {
    const { context, page } = await open(browser, baseURL, 'steward', `/group/${gid('LN_FOOTER_GROUP')}/stream`);
    const summary = page.locator('.cover .ln-group-summary');
    await expect(summary).toHaveCount(1);
    const text = (await summary.textContent())!.trim();
    expect(text.startsWith('Raised beds & a rain barrel, open to every neighbor')).toBe(true);
    expect(text.endsWith('…')).toBe(true);
    expect([...text].length).toBeLessThanOrEqual(SUMMARY_LIMIT);
    // On the white header it is readable body text.
    const m = await page.evaluate(() => {
      const parse = (c: string) => (c.match(/[\d.]+/g) ?? []).map(Number);
      const lum = (rgb: number[]) => {
        const [r, g, b] = rgb.slice(0, 3).map((c) => { const v = c / 255; return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; });
        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
      };
      const fg = parse(getComputedStyle(document.querySelector('.ln-group-summary')!).color);
      const bg = parse(getComputedStyle(document.querySelector('.cover')!).backgroundColor);
      const [hi, lo] = [lum(fg), lum(bg)].sort((x, y) => y - x);
      return (hi + 0.05) / (lo + 0.05);
    });
    expect(m, 'the description on the white header').toBeGreaterThanOrEqual(4.5);
    await context.close();
  });

  for (const [label, name] of [['plain', 'LN_GROUP'], ['cover picture', 'LN_COVER_GROUP'], ['long description', 'LN_FOOTER_GROUP']] as const) {
    test(`at 320px nothing scrolls sideways and the buttons fit (${label})`, async ({ browser, baseURL }) => {
      const { context, page } = await open(browser, baseURL, 'steward', `/group/${gid(name)}/stream`, 320);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      expect(overflow, 'the page does not scroll sideways').toBeLessThanOrEqual(0);
      for (const link of await actions(page).getByRole('link').all()) {
        const box = (await link.boundingBox())!;
        expect(box.x).toBeGreaterThanOrEqual(0);
        expect(box.x + box.width).toBeLessThanOrEqual(320);
        expect(box.height, 'a 24px target at least').toBeGreaterThanOrEqual(24);
      }
      await context.close();
    });
  }

  test('over a cover picture both buttons keep a fill, readable text and a ring inside the fill', async ({ browser, baseURL }) => {
    const { context, page } = await open(browser, baseURL, 'steward', `/group/${gid('LN_COVER_GROUP')}/stream`);
    const links = actions(page).getByRole('link');
    await expect(links).toHaveText(['New post', 'Invite']);
    for (const name of ['New post', 'Invite']) {
      const button = links.filter({ hasText: name });
      for (const [state, m] of [['at rest', await paint(button)], ['hovered', await (async () => { await button.hover(); return paint(button); })()]] as const) {
        expect.soft(m.alpha, `${name} ${state}: the fill is opaque`).toBe(1);
        expect.soft(m.ratio, `${name} ${state}: the text on ${m.bg}`).toBeGreaterThanOrEqual(4.5);
      }
    }
    // Reached with the keyboard: from the name's link (or the page) forward.
    await links.nth(0).focus();
    await page.keyboard.press('Shift+Tab');
    for (const name of ['New post', 'Invite']) {
      await page.keyboard.press('Tab');
      const button = links.filter({ hasText: name });
      await expect(button).toBeFocused();
      const ring = await paint(button);
      expect(ring.focusVisible, `${name}: Tab shows the ring`).toBe(true);
      expect.soft(ring.ringStyle, `${name}: the ring is drawn`).not.toBe('none');
      expect.soft(ring.ringOffset + ring.ringWidth, `${name}: the ring (${ring.ringWidth}px at offset ${ring.ringOffset}px) is inside the fill`).toBeLessThanOrEqual(0);
      expect.soft(ring.ringRatio, `${name}: the ring ${ring.ring} on the fill ${ring.bg}`).toBeGreaterThanOrEqual(3);
    }
    await context.close();
  });

  for (const [label, name] of [['plain', 'LN_GROUP'], ['cover picture', 'LN_COVER_GROUP'], ['long description', 'LN_FOOTER_GROUP']] as const) {
    test(`axe finds nothing in the header (${label})`, async ({ browser, baseURL }) => {
      const { context, page } = await open(browser, baseURL, 'steward', `/group/${gid(name)}/stream`);
      const results = await new AxeBuilder({ page }).include('.cover').withTags(AXE_TAGS).analyze();
      expect(results.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
      await context.close();
    });
  }
});
