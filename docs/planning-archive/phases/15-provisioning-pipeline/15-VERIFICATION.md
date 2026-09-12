---
phase: 15-provisioning-pipeline
verified: 2026-03-04T14:05:15Z
status: passed
score: 9/9 must-haves verified
gaps: []
human_verification:
  - test: "Complete Stripe payment triggers GitHub Actions workflow automatically"
    expected: "Workflow runs and dispatches provisioning within Stripe's 20s webhook timeout"
    why_human: "Requires live Stripe test credentials and watching GitHub Actions in real-time"
  - test: "End-to-end: admin user exists on provisioned Drupal instance with organizer email and sitemanager role"
    expected: "After ~4 min, organizer can log in at their subdomain with the one-time link"
    why_human: "Requires a live Coolify provisioning run — already verified by human checkpoint in plan 02"
  - test: "Welcome email delivered to organizer with site URL and login link"
    expected: "Email arrives from hello@localnodes.xyz with clickable one-time login link"
    why_human: "Requires real Resend API key and outbound email delivery — verified during E2E in plan 02"
---

# Phase 15: Provisioning Pipeline Verification Report

**Phase Goal:** Webhook-triggered GitHub Actions dispatch with user creation, Redis state tracking, and idempotency
**Verified:** 2026-03-04T14:05:15Z
**Status:** PASSED
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

Plan 01 must-haves (PROV-01, PROV-04):

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Stripe checkout.session.completed webhook triggers GitHub Actions provisioning workflow automatically | VERIFIED | `stripe-webhook.post.ts` calls `triggerProvisioning` which calls `dispatchProvisioningWorkflow` via GitHub API |
| 2 | Duplicate webhook deliveries for the same Stripe session do not trigger duplicate provisioning | VERIFIED | `provisioning.ts`: Redis SETNX with 1-hour TTL (`set(lockKey, 'locked', { nx: true, ex: 3600 })`); returns `{ alreadyProcessing: true }` on collision |
| 3 | Frontend can poll provisioning status by session_id and receive structured state | VERIFIED | `provision-status.get.ts` + `provision-handlers.ts`: returns `{ status, siteUrl, loginUrl, error, startedAt }` or `{ status: 'unknown' }` |
| 4 | GitHub Actions workflow can POST status updates back to the Nitro server | VERIFIED | `provision-callback.post.ts` + `provision-handlers.ts`: validates Bearer token, Valibot schema, writes to Redis with 24h TTL |

Plan 02 must-haves (PROV-02, PROV-03):

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 5 | After provisioning completes, an admin user exists on the Drupal instance with the organizer's email | VERIFIED | `entrypoint.sh` lines 159-160: `drush user:create "$ORGANIZER_USERNAME" --mail="$ORGANIZER_EMAIL"` + `drush user:role:add sitemanager` triggered when `ORGANIZER_EMAIL` env var set |
| 6 | The admin user has the sitemanager role | VERIFIED | `entrypoint.sh` line 160: `$DRUSH user:role:add sitemanager "$ORGANIZER_USERNAME"` |
| 7 | A one-time login URL is generated for the organizer to set their own password | VERIFIED | `entrypoint.sh` line 163: `LOGIN_URL=$($DRUSH uli --name="$ORGANIZER_USERNAME" 2>&1)` |
| 8 | A welcome email is sent to the organizer with site URL and login link | VERIFIED | `entrypoint.sh` lines 168-183: curl POST to `https://api.resend.com/emails` with HTML body containing site URL and login link; guarded by `RESEND_API_KEY` check |
| 9 | GitHub Actions workflow posts status callbacks at each provisioning stage | VERIFIED | Workflow posts to `/api/provision-callback` at `provisioning` (line 224), `installing` (line 256), `failed` (line 306) stages; entrypoint posts `creating_user`, `sending_email`, `complete` stages |

**Score:** 9/9 truths verified

---

## Required Artifacts

