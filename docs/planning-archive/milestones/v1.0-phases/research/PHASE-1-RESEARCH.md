# Phase 1: AI Infrastructure - Research

**Researched:** 2026-02-23
**Domain:** Drupal AI Module Ecosystem / Deepseek Integration / Vector Databases
**Confidence:** HIGH (verified against drupal.org project pages, official docs, and community sources)

## Summary

Phase 1 establishes the AI infrastructure foundation for the Open Social Knowledge Gardens. The core stack consists of the Drupal AI module (dev 1.3.x), ai_provider_deepseek for LLM capabilities, ai_provider_ollama for local embeddings, and Milvus as the vector database. 

**Critical Discovery:** The ai_provider_deepseek module provides only chat/LLM capabilities—it does NOT include embedding support. A separate embedding provider is required. 

**Decision:** Use Ollama (local) for embeddings instead of OpenAI. Benefits: free, private, no rate limits, data stays local. Requires running `ollama pull nomic-embed-text` locally.

**Implemented Stack:** Drupal AI core with Deepseek provider for chat, Ollama provider for embeddings, and Milvus via DDEV add-on for vector storage.

---

<phase_requirements>

## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| AI-01 | Drupal AI core module (ai) is installed and configured with Deepseek provider | ai module ^1.2 stable, ai_provider_deepseek ^1.0 — both verified on drupal.org |
| AI-02 | AI Agents module (ai_agents) is installed and provides agent framework | ai_agents ^1.2 stable with security coverage |
| AI-03 | Vector database (Milvus) is deployed and connected via ai_search module | ai_vdb_provider_milvus ^1.1@beta, DDEV add-on available |
| AI-04 | Embedding generation pipeline is configured for content indexing | Requires separate embedding provider (OpenAI or Ollama) — Deepseek only provides chat |
| AI-05 | Deepseek API integration is tested with fallback/retry logic for rate limits | ai_usage_limits module, AiRateLimitException handling built into AI module |

</phase_requirements>

---

## Standard Stack

### Core AI Framework

| Module | Version | Stability | Purpose | Why Standard |
|--------|---------|-----------|---------|--------------|
| **drupal/ai** | dev-1.3.x | **STABLE** (security covered) | Core AI abstraction layer | 11,313+ sites, official framework |
| **drupal/ai_agents** | dev-1.3.x | **STABLE** (security covered) | Agent framework for tool-calling | 6,780+ sites, integrates with AI core |
| **drupal/ai_provider_deepseek** | dev-1.x | STABLE (no security coverage) | Deepseek LLM for chat/generation | Matches user's provider choice |
| **drupal/ai_provider_ollama** | dev-1.2.x | STABLE | Local embeddings via Ollama | **CHOSEN** — Free, private, no rate limits |
| **drupal/key** | ^1.19 | STABLE | API key management | Required by AI providers |

### Vector Database

| Module | Version | Stability | Purpose | Why Standard |
|--------|---------|-----------|---------|--------------|
| **drupal/ai_vdb_provider_milvus** | ^1.1@beta | BETA | Milvus vector database integration | Highest adoption (475 sites), maintained by core AI team |
| **drupal/ai_search** | ^2.0@alpha | ALPHA | RAG backend, Search API integration | Required for semantic search |

### Supporting Modules

| Module | Version | Stability | Purpose | When to Use |
|--------|---------|-----------|---------|-------------|
| drupal/ai_usage_limits | ^1.0 | STABLE | Token/rate limiting | Required for production |
| drupal/search_api | ^1.35 | STABLE | Search framework | Required by ai_search |

### Alternatives Considered

| Instead of | Could Use | Tradeoff | Decision |
|------------|-----------|----------|----------|
| **Ollama embeddings (CHOSEN)** | OpenAI embeddings | API costs, rate limits, data leaves local | Ollama selected for privacy/cost |
| Ollama embeddings | Deepseek embeddings | Not available via ai_provider_deepseek module | N/A |
| Milvus | PostgreSQL pgvector | Alpha quality module, limited scale | Milvus selected |
| Milvus | SQLite | Stable but very low adoption (25 sites), not production-ready | Milvus selected |

---

## Architecture

