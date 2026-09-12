# Phase 9: Deploy Demo Instances to Coolify - Research

**Researched:** 2026-02-28
**Domain:** Coolify PaaS deployment, Docker Compose, Drupal containerization
**Confidence:** HIGH

## Summary

The user already has a working Open Social Coolify deployment (`proofoftom/open-social-coolify` repo, `test-composer-2` branch) running at `demo.localnodes.xyz` with status `running:healthy`. This existing deployment uses the `dockercompose` build pack with three services (opensocial, mariadb, solr) and a vendor-committed strategy (no `composer install` at build time). However, that deployment is vanilla Open Social without AI modules, Qdrant, patches, or custom LocalNodes modules.

The fresh3 project adds significant complexity: 8 composer patches across 5 packages, 4 custom modules (`localnodes_platform`, `social_ai_indexing`, `localnodes_demo`, `boulder_demo`), Qdrant vector database, Gemini AI provider integration, and two distinct demo content profiles. The deployment strategy should fork/update the existing `open-social-coolify` repo to include all fresh3 additions, then deploy two separate Coolify applications from it -- one for Cascadia (localnodes_demo) and one for Boulder (boulder_demo).

Wildcard DNS for `*.localnodes.xyz` is already configured via Cloudflare (both `cascadia.localnodes.xyz` and `boulder.localnodes.xyz` resolve). Coolify's Traefik proxy handles automatic Let's Encrypt SSL certificates. The server (`localhost`, IP `65.108.90.69`) is reachable and has a `LocalNodes` project already created.

**Primary recommendation:** Update the existing `open-social-coolify` deploy repo with fresh3's full codebase (vendor-committed with patches pre-applied), add Qdrant as a fourth service in docker-compose.yml, update the entrypoint to install LocalNodes platform + demo content, and deploy two Coolify applications with different environment variables controlling which demo module gets enabled.

## Standard Stack

### Core
| Component | Version | Purpose | Why Standard |
|-----------|---------|---------|--------------|
| Coolify | v4.0.0-beta.451+ | PaaS deployment platform | Already in use, proven with this project |
| Docker Compose build pack | Coolify native | Multi-container orchestration | Existing proven pattern from `demo.localnodes.xyz` |
| php:8.3-apache | 8.3 | Drupal runtime | Matches DDEV config, existing Dockerfile |
| MariaDB | 10.11 | Database | Matches DDEV config, existing deployment |
| Solr | 9 | Full-text search | Matches DDEV config (Solr 9), upgrade from existing deploy's 8.11 |
| Qdrant | v1.13.2 | Vector database | Matches DDEV config, required for AI features |
| Traefik | 3.6.x | Reverse proxy / SSL | Coolify-managed, automatic Let's Encrypt |

### Supporting
| Component | Version | Purpose | When to Use |
|-----------|---------|---------|-------------|
| Composer | 2.x | Dependency management | Pre-build only (vendor-committed strategy) |
| Drush | 13.7 | Drupal CLI | Entrypoint script for site install + demo content |
| Key module | 1.19 | Secret management | Reads `GEMINI_API_KEY` from env var |
| cweagans/composer-patches | latest | Patch application | Pre-build only, patches applied before committing vendor |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Vendor-committed | Multi-stage Docker build with `composer install` | Multi-stage is "best practice" but the existing deploy repo already uses vendor-committed successfully. With 8 patches and complex deps, build-time `composer install` adds fragility. Vendor-committed is pragmatic for this project. |
| Solr 8.11 (existing deploy) | Solr 9 (DDEV config) | Use Solr 9 to match local dev. The Solr configset from DDEV should work with Solr 9. Existing deploy's Solr 8.11 is outdated. |
| Separate Qdrant service | Qdrant inside docker-compose | Qdrant inside the compose stack is simpler -- no cross-network communication needed. Each instance gets its own Qdrant with isolated data. |
| Database SQL dump import | Drush site-install + demo module enable | Drush approach is reproducible, matches existing entrypoint pattern, and avoids managing SQL dump artifacts. |

## Architecture Patterns

