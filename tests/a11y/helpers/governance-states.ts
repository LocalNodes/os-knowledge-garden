// Shared set-up for the Phase 25 group governance a11y specs (25-26).
//
// The lane site runs the test-only fixture modules, so nothing leaves the
// site while these specs run:
//   - safe_smart_accounts_rpc_fixture: every Safe read answers from canned
//     captures (2 owners, threshold 2);
//   - safe_governance_chain_fixture: the governance reads (who wears a role,
//     the voting page name's owner, the carry-out step's owner) answer from
//     SYNTHETIC facts the seed writes to State;
//   - safe_governance_hub_fixture: the voting service; page views read
//     Drupal's mirror only, so the specs set no hub scenario;
//   - gas_sponsor_fixture: the community's network fee account (relayer).
//
// seed-governance.php (idempotent) makes the groups, funds, voting records,
// proposal mirrors and queue rows, and prints LN_GOVERNANCE={json}.
// `-- state <name>` switches one group to a named state between scans.
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium, expect, type Page, type Request } from '@playwright/test';
import { checkA11y } from './axe';

const HERE = dirname(fileURLToPath(import.meta.url));
export const REPO_ROOT = join(HERE, '..', '..', '..');
export const AUTH_DIR = join(HERE, '..', '.auth');

// The Phase 25 results folder: every scan saves a11y/axe-<screen>-<state>.json.
export const GOVERNANCE_RESULTS_DIR = join(
  REPO_ROOT,
  '.planning',
  'phases',
  '25-group-governance-snapshot-zodiac-hats',
  'a11y',
);

// Axe scope (22-14 convention): the content block and the h1. socialblue's
// navigation and the chat widget have violations of their own outside it.
export const SCOPE = ['#block-socialblue-content', 'h1'];

export interface Governance {
  groups: Record<string, number>;
  proposals: Record<string, number>;
  users: Record<string, number>;
  /** Linked wallets by persona (test addresses). */
  wallets: Record<string, string>;
  fund: string;
}

export function drush(args: string[]): string {
  return execFileSync('ddev', ['drush', ...args], {
    cwd: REPO_ROOT,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    timeout: 120_000,
  });
}

/** Runs seed-governance.php (idempotent) and parses its LN_GOVERNANCE line. */
export function seedGovernance(...mode: string[]): Governance {
  const output = drush(['php:script', 'tests/a11y/seed-governance.php', ...(mode.length ? ['--', ...mode] : [])]);
  const json = output.match(/^LN_GOVERNANCE=(\{.*\})\s*$/m)?.[1];
  expect(json, `seed-governance.php printed LN_GOVERNANCE. Output:\n${output}`).toBeTruthy();
  return JSON.parse(json!) as Governance;
}

/** Picks the network fee account fixture's scenario (gas_sponsor_fixture). */
export function setSponsor(scenario: string): void {
  drush(['state:set', 'gas_sponsor_fixture.scenario', scenario]);
}

/**
 * A stored session for a persona the seed made, by one-time login link.
 * Saved under tests/a11y/.auth/<persona>.json (gitignored).
 */
