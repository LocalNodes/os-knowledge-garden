// The home page, against the built site.
//
// Runs in the "onboarding" project against the BUILT sign-up site, not the dev server:
//   npm --prefix ../localnodes-onboarding run build
//   PORT=3000 node ../localnodes-onboarding/.output/server/index.mjs
//   LN_BROWSER_CHANNEL=chromium npx --no-install playwright test -c tests/a11y/playwright.config.ts \
//     --project onboarding landing-v5
// Build with the launch flag closed: while any home page promise in the site's
// shared/launch-claims.mjs is pending, a build with the flag open fails. "/" is
// prerendered, so the flag is baked into it; with the launch closed the isolation
// check goes to /invite, which is open either way.
//
// What it holds:
//   - axe (WCAG 2.2 AA tags) on "/" at 1440 and 400 in the finished state, with the mobile menu
//     and the group-kind list open; JSON saved to landing-build/;
//   - keyboard: Tab order through the header, hero, switcher, Pause, Play again buttons, the form,
//     the demo link and the footer; every stop has a focus ring of at least 3:1 against its surface;
//   - 400 px: no sideways scroll, and the hero window's pieces do not overlap;
//   - reduced motion: no typing, finished states, Pause and Play again hidden but holding space;
//   - JavaScript off: the finished states and the form are there;
//   - navigation isolation (R5): "/" then a sign-up page by client navigation removes ln-home and
//     leaves the sign-up page's primary colour alone; back and forward bring "/" back;
//   - CLS (R8) over a full hero cycle and each act, at 1440 and 400, from the load;
//   - screenshots and the contrast table (landing-build/CONTRAST.md) from computed styles.
// No live calls: the early-access form is never submitted here.
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { AXE_TAGS, checkA11y } from './helpers/axe';
import { open, requireOnboardingSite } from './helpers/onboarding-mocks';

const HERE = dirname(fileURLToPath(import.meta.url));
const BUILD_DIR = join(HERE, '..', '..', '.planning', 'phases', '23-self-serve-onboarding', 'landing-build');
const SCAN = { resultsDir: BUILD_DIR };
mkdirSync(BUILD_DIR, { recursive: true });

const WIDE = { width: 1440, height: 900 };
const PHONE = { width: 400, height: 860 };
// #0f766e, the sign-up pages' primary since the app took the landing page's teal (it was #176c93).
// Until the sign-up site regenerates ln-tokens.css from the token source, the checks below that use it fail.
const TEAL_LIGHT = 'rgb(15, 118, 110)';

test.beforeAll(async () => {
  await requireOnboardingSite(test.skip);
});

/** Opens "/" and waits until the page is live (the demos have been started). */
async function openHome(page: Page) {
  await open(page, '/');
  await expect(page.getByRole('heading', { level: 1 })).toContainText('Where your community shares, decides and funds');
  await page.waitForSelector('.home-page.home-live');
}

/** Stops the demos where they are, so a scan sees a still picture. */
async function holdStill(page: Page) {
  await page.getByRole('button', { name: /^Pause/ }).click();
  await page.waitForTimeout(1200); // a transition already running may finish
}

/** Contrast of colour a over colour b (both 'rgb(...)' or 'rgba(...)'), b opaque. */
const CONTRAST_FN = `(() => {
  const parse = (s) => { const m = s.match(/[\\d.]+/g).map(Number); return { r: m[0], g: m[1], b: m[2], a: m.length > 3 ? m[3] : 1 }; };
  const lin = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
  const lum = (c) => 0.2126 * lin(c.r) + 0.7152 * lin(c.g) + 0.0722 * lin(c.b);
  const over = (f, b) => ({ r: f.r * f.a + b.r * (1 - f.a), g: f.g * f.a + b.g * (1 - f.a), b: f.b * f.a + b.b * (1 - f.a), a: 1 });
  const surface = (el) => {
    const stack = [];
    for (let n = el; n; n = n.parentElement) {
      const cs = getComputedStyle(n);
      if (cs.backgroundImage !== 'none') return { image: true, color: null };
      const c = parse(cs.backgroundColor);
      if (c.a > 0) stack.push(c);
      if (c.a === 1) break;
    }
    let base = { r: 255, g: 255, b: 255, a: 1 };
    for (let i = stack.length - 1; i >= 0; i--) base = over(stack[i], base);
    return { image: false, color: base };
  };
  const ratio = (fg, bg) => { const f = fg.a < 1 ? over(fg, bg) : fg; const a = lum(f), b = lum(bg); return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05); };
  const hex = (c) => '#' + [c.r, c.g, c.b].map((v) => Math.round(v).toString(16).padStart(2, '0')).join('');
  return { parse, surface, ratio, hex };
})()`;

interface Stop {
  name: string;
  tag: string;
  ring: string;
  ratio: number | null;
  surface: string;
  at: string;
}

