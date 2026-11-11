// Labels-only proof for criterion 4 (25-27 Task 1, D-23).
//
// The live judge (tests/e2e-live/governance-judge.e2e.ts) drives the Phase 25 screens through
// one page object, governance/pages.ts, whose locators use only getByRole, getByLabel and
// getByText with the Phase 25 Copywriting Contract strings. This spec proves, before any live
// run, that:
//   1. pages.ts holds no CSS locator, test id or query selector (with a mutation case that shows
//      the check bites);
//   2. every copy string pages.ts uses appears verbatim in the contract strings held beside this
//      spec (labels.fixture.ts), except the few shipped strings that the contract didn't hold
//      when pages.ts was written, each listed with its source;
//   3. on the lane's fixture site, every locator resolves exactly once in each state the judge
//      passes through.
//
// States come from 25-26's fixture seed (tests/a11y/seed-governance.php) and personas
// (steward = organizer and approver, voter = member with a wallet). Nothing signs: where the
// judge's MetaMask would be asked, a stub wallet in the page answers or waits. Every request to
// a host other than the site is aborted and fails the test.
//
// Lane mutation: the V6 block gives a11y-steward a role holding 'administer safe governance'
// (the admin-only test-group overrides render only with it) and removes it afterwards.
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect, type Locator, type Page } from '@playwright/test';
import {
  PERSONA_USERS,
  assertSiteOnly,
  authFile,
  drush,
  guardNetwork,
  seedGovernance,
  sessionFor,
  setSponsor,
  type Governance,
} from '../../a11y/helpers/governance-states';
import {
  COPY,
  SHIPPED_NOT_IN_SPEC,
  approveMembers,
  carryOut,
  createProposal,
  fill,
  groupTabs,
  proposalPage,
  setupForm,
  setupProgress,
  votesTab,
} from './pages';
import { COPYWRITING_CONTRACT } from './labels.fixture';

const HERE = dirname(fileURLToPath(import.meta.url));
const PAGES_FILE = join(HERE, 'pages.ts');
const BASE_URL = process.env.LN_BASE_URL ?? 'https://fresh3.ddev.site';
const SEPOLIA_HEX = '0xaa36a7';

