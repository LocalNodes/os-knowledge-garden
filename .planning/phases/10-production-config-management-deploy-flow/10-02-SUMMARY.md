---
phase: 10-production-config-management-deploy-flow
plan: 02
subsystem: infra
tags: [drupal, drush-deploy, config-sync, entrypoint, docker]

# Dependency graph
requires:
  - phase: 10-01
    provides: "Template settings.php with getenv(), Dockerfile COPY for config/sync"
provides:
  - "Complete config/sync with localnodes_platform and Gemini key config"
  - "Simplified entrypoint using drush deploy for existing installs"
  - "Fresh install path aligned with config/sync via drush deploy"
affects: [10-03, deployment, coolify]

# Tech tracking
tech-stack:
  added: []
  patterns: ["drush deploy workflow (updb -> cr -> cim -> cr -> deploy:hook)"]

key-files:
  created:
    - config/sync/key.key.gemini_api_key.yml
  modified:
    - config/sync/core.extension.yml
    - docker/entrypoint.sh

key-decisions:
  - "Use drush deploy instead of config:import --partial for existing installs -- standard Drupal workflow"
  - "Remove search-api:index and cron from existing-install path to avoid Gemini API costs on every restart"
  - "Add drush deploy to fresh install path after site:install to align active config with config/sync"

patterns-established:
  - "drush deploy for all config sync on existing installs"
  - "Excluded modules (demo, web3) enabled after drush deploy via explicit drush en"

requirements-completed: [CFG-04, CFG-05]

# Metrics
duration: 2min
completed: 2026-03-02
---

# Phase 10 Plan 02: Config Sync & Entrypoint Simplification Summary

**Complete config/sync with localnodes_platform module, Gemini API key entity, and simplified entrypoint using drush deploy instead of per-module config:import --partial**

## Performance

- **Duration:** 2 min
- **Started:** 2026-03-02T13:05:29Z
- **Completed:** 2026-03-02T13:07:44Z
- **Tasks:** 2
- **Files modified:** 3

## Accomplishments
- Fixed config/sync/core.extension.yml to include localnodes_platform and exclude demo/web3 modules
- Added key.key.gemini_api_key.yml to config/sync with env provider for Gemini API key
- Replaced fragile config:import --partial loop with standard drush deploy workflow
- Removed settings.php generation from entrypoint (template committed in Plan 01)
- Added drush deploy to fresh install path for config/sync alignment

## Task Commits

Each task was committed atomically:

1. **Task 1: Fix config/sync gaps -- add missing configs and remove excluded modules** - `790937a` (feat)
2. **Task 2: Simplify entrypoint -- use drush deploy for existing installs, remove settings.php generation** - `6ba906e` (feat)

## Files Created/Modified
- `config/sync/core.extension.yml` - Added localnodes_platform, removed localnodes_demo and social_demo
- `config/sync/key.key.gemini_api_key.yml` - Gemini API key entity config with env provider
- `docker/entrypoint.sh` - Simplified: removed settings.php generation, replaced config:import --partial with drush deploy

## Decisions Made
- Used drush deploy (updb + cr + cim + cr + deploy:hook) instead of config:import --partial -- this is the standard Drupal deployment workflow and reads from config/sync directory
- Removed search-api:index and drush cron from the existing-install path to avoid expensive Gemini API calls on every container restart; search-api tracks changes organically
- Added drush deploy to fresh install path after site:install to ensure active config aligns with config/sync before demo content loading

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness
- config/sync is now the single source of truth for deployable config
- Entrypoint uses standard drush deploy workflow for both fresh and existing installs
- Ready for Plan 03 (deploy hook implementation and CI/CD integration)

## Self-Check: PASSED

All files verified present, all commit hashes verified in git log.

---
*Phase: 10-production-config-management-deploy-flow*
*Completed: 2026-03-02*
