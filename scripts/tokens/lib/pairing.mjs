/**
 * The pairing primitives shared by scripts/tokens/pairings.mjs and scripts/tokens/lib/stylesheet_pairs.mjs
 * (22.1-17). They live in a leaf module so the two can both import them with no import cycle: this file imports
 * nothing of its own repo.
 *
 * A pairing is one foreground drawn on one background, both named by token path in the resolved token tree, with the
 * WCAG 2.2 AA bar for the kind of thing it is:
 *   text      4.5:1  (SC 1.4.3, text and images of text)
 *   non-text  3:1    (SC 1.4.11, the parts of a control and meaningful graphics)
 *   focus     3:1    (SC 1.4.11 applied to the keyboard focus ring)
 * The bars live here, once, in THRESHOLDS. A test pins them (T-22.1-16).
 */

export const THRESHOLDS = Object.freeze({ text: 4.5, 'non-text': 3, focus: 3 });

/** The id's short name for a token path: no leading semantic. or targets. */
export const short = (path) => path.replace(/^(semantic|targets)\./, '');

/** A pairing object. `min` always comes from THRESHOLDS, never from the caller. */
export function pairing(family, kind, fg, bg, source) {
  if (!(kind in THRESHOLDS)) throw new Error(`pairing(): unknown kind "${kind}"`);
  return { id: `${family}.${short(fg)}.on.${short(bg)}`, family, fg, bg, min: THRESHOLDS[kind], kind, source };
}

/** The token path of a Drupal --ln-color-* custom property by its name without the prefix (`text`, `on-primary`). */
export const ln = (name) => `targets.drupal-css.--ln-color-${name}`;

/** Token paths for the scheme fields (socialblue target), by field name. */
export const scheme = (field) => `targets.socialblue.${field}`;

/** A selector list with its whitespace collapsed and one ", " between members. */
export const normSelector = (sel) => sel.replace(/\s+/g, ' ').split(',').map((x) => x.trim()).join(', ');
