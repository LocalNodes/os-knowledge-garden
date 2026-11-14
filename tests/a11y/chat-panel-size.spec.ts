// The chat panel's size and the assistant's name, at two phone widths, at
// 768 and 769 (either side of the width where the open panel stops filling
// the window), and on two desktops.
//
//   open panel     the chat box sets the panel's width: the panel, the box and
//                  the line under the input ("what is sent to the AI
//                  service") are the same width, and none is wider than the
//                  window. The line is fine print (12px) and wraps inside
//                  the box; it never widens the panel.
//   the line       visible, unchanged and not hidden from a screen reader.
//   closed bar     the default name "LocalNodes LiAIson" is on one line.
//   long name      a site may rename the assistant: with a long name, or one
//                  long word, the bar stays inside the window, open or closed,
//                  and the name itself still shows (at 320 the 40-character
//                  name wraps to a second line). From 900 up the name stays
//                  on one line and a long one ends in an ellipsis, like the
//                  site name in the top bar; its title holds the whole name.
//   short phone    at 320x568 and 320x640 the open panel is 80% of the
//                  window: the chat box gives up height so the line under
//                  the input ends inside the panel and inside the window.
//   Sources links  on a search page with an AI overview, the closed bar's
//                  box meets no Sources link, with the default name and the
//                  40-character name, at 1280 and 320: when a keyboard user
//                  Tabs onto the link from the top of the page (the page's
//                  scroll-padding keeps the focused link above the bar), and
//                  when the link is scrolled to the window's bottom edge.
//                  The page as it first loads is not checked: the bar floats
//                  over whatever sits at the window's bottom, so at some
//                  window heights it covers part of a link until the page is
//                  scrolled.
//
// Each check prints its measures ("SIZE ..."), so a failing run records the
// defect.
//
// NO LIVE AI (helpers/ai-guard.ts): any AI request the page makes is aborted
// and the test fails if one was seen.
import { test, expect, type Page } from '@playwright/test';
import { assertNoLiveAi, guardAi, type AiGuard } from './helpers/ai-guard';
import { OVERVIEW_BIOREGIONALISM_RESPONSE, OVERVIEW_NONE_RESPONSE } from './fixtures/canned-ai';

const CONTAINER = '.ai-deepchat.chat-container';
const HEADER = `${CONTAINER} .ai-deepchat--header`;
const LINE = `${CONTAINER} .ln-chat-disclosure`;
// The template's words; the sentence must not change.
const LINE_TEXT = 'Your question, and passages from pages you can see, are sent to the AI service to write the answer.';
const LONG_NAMES = ['Bread and Roses Neighborhood Cooperative', 'Supercalifragilisticexpialidociousbotty1'];
const NAME = `${HEADER} .ln-assistant-name`;

// Puts a name into the bar as a site's setting renders it: the visible name
// (aria-hidden, clamped, with the whole name as its title) and the visually
// hidden spoken name beside it.
async function setName(page: Page, name: string): Promise<void> {
  await page.locator(NAME).evaluate((e, n) => {
    e.textContent = n;
    e.setAttribute('title', n);
    const spoken = e.parentElement!.querySelector('.visually-hidden');
    if (spoken) {
      spoken.textContent = `${n},`;
    }
  }, name);
}

// The name shows: visible, with a real box. With multiline, it also takes
// more than one line's height.
// From 900 up: one line, cut with an ellipsis like the top bar's site name,
// with the whole name in the title and in what a screen reader gets.
async function expectNameOnOneLine(page: Page, label: string, fullName: string): Promise<void> {
  const fit = await page.locator(NAME).evaluate((e) => {
    const cs = getComputedStyle(e);
    return {
      h: e.getBoundingClientRect().height, lh: parseFloat(cs.lineHeight), sw: e.scrollWidth, cw: e.clientWidth,
      ws: cs.whiteSpace, to: cs.textOverflow, clamp: cs.webkitLineClamp, title: e.getAttribute('title'), text: e.textContent,
    };
  });
  console.log(`FIT ${label}: ${JSON.stringify(fit)}`);
  expect(Math.abs(fit.h - fit.lh), `${label}: the name is one line high`).toBeLessThanOrEqual(1);
  expect([fit.ws, fit.to], `${label}: a long name is cut with an ellipsis on its one line`).toEqual(['nowrap', 'ellipsis']);
  expect(fit.clamp, `${label}: no two-line clamp`).toBe('none');
  expect(fit.sw, `${label}: the long name is wider than its box, so the ellipsis shows`).toBeGreaterThan(fit.cw);
  expect(fit.text, `${label}: the whole name is in the text`).toBe(fullName);
  expect(fit.title, `${label}: the title holds the whole name`).toBe(fullName);
  expect(await page.locator(HEADER).ariaSnapshot(), `${label}: a screen reader gets the whole name`).toContain(fullName);
}

