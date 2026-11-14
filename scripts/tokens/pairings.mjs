/**
 * The declared pairing set for scripts/tokens/check_contrast.mjs (22.1-05, DES-01, D-09).
 *
 * A pairing is one foreground drawn on one background, both named by token path
 * in the resolved token tree, with the WCAG 2.2 AA bar for the kind of thing it
 * is:
 *   text      4.5:1  (SC 1.4.3, text and images of text)
 *   non-text  3:1    (SC 1.4.11, the parts of a control and meaningful graphics)
 *   focus     3:1    (SC 1.4.11 applied to the keyboard focus ring)
 *
 * The bars live here, once, in THRESHOLDS. A test pins them (T-22.1-16).
 *
 * Two things can sit between a stock pairing and what is rendered, and both are
 * visible in the output, never silent (22.1-05, Q2):
 *   - an OVERRIDE: ln-overrides.css (Drupal) re-colours socialblue's accent-as-text
 *     rules, and the Nuxt secondary fill takes the text role. The pairing keeps its
 *     id, is measured with the colour that is actually drawn, and its OK line says
 *     "overridden by ...". If an override is missing the pairing is measured with
 *     the stock colour and FAILs, naming the rule.
 *   - an EXCLUSION: a pair that is not rendered today (Nuxt dark mode). It is listed
 *     in EXCLUDED with a reason, printed as EXCLUDED, never counted as OK, and voided
 *     by the dark-mode tripwire (lib/dark_tripwire.mjs).
 *
 * Families (RESEARCH Pattern 5), by id prefix:
 *   P1..P3  socialblue's own stylesheets (derived; see derive_socialblue_pairings.mjs)
 *   P4      ln-overrides.css
 *   P5      focus ring
 *   P6      control borders and fills
 *   P7      status colours
 *   P8/P9   Nuxt light / Nuxt dark
 *   P10     DeepChat
 *   P11     email
 *   P12     the three decorative amber hover fills and the focus ring that backs each (22.1-05 Q3)
 *   P13     the colours the module stylesheets draw (ln-overrides.css, import.css, ai-overview.css, deepchat.css),
 *           P13.<stylesheet>.<kind>; lib/stylesheet_pairs.mjs holds the partner map (22.1-17, review WR-06)
 *   PB      the landing page's dark hero band
 *   P2 also holds the text roles on the three light surfaces.
 */
import { readFileSync } from 'node:fs';
import { resolve as resolvePath } from 'node:path';
import { TokenError, repoRoot } from './lib/tokens.mjs';
import { parseRules } from './derive_socialblue_pairings.mjs';
import { THRESHOLDS, ln, normSelector, pairing, scheme, short } from './lib/pairing.mjs';
import { STYLESHEETS, stylesheetFiles, stylesheetPairings } from './lib/stylesheet_pairs.mjs';

// The primitives moved to lib/pairing.mjs (22.1-17, no import cycle with lib/stylesheet_pairs.mjs); these two keep
// every existing import of them from this file working.
export { THRESHOLDS, pairing };

/**
 * The three stock socialblue fills that draw brand-accent (#ffc142 in the stock scheme) on white at 1.62:1, under the
 * 3:1 of SC 1.4.11. They are hover decoration: a ::before bar and an icon disc that the accent-as-text overrides left
 * alone (22.1-05 Q2). Q3 (orchestrator, 2026-11-08, option a) declared them as non-text pairs and excluded them, so the
 * gate shows them instead of ignoring them. Each fill has a `key` that makes its pair ids distinct (the three are the
 * same colour on the same surface, so without it they would collapse into one id), the socialblue rule that draws it
 * (`selector`, checked against the stock stylesheets by a test), the control that takes the focus (`controlSelector`)
 * and the background that control sits on (`controlBg`), which is where its focus ring is measured.
 */
