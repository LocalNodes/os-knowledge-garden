// The app look's own checks, on the five screens it changes most: the home
// stream (the front page for a member), a group page, a Source box, the new-post form and sign-in. On each,
// at 320, 400 and 1280 CSS pixels wide:
//   - the page loaded: a 200, the address asked for, and one thing that only
//     that screen shows, visible (so an error page or a redirect cannot pass);
//   - the page does not scroll sideways (scrollWidth <= clientWidth); the
//     group's tab bar may scroll inside its own box, which does not widen it;
//   - axe (WCAG 2.2 AA tags) finds no colour-contrast issue and nothing that
//     is not in KNOWN below.
// Plus two checks on what the browser paints: the keyboard focus ring on the
// white tabs bar, and the border of a field in error.
//
// KNOWN holds the issues Open Social's own markup has on these screens
// (measured on 2026-11-12). They are fixed in markup or not at all. Each
// entry names the rule, the widths, the shape of the element's axe selector,
// and, on screens whose content is fixed
// (the form, the Source page, sign-in, the menu), the exact count. One more
// element of another shape, or one more of a counted one, fails. The streams
// list as many image links as the site has photo posts, so there only the
// shape is checked.
//   LN_BASE_URL=... npx --no-install playwright test app-look.spec.ts --project member --project anonymous
import { execFileSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import AxeBuilder from '@axe-core/playwright';
import { test, expect, type Locator, type Page } from '@playwright/test';
import { AXE_TAGS } from './helpers/axe';

const REPO_ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const WIDTHS = [320, 400, 1280];

type Known = { rule: string; shape: RegExp; widths: number[]; count?: number; why: string };

// socialbase's account menu at desktop width: an <li> under a <ul> whose role is navigation.
const MENU: Known = {
  rule: 'listitem',
  shape: /^(?:(?:ul|\.main\.navbar-nav|\.nav)(?:\[role="navigation"\])? > li:nth-child\(1\)|\.expanded)$/,
  widths: [1280],
  count: 2,
  why: "socialbase's account menu puts an <li> under a list whose role is navigation",
};

const KNOWN: Record<string, Known[]> = {
  stream: [
    {
      rule: 'link-name',
      shape: /^a\[href="\/open\/images\/\d+\/\d+"\]$/,
      widths: WIDTHS,
      why: "a photo post's image link has no text (socialbase post teaser)",
    },
    MENU,
  ],
  group: [MENU],
  source: [MENU],
  form: [
    {
      rule: 'aria-allowed-attr',
      shape: /^#edit-field-(?:topic-type|content-visibility)--wrapper$/,
      widths: WIDTHS,
      count: 2,
      why: "socialbase's radio-group wrapper (topic type, visibility) carries an aria attribute its role does not allow",
    },
    {
      rule: 'aria-input-field-name',
      shape: /^#select2-edit-groups-container$/,
      widths: WIDTHS,
      count: 1,
      why: "the select2 group picker's combobox has no accessible name",
    },
    {
      rule: 'link-name',
      shape: /^a\[data-container="body"\]$/,
      widths: WIDTHS,
      count: 1,
      why: "the form's help popover link has no text",
    },
    MENU,
  ],
  signin: [MENU],
};

/** What axe finds on the page that KNOWN[screen] does not allow at this width. */
async function unexpected(page: Page, screen: string, width: number): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return unexpectedIn(violations, KNOWN[screen] ?? [], width);
}

function unexpectedIn(
  violations: { id: string; impact?: string | null; nodes: { target: unknown[] }[] }[],
  known: Known[],
  width: number,
): string[] {
  const fresh: string[] = [];
  const seen = new Map<Known, number>();
  for (const v of violations) {
    for (const node of v.nodes) {
      const target = node.target.map(String).join(' ');
      const entry = v.id === 'color-contrast' ? undefined : known.find((k) => k.rule === v.id && k.widths.includes(width) && k.shape.test(target));
      if (entry) seen.set(entry, (seen.get(entry) ?? 0) + 1);
      else fresh.push(`${v.id} (${v.impact}): ${target}`);
    }
  }
  for (const [entry, n] of seen) {
    if (entry.count !== undefined && n > entry.count) fresh.push(`${entry.rule}: ${n} elements, ${entry.count} known (${entry.why})`);
  }
  return fresh;
}

