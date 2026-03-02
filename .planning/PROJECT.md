# Open Social AI Knowledge Gardens

## What This Is

An AI-powered knowledge garden built on Open Social, deployed as the LocalNodes platform. Each community Group has its own intelligent assistant that answers questions, retrieves content, and helps members discover institutional knowledge — while respecting user permissions and Group boundaries. Includes community-wide hybrid search (Solr + vector) across all public content. Deployed on Coolify with automated provisioning via GitHub Actions.

## Core Value

**Group Assistants** — Each Group feels like it has its own intelligent assistant that knows their content, can answer questions, and helps members discover institutional knowledge.

## Requirements

### Validated

- ✓ AI assistant answers questions about Group content using natural language — v1.0
- ✓ AI assistant respects user permissions (pre-filter + post-filter defense-in-depth) — v1.0
- ✓ Community-wide search across all public Group and community content — v1.0
- ✓ File parsing (PDFs, Office docs) included in knowledge base — v1.0
- ✓ Integration with Drupal AI ecosystem (ai, ai_agents, ai_search) — v1.0
- ✓ Hybrid search combining Solr keywords + Qdrant vector similarity — v1.0
- ✓ Citation links in AI responses back to source content — v1.0
- ✓ Three live demo instances (Cascadia, Boulder, Portland) deployed on Coolify — v1.0
- ✓ Production config management with drush deploy and 12-factor settings — v1.0

### Active

(None yet — define for v2.0 via `/gsd:new-milestone`)

### Out of Scope

- **Multi-modal media** — Images, video transcripts deferred (complexity)
- **Real-time live chat** — Async Q&A sufficient; streaming adds scaling complexity
- **Reputation/gamification** — Contribution tracking deferred
- **Agent tool use (creating content)** — Requires robust agent framework; defer until Q&A validated
- **Graph database (Neo4j)** — Module abandoned since 2017; not production-ready
- **Group-scoped agent customization** — Per-group system prompts deferred to v2.0

## Context

**Current State (v1.0 shipped 2026-03-02):**
- 245 commits, 1771 files, 345k+ lines
- Tech stack: Drupal 10.6.3, Open Social ~13.0, PHP 8.3
- AI: Gemini (chat + embeddings, 3072-dim), Qdrant (vector DB)
- Search: Solr (BM25) + Qdrant (vector) with RRF merge
- Deploy: Docker multi-stage build → GHCR → Coolify, automated via GitHub Actions
- Live instances: cascadia.localnodes.xyz, boulder.localnodes.xyz, portland.localnodes.xyz

**LocalNodes Vision:**
Agentic x federated bioregional knowledge commons AND web2.5 onchain bioregional financing. Web3/SIWE modules (siwe_login, safe_smart_accounts, group_treasury, social_group_treasury) are integral platform components. Group treasuries are a step toward governance proposals and Zodiac/DAO snapshot voting.

## Constraints

- **Platform:** Open Social / Drupal ecosystem
- **AI Provider:** Gemini for both chat/generation and embeddings (3072-dim) via ai module
- **Vector DB:** Qdrant (migrated from Milvus)
- **Search Backend:** Solr (keyword) + Qdrant (vector) hybrid
- **Auth Model:** Drupal permissions with Group-level access control
- **PHP Version:** 8.3
- **Deployment:** Single Docker image, DEMO_MODULE env var selects instance content

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Gemini for chat + embeddings | Replaced Deepseek (chat) and Ollama (embeddings) — single provider, better quality | ✓ Good |
| Qdrant for vector DB | Replaced Milvus — simpler ops, better Drupal support | ✓ Good |
| Drupal AI ecosystem modules | Leverage existing integrations, avoid reinventing | ✓ Good |
| Solr + Vector (no Graph) | Neo4j module abandoned; not production-ready | ✓ Good |
| Pre-filter + post-filter auth | Defense-in-depth for permission-aware retrieval | ✓ Good |
| Single Docker image for all instances | DEMO_MODULE env var selects content at runtime | ✓ Good |
| CI artifact strategy | GitHub Actions builds image, Coolify pulls pre-built | ✓ Good |
| config/sync as source of truth | drush deploy for existing installs, site:install for fresh | ✓ Good |
| Web3 modules as core platform | Group treasuries ship on all sites, not per-instance | ✓ Good |

---
*Last updated: 2026-03-02 after v1.0 milestone*