### Recommended Deployment Repository Structure
```
open-social-coolify/
├── Dockerfile                  # PHP 8.3 Apache image, copies pre-built code
├── Dockerfile.solr             # Solr 9 with search_api_solr configset
├── docker-compose.yml          # 4 services: opensocial, mariadb, solr, qdrant
├── entrypoint.sh               # Site install, module enable, demo content load
├── composer.json               # With patches defined
├── composer.lock
├── vendor/                     # Pre-installed (committed to repo)
├── html/                       # Drupal docroot with all modules (committed)
│   ├── core/
│   ├── modules/
│   │   ├── contrib/            # Including patched modules
│   │   └── custom/             # localnodes_platform, social_ai_indexing, demos
│   └── profiles/
├── patches/                    # Patch files referenced by composer.json
└── solr-config/                # Solr configset files from DDEV
```

### Pattern 1: Docker Compose with Qdrant Service
**What:** Add Qdrant as a fourth service in the compose file alongside opensocial, mariadb, and solr.
**When to use:** Every deployment that needs AI/vector search features.
**Example:**
```yaml
# Source: Qdrant official docs + DDEV qdrant config
services:
  qdrant:
    image: qdrant/qdrant:v1.13.2
    restart: unless-stopped
    volumes:
      - qdrant_data:/qdrant/storage
    healthcheck:
      test: ["CMD-SHELL", "bash -c 'echo > /dev/tcp/localhost/6333'"]
      interval: 15s
      timeout: 10s
      retries: 5
      start_period: 10s

  opensocial:
    # ... existing config ...
    environment:
      QDRANT_HOST: qdrant
      QDRANT_PORT: 6333
    depends_on:
      qdrant:
        condition: service_healthy
```

### Pattern 2: Environment-Variable-Driven Instance Differentiation
**What:** Use env vars to control which demo content module gets enabled, site name, and domain.
**When to use:** Deploying multiple instances from the same repo/image.
**Example:**
```yaml
# Cascadia instance
environment:
  DRUPAL_SITE_NAME: "Cascadia LocalNodes"
  DEMO_MODULE: "localnodes_demo"
  DEMO_CONTENT_TYPES: "file user group topic event event_enrollment comment post like"
  SERVICE_FQDN_OPENSOCIAL: cascadia.localnodes.xyz
  GEMINI_API_KEY: ${GEMINI_API_KEY}

# Boulder instance
environment:
  DRUPAL_SITE_NAME: "Boulder LocalNodes"
  DEMO_MODULE: "boulder_demo"
  DEMO_CONTENT_TYPES: "file user group topic event event_enrollment comment post like"
  SERVICE_FQDN_OPENSOCIAL: boulder.localnodes.xyz
  GEMINI_API_KEY: ${GEMINI_API_KEY}
```

### Pattern 3: Entrypoint Script with Demo Content Installation
**What:** Extend existing entrypoint.sh to install localnodes_platform, enable demo module, and load demo content via `social-demo:add`.
**When to use:** Every fresh site install (entrypoint detects if site is already installed).
**Example:**
```bash
# After base Open Social install succeeds:
# Enable LocalNodes platform (installs AI stack + config)
$DRUSH en localnodes_platform -y

# Enable the demo content module specified by env var
DEMO_MODULE="${DEMO_MODULE:-localnodes_demo}"
$DRUSH en "$DEMO_MODULE" -y

# Load demo content using social_demo drush command
$DRUSH social-demo:add $DEMO_CONTENT_TYPES

# Index content in Solr
$DRUSH search-api:index

# Note: Qdrant vector indexing happens via cron/queue workers
# Trigger initial indexing
$DRUSH cron
```

### Pattern 4: Coolify Magic Environment Variables for Domain Routing
**What:** Use `SERVICE_FQDN_OPENSOCIAL` to tell Coolify which domain to route to the opensocial service.
**When to use:** Every Coolify docker-compose deployment.
**Example:**
```yaml
# In docker-compose.yml:
services:
  opensocial:
    environment:
      SERVICE_FQDN_OPENSOCIAL: ${SERVICE_FQDN_OPENSOCIAL:-}
# In Coolify UI or API, set the env var:
# SERVICE_FQDN_OPENSOCIAL=cascadia.localnodes.xyz
# Coolify auto-generates Traefik labels for HTTPS routing + Let's Encrypt
```

