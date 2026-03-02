# Stack Research: Drupal AI Knowledge Gardens

**Domain:** AI-powered knowledge garden for Open Social (Drupal)
**Researched:** 2026-02-23
**Confidence:** MEDIUM (AI ecosystem rapidly evolving, many modules in alpha/beta)

## Executive Summary

The Drupal AI ecosystem has matured significantly in 2025 with the AI module reaching stable release (1.2.x) and security advisory coverage. However, the AI Search and Vector Database provider ecosystem remains largely in alpha/beta stages. For an Open Social knowledge garden with RAG, vector search, and agent capabilities, the recommended approach is to use the stable AI Core + AI Agents modules, paired with the most mature VDB provider (Milvus) while accepting beta-stage risk for search functionality.

**Critical Finding:** Neo4j integration is effectively abandoned (last updated 2017) and should NOT be pursued. Graph capabilities are not production-ready in the Drupal ecosystem.

---

## Recommended Stack

### Core AI Framework

| Module | Version | Stability | Purpose | Why |
|--------|---------|-----------|---------|-----|
| **drupal/ai** | ^1.2 | **STABLE** (security covered) | Core AI abstraction layer, provider management, embeddings API | Production-ready, 11,313 sites using it, security advisory coverage, provides unified API for all AI operations |
| **drupal/ai_agents** | ^1.2 | **STABLE** (security covered) | Agent framework for text-to-action capabilities | Production-ready, 6,780 sites, enables Group-specific agents that can manipulate content based on natural language |
| **drupal/ai_provider_deepseek** | ^1.0 | STABLE (no security coverage) | Deepseek LLM integration | Only 96 sites using it, but matches user's LLM choice. Requires monitoring due to no security advisory coverage |
| **drupal/key** | ^1.19 | STABLE | API key management | Required dependency for AI module, stores Deepseek API credentials securely |

### Vector Database (Choose One)

| Module | Version | Stability | Sites | Recommendation |
|--------|---------|-----------|-------|----------------|
| **drupal/ai_vdb_provider_milvus** | ^1.1@beta | BETA | 475 | **RECOMMENDED** - Highest adoption, supports self-hosted Milvus or Zilliz Cloud, maintained by core AI team (FreelyGive, Soapbox) |
| drupal/ai_vdb_provider_pinecone | ^1.1@beta | BETA | 203 | Alternative if cloud-managed Pinecone preferred, serverless only |
| drupal/ai_vdb_provider_postgres | ^1.0@alpha | ALPHA | 270 | Uses pgvector - attractive if already on PostgreSQL, but alpha quality |
| drupal/ai_vdb_provider_sqlite | ^1.0 | **STABLE** | 25 | Only stable VDB provider, but very low adoption and requires sqlite-vec extension |

### Search & RAG

| Module | Version | Stability | Purpose | Notes |
|--------|---------|-----------|---------|-------|
| **drupal/ai_search** | ^2.0@alpha | ALPHA | Semantic vector search, RAG integration, Search API backend | Extracted from AI Core as standalone module. Alpha quality but active development by core team. Supports chunking strategies, hybrid search with Solr boost |
| drupal/search_api | ^1.35 | STABLE | Search framework | Required by AI Search, already familiar from Solr integration |
| drupal/search_api_solr | ^4.3 | STABLE | Solr backend | Already in use, can be combined with AI Search via boost processors for hybrid search |

### Supporting Modules (Optional)

| Module | Purpose | When to Use |
|--------|---------|-------------|
| drupal/ai_ckeditor | AI assistant in CKEditor 5 | If content editors want AI help writing Group content |
| drupal/ai_content | Content assistance tools | If content moderation, summarization, taxonomy suggestions needed |
| drupal/ai_logging | Request/response logging | Essential for debugging AI interactions and cost tracking |

---

## Module Stability Matrix

| Module | Release | Security Coverage | Sites | Confidence |
|--------|---------|-------------------|-------|------------|
| ai | 1.2.9 STABLE | ✅ Yes | 11,313 | **HIGH** |
| ai_agents | 1.2.2 STABLE | ✅ Yes | 6,780 | **HIGH** |
| ai_search | 2.0.0-alpha1 | ⚠️ No | 13 | **MEDIUM** |
| ai_provider_deepseek | 1.0.0 | ❌ No | 96 | **MEDIUM** |
| ai_vdb_provider_milvus | 1.1.0-beta3 | ⚠️ No | 475 | **MEDIUM** |
| ai_vdb_provider_pinecone | 1.1.0-beta3 | ⚠️ No | 203 | **MEDIUM** |
| ai_vdb_provider_postgres | 1.0.0-alpha2 | ❌ No | 270 | **LOW** |
| ai_vdb_provider_sqlite | 1.0.0 | ✅ Yes | 25 | **MEDIUM** |
| search_api_solr_densenumbervector | 1.0.0-alpha8 | ❌ No | - | **LOW** |

