// S7 chat answer with numbered citations (22-28): axe over 'closed', 'open'
// and 'answered', plus what the screen promises (D-19, D-28; UI-SPEC Part 8
// S7 and the Copywriting Contract).
//
// NO LIVE AI. The assistant's endpoint (POST /api/deepchat) is routed to a
// canned body recorded from 22-27's live run (fixtures/canned-ai.ts, RQ-01),
// and helpers/ai-guard.ts fails the test if any request to an AI endpoint was
// not fulfilled by that route. The question is RQ-01's; the page is the
// "A11y Garden" group (the a11y site has no P2P group, and the canned answer
// doesn't depend on it).
//
// Runs in two projects:
//   - member: the chat on the group page (closed, open, answered), and the
//     guard's own proof (a test whose route aborts instead of fulfilling);
//   - steward: on /group/<id>/import the chat is absent (block visibility).
// Axe is scoped to the chat container (.ai-deepchat), which axe follows into
// DeepChat's open shadow root.
import { test, expect, type Page } from '@playwright/test';
import { checkA11y } from './helpers/axe';
import { assertNoLiveAi, guardAi, type AiGuard } from './helpers/ai-guard';
import { CHAT_RQ01_RESPONSE, CHAT_RQ01_SOURCES, RQ01_QUESTION } from './fixtures/canned-ai';

const SCOPE = { include: ['.ai-deepchat'] };
const FIRST_MESSAGE =
  "Hi, I'm an AI assistant. I answer only from pages on this site that you're allowed to see, and I number my " +
  'sources so you can check them. I can get things wrong, so open a source before you rely on an answer.';

function groupId(): string {
  const gid = process.env.LN_GROUP;
  expect(gid, 'global setup exports LN_GROUP').toMatch(/^\d+$/);
  return gid!;
}

/** The body drupal/ai 1.5's block JavaScript posts to /api/deepchat. */
interface ChatRequestBody {
  chat_processor_plugin?: string;
  plugin_configuration?: { assistant_id?: string; verbose_mode?: boolean; [key: string]: unknown };
  verbose_mode?: unknown;
  messages?: { role: string; text: string }[];
}

const header = (page: Page) => page.locator('.ai-deepchat--header');
const chat = (page: Page) => page.locator('deep-chat');

async function openChat(page: Page) {
  await expect(header(page)).toHaveAttribute('aria-expanded', 'false');
  await header(page).click();
  await expect(header(page)).toHaveAttribute('aria-expanded', 'true');
  await expect(chat(page).getByText(FIRST_MESSAGE)).toBeVisible();
}

async function ask(page: Page, question: string) {
  const input = chat(page).locator('#text-input');
  await input.click();
  await input.pressSequentially(question);
  await chat(page).locator('.input-button').last().click();
}