export const DECORATIVE_FILLS = Object.freeze(
  [
    {
      key: 'sidebar-card-button',
      selector: '.socialblue--sky .card--views__sidebar .card__actionbar .btn:hover:before',
      controlSelector: '.socialblue--sky .card--views__sidebar .card__actionbar .btn',
      controlBg: 'semantic.surface', // the sidebar card is socialblue's white .card
    },
    {
      key: 'featured-link',
      selector: '.socialblue--sky .paragraph--featured .field--name-field-featured-link a:hover:before',
      controlSelector: '.socialblue--sky .paragraph--featured .field--name-field-featured-link a',
      controlBg: 'semantic.canvas', // .paragraph--section .paragraph--featured { background-color: #f3f3f3 } in social_featured_content
    },
    {
      key: 'course-nav-icon',
      selector: '.course__navigation .course_nav-title:hover .icon',
      controlSelector: '.course__navigation .course_nav-title',
      controlBg: 'semantic.surface', // .course__navigation is a white .card; the title is in its header
    },
  ].map((d) => Object.freeze(d)),
);

/** Why the three fills are not a failure: SC 1.4.11 does not ask 3:1 of decoration the control does not need to be identified. */
export const DECORATIVE_REASON = 'decorative hover affordance; the control keeps its 3:1 focus ring and text';

/** The focus twin of a decorative fill: the focus ring that applies to the control, on the background it sits on. */
const focusTwinId = (d) => `P12.${d.key}.focus.on.${short(d.controlBg)}`;
const fillId = (d) => `P12.${d.key}.socialblue.brand-accent.on.surface`;

/**
 * Pairings that fail today but are rendered harmlessly or not at all, so the gate prints them as EXCLUDED (with the
 * ratio and the reason) instead of failing the build. They are never counted as OK. Every entry needs a reason, an id
 * that is no pairing is an error, and every entry says what makes the exclusion safe, one of:
 *   voidedBy: 'dark-mode'  the pair is not rendered because dark mode is not. The dark-mode tripwire
 *                          (lib/dark_tripwire.mjs) voids exactly these entries when dark mode is rendered.
 *   focusTwin: <pair id>   the pair is decoration and the control keeps a focus ring, measured at 3:1 as that pair.
 *                          The twin must exist and be a focus pair (assertExclusionsKnown), and if it fails the
 *                          exclusion is void (check_contrast.mjs): the exclusion rests on something measured.
 * check_contrast.mjs also fails when an excluded pair now passes (STALE: remove it), except for an entry flagged
 *   paintedElsewhere: true  the gate measures a colour the browser does not paint for this pair (the static gate does not
 *                          follow the cascade there), so whether that colour passes says nothing. Such an entry must
 *                          have a focusTwin, the ring that is painted; the twin is measured and must pass, or the
 *                          exclusion is void and the twin FAILs.
 * No dark pair is excluded since the teal palette (2026-11-12): on the landing's dark values every Nuxt dark pair
 * passes, so the two that were excluded (dimmed text and error on the accented fill) are measured like any other.
 * voidedBy stays supported, and the tripwire still fails the gate when dark mode is rendered.
 */
export const EXCLUDED = Object.freeze(
  [
    ...DECORATIVE_FILLS.map((d) => ({ id: fillId(d), reason: DECORATIVE_REASON, focusTwin: focusTwinId(d) })),
    {
      id: 'P13.app-look.focus.drupal-css.--ln-color-focus.on.socialblue.navbar-bg',
      reason:
        'not measured here: on the open search form in the dark top bar the browser paints focus-on-dark on this ring, not the global focus colour; tests/a11y/search-pill.spec.ts asserts the painted ring',
      focusTwin: 'P5.focus-on-dark.on.socialblue.navbar-bg',
      paintedElsewhere: true,
    },
  ].map((e) => Object.freeze(e)),
);

/**
 * Throws when an exclusion has no reason, names an id that is not one of `pairings`, rests on neither a tripwire nor a
 * focus twin, or its focus twin is missing or is not a focus pair.
 */
