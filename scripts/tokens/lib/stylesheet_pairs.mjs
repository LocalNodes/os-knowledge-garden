/**
 * The contrast pairs the LocalNodes module stylesheets draw.
 *
 * P4 in pairings.mjs measures a pair only when one ln-overrides.css rule sets both `color` and a background. That left
 * most of what the stylesheets paint unmeasured: the header name, the DeepChat bar, and the colours in import.css and
 * ai-overview.css, which `uncovered()` counted as covered whenever their semantic colour sat in any pairing, on any
 * background. This library reads the five stylesheets that use var(--ln-color-*):
 *
 *   overrides    html/modules/custom/localnodes_platform/css/ln-overrides.css
 *   app-look     html/modules/custom/localnodes_platform/css/ln-app-look.css
 *                (the app look: header, tabs, cards, outline buttons and fields; loads after ln-overrides.css)
 *   import       html/modules/custom/localnodes_import/css/import.css
 *   ai-overview  html/modules/custom/social_ai_indexing/css/ai-overview.css
 *   deepchat     html/modules/custom/social_ai_indexing/css/deepchat.css
 *   treasury     html/modules/custom/localnodes_platform/modules/localnodes_web3/css/treasury.css
 *                (the group fund components)
 *   signin       html/modules/custom/localnodes_platform/modules/localnodes_web3/css/signin.css
 *                (the sign-in button above the password form, and the "Opening sign-in…" strip)
 *   signature-proof  html/modules/custom/localnodes_platform/modules/localnodes_web3/css/signature-proof.css
 *                (the "Confirm your sign-in" panel before a fund or vote change)
 *
 * A stylesheet names a foreground; the background behind it is a fact about the page, so it comes from PARTNERS, a
 * hand-written map keyed by stylesheet, then single selector (a selector list is split: its members can sit on
 * different backgrounds), then property kind. Every entry carries a one-line `why` that names the
 * template or rule which puts the partner there (a guessed partner is a wrong measurement).
 *
 *   text        { on: [bg, ...] }                    the colour is `color` or `fill`; 4.5:1 on each background behind it
 *   border      { against: [bg, ...] }               a border or box-shadow; 3:1 on the SURROUNDINGS, never on the
 *                                                    rule's own fill (a .btn-danger border against its own fill is 1:1)
 *   outline     { fill: [bg, ...], surroundings: [bg, ...] }
 *                                                    the ring the browser renders; 3:1 on the element's own fill when
 *                                                    the cascade renders it inset (a negative outline-offset), on the
 *                                                    surroundings when it sits outside
 *
 * A `bg` is one of
 *   'surface'                          a --ln-color-* role without the prefix
 *   'targets.socialblue.navbar-bg'     a full token path (anything outside these stylesheets), with the reason in `why`
 *   { from: '<selector>', bg: 'text', sheet?: 'deepchat' }
 *                                      the background that an ancestor rule in these stylesheets sets
 *
 * What is measured is what the browser renders, not what a module stylesheet asks for. The
 * global `:focus-visible` rule in ln-overrides.css is !important, so it beats the outline a module sets. For a
 * `:focus-visible` selector the ring that renders is taken from ln-overrides.css: the rule with exactly that selector
 * if it has an !important outline colour or offset, else the global rule. When a module asks for another colour, the
 * gate reports OVERRIDDEN and measures the colour that renders.
 *
 * Pair ids are `P13.<stylesheet>.<kind>.<fg>.on.<bg>`; two selectors that make the same pair share one pairing, and its
 * source lists them all.
 */
import { readFileSync } from 'node:fs';
import { resolve as resolvePath } from 'node:path';
import { parseRules } from '../derive_socialblue_pairings.mjs';
import { repoRoot } from './tokens.mjs';
import { ln, pairing } from './pairing.mjs';

/** The five stylesheets: repo path and the name the output prints. */
export const STYLESHEETS = Object.freeze({
  'site-export': { rel: 'html/modules/custom/localnodes_platform/css/site-export.css', name: 'site-export.css' },
  overrides: { rel: 'html/modules/custom/localnodes_platform/css/ln-overrides.css', name: 'ln-overrides.css' },
  'app-look': { rel: 'html/modules/custom/localnodes_platform/css/ln-app-look.css', name: 'ln-app-look.css' },
  import: { rel: 'html/modules/custom/localnodes_import/css/import.css', name: 'import.css' },
  'ai-overview': { rel: 'html/modules/custom/social_ai_indexing/css/ai-overview.css', name: 'ai-overview.css' },
  deepchat: { rel: 'html/modules/custom/social_ai_indexing/css/deepchat.css', name: 'deepchat.css' },
  treasury: { rel: 'html/modules/custom/localnodes_platform/modules/localnodes_web3/css/treasury.css', name: 'treasury.css' },
  signin: { rel: 'html/modules/custom/localnodes_platform/modules/localnodes_web3/css/signin.css', name: 'signin.css' },
  'signature-proof': { rel: 'html/modules/custom/localnodes_platform/modules/localnodes_web3/css/signature-proof.css', name: 'signature-proof.css' },
});

/** { key: absolute path } for the five stylesheets in `root`, with `replace` (key -> path) taking precedence. */
export function stylesheetFiles(root = repoRoot, replace = {}) {
  return Object.fromEntries(Object.entries(STYLESHEETS).map(([key, s]) => [key, replace[key] ?? resolvePath(root, s.rel)]));
}

const BAR = '.ai-deepchat .ln-chat-bar';
const HEADER = `${BAR} .ai-deepchat--header`;
const OPTIONS = `${BAR} .chat-dropdown-button`;
const LINK = `${BAR} .chat-dropdown-link`;
/** The three light surfaces a text role sits on in P2: socialblue's white card, the canvas, a tinted band. */
const PAGE = ['surface', 'canvas', 'tint'];
/** Where a LocalNodes import screen draws: socialblue's body (the canvas) or a white .card. */
const IMPORT_PAGE = ['canvas', 'surface'];
/** Around the group page's "Joined" button. */
const JOINED_AROUND = ['surface', 'canvas'];
const JOINED_WHY = "the button sits in the group statistics .card (measured on the test site, 2026-11-11: rgb(250, 250, 250), between surface #fff and canvas #f3f3f3, so both bounds are measured)";
const IMPORT_WHY = "import screens sit on socialblue's body or a white .card (measured on the test site, 2026-11-09: body rgb(243, 243, 243) = canvas, .card rgb(255, 255, 255) = surface)";

/** The welcome block's dark panel, as Open Social draws it (no stylesheet here sets it). */
const WELCOME_PANEL = 'primitive.color.opensocial.hero-panel';
const barFill = { from: BAR, sheet: 'deepchat', bg: 'text' };
const headerFill = { from: HEADER, sheet: 'deepchat', bg: 'text' };

const text = (on, why) => ({ text: { on, why } });
const border = (against, why) => ({ border: { against, why } });
const ring = (fill, surroundings, why) => ({ outline: { fill, surroundings, why } });
const fillOf = (shape, why) => ({ background: { ...shape, why } });
const on = (selector, bg, sheet) => ({ from: selector, bg, ...(sheet ? { sheet } : {}) });

const BAR_RING = ring(
  [headerFill],
  PAGE,
  'the header is a role=button strip in the dark bar (templates/ai-deepchat.html.twig); its own fill is --ln-color-text (deepchat.css), and the bar floats over the page',
);
const OPTIONS_RING = ring(
  [barFill],
  PAGE,
  'the options button is transparent and sits in .ln-chat-bar (templates/ai-deepchat.html.twig), so the bar fill shows through (contrib sticky-chatbot.css: background-color rgba(0, 0, 0, 0))',
);
const LINK_RING = ring(
  [on(LINK, 'surface', 'deepchat'), on(`${LINK}:hover`, 'canvas', 'deepchat')],
  ['surface'],
  'the menu link sits in .chat-dropdown-content, a white box (contrib sticky-chatbot.css), and fills with --ln-color-surface, or --ln-color-canvas on hover (deepchat.css)',
);

/**
 * The group fund components (treasury.css, localnodes_web3). safe_smart_accounts and group_treasury draw them on
 * socialblue's page: the canvas body, a white .card, or a tinted band. Their alerts take the fill ln-overrides.css gives
 * each .alert-* (checked against that stylesheet: a change there is a STALE-PARTNER).
 */
