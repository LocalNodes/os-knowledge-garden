// One page object for the Phase 25 screens the live judge passes through (25-27 Task 1, D-23).
//
// Criterion 4: the judge does only what a person reading the screens can do. Every locator here
// is getByRole, getByLabel or getByText with copy from the Phase 25 Copywriting Contract
// (as revised on 2026-11-08), held once in COPY. Strings with runtime values keep the spec's
// ‹placeholder› form and are filled with fill() or matched with pattern(). The few shipped
// strings the contract didn't hold when this file was written are in SHIPPED_NOT_IN_SPEC, each
// with its source. labels.spec.ts checks all of this, and checks that each locator resolves
// exactly once on the lane's fixture site.
//
// Out of scope here, on purpose: SIWE sign-in and the group fund set-up are Phase 21 screens,
// driven by Phase 21's own helpers (tests/e2e/helpers/treasury.ts and the copied sign-in step).
import type { Locator, Page } from '@playwright/test';

/** Copywriting Contract strings, verbatim from the contract (copied to labels.fixture.ts). */
export const COPY = {
  // Group tab and V1.
  votes: 'Votes',
  setUpGroupVoting: 'Set up group voting',
  createProposal: 'Create a proposal',
  youCanVote: 'You can vote.',
  // V6 set up group voting.
  votingPageName: "Name of the group's voting page",
  votesNeeded: 'How many votes a proposal needs',
  oneDay: '1 day',
  threeDays: '3 days',
  anyMemberCanPropose: 'Any member who can vote',
  safetyCheckHours: 'How long the safety check lasts, in hours',
  newMembersAutomatically: 'Add new members to the approval list automatically',
  adminDetails: 'Technical details for administrators',
  votingPeriodMinutes: 'Voting period in minutes',
  safetyCheckSeconds: 'Safety check in seconds',
  waitingSeconds: 'Waiting time after the safety check in seconds',
  startVotingSetup: 'Start voting setup',
  // V7 voting setup progress.
  settingUpGroupVoting: 'Setting up group voting',
  approvedBy: 'Approved by ‹1› of ‹3›. Needs ‹2›.',
  approveTheSetup: 'Approve the setup',
  confirmation: 'Confirmation ‹2› of ‹3›',
  youApprovedTheSetup: 'You approved the setup.',
  votingReady: 'Group voting is ready. Members you approve can now vote.',
  approveNewMembers: 'Approve new members',
  openVotes: 'Open votes',
  seeVotingPage: "See the group's voting page",
  opensAnotherSite: '(opens another site)',
  technicalDetailsOtherTools: 'Technical details for other tools',
  memberRoleHat: 'Member role: Hats Protocol hat ‹id›',
  votingPageOnSnapshot: 'Voting page: ‹name.eth› on Snapshot',
  carryOutStep: 'Carry-out step: Zodiac Reality module ‹short ID›',
  setupFailed: "Setting up voting didn't go through. Nothing changed. Organizers need to approve it again.",
  rulesRefused: "The voting rules weren't accepted. Organizers need to approve them again.",
  // V8 approve new members.
  selectRow: 'Select ‹name›: ‹change›',
  letThemVote: 'Let them vote',
  selectedBecauseNewMembers: 'Selected because new members are added automatically.',
  approveOneChange: 'Approve 1 change',
  approveNChanges: 'Approve ‹n› changes',
  approveTheseChanges: 'Approve these changes',
  youApprovedTheseChanges: 'You approved these changes.',
  membersCanVote: '‹9› members can vote.',
  changesDidntGoThrough: "These changes didn't go through. Nothing changed. Approve them again.",
  // V5 create a proposal.
  proposePayment: 'Propose a payment',
  proposePaymentHelp: "Pay from the group fund if the vote passes. It's carried out automatically.",
  title: 'Title',
  amount: 'Amount',
  paidFrom: 'Paid from',
  payTo: 'Pay to',
  why: 'Why',
  publishProposal: 'Publish proposal',
  proposalOpenUntil: 'Your proposal is open for voting until ‹November 9, 2026 at 2:30 PM MST›.',
  publishHubFailed: "We couldn't publish the proposal right now. Your draft is saved. Try publishing again in a minute.",
  publishNotYet: 'You can propose a minute or so after organizers approve you. Try publishing again shortly.',
  publishWentWrong: "Something went wrong on our side, so the proposal wasn't published. Your draft is saved.",
  // V2 and V3 the proposal page and the vote.
  howDoYouVote: 'How do you vote?',
  voteFor: 'For',
  voteAgainst: 'Against',
  voteAbstain: 'Abstain',
  castYourVote: 'Cast your vote',
  voteRecorded: 'Your vote is recorded.',
  youVotedOn: 'You voted ‹For› on ‹Nov 6, 9:12 AM MST›.',
  seeVotePublicRecord: "See this vote's public record",
  outcomePassed: 'Passed: enough people voted, and more voted For than Against.',
  voteNoPower: 'You were approved to vote after this one started, so you can vote on the next one.',
  voteEnded: "Voting ended before your vote arrived, so it wasn't counted.",
  voteHubFailed: "We couldn't record your vote right now. Try casting it again in a minute.",
  voteWentWrong: "Something went wrong on our side, so your vote wasn't recorded.",
  // V4 carrying out a passed payment.
  carryingOut: 'Carrying out the payment',
  carriedOut: 'Carried out',
  paymentWentOut: 'The payment went out on ‹November 6, 2026 at 3:45 PM MST›.',
  seePublicRecordOn: 'See the public record on ‹explorer name›',
  paymentDidntGoThrough: "The payment didn't go through. No money moved. Organizers have been told.",
  // Added to the contract by the 2026-11-09 amendment (V5 and V7/V1).
  confirmInYourWallet: 'Confirm in your wallet',
  couldntCheckSetup: "Couldn't check the group's voting setup right now.",
} as const;

