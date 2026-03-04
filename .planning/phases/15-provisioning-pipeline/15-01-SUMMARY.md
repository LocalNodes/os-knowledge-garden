---
phase: 15-provisioning-pipeline
plan: 01
subsystem: api
tags: [upstash-redis, github-actions, provisioning, idempotency, stripe-webhook, nitro]

# Dependency graph
requires:
  - phase: 14-payment-integration
    provides: Stripe webhook handler with checkout.session.completed event processing
provides:
  - Upstash Redis singleton and provisioning state management (get/set with TTL)
  - GitHub Actions workflow dispatch with return_run_details for reliable run ID
  - triggerProvisioning function with Redis SETNX idempotency guard
  - Provision status polling endpoint (GET /api/provision-status)
  - Provision callback endpoint (POST /api/provision-callback) for GitHub Actions state updates
affects: [15-02-provisioning-pipeline, 16-status-notification]

# Tech tracking
tech-stack:
  added: [@upstash/redis]
  patterns: [redis-setnx-idempotency, pure-function-dependency-injection, provision-state-machine]

key-files:
  created:
    - localnodes-onboarding/server/utils/redis.ts
    - localnodes-onboarding/server/utils/github.ts
    - localnodes-onboarding/server/utils/provisioning.ts
    - localnodes-onboarding/server/utils/provision-handlers.ts
    - localnodes-onboarding/server/api/provision-status.get.ts
    - localnodes-onboarding/server/api/provision-callback.post.ts
    - localnodes-onboarding/tests/unit/provision-trigger.test.ts
    - localnodes-onboarding/tests/unit/idempotency.test.ts
    - localnodes-onboarding/tests/unit/provision-status.test.ts
  modified:
    - localnodes-onboarding/server/api/stripe-webhook.post.ts
    - localnodes-onboarding/nuxt.config.ts
    - localnodes-onboarding/.env.example

key-decisions:
  - "triggerProvisioning extracted to server/utils/provisioning.ts with dependency injection for testability"
  - "provision-handlers.ts holds pure handler functions for status and callback endpoints"
  - "Redis SETNX with 1-hour TTL for idempotency, 24-hour TTL for provisioning state hash"

patterns-established:
  - "Redis SETNX idempotency: atomic lock acquisition before async workflow dispatch"
  - "Dependency injection in provisioning functions: redis and dispatch function passed as deps parameter"
  - "Provisioning state machine: triggered -> provisioning -> installing -> creating_user -> sending_email -> complete/failed"

requirements-completed: [PROV-01, PROV-04]

# Metrics
duration: 5min
completed: 2026-03-04
---

# Phase 15 Plan 01: Provisioning Pipeline Summary

**Redis-backed provisioning trigger with SETNX idempotency, GitHub Actions dispatch via return_run_details, and status/callback API endpoints**

## Performance

- **Duration:** 5 min
- **Started:** 2026-03-04T09:59:50Z
- **Completed:** 2026-03-04T10:05:00Z
- **Tasks:** 2
- **Files modified:** 12

## Accomplishments
- Stripe checkout.session.completed webhook now triggers GitHub Actions provisioning workflow automatically
- Redis SETNX prevents duplicate provisioning for the same Stripe session (idempotent)
- Status endpoint returns structured provisioning state for frontend polling
- Callback endpoint accepts authenticated state updates from GitHub Actions
- 18 new tests (80 total), zero regressions

## Task Commits

Each task was committed atomically:

1. **Task 1: Redis/GitHub utils, triggerProvisioning function, and idempotency tests**
   - `e47a3bb` (test: add failing tests for triggerProvisioning and idempotency)
   - `b286e89` (feat: add Redis state tracking, GitHub dispatch, and provisioning trigger)
2. **Task 2: Provision status polling and callback endpoints with tests**
   - `1e59e55` (test: add failing tests for provision-status and provision-callback)
   - `0deaf15` (feat: add provision-status and provision-callback endpoints)

_TDD: Each task followed red-green cycle with separate test and implementation commits._

## Files Created/Modified
- `server/utils/redis.ts` - Upstash Redis singleton, ProvisioningState type, setProvisioningState/getProvisioningState helpers
- `server/utils/github.ts` - GitHub API workflow dispatch with return_run_details for reliable run ID
- `server/utils/provisioning.ts` - triggerProvisioning function with SETNX lock and dependency injection
- `server/utils/provision-handlers.ts` - Pure handler functions for status and callback endpoints
- `server/api/stripe-webhook.post.ts` - Modified to call triggerProvisioning on checkout.session.completed
- `server/api/provision-status.get.ts` - GET endpoint for frontend polling by session_id
- `server/api/provision-callback.post.ts` - POST endpoint for GitHub Actions status updates with Bearer auth
- `nuxt.config.ts` - Added upstashRedis, github, provisionCallback runtimeConfig keys
- `.env.example` - Documented all new env vars with source instructions
- `tests/unit/provision-trigger.test.ts` - 5 tests for trigger logic and Redis state management
- `tests/unit/idempotency.test.ts` - 4 tests for SETNX guard and duplicate rejection
- `tests/unit/provision-status.test.ts` - 9 tests for status polling and callback validation

## Decisions Made
- Extracted triggerProvisioning to dedicated server/utils/provisioning.ts with dependency injection (redis + dispatchFn as params) for clean testability without module mocking
- Created provision-handlers.ts for pure status/callback handler functions, matching the existing pure-function testing pattern from Phase 14
- Redis hash key format: `provision:{sessionId}` for state, `provision:{sessionId}:lock` for idempotency guard

## Deviations from Plan

None - plan executed exactly as written.

## User Setup Required

**External services require manual configuration:**
- **Upstash Redis:** Create database, set NUXT_UPSTASH_REDIS_URL and NUXT_UPSTASH_REDIS_TOKEN
- **GitHub PAT:** Create fine-grained PAT with actions:write + metadata:read, set NUXT_GITHUB_TOKEN
- **Callback Secret:** Generate with `openssl rand -hex 32`, set NUXT_PROVISION_CALLBACK_SECRET

See `.env.example` for full documentation of all required env vars.

## Next Phase Readiness
- Provisioning infrastructure complete, ready for Plan 02 (GitHub Actions workflow extensions)
- All 5 server files created (2 utils, 3 API routes) with full test coverage
- Webhook handler wired to triggerProvisioning, returns fast for Stripe's 20s timeout

## Self-Check: PASSED

All 9 created files verified present. All 4 commit hashes verified in git log.

---
*Phase: 15-provisioning-pipeline*
*Completed: 2026-03-04*
