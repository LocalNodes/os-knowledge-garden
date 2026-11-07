// No live AI on the server side either (22-30, Codex merge review R1 item 5).
//
// helpers/ai-guard.ts stops AI calls the browser makes. A page can also make
// them while Drupal renders it: a Topic's Related Topics block runs
// RelatedContentService::findRelated(), a vector search that embeds the
// query through the AI provider. So does background indexing (cron, or
// search-api:index). On the a11y lane those calls are denied on the server
// by the test-only module localnodes_a11y_ai_guard (tests/a11y/README.md):
// every provider call goes through the AI module's ProviderProxy, which
// dispatches PreGenerateResponseEvent first; the module answers it with a
// canned output, so the provider is never invoked, and it counts:
//   - attempted: every provider call;
//   - denied: the ones it answered with a canned output;
//   - reached: the ones that would still invoke a provider (no output was
//     forced by any listener), which must stay 0.
// This spec snapshots the counts (which are the whole suite's: only
// global-setup.ts resets them), renders a Topic as a member (the Related
// Topics block), runs cron and indexes a few items, then checks the
// difference. With the deny switched off, "reached" isn't 0 and the spec
// fails (22-30-SERVER-AI-PROOF.txt). global-teardown.ts also fails the run
// if anything reached a provider at any point in the suite.
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { test, expect } from '@playwright/test';
import { RESULTS_DIR } from './helpers/axe';
import { REPO_ROOT, seedAll, type Seeds } from './helpers/states';

let seeds: Seeds;
test.beforeAll(() => {
  seeds = seedAll();
});

function drush(args: string[]): string {
  return execFileSync('ddev', ['drush', ...args], { cwd: REPO_ROOT, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], timeout: 300_000 });
}

/** The guard's counts, as Drupal stores them. */
function counts(): { attempted: number; denied: number; reached: number; deny: boolean; enabled: boolean } {
  const out = drush([
    'php:eval',
    "$c = \\Drupal::state()->get('localnodes_a11y_ai_guard.counts', []); print json_encode(['attempted' => (int) ($c['attempted'] ?? 0), 'denied' => (int) ($c['denied'] ?? 0), 'reached' => (int) ($c['reached'] ?? 0), 'deny' => (bool) \\Drupal\\Core\\Site\\Settings::get('localnodes_a11y_ai_deny', FALSE), 'enabled' => \\Drupal::moduleHandler()->moduleExists('localnodes_a11y_ai_guard')]);",
  ]);
  return JSON.parse(out.trim().split('\n').pop()!);
}

test('server side: rendering a Topic (Related Topics) and indexing make no provider call', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'member', 'runs as the member');
  test.setTimeout(300_000);
  // The guard's counts are the whole suite's: global-setup.ts resets them
  // once, and global-teardown.ts checks them at the end. This spec only
  // takes a snapshot and checks its own difference (22-30, merge review R2
  // item 4): clearing them here could erase an earlier "reached" call.
  const before = counts();
  // No cached query embeddings (22-32), so the Related Topics search really
  // asks for an embedding.
  drush(['php:eval', "\\Drupal::cache('ai_embeddings')->deleteAll(); \\Drupal::service('cache.render')->deleteAll();"]);

  await page.goto(`/node/${seeds.webNid}`);
  await expect(page.locator('.localnodes-import-source')).toBeVisible();
  const afterRender = counts();

  drush(['cron']);
  drush(['search-api:index', 'social_posts', '--limit=5', '--batch-size=5']);
  const afterIndexing = counts();

  const diff = (a: ReturnType<typeof counts>, b: ReturnType<typeof counts>) => ({
    attempted: a.attempted - b.attempted,
    denied: a.denied - b.denied,
    reached: a.reached - b.reached,
  });
  const render = diff(afterRender, before);
  const indexing = diff(afterIndexing, afterRender);

  mkdirSync(RESULTS_DIR, { recursive: true });
  writeFileSync(join(RESULTS_DIR, 'server-ai-calls.json'), JSON.stringify({ recorded: new Date().toISOString(), before, afterRender, afterIndexing, render, indexing }, null, 2) + '\n');

  expect(afterRender.enabled, 'the lane has localnodes_a11y_ai_guard enabled').toBe(true);
  expect(afterRender.deny, "the lane's settings switch the deny on").toBe(true);
  for (const key of ['attempted', 'denied', 'reached'] as const) {
    expect(afterIndexing[key], `the suite's "${key}" count only goes up during this spec`).toBeGreaterThanOrEqual(before[key]);
  }
  expect(render.attempted, 'the Related Topics block asked for an embedding (the check exercises the path)').toBeGreaterThan(0);
  expect(render.reached, 'no provider call while the Topic rendered').toBe(0);
  expect(indexing.reached, 'no provider call during cron and indexing').toBe(0);
});
