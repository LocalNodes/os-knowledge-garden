# Roadmap: Open Social AI Knowledge Gardens

## Milestones

- ✅ **v1.0 AI Knowledge Gardens** — Phases 1-11 (shipped 2026-03-02)
- 🚧 **v2.0 LocalNodes-as-a-Service** — Phases 12-17 (in progress)

## Phases

<details>
<summary>✅ v1.0 AI Knowledge Gardens (Phases 1-11) — SHIPPED 2026-03-02</summary>

- [x] Phase 1: AI Infrastructure (3/3 plans) — completed 2026-02-24
- [x] Phase 2: Content Indexing (7/7 plans) — completed 2026-02-24
- [x] Phase 3: Permission-Aware Retrieval (3/3 plans) — completed 2026-02-25
- [x] Phase 4: Q&A & Search (3/3 plans) — completed 2026-02-26
- [x] Phase 5: User Interface (3/3 plans) — completed 2026-02-27
- [x] Phase 5.1: Split Related Content (1/1 plan) — completed 2026-02-27
- [x] Phase 6: Demo Content (6/6 plans) — completed 2026-02-27
- [x] Phase 7: Fix Integration Bugs (resolved) — completed 2026-02-27
- [x] Phase 8: Re-verify & Track (resolved) — completed 2026-02-27
- [x] Phase 9: Deploy to Coolify (3/3 plans) — completed 2026-03-01
- [x] Phase 10: Config Management (3/3 plans) — completed 2026-03-02

Full details: `.planning/milestones/v1.0-ROADMAP.md`

</details>

### v2.0 LocalNodes-as-a-Service

- [x] **Phase 12: Landing Page & Project Foundation** - Nuxt 4 app on Vercel replaces static landing page; value prop, pricing, CTA; email DNS warming (completed 2026-03-04)
- [x] **Phase 13: Onboarding Form & Validation** - Community name/email form with live subdomain preview and real-time validation (completed 2026-03-04)
- [ ] **Phase 14: Payment Integration** - Stripe Checkout redirect for subscription billing with webhook handler
- [ ] **Phase 15: Provisioning Pipeline** - Webhook-triggered GitHub Actions dispatch with user creation, Redis state tracking, and idempotency
- [ ] **Phase 16: Status & Notification** - Polling-based progress UI during ~4 min provisioning wait, plus welcome email with one-time login link
- [ ] **Phase 17: Error Handling & Hardening** - Failure display, retry without re-charging, automated refund on unrecoverable failure

## Phase Details

### Phase 12: Landing Page & Project Foundation
**Goal**: Community organizers visiting localnodes.xyz see a modern web app that clearly communicates what knowledge gardens are and how to get one
**Depends on**: Nothing (first phase of v2.0)
**Requirements**: LAND-01, LAND-02, LAND-03
**Success Criteria** (what must be TRUE):
  1. User visiting localnodes.xyz sees a value proposition explaining what bioregional knowledge gardens are and why they matter
  2. User sees a single pricing plan with clear monthly cost before entering the onboarding flow
  3. User can click a "Get Started" CTA that navigates to the onboarding form
  4. Resend email domain (DNS records for SPF/DKIM/DMARC) is configured and warming up
**Plans:** 2/2 plans complete

Plans:
- [x] 12-01-PLAN.md — Scaffold Nuxt 4 project and build complete landing page (hero, features, pricing, social proof, CTA)
- [x] 12-02-PLAN.md — Configure Resend email DNS records (SPF/DKIM/DMARC) in Cloudflare for domain warmup

### Phase 13: Onboarding Form & Validation
**Goal**: Community organizers can describe their community and see their future subdomain, with instant feedback on availability
**Depends on**: Phase 12
**Requirements**: ONBD-01, ONBD-02, ONBD-03, ONBD-04, ERR-03
**Success Criteria** (what must be TRUE):
  1. User can fill out a form with community name and email address
  2. User sees a live subdomain preview (e.g., `mycommunity.localnodes.xyz`) that updates as they type
  3. User sees real-time feedback confirming their chosen subdomain is available (or unavailable)
  4. Community name is automatically slugified into a valid subdomain (lowercase, hyphens, no special chars)
  5. User sees clear validation errors when entering an invalid or already-taken community name
**Plans:** 2/2 plans complete

Plans:
- [x] 13-01-PLAN.md — Install Valibot, configure Vitest, create slugify utility and check-subdomain server route with tests
- [x] 13-02-PLAN.md — Build onboarding form page with useSubdomain composable, SubdomainPreview component, and UForm validation