/** Load `path` and prove it is the screen asked for: a 200, the same address, and `landmark` visible. */
async function open(page: Page, path: string, landmark: (page: Page) => Locator) {
  const response = await page.goto(path);
  expect(response?.status(), `${path} answers 200`).toBe(200);
  expect(new URL(page.url()).pathname, `${path} is not redirected`).toBe(path);
  await expect(landmark(page), `${path} shows its own content`).toBeVisible();
}

async function check(page: Page, screen: string, path: string, landmark: (page: Page) => Locator) {
  for (const width of WIDTHS) {
    await page.setViewportSize({ width, height: 800 });
    await open(page, path, landmark);
    const sideways = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect.soft(sideways, `${path} at ${width}px scrolls sideways by ${sideways}px`).toBeLessThanOrEqual(0);
    expect.soft(await unexpected(page, screen, width), `axe on ${path} at ${width}px`).toEqual([]);
  }
}

/** A --ln-color-* token as the rgb() string getComputedStyle returns. The token must exist on the page. */
async function tokenRgb(page: Page, name: string): Promise<string> {
  const hex = await page.evaluate((n) => getComputedStyle(document.documentElement).getPropertyValue(n).trim(), name);
  expect(hex, `${name} is defined on the page`).toMatch(/^#[0-9a-f]{6}$/i);
  const [r, g, b] = [1, 3, 5].map((at) => parseInt(hex.slice(at, at + 2), 16));
  return `rgb(${r}, ${g}, ${b})`;
}

function groupPath(): string {
  const id = process.env.LN_GROUP ?? '';
  expect(id, 'LN_GROUP is the seeded group id (global-setup.ts)').toMatch(/^\d+$/);
  return `/group/${id}/stream`;
}

const tabsBar = (page: Page) => page.locator('nav.navbar-secondary');

/** The focused element shows the keyboard ring: :focus-visible, 2px solid, in `colour`. */
async function expectRing(page: Page, colour: string, width: number) {
  const active = page.locator(':focus');
  const name = (await active.textContent())?.trim() || (await active.getAttribute('aria-label')) || 'button';
  expect(await active.evaluate((e) => e.matches(':focus-visible')), `${name} at ${width}px is :focus-visible`).toBe(true);
  await expect(active, `${name} at ${width}px: ring colour`).toHaveCSS('outline-color', colour);
  await expect(active, `${name} at ${width}px: ring style`).toHaveCSS('outline-style', 'solid');
  await expect(active, `${name} at ${width}px: ring width`).toHaveCSS('outline-width', '2px');
}

test.describe('app look, member', () => {
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== 'member', 'runs as the member');
  });

  test('home stream', async ({ page }) => {
    await check(page, 'stream', '/', (p) => p.locator('form.social-post-entity-form'));
  });

  test('group page', async ({ page }) => {
    await check(page, 'group', groupPath(), tabsBar);
  });

  // The white tabs bar takes the light-surface ring. Every control in it is
  // reached with the Tab key, so the ring is the :focus-visible one; the
  // colour is read once socialblue's 0.3s transition has settled. At 1024
  // socialblue folds tabs into a list behind the "More group pages" button;
  // the button and a link in the opened list are checked too.
  test('tabs bar: the focus ring is the light-surface focus colour', async ({ page }) => {
    const path = groupPath();
    for (const width of [320, 1024, 1280]) {
      await page.setViewportSize({ width, height: 800 });
      await open(page, path, tabsBar);
      const focus = await tokenRgb(page, '--ln-color-focus');
      const controls = tabsBar(page).locator('a:visible, button:visible');
      const total = await controls.count();
      expect(total, `the tabs bar at ${width}px has controls`).toBeGreaterThan(1);
      if (width === 1024) await expect(page.locator('.ln-tabs-more'), 'the overflow button shows at 1024').toBeVisible();
      let reached = 0;
      for (let presses = 0; presses < 80 && reached < total; presses++) {
        await page.keyboard.press('Tab');
        const inBar = await page.evaluate(() => !!document.activeElement?.closest('nav.navbar-secondary'));
        if (!inBar) {
          if (reached > 0) break;
          continue;
        }
        reached++;
        await expectRing(page, focus, width);
      }
      expect(reached, `the Tab key reached every control in the tabs bar at ${width}px`).toBe(total);
      if (width === 1024) {
        // The folded tabs: Enter opens the list, Tab enters it.
        await page.locator('.ln-tabs-more').focus();
        await page.keyboard.press('Enter');
        await expect(tabsBar(page).locator('.hidden-list'), 'Enter opens the list of folded tabs').toBeVisible();
        await page.keyboard.press('Tab');
        expect(await page.evaluate(() => !!document.activeElement?.closest('nav.navbar-secondary .hidden-list')), 'Tab enters the list').toBe(true);
        await expectRing(page, focus, width);
      }
    }
  });

  test('Source box', async ({ page }) => {
    const output = execFileSync('ddev', ['drush', 'php:script', 'tests/a11y/seed-source-box.php'], {
      cwd: REPO_ROOT,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
      timeout: 120_000,
    });
    const nid = output.match(/^LN_SOURCE_WEB=(\d+)\s*$/m)?.[1] ?? '';
    expect(nid).toMatch(/^\d+$/);
    await check(page, 'source', `/node/${nid}`, (p) => p.locator('.localnodes-import-source'));
  });

  test('new post form', async ({ page }) => {
    await check(page, 'form', '/node/add/topic', (p) => p.locator('form.node-topic-form'));
  });

  // The app look's field edge must not hide an error: the empty title and
  // body come back from the server in error. The browser's own required-field
  // check is switched off so the form reaches Drupal.
  test('new post form: fields in error have the danger border', async ({ page }) => {
    await open(page, '/node/add/topic', (p) => p.locator('form.node-topic-form'));
    const danger = await tokenRgb(page, '--ln-color-danger');
    await page.evaluate(() => {
      const form = document.querySelector<HTMLFormElement>('form.node-topic-form');
      if (!form) return;
      form.noValidate = true;
      form.querySelectorAll('[required]').forEach((field) => field.removeAttribute('required'));
    });
    await page.locator('form.node-topic-form #edit-submit').click();
    const fields = page.locator('form.node-topic-form .has-error .form-control');
    await expect(fields.first(), 'Drupal marks the empty title in error').toBeVisible();
    for (const field of await fields.all()) {
      const id = await field.getAttribute('id');
      await expect(field, `${id} in error`).toHaveCSS('border-top-color', danger);
    }
  });
});

