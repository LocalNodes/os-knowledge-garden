---
phase: 15
slug: provisioning-pipeline
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-03-04
---

# Phase 15 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Vitest 3.2.x (already configured from Phase 13) |
| **Config file** | `vitest.config.ts` (exists) |
| **Quick run command** | `npx vitest run tests/unit/provision-trigger.test.ts` |
| **Full suite command** | `npx vitest run` |
| **Estimated runtime** | ~5 seconds |

---

## Sampling Rate

- **After every task commit:** Run `npx vitest run tests/unit/provision-trigger.test.ts`
- **After every plan wave:** Run `npx vitest run`
- **Before `/gsd:verify-work`:** Full suite must be green
- **Max feedback latency:** 5 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 15-01-01 | 01 | 1 | PROV-01 | unit | `npx vitest run tests/unit/provision-trigger.test.ts` | ❌ W0 | ⬜ pending |
| 15-01-02 | 01 | 1 | PROV-01 | unit | `npx vitest run tests/unit/provision-trigger.test.ts` | ❌ W0 | ⬜ pending |
| 15-01-03 | 01 | 1 | PROV-04 | unit | `npx vitest run tests/unit/idempotency.test.ts` | ❌ W0 | ⬜ pending |
| 15-02-01 | 02 | 1 | PROV-02 | manual-only | Manual: verify drush commands in workflow YAML | N/A | ⬜ pending |
| 15-02-02 | 02 | 1 | PROV-03 | manual-only | Manual: verify drush uli command in workflow YAML | N/A | ⬜ pending |
| 15-02-03 | 02 | 1 | PROV-03 | manual-only | Manual: trigger provisioning, verify email received | N/A | ⬜ pending |
| 15-02-04 | 02 | 1 | PROV-04 | manual-only | Manual: verify subdomain check in workflow YAML | N/A | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/unit/provision-trigger.test.ts` — stubs for PROV-01 (dispatch logic, Redis state management)
- [ ] `tests/unit/idempotency.test.ts` — stubs for PROV-04 (SETNX guard, duplicate rejection)
- [ ] `tests/unit/provision-status.test.ts` — stubs for status polling endpoint

*Existing infrastructure covers framework install (Vitest already configured).*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| User creation via drush commands | PROV-02 | Requires live Drupal instance via SSH | Trigger provisioning, SSH to server, verify user exists with `drush user:info` |
| Login URL generation via drush uli | PROV-03 | Requires live Drupal instance | Verify login URL in welcome email, test it opens password reset |
| Welcome email delivery | PROV-03 | Requires Resend API and real email | Trigger provisioning, check inbox for welcome email with login link |
| Subdomain availability re-check | PROV-04 | Requires Coolify API in workflow | Attempt duplicate provisioning, verify workflow rejects duplicate subdomain |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 5s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