async function expectNameShows(page: Page, label: string, multiline: boolean, fullName = ''): Promise<void> {
  await expect(page.locator(NAME), `${label}: the name is visible`).toBeVisible();
  const { w, h, lh } = await page.locator(NAME).evaluate((e) => {
    const r = e.getBoundingClientRect();
    return { w: r.width, h: r.height, lh: parseFloat(getComputedStyle(e).lineHeight) };
  });
  console.log(`SIZE ${label}: name ${w}x${h}, line height ${lh}`);
  expect(w, `${label}: the name has width`).toBeGreaterThan(0);
  expect(h, `${label}: the name has height`).toBeGreaterThan(0);
  if (multiline) {
    expect(h, `${label}: the name takes more than one line`).toBeGreaterThan(lh * 1.5);
    // A name too long for two lines is cut there, by design. The cut must be
    // safe: it ends in an ellipsis rather than a hard clip, and a screen
    // reader still gets the whole name.
    const fit = await page.locator(NAME).evaluate((e) => {
      const cs = getComputedStyle(e);
      return {
        sh: e.scrollHeight, ch: e.clientHeight, sw: e.scrollWidth, cw: e.clientWidth,
        clamp: cs.webkitLineClamp, maxHeight: cs.maxHeight, orient: cs.webkitBoxOrient, text: e.textContent,
      };
    });
    console.log(`FIT ${label}: ${JSON.stringify(fit)}`);
    expect(fit.sw, `${label}: no part of a line is cut off sideways`).toBeLessThanOrEqual(fit.cw + 1);
    if (fit.sh > fit.ch + 1) {
      // A vertical box clamped to two lines ends its last line with an
      // ellipsis. (Newer Chromium reports the clamped box's display as
      // flow-root, so display isn't checked.)
      expect([fit.orient, fit.clamp], `${label}: cut with an ellipsis at two lines`)
        .toEqual(['vertical', '2']);
      // The clamp alone sets the height: two whole lines, so the second line
      // is the one that ends in the ellipsis and nothing clips it part way.
      expect(fit.maxHeight, `${label}: nothing else limits the name's height`).toBe('none');
      expect(Math.abs(fit.ch - 2 * lh), `${label}: the box is two whole lines (${fit.ch} for line height ${lh})`)
        .toBeLessThanOrEqual(1);
      expect(fit.text, `${label}: the whole name is in the text`).toBe(fullName);
      expect(await page.locator(HEADER).ariaSnapshot(), `${label}: a screen reader gets the whole name`)
        .toContain(fullName);
    }
  }
}

function groupId(): string {
  const gid = process.env.LN_GROUP;
  expect(gid, 'global setup exports LN_GROUP').toMatch(/^\d+$/);
  return gid!;
}

interface Box { left: number; right: number; width: number; height: number }

async function box(page: Page, selector: string): Promise<Box> {
  const b = await page.locator(selector).first().boundingBox();
  expect(b, `${selector} is rendered`).not.toBeNull();
  return { left: b!.x, right: b!.x + b!.width, width: b!.width, height: b!.height };
}