const ALERT_FILLS = [on('.alert-warning', 'warning-bg', 'overrides'), on('.alert-success', 'success-bg', 'overrides'), on('.alert-danger', 'danger-bg', 'overrides')];
const RULE_WHY = 'the 4px left rule is the outer edge of an alert (safe_smart_accounts safe-inline-alert.html.twig, flow-panel.js), which sits on the page or a card; 3:1 against what is around it';
const ID_WHY = 'a short ID (safe-short-id.html.twig, flow-panel.js) or full ID (group-treasury-fund.html.twig, components.js) sits on the page or a card, and inside the progress and inline alerts (flow-panel.js puts short IDs in the done area of an .alert-warning or .alert-success panel)';
const BADGE_WHY = "PaymentView's status badge: the text is on the badge's own fill from the same rule";

const IMPORT_SUMMARY = '.localnodes-import-error-summary';
const IMPORT_SOURCE = '.localnodes-import-source';
const IMPORT_CARD = '.localnodes-import-choice-card';
const IMPORT_STATUS = '.localnodes-import-status';
const IMPORT_ALERT = '.localnodes-import-alert';
const IMPORT_PROGRESS = '.localnodes-import-progress';
const IMPORT_ROW = '.localnodes-import-report tr';
const OVERVIEW = '.ai-overview__content';

/** The ring that every focusable control draws outside itself on an import screen (the global rule renders it, 2px offset). */
const importRing = (surroundings = IMPORT_PAGE, why = IMPORT_WHY) => ring(surroundings, surroundings, why);
const P5_LIGHT = ['P5.focus.on.surface', 'P5.focus.on.canvas', 'P5.focus.on.tint'];
const P5_NAV = ['navbar-bg', 'navbar-active-bg', 'navbar-sec-bg', 'second-line-navbar-bg'].map((f) => `P5.focus-on-dark.on.socialblue.${f}`);

const APP_COVER = '.socialblue--sky .cover:not(.cover-img)';
const APP_TABS = '.socialblue--sky .navbar-secondary';

/**
 * Partner map: PARTNERS[stylesheet][single selector][kind]. See the header for the shapes. Selectors are written as
 * the stylesheet writes them, whitespace collapsed. The nine accent rules of ln-overrides.css are not here: P2 measures
 * them (ACCENT_TEXT_RULES in pairings.mjs).
 */