/** CSS locators, test ids and query selectors: none may appear in pages.ts. */
export const FORBIDDEN = /\blocator\s*\(|getByTestId|data-testid|querySelector|\$\$?eval\s*\(|\bpage\.\$\$?\s*\(/;
/** A copy literal handed straight to a getBy call, instead of a COPY entry. */
export const LITERAL_COPY = /getBy(?:Label|Text|Placeholder|Title|AltText)\(\s*['"`]|\bname:\s*['"`]/;

/** Exactly one element matches. */
async function once(locator: Locator, what: string): Promise<void> {
  await expect(locator, `${what} resolves exactly once`).toHaveCount(1);
}

/**
 * A stub wallet (window.ethereum), never a real one: it answers the account and the chain,
 * signs the first `signs` typed-data requests with a made-up signature after a short pause, and
 * leaves the next one waiting, so a signing panel's waiting state stays on screen.
 */
async function stubWallet(page: Page, account: string, signs: number): Promise<void> {
  await page.addInitScript(({ account, chain, signs }) => {
    let asked = 0;
    (window as unknown as { ethereum: unknown }).ethereum = {
      isStub: true,
      request: ({ method }: { method: string }) => {
        if (method === 'eth_requestAccounts' || method === 'eth_accounts') return Promise.resolve([account]);
        if (method === 'eth_chainId') return Promise.resolve(chain);
        if (method === 'eth_signTypedData_v4') {
          asked += 1;
          if (asked <= signs) return new Promise((resolve) => setTimeout(() => resolve('0x' + '11'.repeat(64) + '1b'), 400));
          return new Promise(() => undefined);
        }
        return Promise.reject(new Error(`stub wallet: ${method}`));
      },
      on: () => undefined,
      removeListener: () => undefined,
    };
  }, { account, chain: SEPOLIA_HEX, signs });
}

test.describe('governance labels: the page object itself', () => {
  const source = readFileSync(PAGES_FILE, 'utf8');

  test('pages.ts uses no CSS locator, test id or query selector', () => {
    const hits = source.split('\n').map((line, i) => [i + 1, line] as const).filter(([, line]) => FORBIDDEN.test(line));
    expect(hits, 'pages.ts lines with a CSS locator, test id or query selector').toEqual([]);
  });

  test('the forbidden-locator check bites (mutation cases)', () => {
    const planted = [
      "page.locator('#siwe-login-button')",
      "panel.locator ('.badge')",
      "page.getByTestId('confirm-btn')",
      '[data-testid="x"]',
      "document.querySelector('form')",
      "page.$$eval('a', (a) => a.length)",
      "page.$('h1')",
    ];
    const missed = planted.filter((line) => !FORBIDDEN.test(line));
    expect(missed, 'planted locators the check missed').toEqual([]);
    expect(FORBIDDEN.test("page.getByRole('button', { name: COPY.castYourVote })"), 'a role locator passes').toBe(false);
  });

  test('pages.ts hands every getBy call a COPY entry, never a literal', () => {
    const hits = source.split('\n').map((line, i) => [i + 1, line] as const).filter(([, line]) => LITERAL_COPY.test(line));
    expect(hits, 'pages.ts lines passing literal copy to a locator').toEqual([]);
    expect(LITERAL_COPY.test("page.getByText('Cast your vote')"), 'the literal check bites').toBe(true);
    expect(LITERAL_COPY.test("page.getByRole('button', { name: 'Cast your vote' })"), 'the literal check bites on names').toBe(true);
  });

  test('every copy string in pages.ts appears verbatim in the contract strings', () => {
    const missing = Object.entries(COPY).filter(([, text]) => !COPYWRITING_CONTRACT.includes(text)).map(([key, text]) => `${key}: ${text}`);
    expect(missing, 'COPY entries not in labels.fixture.ts (accepted shipped copy belongs in SHIPPED_NOT_IN_SPEC)').toEqual([]);
    expect(Object.keys(COPY).length).toBeGreaterThan(40);
  });

  test('accepted shipped copy is a real mismatch with a recorded source', () => {
    for (const [key, entry] of Object.entries(SHIPPED_NOT_IN_SPEC)) {
      expect(COPYWRITING_CONTRACT.includes(entry.text), `${key} is in the contract strings, so it belongs in COPY`).toBe(false);
      expect(entry.source.length, `${key} records where it was accepted`).toBeGreaterThan(10);
    }
  });
});

test.describe('governance labels: the judge\'s states on the lane site', () => {
  let gov: Governance;

  test.beforeAll(async () => {
    gov = seedGovernance();
    setSponsor('configured');
    await sessionFor(PERSONA_USERS.voter, 'voter', BASE_URL);
  });

  const votes = (key: string, tail = '') => `/group/${gov.groups[key]}/votes${tail}`;

  async function open(page: Page, path: string): Promise<() => void> {
    const guard = await guardNetwork(page, BASE_URL);
    const response = await page.goto(path);
    expect(response?.status(), `${path} answers 200`).toBe(200);
    return () => assertSiteOnly(guard);
  }

  test.describe('as the organizer', () => {
    test.use({ storageState: authFile('steward') });

    test('group tabs: the Votes tab', async ({ page }) => {
      const done = await open(page, `/group/${gov.groups.setup}/treasury`);
      await once(groupTabs(page).votes, 'the Votes tab');
      done();
    });

    test('V1, fund ready, voting not set up: Set up group voting', async ({ page }) => {
      const done = await open(page, votes('setup'));
      await once(votesTab(page).setUpGroupVoting, 'Set up group voting');
      done();
    });

    test.describe('V6 with the admin-only test-group overrides', () => {
      const ROLE = 'ln_labels_governance_admin';
      test.beforeAll(() => {
        drush(['php:eval', [
          `$r = \\Drupal\\user\\Entity\\Role::load('${ROLE}') ?? \\Drupal\\user\\Entity\\Role::create(['id' => '${ROLE}', 'label' => 'Labels check governance admin']);`,
          "$r->grantPermission('administer safe governance'); $r->save();",
          `$u = user_load_by_name('a11y-steward'); $u->addRole('${ROLE}'); $u->save();`,
        ].join(' ')]);
      });
      test.afterAll(() => {
        drush(['php:eval', `$u = user_load_by_name('a11y-steward'); $u->removeRole('${ROLE}'); $u->save(); \\Drupal\\user\\Entity\\Role::load('${ROLE}')?->delete();`]);
      });

      test('V6 set up group voting: every field the judge fills', async ({ page }) => {
        const done = await open(page, votes('setup', '/setup'));
        const v6 = setupForm(page);
        await once(v6.votingPageName, 'the voting page name field');
        await once(v6.votesNeeded, 'the votes-needed field');
        await once(v6.votingLength(COPY.oneDay), 'the 1 day choice');
        await once(v6.anyMemberCanPropose, 'Any member who can vote');
        await once(v6.safetyCheckHours, 'the safety-check field');
        await once(v6.newMembersAutomatically, 'the new-members checkbox');
        // D-33: this seeded group is invitation or request only, so the box starts checked.
        await expect(v6.newMembersAutomatically).toBeChecked();
        await once(v6.adminDetails, 'Technical details for administrators');
        await v6.adminDetails.click();
        await once(v6.votingPeriodMinutes, 'Voting period in minutes');
        await once(v6.safetyCheckSeconds, 'Safety check in seconds');
        await once(v6.waitingSeconds, 'Waiting time after the safety check in seconds');
        await once(v6.start, 'Start voting setup');
        done();
      });
    });

    for (const n of [1, 2, 3]) {
      test(`V7 approve the setup: Confirmation ${n} of 3`, async ({ page }) => {
        await stubWallet(page, gov.wallets.steward, n - 1);
        const done = await open(page, votes('progress', '/setup'));
        const v7 = setupProgress(page);
        await once(v7.region, 'the Setting up group voting panel');
        await once(v7.approvals(0, 2, 2), 'the approvals line');
        await once(v7.approveTheSetup, 'Approve the setup');
        await v7.approveTheSetup.click();
        await expect(v7.confirmation(n, 3)).toBeVisible({ timeout: 15_000 });
        await once(v7.confirmation(n, 3), `Confirmation ${n} of 3`);
        done();
      });
    }

    test('V7 ready: Group voting is ready, its links and the technical details', async ({ page }) => {
      const done = await open(page, votes('ready', '/setup'));
      const v7 = setupProgress(page);
      await once(v7.ready, 'Group voting is ready.');
      await once(v7.approveNewMembers, 'Approve new members');
      await once(v7.openVotes, 'Open votes');
      await once(v7.votingPageLink, 'See the group\'s voting page');
      await once(v7.technicalDetails, 'Technical details for other tools');
      await v7.technicalDetails.click();
      await once(v7.memberRole, 'the member role line');
      await once(v7.votingPage, 'the voting page line');
      await once(v7.carryOutStep, 'the carry-out step line');
      expect(await v7.carryOutModule()).toMatch(/^0x[0-9a-fA-F]{40}$/);
      expect(await v7.topHatDomain()).toMatch(/^\d+$/);
      done();
    });

    test('V8 approve new members: the auto-selected row', async ({ page }) => {
      const done = await open(page, votes('ready', '/members'));
      const v8 = approveMembers(page);
      const row = v8.row('Kai Moreno', COPY.letThemVote);
      await once(row, 'the newcomer\'s Let them vote row');
      await expect(row).toBeChecked();
      await once(v8.selectedBecauseNewMembers, 'Selected because new members are added automatically.');
      await once(v8.approveSelected, 'the Approve changes primary');
      await once(v8.votingMembers(2), 'the voting members line');
      done();
    });

    test('V8 approve new members: a batch in progress', async ({ page }) => {
      seedGovernance('state', 'batch');
      try {
        const done = await open(page, votes('ready', '/members'));
        await once(approveMembers(page).approveTheseChanges, 'Approve these changes');
        done();
      }
      finally {
        seedGovernance();
      }
    });

    test('V1 set up: Create a proposal', async ({ page }) => {
      const done = await open(page, votes('ready'));
      await once(votesTab(page).createProposal, 'Create a proposal');
      done();
    });

    test('V5 choice and payment form, up to Confirm in your wallet', async ({ page }) => {
      await stubWallet(page, gov.wallets.steward, 0);
      const done = await open(page, votes('ready', '/new'));
      const v5 = createProposal(page);
      await once(v5.proposePayment, 'Propose a payment');
      await v5.proposePayment.click();
      await once(v5.title, 'Title');
      await once(v5.currency('ETH'), 'the ETH currency');
      await once(v5.amount, 'Amount');
      await once(v5.paidFrom, 'Paid from');
      await once(v5.payTo, 'Pay to');
      await once(v5.why, 'Why');
      await once(v5.publish, 'Publish proposal');
      await v5.title.fill('Labels check payment');
      await v5.currency('ETH').check();
      await v5.amount.fill('0.0001');
      await v5.payTo.fill(gov.wallets.voter);
      await v5.why.fill('The labels check reaches the wallet step and stops there.');
      await v5.publish.click();
      await expect(v5.confirmInYourWallet).toBeVisible({ timeout: 30_000 });
      await once(v5.confirmInYourWallet, 'Confirm in your wallet');
      done();
    });
  });

  test.describe('as a member who can vote', () => {
    test.use({ storageState: authFile('voter') });

    test('V2 and V3 open payment: the vote form', async ({ page }) => {
      const done = await open(page, votes('ready', `/${gov.proposals.open_payment}`));
      const v3 = proposalPage(page);
      await once(v3.voteChoices, 'How do you vote?');
      for (const choice of [COPY.voteFor, COPY.voteAgainst, COPY.voteAbstain]) await once(v3.choice(choice), choice);
      await once(v3.castYourVote, 'Cast your vote');
      await once(v3.payTo, 'Pay to');
      await once(v3.publicRecord, 'See this vote\'s public record');
      done();
    });

    test('V3 after voting: You voted For on', async ({ page }) => {
      const done = await open(page, votes('ready', `/${gov.proposals.open_decision}`));
      await once(proposalPage(page).youVoted, 'You voted ‹For› on ‹date›.');
      done();
    });

    test('V4 carried out: the step, the date and the Payment rows', async ({ page }) => {
      const done = await open(page, votes('ready', `/${gov.proposals.carried_out}`));
      const v4 = carryOut(page);
      await once(v4.region, 'Carrying out the payment');
      await once(v4.carriedOut, 'Carried out');
      await once(v4.wentOut, 'The payment went out on ‹date›.');
      await once(v4.amount, 'Amount');
      await once(v4.paidFrom, 'Paid from');
      await once(v4.payTo, 'Pay to');
      await once(v4.payee('Sam Lee'), 'the Pay to name');
      await once(v4.publicRecord, 'See the public record on Blockscout');
      expect(await v4.transactionHash()).toMatch(/^0x[0-9a-f]{64}$/);
      await once(proposalPage(page).outcomePassed, 'Passed: enough people voted, and more voted For than Against.');
      expect(fill(COPY.youVotedOn, 'For', 'x')).toBe('You voted For on x.');
      done();
    });
  });
});
