// Every Phase 22 screen state (UI-SPEC Part 8, S1 to S8), reached with the
// keyboard only, for the 22-30 keyboard, reflow and screen-reader-proxy
// specs. Each state names its persona, the axe scope 22-28 used for it, and
// a setup that drives the page with Tab, Shift+Tab, Enter, Space and typing:
// no click and no mouse. The one step a keyboard can't do in a headless
// browser is picking files in the operating system's file dialog: Space on
// "Choose files" opens it, and Playwright's file chooser answers it.
//
// Seeds are 22-28's: seed.php (global setup: personas and "A11y Garden"),
// seed-status.php (S1 empty, S5), seed-source-box.php (S6) and
// seed-refresh.php (the refresh page). No live AI: every state installs the
// 22-28 guard; S7 and S8 get the recorded canned bodies, and any other AI
// request is aborted and fails the test.
import { execFileSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, type Page } from '@playwright/test';
import { guardAi, type AiGuard } from './ai-guard';
import { tabUntil, focused } from './keyboard';
import {
  CHAT_NO_ANSWER_RESPONSE,
  CHAT_RQ01_RESPONSE,
  NO_ANSWER_GROUP,
  OVERVIEW_BIOREGIONALISM_RESPONSE,
  OVERVIEW_NONE_RESPONSE,
  RQ01_QUESTION,
} from '../fixtures/canned-ai';

export const REPO_ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');
export const FIXTURES = join(REPO_ROOT, 'html/modules/custom/localnodes_import/tests/fixtures/import/files');
export const fixture = (name: string) => join(FIXTURES, name);

export type Persona = 'steward' | 'member';

export interface Seeds {
  group: string;
  statusGroup: string;
  emptyGroup: string;
  webNid: string;
  fileNid: string;
  refreshRun: string;
  refreshNid: string;
}

function drushScript(script: string): string {
  return execFileSync('ddev', ['drush', 'php:script', script], {
    cwd: REPO_ROOT,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    timeout: 120_000,
  });
}

function pick(output: string, key: string): string {
  const value = output.match(new RegExp(`^${key}=(\\d+)\\s*$`, 'm'))?.[1] ?? '';
  expect(value, `${key} printed by the seed. Output:\n${output}`).toMatch(/^\d+$/);
  return value;
}

/** Runs 22-28's seeds (idempotent) and returns the ids the states need. */
export function seedAll(): Seeds {
  const group = process.env.LN_GROUP ?? '';
  expect(group, 'global setup exports LN_GROUP').toMatch(/^\d+$/);
  const status = drushScript('tests/a11y/seed-status.php');
  const source = drushScript('tests/a11y/seed-source-box.php');
  const refresh = drushScript('tests/a11y/seed-refresh.php');
  return {
    group,
    statusGroup: pick(status, 'LN_STATUS_GROUP'),
    emptyGroup: pick(status, 'LN_EMPTY_GROUP'),
    webNid: pick(source, 'LN_SOURCE_WEB'),
    fileNid: pick(source, 'LN_SOURCE_FILE'),
    refreshRun: pick(refresh, 'LN_REFRESH_RUN'),
    refreshNid: pick(refresh, 'LN_REFRESH_NID'),
  };
}

export interface StateRun {
  page: Page;
  seeds: Seeds;
  guard: AiGuard;
  /** Releases anything a setup held back (an upload, a batch step, an AI answer). */
  release: () => void;
  /** Questions sent to the canned assistant. */
  asked: string[];
}

export interface UiState {
  /** "S2-filled": the screen and the state, used in every result file name. */
  key: string;
  screen: string;
  state: string;
  persona: Persona;
  /** Axe include selectors (22-28's scope for the screen). */
  scope: string[];
  /** What the assistant answers in this state. */
  chat?: 'rq01' | 'none';
  /** Hold the overview's answer back (S8 loading). */
  holdOverview?: boolean;
  setup: (run: StateRun) => Promise<void>;
}

