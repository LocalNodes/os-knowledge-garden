---
phase: 01-ai-infrastructure
plan: 01
subsystem: ai
tags: [deepseek, ollama, nomic-embed-text, ai-providers, embeddings, chat]

# Dependency graph
requires: []
provides:
  - Deepseek LLM provider for chat/generation
  - Ollama embeddings provider with nomic-embed-text (768 dimensions)
  - AI usage limits configuration
affects: [02-content-indexing, 03-permission-retrieval, 04-qa-search]

# Tech tracking
tech-stack:
  added: [drupal/ai, drupal/ai_agents, drupal/ai_provider_deepseek, drupal/ai_provider_ollama, drupal/ai_usage_limits, drupal/key]
  patterns: [ai-provider-configuration, local-embeddings]

key-files:
  created:
    - config/sync/ai.provider.deepseek.yml
    - config/sync/ai.provider.ollama.yml
  modified:
    - config/sync/ai_provider_deepseek.settings.yml
    - config/sync/ai_provider_ollama.settings.yml
    - config/sync/ai_usage_limits.settings.yml

key-decisions:
  - "Use Ollama with nomic-embed-text for embeddings instead of OpenAI (768 dimensions, local, no API costs)"
  - "Use Deepseek for chat/generation with deepseek-chat model"
  - "Configure conservative usage limits (100K daily, 1M monthly) for cost control"

patterns-established:
  - "AI providers configured via Drupal config system with Key module for secure credential storage"
  - "Local Ollama embeddings eliminate external API dependency for vector generation"

requirements-completed: [AI-01, AI-02, AI-05]

# Metrics
duration: 10 min
completed: 2026-02-24
---

# Phase 1 Plan 01: AI Provider Configuration Summary

**Deepseek chat provider and Ollama embeddings configured with local nomic-embed-text model (768 dimensions)**

## Performance

- **Duration:** 10 min
- **Started:** 2026-02-24T21:11:40Z
- **Completed:** 2026-02-24T21:21:12Z
- **Tasks:** 5 (Tasks 3-7, adapted for Ollama)
- **Files modified:** 5

## Accomplishments
- Configured Deepseek provider with API key reference and deepseek-chat model
- Configured Ollama provider for local embeddings using nomic-embed-text (768 dimensions)
- Verified Deepseek chat returns valid responses ("Hello" test)
- Verified Ollama embeddings generate correct 768-dimension vectors
- Configured usage limits: 100K daily tokens, 1M monthly tokens, 80% alert threshold

## Task Commits

Each task was committed atomically:

1. **Task 1: Install Drupal AI modules via Composer** - `5dfec65` (feat) - *Previously completed*
2. **Task 2: Enable AI modules in correct dependency order** - *Previously completed*
3. **Task 3: Create Key entity for Deepseek** - *Key already existed from prior work*
4. **Task 4-7: Configure providers and limits** - `8110499` (feat)

**Plan metadata:** `8110499` (docs: complete plan)

## Files Created/Modified
- `config/sync/ai.provider.deepseek.yml` - Deepseek provider configuration
- `config/sync/ai.provider.ollama.yml` - Ollama provider configuration  
- `config/sync/ai_provider_deepseek.settings.yml` - Deepseek settings (model: deepseek-chat)
- `config/sync/ai_provider_ollama.settings.yml` - Ollama settings (host: http://host.docker.internal:11434)
- `config/sync/ai_usage_limits.settings.yml` - Usage limits (100K daily, 1M monthly, 80% alert)

## Decisions Made
- **Ollama over OpenAI for embeddings:** Original plan specified OpenAI for embeddings, but corrected to use Ollama with nomic-embed-text for local, cost-free embeddings. Nomic-embed-text provides 768 dimensions (sufficient for semantic search).
- **HTTP protocol for Ollama:** Configured host_name with `http://` prefix to avoid SSL errors when connecting from DDEV container to host Ollama instance.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Fixed Ollama base URL configuration**
- **Found during:** Task 6 (Test Ollama embeddings)
- **Issue:** Ollama provider was connecting via HTTPS instead of HTTP, causing SSL errors
- **Fix:** Updated `ai_provider_ollama.settings` host_name to include `http://` protocol
- **Files modified:** config/sync/ai_provider_ollama.settings.yml
- **Verification:** Embeddings test returned 768-dimension vectors
- **Committed in:** 8110499 (Task 4-7 commit)

**2. [Plan Adaptation] Skipped OpenAI, used Ollama for embeddings**
- **Found during:** Task 5 (Configure OpenAI provider)
- **Issue:** Project uses Ollama + nomic-embed-text for embeddings, not OpenAI
- **Fix:** Configured Ollama provider instead of OpenAI for embeddings
- **Files modified:** config/sync/ai.provider.ollama.yml, config/sync/ai_provider_ollama.settings.yml
- **Verification:** Ollama embeddings test passed with 768 dimensions
- **Committed in:** 8110499 (Task 4-7 commit)

---

**Total deviations:** 2 (1 blocking issue fixed, 1 plan adaptation for correct embedding provider)
**Impact on plan:** Both changes improved the solution - using local Ollama eliminates external API dependency and costs.

## Issues Encountered
- Deepseek provider needed model configuration in `ai_provider_deepseek.settings` (not just `ai.provider.deepseek`)
- Ollama provider requires explicit `http://` protocol in host_name config

## User Setup Required

None - Deepseek API key was already configured in Key entity. Ollama runs locally and is already operational.

## Next Phase Readiness
- AI providers configured and tested
- Ready for Plan 01-02 (Milvus Vector Database) - already completed
- Ready for Phase 2 (Content Indexing) - already in progress

---
*Phase: 01-ai-infrastructure*
*Completed: 2026-02-24*