export const PARTNERS = {
  'app-look': {
    // The page body: socialbase html.html.twig; the text and the canvas come from the same rule.
    body: text(['own'], 'the body text sits on the canvas fill of the same rule'),
    // A group header with no cover picture (socialblue group--hero--sky.html.twig without field_group_image).
    [APP_COVER]: text(['own'], 'the header text (meta lines) sits on the white fill of the same rule; its bottom edge is the decorative divider'),
    [`${APP_COVER} .page-title`]: text([on(APP_COVER, 'surface', 'app-look')], 'the header line under the name (the access-permissions link) is in the white header'),
    [`${APP_COVER} .page-title a`]: text([on(APP_COVER, 'surface', 'app-look')], 'the access-permissions link is in the white header'),
    [`${APP_COVER} .page-title h1`]: text([on(APP_COVER, 'surface', 'app-look')], 'the group name is the header title, inside the white header'),
    [`${APP_COVER} .hero-footer a`]: text([on(APP_COVER, 'surface', 'app-look')], 'the member count and join links are in the header footer, inside the white header'),
    [`${APP_COVER} svg`]: text([on(APP_COVER, 'surface', 'app-look')], 'the header icons are drawn in the white header'),
    // The tabs bar under a group or profile header (socialblue navbar--sky.css markup, nav.navbar-secondary).
    [APP_TABS]: fillOf(
      { under: ['text-muted', 'text-strong', { fg: 'primary', kind: 'non-text' }, { fg: 'border-control', kind: 'non-text' }, { fg: 'focus', kind: 'focus' }] },
      'the tab names, the active tab, its teal underline, the hover underline and the focus ring (the focus colour, from the :focus-visible rule below) are all drawn on the white tabs bar',
    ),
    // The ring in the white tabs bar. It replaces ln-overrides.css's ".navbar-secondary :focus-visible" (focus-on-dark, for
    // a dark bar) with the same !important and a higher specificity, and loads later. app-look.spec.ts reads the painted
    // colour of each focused tab and of the "More group pages" button.
    [`${APP_TABS} :focus-visible`]: ring(
      [on(APP_TABS, 'surface', 'app-look')],
      [on(APP_TABS, 'surface', 'app-look'), 'surface'],
      'a tab or the "More group pages" button sits on the white tabs bar and its ring is drawn 2px outside it, still on the bar; the folded tabs open in a list socialblue draws on white (surface)',
    ),
    [`${APP_TABS} .navbar-nav li a`]: text([on(APP_TABS, 'surface', 'app-look')], 'a tab name sits on the white tabs bar (its own fill is transparent)'),
    [`${APP_TABS} .navbar-nav li a:hover`]: text([on(APP_TABS, 'surface', 'app-look')], 'a hovered tab is still on the white tabs bar'),
    [`${APP_TABS} .navbar-nav li a:focus`]: text([on(APP_TABS, 'surface', 'app-look')], 'a focused tab is still on the white tabs bar'),
    [`${APP_TABS} .navbar-nav li.active a`]: text([on(APP_TABS, 'surface', 'app-look')], 'the active tab is on the white tabs bar'),
    [`${APP_TABS} .navbar-nav li a.is-active`]: text([on(APP_TABS, 'surface', 'app-look')], 'the active tab is on the white tabs bar'),
    [`${APP_TABS} .navbar-nav li a:hover::after`]: fillOf({ boundary: [on(APP_TABS, 'surface', 'app-look')] }, 'the hover underline is a 2px bar under the tab, on the white tabs bar'),
    [`${APP_TABS} .navbar-nav li a:focus::after`]: fillOf({ boundary: [on(APP_TABS, 'surface', 'app-look')] }, 'the focus underline is a 2px bar under the tab, on the white tabs bar'),
    [`${APP_TABS} .navbar-nav li.active a::after`]: fillOf({ boundary: [on(APP_TABS, 'surface', 'app-look')] }, 'the active underline is a 2px teal bar under the tab, on the white tabs bar'),
    [`${APP_TABS} .navbar-nav li a.is-active::after`]: fillOf({ boundary: [on(APP_TABS, 'surface', 'app-look')] }, 'the active underline is a 2px teal bar under the tab, on the white tabs bar'),
    // An event card's date tile (EventDateTile, node--event--teaser--localnodes--sky.html.twig): its own dark fill.
    '.socialblue--sky .teaser__content > .ln-event-date': {
      ...text(['own'], 'the tile rule sets its text colour (surface) and its dark fill (text-strong) together; the weekday, day and month spans inherit the text colour'),
      ...fillOf({ under: ['surface'] }, 'the weekday, day and month are drawn on the tile fill'),
    },
    // The search pill in the top bar (block--system-menu-block--main--localnodes--sky.html.twig). socialblue gives
    // .navbar__open-search-block the navbar-bg fill, so the pill's label, icon and edge sit on the dark bar.
    '.socialblue--sky .navbar__open-search-block.ln-search-pill': {
      ...text(['targets.socialblue.navbar-bg'], "the label and the icon sit on the pill's fill, which socialblue paints navbar-bg like the bar around it"),
      ...border(['targets.socialblue.navbar-bg'], 'the pill edge is drawn between its navbar-bg fill and the navbar-bg bar'),
    },
    '.socialblue--sky .ln-search-pill:hover': border(['targets.socialblue.navbar-bg'], 'a hovered pill edge is still on the dark bar'),
    '.socialblue--sky .ln-search-pill:focus': border(['targets.socialblue.navbar-bg'], 'a focused pill edge is still on the dark bar'),
    // The open search form: Open Social scales the pill's navbar-bg fill up to cover the page, and the form floats on it.
    '.socialblue--sky .main-container .search-take-over .form-text': {
      ...text(['targets.socialblue.navbar-bg'], 'the field is transparent; the typed words sit on the scaled-up navbar-bg fill of the search link'),
      ...border(['targets.socialblue.navbar-bg'], 'the line under the field is on the same navbar-bg cover'),
    },
    '.socialblue--sky .main-container .search-take-over .form-text:focus': {
      ...text(['targets.socialblue.navbar-bg'], 'a focused field is still transparent on the navbar-bg cover'),
      ...border(['targets.socialblue.navbar-bg'], 'the line under a focused field is on the navbar-bg cover'),
    },
    '.socialblue--sky .search-take-over :focus-visible': ring(
      ['targets.socialblue.navbar-bg'],
      ['targets.socialblue.navbar-bg'],
      'the field, the search button and the close button sit on the navbar-bg cover, and their ring is drawn on it too',
    ),
    // Card headings: socialblue's .card is white (surface); its edge is the decorative divider.
    '.socialblue--sky .teaser--stream': fillOf({ under: ['text', 'text-strong', 'text-muted', 'link'] }, 'a stream teaser body holds the title, the tag, the meta badges and the read-more link on its own white fill'),
    '.socialblue--sky .teaser--stream .teaser__image': fillOf({ under: ['text-muted'] }, 'the picture column behind a teaser picture; with no picture it is hidden (same stylesheet)'),
    '.socialblue--sky .card h2': text(['surface'], "a card heading is in a socialblue .card, which is white (socialblue card styles)"),
    '.socialblue--sky .card h3': text(['surface'], "a card heading is in a socialblue .card, which is white (socialblue card styles)"),
    '.socialblue--sky .card h4': text(['surface'], "a card heading is in a socialblue .card, which is white (socialblue card styles)"),
    // Outline buttons: transparent, so the page or card shows through.
    '.main-container .btn-default': {
      ...text(PAGE, 'the outline button is transparent; its text sits on the white card, the canvas or a tinted band behind it'),
      ...border(PAGE, 'the outline is the button edge, on the card, canvas or tint around it'),
    },
    // An outline button over a cover picture (socialblue group--hero--sky.html.twig with field_group_image, a profile
    // header with a banner): the photo is behind it, so it has a white fill, and its text (text-strong, from the rule
    // above) sits on that fill. Its edge is drawn on the photo, which no token describes; its focus ring is drawn 4px
    // inside the fill (a rule in ln-overrides.css), so the ring is measured on the fill.
    '.socialblue--sky .cover.cover-img .btn-default': fillOf(
      { under: ['text-strong', { fg: 'focus', kind: 'focus' }] },
      'the button text ("Edit group") and its focus ring, drawn inside the button, sit on the white fill this rule gives the button over a cover picture',
    ),
    // Form fields: the raised fill, the text and placeholder on it, the control edge against the page around it.
    '.main-container .form-control': {
      ...text(['own'], 'the typed text sits on the raised fill of the same rule'),
      ...fillOf({ under: ['text-muted', { fg: 'focus', kind: 'focus' }] }, 'the placeholder (ln-overrides.css, text-muted) and the field focus ring are drawn on the raised fill'),
      ...border([...PAGE, 'raised'], 'the field edge sits between its raised fill and the card, canvas or tint around it'),
    },
    // The field states the rule above would flatten: the focus edge and the error edge, on the same field.
    '.main-container .form-control:focus': border([...PAGE, 'raised'], 'a focused field: its edge sits between its raised fill and the card, canvas or tint around it'),
    '.main-container .has-error .form-control': border([...PAGE, 'raised'], 'a field in error (socialbase form-element: .form-group.has-error around it): its edge sits between its raised fill and the page around it'),
    '.main-container .has-error .form-control:focus': border([...PAGE, 'raised'], 'a focused field in error keeps the danger edge, between its raised fill and the page around it'),
  },
  'site-export': {
    '.ln-export': { ...text(['own'], 'localnodes-site-export.html.twig sets the panel text and surface fill together'), ...fillOf({ under: ['text'] }, 'the panel contains records, actions and helper details') },
    '.ln-export-owner': text(['surface'], 'the owner label is inside the explicitly white export panel'),
    '.ln-export-warning': fillOf({ under: ['text'] }, 'the private-records warning inherits the panel text over its own tint'),
    '.ln-export-row.is-complete svg': text(['surface'], 'the completed row check is inside the white export panel'),
    '.ln-export-error': text(['surface'], 'the safe failure sentence is inside the white export panel'),
  },
  overrides: {
    // Buttons, alerts, badges, status text: socialblue's own components, drawn on the page or on their own fill.
    '.btn-danger': { ...text(['own'], 'white text on the red fill of the same rule (P7 on-danger on danger)'), ...border(PAGE, 'the border is the fill colour, drawn on the page around the button') },
    '.btn-danger:focus': { ...text(['own'], 'white text on the red fill of the same rule'), ...border(PAGE, 'the border is the fill colour, on the page') },
    '.btn-danger.focus': { ...text(['own'], 'white text on the red fill of the same rule'), ...border(PAGE, 'the border is the fill colour, on the page') },
    '.btn-danger:hover': { ...text(['own'], 'white text on the darker red fill of the same rule'), ...border(PAGE, 'the border is the fill colour, on the page') },
    '.btn-danger:active': { ...text(['own'], 'white text on the darker red fill of the same rule'), ...border(PAGE, 'the border is the fill colour, on the page') },
    '.btn-danger.active': { ...text(['own'], 'white text on the darker red fill of the same rule'), ...border(PAGE, 'the border is the fill colour, on the page') },
    // The group page's "Joined" button, outlined navy on the surface fill, in every state.
    '.hero-footer__cta .btn-group > button.btn-accent.dropdown-toggle': { ...text(['own'], 'navy text on the surface fill of the same rule'), ...border(JOINED_AROUND, JOINED_WHY) },
    '.hero-footer__cta .btn-group > button.btn-accent.dropdown-toggle:hover': { ...text(['own'], 'navy text on the surface fill of the same rule'), ...border(JOINED_AROUND, JOINED_WHY) },
    '.hero-footer__cta .btn-group > button.btn-accent.dropdown-toggle:focus': { ...text(['own'], 'navy text on the surface fill of the same rule'), ...border(JOINED_AROUND, JOINED_WHY) },
    '.hero-footer__cta .btn-group > button.btn-accent.dropdown-toggle:active': { ...text(['own'], 'navy text on the surface fill of the same rule'), ...border(JOINED_AROUND, JOINED_WHY) },
    '.hero-footer__cta .btn-group.open > button.btn-accent.dropdown-toggle': { ...text(['own'], 'navy text on the surface fill of the same rule'), ...border(JOINED_AROUND, JOINED_WHY) },
    '.alert-danger': { ...text(['own'], 'body text on the alert tint of the same rule'), ...border(PAGE, 'the alert sits on the page; its border is measured against the page, not its own tint') },
    '.alert-success': { ...text(['own'], 'body text on the alert tint of the same rule'), ...border(PAGE, 'the alert sits on the page; its border is measured against the page, not its own tint') },
    '.alert-warning': { ...text(['own'], 'body text on the alert tint of the same rule'), ...border(PAGE, 'the alert sits on the page; its border is measured against the page, not its own tint') },
    '.alert-info': { ...text(['own'], 'body text on the alert tint of the same rule'), ...border(PAGE, 'the alert sits on the page; its border is measured against the page, not its own tint') },
    '.alert-danger .alert-link': text([on('.alert-danger', 'danger-bg')], 'the link is inside the alert and sits on its tint'),
    '.alert-success .alert-link': text([on('.alert-success', 'success-bg')], 'the link is inside the alert and sits on its tint'),
    '.alert-warning .alert-link': text([on('.alert-warning', 'warning-bg')], 'the link is inside the alert and sits on its tint'),
    '.alert-info .alert-link': text([on('.alert-info', 'tint')], 'the link is inside the alert and sits on its tint'),
    '.badge-danger': text(['own'], 'badge text on the badge fill of the same rule'),
    '.badge-success': text(['own'], 'badge text on the badge fill of the same rule'),
    '.badge-warning': text(['own'], 'badge text on the badge fill of the same rule'),
    '.badge-info': { ...text(['own'], 'badge text on the badge fill of the same rule'), ...border(PAGE, 'the badge sits on the page; its boundary is the border, measured against the page') },
    '.text-danger': text(PAGE, 'status text sits on whichever light surface the page draws (P2 uses the same three)'),
    '.text-success': text(PAGE, 'status text sits on whichever light surface the page draws (P2 uses the same three)'),
    '.has-error .help-block': text(PAGE, 'the help text under a field sits on the page'),
    '.has-error .form-control': border(PAGE, 'the control sits on the page; its border is its only boundary'),
    // The global ring and the navigation ring are P5's.
    ':focus-visible': { outline: { measuredBy: P5_LIGHT, why: 'the global ring on the three light surfaces is P5 (focus on surface, canvas and tint)' } },
    '.navbar-default :focus-visible': { outline: { measuredBy: P5_NAV, why: 'the navigation ring on the four navigation fills is P5 (focus-on-dark on navbar-bg, navbar-active-bg, navbar-sec-bg, second-line-navbar-bg)' } },
    '.navbar-secondary :focus-visible': { outline: { measuredBy: P5_NAV, why: 'the navigation ring on the four navigation fills is P5; where the app look makes the secondary bar white (.socialblue--sky), ln-app-look.css replaces this ring with the focus colour, measured there' } },
    '.navbar-second-line :focus-visible': { outline: { measuredBy: P5_NAV, why: 'the navigation ring on the four navigation fills is P5' } },
    // The welcome block's panel: socialblue's rgba(51, 51, 51, 0.8), recorded as a token-source fact at its lightest
    // (over a white block). welcome-block.spec.ts reads the ring and the panel the browser renders.
    '.cover-with-canvas .hero-canvas :focus-visible': ring(
      [WELCOME_PANEL],
      [WELCOME_PANEL],
      'the links, Sign in and the wallet choice sit on the welcome panel (socialbase block--hero-call-to-action-block.html.twig, .hero-canvas) and their ring is drawn 2px outside them, still on the panel; a cover picture behind the panel only darkens it',
    ),
    // A filled primary button over a cover picture ("New post" in the group header): its ring is drawn inside its own
    // primary fill, so the fill is on both sides of the ring. app-look.spec.ts reads the rendered ring on the button.
    '.socialblue--sky .cover.cover-img:not(.cover-with-canvas) .btn-primary:focus-visible': ring(
      ['primary'],
      ['primary'],
      'the ring is drawn 4px inside the filled primary button over a cover picture, so the primary fill is behind it and around it',
    ),
    // The import alert's left bar: import.css draws it in the accent (1.62:1 on white), which is a fill, never a status mark,
    // so this rule re-colours it with the warning-text role, and the gate measures that.
    '.localnodes-import-alert.localnodes-import-alert': border(IMPORT_PAGE, 'the alert is a block in the web form (on the page) or inside the white status card; the 4px bar is its status mark'),
    // The DeepChat bar.
    [`${HEADER}:focus-visible`]: { outline: BAR_RING.outline },
    [`${OPTIONS}:focus-visible`]: { outline: OPTIONS_RING.outline },
    [HEADER]: text([headerFill], "the header text sits on the header fill, --ln-color-text (deepchat.css); this rule sets the colour instead of contrib sticky-chatbot.css's literal white"),
    // Group tab bar overflow list: a white dropdown (the rule's comment: light grey on white).
    '.navbar-secondary.navbar-secondary .navbar-nav .hidden-list li a': text(PAGE, "the overflow list is socialblue's white dropdown; a hovered row takes a light fill, so the three light surfaces are measured"),
    '.navbar-secondary.navbar-secondary .navbar-nav .hidden-list li a:hover': text(PAGE, 'the overflow list is socialblue\'s white dropdown; a hovered row takes a light fill'),
    '.navbar-secondary.navbar-secondary .navbar-nav .hidden-list li a:focus': text(PAGE, 'the overflow list is socialblue\'s white dropdown; a focused row takes a light fill'),
    // Header name and footer.
    '.navbar-brand .ln-site-name': text(
      ['targets.socialblue.navbar-bg'],
      "templates/block/block--system-branding-block--localnodes.html.twig prints the name inside .navbar-brand in socialblue's navbar, which socialblue paints with its navbar-bg field (socialblue-pairings.json P1 navbar-text on navbar-bg)",
    ),
    '.ln-site-footer': text(['targets.socialblue.brand-secondary'], "the rule's comment: socialblue paints .site-footer in brand-secondary (the dark blue); this block prints inside it"),
    '.ln-site-footer a': text(['targets.socialblue.brand-secondary'], 'the footer links sit on the same .site-footer fill, brand-secondary'),
    // Placeholder and meta lines on the post and comment boxes.
    '.form-control::placeholder': text(['surface'], 'the placeholder sits in a white .form-control (see the rule comment in the stylesheet)'),
    'form .form-control::placeholder': text(['surface'], 'the placeholder sits in a white .form-control'),
    '.metainfo__authorname': text(PAGE, 'the meta line sits in the post card (white) or on the page'),
    '.metainfo__published-date': text(PAGE, 'the meta line sits in the post card (white) or on the page'),
    '.post-date': text(PAGE, 'the post date sits in the post card (white) or on the page'),
    '.post-date a': text(PAGE, 'the post date link sits in the post card (white) or on the page'),
    // The sort icon is a mask painted with a background colour: a graphic on the table header.
    '.table th a::after': fillOf({ boundary: PAGE }, 'the sort icon is a mask over a plain background, a 3:1 graphic on the table header, which sits on the page'),
    '.table th.sortable a::after': fillOf({ boundary: PAGE }, 'the sort icon is a mask over a plain background, a 3:1 graphic on the table header, which sits on the page'),
  },
  deepchat: {
    [BAR]: fillOf(
      { under: ['on-primary', { fg: 'focus-on-dark', kind: 'focus' }] },
      'the strip is the dark bar; the header text and the amber ring are drawn on it (templates/ai-deepchat.html.twig)',
    ),
    [HEADER]: fillOf({ under: ['on-primary', { fg: 'focus-on-dark', kind: 'focus' }] }, 'the header text (ln-overrides.css) and its ring are drawn on the header fill'),
    [`${HEADER}:focus-visible`]: BAR_RING,
    [`${OPTIONS}:focus-visible`]: OPTIONS_RING,
    [`${LINK}:focus-visible`]: LINK_RING,
    [LINK]: text(['own'], 'the link text sits on the link fill of the same rule'),
    [`${LINK}:hover`]: text(['own'], 'the hovered link text sits on the hover fill of the same rule'),
    '.ai-deepchat .ln-chat-disclosure': {
      ...fillOf({ under: ['text-muted'] }, 'templates/ai-deepchat.html.twig puts this paragraph below deep-chat; deepchat.css sets both its surface fill and text-muted foreground in the same rule'),
      ...text(['own'], 'templates/ai-deepchat.html.twig places the disclosure text in this paragraph, on its own surface background from deepchat.css'),
    },
  },
  'ai-overview': {
    '.ai-overview-notice': text(['canvas', 'surface'], 'AiOverviewBlock::build() renders this notice before the social-ai-overview.html.twig panel; socialbase block.html.twig can wrap it in a white card, otherwise the page canvas shows through; ai-overview.css gives only the sibling .ai-overview__content its tint'),
    [OVERVIEW]: text(['own'], 'the answer text sits on the tinted card of the same rule (templates/social-ai-overview.html.twig); its hairline edge is the decorative tint-line'),
    '.ai-overview__label': text([on(OVERVIEW, 'tint')], 'the label is in .ai-overview__header inside the tinted card'),
    '.ai-overview__disclosure': text([on(OVERVIEW, 'tint')], 'the disclosure line is in .ai-overview__header inside the tinted card'),
    '.ai-overview__marker': text([on(OVERVIEW, 'tint')], 'the [n] markers are links in the answer text inside the tinted card'),
    '.ai-overview a:focus-visible': ring([on(OVERVIEW, 'tint')], [on(OVERVIEW, 'tint')], 'every link in the overview is inside the tinted card'),
    '.ai-overview__source-list a': text([on(OVERVIEW, 'tint')], 'the Sources list is inside the tinted card'),
  },
  import: {
    // Error summary, field error, drop zone, chosen files.
    [IMPORT_SUMMARY]: { ...text(['own'], 'the summary text sits on the danger tint of the same rule (ImportFormTrait error summary)'), ...border(IMPORT_PAGE, `the summary is a block on the page: ${IMPORT_WHY}`) },
    [`${IMPORT_SUMMARY}:focus`]: importRing([on(IMPORT_SUMMARY, 'danger-bg')], 'the summary takes script focus; its ring sits 2px outside its border, on the page'),
    [`${IMPORT_SUMMARY} a`]: text([on(IMPORT_SUMMARY, 'danger-bg')], 'the links are the list items inside the summary, on its tint'),
    '.localnodes-import-field-error': text(IMPORT_PAGE, `the message sits under its field on the page: ${IMPORT_WHY}`),
    '.localnodes-import-dropzone': {
      ...border(IMPORT_PAGE, `the drop zone is a block on the page: ${IMPORT_WHY}; its dashed border is the zone's only boundary`),
      ...fillOf({ under: ['text'] }, 'the "Drop files here, or" line (js/upload.js, class localnodes-import-dropzone__text) is body text on it'),
    },
    '.localnodes-import-dropzone.is-dragover': {
      ...border(IMPORT_PAGE, `the highlighted zone is the same block on the page: ${IMPORT_WHY}`),
      ...fillOf({ under: ['text'] }, 'the same line, on the highlight fill'),
    },
    '.localnodes-import-chosen__note': text(IMPORT_PAGE, `the note is above the chosen list, on the page: ${IMPORT_WHY}`),
    '.localnodes-import-chosen__stop-note': text(IMPORT_PAGE, `js/upload.js appends this paragraph inside .localnodes-import-chosen__progress after the file field; import.css gives neither that progress row nor .localnodes-import-chosen a fill, so the form background shows through: ${IMPORT_WHY}`),
    '.localnodes-import-chosen__name': text(IMPORT_PAGE, `a row of the chosen list, on the page (measured on the test site: .localnodes-import-chosen sits on body rgb(243, 243, 243))`),
    '.localnodes-import-chosen__size': text(IMPORT_PAGE, 'a row of the chosen list, on the page'),
    '.localnodes-import-chosen__status': text(IMPORT_PAGE, 'a row of the chosen list, on the page'),
    '.localnodes-import-chosen__row[data-state="failed"] .localnodes-import-chosen__status': text(IMPORT_PAGE, 'the failed row of the chosen list, on the page'),
    '.localnodes-import-chosen__reason': text(IMPORT_PAGE, 'the reason line of a row of the chosen list, on the page'),
    '.localnodes-import-chosen__remove': text(IMPORT_PAGE, 'the remove button is transparent (background: transparent) in a row of the chosen list, on the page'),
    '.localnodes-import-chosen__remove:hover': border(IMPORT_PAGE, 'the hover border of the transparent remove button is drawn on the page'),
    '.localnodes-import-dropzone .localnodes-import-upload__input::file-selector-button': text(
      ['surface', 'canvas'],
      "the file button keeps the browser's light button face, between the drop zone's white and the canvas; the zone sets only its text colour",
    ),
    '.localnodes-import-chosen__remove:focus-visible': importRing(),
    '.localnodes-import-chosen__retry:focus-visible': importRing(),
    '.localnodes-import-chosen__stop:focus-visible': importRing(),
    '.localnodes-import-chosen__summary:focus': importRing(),
    [IMPORT_PROGRESS]: fillOf({ under: [{ fg: 'primary', kind: 'non-text' }] }, 'the determinate bar draws its --ln-color-primary fill on this track (templates and js/upload.js)'),
    '.localnodes-import-progress__fill': fillOf({ boundary: [on(IMPORT_PROGRESS, 'canvas')] }, 'the fill is drawn on the track, --ln-color-canvas (.localnodes-import-progress above)'),
    '.localnodes-import-badge--danger': text(['own'], 'badge text on the badge fill of the same rule'),
    '.localnodes-import-badge--success': text(['own'], 'badge text on the badge fill of the same rule'),
    '.localnodes-import-badge--attention': text(['own'], 'badge text on the badge fill of the same rule'),
    '.localnodes-import-badge--neutral': { ...text(['own'], 'badge text on the badge fill of the same rule'), ...border(IMPORT_PAGE, `the badge sits in a report row or a chosen row, on the page: ${IMPORT_WHY}`) },
    '.localnodes-import-spinner': border(['divider', ...IMPORT_PAGE], 'the spinner is a ring: the primary arc turns over the divider-coloured track, beside the page it sits on'),
    '.localnodes-import-upload__back': text(IMPORT_PAGE, `a standalone link beside a button on the page: ${IMPORT_WHY}`),
    '.localnodes-import-report__more': text(IMPORT_PAGE, `the "Import more documents" link under the report, on the page: ${IMPORT_WHY}`),
    // Report.
    '.localnodes-import-report__summary': text(IMPORT_PAGE, `the report summary line, on the page: ${IMPORT_WHY}`),
    '.localnodes-import-report th': text(IMPORT_PAGE, `a column heading of the report table, on the page (or on the row card below 768px): ${IMPORT_WHY}`),
    '.localnodes-import-report__name a': text(IMPORT_PAGE, 'the page title link in a report row, on the page or the row card'),
    '.localnodes-import-report__original': text(IMPORT_PAGE, 'the original file name in a report row, on the page or the row card'),
    '.localnodes-import-report__action': text(IMPORT_PAGE, 'the reason and next step in a report row, on the page or the row card'),
    '.localnodes-import-report__action a': text(IMPORT_PAGE, 'a link inside the reason and next step, on the page or the row card'),
    '.localnodes-import-report a:focus-visible': importRing(),
    '.localnodes-import-report__actions a:focus-visible': importRing(),
    [IMPORT_ROW]: fillOf({ under: ['text', 'text-muted', 'link'] }, 'below 768px a row is a white card; the cells (text, labels, links) are drawn on it'),
    '.localnodes-import-report td::before': text([on(IMPORT_ROW, 'surface')], 'the stacked cell label sits in a row card (the media rule above)'),
    '.localnodes-import-report__count-note': text(IMPORT_PAGE, `the count note above the report, on the page: ${IMPORT_WHY}`),
    '.localnodes-import-report__refresh:focus-visible': importRing(),
    '.localnodes-import-refresh .form-actions a:focus-visible': importRing(),
    '.localnodes-import-progress-hint': text(IMPORT_PAGE, `the hint under the batch bar, on the page: ${IMPORT_WHY}`),
    // The batch page, an admin route: the h1 sits in the dark navbar; the bar is the same 8px bar.
    '.path-batch .navbar-default .section-title': text(['targets.socialblue.navbar-bg'], "the rule's comment: socialblue puts the h1 in the dark navbar on the batch page, which paints navbar-bg"),
    '.path-batch .progress-wrapper .progress': {
      ...border(IMPORT_PAGE, "the rule's comment: a 1px boundary because the track's grey is the page's grey"),
      ...fillOf({ under: [{ fg: 'primary', kind: 'non-text' }] }, 'the batch bar draws its primary fill on this track'),
    },
    '.path-batch .progress-wrapper .progress-bar': fillOf({ boundary: [on('.path-batch .progress-wrapper .progress', 'surface')] }, 'the fill is drawn on the track, --ln-color-surface'),
    // Source box and imported content.
    [IMPORT_SOURCE]: text(['own'], 'the box text sits on the paper fill of the same rule (templates/localnodes-import-source-box.html.twig); its edge is the decorative paper-rule'),
    '.localnodes-import-source__heading': text([on(IMPORT_SOURCE, 'paper')], 'the "Source" label is the first line of the box (templates/localnodes-import-source-box.html.twig); the rule under it is the decorative paper-rule'),
    [`${IMPORT_SOURCE} a`]: text([on(IMPORT_SOURCE, 'paper')], 'the attribution, licence and report links are inside the box (templates/localnodes-import-source-box.html.twig)'),
    [`${IMPORT_SOURCE} a:focus-visible`]: importRing([on(IMPORT_SOURCE, 'paper')], 'the links are inside the paper box'),
    '.localnodes-import-source__meta': text([on(IMPORT_SOURCE, 'paper')], 'the meta line is a paragraph of the box'),
    '.localnodes-import-source__no-text': text([on(IMPORT_SOURCE, 'paper')], 'the no-text notice is a paragraph of the box (templates/localnodes-import-source-box.html.twig)'),
    '.localnodes-import-imported .body-text blockquote': { ...border(PAGE, 'an imported quote sits in the page body, in a white card or on the page'), ...text(PAGE, 'an imported quote sits in the page body, in a white card or on the page') },
    '.localnodes-import-imported .body-text blockquote cite': text(PAGE, 'the cite line of an imported quote, in the page body'),
    // Web form.
    '.localnodes-import-web__licence': text(IMPORT_PAGE, `the licence note in the web form, on the page: ${IMPORT_WHY}`),
    '.localnodes-import-web__licence a': text(IMPORT_PAGE, 'a link in the licence note, on the page'),
    '.localnodes-import-web__licence a:focus-visible': importRing(),
    '.localnodes-import-web__check:focus-visible': importRing(IMPORT_PAGE, `the check button is a white button on the page, so its ring sits outside it on the page: ${IMPORT_WHY}`),
    '.localnodes-import-web__preview-label': text(IMPORT_PAGE, `the preview label in the web form, on the page: ${IMPORT_WHY}`),
    '.localnodes-import__destination': text(IMPORT_PAGE, `the "Importing into" line above the form, on the page: ${IMPORT_WHY}`),
    // Alerts: used in the web form (on the page) and inside the status card (on white).
    [IMPORT_ALERT]: { ...text(['own'], 'the alert text sits on the white fill of the same rule (ImportWebPagesForm, templates/localnodes-import-status.html.twig)'), ...border(IMPORT_PAGE, 'the alert is a block on the page or inside the white status card; the 4px bar is its status mark') },
    [`${IMPORT_ALERT} .localnodes-import-icon`]: text([on(IMPORT_ALERT, 'surface')], 'the icon is inside the alert, on its white fill'),
    // Landing: choice cards, empty state, status panel, past imports, pager.
    [IMPORT_CARD]: fillOf({ under: ['text', 'link'] }, 'a choice card is a white .card; its link text and icon are drawn on it (templates/localnodes-import-landing.html.twig)'),
    [`${IMPORT_CARD}__link`]: text([on(IMPORT_CARD, 'surface')], 'the card link fills the white card'),
    [`${IMPORT_CARD}__link:hover`]: { ...text([on(IMPORT_CARD, 'surface')], 'the card link fills the white card'), ...border(['surface', 'canvas'], 'the 2px primary hover edge is drawn around the link, over the card and, at its outer edge, the page') },
    [`${IMPORT_CARD}__link:focus`]: text([on(IMPORT_CARD, 'surface')], 'the card link fills the white card'),
    [`${IMPORT_CARD}__link:focus-visible`]: importRing(['surface', 'canvas'], 'the link fills a white card; its ring sits 2px outside it, over the card edge and the page'),
    [`${IMPORT_CARD}__icon`]: text([on(IMPORT_CARD, 'surface')], 'the icon is inside the white card'),
    [`${IMPORT_CARD}__title`]: text([on(IMPORT_CARD, 'surface')], 'the title is inside the white card'),
    '.localnodes-import-empty': fillOf({ under: ['text', 'link'] }, 'the empty-state card is a white .card; its text and link are drawn on it (templates/localnodes-import-landing.html.twig)'),
    '.localnodes-import-empty__body a': text([on('.localnodes-import-empty', 'surface')], 'the link is in the white empty-state card'),
    [IMPORT_STATUS]: fillOf({ under: ['text', 'text-muted', 'link', 'success'] }, 'the search status panel is a white .card; its text, link and confirmation are drawn on it (templates/localnodes-import-status.html.twig)'),
    [`${IMPORT_STATUS} a`]: text([on(IMPORT_STATUS, 'surface')], 'the links are list items inside the white status card'),
    [`${IMPORT_STATUS}__all`]: text([on(IMPORT_STATUS, 'surface')], 'the "all searchable" line is inside the white status card'),
    [`${IMPORT_STATUS}__toggle`]: text([on(IMPORT_STATUS, 'surface')], 'the details summary is inside the white status card'),
    [`${IMPORT_STATUS}__toggle:focus-visible`]: importRing([on(IMPORT_STATUS, 'surface')], 'the summary is inside the white status card'),
    [`${IMPORT_STATUS}__reason`]: text([on(IMPORT_STATUS, 'surface')], 'the reason is inside the white status card'),
    [`${IMPORT_STATUS}__updated`]: text([on(IMPORT_STATUS, 'surface')], 'the updated line is inside the white status card'),
    [`${IMPORT_STATUS}__note`]: text([on(IMPORT_STATUS, 'surface')], 'templates/localnodes-import-status.html.twig puts this paragraph in the status footer; import.css paints the enclosing .localnodes-import-status with surface'),
    [`${IMPORT_STATUS}__error`]: text([on(IMPORT_STATUS, 'surface')], 'templates/localnodes-import-status.html.twig puts the check-error paragraph immediately after the summary, inside .localnodes-import-status with its surface fill from import.css'),
    [`${IMPORT_STATUS}__button:focus-visible`]: importRing([on(IMPORT_STATUS, 'surface')], 'the refresh button is inside the white status card'),
    '.localnodes-import-past a': text(IMPORT_PAGE, `the past-imports table sits on the page: ${IMPORT_WHY}`),
    '.localnodes-import-past__kind': text(IMPORT_PAGE, 'the kind label in a past-imports row, on the page'),
    '.localnodes-import-pager .pager__item a': text(['own'], 'a page number is a white box (the fill of the same rule), link text on it'),
    '.localnodes-import-pager .pager__item a[aria-current="page"]': {
      ...text([on('.localnodes-import-pager .pager__item a', 'surface')], 'the current page number is the same white box'),
      ...border(IMPORT_PAGE, 'the primary border marks the current page box, which sits on the page'),
    },
    '.localnodes-import-pager .pager__item a:focus-visible': importRing(),
  },
  treasury: {
    // The test-network banner: an .alert-warning with its own warning fill and a left rule (safe-test-network-banner.html.twig).
    '.alert.safe-test-network-banner': {
      ...fillOf({ under: ['text'] }, 'the banner text (and its <strong>) takes --ln-color-text from the .alert-warning rule in ln-overrides.css; the warning icon is decorative (aria-hidden)'),
      ...border(PAGE, 'the left rule is the banner\'s outer edge; the banner sits above the page title, on the page'),
    },
    '.alert-warning.safe-inline-alert': border(PAGE, RULE_WHY),
    '.alert-warning.safe-receipt-panel': border(PAGE, RULE_WHY),
    '.alert-success.safe-inline-alert': border(PAGE, RULE_WHY),
    '.alert-success.safe-receipt-panel': border(PAGE, RULE_WHY),
    '.alert-danger.safe-inline-alert': border(PAGE, RULE_WHY),
    '.alert-danger.safe-receipt-panel': border(PAGE, RULE_WHY),
    'code.safe-short-id__value': text([...PAGE, ...ALERT_FILLS], ID_WHY),
    'code.safe-full-id': text([...PAGE, ...ALERT_FILLS], ID_WHY),
    '.badge.safe-status-badge--attention': text(['own'], BADGE_WHY),
    '.badge.safe-status-badge--success': text(['own'], BADGE_WHY),
    '.badge.safe-status-badge--danger': text(['own'], BADGE_WHY),
    '.badge.safe-status-badge--neutral': {
      ...text(['own'], BADGE_WHY),
      ...border(PAGE, 'the neutral badge\'s fill is the canvas, so on the canvas page its border is its only edge; the badge sits in a payment row or page, on the page or a card'),
    },
  },
  signin: {
    // The "Or use a password" line between the sign-in entry and the password form, with a rule above it.
    '.ln-signin-entry .ln-signin__or': {
      ...text(
        PAGE,
        'SignIn::formElement() puts this paragraph above the log-in and sign-up forms, on whatever light surface the form page draws (a white card or the page), so the three light surfaces are measured',
      ),
      ...border(PAGE, 'the top rule separates the sign-in entry from the password form, on the same light surfaces as the text'),
    },
    // "Use a browser wallet": a text button under the sign-in entry, link-coloured. In the welcome block it takes the block's own colour instead (color: inherit, no token).
    '.ln-signin-wallet .ln-signin-wallet__button': text(
      PAGE,
      'SignIn::walletChoice() puts this button in the sign-in entry above the log-in and sign-up forms, on the same light surfaces as the "or" line',
    ),
    // The connector's "Opening sign-in…" strip, fixed under the top bar (js/signin.js places it).
    '.siwe-login-waap__waiting': {
      ...text(['own'], 'the waiting line is body text on the surface fill of the same rule'),
      ...border(PAGE, 'the bottom border is the lower edge of the fixed strip, over whatever light surface of the page scrolls under it'),
    },
  },
  'signature-proof': {
    // The "Confirm your sign-in" panel: js/signature-proof.js inserts it after the fund or vote button, in the form.
    '.ln-proof': {
      ...text(['own'], 'the panel text sits on the canvas fill of the same rule'),
      ...fillOf({ under: ['text'] }, 'the title, the line and the add link are drawn on the panel fill'),
      ...border(PAGE, 'the 1px edge and the 4px left rule are the panel\'s outer edge, over the form page or the card it sits in'),
    },
    '.ln-proof:focus-visible': ring(PAGE, PAGE, 'the panel takes focus when it opens; the ring sits 2px outside it, over the form page or card'),
  },
};

