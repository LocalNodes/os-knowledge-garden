---
phase: 16
slug: status-notification
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-03-04
---

# Phase 16 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Vitest 3.2.4 (already configured from Phase 13) |
| **Config file** | `vitest.config.ts` (exists at project root) |
| **Quick run command** | `npx vitest run tests/unit/use-provisioning-status.test.ts` |
| **Full suite command** | `npx vitest run` |
| **Estimated runtime** | ~3 seconds |

---

## Sampling Rate

- **After every task commit:** Run `npx vitest run tests/unit/use-provisioning-status.test.ts`
- **After every plan wave:** Run `npx vitest run`
- **Before `/gsd:verify-work`:** Full suite must be green
- **Max feedback latency:** 5 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 16-01-01 | 01 | 1 | STAT-01 | unit | `npx vitest run tests/unit/use-provisioning-status.test.ts` | No — W0 | ⬜ pending |
| 16-01-02 | 01 | 1 | STAT-03 | unit | `npx vitest run tests/unit/use-provisioning-status.test.ts` | No — W0 | ⬜ pending |
| 16-01-03 | 01 | 1 | STAT-04 | unit | `npx vitest run tests/unit/use-provisioning-status.test.ts` | No — W0 | ⬜ pending |
| 16-02-01 | 02 | 1 | STAT-01 | manual | Visual inspection — stage indicator renders | N/A | ⬜ pending |
| 16-02-02 | 02 | 1 | STAT-02 | manual | Visual inspection — garden animation plays | N/A | ⬜ pending |
| 16-02-03 | 02 | 1 | STAT-03 | manual | Visual inspection — countdown ticks | N/A | ⬜ pending |
| 16-02-04 | 02 | 1 | STAT-04 | manual | Visual inspection — success CTA renders | N/A | ⬜ pending |
| 16-02-05 | 02 | 2 | NOTIF-01 | manual | Trigger provisioning, verify email received | N/A | ⬜ pending |
| 16-02-06 | 02 | 2 | NOTIF-02 | manual | Read email, verify content sections | N/A | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/unit/use-provisioning-status.test.ts` — stubs for STAT-01 (stage mapping), STAT-03 (countdown), STAT-04 (completion detection)

*Existing Vitest infrastructure covers framework setup.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Garden animation renders and animates | STAT-02 | CSS/SVG visual output | Open /success with active session, observe animation |
| Stage indicator shows correct stage | STAT-01 | Component rendering | Open /success during provisioning, watch stages advance |
| Countdown timer ticks down | STAT-03 | Visual timing behavior | Open /success, verify countdown from ~4:00 |
| Success CTA shows correct URL | STAT-04 | E2E with real provisioning | Complete full provisioning, verify CTA href |
| Welcome email received | NOTIF-01 | External service (Resend) | Trigger provisioning, check inbox |
| Email has getting-started content | NOTIF-02 | Email HTML rendering | Read received email, verify sections |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 5s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
