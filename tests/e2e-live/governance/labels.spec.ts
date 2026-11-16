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
//      passes through;
//   4. with the public-record notice on (group_treasury 1.0.0-alpha8, as a fresh install has it),
//      "Approve the setup", "Confirm in your wallet" and "Cast your vote" are stopped while the
//      box is unticked and go through after tickPublicNotice (the helper the judge uses).
//      A live run stopped at "Approve the setup" this way.
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
import { test, expect, type Locator, type Page, type Response } from '@playwright/test';
import {
  PERSONA_USERS,
  assertSiteOnly,
  authFile,
  drush,
  guardNetwork,
  seedGovernance,
  sessionFor,
  setSponsor,
  SIWE_CDN,
  type Governance,
} from '../../a11y/helpers/governance-states';
import { pressWalletChoice, WALLET_UNAVAILABLE } from '../wallet-choice';
import { noticeError, tickPublicNotice, unreadNotice } from '../../e2e/helpers/public-notice';
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
import { confirmAccountForSignIn, SIGN_IN_PASSWORD_NOT_SAVED } from './live-steps';
import { GUARD_SECRET } from './guard-secret';

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
          (window as unknown as { __lnSignAsks: number }).__lnSignAsks = asked;
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

/**
 * Removes every seeded user's public-record tick in every seeded group (group_treasury's own
 * PublicNotice::withdraw), so the notice is unread again on a rerun. Does nothing where the notice
 * service is absent (group_treasury before 1.0.0-alpha8).
 */