### Plan 01 Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `localnodes-onboarding/server/utils/redis.ts` | Upstash Redis singleton + ProvisioningState type + get/set helpers | VERIFIED | Exports `useRedis`, `ProvisioningState`, `setProvisioningState`, `getProvisioningState`; 55 lines, substantive |
| `localnodes-onboarding/server/utils/github.ts` | GitHub API helper for workflow dispatch with return_run_details | VERIFIED | Exports `dispatchProvisioningWorkflow`; POSTs to GitHub API with `return_run_details: true`; 44 lines |
| `localnodes-onboarding/server/utils/provisioning.ts` | `triggerProvisioning` with SETNX idempotency (created as deviation from plan — extracted to separate util) | VERIFIED | Exports `triggerProvisioning` with full SETNX lock + state machine; 87 lines |
| `localnodes-onboarding/server/utils/provision-handlers.ts` | Pure handler functions for status and callback (created as deviation from plan) | VERIFIED | Exports `handleProvisionStatus` and `handleProvisionCallback`; Valibot validation, Bearer auth check; 92 lines |
| `localnodes-onboarding/server/api/stripe-webhook.post.ts` | Modified webhook handler calling triggerProvisioning | VERIFIED | Imports and calls `triggerProvisioning` in `checkout.session.completed` handler |
| `localnodes-onboarding/server/api/provision-status.get.ts` | GET endpoint returning provisioning state from Redis | VERIFIED | Delegates to `handleProvisionStatus` via `provision-handlers.ts` |
| `localnodes-onboarding/server/api/provision-callback.post.ts` | POST endpoint for GitHub Actions state updates | VERIFIED | Delegates to `handleProvisionCallback` via `provision-handlers.ts` |
| `localnodes-onboarding/nuxt.config.ts` | Added upstashRedis, github, provisionCallback runtimeConfig keys | VERIFIED | All 5 keys present: `upstashRedisUrl`, `upstashRedisToken`, `githubToken`, `githubRepo`, `provisionCallbackSecret` |
| `localnodes-onboarding/tests/unit/provision-trigger.test.ts` | 5 tests for trigger logic | VERIFIED | 5 tests, all passing |
| `localnodes-onboarding/tests/unit/idempotency.test.ts` | 4 tests for SETNX guard | VERIFIED | 4 tests, all passing |
| `localnodes-onboarding/tests/unit/provision-status.test.ts` | 9 tests for status polling and callback | VERIFIED | 9 tests, all passing |

### Plan 02 Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `.github/workflows/provision-instance.yml` | Extended with email/stripe_session_id/callback_url inputs, user creation, welcome email, status callbacks | VERIFIED | All 3 new inputs present (lines 24-35); Coolify env var injection for organizer details; 3 callback steps; failure callback |
| `docker/entrypoint.sh` | Post-install user provisioning block | VERIFIED | Lines 136-188: ORGANIZER_EMAIL guard, `user:create`, `user:role:add sitemanager`, `drush uli`, Resend curl, 3 callback calls |
| `config/sync/symfony_mailer.mailer_transport.smtp.yml` | Resend SMTP transport config | VERIFIED | Created: smtp.resend.com:465, TLS, `user: resend`, `pass: placeholder` (overridden at runtime) |
| `config/sync/symfony_mailer.settings.yml` | Default transport set to smtp | VERIFIED | `default_transport: smtp` |
| `html/sites/default/settings.php` | RESEND_API_KEY config override | VERIFIED | Lines 73-74: `getenv('RESEND_API_KEY')` injected into symfony_mailer transport password |
| `config/sync/symfony_mailer.mailer_transport.sendmail.yml` | Removed (replaced by SMTP) | VERIFIED | File confirmed absent |

---

## Key Link Verification

### Plan 01 Key Links

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `stripe-webhook.post.ts` | `server/utils/redis.ts` | `triggerProvisioning` calls Redis SETNX | WIRED | `useRedis()` called at line 37; passed as dep to `triggerProvisioning` |
| `stripe-webhook.post.ts` | `server/utils/github.ts` | `dispatchProvisioningWorkflow` | WIRED | Imported at line 3; passed as dep to `triggerProvisioning` at line 44 |
| `provision-status.get.ts` | `server/utils/redis.ts` | reads Redis hash | WIRED | Via `provision-handlers.ts`; `redis.hgetall(`provision:${sessionId}`)` at line 45 of handlers |
| `provision-callback.post.ts` | `server/utils/redis.ts` | `setProvisioningState` writes Redis hash | WIRED | Via `provision-handlers.ts`; `redis.hset` + `redis.expire` at lines 81-88 of handlers |

Note: Plan 01 specified `getProvisioningState` helper as the intermediary for the status route; implementation uses `provision-handlers.ts` with direct `redis.hgetall` call instead. This is functionally equivalent and more testable (dependency injection pattern).

### Plan 02 Key Links

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `.github/workflows/provision-instance.yml` | `provision-callback.post.ts` | curl POST to `/api/provision-callback` | WIRED | 3 explicit curl calls in workflow (lines 224, 256, 306); Bearer token from `PROVISION_CALLBACK_SECRET` secret |
| `.github/workflows/provision-instance.yml` | Coolify server | Coolify API env var injection (not SSH) | WIRED (architectural deviation) | Organizer details injected as Coolify env vars (`ORGANIZER_EMAIL`, `ORGANIZER_NAME`, etc.) at lines 185-195; entrypoint handles user creation |
| `.github/workflows/provision-instance.yml` | Resend API | Entrypoint curl POST (not direct from workflow) | WIRED (architectural deviation) | `entrypoint.sh` line 169: curl to `https://api.resend.com/emails` with `RESEND_API_KEY` env var; additional callbacks from entrypoint at `creating_user`, `sending_email`, `complete` stages |

