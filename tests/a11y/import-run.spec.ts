// 22-30 Task 4: the recorded import run (D-05's walkthrough goal, done by a
// script so two reviewer models can judge the recording).
//
// The goal, as a first-time organizer would have it: "Bring your group's
// documents in: the files in that folder, and these five wiki pages. Then
// ask the group's assistant one of the DEMO-SCRIPT questions."
//
// As the organizer (a11y-steward) of a fresh, empty group
// (seed-walkthrough.php, "Riverbend Tool Library"), using only what's on the
// screen: every control is found by its visible label or role, never by a
// class or test id. Steps:
//   1. open the group and its "Import" tab (S1);
//   2. "Upload files": choose every file in
//      localnodes_import/tests/fixtures/import/files/ (S2), tick the rights
//      box, import; watch the run (S4) and read the report;
//   3. "Import web pages": paste the five P2P Foundation Wiki addresses
//      (S3); the wiki blocks automatic imports, so each is expected to
//      report "This site blocks automatic imports…" (D-23); import; read the
//      report;
//   4. open an imported page and its Source box (S6);
//   5. on the group's page, open the LocalNodes LiAIson assistant and ask DEMO-SCRIPT's
//      first rehearsal question, RQ-01 (S7);
//   6. extra, not part of the goal: search the site for "bioregionalism" to
//      show the AI overview (S8).
// Each step saves a screenshot of the window, a full-page screenshot, the
// page's visible text, and a line in steps.json, under
// .planning/…/a11y/walkthrough/. Trace and video are always on for this spec.
//
// AI: canned, as in the rest of the suite (22-28 guard). The a11y site has
// no AI key and nothing is indexed, so the assistant's answer and the
// overview are RQ-01/RQ-02's recorded answers from the eval site; their
// links point at that site's pages. The reviewers are told so.
//
// Live web fetches: the five P2P pages are fetched by the DDEV site through
// SafeFetcher (not AI). They may be slow; the test allows 10 minutes.
import { execFileSync } from 'node:child_process';
import { mkdirSync, readdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { test, expect, type Page } from '@playwright/test';
import { RESULTS_DIR } from './helpers/axe';
import { assertNoLiveAi, guardAi } from './helpers/ai-guard';
import { FIXTURES, REPO_ROOT } from './helpers/states';
import { CHAT_RQ01_RESPONSE, OVERVIEW_BIOREGIONALISM_RESPONSE, OVERVIEW_NONE_RESPONSE, RQ01_QUESTION } from './fixtures/canned-ai';

test.use({ viewport: { width: 1280, height: 900 }, trace: 'on', video: 'on' });

const OUT = join(RESULTS_DIR, 'walkthrough');
const RIGHTS = "I have the right to share these here, because they're openly licensed or I have permission.";
const P2P_PAGES = [
  'https://wiki.p2pfoundation.net/Bioregionalism',
  'https://wiki.p2pfoundation.net/Cosmo-Localization',
  'https://wiki.p2pfoundation.net/Cosmo-Localism',
  'https://wiki.p2pfoundation.net/Commons_Transition',
  'https://wiki.p2pfoundation.net/Commons-Based_Peer_Production',
];

interface Step {
  n: number;
  screen: string;
  did: string;
  url: string;
  title: string;
  h1: string;
  screenshot: string;
  window: string;
  text: string;
  at: string;
}

test('recorded import run: files, five wiki pages, one question', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'steward', 'runs as the organizer (steward)');
  test.setTimeout(600_000);
  mkdirSync(OUT, { recursive: true });

  const seed = execFileSync('ddev', ['drush', 'php:script', 'tests/a11y/seed-walkthrough.php'], { cwd: REPO_ROOT, encoding: 'utf8' });
  const gid = seed.match(/^LN_WALK_GROUP=(\d+)\s*$/m)?.[1];
  expect(gid, `seed-walkthrough.php printed LN_WALK_GROUP. Output:\n${seed}`).toMatch(/^\d+$/);

  const guard = await guardAi(page, async (route, request, fulfil) => {
    const url = new URL(request.url());
    if (url.pathname === '/api/deepchat' && request.method() === 'POST') {
      await fulfil(CHAT_RQ01_RESPONSE);
      return;
    }
    if (url.pathname === '/api/ai/overview') {
      const q = (url.searchParams.get('q') ?? '').trim().toLowerCase();
      await fulfil(q === 'bioregionalism' ? OVERVIEW_BIOREGIONALISM_RESPONSE : OVERVIEW_NONE_RESPONSE);
      return;
    }
    await route.abort();
  });

  const steps: Step[] = [];
  const capture = async (screen: string, did: string) => {
    const n = steps.length + 1;
    const name = `step-${String(n).padStart(2, '0')}-${screen}`;
    await page.waitForLoadState('domcontentloaded');
    // First what the window shows, as a person sees it; then the whole page.
    // A full-page shot draws the closed "LocalNodes LiAIson" panel (which sits
    // below the window) and, by resizing for a moment, makes socialblue
    // close an open tab list, so the window shot is the faithful one.
    await page.screenshot({ path: join(OUT, `${name}-window.png`) });
    await page.screenshot({ path: join(OUT, `${name}.png`), fullPage: true });
    const text = await page.locator('body').innerText();
    writeFileSync(join(OUT, `${name}.txt`), text + '\n');
    steps.push({
      n,
      screen,
      did,
      url: new URL(page.url()).pathname + new URL(page.url()).search,
      title: await page.title(),
      h1: ((await page.locator('h1').first().textContent().catch(() => '')) ?? '').trim(),
      screenshot: `${name}.png`,
      window: `${name}-window.png`,
      text: `${name}.txt`,
      at: new Date().toISOString(),
    });
    writeFileSync(join(OUT, 'steps.json'), JSON.stringify({ group: gid, steps }, null, 2) + '\n');
  };

  // 1. The group, then its Import tab.
  await page.goto(`/group/${gid}`);
  await capture('group', 'Opened the new group "Riverbend Tool Library" as its organizer.');
  const importTab = page.getByRole('link', { name: 'Import', exact: true });
  if (!(await importTab.isVisible())) {
    // 22-30 finding WK-01: from 900 px wide, socialblue moves the tabs that
    // don't fit (Import among them, for an organizer) into a list behind an
    // unlabelled arrow at the end of the tab bar. The arrow has no name or
    // role, so this one step can't go by a visible label: it's the finding.
    await capture('group', 'Looked for "Import" among the group\'s tabs: it isn\'t shown. The tab bar ends with a small arrow.');
    // Let socialblue's resize handler (run by the full-page shot, 250 ms
    // later) rebuild the tab bar before the arrow is used.
    await page.waitForTimeout(600);
    const arrow = page.locator('.navbar-secondary .caret');
    await arrow.click();
    await expect(importTab).toBeVisible();
    await page.waitForTimeout(500);
    await capture('group', 'Clicked the arrow at the end of the tab bar; a list opened with "Import" in it.');
    // The full-page screenshot resizes the window for a moment, and
    // socialblue rebuilds (and closes) the list on resize, 250 ms later:
    // open it again.
    await page.waitForTimeout(600);
    if (!(await importTab.isVisible())) {
      await page.locator('.navbar-secondary .caret').click();
      await expect(importTab).toBeVisible();
      await page.waitForTimeout(500);
    }
  }
  await importTab.click();
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
  await capture('S1', 'Opened the group\'s "Import" tab.');

  // 2. Upload every file in the folder.
  await page.getByRole('link', { name: /^Upload files/ }).first().click();
  await expect(page.getByRole('heading', { level: 1, name: 'Upload files' })).toBeVisible();
  await capture('S2', 'Chose "Upload files".');
  const files = readdirSync(FIXTURES).sort().map((n) => join(FIXTURES, n));
  await page.getByLabel('Choose files').setInputFiles(files);
  await expect(page.getByRole('paragraph').filter({ hasText: /^\d+ files chosen\. \d+ can be imported\.$/ })).toBeVisible();
  await capture('S2', `Chose all ${files.length} files in the folder with "Choose files".`);
  await page.getByText(RIGHTS).click();
  await expect(page.getByLabel(RIGHTS)).toBeChecked();
  const importFiles = page.getByRole('button', { name: /^Import \d+ files?$/ });
  await capture('S2', 'Ticked the rights box.');
  // One later progress state, "‹i› of ‹N›: ‹name›" (22-30, Codex CX-21).
  // The batch's first real answer is shown (at 99% if it already finished
  // everything, as batch.spec.ts does), and the next step waits until it's
  // been captured.
  let batchStep = 0;
  let releaseBatch: () => void = () => {};
  const batchGate = new Promise<void>((resolve) => {
    releaseBatch = resolve;
  });
  await page.route(/\/batch\?.*op=do(&|$)/, async (route) => {
    batchStep += 1;
    if (batchStep === 1) {
      const response = await route.fetch();
      const body = await response.json();
      await route.fulfill({ response, json: { ...body, percentage: body.percentage === '100' ? '99' : body.percentage } });
      return;
    }
    await batchGate;
    await route.continue();
  });
  await importFiles.click();
  await page.waitForURL(/\/batch\?|\/import\/runs\/\d+/, { timeout: 300_000 });
  if (/\/batch\?/.test(page.url())) {
    await capture('S4', 'Pressed the import button; the uploads finished and the import started.');
    await expect(page.getByText(/^\d+ of \d+: \S/).first()).toBeVisible({ timeout: 60_000 });
    await capture('S4', 'While the import runs, the progress line names the last file done ("‹i› of ‹N›: ‹name›").');
    releaseBatch();
    await page.waitForURL(/\/import\/runs\/\d+/, { timeout: 300_000 });
  }
  releaseBatch();
  await expect(page.getByRole('heading', { level: 1, name: 'Import finished' })).toBeVisible();
  await capture('S4', 'The import finished and showed its report.');

  // 3. The five wiki pages.
  await page.getByRole('link', { name: 'Import more documents' }).click();
  await capture('S1', 'Pressed "Import more documents", back on the Import tab.');
  await page.getByRole('link', { name: /^Import web pages/ }).first().click();
  await expect(page.getByRole('heading', { level: 1, name: 'Import web pages' })).toBeVisible();
  await capture('S3', 'Chose "Import web pages".');
  await page.getByLabel('Page addresses').fill(P2P_PAGES.join('\n'));
  // The licence lines update by themselves after the addresses change; give
  // the site time to look at the wiki.
  await page.getByLabel('Page addresses').blur();
  // One line or alert per site; for a site that blocks automatic imports,
  // S4's sentence (22-30 Q5).
  await expect(page.getByText(/^These pages are |^This site doesn't say its pages can be reused|^This site blocks automatic imports/).first()).toBeVisible({ timeout: 120_000 });
  await page.waitForTimeout(1000);
  await capture('S3', 'Pasted the five P2P Foundation Wiki addresses.');
  await page.getByText(RIGHTS).click();
  await expect(page.getByLabel(RIGHTS)).toBeChecked();
  await capture('S3', 'Ticked the rights box.');
  await page.getByRole('button', { name: /^Import \d+ pages?$/ }).click();
  await page.waitForURL(/\/batch\?|\/import\/runs\/\d+/, { timeout: 300_000 });
  if (/\/batch\?/.test(page.url())) {
    await capture('S4', 'Pressed the import button; the import started.');
    await page.waitForURL(/\/import\/runs\/\d+/, { timeout: 420_000 });
  }
  await expect(page.getByRole('heading', { level: 1, name: 'Import finished' })).toBeVisible();
  await capture('S4', 'The web import finished and showed its report.');

  // 4. An imported page and its Source box: the first linked title in the
  // files report.
  await page.goto(steps.filter((s) => s.screen === 'S4').map((s) => s.url).find((u) => /\/import\/runs\/\d+$/.test(u)) ?? `/group/${gid}/import`);
  const firstTitle = page.getByRole('table').getByRole('link').first();
  await expect(firstTitle).toBeVisible();
  await firstTitle.click();
  await expect(page.getByRole('heading', { level: 2, name: 'Source' })).toBeVisible();
  await capture('S6', 'Opened one imported page from the report and found its Source box.');

  // 5. The group's assistant, one DEMO-SCRIPT question.
  await page.goto(`/group/${gid}`);
  await page.getByRole('button', { name: 'LocalNodes Liaison, AI' }).click();
  await expect(page.getByText(/^Hi, I'm an AI assistant\./)).toBeVisible();
  await capture('S7', 'Opened "LocalNodes LiAIson" on the group\'s page.');
  const box = page.getByRole('textbox', { name: /^Ask about documents in / });
  await box.click();
  await box.pressSequentially(RQ01_QUESTION);
  await page.getByRole('button', { name: 'Send question' }).click();
  await expect(page.locator('deep-chat').getByRole('link', { name: /^Source 1: / }).first()).toBeVisible({ timeout: 15_000 });
  // The answer is shown from its start (CX-35), a moment after it lands.
  await expect
    .poll(() =>
      page.locator('deep-chat').evaluate((el) => {
        const root = (el as HTMLElement).shadowRoot!;
        const list = root.getElementById('messages')!;
        const answer = Array.from(root.querySelectorAll('.ai-message-text')).pop() as HTMLElement;
        return answer.getBoundingClientRect().top >= list.getBoundingClientRect().top - 1;
      }),
    )
    .toBe(true);
  await capture('S7', `Asked "${RQ01_QUESTION}" and got an answer with numbered sources.`);

  // Close the assistant, as a person done with it would (Escape, KB-07).
  await page.keyboard.press('Escape');
  await expect(page.getByRole('button', { name: 'LocalNodes Liaison, AI' })).toHaveAttribute('aria-expanded', 'false');

  // 6. Extra: the search page's AI overview.
  await page.goto('/search/all/bioregionalism');
  await expect(page.getByRole('complementary', { name: 'AI overview' })).toBeVisible();
  await expect(page.getByRole('complementary', { name: 'AI overview' }).getByRole('link', { name: /^Source 1: / }).first()).toBeVisible();
  await capture('S8', 'Extra, not part of the goal: searched the site for "bioregionalism" and saw the AI overview.');

  assertNoLiveAi(guard);
  writeFileSync(join(OUT, 'steps.json'), JSON.stringify({ group: gid, completed: true, steps }, null, 2) + '\n');
});