### Phase 1 Component Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           DRUPAL AI ECOSYSTEM                                │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                    AI Module (Core Framework)                       │    │
│  │  - Provider Abstraction                                             │    │
│  │  - Embeddings API                                                   │    │
│  │  - Chat API                                                         │    │
│  │  - Function Calling                                                 │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│           │                        │                        │                │
│           ▼                        ▼                        ▼                │
│  ┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐          │
│  │ Deepseek        │    │ Ollama          │    │ AI Usage        │          │
│  │ Provider        │    │ Provider        │    │ Limits          │          │
│  │ (Chat/LLM)      │    │ (Embeddings)    │    │ (Rate Control)  │          │
│  └────────┬────────┘    └────────┬────────┘    └─────────────────┘          │
│           │                      │                                          │
│           │                      ▼                                          │
│           │             ┌─────────────────┐                                │
│           │             │ AI Search       │                                │
│           │             │ (RAG Backend)   │                                │
│           │             └────────┬────────┘                                │
│           │                      │                                          │
│           │                      ▼                                          │
│           │             ┌─────────────────┐                                │
│           │             │ Milvus VDB      │                                │
│           │             │ Provider        │                                │
│           │             └────────┬────────┘                                │
│           │                      │                                          │
├───────────┴──────────────────────┴──────────────────────────────────────────┤
│                           EXTERNAL SERVICES                                  │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐          │
│  │ Deepseek API    │    │ Ollama (Local)  │    │ Milvus DB       │          │
│  │ api.deepseek.com│    │ localhost:11434 │    │ (DDEV/Cloud)    │          │
│  │ Chat: V3/R1     │    │ nomic-embed-text│    │ Port: 19530     │          │
│  └─────────────────┘    └─────────────────┘    └─────────────────┘          │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Data Flow: Embedding Generation

```
Content Created/Updated
         │
         ▼
┌─────────────────┐
│ Search API      │  (tracks content changes)
│ Track Items     │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ AI Search       │  (processes content for indexing)
│ Processor       │  - Chunks text
└────────┬────────┘  - Generates embeddings via OpenAI
         │
         ▼
┌─────────────────┐
│ Milvus VDB      │  (stores vectors + metadata)
│ Provider        │  { chunk_id, embedding, metadata }
└─────────────────┘
```

---

## Installation Commands

### 1. Core AI Modules

```bash
# Navigate to Drupal root
cd html

# Core AI framework (stable, security covered)
composer require 'drupal/ai:^1.2'

# AI Agents module (stable, security covered)
composer require 'drupal/ai_agents:^1.2'

# Key module for API key management (stable)
composer require 'drupal/key:^1.19'

# Deepseek provider for chat/LLM (stable, no security coverage)
composer require 'drupal/ai_provider_deepseek:^1.0'

# OpenAI provider for embeddings (stable, security covered)
composer require 'drupal/ai_provider_openai:^1.2'
```

### 2. Vector Database & Search

```bash
# Milvus VDB provider (beta)
composer require 'drupal/ai_vdb_provider_milvus:^1.1@beta'

# AI Search for RAG (alpha - accept quality risk)
composer require 'drupal/ai_search:^2.0@alpha'

# Search API (stable, likely already installed)
composer require 'drupal/search_api:^1.35'

# Usage limits for rate control
composer require 'drupal/ai_usage_limits:^1.0'
```

### 3. Enable Modules

```bash
# Enable all AI modules
drush en ai ai_agents key ai_provider_deepseek ai_provider_openai ai_vdb_provider_milvus ai_search search_api ai_usage_limits -y
```

**Enablement Order:**
1. `key` (dependency for providers)
2. `ai` (core framework)
3. `ai_provider_deepseek` and `ai_provider_openai` (providers)
4. `ai_vdb_provider_milvus` (VDB provider)
5. `ai_search` (search integration)
6. `ai_agents` (agent framework)
7. `ai_usage_limits` (rate control)

---

## Configuration Details

### Step 1: Create API Keys

Navigate to `/admin/config/system/keys/add`