const CONTENT = ['#block-socialblue-content', 'h1'];
const BATCH = ['main', 'h1'];

// ---------------------------------------------------------------------------
// Keyboard steps.

export async function chooseFiles(page: Page, names: string[]) {
  await tabUntil(page, (f) => f.id === 'edit-files', { what: '"Choose files"' });
  const chooser = page.waitForEvent('filechooser');
  await page.keyboard.press('Space');
  await (await chooser).setFiles(names.map(fixture));
}

export async function tickRights(page: Page) {
  await tabUntil(page, (f) => f.id === 'edit-rights-confirmed', { what: 'the rights checkbox' });
  await page.keyboard.press('Space');
  await expect(page.locator('#edit-rights-confirmed')).toBeChecked();
}

export async function pressPrimary(page: Page) {
  await tabUntil(page, (f) => f.id === 'edit-submit', { what: 'the primary button' });
  await page.keyboard.press('Enter');
}

async function openUpload(page: Page, gid: string) {
  await page.goto(`/group/${gid}/import/files`);
  await expect(page.getByRole('heading', { level: 1, name: 'Upload files' })).toBeVisible();
  await expect(page.locator('.localnodes-import-dropzone')).toBeVisible();
}

/** Holds every request matching `pattern` until release() is called. */
async function hold(page: Page, pattern: RegExp | string, run: StateRun, { firstOnly = false } = {}) {
  let release: () => void = () => {};
  const gate = new Promise<void>((resolve) => {
    release = resolve;
  });
  const previous = run.release;
  run.release = () => {
    previous();
    release();
  };
  let seen = 0;
  await page.route(pattern, async (route) => {
    seen += 1;
    if (!firstOnly || seen === 1) {
      await gate;
    }
    await route.continue().catch(() => null);
  });
}