/** Presses Tab from the top and records every stop with its focus ring and the ring's contrast. */
async function walkRings(page: Page, cap = 80): Promise<Stop[]> {
  await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
  await page.evaluate(() => window.scrollTo(0, 0));
  const stops: Stop[] = [];
  const first = new Set<string>();
  for (let i = 0; i < cap; i++) {
    await page.keyboard.press('Tab');
    await page.waitForTimeout(220); // transitions on the ring finish
    const stop = await page.evaluate((fnSrc) => {
      const el = document.activeElement as HTMLElement | null;
      if (!el || el === document.body) return null;
      const { parse, surface, ratio, hex } = (0, eval)(fnSrc);
      const cs = getComputedStyle(el);
      const name = (el.getAttribute('aria-label') || el.innerText || el.getAttribute('placeholder') || el.id || el.tagName).trim().replace(/\s+/g, ' ').slice(0, 60);
      let ringColor: string | null = null;
      let ring = 'none';
      if (cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0) {
        ringColor = cs.outlineColor;
        ring = `outline ${cs.outlineWidth} ${cs.outlineStyle} ${hex(parse(cs.outlineColor))}`;
      } else if (cs.boxShadow !== 'none') {
        const m = cs.boxShadow.match(/rgba?\([^)]*\)/g);
        const wide = cs.boxShadow.split(/rgba?\([^)]*\)/).join('|');
        if (m && m.length) {
          // the last coloured shadow is the ring on Nuxt UI (ring-2 ring-inverted etc.)
          ringColor = m[m.length - 1];
          ring = `box-shadow ${cs.boxShadow} ${wide ? '' : ''}`.trim();
        }
      }
      // the surface the ring sits on is the one outside the element for an outline/offset
      const host = el.parentElement ?? el;
      const s = surface(host);
      let r: number | null = null;
      let sc = 'image';
      if (ringColor && !s.image && s.color) {
        r = ratio(parse(ringColor), s.color);
        sc = hex(s.color);
      }
      const box = el.getBoundingClientRect();
      return { name, tag: el.tagName.toLowerCase(), ring, ratio: r === null ? null : Math.round(r * 100) / 100, surface: sc, at: `${Math.round(box.left)},${Math.round(box.top + scrollY)}` };
    }, CONTRAST_FN);
    if (!stop) break;
    const key = `${stop.tag}|${stop.name}|${stop.at}`;
    if (first.has(key)) break;
    first.add(key);
    stops.push(stop);
  }
  return stops;
}

// --- axe --------------------------------------------------------------------

for (const [label, size] of [['1440', WIDE], ['400', PHONE]] as const) {
  test.describe(`home page at ${label}`, () => {
    test.use({ viewport: size });

    test('axe: finished state, light first window', async ({ page }) => {
      await page.emulateMedia({ reducedMotion: 'reduce' }); // the finished state, still
      await openHome(page);
      await checkA11y(page, 'landing-v5', `${label}-finished`, SCAN);
    });

    test('axe: the demos paused mid-run', async ({ page }) => {
      await openHome(page);
      await page.waitForTimeout(5000);
      await holdStill(page);
      expect(await page.evaluate(() => document.documentElement.scrollWidth), 'scrollWidth while the demos play').toBeLessThanOrEqual(size.width);
      await checkA11y(page, 'landing-v5', `${label}-paused`, SCAN);
    });

    test('axe: the group-kind list open on the dark close', async ({ page }) => {
      await page.emulateMedia({ reducedMotion: 'reduce' });
      await openHome(page);
      await page.getByLabel('What kind of group?').scrollIntoViewIfNeeded();
      await page.getByLabel('What kind of group?').click();
      await expect(page.getByRole('listbox')).toBeVisible();
      // Two axe rules are left out of the pass/fail here, and only these two: `aria-hidden-focus` and
      // `scrollable-region-focusable`. Both come from Reka UI (under Nuxt UI's USelect): it puts
      // aria-hidden on everything outside the open list and leaves the list's scroll box without a
      // focusable child (it moves focus with aria-activedescendant). They still run and stay in the
      // saved JSON. Every other rule must pass; do not add a rule to this list.
      const results = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
      writeFileSync(join(BUILD_DIR, `axe-landing-v5-${label}-select-open.json`), JSON.stringify(results, null, 2) + '\n');
      const LIBRARY_RULES = ['aria-hidden-focus', 'scrollable-region-focusable'];
      const blocking = results.violations.filter((v) => (v.impact === 'serious' || v.impact === 'critical') && !LIBRARY_RULES.includes(v.id)).map((v) => ({ id: v.id, targets: v.nodes.map((n) => n.target) }));
      expect(blocking, `axe serious/critical violations with the group-kind list open (${label})`).toEqual([]);
    });

    test('no sideways scroll, and no overlap among the hero window pieces', async ({ page }) => {
      await page.emulateMedia({ reducedMotion: 'reduce' });
      await openHome(page);
      const wide = await page.evaluate(() => document.documentElement.scrollWidth);
      expect(wide, 'scrollWidth').toBeLessThanOrEqual(size.width);
      const rects = await page.evaluate(() => {
        const r = (sel: string) => {
          const el = document.querySelector(sel);
          if (!el) return null;
          const b = el.getBoundingClientRect();
          return { top: b.top + scrollY, bottom: b.bottom + scrollY, left: b.left, right: b.right };
        };
        return {
          hero: r('#think'),
          next: r('#think + section'),
          nextHead: r('.home-scatter p'),
          q: r('.home-q.is-cur'),
          a: r('.home-ans-p.is-cur'),
          cite: r('.home-cite.is-cur'),
          composer: r('.home-composer'),
          paper: r('.home-paper'),
          stage: r('.home-stage')
        };
      });
      const { hero, nextHead, q, a, cite, composer, paper } = rects;
      for (const [n, v] of Object.entries({ hero, nextHead, q, a, cite, composer, paper })) expect(v, n).not.toBeNull();
      const overlap = (x: NonNullable<typeof q>, y: NonNullable<typeof q>) =>
        x.left < y.right - 0.5 && y.left < x.right - 0.5 && x.top < y.bottom - 0.5 && y.top < x.bottom - 0.5;
      const pieces = { q: q!, a: a!, cite: cite!, composer: composer! };
      const names = Object.keys(pieces) as (keyof typeof pieces)[];
      for (let i = 0; i < names.length; i++) {
        for (let j = i + 1; j < names.length; j++) {
          expect(overlap(pieces[names[i]!], pieces[names[j]!]), `${names[i]} overlaps ${names[j]}`).toBe(false);
        }
      }
      // The source paper overhangs the window's bottom-right corner on purpose (prototype: right -22px,
      // bottom -78px), so it may cover part of the composer. It must not cover the question, the
      // answer or the citation.
      for (const n of ['q', 'a', 'cite'] as const) expect(overlap(pieces[n], paper!), `${n} overlaps the source paper`).toBe(false);
      expect(paper!.bottom, 'the source paper ends inside the hero').toBeLessThanOrEqual(hero!.bottom + 0.5);
      expect(paper!.bottom, 'the source paper stays clear of the next band content').toBeLessThanOrEqual(nextHead!.top);
    });
  });
}

