// The DeepChat bar's keyboard focus ring, as the browser draws it (22.1-17,
// review 17-H1, WR-06).
//
// The global `:focus-visible` rule in ln-overrides.css is !important, so it
// beat the ring deepchat.css asks for: the bar's two controls drew the blue
// --ln-color-focus ring outside the dark bar (about 2.14:1 on the bar). The
// fix is in ln-overrides.css. This spec reads the COMPUTED outline colour,
// offset and contrast, so it measures what the cascade renders and not what
// a stylesheet requests.
//
//   header button      --ln-color-focus-on-dark, offset -4px, >= 3:1 on the bar
//   options button     the same, on the bar the button sits on
//   dropdown link      --ln-color-focus, offset -4px, >= 3:1 on the link's own
//                      fill (white) and on its hover fill (canvas)
//
// Keyboard only: Chromium shows :focus-visible for Tab and Shift+Tab, not for a
// click. Every line prints its computed values ("RING ..."), so a failing run
// records the defect.
//
// NO LIVE AI (helpers/ai-guard.ts): any AI request the page makes is aborted
// and the test fails if one was seen.
import { test, expect, type Page } from '@playwright/test';
import { assertNoLiveAi, guardAi, type AiGuard } from './helpers/ai-guard';

const BAR = '.ai-deepchat .ln-chat-bar';
const HEADER = `${BAR} .ai-deepchat--header`;
const OPTIONS = `${BAR} .chat-dropdown-button`;
const LINK = `${BAR} .chat-dropdown-link`;
const BAR_BAR = 3; // SC 1.4.11 non-text contrast, the bar for a focus ring

interface Ring {
  colour: string;
  offset: string;
  style: string;
  width: string;
  /** The first opaque background at or above the element. */
  background: string;
  /** Computed outline colour against `background`. */
  ratio: number;
}

function groupId(): string {
  const gid = process.env.LN_GROUP;
  expect(gid, 'global setup exports LN_GROUP').toMatch(/^\d+$/);
  return gid!;
}