test.describe('S7 chat', () => {
  let guard: AiGuard | undefined;
  let asked: string[];
  // The JSON body of each POST /api/deepchat the shipped block's JavaScript made.
  let posted: ChatRequestBody[];
  test.beforeEach(({}, testInfo) => {
    guard = undefined;
    test.skip(testInfo.project.name !== 'member', 'S7 chat states run as the member');
  });

  test.beforeEach(async ({ page }) => {
    asked = [];
    posted = [];
    guard = await guardAi(page, async (route, request, fulfil) => {
      if (request.method() === 'POST' && new URL(request.url()).pathname === '/api/deepchat') {
        const body = request.postDataJSON() as ChatRequestBody;
        posted.push(body);
        asked.push(body?.messages?.filter((m) => m.role === 'user').pop()?.text ?? '');
        await fulfil(CHAT_RQ01_RESPONSE);
        return;
      }
      // Any other AI request in this spec is unexpected: never let it out.
      await route.abort();
    });
  });
  test.afterEach(() => {
    if (guard) {
      assertNoLiveAi(guard);
    }
  });

  test('closed: the "AI assistant" header shows while collapsed', async ({ page }) => {
    await page.goto(`/group/${groupId()}/stream`);
    await expect(header(page)).toBeVisible();
    await expect(header(page)).toHaveAccessibleName('AI assistant');
    await expect(header(page)).toHaveAttribute('aria-expanded', 'false');
    await expect(page.locator('.chat-element')).toHaveClass(/chat-collapsed/);
    await checkA11y(page, 'S7', 'closed', SCOPE);
    expect(guard!.seen, 'no AI request just from loading the page').toEqual([]);
  });

  test('open: first message states what it answers from and that it can be wrong', async ({ page }) => {
    await page.goto(`/group/${groupId()}/stream`);
    await openChat(page);
    await expect(chat(page).locator('#text-input')).toBeVisible();
    await checkA11y(page, 'S7', 'open', SCOPE);
  });

  test('answered: markers named "Source n: title", Sources list numbered to match', async ({ page }) => {
    await page.goto(`/group/${groupId()}/stream`);
    await openChat(page);
    await ask(page, RQ01_QUESTION);

    const markers = chat(page).locator('a.ln-cite');
    await expect(markers.first()).toBeVisible({ timeout: 15_000 });
    expect(guard!.fulfilled, 'the answer came from the canned route').toHaveLength(1);
    expect(asked, 'the question sent is RQ-01').toEqual([RQ01_QUESTION]);

    // 22.2-07: the shipped 1.5 block names its chat processor and the group
    // assistant in the request it makes. Without chat_processor_plugin the
    // server answers 400 (smoke-instance.sh builds the same body from the
    // rendered block settings). Verbose mode travels inside plugin_configuration,
    // not at the top level, so ai-deepchat-verbose-mode-passthrough.patch has
    // nothing left to add.
    expect(posted, 'one request reached the canned route').toHaveLength(1);
    expect(posted[0].chat_processor_plugin, 'the request names a chat processor plugin').toBe('ai_assistant_api_processor');
    expect(posted[0].plugin_configuration?.assistant_id, 'plugin_configuration names the group assistant').toBe('group_assistant');
    expect(posted[0].plugin_configuration, 'verbose_mode rides in plugin_configuration').toHaveProperty('verbose_mode');
    expect(posted[0], 'no top-level verbose_mode').not.toHaveProperty('verbose_mode');

    // Every inline marker: visible "[n]", accessible name "Source n: title",
    // root-relative href, same tab.
    const markerData = await markers.evaluateAll((links) =>
      links.map((a) => ({
        text: a.textContent,
        label: a.getAttribute('aria-label'),
        href: a.getAttribute('href'),
        target: a.getAttribute('target'),
      })),
    );
    expect(markerData.length).toBeGreaterThan(0);
    for (const m of markerData) {
      expect(m.label).toMatch(/^Source \d+: \S/);
      const n = Number(/^\[(\d+)\]$/.exec(m.text ?? '')?.[1]);
      const source = CHAT_RQ01_SOURCES.find((s) => s.n === n);
      expect(source, `marker ${m.text} is a listed source`).toBeTruthy();
      expect(m.label).toBe(`Source ${n}: ${source!.title}`);
      expect(m.href).toBe(source!.url);
      expect(m.target).toBeNull();
    }

    // The "Sources" label and an ordered list of "[n] title" with the title
    // as link text; numbers match the markers, every link root-relative.
    await expect(chat(page).locator('.ln-sources-label')).toHaveText('Sources');
    const items = chat(page).locator('ol.ln-sources > li');
    await expect(items).toHaveCount(CHAT_RQ01_SOURCES.length);
    for (const [i, source] of CHAT_RQ01_SOURCES.entries()) {
      await expect(items.nth(i)).toHaveText(`[${source.n}] ${source.title}`);
      const link = items.nth(i).locator('a.ln-source');
      await expect(link).toHaveText(source.title);
      await expect(link).toHaveAttribute('href', /^\/(?!\/)/);
    }

    // A new answer is shown from its start, not scrolled to its end
    // (22-30, Codex CX-35).
    // DeepChat scrolls down again 60ms after content loads, which can land
    // after the first correction. Do the same here, then wait it out.
    await page.waitForTimeout(100);
    await chat(page).evaluate((el) => {
      const list = (el as HTMLElement).shadowRoot!.getElementById('messages')!;
      list.scrollTop = list.scrollHeight;
    });
    const answerStart = () => chat(page).evaluate((el) => {
      const root = (el as HTMLElement).shadowRoot!;
      const list = root.getElementById('messages')!;
      const bubbles = root.querySelectorAll('.ai-message-text');
      const last = bubbles[bubbles.length - 1] as HTMLElement;
      return { list: list.getBoundingClientRect().top, answer: last.getBoundingClientRect().top, overflow: list.scrollHeight > list.clientHeight };
    });
    await page.waitForTimeout(1_200);
    const start = await answerStart();
    expect(start.answer, `the answer's first line is in view (${JSON.stringify(start)})`).toBeGreaterThanOrEqual(start.list - 1);

    await checkA11y(page, 'S7', 'answered', SCOPE);
  });
});