test.describe('mobile menu', () => {
  test.use({ viewport: PHONE });

  test('axe: the menu open at 400, dark like the header', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await openHome(page);
    await page.getByRole('button', { name: /menu/i }).first().click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.waitForTimeout(600);
    const bg = await page.getByRole('dialog').evaluate((e) => getComputedStyle(e).backgroundColor);
    expect(bg, 'the menu panel is dark').toMatch(/^rgb\((9|10|24), /);
    await checkA11y(page, 'landing-v5', '400-menu-open', SCAN);
  });
});

// --- keyboard ---------------------------------------------------------------

test.describe('keyboard', () => {
  test.use({ viewport: WIDE });

  test('Tab reaches the header, hero, switcher, Pause, Play again buttons, demo link, form and footer, each with a visible ring', async ({ page }) => {
    await openHome(page);
    const stops = await walkRings(page, 90);
    writeFileSync(join(BUILD_DIR, 'focus-rings-1440.json'), JSON.stringify(stops, null, 2) + '\n');
    const names = stops.map((s) => s.name);
    expect(names[0], 'the first stop is the skip link').toMatch(/Skip to main content/i);
    const at = (re: RegExp) => names.findIndex((n) => re.test(n));
    const order = [
      /Skip to main content/i,
      /How it works/,
      /Open source/,
      /Demo sites/,
      /Get early access/, // header button
      /^Get early access$/, // hero (second occurrence checked below)
      /See how it works/,
      /Co-op|housing co-op|neighborhood|guild|land trust/i, // switcher
      /^Pause/,
      /Play again/,
      /Look around the Boulder demo site/,
      /Email|you@example\.org/
    ];
    let last = -1;
    for (const re of order) {
      const idx = names.findIndex((n, i) => i > last && re.test(n));
      expect(idx, `a stop matching ${re} after position ${last}`).toBeGreaterThan(last);
      last = idx;
    }
    expect(at(/Play again/), 'Play again is reachable').toBeGreaterThan(-1);
    expect(names.filter((n) => /Play again/.test(n)).length, 'four Play again buttons').toBe(4);
    for (const kind of ['neighborhood group', 'pop-up village', 'housing co-op', 'guild']) expect(names, `the switcher button ${kind}`).toContain(kind);
    const bad = stops.filter((s) => s.ring === 'none' || (s.ratio !== null && s.ratio < 3));
    expect(bad, 'every stop has a focus ring of at least 3:1 against its surface').toEqual([]);
    const last3 = names.slice(-3).join(' | ');
    expect(last3, 'the walk ends in the footer').toMatch(/Terms|Privacy|Unsubscribe|Roadmap|LocalNodes|Contact|Source/i);
  });
});

// --- reduced motion ---------------------------------------------------------

