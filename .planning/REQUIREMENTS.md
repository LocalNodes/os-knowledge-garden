# Requirements: LocalNodes-as-a-Service

**Defined:** 2026-03-03
**Core Value:** Community organizers can self-provision their own bioregional knowledge garden without touching any infrastructure

## v2.0 Requirements

Requirements for the self-service onboarding frontend. Each maps to roadmap phases.

### Landing Page

- [x] **LAND-01**: User sees clear value proposition explaining what LocalNodes knowledge gardens are
- [x] **LAND-02**: User sees pricing information (single plan, single price) before starting onboarding
- [x] **LAND-03**: User can click "Get Started" CTA to begin the onboarding flow

### Onboarding Flow

- [x] **ONBD-01**: User can enter community name and email in a 2-field form
- [x] **ONBD-02**: User sees live subdomain preview (e.g., `mycommunity.localnodes.xyz`) as they type
- [x] **ONBD-03**: User sees real-time feedback that their chosen subdomain is available
- [x] **ONBD-04**: Community name is automatically slugified into a valid subdomain

### Payment

- [x] **PAY-01**: User is redirected to Stripe Checkout for monthly subscription payment
- [x] **PAY-02**: User receives payment receipt email from Stripe after successful payment

### Provisioning

- [x] **PROV-01**: Provisioning triggers automatically after successful Stripe payment via webhook
- [ ] **PROV-02**: Admin user is created on the provisioned instance with the organizer's email
- [ ] **PROV-03**: Unique password is auto-generated and organizer receives a one-time login link to set their own password
- [x] **PROV-04**: Provisioning is idempotent — retrying does not create duplicate instances

### Status & Progress

- [ ] **STAT-01**: User sees multi-step progress indicator with named stages during provisioning
- [ ] **STAT-02**: User sees animated "garden growing" visualization during the ~4 minute wait
- [ ] **STAT-03**: User sees estimated time remaining (~4 minutes)
- [ ] **STAT-04**: User sees success page with site URL and "Visit Your Garden" CTA

### Notification

- [ ] **NOTIF-01**: User receives welcome email when their instance is ready
- [ ] **NOTIF-02**: Welcome email contains site URL, one-time login link, and getting-started steps

### Error Handling

- [ ] **ERR-01**: User sees clear error message if provisioning fails
- [ ] **ERR-02**: User can retry provisioning after failure without being charged again
- [x] **ERR-03**: User sees validation errors for invalid or unavailable community names
- [ ] **ERR-04**: System refunds payment if provisioning cannot complete after retries

## Future Requirements

Deferred to v2.1+. Tracked but not in current roadmap.

### Enhanced Onboarding

- **ONBD-05**: Guided community type quiz (2-3 questions) pre-configures instance
- **ONBD-06**: Conversational AI onboarding agent gathers detailed community info

### Enhanced Status

- **STAT-05**: Real-time SSE/WebSocket status updates (replace polling)

### Post-Provision

- **NOTIF-03**: Post-provision email drip sequence (welcome, first content, invite members, try AI)
- **MGMT-01**: Instance management dashboard (status, usage, billing)
- **MGMT-02**: Custom domain support (BYOD with TXT verification)

### Billing

- **PAY-03**: Multiple pricing tiers with feature differentiation
- **PAY-04**: Stripe Customer Portal for subscription management

## Out of Scope

Explicitly excluded. Documented to prevent scope creep.

| Feature | Reason |
|---------|--------|
| Free trial / freemium tier | Infrastructure cost per instance is real (~$5/mo+). Charge from day one. |
| OAuth/SSO sign-in | Email + one-time login link sufficient for community organizers. Adds dependency for marginal gain. |
| Multi-region deployment | Single Coolify server sufficient. Scale when demand requires it. |
| Team/org billing at signup | One organizer account per instance. Members invited via Open Social natively. |
| In-app payment forms (Stripe Elements) | Stripe Checkout handles PCI, mobile, 3D Secure. Zero benefit to custom forms. |
| User-selectable templates | One default demo content set. Reduces decision paralysis. |
| Treasury/Web3 debugging | Separate milestone (v3.0). Safe SDK deployment errors need dedicated attention. |

## Traceability

Which phases cover which requirements. Updated during roadmap creation.

| Requirement | Phase | Status |
|-------------|-------|--------|
| LAND-01 | Phase 12 | Complete |
| LAND-02 | Phase 12 | Complete |
| LAND-03 | Phase 12 | Complete |
| ONBD-01 | Phase 13 | Complete |
| ONBD-02 | Phase 13 | Complete |
| ONBD-03 | Phase 13 | Complete |
| ONBD-04 | Phase 13 | Complete |
| PAY-01 | Phase 14 | Complete |
| PAY-02 | Phase 14 | Complete |
| PROV-01 | Phase 15 | Complete |
| PROV-02 | Phase 15 | Pending |
| PROV-03 | Phase 15 | Pending |
| PROV-04 | Phase 15 | Complete |
| STAT-01 | Phase 16 | Pending |
| STAT-02 | Phase 16 | Pending |
| STAT-03 | Phase 16 | Pending |
| STAT-04 | Phase 16 | Pending |
| NOTIF-01 | Phase 16 | Pending |
| NOTIF-02 | Phase 16 | Pending |
| ERR-01 | Phase 17 | Pending |
| ERR-02 | Phase 17 | Pending |
| ERR-03 | Phase 13 | Complete |
| ERR-04 | Phase 17 | Pending |

**Coverage:**
- v2.0 requirements: 23 total
- Mapped to phases: 23
- Unmapped: 0

---
*Requirements defined: 2026-03-03*
*Last updated: 2026-03-03 after roadmap creation*