### Anti-Patterns to Avoid
- **Running `composer install` in production Dockerfile:** Fragile, depends on external registries, slow builds. Use vendor-committed strategy instead.
- **Sharing database or Qdrant between instances:** Each instance must have its own MariaDB, Solr, and Qdrant volumes. Shared state causes permission/content conflicts.
- **Using `ports:` in docker-compose.yml for Coolify:** Coolify handles port exposure via Traefik. Using `ports:` bypasses the proxy and can expose services publicly.
- **Hardcoding domain names in docker-compose.yml:** Use `SERVICE_FQDN_OPENSOCIAL` env var so the same compose file works for both instances.
- **Mounting Solr configset at runtime:** Bake the configset into the Solr Docker image via Dockerfile.solr. Runtime mounts are fragile with Coolify's volume management.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| SSL certificates | Manual cert management | Coolify/Traefik auto Let's Encrypt | Automatic provisioning and renewal, wildcard DNS already set up |
| Reverse proxy config | Custom nginx/traefik config | Coolify magic env vars (`SERVICE_FQDN_`) | Proven pattern from existing deployment |
| Database provisioning | Manual SQL scripts | Entrypoint.sh with `mysql -e CREATE DATABASE` | Existing pattern handles DB creation on first boot |
| Drupal settings.php | Static config file | Entrypoint.sh dynamic generation | Existing pattern handles trusted hosts, DB config, Solr config, reverse proxy settings |
| Demo content loading | SQL dump import | Drush `social-demo:add` command | Reproducible, uses existing plugin system, no artifact management |
| Health monitoring | Custom monitoring | Coolify built-in healthchecks | Docker healthcheck directives in compose file are sufficient |
| Container networking | Manual Docker networks | Coolify auto-network per compose stack | Each stack gets isolated network, services reference each other by service name |

**Key insight:** The existing `open-social-coolify` repo and its entrypoint.sh solve 80% of the deployment problems. The work is extending it, not building from scratch.

## Common Pitfalls

### Pitfall 1: Solr Configset Mismatch
**What goes wrong:** Solr starts but Drupal can't query it because the configset schema doesn't match what search_api_solr expects.
**Why it happens:** The existing deploy repo uses Solr 8.11 with an "opensearch" configset. Fresh3 uses Solr 9 with the DDEV-generated configset (182 files including language-specific analyzers).
**How to avoid:** Copy the DDEV configset from `.ddev/solr/configsets/drupal/` into the deploy repo's `solr-config/` directory. Update Dockerfile.solr to use `solr:9` base image. Use the same core name ("drupal") that Solr's `solr-precreate` expects.
**Warning signs:** Solr healthcheck passes but search returns no results, or Drupal logs show "SolrServerException" errors.

### Pitfall 2: Qdrant Container Healthcheck Without curl
**What goes wrong:** Docker healthcheck fails because Qdrant's Alpine-based image doesn't include `curl` or `wget`.
**Why it happens:** The Qdrant Docker image is minimal and doesn't ship HTTP clients.
**How to avoid:** Use the bash TCP check: `bash -c 'echo > /dev/tcp/localhost/6333'` -- this is already the pattern used in DDEV.
**Warning signs:** Container shows as "unhealthy" despite Qdrant actually running.

### Pitfall 3: Coolify Duplicate Environment Variables
**What goes wrong:** Environment variables defined in both docker-compose.yml and Coolify UI create duplicates with unpredictable override behavior.
**Why it happens:** Coolify injects its own vars alongside compose-defined vars. The existing deployment shows duplicate `SERVICE_FQDN_OPENSOCIAL` entries in the env var list.
**How to avoid:** Define secrets and instance-specific vars ONLY in Coolify UI. Define service-connection vars (DB_HOST, SOLR_HOST, QDRANT_HOST) ONLY in docker-compose.yml. Use `${VAR:-default}` syntax in compose for vars that Coolify should populate.
**Warning signs:** Unexpected config values, Drupal trusted_host_patterns not matching the actual domain.

