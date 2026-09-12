---
phase: 13-onboarding-form-validation
plan: 01
subsystem: api
tags: [valibot, vitest, slugify, dns-labels, coolify-api, nitro-server-routes, bff]

# Dependency graph
requires:
  - phase: 12-landing-page-project-foundation
    provides: "Nuxt 4 project scaffold with @nuxt/ui, @nuxt/content, and dark theme"
provides:
  - "Valibot validation library installed for schema-based form validation"
  - "Vitest test infrastructure configured with path aliases"
  - "slugify utility for DNS-safe subdomain label generation"
  - "GET /api/check-subdomain server route proxying to Coolify API"
  - "runtimeConfig with coolifyApiUrl and coolifyApiToken (server-only)"
  - "25 passing unit tests (9 slugify + 16 check-subdomain)"
affects: [13-02-onboarding-form-ui, 14-payment-integration, 15-provisioning]

# Tech tracking
tech-stack:
  added: [valibot-1.x, vitest-3.x, "@nuxt/test-utils"]
  patterns: [tdd-unit-tests, nitro-server-route-validation, bff-api-proxy, dns-label-slugification]

key-files:
  created:
    - localnodes-onboarding/vitest.config.ts
    - localnodes-onboarding/app/utils/slugify.ts
    - localnodes-onboarding/server/api/check-subdomain.get.ts
    - localnodes-onboarding/tests/unit/slugify.test.ts
    - localnodes-onboarding/tests/unit/check-subdomain.test.ts
    - localnodes-onboarding/.env.example
  modified:
    - localnodes-onboarding/package.json
    - localnodes-onboarding/nuxt.config.ts

key-decisions:
  - "Valibot for both client-side form and server-side query validation (1.4 kB vs Zod's 17.7 kB)"
  - "Pure function unit tests for server route logic (validation, reserved names, domain matching) instead of full integration tests requiring Nuxt dev server"
  - "13 reserved subdomains covering infrastructure and common admin paths"

patterns-established:
  - "TDD for pure utility functions: write tests first, implement, verify"
  - "Server route validation via Valibot schema + getValidatedQuery for type-safe 400 errors"
  - "BFF pattern: Coolify API token stays server-side via runtimeConfig, client calls /api/check-subdomain"
  - "docker_compose_domains handled as both string and object formats for Coolify API compatibility"

requirements-completed: [ONBD-04, ONBD-03, ERR-03]

# Metrics
duration: ~4min
completed: 2026-03-04
---

# Phase 13 Plan 01: Validation Foundation Summary

**Valibot + Vitest configured, slugify DNS utility with 9 edge-case tests, and Coolify-backed subdomain availability API with 16 unit tests**

## Performance

- **Duration:** ~4 min
- **Started:** 2026-03-04T06:03:14Z
- **Completed:** 2026-03-04T06:07:00Z
- **Tasks:** 2 (both TDD)
- **Files created:** 6
- **Files modified:** 2

## Accomplishments
- Installed Valibot (schema validation) and configured Vitest with path aliases for the Nuxt 4 project
- Created slugify utility that converts community names to valid DNS subdomain labels (lowercase, hyphens, max 63 chars) with 9 comprehensive edge-case tests
- Created GET /api/check-subdomain server route with Valibot query validation, 13 reserved subdomain names, and Coolify API proxy for domain availability
- Added runtimeConfig for server-only Coolify API credentials and /onboarding SSR route rule

## Task Commits

Each task was committed atomically (in the localnodes-onboarding repo):

1. **Task 1: Install dependencies, configure Vitest, and create slugify utility with tests** - `41a4181` (feat)
2. **Task 2: Create check-subdomain server route with runtimeConfig and tests** - `435b8b5` (feat)

_Both tasks followed TDD: RED (failing tests) -> GREEN (implementation passes) -> no refactor needed_

## Files Created/Modified
- `localnodes-onboarding/vitest.config.ts` - Vitest config with node environment and ~/app path alias
- `localnodes-onboarding/app/utils/slugify.ts` - Pure slugify function for DNS label generation
- `localnodes-onboarding/server/api/check-subdomain.get.ts` - Subdomain availability check via Coolify API
- `localnodes-onboarding/tests/unit/slugify.test.ts` - 9 unit tests for slugify edge cases
- `localnodes-onboarding/tests/unit/check-subdomain.test.ts` - 16 unit tests for validation, reserved names, domain matching
- `localnodes-onboarding/.env.example` - Documents NUXT_COOLIFY_API_TOKEN env var
- `localnodes-onboarding/package.json` - Added valibot, vitest, @nuxt/test-utils, test script
- `localnodes-onboarding/nuxt.config.ts` - Added runtimeConfig (coolifyApiUrl, coolifyApiToken) and /onboarding route rule

## Decisions Made
- Valibot chosen for both client and server validation (1.4 kB bundle, first-class Nuxt UI v4 support via Standard Schema)
- Unit tests focus on pure logic extraction (validation rules, reserved name check, domain matching) rather than requiring a running Nuxt dev server
- 13 reserved subdomains: www, api, coolify, mail, smtp, admin, app, dashboard, status, billing, support, help, docs

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required for development. The NUXT_COOLIFY_API_TOKEN env var is needed for production/staging subdomain availability checks.

## Next Phase Readiness
- slugify utility ready for import in useSubdomain composable (Plan 02)
- check-subdomain API ready for $fetch calls from the composable
- Vitest configured for additional component tests in Plan 02
- Valibot available for form schema validation in OnboardingForm component

## Self-Check: PASSED

- Commit 41a4181: FOUND
- Commit 435b8b5: FOUND
- All 7 key files: FOUND
- SUMMARY.md: FOUND

---
*Phase: 13-onboarding-form-validation*
*Completed: 2026-03-04*
