---
gsd_state_version: 1.0
milestone: v2.0
milestone_name: LocalNodes-as-a-Service
status: completed
stopped_at: Completed quick-1 (self-host pricing card)
last_updated: "2026-03-04T07:47:58.611Z"
last_activity: "2026-03-04 — Completed quick task 1: Add self-host card alongside managed pricing card on landing page"
progress:
  total_phases: 6
  completed_phases: 2
  total_plans: 6
  completed_plans: 5
  percent: 33
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-03-02)

**Core value:** Self-service onboarding where community organizers can provision their own bioregional knowledge garden without touching infrastructure
**Current focus:** Phase 14 — Payment Integration (in progress)

## Current Position

Phase: 14 of 17 (Payment Integration) -- IN PROGRESS
Plan: 1 of 2 complete
Status: 14-01 complete, 14-02 next (webhook handler + success/cancel pages)
Last activity: 2026-03-04 — Completed 14-01 Stripe Checkout integration

Progress: [████████░░] 83%

## Performance Metrics

**Velocity:**
- Total plans completed: 5 (v2.0)
- Average duration: ~13min
- Total execution time: ~64min

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 12 | 2 | ~27min | ~14min |
| 13 | 2 | ~34min | ~17min |
| 14 | 1/2 | ~3min | ~3min |

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
- Valibot schema extracted to utils/ for independent testability without component mounting
- autofocus on Community Name input to control initial focus
- [Phase quick]: Self-Host card positioned left with neutral styling; Managed card right with highlight as recommended option
- Server-side Stripe SDK only (no @stripe/stripe-js) -- Checkout redirect mode handles all payment UI on Stripe's domain
- Stripe metadata on both Checkout Session and subscription_data for webhook access in both checkout.session.completed and recurring events
- useStripe() singleton pattern in server/utils/ with Nitro auto-import

### Blockers/Concerns

None yet.

### Quick Tasks Completed

| # | Description | Date | Commit | Directory |
|---|-------------|------|--------|-----------|
| 1 | Add self-host card alongside managed pricing card on landing page | 2026-03-04 | e0739db | [1-add-self-host-card-alongside-managed-pri](./quick/1-add-self-host-card-alongside-managed-pri/) |

## Session Continuity

Last session: 2026-03-04T07:46:30Z
Stopped at: Completed 14-01-PLAN.md (Stripe Checkout integration)
Resume file: None

---
*State initialized: 2026-02-23*
*Last updated: 2026-03-04*