---

## Installation

```bash
# Core AI stack (stable)
composer require 'drupal/ai:^1.2'
composer require 'drupal/ai_agents:^1.2'
composer require 'drupal/ai_provider_deepseek:^1.0'
composer require 'drupal/key:^1.19'

# Search & RAG (accept alpha/beta risk)
composer require 'drupal/ai_search:^2.0@alpha'
composer require 'drupal/search_api:^1.35'

# Vector Database Provider (choose one)
# Option A: Milvus (recommended)
composer require 'drupal/ai_vdb_provider_milvus:^1.1@beta'

# Option B: SQLite (if stable-only required, limited scale)
composer require 'drupal/ai_vdb_provider_sqlite:^1.0'

# Enable modules
drush en ai ai_agents ai_provider_deepseek ai_search ai_vdb_provider_milvus key search_api
```

### Post-Installation Configuration

1. **Add Deepseek API Key:**
   - Navigate to `/admin/config/system/keys/add`
   - Create new key with Deepseek API credentials

2. **Configure Deepseek Provider:**
   - Navigate to `/admin/config/ai/providers/deepseek`
   - Associate the API key

3. **Configure VDB Provider:**
   - Navigate to `/admin/config/ai/vdb_providers/milvus` (or chosen provider)
   - Set connection details (self-hosted Milvus or Zilliz Cloud)

4. **Create Search API Server:**
   - Navigate to `/admin/config/search/search-api/add-server`
   - Select "AI Search" as backend
   - Configure vector database and embedding settings

---

## Vector Database Decision Matrix

| Criteria | Milvus | Pinecone | PostgreSQL | SQLite |
|----------|--------|----------|------------|--------|
| **Stability** | Beta | Beta | Alpha | **Stable** |
| **Adoption** | 475 sites | 203 sites | 270 sites | 25 sites |
| **Scalability** | High (distributed) | High (managed) | Medium | Low |
| **Self-hosted** | ✅ Yes | ❌ No | ✅ Yes | ✅ Yes |
| **Cloud option** | ✅ Zilliz Cloud | ✅ Serverless | ✅ Most hosts | ❌ File-based |
| **Maintenance burden** | Medium | Low | Low | Very Low |
| **Per-group indexes** | ✅ Namespaces | ✅ Namespaces | ⚠️ Manual | ❌ Limited |
| **Production ready?** | ⚠️ With monitoring | ⚠️ With monitoring | ❌ Not recommended | ⚠️ Small scale only |

**Recommendation:** Use **Milvus** for production knowledge gardens. It has the highest adoption, strongest community support (maintained by core AI team), and supports both self-hosted and cloud options. The namespace feature maps well to Group-scoped knowledge bases.

---

## What NOT to Use

| Avoid | Why | Use Instead |
|-------|-----|-------------|
| **Neo4j Connector** | Abandoned since 2017, no Drupal 10/11 support, security vulnerabilities likely | Accept no graph DB for v1; vector embeddings provide semantic similarity which may be sufficient |
| **search_api_solr_densenumbervector** | Alpha quality, limited to single vector per entity (no chunking), loses semantic meaning on long content | ai_search module with dedicated VDB provider |
| **PostgreSQL pgvector (for production)** | Alpha quality module, schema migration issues noted in roadmap | Milvus or wait for stable release |
| **OpenAI Embeddings (if cost-sensitive)** | Requires OpenAI API in addition to Deepseek | Use Deepseek for embeddings via ai_provider_deepseek |

---

## Chunking Strategy for Drupal Content

The ai_search module provides built-in chunking strategies:

| Strategy | Description | Best For |
|----------|-------------|----------|
| **Contextual Chunks** (Recommended) | Enriches each chunk with surrounding context | Group posts, discussions where context matters |
| Average Pool Embedding | Single composite vector from multiple chunks | Short content, quick similarity |
| Custom | Extend base class for domain-specific splitting | Documents with clear structure (chapters, sections) |

**Recommended Settings:**
- Chunk size: 256-512 tokens
- Overlap: 10-20% (preserves context across boundaries)
- Include metadata: Content type, Group ID, author, taxonomy terms

---