for (const width of [320, 390, 768, 769, 1024, 1280]) {
  test.describe(`chat panel size at ${width}px`, () => {
    let guard: AiGuard;
    test.use({ viewport: { width, height: 800 } });
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

    test(`the open panel is as wide as the chat box, inside the window (${width} px)`, async ({ page }) => {
      await page.locator(HEADER).click();
      await expect(page.locator(HEADER)).toHaveAttribute('aria-expanded', 'true');
      await expect(page.locator(`${CONTAINER} deep-chat`)).toBeVisible();
      const panel = await box(page, CONTAINER);
      const chat = await box(page, `${CONTAINER} deep-chat`);
      const line = await box(page, LINE);
      await expect(page.locator(LINE)).toBeVisible();
      await expect(page.locator(LINE)).toHaveText(LINE_TEXT);
      expect(await page.locator(LINE).evaluate((e) => e.closest('[aria-hidden="true"], [inert]') === null), 'the line is not hidden from a screen reader').toBe(true);
      expect(await page.locator(CONTAINER).ariaSnapshot(), 'the line is in the accessibility tree').toContain(LINE_TEXT);
      const { font, ratio } = await page.locator(LINE).evaluate((e) => {
        const cs = getComputedStyle(e);
        const rgb = (c: string) => (c.match(/[\d.]+/g) ?? []).slice(0, 3).map(Number);
        const lum = (c: string) => {
          const [r, g, b] = rgb(c).map((n) => {
            const s = n / 255;
            return s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
          });
          return 0.2126 * r + 0.7152 * g + 0.0722 * b;
        };
        const [a, b] = [lum(cs.color), lum(cs.backgroundColor)].sort((x, y) => y - x);
        return { font: cs.fontSize, ratio: (a + 0.05) / (b + 0.05) };
      });
      console.log(`SIZE ${width}: panel ${panel.left}+${panel.width}, chat box ${chat.left}+${chat.width}, line ${line.left}+${line.width}x${line.height} at ${font}, contrast ${ratio.toFixed(2)}:1`);
      expect(Math.abs(panel.width - chat.width), 'the panel is as wide as the chat box').toBeLessThanOrEqual(1);
      expect(Math.abs(panel.left - chat.left), 'the panel and the chat box start together').toBeLessThanOrEqual(1);
      expect(Math.abs(line.width - chat.width), 'the line under the input is as wide as the chat box').toBeLessThanOrEqual(1);
      expect(Math.abs(line.left - chat.left), 'the line under the input starts with the chat box').toBeLessThanOrEqual(1);
      expect(chat.left, 'the chat box starts inside the window').toBeGreaterThanOrEqual(0);
      expect(chat.right, 'the chat box ends inside the window').toBeLessThanOrEqual(width);
      expect(font, 'the line under the input is fine print').toBe('12px');
      expect(ratio, 'the line under the input meets AA (4.5:1)').toBeGreaterThanOrEqual(4.5);
    });

    test(`the assistant's name is on one line on the closed bar (${width} px)`, async ({ page }) => {
      const name = page.locator(`${HEADER} .ln-assistant-name`);
      await expect(name).toHaveText('LocalNodes LiAIson');
      const lines = await name.evaluate((e) => {
        const r = document.createRange();
        r.selectNodeContents(e);
        return new Set(Array.from(r.getClientRects()).map((x) => Math.round(x.top))).size;
      });
      const b = await box(page, `${HEADER} .ln-assistant-name`);
      console.log(`SIZE ${width}: name ${b.width}x${b.height}, ${lines} line(s)`);
      expect(lines, 'the name is on one line').toBe(1);
    });

    for (const longName of LONG_NAMES) {
      test(`a long assistant name keeps the bar inside the window (${width} px, ${longName.slice(0, 12)}...)`, async ({ page }) => {
        // The name a site sets is rendered into this span; putting a long one
        // there measures the same layout without changing the site's settings.
        await setName(page, longName);
        for (const state of ['closed', 'open']) {
          if (state === 'open') {
            await page.locator(HEADER).click();
            await expect(page.locator(HEADER)).toHaveAttribute('aria-expanded', 'true');
          }
          await expectNameShows(page, `${width} ${state} ${longName.slice(0, 12)}`, width === 320 && longName === LONG_NAMES[0], longName);
          if (width >= 900) {
            await expectNameOnOneLine(page, `${width} ${state} ${longName.slice(0, 12)}`, longName);
          }
          for (const part of [`${CONTAINER} .ln-chat-bar`, HEADER, `${HEADER} .ln-ai-label`]) {
            const b = await box(page, part);
            console.log(`SIZE ${width} ${state} long name: ${part} ${b.left}..${b.right}`);
            expect(b.left, `${state}: ${part} starts inside the window`).toBeGreaterThanOrEqual(0);
            expect(b.right, `${state}: ${part} ends inside the window`).toBeLessThanOrEqual(width);
          }
        }
      });
    }
  });
}

