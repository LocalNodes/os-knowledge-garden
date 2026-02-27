---
gsd_state_version: 1.0
milestone: v1.0
milestone_name: milestone
status: unknown
last_updated: "2026-02-27T11:58:54.521Z"
progress:
  total_phases: 9
  completed_phases: 5
  total_plans: 23
  completed_plans: 21
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-02-24)

**Core value:** Group Assistants — Each Group feels like it has its own intelligent assistant that knows their content
**Current focus:** Phase 5: User Interface (filling gap)

## Current Position

Phase: 05.1 (Split Related Content Block) - Complete
Plan: 1 of 1 in current phase
Status: Phase 05.1 Complete
Last activity: 2026-02-27 — Completed 05.1-01: Split Related Content Block

Progress: [████████████] 96%

## Performance Metrics

**Velocity:**
- Total plans completed: 20
- Average duration: 7 min
- Total execution time: 2.4 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 1. AI Infrastructure | 3/3 | 23 min | 8 min |
| 2. Content Indexing | 7/7 | 35 min | 5 min |
| 3. Permission-Aware Retrieval | 3/3 | 35 min | 12 min |
| 4. Q&A & Search | 2/3 | 35 min | 18 min |
| 5. User Interface | 2/3 | 4 min | 2 min |
| 6. Demo Content | 2/3 | 14 min | 7 min |

**Recent Trend:**
- Last 5 plans: 05.1-01 (1 min), 05-02 (2 min), 05-01 (2 min), 04-03 (8 min), 06-02 (11 min)
- Trend: Stable (~5 min avg)

| Phase 01-03 P03 | 6 min | 7 tasks | 4 files |
| Phase 01-01 P01 | 10 min | 5 tasks | 5 files |
| Phase 01-02 P02 | 7 min | 7 tasks | 0 files |
| Phase 02-03c P03c | 5 min | 3 tasks | 0 files |
| Phase 02-02b P02b | 3 min | 3 tasks | 1 files |
| Phase 02-02a P02a | 2 min | 2 tasks | 1 files |
| Phase 02-01b P01b | 10 min | 4 tasks | 3 files |
| Phase 03 P01 | 17 min | 2 tasks | 3 files |
| Phase 03 P02 | 11 min | 2 tasks | 3 files |
| Phase 03-permission-aware-retrieval P03 | 7 min | 3 tasks | 2 files |
| Phase 04-q-a-search P01 | 27 min | 4 tasks | 6 files |
| Phase 06-demo-content P01 | 3 min | 3 tasks | 14 files |
| Phase 04-q-a-search P03 | 8 min | 3 tasks | 6 files |
| Phase 06-demo-content P02 | 11 min | 2 tasks | 31 files |
| Phase 05-user-interface P01 | 2 min | 2 tasks | 3 files |
| Phase 05-user-interface PP02 | 2 min | 2 tasks | 6 files |
| Phase 05.1-split-related-content P01 | 1 min | 2 tasks | 5 files |

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table.
Recent decisions affecting current work:

- [Phase 01-02]: Used existing docker-compose.ai.yaml instead of docker-compose.milvus.yaml - functionally equivalent
- [Phase 01-02]: Milvus v2.4.1 with etcd v3.5.5 and minio for storage
- [Phase 02-01a]: Created standalone social_ai_indexing module for all indexing configuration
- [Phase 02-01a]: Used Search API processor pattern for Group ID metadata injection
- [Phase 02-01b]: Fixed processor namespace to match PSR-4 (search_api/processor lowercase)
- [Phase 02-03a]: Used ai_file_to_text (PHP-native) instead of unstructured module for simpler setup
- [Phase 02-03b]: Created FileContentExtractor processor with MIME type validation for PDFs and Office docs
- [Phase 02-02a]: Added parent_post_title and parent_post_summary fields to social_comments index for comment context
- [Phase 02-02b]: Updated GroupMetadata processor to handle comments via parent entity group lookup
- [Phase 02-03c]: Verified complete indexing pipeline operational with update/delete handling and file extraction
- [Phase 01-01]: Use Ollama with nomic-embed-text for embeddings (768 dimensions, local, no API costs) instead of OpenAI
- [Phase 01-03]: Used ai_search submodule (bundled with ai package) instead of standalone drupal/ai_search (requires ai 2.x)
- [Phase 03-01]: Default visibility to 'group_content' for empty fields (safest default)
- [Phase 03-01]: Never cache permission decisions (re-evaluate at query time)
- [Phase 03-02]: Only filter AI search indexes (social_posts, social_comments) to avoid breaking regular search
- [Phase 03-02]: Log warnings instead of throwing exceptions to ensure search never breaks
- [Phase 03-02]: Post-retrieval filtering as defense-in-depth, not primary security layer
- [Phase 03-03]: Verification script covers all test cases in single execution for efficiency
- [Phase 03-03]: Configuration items documented as setup steps rather than failures
- [Phase 04-q-a-search]: Use ai_agents module with RAG tool instead of direct ai_assistant_api RAG properties — Current module architecture uses ai_agents with tools/tool_usage_limits pattern
- [Phase 04-q-a-search]: Set RAG threshold to 0.7, max_results to 5 — Balance precision/recall while maintaining acceptable latency
- [Phase 06-01]: Extend social_demo Plugin classes (Topic, Event, Post, Like, EventEnrollment, EventType, UserTerms) rather than base classes directly to inherit entity-specific getEntry() logic
- [Phase 06-01]: No .services.yml needed -- module reuses social_demo services entirely
- [Phase 06-02]: Copied social_demo profile photos renamed for personas rather than downloading new images
- [Phase 06-02]: Used social_demo landscape images as group headers rather than downloading from Unsplash
- [Phase 04-03]: Use Search API fulltext query with entity text extraction for vector similarity (leverages existing Milvus backend)
- [Phase 04-03]: Cache RelatedContentBlock per-user (not per-role) for permission accuracy
- [Phase 04-03]: Similarity threshold 0.7 to avoid returning unrelated content
- [Phase 06-02]: Created 121 unique UUIDs with zero overlap against social_demo
- [Phase 04-q-a-search]: Fixed MilvusProvider IN/NOT IN operator — was generating invalid filter syntax, causing zero results for authenticated users
- [Phase 05-01]: Use hook_preprocess_ai_deepchat (not hook_deepchat_settings) because connect key is set after the settings hook fires
- [Phase 05-01]: Read group_id from AI assistant runner context as fallback when no explicit query option set
- [Phase 05-02]: No CSRF token needed for search — GET endpoint with session cookie auth
- [Phase 05-02]: Empty CSS placeholder created for Plan 05-03 to avoid Drupal asset warnings
- [Phase 05.1-01]: Used single parameterized block plugin rather than two separate block classes
- [Phase 05.1-01]: Events subtitle "happening soon" vs topics subtitle "in the community" for contextual relevance

### Roadmap Evolution

- Phase 6 added: Create demo content for LocalNodes.xyz based on social_demo module
- Phase 05.1 inserted after Phase 5: Split Related Content block into Related Topics and Related Events (URGENT)

### Pending Todos

None yet.

### Blockers/Concerns

**From Research (Phase 1 considerations):**
- DeepSeek embedding API compatibility with ai_search needs validation during setup
- Open Social Group permission model complexity (visibility field, OG Access) needs discovery
- ~~Milvus self-hosted vs Zilliz Cloud operational decision pending~~ ✓ Resolved: Using self-hosted Milvus in DDEV

## Session Continuity

Last session: 2026-02-27
Stopped at: Completed 05.1-01-PLAN.md (Split Related Content Block into Related Topics and Related Events)

---
*State initialized: 2026-02-23*
*Last updated: 2026-02-27*