/** The --ln-color-* custom property on :root, as a lowercase #rrggbb. */
async function token(page: Page, name: string): Promise<string> {
  const raw = await page.evaluate((n) => getComputedStyle(document.documentElement).getPropertyValue(n).trim(), name);
  expect(raw, `${name} is a 6-digit hex on :root`).toMatch(/^#[0-9a-fA-F]{6}$/);
  return raw.toLowerCase();
}

/** Tab (or Shift+Tab) until the element matching `selector` has focus; fails after `cap` presses. */
async function tabTo(page: Page, selector: string, key = 'Tab', cap = 250) {
  for (let i = 0; i < cap; i++) {
    await page.keyboard.press(key);
    if (await page.evaluate((s) => document.activeElement?.matches(s) ?? false, selector)) return;
  }
  throw new Error(`${selector} was not reached with ${cap} presses of ${key}`);
}

/** Computed ring of the focused element, with the contrast against the background it is drawn on. */
async function ringOfActive(page: Page, label: string): Promise<Ring> {
  const ring = await page.evaluate(() => {
    const el = document.activeElement as HTMLElement;
    const cs = getComputedStyle(el);
    const parse = (c: string) => (c.match(/[\d.]+/g) ?? []).map(Number);
    const hex = (c: string) => '#' + parse(c).slice(0, 3).map((n) => Math.round(n).toString(16).padStart(2, '0')).join('');
    let bg = el;
    let background = getComputedStyle(bg).backgroundColor;
    while ((parse(background)[3] ?? 1) === 0 && bg.parentElement) {
      bg = bg.parentElement;
      background = getComputedStyle(bg).backgroundColor;
    }
    const lum = (h: string) => {
      const [r, g, b] = [1, 3, 5].map((i) => {
        const s = parseInt(h.slice(i, i + 2), 16) / 255;
        return s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
      });
      return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    };
    const fg = hex(cs.outlineColor);
    const bgHex = hex(background);
    const [hi, lo] = [lum(fg), lum(bgHex)].sort((a, b) => b - a);
    return {
      colour: fg,
      offset: cs.outlineOffset,
      style: cs.outlineStyle,
      width: cs.outlineWidth,
      background: bgHex,
      ratio: (hi + 0.05) / (lo + 0.05),
    };
  });
  console.log(`RING ${label} ${JSON.stringify(ring)}`);
  return ring;
}

test.describe('S7 chat bar focus ring (22.1-17)', () => {
  let guard: AiGuard;
  test.beforeEach(async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'member', 'the chat bar shows for the member');
    guard = await guardAi(page, async (route) => {
      await route.abort();
    });
    await page.goto(`/group/${groupId()}/stream`);
    await expect(page.locator(HEADER)).toHaveAttribute('aria-expanded', 'false');
  });
  test.afterEach(() => {
    if (guard) assertNoLiveAi(guard);
  });

  test('the header button rings --ln-color-focus-on-dark, inset 4px, at 3:1 or more on the bar', async ({ page }) => {
    const onDark = await token(page, '--ln-color-focus-on-dark');
    await tabTo(page, HEADER);
    const ring = await ringOfActive(page, 'header');
    expect.soft(ring.style, 'a ring is drawn').toBe('solid');
    expect.soft(ring.width, 'the ring is 2px').toBe('2px');
    expect.soft(ring.colour, 'the ring colour is --ln-color-focus-on-dark').toBe(onDark);
    expect.soft(ring.offset, 'the ring is inset, inside the bar the fixed container would otherwise clip').toBe('-4px');
    expect.soft(ring.ratio, `the ring against the bar ${ring.background}`).toBeGreaterThanOrEqual(BAR_BAR);
  });

  test('the options button rings --ln-color-focus-on-dark, inset 4px, at 3:1 or more on the bar', async ({ page }) => {
    const onDark = await token(page, '--ln-color-focus-on-dark');
    await page.locator(HEADER).click(); // opens the panel; the mouse does not draw a ring
    await expect(page.locator(HEADER)).toHaveAttribute('aria-expanded', 'true');
    await page.keyboard.press('Shift+Tab'); // the options button comes before the header in the bar
    await expect(page.locator(OPTIONS)).toBeFocused();
    const ring = await ringOfActive(page, 'options');
    expect.soft(ring.style, 'a ring is drawn').toBe('solid');
    expect.soft(ring.width, 'the ring is 2px').toBe('2px');
    expect.soft(ring.colour, 'the ring colour is --ln-color-focus-on-dark').toBe(onDark);
    expect.soft(ring.offset, 'the ring is inset').toBe('-4px');
    expect.soft(ring.ratio, `the ring against the bar ${ring.background}`).toBeGreaterThanOrEqual(BAR_BAR);
  });

  test('a dropdown link rings --ln-color-focus, inset 4px, at 3:1 or more on white and on its hover fill', async ({ page }) => {
    const focus = await token(page, '--ln-color-focus');
    const surface = await token(page, '--ln-color-surface');
    const canvas = await token(page, '--ln-color-canvas');
    await page.locator(HEADER).click();
    await page.keyboard.press('Shift+Tab');
    await expect(page.locator(OPTIONS)).toBeFocused();
    await page.keyboard.press('Enter'); // opens the menu
    await expect(page.locator(`${BAR} .chat-dropdown`)).toHaveClass(/active/);
    // The menu fades in (visibility moves after a 0.3s transition); a Tab before that skips its link.
    await expect(page.locator(LINK)).toBeVisible();
    await tabTo(page, LINK, 'Tab', 5);
    const ring = await ringOfActive(page, 'link');
    expect.soft(ring.style, 'a ring is drawn').toBe('solid');
    expect.soft(ring.width, 'the ring is 2px').toBe('2px');
    expect.soft(ring.colour, 'the ring colour is --ln-color-focus: amber on the white menu would be 1.6:1').toBe(focus);
    expect.soft(ring.offset, 'the ring is inset').toBe('-4px');
    expect.soft(ring.background, 'the link sits on --ln-color-surface').toBe(surface);
    expect.soft(ring.ratio, `the ring against the link's fill ${ring.background}`).toBeGreaterThanOrEqual(BAR_BAR);

    // Hover keeps the ring and moves the fill to --ln-color-canvas.
    await page.locator(LINK).hover();
    const hovered = await ringOfActive(page, 'link-hover');
    expect.soft(hovered.colour, 'the ring stays --ln-color-focus on hover').toBe(focus);
    expect.soft(hovered.background, 'hovered, the link sits on --ln-color-canvas').toBe(canvas);
    expect.soft(hovered.ratio, `the ring against the hovered fill ${hovered.background}`).toBeGreaterThanOrEqual(BAR_BAR);
  });
});