test.describe('reduced motion', () => {
  test.use({ viewport: WIDE, colorScheme: 'light' });

  test('no typing, finished states, Pause and Play again hidden but holding space', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await openHome(page);
    await page.waitForTimeout(3000);
    const composer = await page.locator('.home-typed').innerText();
    expect(composer, 'the composer shows its placeholder, nothing typed').toMatch(/Ask/i);
    await expect(page.locator('.home-typed')).toHaveClass(/ph/);
    for (const sel of ['.home-pause', '.home-again']) {
      const els = page.locator(sel);
      const n = await els.count();
      expect(n, sel).toBeGreaterThan(0);
      for (let i = 0; i < n; i++) {
        const st = await els.nth(i).evaluate((e) => ({ v: getComputedStyle(e).visibility, d: getComputedStyle(e).display, h: e.getBoundingClientRect().height }));
        expect(st.v, `${sel} #${i} visibility`).toBe('hidden');
        expect(st.d, `${sel} #${i} still takes its space`).not.toBe('none');
        expect(st.h, `${sel} #${i} height`).toBeGreaterThan(0);
      }
    }
    // finished states: every word of the current answer is in, the citation and source are on
    const words = await page.evaluate(() => {
      const ws = [...document.querySelectorAll('.home-ans-p.is-cur .w')];
      return { all: ws.length, shown: ws.filter((w) => w.classList.contains('in')).length };
    });
    expect(words.shown).toBe(words.all);
    await expect(page.locator('.home-cite.is-cur')).not.toHaveClass(/off/);
    await expect(page.locator('.home-paper')).not.toHaveClass(/off/);
    await page.screenshot({ path: join(BUILD_DIR, 'home-reduced-motion-1440.png'), fullPage: true });
  });
});

// --- JavaScript off ---------------------------------------------------------

