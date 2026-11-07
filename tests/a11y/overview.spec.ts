// S8 AI overview panel on the search page (22-28): axe over 'loading' and
// 'loaded', plus what the panel promises (D-19, D-27; UI-SPEC Part 8 S8).
//
// NO LIVE AI. GET /api/ai/overview is routed to canned bodies
// (fixtures/canned-ai.ts: recorded RQ-01 and RQ-02 sentences for
// "bioregionalism", {summary_html: null} for anything else), and
// helpers/ai-guard.ts fails the test if any request to an AI endpoint was not
// fulfilled by that route. The 'loading' state holds the response back so the
// skeleton (shown after 400 ms) can be checked.
//
// Runs in two projects:
//   - anonymous: D-27 makes the overview members-only, so there's no panel and
//     the page makes zero overview requests;
//   - member: loading, loaded, and a query with nothing to say.
// Axe is scoped to the panel ([data-ai-overview]).
import { test, expect, type Page } from '@playwright/test';
import { checkA11y } from './helpers/axe';
import { assertNoLiveAi, guardAi, type AiGuard } from './helpers/ai-guard';
import { OVERVIEW_BIOREGIONALISM_RESPONSE, OVERVIEW_NONE_RESPONSE } from './fixtures/canned-ai';

const PANEL = '[data-ai-overview]';
const SCOPE = { include: [PANEL] };
const DISCLOSURE = 'Written by AI from pages you can see. Check the sources.';

const panel = (page: Page) => page.locator(PANEL);

test.describe('S8 AI overview, visitor', () => {
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== 'anonymous', 'the visitor check runs as anonymous');
  });

  test('no panel and no overview request when not signed in', async ({ page }) => {
    const guard = await guardAi(page, async (route) => {
      await route.abort();
    });
    await page.goto('/search/all/bioregionalism');
    await page.waitForLoadState('networkidle');
    await expect(page.getByRole('complementary', { name: 'AI overview' })).toHaveCount(0);
    await expect(panel(page)).toHaveCount(0);
    expect(guard.seen, 'zero requests to /api/ai/overview').toEqual([]);
    assertNoLiveAi(guard);
  });
});