### Pitfall 4: Entrypoint Script Running Site Install on Every Restart
**What goes wrong:** Container restart triggers a full site reinstall, wiping the database.
**Why it happens:** The install-check logic in entrypoint.sh fails to detect an existing installation (e.g., database exists but settings.php was regenerated).
**How to avoid:** The existing entrypoint already checks `$DRUSH status --field=bootstrap`. Ensure the settings.php generation uses `grep -q "Added by entrypoint"` guard to prevent re-appending config. Ensure MariaDB volume is persistent so data survives container recreation.
**Warning signs:** Demo content disappearing after restarts, admin password resetting.

### Pitfall 5: Demo Content Module Dependencies Not Met
**What goes wrong:** `drush en localnodes_demo` fails because social_demo or its dependencies aren't available.
**Why it happens:** The localnodes_demo and boulder_demo modules depend on social_demo, which is bundled with Open Social profile. If the profile modules aren't fully installed, the dependency chain breaks.
**How to avoid:** Ensure the site install uses `social` profile (`drush site:install social`). Enable localnodes_platform BEFORE the demo module (it installs AI stack dependencies). Enable demo modules in the correct order: social_demo first (it comes with the profile), then localnodes_demo/boulder_demo.
**Warning signs:** Drush "module not found" or "unresolved dependency" errors in deployment logs.

### Pitfall 6: Gemini API Key Not Available During Site Install
**What goes wrong:** The localnodes_platform install hook tries to configure the Gemini provider but the API key env var isn't set, leading to a broken AI configuration.
**Why it happens:** The Key module reads `GEMINI_API_KEY` from the environment. If the env var isn't passed to the container, the key resolves to empty.
**How to avoid:** Set `GEMINI_API_KEY` as a Coolify environment variable (secret). The Key module's `env` provider reads it at runtime, not at build time. Verify it's set before enabling localnodes_platform.
**Warning signs:** AI chatbot returns errors, embedding generation fails silently.

### Pitfall 7: Large Docker Build Context
**What goes wrong:** Docker build takes excessively long or times out because the entire 634MB project is sent as build context.
**Why it happens:** Without a proper `.dockerignore`, Docker sends everything including `.git`, dev files, and unnecessary directories.
**How to avoid:** Maintain a `.dockerignore` that excludes `.git`, `.ddev`, `private/`, site files, and other non-essential content. The existing deploy repo has a good `.dockerignore` to reference.
**Warning signs:** Build logs show "Sending build context to Docker daemon" with a very large size (>500MB).

