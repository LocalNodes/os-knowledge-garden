---
phase: 15-provisioning-pipeline
plan: 02
subsystem: infra
tags: [github-actions, docker-entrypoint, drush, resend-smtp, coolify-api, provisioning, user-creation]

# Dependency graph
requires:
  - phase: 15-provisioning-pipeline-01
    provides: Redis state tracking, GitHub Actions dispatch, provisioning state machine, callback endpoint
provides:
  - Automated admin user creation during Drupal fresh install via Docker entrypoint
  - One-time login URL generation and welcome email via Resend SMTP
  - Status callbacks from entrypoint to Nitro server at each provisioning stage
  - Coolify env var injection for organizer details (ORGANIZER_EMAIL, ORGANIZER_NAME, RESEND_API_KEY)
  - Drupal outbound email via Resend SMTP (replaced broken sendmail transport)
affects: [16-status-notification, 17-error-handling]

# Tech tracking
tech-stack:
  added: [resend-smtp]
  patterns: [entrypoint-user-provisioning, coolify-env-var-injection, patch-then-post-fallback]

key-files:
  created:
    - config/sync/symfony_mailer.mailer_transport.smtp.yml
  modified:
    - .github/workflows/provision-instance.yml
    - docker/entrypoint.sh
    - html/sites/default/settings.php
    - config/sync/symfony_mailer.settings.yml

key-decisions:
  - "Moved user creation from GitHub Actions SSH to Docker entrypoint (SSH port 22 blocked from GitHub runners)"
  - "Organizer details passed as Coolify env vars rather than SSH arguments"
  - "Coolify API: PATCH for existing env vars, POST fallback for new ones (POST must NOT include is_build_time)"
  - "Drupal outbound email switched from sendmail to Resend SMTP (smtp.resend.com:465) with API key via settings.php config override"

patterns-established:
  - "Entrypoint user provisioning: detect ORGANIZER_EMAIL env var, create user, assign role, generate login URL, send callbacks"
  - "Coolify env var PATCH-then-POST: try PATCH first (updates existing), fall back to POST (creates new) without is_build_time field"
  - "Settings.php SMTP config override: inject Resend API key from RESEND_API_KEY env var into symfony_mailer transport config"

requirements-completed: [PROV-02, PROV-03]

# Metrics
duration: multi-session (E2E debugging across sessions)
completed: 2026-03-04
---

# Phase 15 Plan 02: Provisioning Pipeline Summary

**Entrypoint-based admin user creation with sitemanager role, one-time login URL, welcome email via Resend SMTP, and status callbacks to Nitro server**

## Performance

- **Duration:** Multi-session (iterative E2E debugging across sessions)
- **Started:** 2026-03-04 (previous session)
- **Completed:** 2026-03-04T13:58:54Z
- **Tasks:** 2 (1 auto + 1 checkpoint)
- **Files modified:** 7 (excluding .planning)

## Accomplishments
- Admin user with sitemanager role created automatically during Drupal fresh install
- One-time login URL generated via drush uli and sent in welcome email
- Welcome email delivered via Resend SMTP transport (replaced broken sendmail)
- Status callbacks posted to Nitro callback endpoint at each provisioning stage (creating_user, sending_email, complete)
- Coolify env var injection handles both new and existing vars (PATCH + POST fallback)
- Full E2E verified: payment -> webhook -> GitHub Actions -> Coolify deploy -> entrypoint user creation -> welcome email with login link

## Task Commits

Each task was committed atomically:

1. **Task 1: Extend provisioning workflow with user creation, welcome email, and callbacks**
   - `c727bff` (feat(15-02): add user creation, welcome email, and status callbacks to provisioning workflow)
   - `3906d02` (fix: make provisioning user creation step more robust)
   - `de4374e` (refactor(15-02): move user creation from SSH to Docker entrypoint)
   - `6ed8166` (fix: configure Resend SMTP for Drupal outbound email)
   - `bece6db` (fix: use POST fallback for new Coolify env vars)
   - `9d334a1` (fix: remove is_build_time from env var POST (causes 422))
2. **Task 2: Verify end-to-end provisioning pipeline** - Checkpoint approved by user

## Files Created/Modified
- `.github/workflows/provision-instance.yml` - Extended with email/stripe_session_id/callback_url inputs, Coolify env var injection for organizer details, status callbacks at each stage
- `docker/entrypoint.sh` - Added post-install user provisioning: create user, assign sitemanager role, generate login URL, send status callbacks, trigger welcome email
- `config/sync/symfony_mailer.mailer_transport.smtp.yml` - New Resend SMTP transport config (smtp.resend.com:465, TLS)
- `config/sync/symfony_mailer.settings.yml` - Updated default transport from sendmail to smtp
- `html/sites/default/settings.php` - Added RESEND_API_KEY config override for SMTP transport password
- `config/sync/symfony_mailer.mailer_transport.sendmail.yml` - Removed (replaced by SMTP)