function forgetNoticeTicks(gov: Governance): void {
  const users = Object.values(gov.users).join(',');
  const groups = Object.values(gov.groups).filter((id) => id > 0).join(',');
  drush(['php:eval', [
    "if (!\\Drupal::hasService('group_treasury.public_notice')) return;",
    "$n = \\Drupal::service('group_treasury.public_notice');",
    `foreach (\\Drupal\\group\\Entity\\Group::loadMultiple([${groups}]) as $g)`,
    `  foreach (\\Drupal\\user\\Entity\\User::loadMultiple([${users}]) as $u) $n->withdraw($u, $g);`,
  ].join(' ')]);
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
    forgetNoticeTicks(gov);
    await sessionFor(PERSONA_USERS.voter, 'voter', BASE_URL);
  });

  const votes = (key: string, tail = '') => `/group/${gov.groups[key]}/votes${tail}`;

  /** How many typed-data requests the stub wallet has been asked (0 before any). */
  const signAsks = (page: Page) => page.evaluate(() => (window as unknown as { __lnSignAsks?: number }).__lnSignAsks ?? 0);

  /**
   * Presses `button` with the notice unticked and checks the notice stopped it in the page (its
   * own error shows; no wallet request and no fetch to the site), then ticks it with
   * the judge's helper and presses again: the error goes, and the step goes on (the wallet is
   * asked, or the step's route is called), with no answer refusing it for the notice
   * (422 code "public_notice").
   */
  async function stoppedUntilTicked(page: Page, button: Locator, what: string): Promise<void> {
    const steps: { status: number; notice: boolean }[] = [];
    const onResponse = async (r: Response) => {
      if (!['fetch', 'xhr'].includes(r.request().resourceType()) || !r.url().startsWith(BASE_URL) || /\/public-notice(?:\?|$)/.test(new URL(r.url()).pathname)) return;
      const body = (await r.json().catch(() => ({}))) as { code?: unknown };
      steps.push({ status: r.status(), notice: body.code === 'public_notice' });
    };
    page.on('response', onResponse);
    try {
      await once(unreadNotice(page), `the public-record notice above ${what}`);
      await button.click();
      await expect(noticeError(page), `${what} is stopped while the box is unticked`).toHaveCount(1, { timeout: 5_000 });
      await page.waitForTimeout(1_000);
      expect(await signAsks(page), `the wallet is not asked while the box is unticked (${what})`).toBe(0);
      expect(steps.length, `no step request is sent while the box is unticked (${what})`).toBe(0);
      expect(await tickPublicNotice(page), `the judge's helper ticks the notice above ${what}`).toBe('ticked');
      await button.click();
      await expect(noticeError(page), `${what} is not stopped once ticked`).toHaveCount(0, { timeout: 5_000 });
      await expect.poll(async () => (await signAsks(page)) + steps.length, { message: `${what} goes on once ticked`, timeout: 15_000 }).toBeGreaterThan(0);
      expect(steps.filter((x) => x.notice).length, `no step request is refused for the notice (${what})`).toBe(0);
    } finally {
      page.off('response', onResponse);
    }
  }

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

    test('V7 the public-record notice stops Approve the setup until the box is ticked', async ({ page }) => {
      await stubWallet(page, gov.wallets.steward, 0);
      const done = await open(page, votes('progress', '/setup'));
      const v7 = setupProgress(page);
      await once(v7.approveTheSetup, 'Approve the setup');
      await stoppedUntilTicked(page, v7.approveTheSetup, 'Approve the setup');
      await expect(v7.confirmation(1, 3)).toBeVisible({ timeout: 15_000 });
      // The tick is kept for this account in this group: the notice renders read after a reload.
      await page.reload();
      await once(v7.approveTheSetup, 'Approve the setup, after the reload');
      await expect(unreadNotice(page), 'no unread notice once this account has ticked it').toHaveCount(0);
      expect(await tickPublicNotice(page)).toBe('none');
      done();
    });

    for (const n of [1, 2, 3]) {
      test(`V7 approve the setup: Confirmation ${n} of 3`, async ({ page }) => {
        await stubWallet(page, gov.wallets.steward, n - 1);
        const done = await open(page, votes('progress', '/setup'));
        const v7 = setupProgress(page);
        await once(v7.region, 'the Setting up group voting panel');
        await once(v7.approvals(0, 2, 2), 'the approvals line');
        await once(v7.approveTheSetup, 'Approve the setup');
        // Ticks the notice when this test runs alone; returns 'none' after the test above.
        await tickPublicNotice(page);
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
      // The steward has not ticked in this group (only in the progress group above).
      await stoppedUntilTicked(page, v5.confirmInYourWallet, 'Confirm in your wallet');
      done();
    });
  });

  test.describe('as a member who can vote', () => {
    test.use({ storageState: authFile('voter') });

    test('V2 and V3 open payment: the vote form', async ({ page }) => {
      // The stub never signs, so nothing is recorded and the seeded proposal stays open.
      await stubWallet(page, gov.wallets.voter, 0);
      const done = await open(page, votes('ready', `/${gov.proposals.open_payment}`));
      const v3 = proposalPage(page);
      await once(v3.voteChoices, 'How do you vote?');
      for (const choice of [COPY.voteFor, COPY.voteAgainst, COPY.voteAbstain]) await once(v3.choice(choice), choice);
      await once(v3.castYourVote, 'Cast your vote');
      await once(v3.payTo, 'Pay to');
      await once(v3.publicRecord, 'See this vote\'s public record');
      await v3.choice(COPY.voteFor).check();
      await stoppedUntilTicked(page, v3.castYourVote, 'Cast your vote');
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

// The live tests' sign-in (wallet-choice.ts, used by governance/judge-wallet.ts and
// live-sepolia.e2e.ts) on this site's real /user/login, signed out. siwe_login's own button is
// hidden there; "Use a browser wallet" hands it the click. Runs 43709180949 and 43793452239
// failed live because the tests waited for the hidden button to be visible; this guard fails
// here first if the page changes again. Nothing signs: a capture listener counts the click on
// siwe_login's button and stops it before siwe_login's handler runs.
test.describe('governance labels: the live tests\' wallet sign-in on /user/login', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  // siwe_login_waap's own SDK files (its sdk_imports), when that module is on: aborted like
  // SIWE_CDN, listed so only an unexpected host counts as leaving the site.
  const WAAP_CDN = /^https:\/\/cdn\.jsdelivr\.net\/npm\/(@human\.tech\/waap-sdk@|ethers@|eventemitter3@)/;

  /** Opens /user/login with a stub wallet; `ethers` false leaves the page without ethers. */
  async function signInPage(page: Page, ethers: boolean): Promise<() => void> {
    const guard = await guardNetwork(page, BASE_URL, [SIWE_CDN, WAAP_CDN]);
    if (ethers) {
      // The CDN request stays aborted (and its script carries an integrity hash, so it can't be
      // answered here); a stub stands in before the page's scripts run. siwe_login reads ethers
      // only after the click, which stops.
      await page.addInitScript(() => { (window as unknown as { ethers: object }).ethers = {}; });
    }
    await stubWallet(page, '0x' + '22'.repeat(20), 0);
    const response = await page.goto('/user/login');
    expect(response?.status(), '/user/login answers 200').toBe(200);
    await page.evaluate(() => {
      (window as unknown as { __lnWallet: number }).__lnWallet = 0;
      document.getElementById('siwe-login-button')?.addEventListener('click', (e) => {
        e.stopImmediatePropagation();
        e.preventDefault();
        (window as unknown as { __lnWallet: number }).__lnWallet++;
      }, true);
    });
    // A count with a fixed label, never assertSiteOnly's list: no outside URL reaches a log.
    return () => expect(guard.blocked.length, 'requests that left the site (count only)').toBe(0);
  }

  test('"Use a browser wallet" reaches siwe_login\'s hidden button', async ({ page }) => {
    const done = await signInPage(page, true);
    await expect(page.locator('#siwe-login-button'), 'siwe_login\'s own button is hidden').toBeHidden();
    await pressWalletChoice(page, 10_000);
    await expect.poll(() => page.evaluate(() => (window as unknown as { __lnWallet: number }).__lnWallet), { timeout: 5_000 }).toBe(1);
    done();
  });

  test('wallet scripts missing: the sign-in fails with the fixed "not available" message', async ({ page }) => {
    const done = await signInPage(page, false);
    await expect(pressWalletChoice(page, 10_000)).rejects.toThrow(WALLET_UNAVAILABLE);
    expect(await page.evaluate(() => (window as unknown as { __lnWallet: number }).__lnWallet)).toBe(0);
    done();
  });
});

test.describe('governance labels: the member adds a wallet sign-in (siwe_login beta12)', () => {
  test.use({ storageState: { cookies: [], origins: [] } });
  const NAME = 'ln-attach-guard';
  const ADDRESS = '0x' + '3c'.repeat(20);

  /** A member with no wallet sign-in and an unknown password, like the judge's ci-member. */
  function freshMember(): string {
    return drush(['php:eval', [
      `$s = \\Drupal::entityTypeManager()->getStorage('user');`,
      `foreach ($s->loadByProperties(['name' => '${NAME}']) as $old) { $old->delete(); }`,
      `$u = \\Drupal\\user\\Entity\\User::create(['name' => '${NAME}', 'mail' => '${NAME}@example.com', 'status' => 1, 'pass' => \\Drupal::service('password_generator')->generate(32)]);`,
      `$u->save(); echo $u->id();`,
    ].join(' ')]).trim();
  }

  test.afterAll(() => {
    drush(['php:eval', `foreach (\\Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => '${NAME}']) as $u) { $u->delete(); }`]);
  });

  test('the judge\'s confirmAccountForSignIn reaches "Continue" from a one-time login link', async ({ page }) => {
    const uid = freshMember();
    expect(uid, 'the guard member was created').toMatch(/^\d+$/);
    // The same link shape the judge gets (seed-governance-live.php: user_pass_reset_url + /login).
    const link = drush(['uli', `--name=${NAME}`, '--no-browser', `--uri=${BASE_URL}`]).trim().split('\n').pop() ?? '';
    await page.goto(link);
    await confirmAccountForSignIn(page, uid, ADDRESS);
    // The judge presses this button next; the old "Connect your wallet" label is gone at beta12.
    await expect(page.getByRole('button', { name: 'Continue', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Connect your wallet', exact: true })).toHaveCount(0);
  });

  // The three guards below use the known GUARD_SECRET. secret-scan-reporter.ts (labels.config.ts)
  // fails the whole run if it reaches any step title, step or test error, output or attachment.

  /** Opens a one-time login link and makes the second password field unfillable. */
  async function failingFormFor(page: Page): Promise<string> {
    const uid = freshMember();
    const link = drush(['uli', `--name=${NAME}`, '--no-browser', `--uri=${BASE_URL}`]).trim().split('\n').pop() ?? '';
    await page.goto(link);
    // The second field can't be filled, after the first one already holds the password.
    await page.locator('input[name="pass[pass2]"]').evaluate((el) => { (el as HTMLInputElement).type = 'checkbox'; });
    return uid;
  }

  /** True when the first password field still holds the guard password (checked as a boolean only). */
  async function passwordStillOnPage(page: Page): Promise<boolean> {
    return page.locator('input[name="pass[pass1]"]').evaluate((el, v) => (el as HTMLInputElement).value === v, GUARD_SECRET);
  }

  test('a failing password fill throws only the fixed message, and the fields are emptied', async ({ page }) => {
    const uid = await failingFormFor(page);
    const failure = await confirmAccountForSignIn(page, uid, ADDRESS, { makePassword: () => GUARD_SECRET }).then(() => null, (e: unknown) => e);
    expect(failure, 'the helper failed').toBeInstanceOf(Error);
    const error = failure as Error;
    expect(error.message).toBe(SIGN_IN_PASSWORD_NOT_SAVED);
    expect(error.cause, 'no original error is attached').toBeUndefined();
    expect(`${error.message}\n${error.stack ?? ''}`.includes(GUARD_SECRET), 'what the reporter prints holds no password').toBe(false);
    const snapshot = await page.locator('body').ariaSnapshot();
    expect(snapshot.includes(GUARD_SECRET), 'the page snapshot holds no password').toBe(false);
  });

  // These two must fail, with the password still on the page, so Playwright writes
  // error-context.md; PLAYWRIGHT_NO_COPY_PROMPT keeps the page snapshot out of it.
  for (const [name, emptyFields] of [
    ['rejects', () => Promise.reject(new Error('cleanup refused'))],
    ['times out', () => new Promise<void>(() => undefined)],
  ] as const) {
    test(`the field cleanup ${name}: the failure writes no password to error-context.md`, async ({ page }) => {
      test.fail(true, 'the helper must fail here, so that the failure output is written');
      const uid = await failingFormFor(page);
      const failure = await confirmAccountForSignIn(page, uid, ADDRESS, { makePassword: () => GUARD_SECRET, emptyFields }).then(() => null, (e: unknown) => e);
      expect(failure instanceof Error && failure.message === SIGN_IN_PASSWORD_NOT_SAVED, 'the helper failed with the fixed message').toBe(true);
      expect(await passwordStillOnPage(page), 'the injected cleanup failure left the password on the page').toBe(true);
      throw failure;
    });
  }

  test('without the confirmation the wallet page offers only "Add a sign-in"', async ({ page }) => {
    const uid = freshMember();
    const link = drush(['uli', `--name=${NAME}`, '--no-browser', `--uri=${BASE_URL}`]).trim().split('\n').pop() ?? '';
    await page.goto(link);
    await page.goto(`/user/${uid}/wallet`);
    await expect(page.getByRole('button', { name: 'Add a sign-in', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Continue', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Connect your wallet', exact: true })).toHaveCount(0);
  });
});