export function assertExclusionsKnown(excluded, pairings) {
  const byId = new Map(pairings.map((p) => [p.id, p]));
  for (const e of excluded) {
    if (typeof e.reason !== 'string' || e.reason.trim() === '') throw new TokenError(`exclusion ${e.id} has no reason`);
    if (!byId.has(e.id)) throw new TokenError(`exclusion ${e.id}: no such pairing (remove it from EXCLUDED in scripts/tokens/pairings.mjs)`);
    if (e.paintedElsewhere && !e.focusTwin) throw new TokenError(`exclusion ${e.id} is flagged paintedElsewhere but names no focus twin, the ring that is painted (set focusTwin in EXCLUDED)`);
    if (!e.voidedBy && !e.focusTwin) throw new TokenError(`exclusion ${e.id} rests on neither a tripwire or a focus twin (set voidedBy or focusTwin in EXCLUDED)`);
    if (e.focusTwin) {
      const twin = byId.get(e.focusTwin);
      if (!twin) throw new TokenError(`exclusion ${e.id}: its focus ring pair ${e.focusTwin} is missing, and the exclusion rests on it (declare it in scripts/tokens/pairings.mjs)`);
      if (twin.kind !== 'focus') throw new TokenError(`exclusion ${e.id}: ${e.focusTwin} is not a focus pair (kind ${twin.kind})`);
    }
  }
}

/**
 * The nine rules in socialblue's stock stylesheets that paint brand-accent as the text
 * or icon colour (all hover, focus or active states, plus one checkmark). ln-overrides.css
 * re-colours each with the same selector text, word for word, so the override has the same
 * specificity and wins by load order. `props` are what the stock rule sets.
 * A test reads the stock stylesheets and asserts this list is complete.
 */
export const ACCENT_TEXT_RULES = Object.freeze(
  [
    ['.card__featured-items .teaser__info svg:hover, .card__featured-items .teaser__info svg:focus, .card__featured-items .teaser__info svg:active', ['color', 'fill']],
    ['.course__navigation .course_nav-title:hover, .course__navigation .course_nav-title:active, .course__navigation .course_nav-title:focus', ['color']],
    ['.course__navigation .course_nav-toggle:hover, .course__navigation .course_nav-toggle:active, .course__navigation .course_nav-toggle:focus', ['color']],
    ['.course__navigation .course_nav-toggle:hover svg, .course__navigation .course_nav-toggle:active svg, .course__navigation .course_nav-toggle:focus svg', ['fill']],
    ['.phase.phase-past .phase-past--icon .checkmark-icon', ['fill']],
    ['.phase .teaser__tag a:hover, .phase .teaser__tag a:active, .phase .teaser__tag a:focus', ['color']],
    ['.phase .hero__banner-static .hero__tag a:hover, .phase .hero__banner-static .hero__tag a:active, .phase .hero__banner-static .hero__tag a:focus', ['color']],
    ['.socialblue--sky .card--views__sidebar .card__actionbar .btn:hover, .socialblue--sky .card--views__sidebar .card__actionbar .btn:active, .socialblue--sky .card--views__sidebar .card__actionbar .btn:focus', ['color']],
    ['.socialblue--sky .paragraph--featured .field--name-field-featured-link a:hover, .socialblue--sky .paragraph--featured .field--name-field-featured-link a:active, .socialblue--sky .paragraph--featured .field--name-field-featured-link a:focus', ['color']],
  ].map(([selector, props]) => Object.freeze({ selector, props })),
);

/** Stock rules that draw brand-accent as a background only (the ::before bars, the icon disc): fills, not text. */
const ACCENT_BAR_RULES = 3;
/** The derived P2 pairings that the nine rules above produce. */
const ACCENT_TEXT_IDS = new Set(['surface', 'canvas', 'tint'].map((b) => `P2.socialblue.brand-accent.on.${b}`));

/** All fg x bg combinations of one kind in one family. */
function product(family, kind, fgs, bgs, source) {
  return fgs.flatMap((fg) => bgs.map((bg) => pairing(family, kind, fg, bg, source)));
}

