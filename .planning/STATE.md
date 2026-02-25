# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-02-24)

**Core value:** Group Assistants — Each Group feels like it has its own intelligent assistant that knows their content
**Current focus:** Phase 3: Permission-Aware Retrieval

## Current Position

Phase: 3 of 5 (Permission-Aware Retrieval) - In Progress
Plan: 2 of 3 in current phase
Status: In Progress
Last activity: 2026-02-25 — Completed 03-02: Query Pipeline Integration

Progress: [████████░░░░] 67%

## Performance Metrics

**Velocity:**
- Total plans completed: 12
- Average duration: 6 min
- Total execution time: 1.63 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 1. AI Infrastructure | 3/3 | 23 min | 8 min |
| 2. Content Indexing | 7/7 | 35 min | 5 min |
| 3. Permission-Aware Retrieval | 2/3 | 28 min | 14 min |
| 4. Q&A & Search | 0/3 | - | - |
| 5. User Interface | 0/3 | - | - |

**Recent Trend:**
- Last 5 plans: 01-03 (6 min), 01-01 (10 min), 01-02 (7 min), 02-03c (5 min), 02-02b (3 min)
- Trend: Stable (~6 min avg)

| Phase 01-03 P03 | 6 min | 7 tasks | 4 files |
| Phase 01-01 P01 | 10 min | 5 tasks | 5 files |
| Phase 01-02 P02 | 7 min | 7 tasks | 0 files |
| Phase 02-03c P03c | 5 min | 3 tasks | 0 files |
| Phase 02-02b P02b | 3 min | 3 tasks | 1 files |
| Phase 02-02a P02a | 2 min | 2 tasks | 1 files |
| Phase 02-01b P01b | 10 min | 4 tasks | 3 files |
| Phase 03 P01 | 17 min | 2 tasks | 3 files |
| Phase 03 P02 | 11 min | 2 tasks | 3 files |

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

### Roadmap Evolution

- Phase 6 added: Create demo content for LocalNodes.xyz based on social_demo module

### Pending Todos

None yet.

### Blockers/Concerns

**From Research (Phase 1 considerations):**
- DeepSeek embedding API compatibility with ai_search needs validation during setup
- Open Social Group permission model complexity (visibility field, OG Access) needs discovery
- ~~Milvus self-hosted vs Zilliz Cloud operational decision pending~~ ✓ Resolved: Using self-hosted Milvus in DDEV

## Session Continuity

Last session: 2026-02-25
Stopped at: Completed 03-02-PLAN.md (Query Pipeline Integration)
Resume file: None

---
*State initialized: 2026-02-23*
*Last updated: 2026-02-25*
