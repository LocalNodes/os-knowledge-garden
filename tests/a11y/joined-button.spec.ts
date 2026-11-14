// The group page's "Joined" button, as the browser draws it.
//
// The brand rule: the highlight colour is never a button. Open Social's
// join template paints the joined state's toggle with socialblue's btn-accent,
// a solid amber fill. Instead: an outlined navy
// button. The fix is one rule in ln-overrides.css. The rule targets the
// template branch whose primary has no link (a state, not an action), so the
// "Join" call to action keeps its own style.
//
// This spec reads COMPUTED colours for the member, who has joined the a11y
// group:
//   - fill --ln-color-surface, text and border --ln-color-primary-strong (navy)
//   - text at 4.5:1 or more on the fill, border at 3:1 or more on the fill
//   - not the highlight colour, at rest or on hover
//
// NO LIVE AI (helpers/ai-guard.ts): any AI request the page makes is aborted
// and the test fails if one was seen.
import { test, expect, type Page } from '@playwright/test';
import { assertNoLiveAi, guardAi, type AiGuard } from './helpers/ai-guard';

const JOINED = '.hero-footer__cta .btn-group > button.dropdown-toggle.btn-block';

function groupId(): string {
  const gid = process.env.LN_GROUP;
  expect(gid, 'global setup exports LN_GROUP').toMatch(/^\d+$/);
  return gid!;
}

async function token(page: Page, name: string): Promise<string> {
  const raw = await page.evaluate((n) => getComputedStyle(document.documentElement).getPropertyValue(n).trim(), name);
  expect(raw, `${name} is a 6-digit hex on :root`).toMatch(/^#[0-9a-fA-F]{6}$/);
  return raw.toLowerCase();
}

interface Look {
  label: string;
  fill: string;
  text: string;
  border: string;
  borderWidth: string;
  textRatio: number;
  borderRatio: number;
}

async function lookOf(page: Page): Promise<Look> {
  return page.locator(JOINED).evaluate((el) => {
    const cs = getComputedStyle(el);
    const parse = (c: string) => (c.match(/[\d.]+/g) ?? []).map(Number);
    const hex = (c: string) => '#' + parse(c).slice(0, 3).map((n) => Math.round(n).toString(16).padStart(2, '0')).join('');
    const lum = (h: string) => {
      const [r, g, b] = [1, 3, 5].map((i) => {
        const s = parseInt(h.slice(i, i + 2), 16) / 255;
        return s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
      });
      return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    };
    const ratio = (a: string, b: string) => {
      const [hi, lo] = [lum(a), lum(b)].sort((x, y) => y - x);
      return Math.round(((hi + 0.05) / (lo + 0.05)) * 100) / 100;
    };
    const fill = hex(cs.backgroundColor);
    const text = hex(cs.color);
    const border = hex(cs.borderTopColor);
    return {
      label: (el.textContent ?? '').trim(),
      fill,
      text,
      border,
      borderWidth: cs.borderTopWidth,
      textRatio: ratio(text, fill),
      borderRatio: ratio(border, fill),
    };
  });
}

test.describe('the "Joined" button is outlined navy, never the highlight', () => {
  let guard: AiGuard;
  test.beforeEach(async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'member', 'the member has joined the a11y group');
    guard = await guardAi(page, async (route) => {
      await route.abort();
    });
    await page.goto(`/group/${groupId()}/stream`);
    await expect(page.locator(JOINED)).toHaveText(/Joined/);
  });
  test.afterEach(() => {
    if (guard) assertNoLiveAi(guard);
  });

  for (const state of ['rest', 'hover'] as const) {
    test(`at ${state}: surface fill, navy text and border, contrast met`, async ({ page }) => {
      const surface = await token(page, '--ln-color-surface');
      const navy = await token(page, '--ln-color-primary-strong');
      const highlight = await token(page, '--ln-color-highlight');
      if (state === 'hover') {
        await page.locator(JOINED).hover();
        await page.waitForTimeout(400); // socialblue eases button colours
      }
      const look = await lookOf(page);
      console.log(`JOINED ${state} ${JSON.stringify(look)}`);
      expect.soft(look.fill, 'never the highlight colour').not.toBe(highlight);
      expect.soft(look.fill, 'the fill is --ln-color-surface').toBe(surface);
      expect.soft(look.text, 'the text is --ln-color-primary-strong').toBe(navy);
      expect.soft(look.border, 'the border is --ln-color-primary-strong').toBe(navy);
      expect.soft(look.borderWidth, 'the outline is drawn').not.toBe('0px');
      expect.soft(look.textRatio, 'text on the fill (SC 1.4.3)').toBeGreaterThanOrEqual(4.5);
      expect.soft(look.borderRatio, 'border on the fill (SC 1.4.11)').toBeGreaterThanOrEqual(3);
    });
  }
});
