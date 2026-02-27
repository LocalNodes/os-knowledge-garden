# Roadmap: Open Social AI Knowledge Gardens

## Overview

Build an AI-powered knowledge garden that gives each Open Social Group its own intelligent assistant. The journey starts with AI infrastructure (providers, vector DB), builds the indexing pipeline for content, implements permission-aware retrieval, enables natural language Q&A and search, and delivers polished user interfaces.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [x] **Phase 1: AI Infrastructure** - Core AI modules, Deepseek provider, vector database, embeddings pipeline (completed 2026-02-24)
- [ ] **Phase 2: Content Indexing** - Index posts, comments, files with embeddings and Group metadata
- [x] **Phase 3: Permission-Aware Retrieval** - Filter queries by user's Group access, defense-in-depth checks (completed 2026-02-25)
- [x] **Phase 4: Q&A & Search** - Natural language questions, semantic search, citation linking (completed 2026-02-26)
- [ ] **Phase 5: User Interface** - Chat interfaces for Group and community-wide queries
- [ ] **Phase 7: Fix Integration Bugs** - Fix permission filter field mapping on social_comments, clean up dead code processors
- [ ] **Phase 8: Re-verify Indexing & Formalize Search Tracking** - Re-verify Phase 2, formally track SRCH-01/02/03, fix tech debt

## Phase Details

### Phase 1: AI Infrastructure
**Goal**: AI capabilities are available and functional — the foundation for all AI features
**Depends on**: Nothing (first phase)
**Requirements**: AI-01, AI-02, AI-03, AI-04, AI-05
**Success Criteria** (what must be TRUE):
  1. Administrator can configure Deepseek as the LLM provider in Drupal admin UI
  2. Vector database (Milvus) is operational and can store/retrieve vector embeddings
  3. Test embeddings can be generated for sample content and stored in vector DB
  4. AI provider handles rate limits gracefully with retry/fallback logic
  5. AI Agents module provides a working agent framework for future tool-calling
**Plans**: 3 plans in 2 waves

Plans:
- [ ] 01-01: Install and configure Drupal AI core and providers (Deepseek + OpenAI) — Wave 1
- [x] 01-02: Deploy and connect Milvus vector database via DDEV — Wave 1
- [ ] 01-03: Configure AI Search and verify embedding pipeline — Wave 2 (depends on 01-01, 01-02)

### Phase 2: Content Indexing
**Goal**: Group content (posts, comments, files) is indexed with embeddings and ready for semantic retrieval
**Depends on**: Phase 1
**Requirements**: IDX-01, IDX-02, IDX-03, IDX-04, IDX-05, IDX-06
**Success Criteria** (what must be TRUE):
  1. New posts are automatically indexed with embeddings upon creation
  2. Comments are indexed with context from their parent posts
  3. File uploads (PDFs, Office docs) are parsed and their content is indexed
  4. Content is chunked appropriately for retrieval (256-512 tokens with overlap)
  5. Each indexed item has Group ID metadata attached for permission filtering
  6. Content updates and deletes trigger embedding regeneration/invalidation
**Plans**: 7 plans in 3 waves

Plans:
- [x] 02-01a: Create social_ai_indexing module and GroupMetadata processor — Wave 1
- [x] 02-03a: Install and enable ai_file_to_text module — Wave 1
- [x] 02-01b: Create post index and verify chunking — Wave 2 (depends on 02-01a)
- [x] 02-02a: Create CommentParentContext processor and comment index — Wave 2 (depends on 02-01a)
- [x] 02-03b: Create FileContentExtractor processor and configure index — Wave 2 (depends on 02-01a, 02-03a)
- [ ] 02-02b: Update GroupMetadata for comments and verify — Wave 3 (depends on 02-02a)
- [ ] 02-03c: Verify complete indexing pipeline — Wave 3 (depends on 02-01b, 02-03b)

### Phase 3: Permission-Aware Retrieval
**Goal**: AI only surfaces content the user is authorized to see — no permission leakage
**Depends on**: Phase 2
**Requirements**: PERM-01, PERM-02, PERM-03, PERM-04, PERM-05
**Success Criteria** (what must be TRUE):
  1. Vector queries are pre-filtered by user's accessible Group IDs before retrieval
  2. Retrieved results pass Drupal entity access check before inclusion in AI response
  3. AI-generated responses contain only content the querying user is authorized to see
  4. Community-wide search only returns public content when queried globally
  5. Group-scoped queries only return content from that specific Group
**Plans**: 3 plans in 3 waves

Plans:
- [x] 03-01: Build pre-retrieval filtering infrastructure (ContentVisibility processor + PermissionFilterService) — Wave 1
- [x] 03-02: Integrate filters with Search API and add post-retrieval checks — Wave 2 (depends on 03-01)
- [x] 03-03: Verify permission boundaries with adversarial queries — Wave 3 (depends on 03-02)

### Phase 4: Q&A & Search
**Goal**: Users can ask natural language questions and search content semantically with citations
**Depends on**: Phase 3
**Requirements**: QA-01, QA-02, QA-03, QA-04, QA-05, SRCH-01, SRCH-02, SRCH-03, SRCH-04
**Success Criteria** (what must be TRUE):
  1. Users can ask questions in natural language and receive relevant, contextual answers
  2. Every answer includes clickable citations linking back to source content (posts, comments, files)
  3. AI gracefully responds with "I couldn't find information about that" when no relevant content exists
  4. Semantic search returns results based on meaning, not just keyword matching
  5. Hybrid search combines vector similarity with existing Solr keyword matching
  6. Related content suggestions appear alongside Q&A results
  7. Response latency is acceptable for demo purposes (under 10 seconds)