export async function openChat(page: Page) {
  await tabUntil(page, (f) => f.cls.includes('ai-deepchat--header'), { what: 'the "AI assistant" launcher' });
  await page.keyboard.press('Enter');
  await expect(page.locator('.ai-deepchat--header')).toHaveAttribute('aria-expanded', 'true');
  await expect(page.locator('deep-chat').getByText(/^Hi, I'm an AI assistant\./)).toBeVisible();
}

export async function askByKeyboard(page: Page, question: string) {
  await tabUntil(page, (f) => f.id === 'text-input', { what: 'the question box' });
  await page.keyboard.type(question);
  await page.keyboard.press('Enter');
}

// ---------------------------------------------------------------------------
// The states.

export const STATES: UiState[] = [
  {
    key: 'S1-empty', screen: 'S1', state: 'empty', persona: 'steward', scope: CONTENT,
    setup: async ({ page, seeds }) => {
      await page.goto(`/group/${seeds.emptyGroup}/import`);
      await expect(page.getByRole('heading', { level: 2, name: 'Nothing imported yet' })).toBeVisible();
    },
  },
  {
    key: 'S1-populated', screen: 'S1', state: 'populated', persona: 'steward', scope: CONTENT,
    setup: async ({ page, seeds }) => {
      await page.goto(`/group/${seeds.statusGroup}/import`);
      await expect(page.getByRole('heading', { level: 2, name: 'Search status' })).toBeVisible();
    },
  },
  {
    key: 'S2-empty', screen: 'S2', state: 'empty', persona: 'steward', scope: CONTENT,
    setup: async ({ page, seeds }) => {
      await openUpload(page, seeds.group);
    },
  },
  {
    key: 'S2-filled', screen: 'S2', state: 'filled', persona: 'steward', scope: CONTENT,
    setup: async ({ page, seeds }) => {
      await openUpload(page, seeds.group);
      await chooseFiles(page, ['pdf-01-tool-share-rules.pdf', 'welcome-letter.docx', 'budget.xlsx']);
      await expect(page.locator('.localnodes-import-chosen__count')).toHaveText('3 files chosen. 2 can be imported.');
    },
  },
  {
    key: 'S2-error', screen: 'S2', state: 'error', persona: 'steward', scope: CONTENT,
    setup: async ({ page, seeds }) => {
      await openUpload(page, seeds.group);
      await pressPrimary(page);
      await expect(page.locator('[data-ln-error-summary]')).toBeFocused();
    },
  },
  {
    key: 'S2-uploading', screen: 'S2', state: 'uploading', persona: 'steward', scope: CONTENT,
    setup: async (run) => {
      const { page, seeds } = run;
      await hold(page, '**/import/files/upload**', run);
      await openUpload(page, seeds.group);
      await chooseFiles(page, ['pdf-03-rainwater-barrels.pdf', 'pdf-04-potluck-checklist.pdf']);
      await tickRights(page);
      await pressPrimary(page);
      await expect(page.locator('.localnodes-import-chosen__summary')).toHaveText('Uploading 1 of 2');
      await expect(page.locator('.localnodes-import-chosen__summary')).toBeFocused();
    },
  },
  {
    // "Stop uploading" keeps what was uploaded and reports the rest as "Not
    // uploaded"; the run then starts on its own, so the state a person ends
    // up in is that run's report.
    key: 'S2-stopped', screen: 'S2', state: 'stopped', persona: 'steward', scope: CONTENT,
    setup: async (run) => {
      const { page, seeds } = run;
      await hold(page, '**/import/files/upload**', run);
      await openUpload(page, seeds.group);
      await chooseFiles(page, ['pdf-07-meadow-survey.pdf', 'pdf-08-childcare-rota.pdf']);
      await tickRights(page);
      await pressPrimary(page);
      await expect(page.locator('.localnodes-import-chosen__summary')).toHaveText('Uploading 1 of 2');
      await tabUntil(page, (f) => f.name === 'Stop uploading', { what: '"Stop uploading"' });
      await page.keyboard.press('Enter');
      await page.waitForURL(/\/import\/runs\/\d+/, { timeout: 120_000 });
      await expect(page.getByRole('heading', { level: 1, name: 'Import finished' })).toBeVisible();
      await expect(page.getByText('Not uploaded: you stopped the upload.').first()).toBeVisible();
    },
  },
  {
    key: 'S3-empty', screen: 'S3', state: 'empty', persona: 'steward', scope: CONTENT,
    setup: async ({ page, seeds }) => {
      await page.goto(`/group/${seeds.group}/import/web`);
      await expect(page.getByRole('heading', { level: 1, name: 'Import web pages' })).toBeVisible();
    },
  },
  {
    key: 'S3-error', screen: 'S3', state: 'error', persona: 'steward', scope: CONTENT,
    setup: async ({ page, seeds }) => {
      await page.goto(`/group/${seeds.group}/import/web`);
      await expect(page.getByRole('heading', { level: 1, name: 'Import web pages' })).toBeVisible();
      await pressPrimary(page);
      await expect(page.locator('[data-ln-error-summary]')).toBeVisible({ timeout: 60_000 });
      await expect(page.locator('[data-ln-error-summary]')).toBeFocused();
    },
  },
  {
    key: 'S4-in-progress', screen: 'S4', state: 'in-progress', persona: 'steward', scope: BATCH,
    setup: async (run) => {
      const { page, seeds } = run;
      // Every batch step waits until the state has been checked.
      await hold(page, /\/batch\?.*op=do(&|$)/, run);
      await openUpload(page, seeds.group);
      await chooseFiles(page, ['scan_0042_final(2).pdf', 'budget.xlsx', 'pdf-06-repair-cafe-flyer.pdf']);
      await tickRights(page);
      await pressPrimary(page);
      await page.waitForURL(/\/batch\?/, { timeout: 90_000 });
      await expect(page.getByRole('heading', { level: 1, name: 'Importing your files' })).toBeVisible();
      await expect(page.getByRole('progressbar', { name: 'Importing your files' })).toHaveCount(1);
    },
  },
  {
    key: 'S4-report', screen: 'S4', state: 'report', persona: 'steward', scope: CONTENT,
    setup: async ({ page, seeds }) => {
      await openUpload(page, seeds.group);
      await chooseFiles(page, ['scan_0042_final(2).pdf', 'budget.xlsx', 'pdf-09-compost-troubleshooting.pdf']);
      await tickRights(page);
      await pressPrimary(page);
      await page.waitForURL(/\/import\/runs\/\d+/, { timeout: 180_000 });
      await expect(page.getByRole('heading', { level: 1, name: 'Import finished' })).toBeVisible();
      await expect(page.getByRole('heading', { level: 2, name: 'Results' })).toBeVisible();
    },
  },
  {
    key: 'S4-refresh', screen: 'S4', state: 'refresh', persona: 'steward', scope: CONTENT,
    setup: async ({ page, seeds }) => {
      await page.goto(`/group/${seeds.group}/import/runs/${seeds.refreshRun}`);
      await expect(page.getByText('This page is already in the group.')).toBeVisible();
      await tabUntil(page, (f) => f.name.startsWith('Refresh from source'), { what: '"Refresh from source"' });
      await page.keyboard.press('Enter');
      await expect(page).toHaveURL(new RegExp(`/import/refresh/${seeds.refreshNid}\\?run=${seeds.refreshRun}`));
      await expect(page.getByRole('heading', { level: 1 })).toContainText('from Its Source?');
    },
  },
  {
    key: 'S5-lists', screen: 'S5', state: 'lists', persona: 'steward', scope: CONTENT,
    setup: async ({ page, seeds }) => {
      await page.goto(`/group/${seeds.statusGroup}/import`);
      const status = page.locator('.localnodes-import-status');
      await expect(status.getByRole('heading', { level: 2, name: 'Search status' })).toBeVisible();
      for (const label of ["See what's still waiting (2)", "See pages that can't be searched (1)"]) {
        await tabUntil(page, (f) => f.tag === 'SUMMARY' && f.name === label, { what: label });
        await page.keyboard.press('Enter');
      }
      await expect(status.locator('details[open]')).toHaveCount(2);
    },
  },
  {
    key: 'S5-paged', screen: 'S5', state: 'paged', persona: 'steward', scope: CONTENT,
    setup: async ({ page, seeds }) => {
      await page.goto(`/group/${seeds.statusGroup}/import`);
      await expect(page.getByRole('heading', { level: 2, name: 'Past imports' })).toBeVisible();
      await tabUntil(page, (f) => f.name === 'Next page' || f.name.startsWith('Next page'), { what: '"Next page"' });
      await page.keyboard.press('Enter');
      const pager = page.getByRole('navigation', { name: 'Past imports pages' });
      await expect(pager.locator('a[aria-current="page"]')).toHaveText('2');
    },
  },
  {
    key: 'S6-web', screen: 'S6', state: 'web', persona: 'member',
    scope: ['.localnodes-import-source', '.localnodes-import-imported .body-text'],
    setup: async ({ page, seeds }) => {
      await page.goto(`/node/${seeds.webNid}`);
      await expect(page.locator('.localnodes-import-source').getByRole('heading', { level: 2, name: 'Source' })).toBeVisible();
    },
  },
  {
    key: 'S6-file', screen: 'S6', state: 'file', persona: 'member', scope: ['.localnodes-import-source'],
    setup: async ({ page, seeds }) => {
      await page.goto(`/node/${seeds.fileNid}`);
      await expect(page.locator('.localnodes-import-source').getByRole('heading', { level: 2, name: 'Source' })).toBeVisible();
    },
  },
  {
    key: 'S7-closed', screen: 'S7', state: 'closed', persona: 'member', scope: ['.ai-deepchat'],
    setup: async ({ page, seeds }) => {
      await page.goto(`/group/${seeds.group}/stream`);
      await expect(page.locator('.ai-deepchat--header')).toHaveAttribute('aria-expanded', 'false');
    },
  },
  {
    key: 'S7-open', screen: 'S7', state: 'open', persona: 'member', scope: ['.ai-deepchat'],
    setup: async ({ page, seeds }) => {
      await page.goto(`/group/${seeds.group}/stream`);
      await openChat(page);
    },
  },
  {
    key: 'S7-answered', screen: 'S7', state: 'answered', persona: 'member', scope: ['.ai-deepchat'], chat: 'rq01',
    setup: async ({ page, seeds }) => {
      await page.goto(`/group/${seeds.group}/stream`);
      await openChat(page);
      await askByKeyboard(page, RQ01_QUESTION);
      await expect(page.locator('deep-chat').locator('a.ln-cite').first()).toBeVisible({ timeout: 15_000 });
    },
  },
  {
    key: 'S7-no-answer', screen: 'S7', state: 'no-answer', persona: 'member', scope: ['.ai-deepchat'], chat: 'none',
    setup: async ({ page, seeds }) => {
      await page.goto(`/group/${seeds.group}/stream`);
      await openChat(page);
      await askByKeyboard(page, 'Where do we keep the seed bank keys?');
      await expect(page.locator('deep-chat').getByText(NO_ANSWER_GROUP)).toBeVisible({ timeout: 15_000 });
    },
  },
  {
    key: 'S8-loading', screen: 'S8', state: 'loading', persona: 'member', scope: ['[data-ai-overview]'], holdOverview: true,
    setup: async ({ page }) => {
      await page.goto('/search/all/bioregionalism');
      const panel = page.locator('[data-ai-overview]');
      await expect(panel).toBeVisible();
      await expect(panel).toHaveAttribute('aria-busy', 'true');
      await expect(panel.locator('.ai-overview__skeleton-line')).toHaveCount(3);
    },
  },
  {
    key: 'S8-loaded', screen: 'S8', state: 'loaded', persona: 'member', scope: ['[data-ai-overview]'],
    setup: async ({ page }) => {
      await page.goto('/search/all/bioregionalism');
      const region = page.getByRole('complementary', { name: 'AI overview' });
      await expect(region.locator('.ai-overview__marker').first()).toBeVisible();
      await expect(region).not.toHaveAttribute('aria-busy', 'true');
    },
  },
];

/**
 * Installs the no-live-AI guard for a state (canned bodies for S7 and S8,
 * abort for anything else) and runs its setup.
 */
export async function enterState(page: Page, state: UiState, seeds: Seeds): Promise<StateRun> {
  const run: StateRun = { page, seeds, guard: { seen: [], fulfilled: [] }, release: () => {}, asked: [] };
  let releaseOverview: () => void = () => {};
  const overviewGate = state.holdOverview
    ? new Promise<void>((resolve) => {
        releaseOverview = resolve;
      })
    : Promise.resolve();
  run.release = () => releaseOverview();
  run.guard = await guardAi(page, async (route, request, fulfil) => {
    const url = new URL(request.url());
    if (url.pathname === '/api/deepchat' && request.method() === 'POST' && state.chat) {
      const body = request.postDataJSON() as { messages?: { role: string; text: string }[] };
      run.asked.push(body?.messages?.filter((m) => m.role === 'user').pop()?.text ?? '');
      await fulfil(state.chat === 'rq01' ? CHAT_RQ01_RESPONSE : CHAT_NO_ANSWER_RESPONSE);
      return;
    }
    if (url.pathname === '/api/ai/overview') {
      await overviewGate;
      const q = (url.searchParams.get('q') ?? '').trim().toLowerCase();
      await fulfil(q === 'bioregionalism' ? OVERVIEW_BIOREGIONALISM_RESPONSE : OVERVIEW_NONE_RESPONSE).catch(() => null);
      return;
    }
    await route.abort();
  });
  await state.setup(run);
  return run;
}

/** Skips a state test in the other persona's project. */
export function personaOf(projectName: string): Persona | null {
  return projectName === 'steward' || projectName === 'member' ? projectName : null;
}

export { focused };
