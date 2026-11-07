// Phase 21 group fund screens (21-UI-SPEC Part D, T1 to T8) through axe,
// as the organizer (21-28, criterion 7's automated half).
//
// Runs as the steward (group manager of every seeded fund group, with a
// linked wallet that is a fixture owner, so an approver). The site is the
// lane site fresh3-p21-a11y with the test-only RPC fixture module on: each
// state sets the fixture scenario first (ok, fail, mismatch or token_fail).
//
// Order matters, because a failed read is reused for the 60 s fresh window:
// every state that reads "A11y Fund" with the scenario ok runs before the
// failure states, each failure state forgets the snapshot it reads, and the
// file ends with the scenario back at ok and "A11y Fund" forgotten. The
// no-networks block is last: it deletes both registry entries and restores
// them with their original UUIDs in afterAll.
//
// States that need a real wallet signature are covered by 21-42 to 21-45's
// recordings. The receipt and approval panels are shown at their first step
// by calling Drupal.safeSmartAccounts.flowPanel on the real page (21-16).
//
// The states live in helpers/treasury-states.ts (shared with 21-29's
// recorded walkthrough).
import { stewardStates } from './helpers/treasury-states';

stewardStates();
