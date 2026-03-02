# Roadmap: Open Social AI Knowledge Gardens

## Overview

Build an AI-powered knowledge garden that gives each Open Social Group its own intelligent assistant. The journey starts with AI infrastructure (providers, vector DB), builds the indexing pipeline for content, implements permission-aware retrieval, enables natural language Q&A and search, and delivers polished user interfaces.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [x] **Phase 1: AI Infrastructure** - Core AI modules, Deepseek provider, vector database, embeddings pipeline (completed 2026-02-24)
- [x] **Phase 2: Content Indexing** - Index posts, comments, files with embeddings and Group metadata (completed 2026-02-24, verified 2026-02-27)
- [x] **Phase 3: Permission-Aware Retrieval** - Filter queries by user's Group access, defense-in-depth checks (completed 2026-02-25)
- [x] **Phase 4: Q&A & Search** - Natural language questions, semantic search, citation linking (completed 2026-02-26)
- [x] **Phase 5: User Interface** - Chat interfaces for Group and community-wide queries (completed 2026-02-27)
- [x] **Phase 7: Fix Integration Bugs** - Dead code cleanup (completed 2026-02-27, scope reduced after investigation)
- [x] **Phase 8: Re-verify Indexing & Formalize Search Tracking** - Docs cleanup, REQUIREMENTS.md updated (completed 2026-02-27, scope reduced)

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
- [x] 02-02b: Update GroupMetadata for comments and verify — Wave 3 (depends on 02-02a)
- [x] 02-03c: Verify complete indexing pipeline — Wave 3 (depends on 02-01b, 02-03b)

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
- [x] 05-01: Build Group-scoped chat interface
- [x] 05-02: Build community-wide search interface
- [x] 05-03: Polish UX with citations and visual distinction

### Phase 05.1: Split Related Content block into Related Topics and Related Events (INSERTED)

**Goal:** Topic pages show Related Topics and event pages show Related Events instead of a single mixed Related Content block
**Requirements**: None (urgent insertion, no formal requirement IDs)
**Depends on:** Phase 5
**Plans:** 1/1 plans complete

Plans:
- [x] 05.1-01-PLAN.md -- Parameterize RelatedContentBlock with bundle config, replace single block placement with two bundle-specific placements (Wave 1)

### Phase 7: Fix Integration Bugs (RESOLVED)
**Goal**: Permission filtering works correctly on all index types — broken integration connections and dead code are resolved
**Resolution**: Investigation found PermissionFilterService field mapping was already correct (`group_id` used consistently). GroupMetadata is active, not dead code. Only actual dead code was `ContentVisibility` processor — deleted. Comment retrieval E2E already working.
**Completed**: 2026-02-27

### Phase 8: Re-verify Indexing & Formalize Search Tracking (RESOLVED)
**Goal**: All Phase 2 and Phase 4 requirements are verified against running system and formally tracked
**Resolution**: Code review confirmed all IDX and SRCH requirements are implemented. Corrupted 04-02-SUMMARY.md rewritten. Phase 4 VERIFICATION.md rewritten in standard format with correct SRCH ID mappings. REQUIREMENTS.md updated — all 30/30 v1 requirements now complete. Phase 2 verification was stale (server missing at time); server now exists and code is solid.
**Completed**: 2026-02-27

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4 → 5

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. AI Infrastructure | 3/3 | Complete   | 2026-02-24 |
| 2. Content Indexing | 7/7 | Complete | 2026-02-24 |
| 3. Permission-Aware Retrieval | 3/3 | Complete   | 2026-02-25 |
| 4. Q&A & Search | 3/3 | Complete | 2026-02-26 |
| 5. User Interface | 3/3 | Complete | 2026-02-27 |
| 7. Fix Integration Bugs | - | Resolved | 2026-02-27 |
| 8. Re-verify & Track | - | Resolved | 2026-02-27 |
| 9. Deploy to Coolify | 3/3 | Complete | 2026-03-01 |
| 10. Config Management | 3/3 | Complete    | 2026-03-02 |