test.describe('S8 AI overview, member', () => {
  let guard: AiGuard | undefined;
  let release: () => void = () => {};
  let held: Promise<void> = Promise.resolve();

  test.beforeEach(({}, testInfo) => {
    guard = undefined;
    test.skip(testInfo.project.name !== 'member', 'the overview runs as the member');
  });

  test.beforeEach(async ({ page }) => {
    held = Promise.resolve();
    guard = await guardAi(page, async (route, request, fulfil) => {
      const url = new URL(request.url());
      if (url.pathname !== '/api/ai/overview') {
        await route.abort();
        return;
      }
      await held;
      const q = (url.searchParams.get('q') ?? '').trim().toLowerCase();
      await fulfil(q === 'bioregionalism' ? OVERVIEW_BIOREGIONALISM_RESPONSE : OVERVIEW_NONE_RESPONSE);
    });
  });
  test.afterEach(() => {
    release();
    if (guard) {
      assertNoLiveAi(guard);
    }
  });

  test('loading: skeleton with aria-busy and the hidden status text', async ({ page }) => {
    held = new Promise<void>((resolve) => {
      release = resolve;
    });
    await page.goto('/search/all/bioregionalism');
    // aria-busy is set at once; the region unhides when the skeleton appears.
    await expect(panel(page)).toBeVisible();
    await expect(panel(page)).toHaveAttribute('aria-busy', 'true');
    await expect(panel(page)).toHaveAttribute('role', 'complementary');
    await expect(panel(page)).toHaveAttribute('aria-label', 'AI overview');
    await expect(panel(page).locator('.ai-overview__loading .visually-hidden')).toHaveText(
      'Writing an overview of the results',
    );
    await expect(panel(page).locator('.ai-overview__skeleton-line')).toHaveCount(3);
    await checkA11y(page, 'S8', 'loading', SCOPE);

    release();
    await expect(panel(page)).not.toHaveAttribute('aria-busy', 'true');
    expect(guard!.fulfilled).toHaveLength(1);
  });

  test('loaded: header copy, continuous markers, root-relative Sources', async ({ page }) => {
    await page.goto('/search/all/bioregionalism');
    const region = page.getByRole('complementary', { name: 'AI overview' });
    await expect(region).toBeVisible();
    await expect(region.locator('.ai-overview__marker').first()).toBeVisible();
    await expect(region).not.toHaveAttribute('aria-busy', 'true');
    expect(guard!.fulfilled, 'the overview came from the canned route').toHaveLength(1);

    await expect(region.locator('.ai-overview__label')).toHaveText('AI overview');
    await expect(region.locator('.ai-overview__disclosure')).toHaveText(DISCLOSURE);

    // Markers in reading order are numbered by first use with no gaps
    // (1, 1, 2 is fine; 1, 3 is not), and each is named "Source n: title".
    const sources = OVERVIEW_BIOREGIONALISM_RESPONSE.sources;
    const markers = await region.locator('.ai-overview__marker').evaluateAll((links) =>
      links.map((a) => ({ text: a.textContent, label: a.getAttribute('aria-label'), href: a.getAttribute('href') })),
    );
    let highest = 0;
    for (const m of markers) {
      const n = Number(/^\[(\d+)\]$/.exec(m.text ?? '')?.[1]);
      expect(n, `marker ${m.text} is first-use order with no gap`).toBeLessThanOrEqual(highest + 1);
      highest = Math.max(highest, n);
      const source = sources.find((s) => s.n === n)!;
      expect(m.label).toBe(`Source ${n}: ${source.title}`);
      expect(m.href).toBe(source.url);
    }
    expect(highest, 'every listed source is cited').toBe(sources.length);

    // "Sources" label and an ordered list of page-title links, all
    // root-relative (start with "/" but not "//").
    await expect(region.locator('.ai-overview__sources-label')).toHaveText('Sources');
    const links = region.locator('ol.ai-overview__source-list > li > a');
    await expect(links).toHaveText(sources.map((s) => s.title));
    for (const href of await links.evaluateAll((as) => as.map((a) => a.getAttribute('href')))) {
      expect(href).toMatch(/^\/(?![/\\])/);
    }
    // No source-type word appended after a link (system word, UI-SPEC S8).
    await expect(region.locator('ol.ai-overview__source-list > li')).toHaveText(sources.map((s) => s.title));

    await checkA11y(page, 'S8', 'loaded', SCOPE);
  });

  for (const view of [{ width: 1280, height: 900 }, { width: 320, height: 640 }]) {
    test(`the closed AI assistant bar never covers the overview's links (${view.width} px)`, async ({ page }) => {
      // 22-30 (Codex review): in the recording the closed bar sat over the
      // Sources links. Checked as the page first shows, then with each link
      // scrolled to the window's bottom edge.
      await page.setViewportSize(view);
      await page.goto('/search/all/bioregionalism');
      const region = page.getByRole('complementary', { name: 'AI overview' });
      await expect(region.locator('ol.ai-overview__source-list a').first()).toBeVisible();
      await expect(page.locator('.ai-deepchat--header')).toHaveAttribute('aria-expanded', 'false');
      const links = region.locator('a');
      const sources = region.locator('ol.ai-overview__source-list a');
      const covered = async (which = links) =>
        which.evaluateAll((as) =>
          as.flatMap((a) => {
            const r = a.getBoundingClientRect();
            const points = [[r.left + 2, r.top + r.height / 2], [r.left + r.width / 2, r.top + r.height / 2], [r.right - 2, r.top + r.height / 2]];
            return points
              .filter(([x, y]) => y >= 0 && y <= innerHeight && x >= 0 && x <= innerWidth)
              .filter(([x, y]) => {
                const hit = document.elementFromPoint(x, y);
                return !!hit && !!hit.closest('.ai-deepchat');
              })
              .map(() => `${a.textContent?.trim()} at ${Math.round(r.top)}`);
          }),
        );
      // The Sources links (Codex's case) as the page first shows; every
      // link, inline markers too, when scrolled to the window's bottom edge.
      expect(await covered(sources), 'Sources links as the page first shows').toEqual([]);
      for (let i = 0; i < (await links.count()); i++) {
        await links.nth(i).evaluate((a) => a.scrollIntoView({ block: 'end' }));
        const link = links.nth(i);
        expect(await covered(link), `link ${i + 1} at the bottom edge`).toEqual([]);
      }
      // And nothing covers the launcher itself: it still opens.
      await page.locator('.ai-deepchat--header').click();
      await expect(page.locator('.ai-deepchat--header')).toHaveAttribute('aria-expanded', 'true');
    });
  }

  test('nothing to say: the panel is removed and nothing keeps aria-busy', async ({ page }) => {
    await page.goto('/search/all/zqxv-nothing-matches-this');
    await expect.poll(() => guard!.fulfilled.length).toBe(1);
    await expect(panel(page)).toHaveCount(0);
    await expect(page.locator('[aria-busy="true"]')).toHaveCount(0);
  });
});