const SURFACES = ['semantic.surface', 'semantic.canvas', 'semantic.tint'];

function textOnSurfaces() {
  const roles = ['text', 'text-strong', 'text-muted', 'link', 'primary', 'danger', 'success', 'warning-text', 'on-highlight'].map((r) => `semantic.${r}`);
  return product('P2', 'text', roles, SURFACES, 'text role on a light surface (22-UI-SPEC Part 1)');
}

function focus() {
  return [
    ...product('P5', 'focus', ['semantic.focus'], SURFACES, 'keyboard focus ring on the light surfaces'),
    ...product(
      'P5',
      'focus',
      ['semantic.focus-on-dark'],
      [scheme('navbar-bg'), scheme('navbar-active-bg'), scheme('navbar-sec-bg'), scheme('second-line-navbar-bg')],
      'keyboard focus ring on the dark navigation bars',
    ),
  ];
}

/**
 * P12: the three decorative amber fills (non-text, 3:1) and, beside each, the focus ring that applies to its control
 * on the background the control sits on (focus, 3:1). The fills are EXCLUDED (see DECORATIVE_FILLS); the twins are
 * ordinary pairings, so a ring that drops below 3:1 fails the build and voids the exclusion.
 * The ring is the global `:focus-visible` rule of ln-overrides.css (--ln-color-focus = semantic.focus); none of the
 * three controls is in a navigation bar, which has its own ring colour.
 */
function decorativeFills() {
  return DECORATIVE_FILLS.flatMap((d) => {
    const family = `P12.${d.key}`;
    const ring = pairing(family, 'focus', 'semantic.focus', d.controlBg, `focus ring of ${d.controlSelector}`);
    return [
      pairing(family, 'non-text', scheme('brand-accent'), 'semantic.surface', `socialblue rule ${d.selector}: the stock accent bar or disc on white (decorative hover affordance)`),
      { ...ring, id: focusTwinId(d), source: `${ring.source}: the global :focus-visible ring (ln-overrides.css), ${short(d.controlBg)} behind the control` },
    ];
  });
}

/**
 * What ln-overrides.css does to the accent-as-text rules: { name, missing[] }.
 * `name` is the one --ln-color-* the nine rules are set to, or null when any rule is absent,
 * lacks a declaration the stock rule has, or the rules disagree on the colour.
 */
function accentOverride(overridesFile) {
  const rules = new Map(parseRules(readFileSync(overridesFile, 'utf8')).map((r) => [normSelector(r.selector), r.body]));
  const names = new Set();
  const missing = [];
  for (const { selector, props } of ACCENT_TEXT_RULES) {
    const body = rules.get(selector);
    const found = props.map((prop) => body?.match(new RegExp(`(?:^|[;\\s])${prop}:\\s*var\\(--ln-color-([a-z-]+)\\)`))?.[1]);
    if (!body || found.some((n) => n === undefined)) missing.push(selector);
    else found.forEach((n) => names.add(n));
  }
  return { name: missing.length === 0 && names.size === 1 ? [...names][0] : null, missing, names: [...names] };
}