// ---------------------------------------------------------------------------
// Parsing
// ---------------------------------------------------------------------------

/** A comma or semicolon split that ignores separators inside (), [] and quotes. */
function splitTop(text_, sep) {
  const out = [];
  let depth = 0;
  let quote = '';
  let buf = '';
  for (const ch of text_) {
    if (quote) {
      if (ch === quote) quote = '';
    } else if (ch === '"' || ch === "'") quote = ch;
    else if (ch === '(' || ch === '[') depth++;
    else if (ch === ')' || ch === ']') depth--;
    if (ch === sep && depth === 0 && !quote) {
      out.push(buf);
      buf = '';
    } else buf += ch;
  }
  out.push(buf);
  return out;
}

const norm = (s) => s.replace(/\s+/g, ' ').trim();

/** One property kind per declaration, from its name. */
function kindOf(prop) {
  if (['color', 'fill', 'stroke', 'text-decoration-color', 'caret-color'].includes(prop)) return 'text';
  if (prop.startsWith('outline')) return 'outline';
  if (prop.startsWith('border') || prop === 'box-shadow' || prop === 'column-rule') return 'border';
  if (prop === 'background' || prop === 'background-color') return 'background';
  return 'other';
}

/** The --ln-color-* names in a value. */
const rolesIn = (value) => [...value.matchAll(/var\(--ln-color-([a-z0-9-]+)\)/g)].map((m) => m[1]);

