# Project Research Summary

**Project:** Drupal AI Knowledge Gardens (Open Social)
**Domain:** AI-powered community knowledge management / RAG system
**Researched:** 2026-02-23
**Confidence:** MEDIUM-HIGH (strong architecture patterns, stable core modules, but alpha/beta search stack)

## Executive Summary

This project is an AI-powered knowledge garden for Open Social (Drupal) that enables Group-scoped conversational Q&A over community content using RAG (Retrieval-Augmented Generation). The Drupal AI ecosystem has matured significantly in 2025, with the core AI module reaching stable release with security advisory coverage. The recommended approach uses the stable AI Core + AI Agents modules as the foundation, with the ai_search module (alpha) and Milvus vector database (beta) for semantic retrieval.

The critical architectural decision is **permission-aware retrieval from day one**. Unlike typical search features, RAG systems can leak unauthorized content through the LLM response if permissions aren't enforced during vector search. The research strongly recommends pre-retrieval metadata filtering combined with post-retrieval entity access checks. Other key risks include stale embeddings (must implement event-driven re-indexing), DeepSeek API rate limiting (queue-based processing required), and the alpha-stage AI Search module (needs monitoring).

## Key Findings

### Recommended Stack

The Drupal AI module ecosystem provides a production-ready foundation, though the search and vector database components are still maturing. The core `ai` and `ai_agents` modules have stable releases with security coverage, making them safe foundations. The ai_search module (alpha) and Milvus provider (beta) carry higher risk but are maintained by the core AI team and represent the best available options.

**Core technologies:**

- **drupal/ai (^1.2)** — Core AI abstraction layer, provider management — STABLE with security coverage, 11,313 sites
- **drupal/ai_agents (^1.2)** — Agent framework for tool-calling capabilities — STABLE with security coverage, 6,780 sites  
- **drupal/ai_provider_deepseek (^1.0)** — Deepseek LLM integration — STABLE but no security coverage (96 sites)
- **drupal/ai_search (^2.0@alpha)** — RAG backend, vector search, chunking — ALPHA, active development
- **drupal/ai_vdb_provider_milvus (^1.1@beta)** — Vector database storage — BETA, highest adoption (475 sites)
- **drupal/search_api (^1.35)** — Index management framework — STABLE, existing infrastructure

**AVOID:** Neo4j connector (abandoned since 2017), PostgreSQL pgvector provider (alpha quality), custom AI stack (6-18 month commitment).

### Expected Features

Users expect a ChatGPT-like experience with citations and permission respect. The feature research identified clear table stakes that must exist at launch, differentiators that provide competitive advantage, and anti-features that commonly derail projects.

**Must have (table stakes):**
- Natural Language Q&A — Core value prop; users ask questions, get answers about group content
- Citation/Source Linking — Trust mechanism; every answer links back to source posts/files
- Permission-Aware Retrieval — Non-negotiable; only surface content user can access
- Semantic Search (Vector) — Users expect "understanding" not keyword matching
- Content Ingestion (Posts + Comments) — Primary knowledge source
- Basic File Parsing (PDFs, Docs) — Knowledge lives in documents, not just posts
- Community-Wide Public Search — Search across all public content

**Should have (competitive):**
- Per-Group Customized Agents — Each group feels like it has its own assistant (HIGH complexity)
- Multi-Turn Conversations — Maintains context across questions (MEDIUM complexity)
- Near-Real-Time Indexing — New content discoverable within minutes (HIGH complexity)

**Defer (v2+):**
- Graph-Enhanced Retrieval — Neo4j abandoned; requires significant infrastructure
- Agent Tool Use — AI can take actions (create content, tag users) — needs robust foundation first
- Video/Audio Transcription — Multi-modal expansion, high complexity

### Architecture Approach

A layered architecture separates concerns: Presentation (chatbot UI, search views) → AI Assistant/Agent Layer (RAG actions, group context, tools) → AI Core (provider abstraction, embeddings) → Search/Indexing (vector DB, Search API) → Drupal Content (Open Social, Groups) → Permissions (Group access, node grants). The critical pattern is **pre-retrieval metadata filtering** — every vector query must include the user's accessible Group IDs and permission level.

**Major components:**
1. **AI Core Module** — Provider abstraction, unified API for all AI operations (chat, embeddings, function calling)
2. **AI Search Module** — Search API backend for RAG; handles chunking, embedding generation, vector operations
3. **Vector DB (Milvus)** — Stores embeddings with metadata (group_id, entity_id, visibility); performs similarity search
4. **Permission Filter Service** — Resolves user's accessible Groups, builds metadata filters for queries
5. **Group Context Tool** — Provides current Group context to AI agents; enables Group-scoped Q&A