/** P1..P3: the pairings derived from socialblue's stock stylesheets (derive_socialblue_pairings.mjs). */
function socialblue(overridesFile) {
  const file = resolvePath(repoRoot, 'scripts/tokens/socialblue-pairings.json');
  let doc;
  try {
    doc = JSON.parse(readFileSync(file, 'utf8'));
  } catch (e) {
    throw new TokenError(`cannot read the derived socialblue pairings ${file}: ${e.message} (run scripts/tokens/derive_socialblue_pairings.mjs)`);
  }
  const accent = accentOverride(overridesFile);
  return doc.pairings.map((p) => {
    const base = pairing(p.family, p.kind, p.fg, p.bg, `socialblue ${p.example.file} ${p.example.selector}, ${p.rules} rule${p.rules === 1 ? '' : 's'}`);
    if (!ACCENT_TEXT_IDS.has(p.id)) return base;
    // brand-accent as text: the stock rules draw it, ln-overrides.css re-colours them.
    if (p.rules !== ACCENT_TEXT_RULES.length + ACCENT_BAR_RULES) {
      throw new TokenError(`${p.id}: socialblue now has ${p.rules} rules for this pair, not ${ACCENT_TEXT_RULES.length + ACCENT_BAR_RULES}; review ACCENT_TEXT_RULES in scripts/tokens/pairings.mjs and ln-overrides.css`);
    }
    if (accent.name === null) {
      const why = accent.missing.length ? `NOT overridden: ${accent.missing.join(' | ')}` : `the override rules disagree on the colour (${accent.names.join(', ')})`;
      return { ...base, source: `${base.source}; ${why}` };
    }
    const note = `overridden by ln-overrides.css (${ACCENT_TEXT_RULES.length} rules -> --ln-color-${accent.name}); ${ACCENT_BAR_RULES} amber bar fills (hover decoration) are not measured here, they are the P12 pairs`;
    return { ...base, fg: ln(accent.name), note, source: `${base.source}; ${note}` };
  });
}

export const OVERRIDES_FILE = resolvePath(repoRoot, 'html/modules/custom/localnodes_platform/css/ln-overrides.css');

/** P4: ln-overrides.css. A colour + background rule is a text pair; a border colour is a 3:1 pair against the surface. */
function overrides(overridesFile) {
  const css = readFileSync(overridesFile, 'utf8');
  const out = [];
  for (const { selector, body } of parseRules(css)) {
    const v = (prop) => body.match(new RegExp(`(?:^|[;\\s])${prop}:\\s*var\\(--ln-color-([a-z-]+)\\)`))?.[1];
    const sel = selector.replace(/\s+/g, ' ').split(',')[0].trim();
    const fg = v('color');
    const bg = v('background-color') ?? v('background');
    if (fg && bg) out.push(pairing('P4', 'text', ln(fg), ln(bg), `ln-overrides.css ${sel}`));
    const border = v('border-color') ?? body.match(/border:[^;]*var\(--ln-color-([a-z-]+)\)/)?.[1];
    if (border) out.push(pairing('P4', 'non-text', ln(border), 'semantic.surface', `ln-overrides.css ${sel} (border)`));
  }
  return out;
}

/** P6: control boundaries and the fills that show a control's state (SC 1.4.11). */
function controls() {
  const tracks = [...SURFACES, 'semantic.divider'];
  return [
    ...product('P6', 'non-text', ['semantic.border-control'], SURFACES, 'a control border or input outline'),
    ...product('P6', 'non-text', ['semantic.primary'], tracks, 'checked radio and checkbox fill, tab underline, progress fill (and its divider-coloured track)'),
  ];
}

/** P7: status colours as text, as a fill under white text, and the body and link text on their backgrounds. */
function status() {
  const out = [];
  for (const s of ['danger', 'success']) {
    out.push(...product('P7', 'text', [`semantic.${s}`], ['semantic.surface', `semantic.${s}-bg`], `${s} text on a surface and on its own tint`));
  }
  out.push(...product('P7', 'text', ['semantic.warning-text'], ['semantic.surface', 'semantic.warning-bg'], 'warning text on a surface and on its own tint'));
  out.push(pairing('P7', 'text', 'semantic.on-danger', 'semantic.danger', 'white text on .btn-danger'));
  const tints = ['semantic.danger-bg', 'semantic.success-bg', 'semantic.warning-bg'];
  out.push(...product('P7', 'text', ['semantic.text', 'semantic.link'], tints, 'body and link text on each status tint (.alert-*)'));
  return out;
}

/** The Nuxt UI roles of one mode, by name. */
const ui = (mode, name) => `targets.nuxt.${mode}.--ui-${name}`;