/** [{ selector, decls: [{ prop, value, important }] }] for each rule, with each selector of a list on its own. */
function readRules(file) {
  const out = [];
  for (const { selector, body } of parseRules(readFileSync(file, 'utf8'))) {
    const decls = splitTop(body, ';')
      .map((d) => {
        const at = d.indexOf(':');
        if (at < 0) return null;
        const value = d.slice(at + 1).trim();
        return { prop: d.slice(0, at).trim().toLowerCase(), value: value.replace(/\s*!important\s*$/i, '').trim(), important: /!important\s*$/i.test(value) };
      })
      .filter(Boolean);
    for (const sel of splitTop(selector, ',').map(norm).filter(Boolean)) out.push({ selector: sel, decls });
  }
  return out;
}

/** The offset in px of an outline-offset value, or null when it is not a plain px length. */
const offsetPx = (value) => (/^-?\d+(\.\d+)?px$/.test(value) ? Number(value.slice(0, -2)) : null);

/**
 * The outline that ln-overrides.css renders for `selector` (which has :focus-visible in it): the colour from the rule with
 * exactly that selector if it has an !important outline colour, else from the global `:focus-visible` rule; the offset
 * the same way. Null when there is no ln-overrides.css to read.
 */
function ringWinner(overrideRules, selector) {
  if (!overrideRules) return null;
  const find = (sel, pick) => {
    for (const r of overrideRules) {
      if (r.selector !== sel) continue;
      for (const d of r.decls) {
        const hit = d.important ? pick(d) : undefined;
        if (hit !== undefined) return { value: hit, selector: sel };
      }
    }
    return null;
  };
  const colour = (d) => (d.prop === 'outline-color' || d.prop === 'outline' ? rolesIn(d.value)[0] : undefined);
  const offset = (d) => (d.prop === 'outline-offset' ? d.value : undefined);
  const c = find(selector, colour) ?? find(':focus-visible', colour);
  const o = find(selector, offset) ?? find(':focus-visible', offset);
  return { role: c?.value, roleFrom: c?.selector, offset: o?.value, offsetFrom: o?.selector };
}

