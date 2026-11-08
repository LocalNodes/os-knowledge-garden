// Shared set-up for the Phase 21 group fund a11y specs (21-28).
//
// The specs run from the lane site's dir (fresh3-p21-a11y, ruling R-F), so
// `ddev` here talks to that site. The site runs the test-only
// safe_smart_accounts_rpc_fixture module (D-14): every chain read answers
// from canned fixtures, and the State key below picks a failure on cue.
import { execFileSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, type Page } from '@playwright/test';

export const REPO_ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');

// Axe scope (22-14 convention): the content block and the h1. socialblue's
// nav and the DeepChat widget have pre-existing violations outside it.
export const SCOPE = { include: ['#block-socialblue-content', 'h1'] };

export const SCENARIO_KEY = 'safe_smart_accounts_rpc_fixture.scenario';
export type Scenario = 'ok' | 'fail' | 'mismatch' | 'token_fail';

export interface Treasury {
  groups: Record<'filled' | 'old' | 'never' | 'setup' | 'failed' | 'retired' | 'sending' | 'recovery' | 'empty', number>;
  safes: Record<'filled' | 'old' | 'never' | 'setup' | 'failed' | 'retired' | 'sending' | 'recovery', number>;
  // failed, ran_failed, replaced and resendable: 21-25 fixes 15 to 19.
  payments: Record<'approve' | 'ready' | 'waiting' | 'executed' | 'failed' | 'ran_failed' | 'replaced' | 'resendable', number>;
  steward: number;
  member: number;
  personal: number | null;
}

export function drush(args: string[]): string {
  return execFileSync('ddev', ['drush', ...args], {
    cwd: REPO_ROOT,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    timeout: 120_000,
  });
}

/** Runs seed-treasury.php (idempotent) and parses its LN_TREASURY line. */
export function seedTreasury(): Treasury {
  const output = drush(['php:script', 'tests/a11y/seed-treasury.php']);
  const json = output.match(/^LN_TREASURY=(\{.*\})\s*$/m)?.[1];
  expect(json, `seed-treasury.php printed LN_TREASURY. Output:\n${output}`).toBeTruthy();
  return JSON.parse(json!) as Treasury;
}

export function setScenario(scenario: Scenario): void {
  drush(['state:set', SCENARIO_KEY, scenario]);
}

/**
 * Deletes a Safe's stored snapshot and invalidates its cache tags, so the
 * next page view reads the chain again (a failed read is otherwise reused
 * for the 60 s fresh window).
 */
export function forget(safeId: number): void {
  drush(['php:script', 'tests/a11y/seed-treasury.php', '--', 'forget', String(safeId)]);
}

/** Opens a group fund tab and waits for the streamed card. */
export async function openFund(page: Page, groupId: number): Promise<void> {
  await page.goto(`/group/${groupId}/treasury`);
  await expect(page.getByRole('heading', { level: 2, name: 'Group fund', exact: true })).toBeVisible();
  await expect(page.locator('.group-fund-card--loading')).toHaveCount(0, { timeout: 30_000 });
}

/** The first Balances row reads a real amount, and the stamp says "Updated". */
export async function expectSuccessfulRead(page: Page): Promise<void> {
  await expect(page.getByRole('heading', { level: 3, name: 'Balances' })).toBeVisible();
  const amount = page.locator('table.group-fund-balances tbody tr').first().locator('td');
  await expect(amount).toHaveText(/\d/);
  await expect(amount).not.toContainText("Couldn't read this right now");
  await expect(page.locator('.group-fund-card__stamp')).toHaveText(/^Updated/);
}