for (const view of [{ width: 320, height: 568 }, { width: 320, height: 640 }]) {
  test.describe(`the line under the input on a short phone (${view.width}x${view.height})`, () => {
    let guard: AiGuard;
    test.use({ viewport: view });
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

    test(`the line ends inside the open panel and the window (${view.width}x${view.height})`, async ({ page }) => {
      await page.locator(HEADER).click();
      await expect(page.locator(HEADER)).toHaveAttribute('aria-expanded', 'true');
      await expect(page.locator(`${CONTAINER} deep-chat`)).toBeVisible();
      await expect(page.locator(LINE)).toHaveText(LINE_TEXT);
      const rect = (sel: string) => page.locator(sel).first().evaluate((e) => {
        const r = e.getBoundingClientRect();
        return { top: r.top, bottom: r.bottom, height: r.height };
      });
      const panel = await rect(CONTAINER);
      const chat = await rect(`${CONTAINER} deep-chat`);
      const line = await rect(LINE);
      console.log(`SIZE ${view.width}x${view.height}: panel ${panel.top}..${panel.bottom}, chat box ${chat.top}..${chat.bottom}, line ${line.top}..${line.bottom}`);
      expect(line.height, 'the line has height').toBeGreaterThan(0);
      expect(line.top, 'the line starts inside the panel').toBeGreaterThanOrEqual(panel.top - 0.5);
      expect(line.bottom, 'the line ends inside the panel').toBeLessThanOrEqual(panel.bottom + 0.5);
      expect(line.bottom, 'the line ends inside the window').toBeLessThanOrEqual(view.height + 0.5);
      expect(chat.bottom, 'the chat box ends above the line').toBeLessThanOrEqual(line.top + 0.5);
      expect(chat.height, 'the chat box still has room for the input').toBeGreaterThan(150);
    });
  });
}

// The closed bar against the AI overview's Sources links (overview.spec.ts
// checks the same with the default name by hit-testing; this checks boxes,
// with the default and the 40-character name).
for (const view of [{ width: 1280, height: 900 }, { width: 320, height: 640 }]) {
  for (const name of ['LocalNodes LiAIson', LONG_NAMES[0]]) {
    test(`the closed bar meets no Sources link (${view.width} px, ${name.slice(0, 12)}...)`, async ({ page }, testInfo) => {
      test.skip(testInfo.project.name !== 'member', 'the overview and the chat bar show for the member');
      await page.setViewportSize(view);
      const guard = await guardAi(page, async (route, request, fulfil) => {
        const url = new URL(request.url());
        if (url.pathname !== '/api/ai/overview') {
          await route.abort();
          return;
        }
        const q = (url.searchParams.get('q') ?? '').trim().toLowerCase();
        await fulfil(q === 'bioregionalism' ? OVERVIEW_BIOREGIONALISM_RESPONSE : OVERVIEW_NONE_RESPONSE);
      });
      await page.goto('/search/all/bioregionalism');
      const sources = page.getByRole('complementary', { name: 'AI overview' }).locator('ol.ai-overview__source-list a');
      await expect(sources.first()).toBeVisible();
      await expect(page.locator(HEADER)).toHaveAttribute('aria-expanded', 'false');
      await setName(page, name);
      await expectNameShows(page, `${view.width} sources ${name.slice(0, 12)}`, false);
      const meets = () => page.evaluate(([bar, sel]) => {
        const b = document.querySelector(bar)!.getBoundingClientRect();
        return Array.from(document.querySelectorAll(sel)).flatMap((a) => {
          const r = a.getBoundingClientRect();
          const hit = r.left < b.right && r.right > b.left && r.top < b.bottom && r.bottom > b.top;
          return hit ? [`${a.textContent?.trim()} at ${Math.round(r.left)},${Math.round(r.top)} (bar ${Math.round(b.left)}..${Math.round(b.right)}, ${Math.round(b.top)}..${Math.round(b.bottom)})`] : [];
        });
      }, [`${CONTAINER} .ln-chat-bar`, '[data-ai-overview] ol.ai-overview__source-list a'] as const);
      const bar = await box(page, `${CONTAINER} .ln-chat-bar`);
      console.log(`SIZE ${view.width} sources ${name.slice(0, 12)}: bar ${bar.left}..${bar.right} x ${bar.height}`);
      for (let i = 0; i < (await sources.count()); i++) {
        // From the top of the page, focus the element before the link without scrolling, then Tab onto the link.
        await page.evaluate(() => window.scrollTo(0, 0));
        await sources.nth(i).evaluate((a) => {
          const tabbable = Array.from(document.querySelectorAll<HTMLElement>('a[href], button, input, select, textarea, [tabindex]'))
            .filter((el) => el.tabIndex >= 0 && !el.matches(':disabled') && el.getClientRects().length > 0);
          tabbable[tabbable.indexOf(a as HTMLElement) - 1].focus({ preventScroll: true });
        });
        await page.keyboard.press('Tab');
        await expect(sources.nth(i)).toBeFocused();
        await expect.poll(meets, { message: `Tab onto Sources link ${i + 1} from the top of the page`, timeout: 2000 }).toEqual([]);
      }
      for (let i = 0; i < (await sources.count()); i++) {
        await sources.nth(i).evaluate((a) => a.scrollIntoView({ block: 'end' }));
        expect(await meets(), `Sources link ${i + 1} at the window's bottom edge`).toEqual([]);
      }
      assertNoLiveAi(guard);
    });
  }
}