/**
 * The colour an ln-overrides.css rule gives a module's border: a rule whose selector is the module's single
 * class doubled (`.a.a` outranks `.a` without !important) with a border colour of the same side. Null when none.
 */
function borderOverride(overrideRules, selector, prop) {
  if (!overrideRules || !/^\.[\w-]+$/.test(selector)) return null;
  const base = (p) => p.replace(/-color$/, '');
  let found = null;
  for (const r of overrideRules) {
    if (r.selector !== selector + selector) continue;
    for (const d of r.decls) {
      const role = kindOf(d.prop) === 'border' ? rolesIn(d.value)[0] : undefined;
      if (role && (base(d.prop) === base(prop) || base(prop) === 'border')) found = { role, winner: r.selector };
    }
  }
  return found;
}

const isObj = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);

/** The reason a role is decorative, when its token chain (targets -> semantic) says `decorative: true` with a reason. */
function decorativeReason(tree, role) {
  if (!tree) return null;
  const rawAt = (path) => path.split('.').reduce((n, part) => (isObj(n) ? n[part] : undefined), tree);
  const seen = new Set();
  for (let path = ln(role); path && !seen.has(path); ) {
    seen.add(path);
    const node = rawAt(path);
    if (isObj(node) && node.decorative === true) {
      const reason = String(node.reason ?? node.$description ?? '').trim();
      return reason || null;
    }
    const raw = isObj(node) && '$value' in node ? node.$value : node;
    path = typeof raw === 'string' ? raw.match(/^\{([^{}]+)\}$/)?.[1] : undefined;
  }
  return null;
}