### Critical Pitfalls

The research identified five critical pitfalls that commonly derail RAG implementations. Permission leakage is the most severe — a single incident destroys trust. Stale embeddings and poor chunking directly impact answer quality.

1. **Permission Leakage Through RAG Retrieval** — Users see unauthorized content; MUST implement pre-retrieval metadata filtering (never post-filter) and test with adversarial queries
2. **Stale Embeddings (Vector Decay)** — AI provides outdated information; MUST implement event-driven re-embedding on content CRUD via Drupal hooks + queue API
3. **Naive Chunking Destroying Context** — Documents split mid-sentence, losing meaning; MUST use semantic/recursive chunking with 10-20% overlap, content-type awareness
4. **Skipping Reranking** — Wrong results in top positions; SHOULD implement reranking layer (retrieve 20-50, rerank to top 5-10)
5. **DeepSeek API Rate Limiting** — 429 errors during peak usage; MUST implement request queuing, exponential backoff, fallback provider

## Implications for Roadmap

Based on research, suggested phase structure follows the dependency chain: foundational infrastructure (permissions, embeddings, vector DB) must be in place before RAG can function; RAG quality determines user experience; agents and advanced features build on working RAG.

### Phase 1: Core Infrastructure & Permission Foundation

**Rationale:** Permission model must be foundational, not retrofitted. Pre-retrieval filtering requires Group metadata in every chunk. Event-driven re-embedding requires hooks in place before content is indexed. API resilience patterns (queuing, backoff) are architectural.

**Delivers:** Working AI provider connection, vector database, permission-aware indexing pipeline, embedding generation queue

**Addresses:** Natural Language Q&A (foundation), Permission-Aware Retrieval (core), Content Ingestion (pipeline)

**Avoids:** Permission Leakage (pre-retrieval filtering), Stale Embeddings (event-driven updates), DeepSeek Rate Limits (queue + backoff)

**Research needed:** Open Social specific permission integration (Group visibility field, OG Access layers)

### Phase 2: RAG Implementation & Quality

**Rationale:** With infrastructure in place, implement the actual retrieval and generation. Quality depends on chunking strategy and reranking. Citations require chunk-to-source mapping.

**Delivers:** Working RAG system with semantic search, proper chunking, citation linking, answer generation

**Uses:** ai_search module, Milvus VDB provider, Search API indexes, chunking strategies

**Implements:** AI Search component (chunking, embedding, retrieval), RAG Action (query pipeline)

**Addresses:** Citation/Source Linking, Semantic Search, Basic File Parsing

**Avoids:** Naive Chunking (semantic strategies), Skipping Reranking (reranking layer), Hallucination (confidence thresholds, "I don't know" prompts)

**Research needed:** DeepSeek embedding API compatibility with ai_search; optimal chunk size for community content

### Phase 3: Group-Scoped Experience

**Rationale:** With core RAG working, add Group context awareness. Per-group customization and community-wide search require Group context injection into prompts and queries.

