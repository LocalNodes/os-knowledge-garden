// Phase 21 page titles in Title Case (Tom, 2026-11-07; 22-UI-SPEC Part 6),
// on the real site (21-29).
//
// Page titles only: the browser <title> and the page's main title (the h1).
// Tabs, in-page headings, buttons and body copy stay sentence case, so the
// group tab "Fund", the profile tab "Shared accounts", the h2 "Group fund"
// and the button "Set up group fund" are pinned as they are.
//
// The group fund tab's h1 is the group's own name (the Group hero), so only
// its <title> is pinned. The profile pages (/user/{user}/safe-accounts…)
// have no h1 in Open Social's profile layout, so only their <title> is.
//
// Runs as the steward, on 21-28's seed (seed-treasury.php), scenario ok.
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { test, expect, type Page } from '@playwright/test';
import { RESULTS_DIR } from './helpers/axe';
import { openFund, seedTreasury, setScenario, type Treasury } from './helpers/treasury';

let t: Treasury;
const seen: { screen: string; route: string; title: string; h1: string | null }[] = [];

test.beforeAll(() => {
  setScenario('ok');
  t = seedTreasury();
});

test.afterAll(() => {
  setScenario('ok');
  mkdirSync(RESULTS_DIR, { recursive: true });
  writeFileSync(join(RESULTS_DIR, 'titles-treasury.json'), JSON.stringify(seen, null, 2) + '\n');
});

/** The <title> before " | ", and the main h1 when the page has one. */
async function pin(page: Page, screen: string, route: string, expected: string | RegExp, h1: 'title' | 'none' | 'other') {
  const main = page.locator('h1').first();
  const text = (await main.count()) ? ((await main.textContent()) ?? '').replace(/\s+/g, ' ').trim() : null;
  const title = (await page.title()).split(' | ')[0];
  seen.push({ screen, route, title, h1: text });
  if (typeof expected === 'string') {
    expect(title, `${screen} <title>`).toBe(expected);
  }
  else {
    expect(title, `${screen} <title>`).toMatch(expected);
  }
  if (h1 === 'title') {
    expect(text, `${screen} main title`).toBe(title);
  }
}

test.describe('Phase 21 page titles', () => {
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== 'steward', 'runs as the steward');
  });

  test('T1 group fund tab: <title> Group Fund, tab Fund, h2 Group fund', async ({ page }) => {
    await openFund(page, t.groups.filled);
    await pin(page, 'T1', '/group/{group}/treasury', 'Group Fund', 'other');
    // The tab and the in-page heading stay sentence case.
    await expect(page.locator('.nav-tabs, nav.tabs, [role="navigation"]').getByRole('link', { name: 'Fund', exact: true }).first()).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'Group fund', exact: true })).toBeVisible();
  });

  test('T3 link page: Link an Account You Already Have', async ({ page }) => {
    await page.goto(`/group/${t.groups.empty}/treasury/link`);
    await pin(page, 'T3', '/group/{group}/treasury/link', 'Link an Account You Already Have', 'title');
    await expect(page.getByRole('button', { name: 'Link account', exact: true })).toBeVisible();
  });

  test('T3 change mode: Change Linked Account', async ({ page }) => {
    await page.goto(`/group/${t.groups.filled}/treasury/link`);
    await pin(page, 'T3', '/group/{group}/treasury/link (change mode)', 'Change Linked Account', 'title');
  });

  test('T4 group set-up: Set Up Group Fund, button Set up group fund', async ({ page }) => {
    await page.goto(`/group/${t.groups.empty}/treasury/create`);
    await pin(page, 'T4', '/group/{group}/treasury/create', 'Set Up Group Fund', 'title');
    await expect(page.getByRole('button', { name: 'Set up group fund', exact: true })).toBeVisible();
  });

  test('T1 unlink: page Unlink Group Fund, modal Unlink the Group Fund from ‹group›?', async ({ page }) => {
    // Without JavaScript the confirm page's title is the route's.
    await page.goto(`/group/${t.groups.filled}/treasury/unlink`);
    await pin(page, 'T1', '/group/{group}/treasury/unlink', 'Unlink Group Fund', 'title');
    // With it, the modal's title is the confirmation question.
    await openFund(page, t.groups.filled);
    const group = ((await page.locator('h1').first().textContent()) ?? '').replace(/\s+/g, ' ').trim();
    await page.getByRole('link', { name: 'Unlink group fund', exact: true }).click();
    const title = page.locator('.ui-dialog .ui-dialog-title');
    await expect(title).toHaveText(`Unlink the Group Fund from ${group}?`);
    seen.push({ screen: 'T1', route: 'unlink modal', title: (await title.textContent()) ?? '', h1: null });
    await page.keyboard.press('Escape');
  });

  test('T5 group propose: Propose a Payment', async ({ page }) => {
    await page.goto(`/group/${t.groups.filled}/treasury/propose`);
    await pin(page, 'T5', '/group/{group}/treasury/propose', 'Propose a Payment', 'title');
    await expect(page.getByRole('button', { name: 'Propose payment', exact: true })).toBeVisible();
  });

  test('T6 group payment: Payment: ‹amount› to ‹recipient›', async ({ page }) => {
    await page.goto(`/group/${t.groups.filled}/treasury/transaction/${t.payments.approve}`);
    await pin(page, 'T6', '/group/{group}/treasury/transaction/{tx}', /^Payment: \S+ \S+ to .+$/, 'title');
  });

  test('T8 profile listing: Shared Accounts, tab Shared accounts', async ({ page }) => {
    await page.goto(`/user/${t.steward}/safe-accounts`);
    await pin(page, 'T8', '/user/{user}/safe-accounts', 'Shared Accounts', 'none');
    await expect(page.getByRole('link', { name: 'Shared accounts', exact: true }).first()).toBeVisible();
  });

  test('T4 personal create: Create a Shared Account', async ({ page }) => {
    await page.goto(`/user/${t.steward}/safe-accounts/create`);
    await pin(page, 'T4', '/user/{user}/safe-accounts/create', 'Create a Shared Account', 'none');
  });

  test('T7 approval settings: Approval Settings', async ({ page }) => {
    await page.goto(`/user/${t.steward}/safe-accounts/${t.safes.filled}`);
    await pin(page, 'T7', '/user/{user}/safe-accounts/{account}', 'Approval Settings', 'none');
    await expect(page.getByRole('button', { name: 'Save approval settings', exact: true })).toBeVisible();
  });

  test('T6 personal payment page: Payment: ‹amount› to ‹recipient›', async ({ page }) => {
    await page.goto(`/user/${t.steward}/safe-accounts/${t.safes.filled}/transactions/${t.payments.approve}`);
    await pin(page, 'T6', '/user/{user}/safe-accounts/{account}/transactions/{tx}', /^Payment: \S+ \S+ to .+$/, 'none');
  });
});