**Deepseek Key:**
- Key name: `Deepseek API Key`
- Key provider: `Configuration` (for dev) or `Environment` (for production)
- Key value: Your Deepseek API key from [platform.deepseek.com](https://platform.deepseek.com)

**OpenAI Key (for embeddings):**
- Key name: `OpenAI API Key`
- Key provider: `Configuration` or `Environment`
- Key value: Your OpenAI API key from [platform.openai.com](https://platform.openai.com)

### Step 2: Configure Deepseek Provider

Navigate to `/admin/config/ai/providers/deepseek`

- **API Key:** Select the Deepseek API Key created in Step 1
- **Default Chat Model:** `deepseek-chat` or `deepseek-reasoner` (R1)
- **Default Temperature:** `0.7` (adjust as needed)

**Available Models:**
| Model | Purpose | Notes |
|-------|---------|-------|
| deepseek-chat | General chat/generation | Fast, cost-effective |
| deepseek-reasoner | Complex reasoning | R1 model, slower but more accurate |

### Step 3: Configure OpenAI Provider (for Embeddings)

Navigate to `/admin/config/ai/providers/openai`

- **API Key:** Select the OpenAI API Key created in Step 1
- **Embedding Model:** `text-embedding-3-small` (recommended) or `text-embedding-3-large`

**Embedding Model Comparison:**
| Model | Dimensions | Cost (per 1M tokens) | Quality |
|-------|------------|---------------------|---------|
| text-embedding-3-small | 1536 | $0.02 | Good |
| text-embedding-3-large | 3072 | $0.13 | Best |

### Step 4: Configure Milvus VDB Provider

Navigate to `/admin/config/ai/vdb_providers/milvus`

**Local DDEV Setup:**
- **Host:** `milvus` (DDEV service name)
- **Port:** `19530`
- **Database:** `default`
- **Authentication:** None (local dev) or token (production)

**Zilliz Cloud (Production Alternative):**
- **Host:** Your Zilliz Cloud endpoint
- **Port:** `443`
- **Database:** `default`
- **Token:** Your Zilliz API token

### Step 5: Configure AI Search

Navigate to `/admin/config/search/search-api/add-server`

1. Create new server:
   - **Server name:** `AI Knowledge Garden`
   - **Backend:** `AI Search`
   
2. Configure backend:
   - **Vector Database Provider:** `Milvus`
   - **Embedding Provider:** `OpenAI`
   - **Embedding Model:** `text-embedding-3-small`
   - **Chunk Size:** `512` tokens
   - **Chunk Overlap:** `50` tokens (10%)

### Step 6: Configure AI Usage Limits

Navigate to `/admin/config/ai/usage-limits`

- **Enable limits per role**
- **Set daily/monthly token limits** based on your budget
- **Configure alert thresholds** (e.g., 80% of limit)

---

## Milvus Deployment

### Option A: DDEV Add-on (Recommended for Development)

```bash
# Add AI services including Milvus to DDEV
ddev add-on get lpeabody/ddev-ai

# Restart DDEV to apply changes
ddev restart

# Start Milvus specifically
ddev start --profiles='milvus'

# Verify Milvus is running
ddev describe
```

**Environment Variables (add to `.ddev/.env`):**
```
COMPOSE_PROFILES=milvus
```

**Milvus Connection from Drupal:**
- Host: `milvus` (DDEV internal hostname)
- Port: `19530`

### Option B: Docker Compose (Self-hosted)

Create `docker-compose.milvus.yml`:

```yaml
version: '3.8'

services:
  etcd:
    image: quay.io/coreos/etcd:v3.5.5
    environment:
      - ETCD_AUTO_COMPACTION_MODE=revision
      - ETCD_AUTO_COMPACTION_RETENTION=1000
    volumes:
      - milvus_etcd:/etcd

  minio:
    image: minio/minio:RELEASE.2023-03-20T20-16-18Z
    environment:
      MINIO_ACCESS_KEY: minioadmin
      MINIO_SECRET_KEY: minioadmin
    volumes:
      - milvus_minio:/minio_data
    command: minio server /minio_data

  milvus:
    image: milvusdb/milvus:v2.3.3
    command: ["milvus", "run", "standalone"]
    environment:
      ETCD_ENDPOINTS: etcd:2379
      MINIO_ADDRESS: minio:9000
    volumes:
      - milvus_data:/var/lib/milvus
    ports:
      - "19530:19530"
    depends_on:
      - etcd
      - minio

volumes:
  milvus_etcd:
  milvus_minio:
  milvus_data:
```

### Option C: Zilliz Cloud (Production)

- Sign up at [zilliz.com](https://zilliz.com)
- Create a cluster (free tier available)
- Get endpoint URL and API token
- Configure in Drupal at `/admin/config/ai/vdb_providers/milvus`

---

## Rate Limiting & Fallback

### Built-in Rate Limit Handling

The Drupal AI module includes:

1. **AiRateLimitException** - Automatically thrown when provider rate limits are hit
2. **AI Usage Limits module** - Configure token limits per role/provider
3. **Retry logic in AI Automators** - Automatic retry for transient failures

### Configure Fallback Provider

To set up a fallback when Deepseek is unavailable:

1. Install additional provider (e.g., `ai_provider_openai` for chat)
2. Navigate to `/admin/config/ai/providers`
3. Configure provider priority/weights
4. Enable "Fallback to next provider" option

### Queue-Based Processing (Recommended)

For embedding generation at scale:

1. Use Drupal's Queue API for batch embedding jobs
2. Process queue via `drush queue:run` or cron
3. Configure queue worker concurrency based on API rate limits

---

## AI Agents Module

### Basic Configuration

After enabling `ai_agents`:

1. Navigate to `/admin/config/ai/agents`
2. Configure default agent settings:
   - **Default LLM Provider:** `deepseek`
   - **System Prompt:** Define agent behavior
   - **Max Tool Calls:** `10` (prevent infinite loops)

### What "Working Agent Framework" Looks Like

1. **Admin UI:** `/admin/config/ai/agents` shows available agents
2. **Tool Registration:** Navigate to `/admin/config/ai/tools` to see available tools
3. **Test Agent:** Create a test assistant at `/admin/ai/assistant` and verify it can:
   - Accept natural language input
   - Call configured tools
   - Return structured responses

### Immediate Configuration Needed

- **None required for Phase 1** - Module just needs to be enabled
- Agents will be configured in later phases for Group-specific assistants

---

## Open Social Compatibility

### Verified Compatibility

| Component | Version | Status |
|-----------|---------|--------|
| Open Social | 13.x | ✅ Compatible with Drupal 10/11 |
| Drupal AI modules | 1.2.x | ✅ Designed for Drupal 10.4+ / 11 |
| PHP | 8.3 | ✅ Required by Open Social 13.x |

### No Known Conflicts

- Open Social 13.x uses standard Drupal entity types
- AI modules integrate via hooks and services (no conflicts expected)
- Group module integration will be addressed in Phase 3

### Permissions Setup

After enabling AI modules:

1. Navigate to `/admin/people/permissions`
2. Configure AI-related permissions:
   - `use ai chatbot` - For chat interface access
   - `administer ai` - For admin configuration
   - `configure ai agents` - For agent management

3. **Recommendation:** Initially restrict AI access to administrators, expand to Group members in Phase 5

---

## Pre-flight Checklist

Before starting Phase 1 implementation:

- [ ] **Deepseek API Key** obtained from platform.deepseek.com
- [ ] **OpenAI API Key** obtained from platform.openai.com (for embeddings)
- [ ] **DDEV** installed and project running
- [ ] **Composer** available for package installation
- [ ] **Drush** available for module enabling
- [ ] **Backup** of database taken before module installation
- [ ] **PHP 8.3** confirmed (check `php -v`)
- [ ] **Drupal 10.4+ or 11** confirmed (check `/admin/reports/status`)

---

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| ai_provider_deepseek no security coverage | MEDIUM | MEDIUM | Monitor drupal.org security advisories, update promptly |
| ai_search module is alpha quality | HIGH | MEDIUM | Test thoroughly, report bugs, have fallback plan |
| Deepseek API rate limits hit during testing | MEDIUM | LOW | Implement usage limits, use queue for batch operations |
| Milvus beta module issues | MEDIUM | MEDIUM | Test locally first, consider Zilliz Cloud for production |
| OpenAI embedding costs exceed budget | LOW | MEDIUM | Set usage limits, consider Ollama for local embeddings |

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| API key storage | Custom config | Key module | Security, encryption, environment variable support |
| Rate limiting | Custom middleware | ai_usage_limits module | Built-in integration, per-role limits |
| Vector DB connection | Custom Milvus client | ai_vdb_provider_milvus | Handles connection pooling, error handling |
| Embedding generation | Custom API calls | ai_search module | Chunking, caching, Search API integration |
| Retry logic | Custom loops | AI module built-in | Exponential backoff, dead letter queues |

---

## Common Pitfalls

### Pitfall 1: Assuming Deepseek Provides Embeddings

**What goes wrong:** Developer enables ai_provider_deepseek and expects it to work for embedding generation in ai_search.

**Why it happens:** The module name suggests complete Deepseek integration, but it only provides chat/LLM capabilities.

**How to avoid:** Install a separate embedding provider (OpenAI or Ollama) alongside Deepseek. Configure ai_search to use the embedding provider for embeddings and Deepseek for chat.

### Pitfall 2: Milvus Not Starting in DDEV

**What goes wrong:** `ddev start` succeeds but Milvus isn't accessible at port 19530.

**Why it happens:** The Milvus profile isn't enabled by default.

**How to avoid:** Run `ddev start --profiles='milvus'` or add `COMPOSE_PROFILES=milvus` to `.ddev/.env`

### Pitfall 3: API Keys in Code/Config Export

**What goes wrong:** API keys end up in version control via config export.

**Why it happens:** Using "Configuration" key provider stores keys in database, which gets exported.

**How to avoid:** Use "Environment" key provider and store keys in `.env` file (not committed to git).

### Pitfall 4: Module Enablement Order Issues

**What goes wrong:** Enabling ai_search before ai_vdb_provider_milvus causes configuration errors.

**Why it happens:** Dependency chain isn't always enforced during `drush en`.

**How to avoid:** Follow the enablement order specified in this document.

---

## Testing Verification

### Verify AI Core

```bash
# Test AI module is working
drush eval "echo \Drupal::service('ai.provider')->getAvailableProviders();"
```

### Verify Deepseek Provider

1. Navigate to `/admin/config/ai/providers/deepseek`
2. Click "Test Connection"
3. Send a test prompt: "Hello, respond with 'OK'"

### Verify OpenAI Embeddings

1. Navigate to `/admin/config/ai/providers/openai`
2. Test embedding generation with sample text
3. Verify response includes embedding vector

### Verify Milvus Connection

```bash
# Check Milvus is running
ddev logs -s milvus | head -20

# Test connection from Drupal
drush eval "echo \Drupal::service('ai.vdb_provider_milvus')->testConnection();"
```

### Verify AI Search Index

1. Navigate to `/admin/config/search/search-api`
2. Create test index using AI Search backend
3. Index sample content
4. Verify embeddings stored in Milvus

---

## Sources

### Primary (HIGH confidence)
- drupal.org/project/ai - AI module official page
- drupal.org/project/ai_provider_deepseek - Deepseek provider (verified 1.0.0)
- drupal.org/project/ai_provider_openai - OpenAI provider
- drupal.org/project/ai_vdb_provider_milvus - Milvus VDB provider
- drupal.org/project/ai_search - AI Search module
- drupal.org/project/ai_agents - AI Agents module
- project.pages.drupalcode.org/ai - Official AI module documentation

### Secondary (MEDIUM confidence)
- DDEV add-on: github.com/lpeabody/ddev-ai
- Milvus documentation: milvus.io
- Deepseek API: platform.deepseek.com
- OpenAI API: platform.openai.com

### Tertiary (LOW confidence)
- Community forum discussions on embedding provider choices
- Blog posts on Drupal AI implementation patterns

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - Verified on drupal.org, official releases
- Installation commands: HIGH - Standard composer/drush patterns
- Configuration details: MEDIUM - Based on documentation, needs testing
- Pitfalls: HIGH - Common issues documented in issue queues

**Research date:** 2026-02-23
**Valid until:** 2026-03-23 (30 days - Drupal AI ecosystem is evolving rapidly)