## Decisions Made
- **Moved user creation from SSH to Docker entrypoint:** GitHub Actions runners cannot reach Coolify server on port 22 (firewall blocks non-whitelisted IPs). Solution: pass organizer details as Coolify env vars, let the entrypoint handle user creation during fresh install.
- **Coolify env var API pattern (PATCH then POST):** PATCH endpoint only updates existing env vars. For new vars (ORGANIZER_EMAIL, etc.), must use POST. POST body must NOT include `is_build_time` field (causes 422).
- **Drupal email via Resend SMTP:** Sendmail transport was broken in the Docker container (no local MTA). Switched to Resend SMTP at smtp.resend.com:465 with API key injected via settings.php `$config` override from env var.
- **Drupal username format:** "[Site Name] Admin" (e.g., "Cascadia Admin") with organizer email set separately via --mail flag.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] SSH port 22 blocked from GitHub Actions runners**
- **Found during:** Task 1 (E2E testing)
- **Issue:** GitHub Actions runners cannot SSH to Coolify server (port 22 blocked by firewall for non-whitelisted IPs)
- **Fix:** Moved user creation from GitHub Actions SSH steps to Docker entrypoint. Organizer details passed as Coolify env vars instead.
- **Files modified:** docker/entrypoint.sh, .github/workflows/provision-instance.yml
- **Committed in:** de4374e

**2. [Rule 1 - Bug] Coolify env var creation fails with PATCH for new vars**
- **Found during:** Task 1 (E2E testing)
- **Issue:** PATCH /applications/{uuid}/envs only updates existing env vars. New organizer env vars (ORGANIZER_EMAIL, etc.) need to be created first.
- **Fix:** Workflow now tries PATCH first, falls back to POST for new vars.
- **Files modified:** .github/workflows/provision-instance.yml
- **Committed in:** bece6db

**3. [Rule 1 - Bug] Coolify POST env var rejects is_build_time field**
- **Found during:** Task 1 (E2E testing)
- **Issue:** POST /applications/{uuid}/envs returns 422 when body includes `is_build_time` field
- **Fix:** Removed `is_build_time` from POST request body (only include key, value, is_preview)
- **Files modified:** .github/workflows/provision-instance.yml
- **Committed in:** 9d334a1

**4. [Rule 1 - Bug] Drupal sendmail transport broken in Docker container**
- **Found during:** Task 1 (E2E testing)
- **Issue:** Default sendmail transport fails (no local MTA in container). Welcome email never delivered.
- **Fix:** Switched symfony_mailer from sendmail to Resend SMTP transport (smtp.resend.com:465). API key injected via settings.php from RESEND_API_KEY env var.
- **Files modified:** config/sync/symfony_mailer.mailer_transport.smtp.yml (created), config/sync/symfony_mailer.settings.yml, html/sites/default/settings.php, config/sync/symfony_mailer.mailer_transport.sendmail.yml (removed)
- **Committed in:** 6ed8166

---

**Total deviations:** 4 auto-fixed (1 blocking, 3 bugs)
**Impact on plan:** All deviations were discovered during E2E testing and were necessary for the pipeline to function. The entrypoint approach is actually more robust than the original SSH plan since it runs within the container lifecycle. No scope creep.

## Issues Encountered
- GitHub Actions SSH connectivity to Coolify server was the main challenge. The original plan assumed SSH access from GitHub runners, but port 22 is blocked. The entrypoint-based approach turned out to be architecturally better since user creation happens atomically with site installation.
- Coolify API has undocumented differences between PATCH and POST for env vars. Required iterative debugging to discover the correct payload format.

## User Setup Required

**External services require manual configuration:**
- **SSH_PRIVATE_KEY:** GitHub secret with ed25519 private key (public key on root@localnodes.xyz). Note: currently unused after moving to entrypoint approach, but kept for future debugging access.
- **RESEND_API_KEY:** GitHub secret with Resend API key (used by Coolify env var injection for entrypoint welcome emails)
- **PROVISION_CALLBACK_SECRET:** GitHub secret matching Vercel env NUXT_PROVISION_CALLBACK_SECRET (for authenticated status callbacks)

All secrets were configured during E2E testing and verified working.

## Next Phase Readiness
- Phase 15 (Provisioning Pipeline) fully complete. All 4 PROV requirements satisfied.
- Ready for Phase 16 (Status & Notification): polling-based progress UI and welcome email with getting-started steps
- Existing instances (Cascadia, Boulder, Portland) need RESEND_API_KEY env var set and fresh deploy to enable Resend SMTP

## Self-Check: PASSED

All 5 created/modified files verified present. Removed file (sendmail transport) confirmed absent. All 6 commit hashes verified in git log.

---
*Phase: 15-provisioning-pipeline*
*Completed: 2026-03-04*