### Pitfall 8: Composer Patches Not Applied in Deploy Repo
**What goes wrong:** The deployed site has bugs that were fixed by patches in the development environment, because the patched vendor/module code wasn't committed to the deploy repo.
**Why it happens:** When syncing code to the deploy repo, one must run `composer install` with patches enabled to ensure patched code is in vendor/modules/contrib before committing.
**How to avoid:** Run `composer install` locally (with DDEV or native PHP) to apply all 8 patches, then commit the resulting vendor/ and html/modules/contrib/ to the deploy repo. Verify with `composer patches:check` or by diffing specific patched files.
**Warning signs:** AI assistant throws null pointer errors (the ai-assistant-api-runner-null-safety.patch wasn't applied), Qdrant deletes fail silently (qdrant-provider-fix-double-md5-delete.patch missing).

## Code Examples

### docker-compose.yml for LocalNodes with Qdrant
```yaml
# Source: Existing open-social-coolify docker-compose.yml + DDEV qdrant config
services:
  opensocial:
    build:
      context: .
      dockerfile: Dockerfile
    restart: unless-stopped
    environment:
      DB_HOST: mariadb
      DB_PORT: 3306
      DB_NAME: ${DB_NAME:-opensocial}
      DB_USER: ${DB_USER:-opensocial}
      DB_PASSWORD: ${DB_PASSWORD:-changeme_in_production}
      DRUPAL_HASH_SALT: ${DRUPAL_HASH_SALT:-generate-a-random-string-here}
      DRUPAL_TRUSTED_HOST_PATTERNS: ${DRUPAL_TRUSTED_HOST_PATTERNS:-}
      DRUPAL_REVERSE_PROXY: "true"
      SOLR_HOST: solr
      SOLR_PORT: 8983
      SOLR_PATH: /solr
      QDRANT_HOST: qdrant
      QDRANT_PORT: 6333
      GEMINI_API_KEY: ${GEMINI_API_KEY:-}
      DRUPAL_SITE_NAME: ${DRUPAL_SITE_NAME:-LocalNodes}
      DRUPAL_ADMIN_USER: ${DRUPAL_ADMIN_USER:-admin}
      DRUPAL_ADMIN_PASS: ${DRUPAL_ADMIN_PASS:-admin}
      DRUPAL_ADMIN_EMAIL: ${DRUPAL_ADMIN_EMAIL:-admin@example.com}
      DEMO_MODULE: ${DEMO_MODULE:-localnodes_demo}
      SERVICE_FQDN_OPENSOCIAL: ${SERVICE_FQDN_OPENSOCIAL:-}
      SERVICE_URL_OPENSOCIAL: ${SERVICE_URL_OPENSOCIAL:-}
      COOLIFY_FQDN: ${COOLIFY_FQDN:-}
      COOLIFY_URL: ${COOLIFY_URL:-}
    volumes:
      - opensocial_files:/var/www/html/html/sites/default/files
      - opensocial_private:/var/www/private
    depends_on:
      mariadb:
        condition: service_healthy
      solr:
        condition: service_healthy
      qdrant:
        condition: service_healthy
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost/core/install.php"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 120s
    labels:
      - "coolify.managed=true"

  mariadb:
    image: mariadb:10.11
    restart: unless-stopped
    environment:
      MYSQL_DATABASE: ${DB_NAME:-opensocial}
      MYSQL_USER: ${DB_USER:-opensocial}
      MYSQL_PASSWORD: ${DB_PASSWORD:-changeme_in_production}
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD:-rootpassword}
    volumes:
      - mariadb_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-u", "${DB_USER:-opensocial}", "-p${DB_PASSWORD:-changeme_in_production}"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 30s

  solr:
    build:
      context: .
      dockerfile: Dockerfile.solr
    restart: unless-stopped
    volumes:
      - solr_data:/var/solr
    environment:
      - SOLR_HEAP=512m
    command: >
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
    restart: unless-stopped
    volumes:
      - qdrant_data:/qdrant/storage
    healthcheck:
      test: ["CMD-SHELL", "bash -c 'echo > /dev/tcp/localhost/6333'"]
      interval: 15s
      timeout: 10s
      retries: 5
      start_period: 10s

volumes:
  opensocial_files:
  opensocial_private:
  mariadb_data:
  solr_data:
  qdrant_data:
```

### Entrypoint Extension for LocalNodes Demo Content
```bash
# Source: Existing entrypoint.sh pattern + localnodes_platform install logic
# Add after the base Open Social install block:

# Wait for Qdrant if configured
if [ -n "${QDRANT_HOST:-}" ] && [ -n "${QDRANT_PORT:-}" ]; then
    echo "Waiting for Qdrant at ${QDRANT_HOST}:${QDRANT_PORT}..."
    while ! bash -c "echo > /dev/tcp/${QDRANT_HOST}/${QDRANT_PORT}" 2>/dev/null; do
        sleep 1
    done
    echo "Qdrant is available!"
fi

# Enable LocalNodes platform (installs AI stack config)
echo "Enabling LocalNodes Platform..."
$DRUSH en localnodes_platform -y || echo "Failed to enable localnodes_platform"

# Enable instance-specific demo content module
DEMO_MODULE="${DEMO_MODULE:-localnodes_demo}"
echo "Enabling demo module: $DEMO_MODULE..."
$DRUSH en "$DEMO_MODULE" -y || echo "Failed to enable $DEMO_MODULE"

# Load demo content
echo "Loading demo content..."
$DRUSH social-demo:add file user group topic event event_enrollment comment post like || echo "Failed to add demo content"

# Index content in Solr
echo "Indexing content in Solr..."
$DRUSH search-api:index || echo "Failed to index content"

# Run cron to trigger vector indexing
echo "Running cron for vector indexing..."
$DRUSH cron || echo "Failed to run cron"
```

### Dockerfile.solr for Solr 9
```dockerfile
# Source: Existing Dockerfile.solr updated for Solr 9
FROM solr:9

USER root

# Create the configset directory (use 'drupal' to match solr-precreate core name)
RUN mkdir -p /opt/solr/server/solr/configsets/drupal/conf

# Copy search_api_solr generated configset
COPY ./solr-config/ /opt/solr/server/solr/configsets/drupal/conf/

# Ensure the solr user owns the config directory
RUN chown -R solr:solr /opt/solr/server/solr/configsets/drupal

USER solr
```

### Coolify Application Creation via API
```bash
# Source: Coolify API docs + existing deployment pattern
# Create Cascadia instance
gh api -X POST "https://your-coolify-instance/api/v1/applications" \
  -f "project_uuid=n0sc0wcog8k4kkc48k0k0wo0" \
  -f "server_uuid=q4okokcg8occ88w00c4kg0sw" \
  -f "environment_name=production" \
  -f "git_repository=proofoftom/open-social-coolify" \
  -f "git_branch=localnodes" \
  -f "build_pack=dockercompose" \
  -f "name=cascadia"
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Solr 8.11 in deploy repo | Solr 9 (matching DDEV/search_api_solr) | search_api_solr 4.3+ supports Solr 9 natively | Better performance, current configset compatibility |
| Vanilla Open Social deploy | LocalNodes with AI stack | This phase | Adds Qdrant, Gemini, custom modules |
| Manual domain/SSL config | Coolify auto Let's Encrypt via `SERVICE_FQDN_` | Coolify v4 beta.411+ | Zero SSL management effort |
| Single demo instance | Two instances with env-driven differentiation | This phase | Same codebase, different content |

**Important architectural note:**
- Web3/SIWE modules (siwe_login, safe_smart_accounts, group_treasury, social_group_treasury) MUST be kept in the deployment. The LocalNodes vision encompasses both "agentic x federated bioregional knowledge commons AND web2.5 onchain bioregional financing facilities." Group treasuries are a step toward governance proposals and Zodiac/DAO snapshot voting. See: https://omniharmonic.substack.com/p/the-infrastructure-of-belonging
- The SIWE expected domain config must be set dynamically per instance using `SERVICE_FQDN_OPENSOCIAL` env var.

**Deprecated/outdated:**
- Solr 8.11 in deploy repo: Should be upgraded to Solr 9 to match DDEV config and current search_api_solr expectations
- The existing `open-social-coolify` deploy repo on `test-composer-2` branch has no AI modules -- it's a vanilla Open Social that needs significant updating

## Open Questions

1. **Embedding Generation on First Deploy**
   - What we know: Vector embeddings are generated by the AI module's queue system, triggered by cron. The Gemini embedding model (`gemini-embedding-001`) generates 768-dimension vectors stored in Qdrant.
   - What's unclear: How long will initial embedding generation take for ~120 demo content entities per instance? Will it complete in a single cron run or need multiple?
   - Recommendation: Run `drush cron` multiple times in the entrypoint, or add a loop that runs cron until the queue is empty. For demo purposes, partial indexing is acceptable on first boot.

2. **Deploy Repo Strategy: Fork or Update?**
   - What we know: The existing `open-social-coolify` repo is public on GitHub. The `test-composer-2` branch is the running deployment. The `main` branch has vendor committed.
   - What's unclear: Should we create a new branch (`localnodes`) on the existing repo, or create a new repo?
   - Recommendation: Create a `localnodes` branch on the existing repo. This preserves the deployment history and Coolify's existing GitHub App connection (source_id: 2 for the `gc4cg8gcck0scko40skc4kkc` app).

3. **Existing Deployments Cleanup**
   - What we know: There are two existing Open Social deployments -- `gc4cg8gcck0scko40skc4kkc` (running, demo.localnodes.xyz) and `tkgc48gcw8gsoos8o8c08soc` (exited/unhealthy). They sit in the "New Project" project, not the "LocalNodes" project.
   - What's unclear: Should the existing deployments be stopped/removed, or left running alongside the new ones?
   - Recommendation: Leave existing deployments alone during initial setup. Clean up after new instances are verified working.

4. **Shared Gemini API Key**
   - What we know: Both instances need the same Gemini API key. Coolify supports shared variables at the project level.
   - What's unclear: Whether Coolify's shared variables work correctly with docker-compose build pack env vars.
   - Recommendation: Set `GEMINI_API_KEY` directly on each application via Coolify API env vars. Avoid shared variables complexity for now.

5. **Server Resource Constraints**
   - What we know: Running two instances means 8 containers total (2x opensocial, 2x mariadb, 2x solr, 2x qdrant) plus existing services on the server.
   - What's unclear: Whether the Hetzner server has sufficient RAM/CPU for all these containers simultaneously.
   - Recommendation: Set memory limits in docker-compose.yml. Qdrant and Solr each need ~512MB. MariaDB needs ~256MB. The PHP container needs ~512MB. Total per instance: ~1.8GB. Check server resources before deploying both instances.

## Sources

### Primary (HIGH confidence)
- Coolify infrastructure API -- live data from `mcp__coolify__get_infrastructure_overview`, `get_application`, `env_vars`, `server_domains`
- Existing `proofoftom/open-social-coolify` GitHub repo -- Dockerfile, docker-compose.yml, entrypoint.sh, Dockerfile.solr (fetched via `gh api`)
- Existing running deployment `gc4cg8gcck0scko40skc4kkc` -- full docker-compose config, env vars, status
- DDEV config files from fresh3 project -- `.ddev/config.yaml`, `docker-compose.solr.yaml`, `docker-compose.qdrant.yaml`
- `localnodes_platform` install hooks -- environment variable override pattern for Solr/Qdrant/Gemini
- `key.key.gemini_api_key.yml` config -- env-based key provider pattern

### Secondary (MEDIUM confidence)
- [Coolify Docker Compose docs](https://coolify.io/docs/applications/build-packs/docker-compose) -- build pack configuration, magic env vars, storage
- [Coolify Knowledge Base: Docker Compose](https://coolify.io/docs/knowledge-base/docker/compose) -- raw deployment, environment vars, predefined networks
- [Coolify Domains docs](https://coolify.io/docs/knowledge-base/domains) -- FQDN format, auto SSL, DNS validation
- [Coolify Wildcard SSL docs](https://coolify.io/docs/knowledge-base/proxy/traefik/wildcard-certs) -- Traefik DNS challenge setup
- [Qdrant Installation docs](https://qdrant.tech/documentation/guides/installation/) -- Docker Compose config, persistent storage requirements
- DNS verification -- `dig` confirms `*.localnodes.xyz` wildcard resolves via Cloudflare

### Tertiary (LOW confidence)
- [Drupal Dockerize module](https://www.drupal.org/project/dockerize) -- general Drupal containerization patterns (not directly used but informed approach)
- [DigitalOcean Drupal Docker Compose guide](https://www.digitalocean.com/community/tutorials/how-to-install-drupal-with-docker-compose) -- general patterns
- Community consensus on vendor-committed vs multi-stage build -- multiple sources agree multi-stage is "best practice" but vendor-committed is valid for complex patching scenarios

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - Based on existing working deployment + DDEV config, not speculation
- Architecture: HIGH - Extending proven patterns (existing entrypoint.sh, compose file, Coolify integration)
- Pitfalls: HIGH - Most pitfalls identified from existing deployment issues (duplicate env vars observed in live data) and DDEV experience (Qdrant healthcheck pattern)
- Open questions: MEDIUM - Server resources and embedding timing need runtime validation

**Research date:** 2026-02-28
**Valid until:** 2026-03-28 (stable infrastructure, unlikely to change rapidly)
