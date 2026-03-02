---
gsd_state_version: 1.0
milestone: v1.0
milestone_name: milestone
status: unknown
last_updated: "2026-03-02T13:41:18.226Z"
progress:
  total_phases: 11
  completed_phases: 7
  total_plans: 32
  completed_plans: 30
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-02-24)

**Core value:** Group Assistants — Each Group feels like it has its own intelligent assistant that knows their content
**Current focus:** Phase 10: Production Config Management & Deploy Flow

## Current Position

Phase: 10 (Production Config Management & Deploy Flow)
Plan: 03 (complete)
Status: Phase 10 complete — all config management requirements satisfied, web3 modules corrected to core platform
Last activity: 2026-03-02 — Completed 10-03, Phase 10 done

Progress: [█████████████████████████] 97% (30/31 plans)

## Performance Metrics

**Velocity:**
- Total plans completed: 22
- Average duration: 7 min
- Total execution time: 2.7 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 1. AI Infrastructure | 3/3 | 23 min | 8 min |
| 2. Content Indexing | 7/7 | 35 min | 5 min |
| 3. Permission-Aware Retrieval | 3/3 | 35 min | 12 min |
| 4. Q&A & Search | 2/3 | 35 min | 18 min |
| 5. User Interface | 3/3 | 4 min | 2 min |
| 6. Demo Content | 6/6 | 36 min | 6 min |

**Recent Trend:**
- Last 5 plans: 06-06 (8 min), 06-05 (12 min), 06-04 (2 min), 05.1-01 (1 min), 05-02 (2 min)
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
| Phase 06 P04 | 2 min | 3 tasks | 14 files |
| Phase 06 P05 | 12 min | 2 tasks | 28 files |
| Phase 06 P06 | 8 min | 2 tasks | 0 files |
| Phase 09 P01 | 4 min | 3 tasks | 188 files |
| Phase 10 P01 | 2 min | 2 tasks | 3 files |
| Phase 10 P02 | 2 min | 2 tasks | 3 files |
| Phase 10 P03 | 8 min | 3 tasks | 5 files |

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
- [Phase 06]: Followed localnodes_demo pattern exactly for boulder_demo -- thin subclasses, no services.yml
- [Phase 06-05]: Mapped social_demo named photos to Boulder personas since plan source filenames did not exist
- [Phase 06-05]: Created 119 unique UUIDs with zero overlap against social_demo and localnodes_demo
- [Phase 06-05]: Research and Question topic types use exact string names per prepareTopicType() method
- [Phase 06-06]: Ran localnodes content removal twice -- first sdr pass left events, second pass cleaned all duplicates
- [Phase 06-06]: Ran localnodes_demo update_10002 to rename Resource to Research before boulder content install
- [Phase 09-01]: Vendor-committed strategy for deploy repo -- rsync pre-built code with patches applied rather than composer install at build time
- [Phase 09-01]: Solr configset name opensearch -> drupal to match DDEV naming convention
- [Phase 09-01]: SIWE domain extraction strips protocol prefix from SERVICE_FQDN_OPENSOCIAL
- [Phase 09-01]: Qdrant healthcheck uses bash TCP probe since image lacks curl
- [Phase 09-02]: Used create_public instead of create_github (Coolify API bug with create_github returning HTML)
- [Phase 09-02]: Cascadia UUID: sw08occo8so0g4okkw0w8goc, Boulder UUID: lwkkk4s00wokowc4c8o8k0sg
- [Phase 09]: Multi-stage Docker build: php:8.3-cli builder for composer install with patches, php:8.3-apache runtime for lean production image
- [Phase 09]: Single Docker image for both instances; DEMO_MODULE env var selects content module at runtime
- [Phase 10-01]: Replaced 903-line stock settings.php with 100-line 12-factor template using getenv() for all env-specific values
- [Phase 10-01]: config_exclude_modules excludes demo and web3 modules from config sync
- [Phase 10-01]: Solr, Qdrant, and Gemini API key configured via runtime $config[] overrides in settings.php
- [Phase 10-02]: Use drush deploy instead of config:import --partial for existing installs -- standard Drupal workflow
- [Phase 10-02]: Remove search-api:index and cron from existing-install path to avoid Gemini API costs on every restart
- [Phase 10-02]: Add drush deploy to fresh install path after site:install to align active config with config/sync
- [Phase 10-03]: Web3 modules (siwe_login, safe_smart_accounts, group_treasury, social_group_treasury) are core platform, not per-instance -- removed from config_exclude_modules
- [Phase 10-03]: SIWE domain configured via settings.php $config[] override instead of entrypoint drush config:set
- [Phase 10-03]: SKILL.md updated locally to reflect drush deploy workflow (gitignored, not committed)

### Roadmap Evolution

- Phase 6 added: Create demo content for LocalNodes.xyz based on social_demo module
- Phase 05.1 inserted after Phase 5: Split Related Content block into Related Topics and Related Events (URGENT)
- Phase 9 added: Deploy demo instances to Coolify (cascadia.localnodes.xyz, boulder.localnodes.xyz)
- Phase 10 added: Production Config Management & Deploy Flow

### Pending Todos

1. **Change Topic Type field to single select and update taxonomy terms** (content)
   - `.planning/todos/pending/2026-02-27-change-topic-type-field-to-single-select-and-update-taxonomy-terms.md`

### Blockers/Concerns

**From Research (Phase 1 considerations):**
- DeepSeek embedding API compatibility with ai_search needs validation during setup
- Open Social Group permission model complexity (visibility field, OG Access) needs discovery
- ~~Milvus self-hosted vs Zilliz Cloud operational decision pending~~ ✓ Resolved: Using self-hosted Milvus in DDEV

## Session Continuity

Last session: 2026-03-02
Stopped at: Completed 10-03-PLAN.md — Phase 10 complete

---
*State initialized: 2026-02-23*
*Last updated: 2026-03-02*
