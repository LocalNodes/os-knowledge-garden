---
phase: 14
slug: payment-integration
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-03-04
---

# Phase 14 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Vitest 3.2.x (already configured) |
| **Config file** | `vitest.config.ts` (exists from Phase 13) |
| **Quick run command** | `npx vitest run tests/unit/create-checkout.test.ts` |
| **Full suite command** | `npx vitest run` |
| **Estimated runtime** | ~5 seconds |

---

## Sampling Rate

- **After every task commit:** Run `npx vitest run tests/unit/create-checkout.test.ts`
- **After every plan wave:** Run `npx vitest run`
- **Before `/gsd:verify-work`:** Full suite must be green
- **Max feedback latency:** 5 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 14-01-01 | 01 | 1 | PAY-01 | unit | `npx vitest run tests/unit/create-checkout.test.ts` | ❌ W0 | ⬜ pending |
| 14-01-02 | 01 | 1 | PAY-01 | unit | `npx vitest run tests/unit/onboarding-submit.test.ts` | ❌ W0 | ⬜ pending |
| 14-02-01 | 02 | 1 | PAY-01+02 | unit | `npx vitest run tests/unit/stripe-webhook.test.ts` | ❌ W0 | ⬜ pending |
| 14-02-02 | 02 | 1 | PAY-02 | manual-only | Manual: verify Stripe Dashboard receipt toggle | N/A | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/unit/create-checkout.test.ts` — stubs for PAY-01 (checkout session creation logic)
- [ ] `tests/unit/onboarding-submit.test.ts` — stubs for PAY-01 (form submit + redirect)
- [ ] `tests/unit/stripe-webhook.test.ts` — stubs for PAY-01+02 (webhook signature verification and event parsing)

*Vitest framework already installed from Phase 13.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Receipt emails sent after payment | PAY-02 | Stripe Dashboard configuration, not code | 1. Log into Stripe Dashboard 2. Navigate to Settings > Customer emails 3. Verify "Successful payments" toggle is ON 4. Make a test payment and confirm receipt email arrives |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 5s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