/** P8 (light) and P9 (dark): the Nuxt UI semantic roles against each other. */
function nuxt(mode) {
  const family = mode === 'light' ? 'P8' : 'P9';
  const texts = ['text', 'text-muted', 'text-toned', 'text-dimmed', 'text-highlighted', 'primary', 'error', 'success', 'warning'];
  const bgs = ['bg', 'bg-muted', 'bg-elevated', 'bg-accented'];
  const solids = ['primary', 'secondary', 'error', 'success', 'warning'];
  const out = [
    ...product(family, 'text', texts.map((t) => ui(mode, t)), bgs.map((b) => ui(mode, b)), `Nuxt ${mode}: a text role on a background role`),
    ...solids.map((s) => {
      const p = pairing(family, 'text', ui(mode, 'text-inverted'), ui(mode, s), `Nuxt ${mode}: inverted text on the solid ${s} fill`);
      if (mode !== 'light' || s !== 'secondary') return p;
      // Nuxt UI draws a solid secondary with --ui-text-inverted (white): 1.62:1 on the amber. The light --ui-text role
      // (#343434, BRAND.md's text on the highlight) is 7.68:1. It takes a compound variant in the Nuxt repo's
      // app.config.ts (22.1-05 SUMMARY, Q2 resolution); no solid secondary is used on the site today.
      const note = `Nuxt UI's white text-inverted is 1.62:1 on this fill, so it is measured as ${ui(mode, 'text')}, which the Nuxt repo's app.config.ts must apply: compoundVariants { color: 'secondary', variant: 'solid', class: 'text-default' }; no solid secondary is used on the site today`;
      return { ...p, fg: ui(mode, 'text'), note, source: `${p.source}; ${note}` };
    }),
    ...product(family, 'non-text', [ui(mode, 'border-accented')], [ui(mode, 'bg'), ui(mode, 'bg-muted')], `Nuxt ${mode}: the accented border (input outline) on the page`),
  ];
  // Nuxt UI's neutral solid fill is --ui-bg-inverted, which the token file leaves at Nuxt's default.
  const neutral = mode === 'light' ? 'primitive.color.neutral.900' : 'primitive.color.neutral.white';
  out.push(pairing(family, 'text', ui(mode, 'text-inverted'), neutral, `Nuxt ${mode}: inverted text on the neutral solid fill (Nuxt's default --ui-bg-inverted)`));
  return out;
}

/**
 * P14: the app look (2026-11-12). The raised fill of inputs, the composer and the question bubble, with the text and
 * the control border drawn on it; and where an answer meets its source: the amber citation chip, the paper slip and
 * the highlighted sentence on it.
 */
function appLook() {
  return [
    ...product('P14', 'text', ['text', 'text-strong', 'text-muted', 'link', 'primary'].map((r) => `semantic.${r}`), ['semantic.raised'], 'text, placeholder and links on the raised fill (inputs, the composer, the question bubble)'),
    pairing('P14', 'non-text', 'semantic.border-control', 'semantic.raised', 'an input border on its own raised fill'),
    pairing('P14', 'focus', 'semantic.focus', 'semantic.raised', 'the focus ring of a control on the raised fill'),
    pairing('P14', 'text', 'semantic.on-cite', 'semantic.cite', 'the citation chip text on its amber fill'),
    ...product('P14', 'text', ['semantic.on-paper', 'semantic.paper-label'], ['semantic.paper'], 'the quoted passage and its label line on the paper slip'),
    pairing('P14', 'text', 'semantic.on-paper', 'semantic.mark', 'the cited sentence on its highlight'),
  ];
}

/** P10: the DeepChat style file's roles (shadow DOM, so socialblue's CSS cannot reach it). */
function deepchat() {
  const d = (n) => `targets.deepchat.${n}`;
  return [
    ...product('P10', 'text', [d('text'), d('text-muted'), d('link')], [d('surface'), d('canvas'), d('raised')], 'DeepChat message and input text, placeholder and links on the bubble colours (the question bubble and the input are raised)'),
    pairing('P10', 'text', d('on-cite'), d('cite'), 'DeepChat [n] citation chip: its number on the amber chip'),
    pairing('P10', 'non-text', d('border-control'), d('raised'), 'DeepChat input border on the raised input'),
    ...product('P10', 'text', [d('surface')], [d('submit'), d('submit-hover')], 'DeepChat submit icon on its button and hover colour'),
    pairing('P10', 'focus', d('focus'), d('surface'), 'DeepChat focus outline on the panel'),
    pairing('P10', 'non-text', d('border-control'), d('surface'), 'DeepChat input border'),
  ];
}