/**
 * Shipped strings the judge relies on that the contract didn't hold when this file was written, each
 * accepted. labels.spec.ts checks each is really absent from labels.fixture.ts (a mismatch to
 * amend there).
 */
export const SHIPPED_NOT_IN_SPEC: Record<string, { text: string; source: string }> = {
  // Empty: the two entries this held (Confirm in your wallet; Couldn't check the group's voting
  // setup right now.) are in the contract since its 2026-11-09 amendment, so they are in COPY.
};

/** Escapes a string for use inside a RegExp. */
function escape(text: string): string {
  return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** Fills a template's ‹placeholders›, in order. */
export function fill(template: string, ...values: Array<string | number>): string {
  let i = 0;
  return template.replace(/‹[^›]*›/g, () => {
    if (i >= values.length) throw new Error(`fill: too few values for "${template}"`);
    return String(values[i++]);
  });
}

/**
 * The regex source of a template: literal text (any run of white space matches a space, as the
 * page's text nodes break lines), and one lazy capture per ‹placeholder›.
 */
function source(template: string): string {
  return template.split(/‹[^›]*›/).map((part) => escape(part).replace(/ /g, '\\s+')).join('([\\s\\S]+?)');
}

/** A template as a regex (unanchored unless `whole`). */
export function pattern(template: string, whole = false): RegExp {
  return new RegExp(whole ? `^${source(template)}$` : source(template));
}

/** A form label as Drupal renders it: the text, then the required mark when there is one. */
function labelled(text: string): RegExp {
  return new RegExp(`^${escape(text)}\\s*\\*?$`);
}

/** A link name with the hidden "(opens another site)" suffix. */
function offSite(text: string): string {
  return `${text} ${COPY.opensAnotherSite}`;
}

/** The group's tabs. */
export function groupTabs(page: Page) {
  return {
    votes: page.getByRole('link', { name: COPY.votes, exact: true }),
  };
}

/** V1, the Votes tab. */
export function votesTab(page: Page) {
  return {
    setUpGroupVoting: page.getByRole('link', { name: COPY.setUpGroupVoting, exact: true }),
    createProposal: page.getByRole('link', { name: COPY.createProposal, exact: true }),
    youCanVote: page.getByText(COPY.youCanVote, { exact: true }),
    /** A proposal card's title link (the proposer's own words). */
    proposal: (title: string) => page.getByRole('link', { name: title, exact: true }),
    couldntCheckSetup: page.getByText(COPY.couldntCheckSetup, { exact: true }),
  };
}

/** V6, Set up group voting. */
export function setupForm(page: Page) {
  return {
    votingPageName: page.getByLabel(labelled(COPY.votingPageName)),
    votesNeeded: page.getByLabel(labelled(COPY.votesNeeded)),
    votingLength: (choice: string) => page.getByRole('radio', { name: choice, exact: true }),
    anyMemberCanPropose: page.getByRole('radio', { name: COPY.anyMemberCanPropose, exact: true }),
    safetyCheckHours: page.getByLabel(labelled(COPY.safetyCheckHours)),
    newMembersAutomatically: page.getByRole('checkbox', { name: COPY.newMembersAutomatically, exact: true }),
    adminDetails: page.getByText(COPY.adminDetails, { exact: true }),
    votingPeriodMinutes: page.getByLabel(labelled(COPY.votingPeriodMinutes)),
    safetyCheckSeconds: page.getByLabel(labelled(COPY.safetyCheckSeconds)),
    waitingSeconds: page.getByLabel(labelled(COPY.waitingSeconds)),
    start: page.getByRole('button', { name: COPY.startVotingSetup, exact: true }),
  };
}

/** V7, Voting setup: the known-steps panel and the summary. */
export function setupProgress(page: Page) {
  const memberRole = page.getByText(pattern(COPY.memberRoleHat));
  return {
    region: page.getByRole('region', { name: COPY.settingUpGroupVoting }),
    approvals: (approved: number, of: number, needs: number) => page.getByText(fill(COPY.approvedBy, approved, of, needs), { exact: true }),
    approveTheSetup: page.getByRole('button', { name: COPY.approveTheSetup, exact: true }),
    confirmation: (current: number, total: number) => page.getByText(fill(COPY.confirmation, current, total), { exact: true }),
    youApprovedTheSetup: page.getByText(COPY.youApprovedTheSetup, { exact: true }),
    ready: page.getByText(COPY.votingReady, { exact: true }),
    setupFailed: page.getByText(COPY.setupFailed, { exact: true }),
    rulesRefused: page.getByText(COPY.rulesRefused, { exact: true }),
    couldntCheckSetup: page.getByText(COPY.couldntCheckSetup, { exact: true }),
    approveNewMembers: page.getByRole('link', { name: COPY.approveNewMembers, exact: true }),
    openVotes: page.getByRole('link', { name: COPY.openVotes, exact: true }),
    votingPageLink: page.getByRole('link', { name: offSite(COPY.seeVotingPage), exact: true }),
    technicalDetails: page.getByText(COPY.technicalDetailsOtherTools, { exact: true }),
    memberRole,
    votingPage: page.getByText(pattern(COPY.votingPageOnSnapshot)),
    carryOutStep: page.getByRole('listitem').filter({ hasText: pattern(COPY.carryOutStep) }),
    /** The carry-out step's full ID, from the code element in its line. */
    async carryOutModule(): Promise<string> {
      // The code element shows the Short ID and holds the full ID as hidden text.
      const text = (await page.getByRole('listitem').filter({ hasText: pattern(COPY.carryOutStep) }).getByRole('code').textContent()) ?? '';
      const id = text.match(/0x[0-9a-fA-F]{40}/)?.[0];
      if (!id) throw new Error('the carry-out step line holds no full ID');
      return id;
    },
    /** The top hat's domain: the member role reads "hat ‹domain›.1". */
    async topHatDomain(): Promise<string> {
      const text = await memberRole.innerText();
      const id = text.trim().match(pattern(COPY.memberRoleHat, true))?.[1] ?? '';
      const domain = id.match(/^(\d+)\.1\b/)?.[1];
      if (!domain) throw new Error('the member role line names no hat of the form ‹domain›.1');
      return domain;
    },
  };
}

/** V8, Approve new members. */
export function approveMembers(page: Page) {
  return {
    /** A waiting row's checkbox: "Select ‹name›: ‹change›". */
    row: (name: string, change: string) => page.getByRole('checkbox', { name: fill(COPY.selectRow, name, change), exact: true }),
    selectedBecauseNewMembers: page.getByText(COPY.selectedBecauseNewMembers, { exact: true }),
    /** The primary, "Approve 1 change" or "Approve ‹n› changes" by the selection. */
    approveSelected: page.getByRole('button', {
      name: new RegExp(`^(?:${escape(COPY.approveOneChange)}|${source(COPY.approveNChanges)})$`),
    }),
    approveTheseChanges: page.getByRole('button', { name: COPY.approveTheseChanges, exact: true }),
    youApprovedTheseChanges: page.getByText(COPY.youApprovedTheseChanges, { exact: true }),
    votingMembers: (count: number) => page.getByText(fill(COPY.membersCanVote, count), { exact: true }),
    changesDidntGoThrough: page.getByText(COPY.changesDidntGoThrough, { exact: true }),
  };
}

/** V5, Create a proposal: the choice screen and the payment form. */
export function createProposal(page: Page) {
  return {
    // A choice card is one link named by its title and its line.
    proposePayment: page.getByRole('link', { name: [COPY.proposePayment, COPY.proposePaymentHelp].join(' '), exact: true }),
    title: page.getByLabel(labelled(COPY.title)),
    /** A currency radio, labelled by the asset's symbol (25-23, accepted). */
    currency: (symbol: string) => page.getByRole('radio', { name: symbol, exact: true }),
    /** "Amount (‹symbol›)" once a currency is chosen; "Amount" before (25-23, accepted). */
    amount: page.getByLabel(new RegExp(`^${escape(COPY.amount)}(?: \\(.+\\))?\\s*\\*?$`)),
    paidFrom: page.getByText(COPY.paidFrom, { exact: true }),
    payTo: page.getByLabel(labelled(COPY.payTo)),
    why: page.getByLabel(labelled(COPY.why)),
    publish: page.getByRole('button', { name: COPY.publishProposal, exact: true }),
    confirmInYourWallet: page.getByRole('button', { name: COPY.confirmInYourWallet, exact: true }),
    openUntil: page.getByText(pattern(COPY.proposalOpenUntil)),
    hubFailed: page.getByText(COPY.publishHubFailed, { exact: true }),
    notYet: page.getByText(COPY.publishNotYet, { exact: true }),
    wentWrong: page.getByText(COPY.publishWentWrong, { exact: true }),
  };
}

/** V2 and V3, the proposal page and its vote form. */
export function proposalPage(page: Page) {
  return {
    voteChoices: page.getByRole('group', { name: COPY.howDoYouVote, exact: true }),
    choice: (choice: string) => page.getByRole('radio', { name: choice, exact: true }),
    castYourVote: page.getByRole('button', { name: COPY.castYourVote, exact: true }),
    voteRecorded: page.getByText(COPY.voteRecorded, { exact: true }),
    youVoted: page.getByText(pattern(COPY.youVotedOn)),
    payTo: page.getByText(COPY.payTo, { exact: true }),
    publicRecord: page.getByRole('link', { name: offSite(COPY.seeVotePublicRecord), exact: true }),
    outcomePassed: page.getByText(COPY.outcomePassed, { exact: true }),
    noPower: page.getByText(COPY.voteNoPower, { exact: true }),
    ended: page.getByText(COPY.voteEnded, { exact: true }),
    hubFailed: page.getByText(COPY.voteHubFailed, { exact: true }),
    wentWrong: page.getByText(COPY.voteWentWrong, { exact: true }),
  };
}

/** V4, Carrying out the payment (the known-steps panel on V2). */
export function carryOut(page: Page) {
  const region = page.getByRole('region', { name: COPY.carryingOut });
  const publicRecord = region.getByRole('link', { name: pattern(offSite(COPY.seePublicRecordOn), true) });
  return {
    region,
    carriedOut: region.getByText(COPY.carriedOut, { exact: true }),
    wentOut: region.getByText(pattern(COPY.paymentWentOut)),
    amount: region.getByText(COPY.amount, { exact: true }),
    paidFrom: region.getByText(COPY.paidFrom, { exact: true }),
    payTo: region.getByText(COPY.payTo, { exact: true }),
    /** The Pay to value: the recipient's name, a link (D-29). */
    payee: (name: string) => region.getByRole('link', { name, exact: true }),
    publicRecord,
    didntGoThrough: region.getByText(COPY.paymentDidntGoThrough, { exact: true }),
    /** The payment's transaction hash, from the public record link. */
    async transactionHash(): Promise<string> {
      const href = (await publicRecord.getAttribute('href')) ?? '';
      const hash = href.match(/\/tx\/(0x[0-9a-fA-F]{64})(?:[/?#]|$)/)?.[1];
      if (!hash) throw new Error('the public record link holds no transaction hash');
      return hash.toLowerCase();
    },
  };
}