### Phase 14: Payment Integration
**Goal**: Community organizers pay for their knowledge garden via Stripe before any infrastructure is provisioned
**Depends on**: Phase 13
**Requirements**: PAY-01, PAY-02
**Success Criteria** (what must be TRUE):
  1. User is redirected to Stripe Checkout to complete a monthly subscription payment after submitting the onboarding form
  2. User receives a payment receipt email from Stripe after successful payment
  3. Stripe webhook handler receives and verifies `checkout.session.completed` events with signature validation
**Plans**: TBD

Plans:
- [ ] 14-01: TBD
- [ ] 14-02: TBD

### Phase 15: Provisioning Pipeline
**Goal**: Successful payment automatically triggers infrastructure provisioning that creates a working knowledge garden instance with the organizer's account
**Depends on**: Phase 14
**Requirements**: PROV-01, PROV-02, PROV-03, PROV-04
**Success Criteria** (what must be TRUE):
  1. Provisioning triggers automatically after Stripe webhook confirms successful payment (no manual steps)
  2. Admin user is created on the provisioned Drupal instance with the organizer's email address
  3. A unique, secure password is auto-generated and organizer receives a one-time login link to set their own password
  4. Retrying provisioning for the same payment session does not create duplicate instances or duplicate users
**Plans**: TBD

Plans:
- [ ] 15-01: TBD
- [ ] 15-02: TBD

### Phase 16: Status & Notification
**Goal**: Community organizers stay informed during the ~4 minute provisioning wait and receive everything they need to start using their garden
**Depends on**: Phase 15
**Requirements**: STAT-01, STAT-02, STAT-03, STAT-04, NOTIF-01, NOTIF-02
**Success Criteria** (what must be TRUE):
  1. User sees a multi-step progress indicator with named stages (e.g., "Building image", "Installing Drupal", "Creating your account")
  2. User sees an animated "garden growing" visualization that makes the ~4 minute wait feel intentional
  3. User sees estimated time remaining that counts down during provisioning
  4. User sees a success page with their site URL and a "Visit Your Garden" button
  5. User receives a welcome email containing their site URL, one-time login link, and getting-started steps
**Plans**: TBD

Plans:
- [ ] 16-01: TBD
- [ ] 16-02: TBD

### Phase 17: Error Handling & Hardening
**Goal**: The system gracefully handles provisioning failures, protects organizers from losing money, and is ready for real-world use
**Depends on**: Phase 16
**Requirements**: ERR-01, ERR-02, ERR-04
**Success Criteria** (what must be TRUE):
  1. User sees a clear, human-readable error message if provisioning fails (not a generic 500 or blank screen)
  2. User can retry provisioning after a failure without being charged again
  3. User's payment is automatically refunded if provisioning cannot complete after all retry attempts
**Plans**: TBD

Plans:
- [ ] 17-01: TBD
- [ ] 17-02: TBD

## Progress

**Execution Order:**
Phases execute in numeric order: 12 -> 13 -> 14 -> 15 -> 16 -> 17

| Phase | Milestone | Plans Complete | Status | Completed |
|-------|-----------|----------------|--------|-----------|
| 1. AI Infrastructure | v1.0 | 3/3 | Complete | 2026-02-24 |
| 2. Content Indexing | v1.0 | 7/7 | Complete | 2026-02-24 |
| 3. Permission-Aware Retrieval | v1.0 | 3/3 | Complete | 2026-02-25 |
| 4. Q&A & Search | v1.0 | 3/3 | Complete | 2026-02-26 |
| 5. User Interface | v1.0 | 3/3 | Complete | 2026-02-27 |
| 5.1 Split Related Content | v1.0 | 1/1 | Complete | 2026-02-27 |
| 6. Demo Content | v1.0 | 6/6 | Complete | 2026-02-27 |
| 7. Fix Integration Bugs | v1.0 | -- | Resolved | 2026-02-27 |
| 8. Re-verify & Track | v1.0 | -- | Resolved | 2026-02-27 |
| 9. Deploy to Coolify | v1.0 | 3/3 | Complete | 2026-03-01 |
| 10. Config Management | v1.0 | 3/3 | Complete | 2026-03-02 |
| 12. Landing Page & Project Foundation | v2.0 | 2/2 | Complete | 2026-03-04 |
| 13. Onboarding Form & Validation | v2.0 | 2/2 | Complete | 2026-03-04 |
| 14. Payment Integration | v2.0 | 0/TBD | Not started | - |
| 15. Provisioning Pipeline | v2.0 | 0/TBD | Not started | - |
| 16. Status & Notification | v2.0 | 0/TBD | Not started | - |
| 17. Error Handling & Hardening | v2.0 | 0/TBD | Not started | - |

---
*Roadmap created: 2026-02-23*
*v1.0 shipped: 2026-03-02*
*v2.0 roadmap added: 2026-03-03*