// The guard's own proof (22-28), outside the canned describe so no other
// route is in play: with the route aborting instead of fulfilling, the
// request is seen but not fulfilled and assertNoLiveAi throws. abort() never
// leaves the browser, so nothing reaches the site or Gemini. Kept in the
// suite so every run re-proves the guard.
test.describe('AI guard', () => {
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== 'member', 'runs once, as the member');
  });

  test('guard fires on an AI request the route did not fulfil', async ({ page }) => {
    const probe = await guardAi(page, async (route) => {
      await route.abort();
    });
    await page.goto(`/group/${groupId()}/stream`);
    await page.evaluate(async () => {
      await fetch('/api/ai/overview?q=guard-probe', { credentials: 'same-origin' }).catch(() => null);
    });
    await expect.poll(() => probe.seen.length).toBe(1);
    expect(probe.fulfilled).toEqual([]);
    expect(() => assertNoLiveAi(probe)).toThrow(/none went live/);
  });
});

test.describe('S7 chat, import screens', () => {
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== 'steward', 'the import screens run as the steward');
  });

  test('no chat toggle on /group/<id>/import', async ({ page }) => {
    const guard = await guardAi(page, async (route) => {
      await route.abort();
    });
    await page.goto(`/group/${groupId()}/import`);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.locator('.ai-deepchat')).toHaveCount(0);
    await expect(page.locator('deep-chat')).toHaveCount(0);
    assertNoLiveAi(guard);
    expect(guard.seen).toEqual([]);
  });
});

// The collapsed panel takes no space and no clicks, site-wide (22-30,
// from Phase 21's walkthrough: in full-page captures the collapsed body was
// drawn over the Group Fund amounts). Only the launcher bar may sit over
// the page. Checked on the group stream, whose last "Read more" link sits at
// the bottom right, at 1280 and 320 px.
test.describe('S7 collapsed panel', () => {
  for (const view of [{ width: 1280, height: 900 }, { width: 320, height: 640 }]) {
    test(`takes no space and blocks no clicks (${view.width} px)`, async ({ page }, testInfo) => {
      test.skip(testInfo.project.name !== 'member', 'runs as the member');
      await page.setViewportSize(view);
      const guard = await guardAi(page, async (route) => {
        await route.abort();
      });
      await page.goto(`/group/${groupId()}/stream`);
      await expect(header(page)).toHaveAttribute('aria-expanded', 'false');

      // The hidden body: no box, not painted, no pointer events; the panel
      // is no taller than its launcher bar.
      const shape = await page.evaluate(() => {
        const container = document.querySelector('.ai-deepchat') as HTMLElement;
        const body = container.querySelector('.chat-element') as HTMLElement;
        const bar = container.querySelector('.ln-chat-bar') as HTMLElement;
        const b = body.getBoundingClientRect();
        const cs = getComputedStyle(body);
        return {
          bodyArea: Math.round(b.width * b.height),
          bodyVisibility: cs.visibility,
          bodyPointerEvents: cs.pointerEvents,
          panelHeight: Math.round(container.getBoundingClientRect().height),
          barHeight: Math.round(bar.getBoundingClientRect().height),
          barTop: Math.round(bar.getBoundingClientRect().top),
          barBottom: Math.round(bar.getBoundingClientRect().bottom),
          viewHeight: window.innerHeight,
        };
      });
      expect.soft(shape.bodyArea, `the collapsed body has no box (${JSON.stringify(shape)})`).toBe(0);
      expect.soft(shape.bodyVisibility, 'the collapsed body is not painted').toBe('hidden');
      expect.soft(shape.bodyPointerEvents, 'the collapsed body takes no clicks').toBe('none');
      expect.soft(shape.panelHeight, 'the collapsed panel is no taller than its launcher bar').toBeLessThanOrEqual(shape.barHeight + 1);
      // The launcher itself stays fully in the window.
      expect.soft(shape.barTop, 'the launcher bar starts inside the window').toBeGreaterThanOrEqual(0);
      expect.soft(shape.barBottom, 'the launcher bar ends inside the window').toBeLessThanOrEqual(shape.viewHeight);

      // The bottom-right link of the stream, at the window's bottom edge:
      // its corners hit the link, and a click on it works.
      const link = page.locator('main a[href^="/node/"]').filter({ hasText: /Read more/ }).last();
      await link.evaluate((a) => a.scrollIntoView({ block: 'end' }));
      const corners = await link.evaluate((a) => {
        const r = a.getBoundingClientRect();
        return [[r.left + 1, r.top + 1], [r.right - 1, r.top + 1], [r.left + 1, r.bottom - 1], [r.right - 1, r.bottom - 1]].map(([x, y]) => {
          const hit = document.elementFromPoint(x, y);
          return !!hit && (hit === a || a.contains(hit));
        });
      });
      expect.soft(corners, "the link's four corners hit the link, not the panel").toEqual([true, true, true, true]);
      const href = await link.getAttribute('href');
      await link.click({ timeout: 5_000 });
      await expect(page).toHaveURL(new RegExp(`${href}$`));
      assertNoLiveAi(guard);
    });
  }
});