**Key architectural deviation (documented in 15-02-SUMMARY.md):** Plan 02 specified SSH + `docker exec` for user creation and direct Resend curl from workflow. SSH port 22 was blocked from GitHub runners. The implemented approach (Coolify env var injection → entrypoint handles user creation and email) is architecturally superior — user creation happens atomically within the container lifecycle rather than over a fragile SSH tunnel.

---

## Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|------------|-------------|--------|---------|
| PROV-01 | 15-01-PLAN.md | Provisioning triggers automatically after successful Stripe payment via webhook | SATISFIED | `stripe-webhook.post.ts` calls `triggerProvisioning` → `dispatchProvisioningWorkflow` on `checkout.session.completed` |
| PROV-02 | 15-02-PLAN.md | Admin user created on provisioned instance with organizer's email | SATISFIED | `entrypoint.sh` `drush user:create ... --mail="$ORGANIZER_EMAIL"` executed when `ORGANIZER_EMAIL` set |
| PROV-03 | 15-02-PLAN.md | Unique password auto-generated, organizer receives one-time login link | SATISFIED | `entrypoint.sh`: `openssl rand -hex 16` for password, `drush uli` for login URL, sent via Resend email |
| PROV-04 | 15-01-PLAN.md | Provisioning is idempotent — retrying does not create duplicate instances | SATISFIED | Redis SETNX (`nx: true, ex: 3600`) in `provisioning.ts`; entrypoint user creation uses `|| echo "Warning: user may already exist"` for idempotency at Drupal level too |

All 4 requirements for Phase 15 are satisfied. REQUIREMENTS.md Traceability table confirms all marked Complete.

---

## Anti-Patterns Found

No anti-patterns detected. All files verified against stub detection patterns:

- No TODO/FIXME/PLACEHOLDER comments in implementation files
- No empty handlers (`return null`, `return {}`)
- No stub API routes (all routes delegate to substantive logic)
- No unimplemented webhook stubs (Phase 14 placeholder comment fully replaced)
- Webhook handler returns `{ received: true }` fast — correct for Stripe's 20s timeout

---

## Test Suite Results

```
Tests: 80 passed (80)
Files: 9 passed (9)
Duration: 762ms
```

Breakdown for Phase 15 new tests:
- `provision-trigger.test.ts`: 5/5 passed
- `idempotency.test.ts`: 4/4 passed
- `provision-status.test.ts`: 9/9 passed (5 status + 4 callback)

Zero regressions against prior 62 tests.

---

## Human Verification Required

The following were verified by human checkpoint (Plan 02, Task 2 — checkpoint approved):

### 1. End-to-End Payment to Provisioning

**Test:** Complete Stripe Checkout with test card 4242 4242 4242 4242 at https://localnodes.xyz/onboarding
**Expected:** GitHub Actions provisioning workflow triggers automatically within 20 seconds
**Why human:** Requires live Stripe credentials and real-time GitHub Actions observation. Verified during E2E testing in Plan 02.

### 2. Admin User Creation and Role Assignment

**Test:** After ~4 minute provisioning run, SSH to Coolify server and check `drush user:list`
**Expected:** User "[Site Name] Admin" exists with organizer email, sitemanager role assigned
**Why human:** Requires live Coolify instance. Verified during E2E testing in Plan 02.

### 3. Welcome Email with One-Time Login Link

**Test:** Check organizer inbox after provisioning completes
**Expected:** Email from hello@localnodes.xyz with site URL and clickable one-time login link; link redirects to Drupal password reset
**Why human:** Requires real Resend API key and email delivery. Verified during E2E testing in Plan 02 (checkpoint approved).

### 4. Idempotency Under Duplicate Webhook

**Test:** Resend the same Stripe webhook event from Stripe Dashboard
**Expected:** No duplicate GitHub Actions workflow dispatched; Redis log shows `alreadyProcessing: true`
**Why human:** Requires Stripe Dashboard access and GitHub Actions monitoring. Covered by automated test suite (idempotency.test.ts) for the logic layer.

---

## Gaps Summary

No gaps. All 9 must-have truths verified, all 16 artifacts confirmed substantive and wired, all 4 requirements satisfied. The notable architectural deviation in Plan 02 (SSH → entrypoint for user creation) was correctly auto-resolved during E2E testing and results in a more robust implementation. Human checkpoint was approved confirming end-to-end pipeline worked.

---

_Verified: 2026-03-04T14:05:15Z_
_Verifier: Claude (gsd-verifier)_
