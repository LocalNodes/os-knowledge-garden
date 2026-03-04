---
phase: quick
plan: 1
subsystem: ui
tags: [nuxt, vue, pricing, open-source, UPricingPlan]

# Dependency graph
requires:
  - phase: 12-landing-page
    provides: PricingSection.vue component and content/index.yml
provides:
  - Two-card pricing layout with self-host and managed options
  - Self-host content data in index.yml
affects: [landing-page, onboarding-flow]

# Tech tracking
tech-stack:
  added: []
  patterns: [two-card pricing comparison layout]

key-files:
  created: []
  modified:
    - ../localnodes-onboarding/app/components/PricingSection.vue
    - ../localnodes-onboarding/content/index.yml

key-decisions:
  - "Self-Host card positioned left (first) to communicate open-source first, Managed card right with highlight to mark as recommended"
  - "Button variant outline+neutral for self-host vs primary for managed to visually guide toward managed"

patterns-established:
  - "Two-tier pricing: free self-host alongside managed SaaS with visual emphasis on managed"

requirements-completed: [QUICK-1]

# Metrics
duration: 2min
completed: 2026-03-04
---

# Quick Task 1: Add Self-Host Card Alongside Managed Pricing Summary

**Two-card pricing layout: Self-Host (Free/forever, GitHub link) and Managed ($29/month, /onboarding CTA) with responsive grid**

## Performance

- **Duration:** 2 min
- **Started:** 2026-03-04T06:57:21Z
- **Completed:** 2026-03-04T06:59:35Z
- **Tasks:** 2
- **Files modified:** 2

## Accomplishments
- Added self-host pricing card with Free/forever pricing and GitHub repo link
- Maintained managed card with $29/month pricing, highlighted as recommended option
- Responsive layout: side by side on desktop (md:grid-cols-2), stacked on mobile
- Updated content/index.yml with self-host block and restructured managed features

## Task Commits

Each task was committed atomically:

1. **Task 1: Add self-host content to index.yml** - `16b120e` (feat)
2. **Task 2: Rewrite PricingSection.vue with two-card layout** - `226b38b` (feat)

## Files Created/Modified
- `content/index.yml` - Added selfHost block, renamed features to managedFeatures, added managedTitle, updated pricing headline
- `app/components/PricingSection.vue` - Two UPricingPlan cards in responsive grid, self-host (neutral/outline) and managed (primary/highlight)

## Decisions Made
- Self-Host card uses `variant="outline"` neutral button to visually distinguish from the primary managed CTA
- Managed card retains `highlight` prop for visual emphasis as the recommended option
- Values hardcoded in component (matching existing pattern) rather than reading from index.yml at runtime

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

Pre-existing typecheck error in OnboardingForm.vue (unrelated to this task's changes) - logged but not addressed per scope boundary rules.

## User Setup Required

None - no external service configuration required.

## Next Steps
- Deploy to Vercel to see live two-card layout
- Consider A/B testing conversion between self-host and managed CTAs

## Self-Check: PASSED

- [x] PricingSection.vue exists
- [x] content/index.yml exists
- [x] 1-SUMMARY.md exists
- [x] Commit 16b120e found
- [x] Commit 226b38b found
- [x] Nuxt build succeeds

---
*Quick Task: 1*
*Completed: 2026-03-04*
