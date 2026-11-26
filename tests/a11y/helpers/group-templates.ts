// Shared set-up for the Group template specs.
//
// seed-group-templates.php makes fictional personas and Groups; these helpers
// seed under a run key (so every run starts from Groups that are not set up),
// read the installed template from the site's config (so the specs name no
// template, kind or Group type), sign personas in through `drush uli`, and
// open each page with the network and AI guards on.
import { join } from 'node:path';
import { expect, type Browser, type Page, type Response } from '@playwright/test';
import { AUTH_DIR, REPO_ROOT, assertSiteOnly, drush, guardNetwork, sessionFor, BANNED, BANNED_SAFE, type NetworkGuard } from './governance-states';
import { assertNoLiveAi, guardAi, type AiGuard } from './ai-guard';
import { CHAT_NO_ANSWER_RESPONSE, OVERVIEW_NONE_RESPONSE } from '../fixtures/canned-ai';

export const BASE_URL = process.env.LN_BASE_URL ?? 'https://fresh3.ddev.site';

// Axe results for these specs go to their own test output folder (gitignored).
export const RESULTS_DIR = join(REPO_ROOT, 'test-results', '25.3-group-templates-a11y');

// The content block, as the other specs scope their checks.
export const CONTENT = '#block-socialblue-content';

export const NON_AUTHORITY = 'This records a decision. It does not authorize or send a payment.';
export const CLOSE_ON_AGREE = "Everyone else who decides on it has agreed. If you agree, it will close and everyone's responses will be final.";
export const PROVENANCE = 'Human-confirmed meeting report · no online responses collected';
export const NOT_ADOPTED = 'Example · not adopted';

// Fund, chain and wallet words, beside the 25-UI-SPEC BANNED list. "payment"
// is not here: the non-authority line itself says it.
export const FUND_WORDS = /\b(?:wallets?|chains?|blockchains?|tokens?|funds?|funding|treasur(?:y|ies)|payouts?|fees?|crypto(?:currency)?|ether(?:eum)?|sign(?:ature|ing)s?)\b/i;
// Payment words, allowed only inside the non-authority line.
export const PAYMENT_WORDS = /\b(?:pay|paid|paying|payments?)\b/i;
// A control that would pay, charge or ask the assistant to act.
export const ACTION_CONTROL = /\b(?:pay|send money|transfer|fee|donate|checkout|ask the assistant|ask ai)\b/i;

// Open Social's post form loads its emoji picker's data from a CDN on a
// Group's stream page. That is Open Social's own code, not ours: the
// guard aborts it before it leaves, and the spec records it.
export const EMOJI_CDN = /^https:\/\/cdn\.jsdelivr\.net\/npm\/emoji-picker-element-data@/;
// The same for the image cropper on a Topic's edit form (image_widget_crop).
export const CROPPER_CDN = /^https:\/\/cdnjs\.cloudflare\.com\/ajax\/libs\/cropper\//;

export interface SeedUser { uid: number; name: string; display: string }
export interface Seed {
  mode: string;
  key: string;
  groups: Record<string, number>;
  group_labels: Record<string, string>;
  users: Record<string, SeedUser>;
  roster: string[];
  rounds?: Record<string, number>;
  report?: number;
}

export interface Template {
  id: string;
  label: string;
  starter: string[];
  everyday_days: number;
}

/** A run key of letters only, so Group names hold no digits. */
export function runKey(prefix: string): string {
  return prefix + Date.now().toString(36).replace(/\d/g, (d) => 'abcdefghij'[Number(d)]);
}

function lastJson(output: string, prefix: string): string {
  const line = output.split('\n').find((l) => l.startsWith(prefix));
  expect(line, `${prefix} in the output:\n${output.slice(-2000)}`).toBeTruthy();
  return line!.slice(prefix.length);
}

/** Runs seed-group-templates.php and parses its JSON line. */
export function seed(mode: 'journey' | 'states', key: string): Seed {
  const output = drush(['php:script', 'tests/a11y/seed-group-templates.php', '--', mode, key]);
  return JSON.parse(lastJson(output, 'LN_GROUP_TEMPLATES=')) as Seed;
}

/** The installed templates, read from the site's config. */
export function installedTemplates(): Template[] {
  const code = [
    "$s = \\Drupal::service('config.storage');",
    '$o = [];',
    "foreach ($s->listAll('localnodes_group_setup.template.') as $n) {",
    '  $d = $s->read($n);',
    "  $o[] = ['id' => $d['id'], 'label' => $d['label'], 'starter' => array_column($d['starter'], 'title'), 'everyday_days' => (int) $d['decision_rules']['everyday']['response_days']];",
    '}',
    "print 'LN_TEMPLATES=' . json_encode($o) . \"\\n\";",
  ].join(' ');
  return JSON.parse(lastJson(drush(['php:eval', code]), 'LN_TEMPLATES=')) as Template[];
}

/** The server-side AI guard's counts (localnodes_a11y_ai_guard). */
export function serverAiCounts(): { attempted?: number; denied?: number; reached?: number } {
  const out = drush(['php:eval', "print 'LN_AI=' . json_encode(\\Drupal::state()->get('localnodes_a11y_ai_guard.counts', [])) . \"\\n\";"]);
  return JSON.parse(lastJson(out, 'LN_AI='));
}

/**
 * Repeats a page load that Chromium dropped because the host's network
 * changed (other test sites starting containers on the shared machine).
 * Only that transport error is retried; every other failure is thrown.
 */
