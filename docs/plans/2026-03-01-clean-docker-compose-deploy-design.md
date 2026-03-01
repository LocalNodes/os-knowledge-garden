# Clean Docker Compose Deployment Design

**Date:** 2026-03-01
**Status:** Approved
**Replaces:** open-social-coolify deploy repo approach

## Problem

The separate vendor-committed deploy repo (`proofoftom/open-social-coolify`) has been fragile:
- 6.8GB+ repo with committed vendor/ directory
- Demo content not loading correctly (showing social_demo instead of instance-specific content)
- Changes in development repo must be manually synced to deploy repo
- Domain routing requires manual Coolify UI intervention

## Decision

Self-contained deployment in the main repo (`LocalNodes/os-knowledge-garden`) using a CI artifact strategy:
- GitHub Actions builds the Docker image (composer install + patches)
- Pushes to GitHub Container Registry (`ghcr.io/localnodes/os-knowledge-garden`)
- Coolify deploys docker-compose.yml which pulls the pre-built image

## Architecture

### Approach: CI builds image, Coolify orchestrates containers

```
GitHub Actions (on push to main)
  └─> Build multi-stage Dockerfile
      └─> Stage 1 (builder): composer install, apply 17+ patches
      └─> Stage 2 (runtime): lean php:8.3-apache with built artifacts
  └─> Push to ghcr.io/localnodes/os-knowledge-garden:latest + :sha

Coolify (per instance)
  └─> Deploy docker-compose.yml from repo
      └─> opensocial: pulls pre-built image from GHCR
      └─> mariadb: mariadb:10.11 (stock image)
      └─> solr: builds locally from Dockerfile.solr (fast, just copies configset)
      └─> qdrant: qdrant/qdrant:v1.13.2 (stock image)
```

### File Structure

```
docker/
  Dockerfile            # Multi-stage: composer install + patches -> lean runtime
  Dockerfile.solr       # Solr 9 + search_api_solr configset
  entrypoint.sh         # Site install, demo content, indexing
  solr-config/          # Copied from DDEV's Solr configset (182 files)
docker-compose.yml      # 4-service stack, pulls ghcr.io/localnodes/os-knowledge-garden
.github/
  workflows/
    build-image.yml     # CI: build + push to GHCR
```

### Dockerfile (multi-stage)

**Stage 1 — Builder:**
- Base: `php:8.3-cli`
- Install: composer, git, patch, unzip
- COPY composer.json, composer.lock, patches/
- RUN composer install --no-dev --optimize-autoloader
- Patches applied automatically by cweagans/composer-patches

**Stage 2 — Runtime:**
- Base: `php:8.3-apache`
- PHP extensions: gd (webp/jpeg/freetype), gmp (SIWE), imagick, pdo_mysql, opcache
- COPY --from=builder vendor/ and html/
- COPY entrypoint.sh
- Configure Apache for Drupal (rewrite module, docroot)

### docker-compose.yml

