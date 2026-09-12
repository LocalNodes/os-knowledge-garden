# Phase 09: Deploy Demo Instances to Coolify - Context

**Gathered:** 2026-03-01
**Status:** Ready for planning
**Source:** PRD Express Path (docs/plans/2026-03-01-clean-docker-compose-deploy-design.md)

<domain>
## Phase Boundary

This phase delivers two live demo instances (cascadia.localnodes.xyz, boulder.localnodes.xyz) running the full AI knowledge garden stack via a CI artifact deployment strategy. The approach is self-contained in the main repo (LocalNodes/os-knowledge-garden) — no separate deploy repo.

**Replaces:** The old vendor-committed deploy repo approach (proofoftom/open-social-coolify) which was fragile, 6.8GB+, and failed to load correct demo content.

</domain>

<decisions>
## Implementation Decisions

### Deployment Architecture
- CI artifact strategy: GitHub Actions builds Docker image, pushes to GHCR, Coolify pulls pre-built image
- Registry: ghcr.io/localnodes/os-knowledge-garden (public, free via GitHub)
- One image for both instances; DEMO_MODULE env var differentiates at runtime
- DDEV stays for local dev (unchanged), Docker files are production-only

### Dockerfile (Multi-Stage)
- Stage 1 (builder): php:8.3-cli, install composer/git/patch/unzip, COPY composer.json + composer.lock + patches/, RUN composer install --no-dev --optimize-autoloader (patches applied automatically by cweagans/composer-patches)
- Stage 2 (runtime): php:8.3-apache, PHP extensions: gd (webp/jpeg/freetype), gmp (SIWE), imagick, pdo_mysql, opcache
- COPY --from=builder vendor/ and html/, COPY entrypoint.sh
- Configure Apache for Drupal (rewrite module, docroot)

### Docker Compose Stack (4 services)
- opensocial: pulls pre-built image from GHCR, env vars for DB/Solr/Qdrant/Gemini/demo config
- mariadb: mariadb:10.11 (stock image)
- solr: builds locally from Dockerfile.solr (copies configset, fast build)
- qdrant: qdrant/qdrant:v1.13.2 (stock image)
- 5 named volumes: opensocial-files, opensocial-private, mariadb-data, solr-data, qdrant-data

### Entrypoint Logic
- First boot (no `system` table in DB): drush site:install social, enable localnodes_platform, enable $DEMO_MODULE, load demo content, index Solr, run cron 3x for vector embedding queue, enable Web3 modules (siwe_login, safe_smart_accounts, group_treasury, social_group_treasury), configure SIWE domain from SERVICE_FQDN_OPENSOCIAL
- Restart (system table exists): verify modules enabled, run cron, start Apache in foreground

### Solr Configuration
- Dockerfile.solr: Solr 9 + search_api_solr configset copied from DDEV's Solr configset (182 files)
- Core name: drupal (matches DDEV naming convention)
- Solr command: solr-precreate drupal with configset

### GitHub Actions CI
- Trigger: push to main + workflow_dispatch
- Build: Docker Buildx, login to GHCR with GITHUB_TOKEN, build and push with tags: latest + git SHA
- Permissions: contents:read, packages:write

### Coolify Configuration
- Delete existing old apps (j8osgcos8scos0ccw44wgo00, f8ccwks04oso408ko44wwkoc)
- Create two new apps pointing to LocalNodes/os-knowledge-garden
- Coolify deploys docker-compose.yml: pulls opensocial from GHCR, builds only Solr
- Per-instance env vars: DEMO_MODULE, DRUPAL_SITE_NAME, GEMINI_API_KEY, passwords
- Domain mapping: cascadia.localnodes.xyz, boulder.localnodes.xyz

### Instance Differentiation
- Cascadia: cascadia.localnodes.xyz, DEMO_MODULE=localnodes_demo, DRUPAL_SITE_NAME="Cascadia LocalNodes"
- Boulder: boulder.localnodes.xyz, DEMO_MODULE=boulder_demo, DRUPAL_SITE_NAME="Boulder LocalNodes"

### File Structure
- docker/Dockerfile — multi-stage builder
- docker/Dockerfile.solr — Solr 9 + configset
- docker/entrypoint.sh — site install, demo content, indexing
- docker/solr-config/ — copied from DDEV's Solr configset
- docker-compose.yml — 4-service stack (root of repo)
- .github/workflows/build-image.yml — CI build + push

### Claude's Discretion
- Exact PHP extension install commands in Dockerfile
- Apache configuration details (DocumentRoot, AllowOverride)
- Error handling in entrypoint.sh (retry logic, failure modes)
- Solr configset copy mechanics
- Docker build caching strategy
- Coolify API calls vs UI for app creation/deletion

</decisions>

<specifics>
## Specific Ideas

- Healthchecks defined for all 4 services in docker-compose.yml (curl for opensocial/solr, mysqladmin for mariadb, TCP probe for qdrant)
- opensocial depends_on all 3 other services with condition: service_healthy
- Qdrant healthcheck uses bash TCP probe since image lacks curl
- Start periods: opensocial 120s, mariadb 30s, solr 60s, qdrant 10s
- SOLR_HEAP: 512m
- Environment variables use ${VAR:-default} pattern throughout docker-compose.yml

</specifics>

<deferred>
## Deferred Ideas

None — design doc covers full phase scope.

</deferred>

---

*Phase: 09-deploy-demo-instances-to-coolify-cascadia-localnodes-xyz-boulder-localnodes-xyz*
*Context gathered: 2026-03-01 via PRD Express Path*
