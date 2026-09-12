# Research Summary: LocalNodes-as-a-Service (v2.0)

**Domain:** Self-service SaaS onboarding with Stripe billing and async infrastructure provisioning
**Researched:** 2026-03-02
**Overall confidence:** HIGH

## Executive Summary

The v2.0 milestone adds a self-service onboarding frontend to the existing LocalNodes AI Knowledge Garden platform. The core challenge is bridging a user-facing web application with an existing async provisioning pipeline (GitHub Actions -> Coolify -> Drupal) that takes ~4 minutes to complete. The architecture is fundamentally an **async orchestration problem**: accept payment, trigger infrastructure provisioning, communicate progress, and deliver credentials -- all while handling the many failure modes of distributed systems.

The recommended architecture uses a **full-stack framework on Vercel** (Nuxt 4 per STACK.md) with server routes acting as a thin backend-for-frontend (BFF). No separate backend server is needed. The server routes handle Stripe webhook verification, GitHub Actions workflow dispatch, status polling with caching, and email notification -- all within Vercel's serverless function constraints. **Upstash Redis** serves as the state bridge between async components (Stripe webhooks, GitHub Actions, frontend polling).

A critical Feb 2026 GitHub API update (`return_run_details` parameter on workflow_dispatch) eliminates the previously fragile workflow-to-run correlation problem, making GitHub Actions viable as a provisioning executor. User creation on provisioned Drupal instances is handled via SSH + docker exec + drush from the GitHub Actions workflow, since Coolify's execute API is broken for Docker Compose applications.

The biggest risks are: (1) payment-provisioning state desynchronization requiring a saga-pattern state machine, (2) the 4-minute provisioning wait requiring careful UX with email as the primary delivery mechanism, and (3) security exposure of infrastructure APIs to the public internet requiring payment as the primary gate.

## Key Findings

**Stack:** Nuxt 4 on Vercel with Nitro server routes, Stripe Checkout, Upstash Redis, Resend email, GitHub Actions as provisioning executor.

**Architecture:** Webhook-first provisioning triggered by Stripe `checkout.session.completed`, with client-side polling for status (not SSE/WebSocket), Redis as state bridge, and GitHub Actions callback for completion notification.

**Critical pitfall:** Payment-provisioning desynchronization -- if provisioning fails after payment, you need automated rollback (refund + Coolify app cleanup) or the customer has paid for nothing.

## Implications for Roadmap

Based on research, suggested phase structure:

1. **Project Setup & Accounts** - Foundation before any code
   - Addresses: Vercel project, Stripe account, Upstash Redis, Resend domain verification, GitHub secrets
   - Avoids: Pitfall 5 (email deliverability) by configuring SPF/DKIM/DMARC early for domain warmup

2. **Payment Flow** - The security boundary
   - Addresses: Onboarding form, subdomain validation, Stripe Checkout Session, webhook handler with idempotency
   - Avoids: Pitfall 2 (webhook SPOF), Pitfall 3 (public exposure), Pitfall 11 (signature verification)

3. **Provisioning Integration** - Connecting payment to infrastructure
   - Addresses: GitHub Actions workflow extensions (user creation, callback), webhook-to-dispatch bridge, Redis state management
   - Avoids: Pitfall 1 (saga gap), Pitfall 7 (GH Actions as backend), Pitfall 13 (hardcoded passwords)

4. **Status & Notification** - User-facing progress and delivery
   - Addresses: Status polling endpoint, progress UI component, email notification, completion callback
   - Avoids: Pitfall 4 (4-minute UX death zone), Pitfall 5 (email deliverability)

5. **Landing Page & Hardening** - Production readiness
   - Addresses: Replace static landing page, rate limiting, error handling, monitoring, DNS cutover
   - Avoids: Pitfall 8 (resource exhaustion via capacity gate), Pitfall 12 (no monitoring)

**Phase ordering rationale:**
- Payment flow must come before provisioning integration because payment is the security gate (Pitfall 3)
- Provisioning integration must come before status UI because the status endpoint depends on having a runId from the dispatch
- Email domain must be configured in Phase 1 so it warms up during development (Pitfall 5)
- Landing page is last because a simple form can be used for testing during development

**Research flags for phases:**
- Phase 2 (Payment Flow): Needs deeper research on Stripe subscription vs one-time payment model, pricing structure
- Phase 3 (Provisioning Integration): SSH key setup for GitHub Actions runner needs careful testing; container naming pattern for docker exec needs verification against actual Coolify deployment
- Phase 5 (Landing Page): Content strategy and design are not technical research -- they are product/marketing decisions

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | HIGH | Nuxt 4 stable since July 2025. All services (Stripe, Upstash, Resend) have official docs and Next.js/Nuxt integration guides verified. |
| Features | HIGH | Table stakes derived from competitor analysis (Vercel, Railway, Render). Anti-features clearly scoped in PROJECT.md. |
| Architecture | HIGH | Webhook-first pattern is Stripe's official recommendation. GitHub `return_run_details` confirmed Feb 2026. Polling math validates over SSE. Coolify execute API bug confirmed (issue #5387). |
| Pitfalls | HIGH | All critical pitfalls verified with authoritative sources (Stripe docs, Coolify issues, Azure architecture patterns). Hardcoded password is a verifiable fact in the existing codebase. |

## Gaps to Address

- **Stripe pricing model:** One-time payment vs subscription not fully decided. Subscription is recommended (SaaS standard) but adds complexity (renewal handling, failed payment grace periods, subscription cancellation -> instance teardown).
- **SSH key for GitHub Actions:** The exact SSH key setup for the runner to access `root@localnodes.xyz` needs testing. The Coolify server's SSH key may need to be added as a GitHub repository secret.
- **Container naming convention:** The `docker ps | grep $SUBDOMAIN | grep opensocial` pattern in the user creation step assumes Coolify names containers predictably. This needs validation against actual deployments.
- **Capacity planning:** How many instances can the current Coolify server support? This determines when to implement the capacity gate (Pitfall 8). Need to measure actual per-instance resource usage.
- **Nuxt 4 + Vercel KV vs Upstash:** STACK.md mentions Vercel KV; ARCHITECTURE.md recommends Upstash Redis directly. Vercel KV was sunset and replaced by Vercel Marketplace Redis (which is Upstash underneath). The specific Nitro storage driver for Upstash needs verification.
- **Conversational onboarding stub:** PROJECT.md mentions stubbing extensibility for future conversational AI onboarding. The architecture should identify where this hook point is (likely a middleware layer between the form and the provision API).