/** The individual selectors of the nine accent rules (ACCENT_TEXT_RULES in pairings.mjs), which P2 measures. */
const accentSelectors = (accentRules) => new Set(accentRules.flatMap((r) => splitTop(r.selector, ',').map(norm)));
const ACCENT_IDS = ['surface', 'canvas', 'tint'].map((b) => `P2.socialblue.brand-accent.on.${b}`);

// ---------------------------------------------------------------------------
// The pairs
// ---------------------------------------------------------------------------

/**
 * Account for every var(--ln-color-*) declaration in the stylesheets named in `files`.
 *
 * @param {object}  opts
 * @param {object}  opts.files        { stylesheet key: absolute path }; a key that is absent is not read
 * @param {object}  [opts.partners]   the partner map (default PARTNERS)
 * @param {object}  [opts.tree]       the merged, unresolved token tree (for the decorative roles)
 * @param {object[]} [opts.accentRules] the nine accent rules of ln-overrides.css, measured by P2
 * @returns {{
 *   pairs: object[],       the P13 pairings
 *   overridden: object[],  { sheet, selector, property, asks, renders, winner }: an outline that loses to an !important ring
 *   decorative: object[],  { sheet, selector, property, role, reason }
 *   unmapped: object[],    { sheet, selector, property, role }: a declaration no partner accounts for
 *   stale: object[],       { sheet, selector, kind, message }: the map and the CSS disagree
 *   claims: object[],      { sheet, selector, kind, ids }: declarations that other families measure
 *   drawn: Set<string>     every --ln-color-* name the stylesheets draw
 * }}
 */