```yaml
services:
  opensocial:
    image: ghcr.io/localnodes/os-knowledge-garden:latest
    environment:
      DB_HOST: mariadb
      DB_PORT: 3306
      DB_NAME: ${DB_NAME:-opensocial}
      DB_USER: ${DB_USER:-opensocial}
      DB_PASSWORD: ${DB_PASSWORD:-changeme}
      DB_ROOT_PASSWORD: ${DB_ROOT_PASSWORD:-rootpassword}
      DRUPAL_HASH_SALT: ${DRUPAL_HASH_SALT:-generate-random}
      DRUPAL_TRUSTED_HOST_PATTERNS: ${DRUPAL_TRUSTED_HOST_PATTERNS:-}
      DRUPAL_REVERSE_PROXY: "true"
      SOLR_HOST: solr
      SOLR_PORT: 8983
      SOLR_PATH: /solr
      QDRANT_HOST: qdrant
      QDRANT_PORT: 6333
      GEMINI_API_KEY: ${GEMINI_API_KEY:-}
      DEMO_MODULE: ${DEMO_MODULE:-localnodes_demo}
      DRUPAL_SITE_NAME: ${DRUPAL_SITE_NAME:-LocalNodes}
      DRUPAL_ADMIN_USER: ${DRUPAL_ADMIN_USER:-admin}
      DRUPAL_ADMIN_PASS: ${DRUPAL_ADMIN_PASS:-admin}
      SERVICE_FQDN_OPENSOCIAL: ${SERVICE_FQDN_OPENSOCIAL:-}
    volumes:
      - opensocial-files:/var/www/html/html/sites/default/files
      - opensocial-private:/var/www/private
    depends_on:
      mariadb: { condition: service_healthy }
      solr: { condition: service_healthy }
      qdrant: { condition: service_healthy }
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost/core/install.php"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 120s

  mariadb:
    image: mariadb:10.11
    environment:
      MYSQL_DATABASE: ${DB_NAME:-opensocial}
      MYSQL_USER: ${DB_USER:-opensocial}
      MYSQL_PASSWORD: ${DB_PASSWORD:-changeme}
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD:-rootpassword}
    volumes:
      - mariadb-data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-u", "${DB_USER:-opensocial}", "-p${DB_PASSWORD:-changeme}"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 30s

  solr:
    build:
      context: ./docker
      dockerfile: Dockerfile.solr
    volumes:
      - solr-data:/var/solr
    environment:
      SOLR_HEAP: 512m
    command: |
      bash -c "
        sleep 2 &&
        rm -rf /var/solr/data/drupal &&
        solr-precreate drupal /opt/solr/server/solr/configsets/drupal &&
        exec solr -f
      "
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8983/solr/"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 60s

  qdrant:
    image: qdrant/qdrant:v1.13.2
    volumes:
      - qdrant-data:/qdrant/storage
    healthcheck:
      test: ["CMD-SHELL", "bash -c 'echo > /dev/tcp/localhost/6333'"]
      interval: 15s
      timeout: 10s
      retries: 5
      start_period: 10s

volumes:
  opensocial-files:
  opensocial-private:
  mariadb-data:
  solr-data:
  qdrant-data:
```

### entrypoint.sh

Cleanly separated first-boot vs restart paths:

**First boot** (no `system` table in DB):
1. `drush site:install social` with site name from env
2. Enable `localnodes_platform` (AI stack config)
3. Enable `$DEMO_MODULE` (localnodes_demo or boulder_demo)
4. Load demo content: `drush social-demo:add file user group topic event event_enrollment comment post like`
5. Index Solr: `drush search-api:index`
6. Run cron 3x for vector embedding queue
7. Enable Web3 modules (siwe_login, safe_smart_accounts, group_treasury, social_group_treasury)
8. Configure SIWE domain from `SERVICE_FQDN_OPENSOCIAL`

**Restart** (system table exists):
1. Verify modules enabled, run cron
2. Start Apache in foreground

### GitHub Actions CI

```yaml
on:
  push:
    branches: [main]
  workflow_dispatch:

jobs:
  build:
    runs-on: ubuntu-latest
    permissions:
      contents: read
      packages: write
    steps:
      - Checkout
      - Set up Docker Buildx
      - Login to GHCR (GITHUB_TOKEN)
      - Build and push (tags: latest + git SHA)
```

### Coolify Configuration

- Delete existing apps (j8osgcos8scos0ccw44wgo00, f8ccwks04oso408ko44wwkoc)
- Create two new apps pointing to `LocalNodes/os-knowledge-garden`
- Coolify deploys docker-compose.yml: pulls opensocial from GHCR, builds only Solr
- Per-instance env vars: DEMO_MODULE, DRUPAL_SITE_NAME, GEMINI_API_KEY, passwords
- Domain mapping: cascadia.localnodes.xyz, boulder.localnodes.xyz

### Instance Differentiation

| Instance | Domain | DEMO_MODULE | DRUPAL_SITE_NAME |
|----------|--------|-------------|------------------|
| Cascadia | cascadia.localnodes.xyz | localnodes_demo | Cascadia LocalNodes |
| Boulder | boulder.localnodes.xyz | boulder_demo | Boulder LocalNodes |

## What This Replaces

- Eliminates the separate `proofoftom/open-social-coolify` deploy repo
- No more vendor-committed 6.8GB repo
- No more manual rsync of changes between dev and deploy repos
- Patches applied once in CI (visible failure if a patch breaks)
- DDEV stays for local development (unchanged)