**Delivers:** Group-scoped Q&A (assistant knows which group you're in), community-wide public search, Group context tools for agents

**Uses:** AI Agents module, Group Context Tool, AI Assistant API

**Implements:** Agent Architecture (tool-calling, memory), Group Isolation (single index with metadata filtering)

**Addresses:** Per-Group Customized Agents (basic), Community-Wide Search, Related Content Suggestions

**Research needed:** Hybrid search tuning (Solr keyword + AI semantic boost values)

### Phase 4: User Experience & Polish

**Rationale:** With functionality complete, focus on UX. Streaming responses, error handling, mobile experience, feedback collection.

**Delivers:** Polished chatbot UI, streaming responses, graceful error handling, user feedback loop, admin configuration UI

**Uses:** AI Chatbot (Deepchat-based), Views integration, logging module

**Addresses:** Clear Error/Unknown Responses, Mobile Experience, User Feedback Loop

**Avoids:** Poor UX expectations, AI always answers even when uncertain

### Phase 5: Advanced Features (v1.x / v2)

**Rationale:** Only pursue after core is validated. Multi-turn requires session state. Near-real-time requires queue optimization. Agent tool use requires robust foundation.

**Delivers:** Multi-turn conversations, near-real-time indexing, advanced agent capabilities, per-group custom prompts

**Addresses:** Multi-Turn Conversations, Near-Real-Time Indexing, Agent Tool Use

**Research needed:** Session state management for multi-turn; queue optimization for near-real-time

### Phase Ordering Rationale

- **Dependencies drive order:** Permission layer must exist before indexing (can't filter without metadata); indexing must exist before RAG (can't retrieve without vectors); RAG must work before agents (agents use RAG tools)
- **Architecture patterns support grouping:** Infrastructure layer (Phase 1), Retrieval layer (Phase 2), Agent/Context layer (Phase 3), Presentation layer (Phase 4)
- **Pitfall avoidance built in:** Phase 1 addresses security (permission leakage, stale embeddings); Phase 2 addresses quality (chunking, reranking); Phase 3+ addresses UX

### Research Flags

Phases likely needing deeper research during planning:
- **Phase 1:** Open Social Group permission model complexity (visibility field, OG Access, node grants); DeepSeek embedding API compatibility; Milvus self-hosted vs Zilliz Cloud operational requirements
- **Phase 2:** File parsing integration with ai_search (PDFs, Office docs); chunking strategy optimization for community content
- **Phase 3:** Hybrid search tuning (Solr + vector boost values); cross-group search performance at scale

Phases with standard patterns (skip research-phase):
- **Phase 1:** AI Core + Provider setup (well-documented module configuration)
- **Phase 4:** Chatbot UI, streaming responses (standard Drupal block + JS patterns)

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | MEDIUM | Core AI module stable with security coverage, but ai_search is alpha and VDB providers are beta. DeepSeek provider has low adoption (96 sites) and no security coverage. |
| Features | HIGH | Multiple sources (Notion AI, Slack AI, Confluence AI, Teams Copilot) provide clear feature landscape. MVP definition is well-supported. |
| Architecture | HIGH | Official Drupal AI module documentation + Group module patterns + RAG best practices. Permission filtering patterns are industry-standard. |
| Pitfalls | HIGH | OWASP LLM Top 10, Drupal security advisories, industry RAG failure analysis (72-80% failure rate cited). Warning signs and prevention strategies are concrete. |

**Overall confidence:** MEDIUM-HIGH

### Gaps to Address

- **DeepSeek Embeddings:** Research couldn't confirm Deepseek embedding model compatibility with ai_search. May need fallback to OpenAI embeddings if Deepseek doesn't support embedding API. Validate during Phase 1 setup.
  
- **Open Social Specific Integration:** Group visibility field, OG Access layer, and Open Social permission complexity not fully researched. Plan for discovery during Phase 1 implementation.

- **File Parsing Details:** PDF and Office document parsing with ai_search needs validation. Chunking strategy for files vs. posts needs testing.

- **Hybrid Search Tuning:** Optimal boost values for combining Solr keyword search with AI semantic search need empirical testing. Plan A/B testing in Phase 3.

- **Scale Thresholds:** Research provides scaling guidance (0-1k, 1k-100k, 100k+ users) but actual performance characteristics need monitoring. Plan metrics dashboard from Phase 1.

## Sources

### Primary (HIGH confidence)
- **drupal.org/project/ai** — AI module official page, verified stable 1.2.9 with security coverage
- **drupal.org/project/ai_agents** — AI Agents module, verified stable 1.2.2 with security coverage  
- **project.pages.drupalcode.org/ai/1.2.x/** — Official AI module documentation (core, search, agents, assistant API)
- **drupal.org/project/group** — Group module documentation
- **OWASP Top 10 for LLM Applications (2025)** — LLM08:2025 Vector and Embedding Weaknesses

### Secondary (MEDIUM confidence)
- **drupal.org/project/ai_search** — AI Search module, verified 2.0.0-alpha1 status
- **drupal.org/project/ai_vdb_provider_milvus** — Milvus VDB provider, verified 1.1.0-beta3
- **drupal.org/project/ai_provider_deepseek** — Deepseek provider, verified 1.0.0
- **Notion AI / Slack AI / Confluence AI / Teams Copilot** — Feature landscape analysis (2025)
- **Databricks RAG Failure Analysis (2024)** — 72-80% enterprise failure rate findings
- **Stanford HAI Legal RAG Hallucination Study (2024)** — 17-33% hallucination rates
- **Drupal Security Advisories** — SA-CONTRIB-2025-003, SA-CONTRIB-2025-004, CVE-2025-3169, CVE-2025-31693, CVE-2025-13981

### Tertiary (LOW confidence)
- **DeepSeek API Documentation** — Rate limit policy and tier structure (subject to change)
- **Community reports** — Reddit/GitHub discussions on DeepSeek API availability
- **Industry surveys** — Enterprise AI adoption statistics (generalized findings)

---
*Research completed: 2026-02-23*
*Ready for roadmap: yes*