export function stylesheetPairings({ files, partners = PARTNERS, tree = null, accentRules = [] } = {}) {
  const name = (sheet) => STYLESHEETS[sheet]?.name ?? sheet;
  const sheets = Object.fromEntries(Object.entries(files).map(([key, file]) => [key, readRules(file)]));
  const overrideRules = sheets.overrides ?? null;
  const accent = accentSelectors(accentRules);

  /** Per sheet: selector -> every declaration of every rule that has it, in file order. */
  const bySel = {};
  for (const [sheet, rules] of Object.entries(sheets)) {
    bySel[sheet] = new Map();
    for (const { selector, decls } of rules) bySel[sheet].set(selector, [...(bySel[sheet].get(selector) ?? []), ...decls]);
  }

  const merged = new Map();
  const out = { overridden: [], decorative: [], unmapped: [], stale: [], claims: [], drawn: new Set() };
  const stale = (sheet, selector, kind, message) => {
    if (!out.stale.some((s) => s.sheet === sheet && s.selector === selector && s.kind === kind && s.message === message)) out.stale.push({ sheet, selector, kind, message });
  };
  const unmapped = (sheet, selector, property, role) => {
    if (!out.unmapped.some((u) => u.sheet === sheet && u.selector === selector && u.property === property && u.role === role)) out.unmapped.push({ sheet, selector, property, role });
  };

  const add = (family, kind, fg, bg, sheet, selector, prop) => {
    const made = pairing(family, kind, fg, bg, '');
    const item = `${selector} ${prop}`;
    const have = merged.get(made.id);
    if (have) {
      if (!have.items.includes(item)) have.items.push(item);
    } else merged.set(made.id, { made, sheet, items: [item] });
  };

  /** The last background role a selector sets, or null. */
  const fillOfSelector = (sheet, selector) => {
    const roles = (bySel[sheet]?.get(selector) ?? []).filter((d) => kindOf(d.prop) === 'background').flatMap((d) => rolesIn(d.value));
    return roles.length ? roles[roles.length - 1] : null;
  };

  /** The token path a bg spec stands for, noting a stale partner on the way. */
  const resolveBg = (spec, ctx) => {
    if (spec === 'own') {
      const role = fillOfSelector(ctx.sheet, ctx.selector);
      if (role === null) {
        stale(ctx.sheet, ctx.selector, ctx.kind, `"own" names this rule's fill, but ${ctx.selector} sets no background with a --ln-color-* in ${name(ctx.sheet)}`);
        return null;
      }
      return ln(role);
    }
    if (typeof spec === 'string') return spec.includes('.') ? spec : ln(spec);
    const target = spec.sheet ?? ctx.sheet;
    if (!bySel[target]) return ln(spec.bg); // that stylesheet is not read here: the map's word stands
    const actual = fillOfSelector(target, spec.from);
    if (actual === null) {
      stale(ctx.sheet, ctx.selector, ctx.kind, `the map puts it on the background of ${spec.from}, which sets no background with a --ln-color-* in ${name(target)}`);
      return ln(spec.bg);
    }
    if (actual !== spec.bg) {
      stale(ctx.sheet, ctx.selector, ctx.kind, `map says --ln-color-${spec.bg}, but ${spec.from} sets --ln-color-${actual} in ${name(target)}; confirm the partner and update the map in scripts/tokens/lib/stylesheet_pairs.mjs`);
    }
    return ln(actual); // measured on what the CSS draws
  };

  const seenGroups = new Set();
  for (const [sheet, rules] of Object.entries(sheets)) {
    for (const selector of new Set(rules.map((r) => r.selector))) {
      const decls = bySel[sheet].get(selector);
      const entries = partners[sheet]?.[selector] ?? {};
      // Group the declarations of this selector by kind: [{ prop, role, important }].
      const groups = new Map();
      for (const d of decls) {
        for (const role of rolesIn(d.value)) {
          out.drawn.add(role);
          const kind = kindOf(d.prop);
          groups.set(kind, [...(groups.get(kind) ?? []), { prop: d.prop, role }]);
        }
      }
      for (const [kind, items] of groups) {
        seenGroups.add(`${sheet}\n${selector}\n${kind}`);
        const live = [];
        for (const it of items) {
          const reason = decorativeReason(tree, it.role);
          if (reason) out.decorative.push({ sheet, selector, property: it.prop, role: it.role, reason });
          else live.push(it);
        }
        if (live.length === 0) continue;
        const entry = entries[kind];
        const ctx = { sheet, selector, kind };

        if (kind === 'text' && sheet === 'overrides' && accent.has(selector)) {
          out.claims.push({ sheet, selector, kind, ids: ACCENT_IDS });
          continue;
        }
        if (entry?.measuredBy) {
          out.claims.push({ sheet, selector, kind, ids: entry.measuredBy });
          continue;
        }
        // A fill the rule's own text is drawn on is accounted for by that text's pair.
        if (!entry && kind === 'background' && (entries.text?.on ?? []).includes('own') && groups.has('text')) continue;
        if (!entry || kind === 'other') {
          for (const it of live) unmapped(sheet, selector, it.prop, it.role);
          continue;
        }

        for (const it of live) {
          if (kind === 'text') {
            if (!entry.on?.length) stale(sheet, selector, kind, 'the entry names no background (on)');
            for (const spec of entry.on ?? []) {
              const bg = resolveBg(spec, ctx);
              if (bg) add(`P13.${sheet}.text`, 'text', ln(it.role), bg, sheet, selector, it.prop);
            }
          } else if (kind === 'border') {
            if (!entry.against?.length) stale(sheet, selector, kind, 'the entry names no surroundings (against)');
            const over = sheet !== 'overrides' ? borderOverride(overrideRules, selector, it.prop) : null;
            const rendered = over?.role ?? it.role;
            if (over && rendered !== it.role) {
              out.overridden.push({ sheet, selector, property: it.prop, asks: it.role, renders: rendered, winner: over.winner });
            }
            for (const spec of entry.against ?? []) {
              const bg = resolveBg(spec, ctx);
              if (bg) add(`P13.${sheet}.border`, 'non-text', ln(rendered), bg, sheet, selector, it.prop);
            }
          } else if (kind === 'background') {
            if (!entry.under?.length && !entry.boundary?.length) stale(sheet, selector, kind, 'the entry names neither the foregrounds drawn on it (under) nor its boundary');
            for (const u of entry.under ?? []) {
              const fg = typeof u === 'string' ? { fg: u, kind: 'text' } : { kind: 'text', ...u };
              add(`P13.${sheet}.fill`, fg.kind, ln(fg.fg), ln(it.role), sheet, selector, it.prop);
            }
            for (const spec of entry.boundary ?? []) {
              const bg = resolveBg(spec, ctx);
              if (bg) add(`P13.${sheet}.fill`, 'non-text', ln(it.role), bg, sheet, selector, it.prop);
            }
          } else if (kind === 'outline') {
            const win = selector.includes(':focus-visible') ? ringWinner(overrideRules, selector) : null;
            const rendered = win?.role ?? it.role;
            const ownOffset = decls.find((x) => x.prop === 'outline-offset')?.value;
            const px = offsetPx(win?.offset ?? ownOffset ?? '0px');
            const inset = px !== null && px < 0;
            if (sheet !== 'overrides' && rendered !== it.role) {
              out.overridden.push({ sheet, selector, property: it.prop, asks: it.role, renders: rendered, winner: win.roleFrom });
            }
            const list = inset ? entry.fill : entry.surroundings;
            if (!list?.length) stale(sheet, selector, kind, `the ring renders ${inset ? 'inset' : 'outside'}, and the entry names no ${inset ? 'fill' : 'surroundings'}`);
            for (const spec of list ?? []) {
              const bg = resolveBg(spec, ctx);
              if (bg) add(`P13.${sheet}.focus`, 'focus', ln(rendered), bg, sheet, selector, it.prop);
            }
          }
        }
      }
    }
  }

  // An entry whose selector or property is no longer in its stylesheet.
  for (const [sheet, bySelector] of Object.entries(partners)) {
    if (!bySel[sheet]) continue;
    for (const [selector, kinds] of Object.entries(bySelector)) {
      if (!bySel[sheet].has(selector)) {
        for (const kind of Object.keys(kinds)) stale(sheet, selector, kind, `entry for a selector that is not in ${name(sheet)} any more; remove it from PARTNERS`);
        continue;
      }
      for (const kind of Object.keys(kinds)) {
        if (!seenGroups.has(`${sheet}\n${selector}\n${kind}`)) stale(sheet, selector, kind, `no ${kind} declaration with a --ln-color-* is left in ${selector} (${name(sheet)}); remove the entry from PARTNERS`);
      }
    }
  }

  const pairs = [...merged.values()].map(({ made, sheet, items }) => ({ ...made, source: `${name(sheet)} ${items.join('; ')}` }));
  return { pairs, ...out };
}
