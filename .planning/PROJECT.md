# Open Social AI Knowledge Gardens

## What This Is

An AI-powered knowledge garden enhancement for an Open Social community platform. Enables Groups to cultivate their own intelligent assistants that can answer questions, retrieve content, and help with curation—while respecting user permissions and Group boundaries. Includes community-wide search across all public content.

## Core Value

**Group Assistants** — Each Group feels like it has its own intelligent assistant that knows their content, can answer questions, and helps members discover institutional knowledge.

## Requirements

### Validated

(None yet — ship to validate)

### Active

- [ ] AI assistant can answer questions about Group content using natural language
- [ ] AI assistant respects user permissions — only surfaces content user is authorized to see
- [ ] Community-wide search across all public Group and community content
- [ ] File parsing — PDFs, documents, uploads included in knowledge base
- [ ] Group-scoped agent customization (stretch goal)
- [ ] Integration with Drupal AI ecosystem (ai, ai_agents, ai_search modules)

### Out of Scope

- **Multi-modal media** — Images, video transcripts deferred (complexity)
- **Real-time chat** — Async Q&A for v1, not live chat
- **Reputation/gamification** — Contribution tracking deferred to future iteration

## Context

**Existing Platform:** Open Social ~13.0 installation with Groups functionality. Groups contain posts, comments, file uploads, and member interactions.

**Drupal AI Ecosystem:**
- Drupal AI module (https://www.drupal.org/project/ai) — Core AI framework
- AI Agents module (https://www.drupal.org/project/ai_agents) — Agent capabilities
- AI Search related modules — RAG, vector search, embeddings

**Knowledge Garden Vision:**
- Groups cultivate their own knowledge bases
- Community-wide garden aggregates public content
- Assistants/agents provide intelligent retrieval and generation
- Auth-aware: results scoped to user's access level

**RAG Techniques to Evaluate:**
- Per-group indexes
- Metadata filtering at chunk level
- SQL-based retrieval
- Solr (existing) integration
- Vector embeddings (new)
- Graph database / Neo4j (new)

## Constraints

- **Platform:** Must integrate with Open Social / Drupal ecosystem
- **LLM Provider:** Deepseek (via ai_provider_deepseek module) for chat/generation
- **Embedding Provider:** Ollama (local) for vector embeddings — no external API dependency
- **Search Backend:** Solr already in place, adding vector capabilities via Milvus
- **Auth Model:** Drupal permissions system must be respected
- **PHP Version:** 8.3

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Deepseek as LLM provider | User selected via ai_provider_deepseek module | ✓ Implemented |
| Ollama for embeddings (local) | Free, private, no rate limits, data stays local. Deepseek module doesn't support embeddings. | ✓ Implemented |
| Milvus for vector DB | Highest adoption in Drupal AI ecosystem, DDEV add-on available | ✓ Implemented |
| Drupal AI ecosystem modules | Leverage existing integrations, avoid reinventing | ✓ Implemented |
| Solr + Vector (no Graph) | Neo4j module abandoned; defer graph capabilities | — Scoped out |
| Flexible auth implementation | Research best approach during discovery | — Pending |

---
*Last updated: 2026-02-24 after Phase 1 infrastructure setup*
