/**
 * WCAG 2.2 contrast maths for scripts/tokens/check_contrast.mjs (22.1-05, DES-01, D-09).
 *
 * Relative luminance and contrast ratio exactly as the WCAG 2.2 glossary
 * defines them (https://www.w3.org/TR/WCAG22/#dfn-relative-luminance), no
 * dependencies. Colours are 6-digit hex; lib/tokens.mjs has already
 * normalised every token to that form.
 *
 * Nothing here rounds. The ratio is compared with its threshold as it comes
 * out of the formula, so 4.4999 is a fail (T-22.1-15). Formatting for display
 * is the caller's job and must never feed back into a comparison.
 */

const channel = (c) => {
  const s = c / 255;
  return s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
};

/** Relative luminance of a #rrggbb colour. */
export function luminance(hex) {
  if (typeof hex !== 'string' || !/^#[0-9a-fA-F]{6}$/.test(hex)) {
    throw new TypeError(`luminance(): "${hex}" is not a 6-digit hex colour`);
  }
  const [r, g, b] = [1, 3, 5].map((i) => channel(parseInt(hex.slice(i, i + 2), 16)));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** Contrast ratio of two colours, unrounded, 1 to 21. Order does not matter. */
export function contrast(a, b) {
  const x = luminance(a);
  const y = luminance(b);
  return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
}

/** True when the unrounded ratio reaches the minimum. */
export function meets(ratio, min) {
  return ratio >= min;
}
