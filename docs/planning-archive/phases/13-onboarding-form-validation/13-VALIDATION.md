---
phase: 13
slug: onboarding-form-validation
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-03-03
---

# Phase 13 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Vitest (not yet configured) |
| **Config file** | none — Wave 0 installs |
| **Quick run command** | `npx vitest run tests/unit/slugify.test.ts` |
| **Full suite command** | `npx vitest run` |
| **Estimated runtime** | ~5 seconds |

---

## Sampling Rate

- **After every task commit:** Run `npx vitest run tests/unit/slugify.test.ts`
- **After every plan wave:** Run `npx vitest run`
- **Before `/gsd:verify-work`:** Full suite must be green
- **Max feedback latency:** 5 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 13-01-01 | 01 | 0 | ONBD-04 | unit | `npx vitest run tests/unit/slugify.test.ts` | No — W0 | ⬜ pending |
| 13-01-02 | 01 | 0 | ONBD-02, ONBD-03 | unit | `npx vitest run tests/unit/use-subdomain.test.ts` | No — W0 | ⬜ pending |
| 13-01-03 | 01 | 0 | ONBD-01, ERR-03 | unit | `npx vitest run tests/unit/onboarding-form.test.ts` | No — W0 | ⬜ pending |
| 13-02-01 | 02 | 1 | ONBD-04 | unit | `npx vitest run tests/unit/slugify.test.ts` | No — W0 | ⬜ pending |
| 13-02-02 | 02 | 1 | ONBD-02 | unit | `npx vitest run tests/unit/subdomain-preview.test.ts` | No — W0 | ⬜ pending |
| 13-02-03 | 02 | 1 | ONBD-03 | unit | `npx vitest run tests/unit/use-subdomain.test.ts` | No — W0 | ⬜ pending |
| 13-02-04 | 02 | 1 | ONBD-01, ERR-03 | unit | `npx vitest run tests/unit/onboarding-form.test.ts` | No — W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `vitest.config.ts` — Vitest configuration for Nuxt project
- [ ] `@nuxt/test-utils` — Testing utilities for Nuxt components
- [ ] `tests/unit/slugify.test.ts` — stubs for ONBD-04 (pure function)
- [ ] `tests/unit/use-subdomain.test.ts` — stubs for ONBD-02, ONBD-03 (composable logic)
- [ ] `tests/unit/onboarding-form.test.ts` — stubs for ONBD-01, ERR-03 (component rendering)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Live subdomain preview updates as user types | ONBD-02 | Visual/UX verification | Type community name, observe `.localnodes.xyz` suffix updates in real-time |
| Validation errors display clearly | ERR-03 | Visual styling check | Submit empty form, enter taken name, verify error messages appear correctly |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 5s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