**Plans**: 3 plans in 3 waves

Plans:
- [x] 04-01: Enable RAG pipeline with ai_assistant_api and CitationMetadata processor — Wave 1
- [x] 04-02: Implement hybrid search with RRF algorithm and JSON API — Wave 2 (depends on 04-01)
- [x] 04-03: Implement related content and comprehensive verification — Wave 3 (depends on 04-01, 04-02)

### Phase 5: User Interface
**Goal**: Users have intuitive interfaces for AI interactions — chat and search
**Depends on**: Phase 4
**Requirements**: UI-01, UI-02, UI-03, UI-04, UI-05
**Success Criteria** (what must be TRUE):
  1. Chat interface is available for natural language queries
  2. Chat interface is accessible within Group context for Group-scoped queries
  3. Community-wide search interface is accessible outside Group context
  4. Source citations are clickable and navigate to original content
  5. Clear visual distinction between AI-generated content and user-created content
**Plans**: TBD

Plans:
- [ ] 05-01: Build Group-scoped chat interface
- [ ] 05-02: Build community-wide search interface
- [ ] 05-03: Polish UX with citations and visual distinction

### Phase 05.1: Split Related Content block into Related Topics and Related Events (INSERTED)

**Goal:** Topic pages show Related Topics and event pages show Related Events instead of a single mixed Related Content block
**Requirements**: None (urgent insertion, no formal requirement IDs)
**Depends on:** Phase 5
**Plans:** 1 plan in 1 wave

Plans:
- [ ] 05.1-01-PLAN.md -- Parameterize RelatedContentBlock with bundle config, replace single block placement with two bundle-specific placements (Wave 1)

### Phase 7: Fix Integration Bugs
**Goal**: Permission filtering works correctly on all index types — broken integration connections and dead code are resolved
**Depends on**: Phase 3
**Requirements**: (none reassigned — fixes integration plumbing for PERM-01, PERM-03, PERM-04, IDX-05)
**Gap Closure:** Closes integration and flow gaps from v1.0 audit
**Success Criteria** (what must be TRUE):
  1. PermissionFilterService applies correct field names on social_comments index (group_id, not groups)
  2. Permission-filtered comment retrieval E2E flow works without error
  3. ContentVisibility dead code processor on social_posts is cleaned up
  4. GroupMetadata dead code on social_posts is cleaned up or documented
**Plans**: TBD

Plans:
- [ ] 07-01: Fix PermissionFilterService field mapping for social_comments
- [ ] 07-02: Clean up dead code processors (ContentVisibility, GroupMetadata on social_posts)
- [ ] 07-03: Verify permission-filtered comment retrieval E2E

### Phase 8: Re-verify Indexing & Formalize Search Tracking
**Goal**: All Phase 2 and Phase 4 requirements are verified against running system and formally tracked
**Depends on**: Phase 7
**Requirements**: IDX-01, IDX-02, IDX-03, IDX-04, IDX-05, IDX-06, SRCH-01, SRCH-02, SRCH-03
**Gap Closure:** Closes requirement gaps from v1.0 audit (stale verification + untracked code)
**Success Criteria** (what must be TRUE):
  1. Phase 2 indexing re-verified with server present — all IDX requirements confirmed working
  2. SRCH-01/02/03 code verified and formally tracked in SUMMARY and REQUIREMENTS
  3. AI-01/02/05 stale checkboxes corrected in REQUIREMENTS.md
  4. Tech debt resolved: corrupted 04-02-SUMMARY, non-standard Phase 4 VERIFICATION format
**Plans**: TBD

Plans:
- [ ] 08-01: Re-verify Phase 2 content indexing against live server
- [ ] 08-02: Verify and formally track SRCH-01/02/03 implementation
- [ ] 08-03: Fix tech debt (stale docs, corrupted summaries, non-standard verification)
- [ ] 08-04: Update REQUIREMENTS.md tracking for all resolved items

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4 → 5

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. AI Infrastructure | 3/3 | Complete   | 2026-02-24 |
| 2. Content Indexing | 5/7 | In Progress | 2026-02-24 |
| 3. Permission-Aware Retrieval | 3/3 | Complete   | 2026-02-25 |
| 4. Q&A & Search | 3/3 | Complete | 2026-02-26 |
| 5. User Interface | 0/3 | Not started | - |
| 7. Fix Integration Bugs | 0/3 | Not started | - |
| 8. Re-verify & Track | 0/4 | Not started | - |

### Phase 6: Create demo content for LocalNodes.xyz based on social_demo module

**Goal:** LocalNodes.xyz platform is populated with bioregionalism-themed demo content showcasing all AI features built in phases 1-5
**Depends on:** Phase 5
**Requirements:** DEMO-SCAFFOLDING, DEMO-CONTENT, DEMO-INSTALL
**Plans:** 3 plans in 3 waves

Plans:
- [ ] 06-01-PLAN.md -- Create localnodes_demo module scaffolding with 11 DemoContent plugins (Wave 1)
- [ ] 06-02-PLAN.md -- Create 11 YAML content files with bioregionalism-themed entities and AI coverage annotations (Wave 2)
- [ ] 06-03-PLAN.md -- Remove social_demo content, install localnodes_demo content, and verify in UI (Wave 3)

---
*Roadmap created: 2026-02-23*