export async function sessionFor(name: string, file: string, baseURL: string): Promise<string> {
  const path = join(AUTH_DIR, `${file}.json`);
  mkdirSync(AUTH_DIR, { recursive: true });
  const link = drush(['uli', `--name=${name}`, `--uri=${baseURL}`, '--no-browser']).trim().split('\n').pop()!.trim();
  expect(link, `drush uli gave a login link for ${name}`).toMatch(/^https?:\/\//);
  const channel = process.env.LN_BROWSER_CHANNEL ?? 'chrome';
  const browser = await chromium.launch({ channel: channel === 'chromium' ? undefined : channel, headless: true });
  try {
    // A clean context: inside a test run, newContext otherwise inherits the
    // project's storageState, which may be the file this call makes.
    const context = await browser.newContext({ ignoreHTTPSErrors: true, storageState: { cookies: [], origins: [] } });
    const page = await context.newPage();
    await page.goto(link);
    const cookies = await context.cookies();
    expect(cookies.some((c) => /^S?SESS/.test(c.name)), `a Drupal session for ${name}`).toBe(true);
    await context.storageState({ path });
    await context.close();
  }
  finally {
    await browser.close();
  }
  return path;
}

export interface NetworkGuard {
  /** Requests the page tried to send to a host other than the site's. */
  blocked: string[];
  /** Of those, the known ones a state names (aborted all the same). */
  known: string[];
}

/**
 * Fails the scan on any request to a host other than the site's (TM-141).
 *
 * Copies the 22 AI guard's counting approach: every request the page makes
 * is seen through the 'request' event, independently of the route, and
 * every request to another host is aborted by the route, so nothing reaches
 * a third-party governance host. assertSiteOnly() fails when any was seen.
 */
export async function guardNetwork(page: Page, siteURL: string, known: RegExp[] = []): Promise<NetworkGuard> {
  const site = new URL(siteURL).host;
  const guard: NetworkGuard = { blocked: [], known: [] };
  const offSite = (url: string) => {
    if (!/^https?:/.test(url)) return false;
    return new URL(url).host !== site;
  };
  page.on('request', (request: Request) => {
    if (!offSite(request.url())) return;
    const entry = `${request.method()} ${request.url()}`;
    (known.some((pattern) => pattern.test(request.url())) ? guard.known : guard.blocked).push(entry);
  });
  await page.route((url) => offSite(url.toString()), (route) => route.abort('blockedbyclient'));
  return guard;
}

/** Fails unless every request the page made stayed on the site. */
export function assertSiteOnly(guard: NetworkGuard): void {
  expect(guard.blocked, 'no request left the site').toEqual([]);
}

/** axe on one governance state, saved to the Phase 25 folder. */
export async function scan(page: Page, screen: string, state: string, include: string[] = SCOPE) {
  return checkA11y(page, screen, state, { include, resultsDir: GOVERNANCE_RESULTS_DIR });
}

// V10 is an admin page (the site's admin theme): its content is <main>.
export const ADMIN_SCOPE = ['main'];

/**
 * siwe_login's "Connect your wallet" button (D-11) loads ethers from a CDN.
 * That is siwe_login's own code, outside this phase: the guard still aborts
 * the request, and the run records it as a finding (25-A11Y.md).
 */
export const SIWE_CDN = /^https:\/\/cdn\.jsdelivr\.net\/npm\/ethers@/;

// ---------------------------------------------------------------------------
// Every state of V1 to V10 (25-UI-SPEC UI Considerations), as one table.
// ---------------------------------------------------------------------------

export type Persona = 'member' | 'voter' | 'newcomer' | 'steward' | 'sitemanager';

/** Who each persona is (seed-governance.php). */
export const PERSONA_USERS: Record<Exclude<Persona, 'member' | 'steward'>, string> = {
  voter: 'a11y-voter',
  newcomer: 'a11y-newcomer',
  sitemanager: 'a11y-sitemanager',
};

/** A session file for a persona; member and steward come from global-setup. */
export function authFile(persona: Persona): string {
  return join(AUTH_DIR, `${persona}.json`);
}

export interface GovernanceState {
  screen: string;
  state: string;
  persona: Persona;
  path: (g: Governance) => string;
  /** seed-governance.php `-- state <name>` before the scan (default base). */
  seed?: string;
  /** gas_sponsor_fixture scenario for the scan (default configured). */
  sponsor?: string;
  /** Text that must show before the scan, proving the state is the right one. */
  expect: string | RegExp;
  /** Steps after the page loads (a click, a key), before the scan. */
  act?: (page: Page) => Promise<void>;
  /** A wallet in the browser (a stub, never a real one). */
  wallet?: 'pending' | 'second_pending';
  /** The axe scope and the text root (default: the content block and h1). */
  scope?: string[];
  /** Off-site requests this state is known to attempt (aborted, recorded). */
  knownOffSite?: RegExp[];
}

const ready = (g: Governance) => `/group/${g.groups.ready}/votes`;
const proposal = (key: string) => (g: Governance) => `${ready(g)}/${g.proposals[key]}`;
const votesOf = (key: string, tail = '') => (g: Governance) => `/group/${g.groups[key]}/votes${tail}`;

/** Presses the form's primary with nothing filled in. */
const submitEmpty = (name: string) => async (page: Page) => {
  await page.getByRole('button', { name }).click();
};

export const GOVERNANCE_STATES: GovernanceState[] = [
  // V1 Votes tab.
  { screen: 'V1', state: 'member-ready', persona: 'member', path: ready, expect: 'How this group votes' },
  { screen: 'V1', state: 'voter-ready', persona: 'voter', path: ready, expect: /You can vote\./ },
  { screen: 'V1', state: 'member-no-proposals', persona: 'member', path: votesOf('quiet'), expect: 'No proposals yet' },
  { screen: 'V1', state: 'member-not-set-up', persona: 'member', path: votesOf('setup'), expect: 'When organizers set it up, proposals show here.' },
  { screen: 'V1', state: 'member-setup-in-progress', persona: 'member', path: votesOf('progress'), expect: 'Organizers are setting up voting.' },
  { screen: 'V1', state: 'member-no-fund', persona: 'member', path: votesOf('no_fund'), expect: "Voting isn't set up yet" },
  { screen: 'V1', state: 'hub-last-known', persona: 'member', path: ready, seed: 'hub_last_known', expect: "Couldn't load the latest votes right now." },
  { screen: 'V1', state: 'hub-never-read', persona: 'member', path: votesOf('quiet'), seed: 'hub_never_read', expect: "Couldn't load the votes right now." },
  { screen: 'V1', state: 'organizer-changes-waiting', persona: 'steward', path: ready, expect: 'changes are waiting for organizers to approve.' },
  { screen: 'V1', state: 'organizer-not-set-up', persona: 'steward', path: votesOf('setup'), expect: 'Set up group voting' },
  { screen: 'V1', state: 'organizer-setup-in-progress', persona: 'steward', path: votesOf('progress'), expect: "Voting setup isn't finished." },
  { screen: 'V1', state: 'organizer-no-fund', persona: 'steward', path: votesOf('no_fund'), expect: 'Open the group fund' },
  { screen: 'V1', state: 'organizer-host-control', persona: 'steward', path: votesOf('quiet'), expect: "Something gives this site's host control over the group's votes" },
  { screen: 'V1', state: 'organizer-fee-limit', persona: 'steward', path: ready, sponsor: 'cap_day', expect: "The community's network fee limit is reached." },

  // V2 proposal page and V3 vote flow.
  { screen: 'V2', state: 'open', persona: 'voter', path: proposal('open_payment'), expect: 'How do you vote?' },
  { screen: 'V2', state: 'counting', persona: 'voter', path: proposal('counting'), expect: 'Counting the votes' },
  { screen: 'V2', state: 'passed-decision', persona: 'voter', path: proposal('passed_decision'), expect: 'Passed: enough people voted, and more voted For than Against.' },
  { screen: 'V2', state: 'not-passed', persona: 'voter', path: proposal('not_passed'), expect: "Didn't pass: not enough people voted." },
  { screen: 'V2', state: 'recipient-outside', persona: 'voter', path: (g) => `${proposal('outside')(g)}/recipient`, expect: 'This payment goes to an account outside the group.' },
  { screen: 'V3', state: 'connect-wallet', persona: 'member', path: proposal('open_payment'), expect: 'Connect a wallet to vote', knownOffSite: [SIWE_CDN] },
  { screen: 'V3', state: 'not-approved', persona: 'newcomer', path: proposal('open_payment'), expect: "You're on the list for organizers to approve." },
  { screen: 'V3', state: 'voted', persona: 'voter', path: proposal('open_decision'), expect: /You voted For on / },
  { screen: 'V3', state: 'no-choice', persona: 'voter', path: proposal('open_payment'), act: submitEmpty('Cast your vote'), expect: 'Choose For, Against or Abstain.' },
  {
    screen: 'V3', state: 'signing', persona: 'voter', path: proposal('open_payment'), wallet: 'pending',
    act: async (page) => {
      await page.getByRole('radio', { name: 'For' }).check();
      await page.getByRole('button', { name: 'Cast your vote' }).click();
    },
    expect: 'Waiting for your approval',
  },

  // V4 carrying out a passed payment. The safety check's end is D-30 Time or
  // Short: Short ("Nov 9, 2:30 PM MST") once it falls on another day, so the
  // state passes across midnight (25-28 baseline answers, Q4 (b)).
  { screen: 'V4', state: 'reporting', persona: 'voter', path: proposal('reporting'), expect: 'The result is being added to the public record.' },
  { screen: 'V4', state: 'safety-check', persona: 'voter', path: proposal('safety_check'), expect: /Anyone can check the result until (?:[A-Z][a-z]{2} \d{1,2}, )?\d{1,2}:\d{2} [AP]M [A-Z]{2,4}\./ },
  { screen: 'V4', state: 'sending', persona: 'voter', path: proposal('sending'), expect: 'Sending the payment' },
  { screen: 'V4', state: 'carried-out', persona: 'voter', path: proposal('carried_out'), expect: /The payment went out on [A-Z][a-z]+ \d{1,2}, \d{4} at \d{1,2}:\d{2} [AP]M [A-Z]{2,4}\./ },
  { screen: 'V4', state: 'failed', persona: 'voter', path: proposal('failed'), expect: "The payment didn't go through. No money moved. Organizers have been told." },
  { screen: 'V4', state: 'failed-organizer', persona: 'steward', path: proposal('failed'), expect: 'Try carrying it out again' },
  { screen: 'V4', state: 'on-hold', persona: 'voter', path: proposal('on_hold'), expect: 'On hold. Someone reported a different result' },
  { screen: 'V4', state: 'low-balance', persona: 'voter', path: proposal('low_balance'), expect: 'Waiting for enough money.' },

  // V5 creating a proposal (the steward wears the proposer role).
  { screen: 'V5', state: 'choice', persona: 'steward', path: (g) => `${ready(g)}/new`, expect: 'Propose a decision' },
  { screen: 'V5', state: 'payment-form', persona: 'steward', path: (g) => `${ready(g)}/new/payment`, expect: 'Paid from' },
  { screen: 'V5', state: 'decision-form', persona: 'steward', path: (g) => `${ready(g)}/new/decision`, expect: 'What the group is deciding' },
  { screen: 'V5', state: 'errors', persona: 'steward', path: (g) => `${ready(g)}/new/decision`, act: submitEmpty('Publish proposal'), expect: 'Give the proposal a title.' },

  // V6 setting up voting.
  { screen: 'V6', state: 'empty-request-group', persona: 'steward', path: votesOf('setup', '/setup'), expect: 'Choose all three to see your group\'s rules in one sentence.' },
  { screen: 'V6', state: 'empty-open-group', persona: 'steward', path: votesOf('open', '/setup'), expect: 'Add new members to the approval list automatically' },
  { screen: 'V6', state: 'errors', persona: 'steward', path: votesOf('setup', '/setup'), act: submitEmpty('Start voting setup'), expect: 'Choose how many votes a proposal needs.' },
  {
    screen: 'V6', state: 'fraction-filled', persona: 'steward', path: votesOf('setup', '/setup'),
    act: async (page) => {
      await page.getByRole('button', { name: /½|half/i }).click();
    },
    expect: 'Choose all three',
  },
  {
    screen: 'V6', state: 'new-members-unchecked', persona: 'steward', path: votesOf('setup', '/setup'),
    act: async (page) => {
      await page.getByRole('checkbox', { name: 'Add new members to the approval list automatically' }).uncheck();
    },
    expect: 'Add new members to the approval list automatically',
  },

  { screen: 'V6', state: 'no-fund', persona: 'steward', path: votesOf('no_fund', '/setup'), expect: 'Set up the group fund first.' },
  {
    screen: 'V6', state: 'bad-values', persona: 'steward', path: votesOf('setup', '/setup'),
    act: async (page) => {
      await page.getByLabel("Name of the group's voting page").fill('Bad Name!');
      await page.getByLabel('How many votes a proposal needs').fill('99');
      await page.getByLabel('How long the safety check lasts, in hours').fill('200');
      await page.getByRole('button', { name: 'Start voting setup' }).click();
    },
    expect: 'Use only lowercase letters, numbers and hyphens, 3 to 40 characters.',
  },

  // V7 setup progress.
  { screen: 'V7', state: 'approve', persona: 'steward', path: votesOf('progress', '/setup'), expect: 'Approved by 0 of 2. Needs 2.' },
  {
    screen: 'V7', state: 'confirmation-2-of-3', persona: 'steward', path: votesOf('progress', '/setup'), wallet: 'second_pending',
    act: async (page) => {
      await page.getByRole('button', { name: 'Approve the setup' }).click();
    },
    expect: 'Confirmation 2 of 3',
  },
  { screen: 'V7', state: 'name-taken', persona: 'steward', path: votesOf('progress', '/setup'), seed: 'progress_name_taken', expect: /Someone reserved .* first\./ },
  { screen: 'V7', state: 'setup-failed', persona: 'steward', path: votesOf('progress', '/setup'), seed: 'progress_setup_failed', expect: "Setting up voting didn't go through." },
  { screen: 'V7', state: 'rules-refused', persona: 'steward', path: votesOf('progress', '/setup'), seed: 'progress_rules_refused', expect: "The voting rules weren't accepted." },
  {
    screen: 'V7', state: 'ready', persona: 'steward', path: votesOf('ready', '/setup'),
    act: async (page) => {
      await page.getByText('Technical details for other tools').click();
    },
    expect: 'Group voting is ready. Members you approve can now vote.',
  },
  { screen: 'V7', state: 'host-control', persona: 'steward', path: votesOf('quiet', '/setup'), expect: "Something gives this site's host control over the group's votes" },
  { screen: 'V7', state: 'fee-limit', persona: 'steward', path: votesOf('progress', '/setup'), seed: 'progress_cap', sponsor: 'cap_day', expect: "network fee limit is reached" },

  // V8 approve new members.
  { screen: 'V8', state: 'queue-selected', persona: 'steward', path: votesOf('ready', '/members'), expect: 'Selected because new members are added automatically.' },
  { screen: 'V8', state: 'empty', persona: 'steward', path: votesOf('quiet', '/members'), expect: 'No one is waiting' },
  { screen: 'V8', state: 'batch-in-progress', persona: 'steward', path: votesOf('ready', '/members'), seed: 'batch', expect: 'Withdraw these changes' },

  // V9 wrong-result alerts (organizers).
  { screen: 'V9', state: 'wrong-result-votes', persona: 'steward', path: ready, seed: 'alert_wrong_result', expect: /A wrong vote result was reported for/ },
  { screen: 'V9', state: 'wrong-result-proposal', persona: 'steward', path: proposal('on_hold'), seed: 'alert_wrong_result', expect: /A wrong vote result was reported for/ },
  { screen: 'V9', state: 'escalation', persona: 'steward', path: ready, seed: 'alert_escalation', expect: 'Organizers need to act on a wrong vote result.' },
  { screen: 'V9', state: 'resolved', persona: 'steward', path: ready, seed: 'alert_resolved', expect: /was corrected\. No money moved\./ },

  // V10 the network fee account (site manager).
  { screen: 'V10', state: 'configured', persona: 'sitemanager', path: () => '/admin/community/network-fees', scope: ADMIN_SCOPE, expect: 'Spent in the last 24 hours' },
  { screen: 'V10', state: 'limit-day', persona: 'sitemanager', path: () => '/admin/community/network-fees', scope: ADMIN_SCOPE, sponsor: 'cap_day', expect: 'The limit for the last 24 hours is reached.' },
  { screen: 'V10', state: 'limit-month', persona: 'sitemanager', path: () => '/admin/community/network-fees', scope: ADMIN_SCOPE, sponsor: 'cap_month', expect: /The limit for [A-Z][a-z]+ is reached\./ },
  { screen: 'V10', state: 'low-balance', persona: 'sitemanager', path: () => '/admin/community/network-fees', scope: ADMIN_SCOPE, sponsor: 'low_balance', expect: 'The balance is low.' },
  { screen: 'V10', state: 'unreachable', persona: 'sitemanager', path: () => '/admin/community/network-fees', scope: ADMIN_SCOPE, sponsor: 'unreachable', expect: "Couldn't reach the network fee account right now." },
  { screen: 'V10', state: 'not-set-up', persona: 'sitemanager', path: () => '/admin/community/network-fees', scope: ADMIN_SCOPE, sponsor: 'not_set_up', expect: 'No network fee account is set up on this site.' },
  { screen: 'V10', state: 'no-groups', persona: 'sitemanager', path: () => '/admin/community/network-fees', scope: ADMIN_SCOPE, sponsor: 'no_groups', expect: 'No groups are set up for voting yet.' },
];

/**
 * A stub wallet in the page (window.ethereum), never a real one: it answers
 * the account and the chain, and leaves a signature request waiting, so the
 * signing panel's "Waiting for your approval" state stays on screen. In
 * 'second_pending' the first request is answered with a made-up signature
 * and the second waits ("Confirmation 2 of 3").
 */
export async function stubWallet(page: Page, account: string, chainIdHex: string, mode: 'pending' | 'second_pending' | 'sign'): Promise<void> {
  await page.addInitScript(({ account, chainIdHex, mode }) => {
    let signed = 0;
    (window as unknown as { ethereum: unknown }).ethereum = {
      isStub: true,
      request: ({ method }: { method: string }) => {
        if (method === 'eth_requestAccounts' || method === 'eth_accounts') return Promise.resolve([account]);
        if (method === 'eth_chainId') return Promise.resolve(chainIdHex);
        if (method === 'eth_signTypedData_v4') {
          signed += 1;
          if (mode === 'sign' || (mode === 'second_pending' && signed === 1)) {
            // A person takes a moment to confirm: the panel's waiting state
            // stays long enough to be announced.
            return new Promise((resolve) => setTimeout(() => resolve('0x' + '11'.repeat(64) + '1b'), 800));
          }
          return new Promise(() => undefined);
        }
        return Promise.reject(new Error(`stub wallet: ${method}`));
      },
      on: () => undefined,
      removeListener: () => undefined,
    };
  }, { account, chainIdHex, mode });
}

/** The banned words of 25-UI-SPEC, as GuardTest::BANNED and BANNED_SAFE. */
export const BANNED = /\b(?:snapshot|ens|reality(?:\.eth)?|zodiac|modules?|arbitrators?|hats?|gas|bonds?|quorum|transactions?|signatures?|multisig|blockchains?|on-chain|sepolia|testnets?)\b/i;
export const BANNED_SAFE = /\bSafes?\b/;

/** The D-30 formats: Full, Short and Time. */
export const D30 = {
  full: /\b(?:January|February|March|April|May|June|July|August|September|October|November|December) \d{1,2}, \d{4} at \d{1,2}:\d{2} [AP]M [A-Z]{2,4}\b/,
  short: /\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) \d{1,2}, \d{1,2}:\d{2} [AP]M [A-Z]{2,4}\b/,
  time: /until \d{1,2}:\d{2} [AP]M [A-Z]{2,4}\./,
};