/** P11: the one mail template (templates/email/email--localnodes.html.twig). */
function email() {
  const e = (n) => `targets.email.${n}`;
  return [
    ...product('P11', 'text', [e('text'), e('heading'), e('link'), e('button')], [e('card')], 'mail body text, headings, links and the outline button on the white card'),
    ...product('P11', 'text', [e('text'), e('link'), e('footer-text')], [e('body')], 'mail text and links on the page colour, and the footer text that sits on it'),
    ...product('P11', 'text', [e('button-text')], [e('button'), e('button-hover')], 'mail button text on the button and its hover colour'),
    pairing('P11', 'text', e('footer-text'), e('footer-bg'), 'mail footer text on its own background'),
  ];
}

/** PB: the landing page's dark hero band (BRAND.md section 5; CANVAS-BRIEF "Option B dark band"). */
function band() {
  const b = (n) => `semantic.band.${n}`;
  const src = 'dark hero band (CANVAS-BRIEF Option B dark band)';
  return [
    ...product('PB', 'text', [b('text'), b('text-muted'), b('link')], [b('bg')], `${src}: text, muted text and link on the band`),
    pairing('PB', 'non-text', b('button'), b('bg'), `${src}: primary button fill against the band`),
    pairing('PB', 'text', b('button-text'), b('button'), `${src}: primary button text`),
    pairing('PB', 'focus', b('focus'), b('bg'), `${src}: focus ring on the band`),
    pairing('PB', 'non-text', b('search-field'), b('bg'), `${src}: search box against the band`),
    pairing('PB', 'text', b('search-text'), b('search-field'), `${src}: typed text`),
    pairing('PB', 'text', b('search-placeholder'), b('search-field'), `${src}: placeholder`),
    pairing('PB', 'text', b('answer-card-text'), b('answer-card'), `${src}: answer card text`),
    pairing('PB', 'text', b('source-chip-text'), b('source-chip'), `${src}: source chip text`),
    pairing('PB', 'non-text', b('source-chip'), b('answer-card'), `${src}: source chip against the answer card`),
    pairing('PB', 'text', b('coming-chip-text'), b('coming-chip'), `${src}: "Coming" chip text`),
    pairing('PB', 'non-text', b('coming-chip'), b('answer-card'), `${src}: "Coming" chip against the answer card`),
  ];
}

/**
 * What the five module stylesheets draw (lib/stylesheet_pairs.mjs): the P13 pairs and the OVERRIDDEN rings.
 * `overridesFile` is the ln-overrides.css under test (check_contrast.mjs --overrides).
 */
export function declaredStylesheets({ overridesFile = OVERRIDES_FILE, tree = null, partners } = {}) {
  return stylesheetPairings({ files: stylesheetFiles(repoRoot, { overrides: overridesFile }), tree, accentRules: ACCENT_TEXT_RULES, ...(partners ? { partners } : {}) });
}

