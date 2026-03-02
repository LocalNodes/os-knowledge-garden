---
phase: 10-production-config-management-deploy-flow
plan: 01
subsystem: infra
tags: [drupal, settings-php, getenv, config-sync, docker, deploy-hooks, 12-factor]

# Dependency graph
requires:
  - phase: 09-deploy-demo-instances-to-coolify
    provides: Docker multi-stage build, entrypoint, docker-compose.yaml
provides:
  - Template settings.php with getenv() for all environment-specific values
  - config_exclude_modules for demo and web3 module isolation
  - Docker image with config/sync directory for drush deploy
  - Deploy hook scaffold for future one-time post-cim operations
affects: [10-02-entrypoint-simplification, 10-03-config-export-verification]

# Tech tracking
tech-stack:
  added: []
  patterns: [12-factor-settings-php, config-exclude-modules, deploy-hooks]

key-files:
  created:
    - html/modules/custom/localnodes_platform/localnodes_platform.deploy.php
  modified:
    - html/sites/default/settings.php
    - docker/Dockerfile

key-decisions:
  - "Replaced 903-line stock settings.php with 100-line template using getenv() for all env-specific values"
  - "config_exclude_modules excludes both demo modules (localnodes_demo, boulder_demo, portland_demo, social_demo) and web3 modules (siwe_login, safe_smart_accounts, group_treasury, social_group_treasury)"
  - "Solr, Qdrant, and Gemini API key all configured via runtime $config[] overrides in settings.php"

patterns-established:
  - "12-factor settings.php: All environment-specific values via getenv() with sensible defaults"
  - "config_exclude_modules: Instance-specific modules excluded from config sync, enabled by entrypoint"
  - "Deploy hooks in localnodes_platform.deploy.php for post-cim one-time operations"

requirements-completed: [CFG-01, CFG-02, CFG-03, CFG-06]

# Metrics
duration: 2min
completed: 2026-03-02
---

# Phase 10 Plan 01: Settings & Docker Config Summary

**12-factor template settings.php with getenv(), config_exclude_modules for instance isolation, and Docker image config/sync for drush deploy**

## Performance

- **Duration:** 2 min
- **Started:** 2026-03-02T13:00:36Z
- **Completed:** 2026-03-02T13:02:31Z
- **Tasks:** 2
- **Files modified:** 3

## Accomplishments
- Replaced 903-line stock Drupal settings.php with clean 100-line 12-factor template
- All environment-specific values (DB, Solr, Qdrant, Gemini, reverse proxy) read from env vars via getenv()
- config_exclude_modules isolates demo and web3 modules from config sync
- Docker image now includes config/sync directory for drush deploy / drush cim
- Deploy hook scaffold established for future one-time post-config-import operations

## Task Commits

Each task was committed atomically:

1. **Task 1: Create template settings.php with getenv() calls and config overrides** - `dbcacef` (feat)
2. **Task 2: Add config/sync to Docker image and create deploy hook scaffold** - `886dbb1` (feat)

## Files Created/Modified
- `html/sites/default/settings.php` - Clean template with getenv() for DB, hash_salt, Solr, Qdrant, Gemini; config_exclude_modules; reverse proxy; trusted hosts; DDEV integration
- `docker/Dockerfile` - Added COPY config/ to include config/sync in Docker image
- `html/modules/custom/localnodes_platform/localnodes_platform.deploy.php` - Deploy hook scaffold with naming convention docs

## Decisions Made
- Replaced 903-line stock settings.php with 100-line template -- all commented documentation removed since this is a purpose-built distribution template, not a generic Drupal install
- Excluded both demo modules AND web3 modules from config sync via config_exclude_modules -- these are instance-specific and enabled by entrypoint
- Runtime $config[] overrides for Solr, Qdrant, Gemini keep per-instance values out of config sync while still being auditable in version control
- DDEV include at bottom of settings.php so settings.ddev.php overrides DB connection for local dev

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness
- settings.php template ready for both DDEV and Docker environments
- Docker image will include config/sync for drush deploy workflow
- Deploy hook file ready for future one-time operations
- Plan 10-02 (entrypoint simplification with drush deploy) can proceed

## Self-Check: PASSED

All files verified present, all commits verified in git log.

---
*Phase: 10-production-config-management-deploy-flow*
*Completed: 2026-03-02*