## RAG Implementation Pattern

```
┌─────────────────────────────────────────────────────────────┐
│                     User Query                              │
│                 "What did our group decide about X?"        │
└─────────────────────┬───────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────┐
│              AI Search (Vector Retrieval)                   │
│   - Query embedding via Deepseek                            │
│   - Vector similarity search in Milvus                      │
│   - Filter by user's accessible Groups                      │
│   - Return top-k relevant chunks                            │
└─────────────────────┬───────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────┐
│                 RAG Tool (ai_search)                        │
│   - Assemble context from retrieved chunks                  │
│   - Inject into prompt with user question                   │
│   - Call Deepseek for generation                            │
└─────────────────────┬───────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────┐
│              AI Response                                    │
│   "Based on your group's discussion from [date]..."         │
└─────────────────────────────────────────────────────────────┘
```

**Key Integration Points:**
1. `RagTool` plugin - exposes RAG capability to AI Agents
2. `RagAction` - integrates with Chatbot/Assistant API
3. Post-query access checks - ensures users only see authorized content

---

## Version Compatibility

| Package | Compatible With | Notes |
|---------|-----------------|-------|
| ai ^1.2 | Drupal ^10.4 \|\| ^11 | PHP 8.1+ required |
| ai_agents ^1.2 | Drupal ^10.3 \|\| ^11 | Requires ai module |
| ai_search ^2.0 | Drupal ^10.4 \|\| ^11 | Requires ai ^2.0.x |
| ai_vdb_provider_milvus ^1.1 | Drupal ^10.2 \|\| ^11 | Milvus 2.x or Zilliz Cloud |
| search_api_solr ^4.3 | Drupal ^10.2 \|\| ^11 | Solr 9.6+ for vector support |

---

## Sources

- **drupal.org/project/ai** — AI module official page, verified stable 1.2.9 with security coverage
- **drupal.org/project/ai_agents** — AI Agents module, verified stable 1.2.2 with security coverage
- **drupal.org/project/ai_search** — AI Search module, verified 2.0.0-alpha1 status
- **drupal.org/project/ai_provider_deepseek** — Deepseek provider, verified 1.0.0 (no security coverage)
- **drupal.org/project/ai_vdb_provider_milvus** — Milvus VDB provider, verified 1.1.0-beta3
- **drupal.org/project/ai_vdb_provider_postgres** — PostgreSQL VDB provider, verified 1.0.0-alpha2
- **drupal.org/project/ai_vdb_provider_sqlite** — SQLite VDB provider, verified 1.0.0 stable
- **drupal.org/project/ai_vdb_provider_pinecone** — Pinecone VDB provider, verified 1.1.0-beta3
- **drupal.org/project/neo4j_connector** — Neo4j integration, verified abandoned (2017)
- **velir.com** — Search API Solr Dense Vector Field module information
- **project.pages.drupalcode.org/ai** — Official AI module documentation

---

## Confidence Assessment

| Area | Confidence | Reason |
|------|------------|--------|
| AI Core (ai module) | **HIGH** | Stable release, security coverage, high adoption |
| AI Agents | **HIGH** | Stable release, security coverage, integrated with Drupal CMS |
| AI Search | **MEDIUM** | Alpha release, active development, core team maintainers |
| Deepseek Provider | **MEDIUM** | Stable but no security coverage, low adoption (96 sites) |
| Vector DB (Milvus) | **MEDIUM** | Beta quality but highest adoption, core team maintenance |
| Neo4j Integration | **LOW** | Abandoned module, not viable for production |
| RAG Implementation | **MEDIUM** | Standard patterns available in ai_search, needs testing with Drupal content types |

---

## Open Questions / Research Flags

1. **Deepseek embeddings quality** — Need to verify Deepseek embedding model compatibility with ai_search. May need fallback to OpenAI embeddings if Deepseek doesn't support embedding API.

2. **Group-scoped access in vector search** — Post-query filtering is mentioned, but performance implications for large-scale Group content need evaluation.

3. **File parsing (PDFs)** — PROJECT.md mentions file parsing requirement. Research needed on:
   - ai_summarize_document module (CKEditor plugin for PDF)
   - Integration with ai_search for file content indexing
   - File field chunking strategy

4. **Open Social compatibility** — Specific Open Social module interactions not researched. May need profile-specific configuration.

5. **Hybrid search tuning** — Optimal boost values for combining Solr keyword search with AI semantic search need empirical testing.

---

*Stack research for: Drupal AI Knowledge Gardens*
*Researched: 2026-02-23*