/** Every declared pairing for the resolved token tree. Ids are unique; a duplicate is a bug. */
export function declaredPairings(resolved, { overridesFile = OVERRIDES_FILE, tree = null, partners } = {}) {
  void resolved;
  const sheets = declaredStylesheets({ overridesFile, tree, partners });
  const all = [
    ...socialblue(overridesFile),
    ...textOnSurfaces(),
    ...overrides(overridesFile),
    ...focus(),
    ...decorativeFills(),
    ...controls(),
    ...status(),
    ...nuxt('light'),
    ...nuxt('dark'),
    ...appLook(),
    ...deepchat(),
    ...email(),
    ...band(),
    ...sheets.pairs,
  ];
  const seen = new Map();
  for (const p of all) {
    if (seen.has(p.id)) continue;
    seen.set(p.id, p);
  }
  // A declaration that the partner map says another family measures: the pairs it names must exist (22.1-17).
  for (const c of sheets.claims) {
    for (const id of c.ids) {
      if (!seen.has(id)) throw new TokenError(`${STYLESHEETS[c.sheet].name} ${c.selector} ${c.kind}: measuredBy ${id}, but no pairing has that id (scripts/tokens/lib/stylesheet_pairs.mjs)`);
    }
  }
  return [...seen.values()];
}

// ---------------------------------------------------------------------------
// Coverage (D-09): every colour token is in a checked pairing or says why not.
// ---------------------------------------------------------------------------

const isObj = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);

function rawAt(tree, path) {
  let node = tree;
  for (const part of path.split('.')) node = isObj(node) ? node[part] : undefined;
  return node;
}

/** The path, then each reference it follows: targets.x -> semantic.y -> primitive.z. */
function chain(tree, path) {
  const out = [path];
  for (let cur = path; ; ) {
    const node = rawAt(tree, cur);
    const raw = isObj(node) && '$value' in node ? node.$value : node;
    const ref = typeof raw === 'string' ? raw.match(/^\{([^{}]+)\}$/)?.[1] : undefined;
    if (!ref || out.includes(ref)) return out;
    out.push(ref);
    cur = ref;
  }
}

/** Colour leaves (6-digit hex in the resolved tree) under one top-level key. */
function colourPaths(resolved, top) {
  const out = [];
  const walk = (node, path) => {
    if (typeof node === 'string') {
      if (/^#[0-9a-f]{6}$/.test(node)) out.push(path);
    } else if (isObj(node)) {
      for (const [k, v] of Object.entries(node)) walk(v, `${path}.${k}`);
    }
  };
  walk(resolved[top], top);
  return out;
}

/**
 * Colour tokens that no pairing checks and that are not marked decorative with a reason.
 * Checked: every `semantic` colour (light, dark, band) and every `targets` colour (the 15
 * socialblue fields and the Nuxt, DeepChat, email and Drupal roles). `tree` is the merged,
 * unresolved source (it holds the `decorative` and `reason` keys); `resolved` its resolution.
 * A semantic colour counts as covered when a pairing names it or names a target role that
 * references it; a target role also counts when the semantic colour it references is covered.
 * `drawn` (a Set of token paths) is the --ln-color-* roles that the module stylesheets draw: such a role counts as covered
 * only by a pairing that names it. The via-semantic shortcut said a role was fine when its semantic colour sat in any
 * pairing, on any background, which is how the header name and the DeepChat bar went unmeasured (22.1-17, review WR-06).
 * Returns [{ path, why }].
 */
export function uncovered(tree, resolved, pairings, { drawn = new Set() } = {}) {
  const touched = new Set();
  for (const p of pairings) {
    for (const path of [p.fg, p.bg]) for (const hop of chain(tree, path)) if (!hop.startsWith('primitive.')) touched.add(hop);
  }
  const out = [];
  for (const path of [...colourPaths(resolved, 'semantic'), ...colourPaths(resolved, 'targets')]) {
    const hops = chain(tree, path);
    const flagged = hops.map((h) => rawAt(tree, h)).find((n) => isObj(n) && n.decorative === true);
    if (flagged) {
      const reason = String(flagged.reason ?? flagged.$description ?? '').trim();
      if (!reason) out.push({ path, why: 'marked decorative without a reason' });
      continue;
    }
    const direct = touched.has(path);
    const viaSemantic = !drawn.has(path) && path.startsWith('targets.') && hops.slice(1).some((h) => h.startsWith('semantic.') && touched.has(h));
    if (!direct && !viaSemantic) out.push({ path, why: 'in no pairing and not decorative' });
  }
  return out;
}