test.describe('JavaScript off', () => {
  test.use({ viewport: WIDE, javaScriptEnabled: false });

  test('the finished states and the form are in the page, and the class and teal are there', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Where your community shares, decides and funds');
    expect(await page.evaluate(() => document.documentElement.classList.contains('ln-home'))).toBe(true);
    // the teal: the page's primary is the home teal (#0f766e), the same as the sign-up pages', and the
    // hero button takes the dark band's pale mint, both without a script
    expect(await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--ui-primary').trim())).toBe('#0f766e');
    expect(await page.locator('.home-row a').first().evaluate((e) => getComputedStyle(e).backgroundColor)).toBe('rgb(204, 251, 241)');
    await expect(page.locator('.home-alt').first()).toBeAttached();
    await expect(page.locator('dl [data-demo-amount-box]').first()).toBeAttached();
    await expect(page.getByLabel('Email')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Get early access' }).last()).toBeVisible();
    await expect(page.getByRole('link', { name: /Look around the Boulder demo site/ })).toBeVisible();
    // words of the answer are in, the source paper is on, nothing waits for a script
    const state = await page.evaluate(() => {
      const ws = [...document.querySelectorAll('.home-ans-p.is-cur .w')];
      return { all: ws.length, shown: ws.filter((w) => w.classList.contains('in')).length, paperOff: document.querySelector('.home-paper')?.classList.contains('off') };
    });
    expect(state.shown).toBe(state.all);
    expect(state.paperOff).toBe(false);
    const hidden = await page.locator('.home-needs-js').first().evaluate((e) => getComputedStyle(e).visibility);
    expect(hidden, 'script-only controls stay out of sight without a script').toBe('hidden');
  });
});

// --- navigation isolation (R5) ----------------------------------------------

test.describe('navigation isolation', () => {
  test.use({ viewport: WIDE });

  test('"/" then a sign-up page by client navigation removes ln-home and keeps the sign-up primary; back and forward restore "/"', async ({ page }) => {
    await openHome(page);
    const primary = () => page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--ui-primary').trim());
    const homeClass = () => page.evaluate(() => document.documentElement.classList.contains('ln-home'));
    expect(await homeClass(), 'ln-home on "/"').toBe(true);
    // /invite is open whether or not the launch is open; /onboarding redirects there when it is closed.
    await page.evaluate(async () => {
      const nuxt = (document.querySelector('#__nuxt') as unknown as { __vue_app__: { config: { globalProperties: { $nuxt: { $router: { push: (p: string) => Promise<unknown> } } } } } }).__vue_app__.config.globalProperties.$nuxt;
      await nuxt.$router.push('/invite');
    });
    await expect(page.getByRole('heading', { level: 1, name: 'Use Your Invite' })).toBeVisible();
    expect(await homeClass(), 'ln-home gone on /invite').toBe(false);
    const other = await page.evaluate(() => getComputedStyle(document.querySelector('button[type="submit"], a[class*="bg-primary"], button') as Element).backgroundColor);
    const ref = await primary();
    // the sign-up page's primary button is #0f766e (light)
    await page.reload();
    await expect(page.getByRole('heading', { level: 1, name: 'Use Your Invite' })).toBeVisible();
    const fresh = await primary();
    expect(ref, 'primary after client navigation equals primary on a direct load').toBe(fresh);
    const btn = await page.getByRole('button', { name: 'Send my sign-in link' }).evaluate((e) => getComputedStyle(e).backgroundColor);
    expect(btn).toBe(TEAL_LIGHT);
    void other;
    // direct load, then the other way round: "/" -> /invite (client) -> back -> forward
    await openHome(page);
    await page.evaluate(async () => {
      const nuxt = (document.querySelector('#__nuxt') as unknown as { __vue_app__: { config: { globalProperties: { $nuxt: { $router: { push: (p: string) => Promise<unknown> } } } } } }).__vue_app__.config.globalProperties.$nuxt;
      await nuxt.$router.push('/invite');
    });
    await expect(page.getByRole('heading', { level: 1, name: 'Use Your Invite' })).toBeVisible();
    // The button eases between colours, so wait for it to settle; it must end on #0f766e.
    await expect.poll(
      () => page.getByRole('button', { name: 'Send my sign-in link' }).evaluate((e) => getComputedStyle(e).backgroundColor),
      { message: 'the sign-up button is still #0f766e after leaving "/" by client navigation', timeout: 3000 }
    ).toBe(TEAL_LIGHT);
    await page.goBack();
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Where your community shares');
    expect(await homeClass(), 'ln-home back on "/"').toBe(true);
    await page.waitForSelector('.home-page.home-live');
    await page.goForward();
    await expect(page.getByRole('heading', { level: 1, name: 'Use Your Invite' })).toBeVisible();
    expect(await homeClass(), 'ln-home gone again going forward').toBe(false);
  });
});

// --- CLS (R8) ---------------------------------------------------------------

type ClsEntry = { v: number; t: number; src: string[] };

// The shift's sources, named so a run says what moved. An SVG's className is an SVGAnimatedString, so
// read className.baseVal (printing it gave "[object SVGAnimatedString]" and hid which SVG it was).
const CLS_OBSERVER = () => {
  const w = window as unknown as { __cls: { total: number; entries: ClsEntry[] } };
  w.__cls = { total: 0, entries: [] };
  const name = (n: Node | undefined): string => {
    if (!n) return '?';
    const el = n as Element;
    const cn = (el.className as unknown as { baseVal?: string } | string | undefined);
    const cls = typeof cn === 'string' ? cn : cn?.baseVal ?? '';
    return `${n.nodeName.toLowerCase()}.${cls}`.slice(0, 80);
  };
  new PerformanceObserver((list) => {
    for (const e of list.getEntries() as unknown as { value: number; hadRecentInput: boolean; startTime: number; sources?: { node?: Node }[] }[]) {
      if (e.hadRecentInput) continue;
      w.__cls.total += e.value;
      w.__cls.entries.push({ v: e.value, t: Math.round(e.startTime), src: (e.sources ?? []).map((s) => name(s.node)) });
    }
  }).observe({ type: 'layout-shift', buffered: true });
};

for (const [label, size] of [['1440', WIDE], ['400', PHONE]] as const) {
  test.describe(`layout shift at ${label}`, () => {
    test.use({ viewport: size });

    test(`total CLS stays at or under 0.01 across the load, a full hero cycle and each act`, async ({ page }) => {
      test.setTimeout(240_000);
      await page.addInitScript(CLS_OBSERVER);
      await page.goto('/');
      await page.waitForSelector('.home-page.home-live');
      await page.evaluate(() => document.fonts.ready);
      const read = () => page.evaluate(() => (window as unknown as { __cls: { total: number; entries: ClsEntry[] } }).__cls);
      const marks: Record<string, number> = {};
      await page.waitForTimeout(20_000);
      marks.load_plus_20s = (await read()).total;
      await page.waitForTimeout(52_000); // one complete four-example cycle is about 70 s
      marks.hero_cycle = (await read()).total;
      for (const id of ['decide', 'fund', 'yours']) {
        await page.locator(`#${id}`).scrollIntoViewIfNeeded();
        await page.waitForTimeout(500);
        await page.evaluate((sel) => document.querySelector(sel)?.scrollIntoView({ block: 'center' }), `#${id}`);
        await page.waitForTimeout(24_000);
        marks[id] = (await read()).total;
      }
      await page.locator('#early-access').scrollIntoViewIfNeeded();
      await page.waitForTimeout(1500);
      const final = await read();
      marks.final = final.total;
      writeFileSync(join(BUILD_DIR, `cls-${label}.json`), JSON.stringify({ viewport: size, marks, entries: final.entries }, null, 2) + '\n');
      console.log(`CLS ${label}: ${JSON.stringify(marks)}`);
      expect(final.total, `CLS ${label}: ${JSON.stringify(final.entries)}`).toBeLessThanOrEqual(0.01);
    });

    // One run on a slow machine and a slow line, the case that once recorded 0.1445 at 1440 from a
    // single shift. The cause was the web font arriving after the first paint: Montserrat is up to 10%
    // wider than the fallback, so the "Show me a" buttons dropped a row, the hero grew by 68px and the
    // hero window (centred in its row) slid with it (the SVG rings were only moving along with it).
    // The hero rows now break at fixed places. The words of the answer still re-wrap when the font
    // lands, which no layout can prevent, so the bar here is the Core Web Vitals "good" line (0.1),
    // not the 0.01 of the fast run. The sources are saved so a rerun shows what moved.
    test(`CLS stays under 0.1 with the CPU slowed 6x and a slow network, and the sources are recorded`, async ({ page, context }) => {
      test.setTimeout(120_000);
      await page.addInitScript(CLS_OBSERVER);
      const cdp = await context.newCDPSession(page);
      await cdp.send('Emulation.setCPUThrottlingRate', { rate: 6 });
      await cdp.send('Network.enable');
      await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 150, downloadThroughput: (1.6 * 1024 * 1024) / 8, uploadThroughput: (750 * 1024) / 8 });
      await page.goto('/', { waitUntil: 'load', timeout: 90_000 });
      await page.waitForSelector('.home-page.home-live', { timeout: 90_000 });
      await page.waitForTimeout(8000);
      const res = await page.evaluate(() => (window as unknown as { __cls: { total: number; entries: ClsEntry[] } }).__cls);
      writeFileSync(join(BUILD_DIR, `cls-${label}-throttled.json`), JSON.stringify({ viewport: size, cpuThrottle: 6, network: '1.6 Mbit/s, 150 ms', total: res.total, entries: res.entries }, null, 2) + '\n');
      console.log(`CLS ${label} throttled: total ${res.total}; ${JSON.stringify(res.entries.filter((e) => e.v > 0.001))}`);
      expect(res.total, `CLS ${label} throttled: ${JSON.stringify(res.entries)}`).toBeLessThanOrEqual(0.1);
    });
  });
}