// Over a cover picture an outline button ("Edit group", which the group's
// manager sees) has only the photo behind it, so it keeps a white fill: the
// button's own background must be opaque, its text at 4.5:1 or more on it, at
// rest, hovered and focused. Its keyboard focus ring is drawn inside that
// fill (outside, it would sit on the photo, 1:1 on a patch of its own
// colour), at 3:1 or more against the fill. The group is seed.php's "A11y
// Cover Garden", which the steward manages.
test.describe('app look, controls over a cover picture', () => {
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== 'member', 'runs once, as the steward');
  });

  for (const width of WIDTHS) {
    test(`"Edit group" over a cover picture has an opaque fill, ${width}px`, async ({ browser, baseURL }) => {
      const gid = process.env.LN_COVER_GROUP ?? '';
      expect(gid, 'global-setup seeded a group with a cover picture').toMatch(/^\d+$/);
      const context = await browser.newContext({
        baseURL, viewport: { width, height: 900 },
        storageState: join(dirname(fileURLToPath(import.meta.url)), '.auth', 'steward.json'),
      });
      const page = await context.newPage();
      await page.goto(`/group/${gid}/stream`);
      const button = page.locator('.cover.cover-img .btn-default', { hasText: 'Edit group' });
      await expect(button).toBeVisible();
      const surface = await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--ln-color-surface').trim().toLowerCase());
      const paint = () => button.evaluate((el) => {
        el.getAnimations().forEach((a) => a.finish());
        const cs = getComputedStyle(el);
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
          focusVisible: el.matches(':focus-visible'), ringStyle: cs.outlineStyle, ring: hex(parse(cs.outlineColor)),
          ringWidth: parseFloat(cs.outlineWidth), ringOffset: parseFloat(cs.outlineOffset), ringRatio: contrast(parse(cs.outlineColor), bg),
        };
      });
      const rest = await paint();
      expect(rest.bg, 'at rest: the white fill').toBe(surface);
      for (const [state, m] of [['at rest', rest], ['hovered', await (async () => { await button.hover(); return paint(); })()],
        ['focused', await (async () => { await button.focus(); return paint(); })()]] as const) {
        expect.soft(m.alpha, `${state}: the fill is opaque`).toBe(1);
        expect.soft(m.ratio, `${state}: the text on ${m.bg}`).toBeGreaterThanOrEqual(4.5);
      }
      // Reached with the keyboard, as a person tabbing through the header.
      await page.keyboard.press('Shift+Tab');
      await page.keyboard.press('Tab');
      const ring = await paint();
      expect(ring.focusVisible, 'Tab lands on "Edit group" and shows the ring').toBe(true);
      expect.soft(ring.ringStyle, 'the ring is drawn').not.toBe('none');
      expect.soft(ring.ringOffset + ring.ringWidth, `the ring (${ring.ringWidth}px at offset ${ring.ringOffset}px) is inside the fill`).toBeLessThanOrEqual(0);
      expect.soft(ring.ringRatio, `the ring ${ring.ring} on the fill ${ring.bg}`).toBeGreaterThanOrEqual(3);
      await context.close();
    });
  }
});