### Phase 6: Create demo content for LocalNodes.xyz based on social_demo module

**Goal:** LocalNodes.xyz platform is populated with bioregionalism-themed demo content showcasing all AI features built in phases 1-5, with two distinct bioregional nodes (Cascadia + Boulder) simulating the LocalNodes network
**Depends on:** Phase 5
**Requirements:** DEMO-SCAFFOLDING, DEMO-CONTENT, DEMO-INSTALL
**Plans:** 6 plans in 3 waves

Plans:
- [x] 06-01-PLAN.md -- Create localnodes_demo module scaffolding with 11 DemoContent plugins (Wave 1)
- [x] 06-02-PLAN.md -- Create 11 YAML content files with bioregionalism-themed entities and AI coverage annotations (Wave 2)
- [ ] 06-03-PLAN.md -- Remove social_demo content, install localnodes_demo content, and verify in UI (Wave 3)
- [ ] 06-04-PLAN.md -- Create boulder_demo module scaffolding with 11 DemoContent plugins (Wave 1)
- [ ] 06-05-PLAN.md -- Create 11 YAML content files with Boulder/Front Range regen community content showcasing Research and Question topic types (Wave 2)
- [ ] 06-06-PLAN.md -- Install boulder_demo content and verify entities, topic types, and permissions in UI (Wave 3)

### Phase 9: Deploy demo instances to Coolify (cascadia.localnodes.xyz, boulder.localnodes.xyz)

**Goal:** Two live demo instances are deployed and accessible -- cascadia.localnodes.xyz with Cascadia bioregionalism content and boulder.localnodes.xyz with Boulder/Front Range regen content, each running the full AI knowledge garden stack (Solr + Qdrant + Gemini)
**Requirements**: DEPLOY-REPO, DEPLOY-CASCADIA, DEPLOY-BOULDER, DEPLOY-VERIFY
**Depends on:** Phase 6
**Plans:** 3 plans in 3 waves

Plans:
- [x] 09-01-PLAN.md -- Create Docker/CI infrastructure: multi-stage Dockerfile, Solr Dockerfile, entrypoint.sh, solr-config, docker-compose.yml, .dockerignore, GitHub Actions workflow (Wave 1)
- [ ] 09-02-PLAN.md -- Push to GitHub, delete old Coolify apps, create Cascadia + Boulder apps with env vars, trigger deployments (Wave 2, depends on 09-01)
- [ ] 09-03-PLAN.md -- Verify both instances: domain access, SSL, correct demo content, AI chatbot, container health (Wave 3, depends on 09-02)

### Phase 10: Production Config Management & Deploy Flow

**Goal:** Config changes deploy incrementally to existing instances via `drush deploy` instead of requiring volume wipes. Settings.php is version-controlled with `getenv()`. Config/sync is complete and shipped in the Docker image.
**Requirements**: CFG-01, CFG-02, CFG-03, CFG-04, CFG-05, CFG-06
**Depends on:** Phase 9
**Success Criteria** (what must be TRUE):
  1. settings.php is a committed template with getenv() calls, not generated by the entrypoint
  2. Config/sync directory is included in the Docker image for drush deploy
  3. config_exclude_modules excludes demo modules from config sync; web3 modules are core platform
  4. config/sync is complete (localnodes_platform + web3 modules in core.extension, gemini key config present, demo modules removed)
  5. Entrypoint uses `drush deploy` for existing installs (no more config:import --partial)
  6. Deploy hook scaffold exists for future one-time operations
**Plans:** 3/3 plans complete

Plans:
- [x] 10-01-PLAN.md -- Template settings.php with getenv() + Dockerfile config/sync COPY + deploy hook scaffold (Wave 1)
- [x] 10-02-PLAN.md -- Fix config/sync completeness + simplify entrypoint to use drush deploy (Wave 2, depends on 10-01)
- [x] 10-03-PLAN.md -- Verify cross-file consistency + update SKILL.md + human verification (Wave 3, depends on 10-02)

---
*Roadmap created: 2026-02-23*
