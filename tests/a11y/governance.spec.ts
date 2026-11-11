// Phase 25 group governance screens (25-UI-SPEC V1 to V10) through axe
// (25-26, criterion 4's automated half).
//
// Runs in the member project. Each block switches to the persona its states
// belong to (test.use storageState), as 21-29's walkthrough does: member
// (no wallet), voter and newcomer (wallets; the newcomer waits for
// approval), steward (organizer, an approver) and sitemanager (V10). Every
// test guards the network: a request to any host but the site fails it.
// The states are the table in helpers/governance-states.ts.
//
// Rerun from the repo root (lane site, Playwright's own Chromium):
//   LN_BROWSER_CHANNEL=chromium LN_BASE_URL=<site> npx --no-install playwright test \
//     -c tests/a11y/playwright.config.ts --project=member -g governance
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { test, expect, type Page } from '@playwright/test';
import {
  BANNED,
  BANNED_SAFE,
  D30,
  GOVERNANCE_RESULTS_DIR,
  GOVERNANCE_STATES,
  PERSONA_USERS,
  assertSiteOnly,
  authFile,
  guardNetwork,
  scan,
  seedGovernance,
  sessionFor,
  setSponsor,
  stubWallet,
  type Governance,
  type GovernanceState,
  type Persona,
} from './helpers/governance-states';

const BASE_URL = process.env.LN_BASE_URL ?? 'https://fresh3.ddev.site';
// ethereum_sepolia, the seeded funds' network.
const CHAIN_ID_HEX = '0xaa36a7';

let gov: Governance;

/** The member-visible text of the content block. */
async function contentText(page: Page): Promise<string> {
  return page.locator('#block-socialblue-content').innerText();
}

async function runState(page: Page, s: GovernanceState): Promise<void> {
  gov = seedGovernance(...(s.seed ? ['state', s.seed] : []));
  setSponsor(s.sponsor ?? 'configured');
  try {
    if (s.wallet) {
      const account = gov.wallets[s.persona];
      expect(account, `${s.persona} has a wallet`).toBeTruthy();
      await stubWallet(page, account, CHAIN_ID_HEX, s.wallet);
    }
    const guard = await guardNetwork(page, BASE_URL, s.knownOffSite ?? []);
    const response = await page.goto(s.path(gov));
    expect(response?.status(), `${s.screen} ${s.state} answers 200`).toBe(200);
    if (s.act) await s.act(page);
    const root = page.locator((s.scope ?? ['#block-socialblue-content'])[0]);
    await expect(root.getByText(s.expect).first()).toBeVisible();
    await scan(page, s.screen, s.state, s.scope);
    if (guard.known.length) {
      writeFileSync(join(GOVERNANCE_RESULTS_DIR, `network-${s.screen}-${s.state}.json`), JSON.stringify({ aborted: guard.known }, null, 2) + '\n');
    }
    if (['member', 'voter', 'newcomer'].includes(s.persona)) {
      // No crypto words on member screens (25-UI-SPEC global rules), whole
      // words only, on the rendered text.
      const text = await contentText(page);
      expect(text.match(BANNED)?.[0] ?? null, `${s.screen} ${s.state}: banned word`).toBeNull();
      expect(text.match(BANNED_SAFE)?.[0] ?? null, `${s.screen} ${s.state}: banned word`).toBeNull();
    }
    assertSiteOnly(guard);
  }
  finally {
    if (s.sponsor) setSponsor('configured');
  }
}

test.describe('governance', () => {
  test.beforeAll(() => {
    gov = seedGovernance();
  });

  test('governance network guard fails on a request to another host', async ({ page }) => {
    const guard = await guardNetwork(page, BASE_URL);
    await page.goto('/');
    // .invalid never resolves (RFC 2606), and the route aborts it anyway.
    await page.evaluate(() => fetch('https://a11y-guard.invalid/hub').catch(() => undefined));
    expect(guard.blocked).toEqual(['GET https://a11y-guard.invalid/hub']);
    expect(() => assertSiteOnly(guard)).toThrow();
  });

  const personas: Persona[] = ['member', 'voter', 'newcomer', 'steward', 'sitemanager'];
  for (const persona of personas) {
    test.describe(`as ${persona}`, () => {
      if (persona in PERSONA_USERS) {
        test.beforeAll(async () => {
          await sessionFor(PERSONA_USERS[persona as keyof typeof PERSONA_USERS], persona, BASE_URL);
        });
      }
      test.use({ storageState: authFile(persona) });

      for (const s of GOVERNANCE_STATES.filter((x) => x.persona === persona)) {
        test(`governance ${s.screen} ${s.state}`, async ({ page }) => {
          await runState(page, s);
        });
      }
    });
  }

  test.describe('dates (D-30)', () => {
    test.use({ storageState: authFile('voter') });

    test('governance dates read Full, Short and Time in the viewer timezone', async ({ page }) => {
      const guard = await guardNetwork(page, BASE_URL);
      await page.goto(`/group/${gov.groups.ready}/votes/${gov.proposals.carried_out}`);
      const carried = await contentText(page);
      expect(carried).toMatch(D30.full);
      expect(carried).toMatch(D30.short);
      await page.goto(`/group/${gov.groups.ready}/votes/${gov.proposals.safety_check}`);
      expect(await contentText(page)).toMatch(D30.time);
      // A relative time carries the Full date as its hover title.
      await page.goto(`/group/${gov.groups.ready}/votes`);
      const time = page.locator('time[title]').first();
      const title = await time.getAttribute('title');
      expect(title ?? '').toMatch(D30.full);
      // ARIA 1.2 prohibits aria-label on <time>; the Full date is visually
      // hidden text inside it (25-26 follow-up, F5).
      expect(await page.locator('time[title][aria-label]').count(), 'no relative time carries aria-label').toBe(0);
      expect(await time.locator('.visually-hidden').textContent()).toBe(`, ${title}`);
      assertSiteOnly(guard);
    });
  });
});
