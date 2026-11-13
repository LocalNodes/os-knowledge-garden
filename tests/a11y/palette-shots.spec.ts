// Before-and-after screenshots of the five app screens a palette or look
// change is judged on: the home stream, the AI overview, a Source box, the
// assistant with a cited answer, a form with a primary button and a focus
// ring, and the sign-in page. Each at 1280 and 390 wide.
//
// It takes pictures and checks nothing, so it is skipped unless LN_SHOTS_DIR
// names the folder the pictures go to:
//   LN_SHOTS_DIR=/abs/dir LN_BASE_URL=... npx --no-install playwright test palette-shots.spec.ts --project member --project anonymous
// No live AI: the overview and the chat answer are the canned bodies.
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect, type Page } from '@playwright/test';
import { guardAi } from './helpers/ai-guard';
import { CHAT_RQ01_RESPONSE, OVERVIEW_BIOREGIONALISM_RESPONSE, RQ01_QUESTION } from './fixtures/canned-ai';

const REPO_ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const OUT = process.env.LN_SHOTS_DIR ?? '';
const WIDTHS = [
  { name: 'desktop', width: 1280, height: 900 },
  { name: 'phone', width: 390, height: 844 },
];

test.skip(!OUT, 'set LN_SHOTS_DIR to take the screenshots');

async function shoot(page: Page, name: string, prepare: () => Promise<void>) {
  mkdirSync(OUT, { recursive: true });
  for (const size of WIDTHS) {
    await page.setViewportSize({ width: size.width, height: size.height });
    await prepare();
    await page.waitForTimeout(300);
    await page.screenshot({ path: join(OUT, `${name}-${size.name}.png`), fullPage: false });
  }
}

test.describe('palette shots, member', () => {
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== 'member', 'runs as the member');
  });

  test('home stream', async ({ page }) => {
    await shoot(page, '1-home-stream', async () => {
      await page.goto('/stream');
    });
  });

  test('AI overview and a Source box', async ({ page }) => {
    await guardAi(page, async (route, request, fulfil) => {
      if (new URL(request.url()).pathname === '/api/ai/overview') {
        await fulfil(OVERVIEW_BIOREGIONALISM_RESPONSE);
        return;
      }
      await route.abort();
    });
    await shoot(page, '2a-ai-overview', async () => {
      await page.goto('/search/all/bioregionalism');
      await expect(page.locator('[data-ai-overview]')).toContainText('Bioregionalism', { timeout: 15_000 });
    });
    const output = execFileSync('ddev', ['drush', 'php:script', 'tests/a11y/seed-source-box.php'], {
      cwd: REPO_ROOT,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
      timeout: 120_000,
    });
    const nid = output.match(/^LN_SOURCE_WEB=(\d+)\s*$/m)?.[1] ?? '';
    expect(nid).toMatch(/^\d+$/);
    await shoot(page, '2b-source-box', async () => {
      await page.goto(`/node/${nid}`);
      await page.locator('.localnodes-import-source').scrollIntoViewIfNeeded();
    });
  });

  test('assistant with a cited answer', async ({ page }) => {
    await guardAi(page, async (route, request, fulfil) => {
      if (request.method() === 'POST' && new URL(request.url()).pathname === '/api/deepchat') {
        await fulfil(CHAT_RQ01_RESPONSE);
        return;
      }
      await route.abort();
    });
    await shoot(page, '3-assistant-answer', async () => {
      await page.goto(`/group/${process.env.LN_GROUP}/stream`);
      const header = page.locator('.ai-deepchat--header');
      // The chat remembers it was open, so the second width may find it open.
      if ((await header.getAttribute('aria-expanded')) !== 'true') {
        await header.click();
      }
      await expect(header).toHaveAttribute('aria-expanded', 'true');
      const chat = page.locator('deep-chat');
      const input = chat.locator('#text-input');
      await input.click();
      await input.pressSequentially(RQ01_QUESTION);
      await chat.locator('.input-button').last().click();
      await expect(chat.locator('a.ln-cite').first()).toBeVisible({ timeout: 15_000 });
    });
  });

  // A group header with no cover picture (white) and one with a picture (the
  // picture stays, with the white tabs bar under it). LN_COVER_GROUP names a
  // group that has a cover picture; it is skipped when unset.
  test('group headers', async ({ page }) => {
    await shoot(page, '6a-group-no-cover', async () => {
      await page.goto(`/group/${process.env.LN_GROUP}/stream`);
    });
    if (process.env.LN_COVER_GROUP) {
      await shoot(page, '6b-group-cover', async () => {
        await page.goto(`/group/${process.env.LN_COVER_GROUP}/stream`);
      });
    }
  });

  test('form with a primary button and a focus ring', async ({ page }) => {
    await shoot(page, '4-form-focus', async () => {
      await page.goto('/node/add/topic');
      // Keyboard focus, so the ring is the :focus-visible one a keyboard user sees.
      await page.locator('#edit-title-0-value').focus();
      await page.keyboard.press('Shift+Tab');
      await page.keyboard.press('Tab');
    });
  });
});

test.describe('palette shots, visitor', () => {
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== 'anonymous', 'runs signed out');
  });

  test('sign-in page', async ({ page }) => {
    await shoot(page, '5-sign-in', async () => {
      await page.goto('/user/login');
    });
  });
});