export async function steady<T>(load: () => Promise<T>): Promise<T> {
  for (let attempt = 1; ; attempt++) {
    try {
      return await load();
    }
    catch (error) {
      if (attempt >= 5 || !/net::ERR_NETWORK_CHANGED/.test(String(error))) throw error;
      await new Promise((resolve) => setTimeout(resolve, 1000));
    }
  }
}

/** Signs every persona of a seed in; returns persona → session file. */
export async function sessions(s: Seed, prefix: string): Promise<Record<string, string>> {
  const files: Record<string, string> = {};
  for (const [persona, user] of Object.entries(s.users)) {
    files[persona] = await steady(() => sessionFor(user.name, `${prefix}-${persona}`, BASE_URL));
  }
  return files;
}

/** Off-site requests a known Open Social component tried (aborted). */
export const knownOffSite: string[] = [];

export interface Visit {
  page: Page;
  response: Response | null;
  /** Checks the guards, then closes the context. */
  done: () => Promise<void>;
}

/**
 * Opens a page as a persona (a session file, or null for a signed-out
 * visitor), with the network guard (any other host fails the test) and the
 * AI endpoint guard (any AI request must be answered by the canned route).
 */
export async function visit(browser: Browser, session: string | null, path: string | null, options: { viewport?: { width: number; height: number }; deviceScaleFactor?: number; reducedMotion?: 'reduce' | 'no-preference' } = {}): Promise<Visit> {
  const context = await browser.newContext({
    baseURL: BASE_URL,
    ignoreHTTPSErrors: true,
    storageState: session ?? { cookies: [], origins: [] },
    viewport: options.viewport,
    deviceScaleFactor: options.deviceScaleFactor,
    reducedMotion: options.reducedMotion,
  });
  const page = await context.newPage();
  // Every load of a page, here or in a spec, gets the network-change retry.
  const goto = page.goto.bind(page);
  page.goto = (url, opts) => steady(() => goto(url, opts));
  const net: NetworkGuard = await guardNetwork(page, BASE_URL, [EMOJI_CDN, CROPPER_CDN]);
  const ai: AiGuard = await guardAi(page, async (route, request, fulfil) => {
    await fulfil(new URL(request.url()).pathname.startsWith('/api/ai/overview') ? OVERVIEW_NONE_RESPONSE : CHAT_NO_ANSWER_RESPONSE);
  });
  const response = path === null ? null : await page.goto(path);
  return {
    page,
    response,
    done: async () => {
      try {
        assertSiteOnly(net);
        assertNoLiveAi(ai);
        knownOffSite.push(...net.known);
      }
      finally {
        await context.close();
      }
    },
  };
}

/** A refusal: 403 or 404, or the sign-in page for a signed-out visitor. */
export function isRefusal(page: Page, response: Response | null): boolean {
  const status = response?.status() ?? 0;
  return [403, 404].includes(status) || /\/user\/login\b/.test(new URL(page.url()).pathname);
}

/** No banned, fund or chain word, and no paying or assistant control, in the content. */
export async function assertPlainWords(page: Page, where: string): Promise<void> {
  const root = page.locator(CONTENT);
  const text = await root.innerText();
  expect(text.match(BANNED)?.[0] ?? null, `${where}: a banned word`).toBeNull();
  expect(text.match(BANNED_SAFE)?.[0] ?? null, `${where}: a banned word`).toBeNull();
  expect(text.match(FUND_WORDS)?.[0] ?? null, `${where}: a fund, chain or wallet word`).toBeNull();
  // Payment words only in the non-authority line itself.
  expect(text.split(NON_AUTHORITY).join(' ').match(PAYMENT_WORDS)?.[0] ?? null, `${where}: a payment word outside the non-authority line`).toBeNull();
  const controls = await root.locator('a, button, input[type=submit], [role=button]').evaluateAll((els) =>
    els.map((el) => (el.getAttribute('aria-label') || (el as HTMLInputElement).value || el.textContent || '').replace(/\s+/g, ' ').trim()));
  expect(controls.filter((name) => ACTION_CONTROL.test(name)), `${where}: no paying, fee or assistant control`).toEqual([]);
}

/** Escapes a string for a RegExp. */
export function esc(text: string): string {
  return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * Chooses a radio or checkbox by its label, as a person does: socialblue
 * draws the label over the input, so the label is what takes the click.
 */
export async function choose(page: Page, label: string): Promise<void> {
  const target = page.locator('label').filter({ hasText: new RegExp(`^\\s*${esc(label)}\\s*\\*?\\s*$`) }).first();
  await target.click();
  const id = await target.getAttribute('for');
  expect(id, `the label "${label}" names its input`).toBeTruthy();
  await expect(page.locator(`[id="${id}"]`)).toBeChecked();
}

/**
 * Opens one of the Group's tabs. On narrow layouts socialblue folds the
 * later tabs under "More group pages", so that menu is opened first.
 */
export async function openTab(page: Page, name: string): Promise<void> {
  const tabs = page.locator('#block-socialblue-local-tasks');
  const link = tabs.getByRole('link', { name, exact: true });
  if (!(await link.isVisible())) {
    await tabs.getByRole('button', { name: 'More group pages' }).click();
  }
  await link.click();
}

/** A text field or text area by the start of its label ("Why" matches "Why*"). */
export function field(page: Page, label: string) {
  return page.getByRole('textbox', { name: new RegExp(`^${esc(label)}\\s*\\*?$`) });
}

/** A select by the start of its label. */
export function select(page: Page, label: string) {
  return page.getByRole('combobox', { name: new RegExp(`^${esc(label)}\\s*\\*?$`) });
}

/** The stored session file for a persona of a seed. */
export function authPath(prefix: string, persona: string): string {
  return join(AUTH_DIR, `${prefix}-${persona}.json`);
}
