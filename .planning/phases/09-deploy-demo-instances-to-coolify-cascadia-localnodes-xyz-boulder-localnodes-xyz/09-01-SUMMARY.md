---
phase: 09-deploy-demo-instances-to-coolify-cascadia-localnodes-xyz-boulder-localnodes-xyz
plan: 01
subsystem: infra
tags: [docker, docker-compose, coolify, solr, qdrant, deploy, vendor-committed]

# Dependency graph
requires:
  - phase: 06-demo-content
    provides: "Custom modules (localnodes_platform, social_ai_indexing, localnodes_demo, boulder_demo) and patched vendor code"
provides:
  - "localnodes branch on proofoftom/open-social-coolify with full AI knowledge garden stack"
  - "4-service docker-compose stack (opensocial, mariadb, solr, qdrant)"
  - "Solr 9 configset (182 files) baked into Docker image"
  - "Entrypoint with LocalNodes platform install and env-driven demo content"
affects: [09-02, 09-03, deploy-cascadia, deploy-boulder]

# Tech tracking
tech-stack:
  added: [qdrant/qdrant:v1.13.2, solr:9, mariadb:10.11]
  patterns: [vendor-committed deploy, env-driven instance differentiation, bash TCP healthcheck for Qdrant]

key-files:
  created: []
  modified:
    - "(open-social-coolify) docker-compose.yml"
    - "(open-social-coolify) Dockerfile"
    - "(open-social-coolify) Dockerfile.solr"
    - "(open-social-coolify) entrypoint.sh"
    - "(open-social-coolify) .dockerignore"

key-decisions:
  - "Vendor-committed strategy: rsync pre-built code with patches applied rather than composer install at build time"
  - "Solr configset name changed from opensearch to drupal to match DDEV naming convention"
  - "SIWE domain extraction strips protocol prefix from SERVICE_FQDN_OPENSOCIAL for dynamic per-instance config"
  - "Qdrant healthcheck uses bash TCP probe since Qdrant image lacks curl"

patterns-established:
  - "Environment-variable-driven instance differentiation: DEMO_MODULE controls which demo content module is enabled"
  - "Vendor-committed deploy repo: patches pre-applied, no composer install at build time"

requirements-completed: [DEPLOY-REPO]

# Metrics
duration: 4min
completed: 2026-02-28
---

# Phase 9 Plan 01: Deploy Repo Update Summary

**Synced fresh3 AI knowledge garden stack to open-social-coolify deploy repo with 4-service Docker Compose (opensocial + mariadb + Solr 9 + Qdrant v1.13.2) and env-driven instance differentiation**

## Performance

- **Duration:** 4 min
- **Started:** 2026-03-01T06:19:16Z
- **Completed:** 2026-03-01T06:23:38Z
- **Tasks:** 3
- **Files modified:** 6946 (full codebase sync)

## Accomplishments
- Synced complete fresh3 codebase to localnodes branch including all 4 custom modules, 8 patches pre-applied in vendor, and 182-file Solr configset
- Added Qdrant v1.13.2 as fourth Docker service with bash TCP healthcheck and persistent volume
- Upgraded Solr from 8.11 to 9 with DDEV-generated search_api_solr configset
- Updated entrypoint.sh with Qdrant wait, LocalNodes platform install, env-driven demo content loading, and cron-triggered vector indexing
- Retained Web3/SIWE modules with dynamic per-instance domain configuration

## Task Commits

All work was committed to the `proofoftom/open-social-coolify` repository (not the fresh3 repo):

1. **Tasks 1-3: Clone, sync, update Docker files, commit and push** - `d7e4956d` (feat) on `localnodes` branch of `proofoftom/open-social-coolify`

**Note:** Tasks 1-3 all operate on the external deploy repo and are captured in a single commit there since they are sequential steps building toward the same artifact.

## Files Created/Modified

All in `proofoftom/open-social-coolify` repo, `localnodes` branch:

- `docker-compose.yml` - 4-service stack with Qdrant, env vars for AI/demo settings, 120s start period
- `Dockerfile` - Added COPY patches line for documentation
- `Dockerfile.solr` - Upgraded from solr:8.11 to solr:9, configset name opensearch -> drupal
- `entrypoint.sh` - Added Qdrant wait, localnodes_platform enable, DEMO_MODULE-driven demo content, cron loop for vector indexing, dynamic SIWE domain from SERVICE_FQDN_OPENSOCIAL
- `.dockerignore` - Updated to exclude CLAUDE.md, AGENTS.md, docs/ but NOT vendor/ or html/
- `composer.json` / `composer.lock` - Synced from fresh3 with all patches defined
- `vendor/` - Full pre-built vendor with 8 patches applied
- `html/` - Full Drupal docroot with core, contrib (patched), custom modules, profiles
- `patches/` - 8 patch files for reference/documentation
- `solr-config/` - 182-file search_api_solr configset from DDEV

## Decisions Made
- Used vendor-committed strategy (rsync pre-built code) rather than multi-stage Docker build with composer install -- pragmatic choice given 8 complex patches
- Changed Solr configset name from `opensearch` to `drupal` to match DDEV naming and solr-precreate core name
- Updated SIWE domain extraction to strip protocol prefix from SERVICE_FQDN_OPENSOCIAL for correct per-instance configuration
- Used bash TCP healthcheck for Qdrant (`echo > /dev/tcp/localhost/6333`) since Qdrant Docker image lacks curl

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] Updated SIWE domain extraction to handle protocol prefix**
- **Found during:** Task 2 (entrypoint.sh update)
- **Issue:** The existing entrypoint used SERVICE_FQDN_OPENSOCIAL directly as the SIWE domain, but Coolify may provide it with https:// prefix. The plan specified stripping the protocol.
- **Fix:** Added `sed 's|https://||' | sed 's|http://||'` to extract bare domain from SERVICE_FQDN_OPENSOCIAL
- **Files modified:** entrypoint.sh
- **Verification:** Grep confirms the sed pipeline is present
- **Committed in:** d7e4956d

---

**Total deviations:** 1 auto-fixed (1 missing critical)
**Impact on plan:** Essential for correct SIWE domain configuration. No scope creep.

## Issues Encountered
None - plan executed smoothly.

## User Setup Required
None - no external service configuration required for this plan. Coolify env var configuration happens in Plan 09-02.

## Next Phase Readiness
- localnodes branch is pushed and ready for Coolify to deploy from
- Plan 09-02 can create Coolify applications pointing to this branch
- GEMINI_API_KEY will need to be set as a Coolify env var in Plan 09-02

## Self-Check: PASSED

- FOUND: 09-01-SUMMARY.md
- FOUND: d7e4956d (deploy repo commit)
- FOUND: localnodes branch on GitHub
- FOUND: docker-compose.yml, Dockerfile.solr, entrypoint.sh
- FOUND: localnodes_platform, boulder_demo custom modules

---
*Phase: 09-deploy-demo-instances-to-coolify-cascadia-localnodes-xyz-boulder-localnodes-xyz*
*Completed: 2026-02-28*
