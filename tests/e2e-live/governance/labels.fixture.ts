// The Phase 25 Copywriting Contract strings the live judge's page object (pages.ts) relies on,
// copied verbatim from the contract, so labels.spec.ts needs no other file to check pages.ts
// against. Values in ‹› are filled at runtime, as in the contract.
export const COPYWRITING_CONTRACT: readonly string[] = [
  'Votes',  // votes
  'Set up group voting',  // setUpGroupVoting
  'Create a proposal',  // createProposal
  'You can vote.',  // youCanVote
  'Name of the group\'s voting page',  // votingPageName
  'How many votes a proposal needs',  // votesNeeded
  '1 day',  // oneDay
  '3 days',  // threeDays
  'Any member who can vote',  // anyMemberCanPropose
  'How long the safety check lasts, in hours',  // safetyCheckHours
  'Add new members to the approval list automatically',  // newMembersAutomatically
  'Technical details for administrators',  // adminDetails
  'Voting period in minutes',  // votingPeriodMinutes
  'Safety check in seconds',  // safetyCheckSeconds
  'Waiting time after the safety check in seconds',  // waitingSeconds
  'Start voting setup',  // startVotingSetup
  'Setting up group voting',  // settingUpGroupVoting
  'Approved by ‹1› of ‹3›. Needs ‹2›.',  // approvedBy
  'Approve the setup',  // approveTheSetup
  'Confirmation ‹2› of ‹3›',  // confirmation
  'You approved the setup.',  // youApprovedTheSetup
  'Group voting is ready. Members you approve can now vote.',  // votingReady
  'Approve new members',  // approveNewMembers
  'Open votes',  // openVotes
  'See the group\'s voting page',  // seeVotingPage
  '(opens another site)',  // opensAnotherSite
  'Technical details for other tools',  // technicalDetailsOtherTools
  'Member role: Hats Protocol hat ‹id›',  // memberRoleHat
  'Voting page: ‹name.eth› on Snapshot',  // votingPageOnSnapshot
  'Carry-out step: Zodiac Reality module ‹short ID›',  // carryOutStep
  'Setting up voting didn\'t go through. Nothing changed. Organizers need to approve it again.',  // setupFailed
  'The voting rules weren\'t accepted. Organizers need to approve them again.',  // rulesRefused
  'Select ‹name›: ‹change›',  // selectRow
  'Let them vote',  // letThemVote
  'Selected because new members are added automatically.',  // selectedBecauseNewMembers
  'Approve 1 change',  // approveOneChange
  'Approve ‹n› changes',  // approveNChanges
  'Approve these changes',  // approveTheseChanges
  'You approved these changes.',  // youApprovedTheseChanges
  '‹9› members can vote.',  // membersCanVote
  'These changes didn\'t go through. Nothing changed. Approve them again.',  // changesDidntGoThrough
  'Propose a payment',  // proposePayment
  'Pay from the group fund if the vote passes. It\'s carried out automatically.',  // proposePaymentHelp
  'Title',  // title
  'Amount',  // amount
  'Paid from',  // paidFrom
  'Pay to',  // payTo
  'Why',  // why
  'Publish proposal',  // publishProposal
  'Your proposal is open for voting until ‹November 9, 2026 at 2:30 PM MST›.',  // proposalOpenUntil
  'We couldn\'t publish the proposal right now. Your draft is saved. Try publishing again in a minute.',  // publishHubFailed
  'You can propose a minute or so after organizers approve you. Try publishing again shortly.',  // publishNotYet
  'Something went wrong on our side, so the proposal wasn\'t published. Your draft is saved.',  // publishWentWrong
  'How do you vote?',  // howDoYouVote
  'For',  // voteFor
  'Against',  // voteAgainst
  'Abstain',  // voteAbstain
  'Cast your vote',  // castYourVote
  'Your vote is recorded.',  // voteRecorded
  'You voted ‹For› on ‹Nov 6, 9:12 AM MST›.',  // youVotedOn
  'See this vote\'s public record',  // seeVotePublicRecord
  'Passed: enough people voted, and more voted For than Against.',  // outcomePassed
  'You were approved to vote after this one started, so you can vote on the next one.',  // voteNoPower
  'Voting ended before your vote arrived, so it wasn\'t counted.',  // voteEnded
  'We couldn\'t record your vote right now. Try casting it again in a minute.',  // voteHubFailed
  'Something went wrong on our side, so your vote wasn\'t recorded.',  // voteWentWrong
  'Carrying out the payment',  // carryingOut
  'Carried out',  // carriedOut
  'The payment went out on ‹November 6, 2026 at 3:45 PM MST›.',  // paymentWentOut
  'See the public record on ‹explorer name›',  // seePublicRecordOn
  'The payment didn\'t go through. No money moved. Organizers have been told.',  // paymentDidntGoThrough
  'Confirm in your wallet',  // confirmInYourWallet
  'Couldn\'t check the group\'s voting setup right now.',  // couldntCheckSetup
];
