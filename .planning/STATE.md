---
gsd_state_version: 1.0
milestone: v2.0
milestone_name: LocalNodes-as-a-Service
status: executing
stopped_at: Completed 15-01-PLAN.md
last_updated: "2026-03-04T10:05:00Z"
last_activity: 2026-03-04 — Completed 15-01 (provisioning pipeline server-side infrastructure)
progress:
  total_phases: 6
  completed_phases: 3
  total_plans: 8
  completed_plans: 7
  percent: 88
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-03-02)

**Core value:** Self-service onboarding where community organizers can provision their own bioregional knowledge garden without touching infrastructure
**Current focus:** Phase 15 — Provisioning Pipeline (Plan 01 complete, Plan 02 next)

## Current Position

Phase: 15 of 17 (Provisioning Pipeline)
Plan: 1 of 2 (15-01 complete)
Status: Plan 02 code complete, E2E checkpoint in progress — moving user creation from SSH to entrypoint (SSH blocked from GH Actions)
Last activity: 2026-03-04 — Debugging provisioning E2E, discovered SSH blocked from GH runners

Progress: [████████░░] 88%

## Performance Metrics

**Velocity:**
- Total plans completed: 7 (v2.0)
- Average duration: ~10min
- Total execution time: ~71min

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 12 | 2 | ~27min | ~14min |
| 13 | 2 | ~34min | ~17min |
| 14 | 2 | ~5min | ~3min |
| 15 | 1/2 | ~5min | ~5min |

*Updated after each plan completion*

## Accumulated Context

### Decisions

- Nuxt 4 on Vercel with Nitro server routes (BFF pattern) — no separate backend
- Stripe Checkout (hosted redirect), webhook-first provisioning — payment before infra
- Upstash Redis as state bridge between Stripe webhooks, GitHub Actions, frontend polling
- Resend for transactional email — DNS (SPF/DKIM/DMARC) configured in Phase 12, DMARC p=none for warmup
- Polling (not SSE) for status updates during provisioning wait
- GitHub Actions `return_run_details` (Feb 2026) for reliable run ID tracking
- Nuxt 4 project in sibling directory localnodes-onboarding/ (separate git repo from Drupal codebase)
- Marketing copy in content/index.yml for easy iteration without touching components
- CSS-only geometric art in hero (no photos per locked decision)
- Valibot for both client-side form and server-side query validation (1.4 kB vs Zod's 17.7 kB)
- Pure function unit tests for server route logic instead of full integration tests
- 13 reserved subdomains covering infrastructure and common admin paths
- Password field removed from onboarding form — 2 fields (community name + email). Password auto-generated server-side with one-time login link (Phase 15).
- Drupal username format: "[Community Name] Admin" (e.g., "Cascadia Admin") — email set separately via --mail flag
- Valibot schema extracted to utils/ for independent testability without component mounting
- autofocus on Community Name input to control initial focus
- [Phase quick]: Self-Host card positioned left with neutral styling; Managed card right with highlight as recommended option
- Server-side Stripe SDK only (no @stripe/stripe-js) -- Checkout redirect mode handles all payment UI on Stripe's domain
- Stripe metadata on both Checkout Session and subscription_data for webhook access in both checkout.session.completed and recurring events
- useStripe() singleton pattern in server/utils/ with Nitro auto-import
- readRawBody (not readBody) for webhook signature verification -- parsed JSON destroys Stripe signature
- Pure function testing pattern for webhook logic: validateWebhookHeaders, parseWebhookEvent, handleCheckoutCompleted
- Stripe Dashboard receipt emails enabled for both successful payments and refunds (PAY-02 verified)
- triggerProvisioning extracted to server/utils/provisioning.ts with dependency injection (redis + dispatchFn) for testability
- provision-handlers.ts holds pure handler functions for status and callback endpoints
- Redis SETNX with 1-hour TTL for idempotency, 24-hour TTL for provisioning state hash
- Provisioning state machine: triggered -> provisioning -> installing -> creating_user -> sending_email -> complete/failed

### Blockers/Concerns

None yet.

### Quick Tasks Completed

| # | Description | Date | Commit | Directory |
|---|-------------|------|--------|-----------|
| 1 | Add self-host card alongside managed pricing card on landing page | 2026-03-04 | e0739db | [1-add-self-host-card-alongside-managed-pri](./quick/1-add-self-host-card-alongside-managed-pri/) |

## Session Continuity

Last session: 2026-03-04T12:45:00Z
Stopped at: Plan 15-02 Task 2 (E2E checkpoint) — moving user creation from SSH to entrypoint
Resume file: .planning/phases/15-provisioning-pipeline/.continue-here.md

---
*State initialized: 2026-02-23*
*Last updated: 2026-03-04*
