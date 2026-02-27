---
phase: 04-q-a-search
plan: 02
subsystem: search
tags: [hybrid-search, rrf, milvus, solr, semantic-search, permission-filter]

# Dependency graph
requires:
  - phase: 04-q-a-search/04-01
    provides: RAG pipeline, CitationMetadata processor, AI Agent configuration
provides:
  - HybridSearchService with RRF merging of Milvus vector + Solr keyword results
  - Permission-aware hybrid search via PermissionFilterService integration
  - AiOverviewService + AiOverviewController for /api/ai/overview endpoint
affects: [04-03, 05-ui]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Reciprocal Rank Fusion (RRF) with K=60 for rank-based score merging"
    - "Dual-index search: social_posts (Milvus) + social_content (Solr)"
    - "Post-retrieval access filtering as defense-in-depth"

key-files:
  created:
    - html/modules/custom/social_ai_indexing/src/Service/HybridSearchService.php
    - html/modules/custom/social_ai_indexing/src/Service/AiOverviewService.php
    - html/modules/custom/social_ai_indexing/src/Controller/AiOverviewController.php
  modified:
    - html/modules/custom/social_ai_indexing/social_ai_indexing.services.yml
    - html/modules/custom/social_ai_indexing/social_ai_indexing.routing.yml
    - html/modules/custom/social_ai_indexing/src/Service/PermissionFilterService.php

key-decisions:
  - "RRF K=60 (industry standard) for democratic fusion between Milvus cosine similarity and Solr BM25"
  - "Fetch 2x results from each source for better RRF merging quality"
  - "Use social_posts (Milvus) for vector search and social_content (Solr) for keyword search"
  - "Post-retrieval filterResultsByAccess as defense-in-depth layer"

patterns-established:
  - "HybridSearchService pattern: vector + keyword + RRF + permission filtering"
  - "AiOverviewService pattern: hybrid search → LLM summarization → cached JSON response"

requirements-completed: [SRCH-01, SRCH-02, SRCH-03]

# Metrics
duration: 8 min
completed: 2026-02-25
---

# Phase 4 Plan 2: Hybrid Search with RRF Summary

**Implemented hybrid search combining Milvus semantic search with Solr keyword matching using Reciprocal Rank Fusion (RRF), exposed via /api/ai/overview endpoint**

## Performance

- **Duration:** ~8 min
- **Completed:** 2026-02-25

## Accomplishments

- Created HybridSearchService with vectorSearch (Milvus social_posts) and keywordSearch (Solr social_content)
- Implemented Reciprocal Rank Fusion algorithm (RRF_K=60) for rank-based score merging
- Created AiOverviewService that feeds hybrid search results into LLM for summarization with citations
- Created AiOverviewController at /api/ai/overview with per-user caching (5 min positive, 2 min negative)
- Integrated PermissionFilterService for both pre-retrieval filtering and post-retrieval access checks

## Files Created/Modified

- `html/modules/custom/social_ai_indexing/src/Service/HybridSearchService.php` — RRF hybrid search (vector + keyword + fusion + permission filtering)
- `html/modules/custom/social_ai_indexing/src/Service/AiOverviewService.php` — Orchestrates hybrid search → LLM summarization
- `html/modules/custom/social_ai_indexing/src/Controller/AiOverviewController.php` — /api/ai/overview JSON endpoint with caching
- `html/modules/custom/social_ai_indexing/social_ai_indexing.services.yml` — Service registrations
- `html/modules/custom/social_ai_indexing/social_ai_indexing.routing.yml` — Route definition
- `html/modules/custom/social_ai_indexing/src/Service/PermissionFilterService.php` — Updated with community-wide query detection

## Deviations from Plan

1. **[Rule 1 - Bug] Permission filter field adjustment** — Plan specified `content_visibility` for permission filtering, but the indexed field is `groups`/`group_id`. Fixed PermissionFilterService to use the correct indexed field names.

---
*Phase: 04-q-a-search*
*Completed: 2026-02-25*
