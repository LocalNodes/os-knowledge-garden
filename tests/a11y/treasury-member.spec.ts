// Phase 21 group fund screens through axe, as a plain member (21-28).
//
// Runs as a11y-member: a member of every seeded fund group, with no linked
// wallet and no organizer role. Members never see a wallet step, a fee or
// a technical word (21-UI-SPEC Global rules). The fixture scenario is set
// to ok first, and "A11y Fund" is forgotten so its first view reads again.
//
// The states live in helpers/treasury-states.ts (shared with 21-29's
// recorded walkthrough).
import { memberStates } from './helpers/treasury-states';

memberStates();
