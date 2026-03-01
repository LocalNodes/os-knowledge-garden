---
phase: 09-deploy-demo-instances-to-coolify-cascadia-localnodes-xyz-boulder-localnodes-xyz
plan: 01
subsystem: infra
tags: [docker, ci, github-actions, ghcr, solr, qdrant, apache, drupal, multi-stage-build]

# Dependency graph
requires:
  - phase: 06-demo-content
    provides: "Demo content modules (localnodes_demo, boulder_demo) for site install"
provides:
  - "Multi-stage Dockerfile building Drupal with composer patches into lean Apache runtime"
  - "Solr 9 Dockerfile with search_api_solr configset baked in (182 files)"
  - "Entrypoint script with fresh-install and restart paths, including Web3 modules"
  - "docker-compose.yml defining 4-service stack (opensocial, mariadb, solr, qdrant)"
  - "GitHub Actions workflow building and pushing to ghcr.io/localnodes/os-knowledge-garden"
  - ".dockerignore excluding non-build files"
affects: [09-02, 09-03]

# Tech tracking
tech-stack:
  added: [docker, docker-compose, github-actions, ghcr]
  patterns: [multi-stage-docker-build, ci-artifact-deployment, env-var-driven-config]

key-files:
  created:
    - docker/Dockerfile
    - docker/Dockerfile.solr
    - docker/entrypoint.sh
    - docker/solr-config/ (182 files)
    - docker-compose.yml
    - .dockerignore
    - .github/workflows/build-image.yml
  modified: []

key-decisions:
  - "Multi-stage Docker build: php:8.3-cli builder for composer install with patches, php:8.3-apache runtime for lean production image"
  - "Solr configset baked into custom Solr image rather than volume-mounted at runtime"
  - "Entrypoint uses drush bootstrap check for install detection instead of raw MySQL queries"
  - "Qdrant dimension check in entrypoint auto-deletes collections with wrong dimensions (1536 vs 3072)"
  - "DEMO_MODULE env var differentiates instances (localnodes_demo vs boulder_demo) from single image"
  - "GitHub Actions uses docker/metadata-action for SHA-based and latest tags"

patterns-established:
  - "CI artifact pattern: build once in CI, deploy pre-built image via docker-compose"
  - "Environment-driven configuration: all service endpoints and credentials via env vars with sensible defaults"
  - "Service health wait pattern: entrypoint waits for mariadb, solr, qdrant before proceeding"

requirements-completed: [DEPLOY-REPO]

# Metrics
duration: 4min
completed: 2026-03-01
---

# Phase 9 Plan 01: Docker/CI Infrastructure Summary

**Multi-stage Dockerfile with composer patch support, 4-service docker-compose stack, and GitHub Actions CI pushing to ghcr.io/localnodes/os-knowledge-garden**

## Performance

- **Duration:** 4 min
- **Started:** 2026-03-01T09:10:56Z
- **Completed:** 2026-03-01T09:14:55Z
- **Tasks:** 3 (2 auto + 1 verification checkpoint)
- **Files created:** 188

## Accomplishments
- Created multi-stage Dockerfile: php:8.3-cli builder stage handles composer install with all 8 patches, php:8.3-apache runtime stage provides lean production image with all required PHP extensions (gd, gmp, pdo_mysql, opcache, zip, imagick)
- Created comprehensive entrypoint.sh handling fresh install (drush site:install, demo content loading, Solr indexing, vector embedding via cron, Web3 module enablement) and restart paths (module verification, cron)
- Created docker-compose.yml with 4 services (opensocial from GHCR, mariadb, solr with custom configset, qdrant) including healthchecks, dependency ordering, and 5 named volumes
- Created GitHub Actions workflow triggered on push to main and manual dispatch, building and pushing to GHCR with SHA and latest tags using Docker Buildx with GHA caching
- Copied 182 Solr configset files from DDEV configuration into docker/solr-config/ for baked-in Solr image

## Task Commits

Each task was committed atomically:

1. **Task 1: Create Dockerfile, Dockerfile.solr, entrypoint.sh, .dockerignore, and copy Solr configset** - `f3127ef` (feat)
2. **Task 2: Create docker-compose.yml and GitHub Actions workflow** - `e16b4e9` (feat)
3. **Task 3: Human verification of all Docker infrastructure files** - No commit (checkpoint:human-verify, approved)

## Files Created/Modified
- `docker/Dockerfile` - Multi-stage build: composer install with patches in builder, Apache runtime
- `docker/Dockerfile.solr` - Solr 9 with search_api_solr configset baked in
- `docker/entrypoint.sh` - First-boot site install + demo content + restart logic
- `docker/solr-config/` - 182 Solr configset files from DDEV
- `docker-compose.yml` - 4-service stack pulling pre-built GHCR image
- `.dockerignore` - Excludes .git, .ddev, .planning, private/ from Docker build context
- `.github/workflows/build-image.yml` - CI build + push to GHCR on push to main

## Decisions Made
- Multi-stage Docker build separates composer install (with patches) from runtime, keeping final image lean
- Solr configset baked into custom Docker image (182 files) rather than volume-mounted
- Entrypoint uses `drush status --field=bootstrap` for install detection (more reliable than raw MySQL)
- Qdrant dimension check auto-deletes collections with wrong dimensions (1536 from old model vs 3072 current)
- Single Docker image serves both instances; `DEMO_MODULE` env var selects content module at runtime
- GitHub Actions uses docker/metadata-action for automatic SHA-based and latest tagging

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required for this plan. GitHub Actions and Coolify configuration happens in Plan 09-02.

## Next Phase Readiness
- All Docker/CI infrastructure files are created and reviewed
- Ready to push to GitHub to trigger first CI build (Plan 09-02)
- Plan 09-02 will handle GitHub push, Coolify app creation, and deployment

## Self-Check: PASSED

All 7 created files verified present on disk. Both task commits (f3127ef, e16b4e9) verified in git history. SUMMARY.md exists.

---
*Phase: 09-deploy-demo-instances-to-coolify-cascadia-localnodes-xyz-boulder-localnodes-xyz*
*Completed: 2026-03-01*