// --- screenshots ------------------------------------------------------------

test.describe('screenshots', () => {
  test('home-1440.png', async ({ page }) => {
    await page.setViewportSize(WIDE);
    await openHome(page);
    await page.waitForTimeout(9000); // the first answer, citation and source are in
    await page.screenshot({ path: join(BUILD_DIR, 'home-1440.png'), fullPage: true });
  });
  test('home-400.png', async ({ page }) => {
    await page.setViewportSize(PHONE);
    await openHome(page);
    await page.waitForTimeout(9000);
    await page.screenshot({ path: join(BUILD_DIR, 'home-400.png'), fullPage: true });
  });
});

// --- the contrast table -------------------------------------------------------

test.describe('contrast table', () => {
  test.use({ viewport: WIDE });

  test('every text pair on the page and the non-text pairs, from computed styles, written to CONTRAST.md', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await openHome(page);
    interface Row { where: string; fg: string; bg: string; size: number; bold: boolean; ratio: number | null; need: number; sample: string; note: string }
    const text: Row[] = await page.evaluate((fnSrc) => {
      const { parse, surface, ratio, hex } = (0, eval)(fnSrc);
      const rows: Row[] = [];
      const seen = new Set<string>();
      const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
      for (let node = walker.nextNode(); node; node = walker.nextNode()) {
        const t = (node.textContent ?? '').trim();
        const el = node.parentElement;
        if (!t || !el || el.closest('script,style,noscript,.sr-only,.home-alt,[data-nuxt-devtools]')) continue;
        const cs = getComputedStyle(el);
        if (cs.visibility === 'hidden' || cs.display === 'none') continue;
        const box = el.getBoundingClientRect();
        if (!box.width || !box.height) continue;
        let alpha = 1;
        for (let n: Element | null = el; n; n = n.parentElement) alpha *= parseFloat(getComputedStyle(n).opacity);
        if (alpha === 0) continue;
        const section = el.closest('header, footer, section, aside') as HTMLElement | null;
        const where = !section ? 'page' : section.tagName === 'SECTION' ? (section.id ? `#${section.id}` : section.className.split(' ')[0]) : section.tagName.toLowerCase();
        const size = parseFloat(cs.fontSize);
        const bold = parseInt(cs.fontWeight, 10) >= 700;
        const fg = parse(cs.color);
        fg.a *= alpha;
        const s = surface(el);
        let note = '';
        let r: number | null = null;
        let bgHex = 'image';
        const clip = (cs as unknown as { webkitBackgroundClip?: string }).webkitBackgroundClip === 'text' || cs.backgroundClip === 'text';
        if (clip) note = 'gradient text, measured per stop below';
        else if (s.image || !s.color) note = 'sits on a gradient or image';
        else { r = Math.round(ratio(fg, s.color) * 100) / 100; bgHex = hex(s.color); }
        const large = size >= 24 || (size >= 18.66 && bold);
        const need = large ? 3 : 4.5;
        const key = [where, hex(fg), bgHex, size, bold, note].join('|');
        if (seen.has(key)) continue;
        seen.add(key);
        rows.push({ where, fg: hex(fg) + (fg.a < 1 ? ` @${Math.round(fg.a * 100)}%` : ''), bg: bgHex, size, bold, ratio: r, need, sample: t.slice(0, 42), note });
      }
      return rows;
    }, CONTRAST_FN);
    // the gradient text: measure the two stops against the hero surface
    const grad = await page.evaluate((fnSrc) => {
      const { parse, surface, ratio, hex } = (0, eval)(fnSrc);
      const el = document.querySelector('.home-grad') as HTMLElement;
      const bg = surface(el.parentElement as Element);
      const img = getComputedStyle(el).backgroundImage;
      const stops = (img.match(/rgba?\([^)]*\)/g) ?? []).map((c) => ({ c: hex(parse(c)), r: bg.color ? Math.round(ratio(parse(c), bg.color) * 100) / 100 : null }));
      return { bg: bg.color ? hex(bg.color) : 'image', stops, size: parseFloat(getComputedStyle(el).fontSize) };
    }, CONTRAST_FN);
    const nonText = await page.evaluate((fnSrc) => {
      const { parse, surface, ratio, hex } = (0, eval)(fnSrc);
      const out: { what: string; fg: string; bg: string; ratio: number | null }[] = [];
      // Nuxt UI draws a field's or an outline button's edge as an inset ring (box-shadow), not a border,
      // so when the border is 0 wide the first coloured shadow is the edge.
      const edge = (what: string, sel: string, prop: 'borderTopColor' | 'backgroundColor') => {
        const el = document.querySelector(sel) as HTMLElement | null;
        if (!el) { out.push({ what: `${what} (not found)`, fg: '', bg: '', ratio: null }); return; }
        const cs = getComputedStyle(el);
        let colour = cs[prop];
        let how = '';
        if (prop === 'borderTopColor' && parseFloat(cs.borderTopWidth) === 0) {
          const ring = (cs.boxShadow.match(/rgba?\([^)]*\)/g) ?? []).find((c) => parse(c).a > 0);
          if (ring) { colour = ring; how = ' [inset ring]'; }
        }
        const c = parse(colour);
        const s = surface(el.parentElement as Element);
        out.push({ what: what + how, fg: hex(c), bg: s.color ? hex(s.color) : 'image', ratio: s.color ? Math.round(ratio(c, s.color) * 100) / 100 : null });
      };
      edge('email input border on the dark close', '#early-access input[type="email"]', 'borderTopColor');
      edge('community input border on the dark close', '#early-access input[autocomplete="organization"]', 'borderTopColor');
      edge('group-kind select border on the dark close', '#early-access button[role="combobox"]', 'borderTopColor');
      edge('"See how it works" outline button border (dark hero)', '#think a[href="#decide"]', 'borderTopColor');
      edge('switcher chip border, unselected (dark hero)', '#think .home-who button[aria-pressed="false"]', 'borderTopColor');
      edge('"Launching soon" pill border (dark hero)', '#think .home-soon', 'borderTopColor');
      edge('Get early access button fill (dark hero)', '#think a[href="#early-access"].inline-flex, #think a[href="#early-access"][class*="bg-"]', 'backgroundColor');
      edge('Download button fill (light band)', '#yours .home-xbtn', 'backgroundColor');
      // The highlighted words on the source paper are text on a highlight, which the text scan
      // reads as "image" (the highlight is a background-image so it can sweep in). Needs 4.5 (text).
      const mark = document.querySelector('.home-paper.lit mark, .home-paper mark') as HTMLElement | null;
      const hl = getComputedStyle(document.querySelector('.home-paper') as Element).getPropertyValue('--home-mark').trim();
      if (mark && /^#[0-9a-f]{6}$/i.test(hl)) {
        const bg = parse(`rgb(${parseInt(hl.slice(1, 3), 16)}, ${parseInt(hl.slice(3, 5), 16)}, ${parseInt(hl.slice(5, 7), 16)})`);
        out.push({ what: 'Source paper highlighted words, ink on highlight (text, needs 4.5)', fg: hex(parse(getComputedStyle(mark).color)), bg: hex(bg), ratio: Math.round(ratio(parse(getComputedStyle(mark).color), bg) * 100) / 100 });
      } else out.push({ what: 'Source paper highlight (not found)', fg: '', bg: '', ratio: null });
      return out;
    }, CONTRAST_FN);
    const rings = await walkRings(page, 90);
    const ringBySurface = new Map<string, { ring: string; ratio: number | null; n: number; sample: string }>();
    for (const r of rings) {
      const k = `${r.surface}|${r.ring}`;
      const cur = ringBySurface.get(k);
      if (cur) cur.n++; else ringBySurface.set(k, { ring: r.ring, ratio: r.ratio, n: 1, sample: r.name });
    }
    // R6: hover and pressed states, measured with the pointer on the control.
    const controls: [string, string][] = [
      ['header "Get early access" (mint button)', 'header a[href$="#early-access"]'],
      ['hero "Get early access" (mint button)', '.home-row a >> nth=0'],
      ['hero "See how it works" (outline button)', '.home-row a >> nth=1'],
      ['header nav link "How it works"', 'header a[href$="#together"]'],
      ['switcher chip, not selected', '.home-who button[aria-pressed="false"] >> nth=0'],
      ['switcher chip, selected', '.home-who button[aria-pressed="true"]'],
      ['Pause', '.home-pause'],
      ['"Play again" (light band)', '#decide .home-again'],
      ['close form "Get early access" (mint button)', '#early-access button[type="submit"]'],
      ['demo link on the dark close', '#demo'],
      ['"Get early access" link in the pill', '#think .home-soon a']
    ];
    const probe = (loc: ReturnType<Page['locator']>) => loc.evaluate((e, fnSrc) => {
      const { parse, surface, ratio, hex } = (0, eval)(fnSrc);
      const cs = getComputedStyle(e);
      const s = surface(e);
      const fg = parse(cs.color);
      return { fg: hex(fg), bg: s.color ? hex(s.color) : 'image', ratio: s.color ? Math.round(ratio(fg, s.color) * 100) / 100 : null };
    }, CONTRAST_FN);
    const stateRows: { control: string; state: string; fg: string; bg: string; ratio: number | null }[] = [];
    // Pause and Play again are hidden for reduced motion, so the pointer states are measured with motion on.
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    await page.waitForSelector('.home-page.home-live:not(.home-reduced)');
    await page.evaluate(() => window.scrollTo(0, 0));
    for (const [control, sel] of controls) {
      const loc = page.locator(sel).first();
      await loc.scrollIntoViewIfNeeded();
      await page.mouse.move(2, 2);
      await page.waitForTimeout(350);
      stateRows.push({ control, state: 'rest', ...(await probe(loc)) });
      await loc.hover();
      await page.waitForTimeout(450);
      stateRows.push({ control, state: 'hover', ...(await probe(loc)) });
      await page.mouse.down();
      await page.waitForTimeout(450);
      stateRows.push({ control, state: 'pressed', ...(await probe(loc)) });
      await page.mouse.move(2, 2); // leaving before the release cancels the click
      await page.mouse.up();
      await page.waitForTimeout(100);
    }
    const pass = (r: number | null, need: number) => (r === null ? 'see note' : r >= need ? 'pass' : 'FAIL');
    const lines: string[] = [];
    lines.push('# Contrast table, home page', '');
    lines.push('Measured from computed styles in Chromium on the built page at 1440 px, finished state (landing-v5.spec.ts, "contrast table").');
    lines.push('Text needs 4.5:1 (3:1 when 24 px or larger, or 18.66 px and bold); non-text parts and focus rings need 3:1. The background is the');
    lines.push('colour of the nearest opaque ancestor, composited; text with opacity under 1 is composited over it. One row per colour pair per section.', '');
    lines.push('## Text', '', '| Where | Text colour | On | Size | Ratio | Needs | Result | Sample | Note |', '|---|---|---|---|---|---|---|---|---|');
    for (const r of text) lines.push(`| ${r.where} | ${r.fg} | ${r.bg} | ${r.size}px${r.bold ? ' bold' : ''} | ${r.ratio ?? ''} | ${r.need} | ${pass(r.ratio, r.need)} | ${r.sample.replace(/\|/g, '/')} | ${r.note} |`);
    lines.push('', '## Gradient text ("together.")', '', `On ${grad.bg}, ${grad.size}px (large, needs 3:1):`, '', '| Stop | Ratio | Result |', '|---|---|---|');
    for (const g of grad.stops) lines.push(`| ${g.c} | ${g.r} | ${pass(g.r, 3)} |`);
    lines.push('', '## Non-text', '', '| Part | Colour | On | Ratio | Needs | Result |', '|---|---|---|---|---|---|');
    for (const n of nonText) lines.push(`| ${n.what} | ${n.fg} | ${n.bg} | ${n.ratio ?? ''} | 3 | ${pass(n.ratio, 3)} |`);
    lines.push('', '## Button and link states (R6): label colour on the fill, at rest, hover and pressed', '', '| Control | State | Label | On | Ratio | Needs | Result |', '|---|---|---|---|---|---|---|');
    for (const st of stateRows) lines.push(`| ${st.control} | ${st.state} | ${st.fg} | ${st.bg} | ${st.ratio ?? ''} | 4.5 | ${pass(st.ratio, 4.5)} |`);
    lines.push('', '## Focus rings (every Tab stop, found by pressing Tab through the page)', '', '| Surface | Ring | Ratio | Stops | Example | Result |', '|---|---|---|---|---|---|');
    for (const [k, v] of ringBySurface) lines.push(`| ${k.split('|')[0]} | ${v.ring} | ${v.ratio ?? ''} | ${v.n} | ${v.sample.replace(/\|/g, '/')} | ${pass(v.ratio, 3)} |`);
    writeFileSync(join(BUILD_DIR, 'CONTRAST.md'), lines.join('\n') + '\n');
    const fails = [
      ...stateRows.filter((r) => r.ratio !== null && r.ratio < 4.5).map((r) => `state ${r.control} ${r.state} ${r.ratio}`),
      ...text.filter((r) => r.ratio !== null && r.ratio < r.need).map((r) => `text ${r.where} ${r.fg} on ${r.bg} ${r.ratio} (${r.sample})`),
      ...grad.stops.filter((g) => g.r !== null && g.r < 3).map((g) => `gradient stop ${g.c} ${g.r}`),
      ...nonText.filter((n) => n.ratio !== null && n.ratio < 3).map((n) => `${n.what} ${n.ratio}`)
    ];
    expect(fails, 'every measured pair passes').toEqual([]);
  });
});
