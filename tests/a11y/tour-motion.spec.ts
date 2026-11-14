// The group tour's scroll to each tip, with and without reduced motion.
//
// Core's tour hands each tip scrollIntoView({behavior: 'smooth'}); a member
// who asks for reduced motion gets 'auto' instead. The page's own CSS cannot
// change that argument, so this spec records the arguments the page passes to
// scrollIntoView while the tour opens on the a11y group.
//
// The tour has tips only on the community-wide group, so the spec records
// the a11y group as the community template's one everyone group for its run
// and puts back whatever was recorded before. The member persona is in that
// group and signed in already, so recording it joins no one.
//
// NO LIVE AI (helpers/ai-guard.ts): any AI request the page makes is aborted
// and the test fails if one was seen.
import { execFileSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect, type Page } from '@playwright/test';
import { assertNoLiveAi, guardAi } from './helpers/ai-guard';

const REPO_ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const STATE = 'localnodes.community_template';

function drushEval(php: string): string {
  return execFileSync('ddev', ['drush', 'php:eval', php], {
    cwd: REPO_ROOT,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    timeout: 120_000,
  });
}

function groupId(): string {
  const gid = process.env.LN_GROUP;
  expect(gid, 'global setup exports LN_GROUP').toMatch(/^\d+$/);
  return gid!;
}

let saved = 'null';

test.beforeAll(() => {
  saved = drushEval(`print json_encode(\\Drupal::state()->get('${STATE}'));`).trim().split('\n').pop()!;
  drushEval(
    `\\Drupal::state()->set('${STATE}', ['id' => 'housing_coop', 'version' => 1, 'groups' => [['group' => ${Number(groupId())}, 'everyone' => TRUE, 'group_template' => NULL]], 'repeatable' => []]); \\Drupal\\Core\\Cache\\Cache::invalidateTags(['rendered']);`,
  );
});

test.afterAll(() => {
  const php =
    saved === 'null'
      ? `\\Drupal::state()->delete('${STATE}');`
      : `\\Drupal::state()->set('${STATE}', json_decode(${JSON.stringify(saved)}, TRUE));`;
  drushEval(`${php} \\Drupal\\Core\\Cache\\Cache::invalidateTags(['rendered']);`);
});

// The behavior each scrollIntoView call on the page asked for, in order.
async function scrollsWhileTheTourOpens(page: Page): Promise<string[]> {
  await page.addInitScript(() => {
    const calls: string[] = [];
    (window as unknown as { __lnScrolls: string[] }).__lnScrolls = calls;
    const original = Element.prototype.scrollIntoView;
    Element.prototype.scrollIntoView = function (arg?: boolean | ScrollIntoViewOptions) {
      calls.push(typeof arg === 'object' && arg !== null ? String(arg.behavior ?? '') : String(arg));
      return original.call(this, arg as ScrollIntoViewOptions);
    };
  });
  await page.goto(`/group/${groupId()}/stream?tour=1`);
  const first = page.getByRole('dialog', { name: 'Your shared group' });
  await expect(first).toBeVisible();
  await first.locator('.shepherd-button').first().click();
  await expect(page.getByRole('dialog', { name: "What's coming up" })).toBeVisible();
  return page.evaluate(() => (window as unknown as { __lnScrolls: string[] }).__lnScrolls);
}

for (const [motion, expected] of [
  ['reduce', 'auto'],
  ['no-preference', 'smooth'],
] as const) {
  test(`tour scrolls with behavior ${expected} when reduced motion is ${motion}`, async ({ page }) => {
    const guard = await guardAi(page, async (route) => {
      await route.abort();
    });
    await page.emulateMedia({ reducedMotion: motion });
    const scrolls = await scrollsWhileTheTourOpens(page);
    expect(scrolls.length, 'the tour scrolled to its tips').toBeGreaterThan(0);
    expect(new Set(scrolls)).toEqual(new Set([expected]));
    assertNoLiveAi(guard);
  });
}
