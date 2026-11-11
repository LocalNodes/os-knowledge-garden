# socialblue stock sources (Open Social 13.1.0)

Test fixtures for `scripts/tokens/derive_socialblue_pairings.mjs --check` (WR-02). Open Social is not installed in CI, so `--check`
derives the contrast pairings from these four files when the theme is absent. When Open Social is installed
--check also requires its copy to equal these files byte for byte.

- Version: Open Social 13.1.0 (drupal/social).
- Licence: GPL-2.0-or-later, the same as the rest of this repository.
- Copied unchanged from:
  - `html/profiles/contrib/social/themes/socialblue/color/color.inc` -> `color/color.inc`
  - `html/profiles/contrib/social/themes/socialblue/assets/css/brand.css` -> `assets/css/brand.css`
  - `html/profiles/contrib/social/themes/socialblue/assets/css/brand--sky.css` -> `assets/css/brand--sky.css`
  - `html/profiles/contrib/social/themes/socialblue/assets/css/tokens.css` -> `assets/css/tokens.css`

## Refreshing

When Open Social moves (Phase 22.2), refresh the fixtures in the same change: `node scripts/tokens/derive_socialblue_pairings.mjs --refresh-fixtures`
with the new Open Social installed. It rewrites socialblue-pairings.json, replaces this directory with
socialblue-<new version>/ and rewrites this file. Commit all three.