// The header's footer row holds only the group's location and address, both
// plain text. With neither it is hidden (no gap above the tabs); with a
// location it shows. The group is seed.php's "A11y Footer Garden", a fixture
// with no picture, location or address: the test sets a location and takes it
// off again, and the seed clears it on the next run if a run died between.
test.describe('app look, the group header footer', () => {
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== 'member', 'runs as the member');
  });

  const drush = (...args: string[]) => execFileSync('ddev', ['drush', ...args], {
    cwd: REPO_ROOT, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], timeout: 60_000,
  }).trim();
  const setLocation = (gid: string, value: string | null) => drush('php:eval',
    `$g = \\Drupal::entityTypeManager()->getStorage('group')->load(${gid}); $g->set('field_group_location', ${value === null ? 'NULL' : JSON.stringify(value)}); $g->save();`);

  test('a plain-text location shows; with none the row is gone', async ({ page }) => {
    const gid = process.env.LN_FOOTER_GROUP ?? '';
    expect(gid, 'global-setup seeded the footer fixture group').toMatch(/^\d+$/);
    expect(drush('sqlq', `SELECT COUNT(*) FROM group__field_group_image WHERE entity_id = ${gid}`)
      + drush('sqlq', `SELECT COUNT(*) FROM group__field_group_location WHERE entity_id = ${gid}`)
      + drush('sqlq', `SELECT COUNT(*) FROM group__field_group_address WHERE entity_id = ${gid}`), 'the fixture has no picture, location or address').toBe('000');
    const footer = page.locator('.cover:not(.cover-img) .hero-footer');
    await page.goto(`/group/${gid}/about`);
    await expect(footer).toHaveCount(1);
    await expect(footer, 'no location or address: no empty row').toBeHidden();
    try {
      setLocation(gid, 'Riverside Hall');
      await page.goto(`/group/${gid}/about`);
      await expect(footer, 'a plain-text location shows').toBeVisible();
      await expect(footer).toContainText('Riverside Hall');
    }
    finally {
      setLocation(gid, null);
    }
  });
});

test.describe('app look, visitor', () => {
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== 'anonymous', 'runs signed out');
  });

  test('sign-in', async ({ page }) => {
    await check(page, 'signin', '/user/login', (p) => p.locator('#edit-name-or-mail'));
  });

  // A field in error keeps the danger border over the app look's field edge.
  test('sign-in: a field in error has the danger border', async ({ page }) => {
    await open(page, '/user/login', (p) => p.locator('#edit-name-or-mail'));
    const danger = await tokenRgb(page, '--ln-color-danger');
    await page.fill('#edit-name-or-mail', 'no-such-member');
    await page.fill('#edit-pass', 'not-the-password');
    await page.click('#edit-submit');
    const field = page.locator('.has-error #edit-name-or-mail');
    await expect(field, 'Drupal marks the name field in error').toBeVisible();
    await expect(field).toHaveCSS('border-top-color', danger);
    await expect(field).toHaveCSS('border-top-style', 'solid');
    await field.focus();
    await expect(field, 'still the danger border while focused').toHaveCSS('border-top-color', danger);
  });
});
