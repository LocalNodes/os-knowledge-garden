---
phase: 05-user-interface
plan: 01
subsystem: ui
tags: [deepchat, group-context, rag, search-api, drupal-hooks]

# Dependency graph
requires:
  - phase: 03-permission-aware-retrieval
    provides: PermissionFilterService with $scopeGroupId parameter support
  - phase: 04-q-a-search
    provides: AI assistant (group_assistant) with RAG tool and DeepChat block placement
provides:
  - Group-context-aware DeepChat chatbot via hook_preprocess_ai_deepchat
  - SearchQuerySubscriber propagation of group_id from AI runner context to permission filters
  - social_ai_search_page theme hook for Plan 05-02
affects: [05-02-PLAN, 05-03-PLAN]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "hook_preprocess_ai_deepchat for modifying DeepChat connect JSON (not hook_deepchat_settings)"
    - "AI assistant runner context propagation to Search API event subscribers"

key-files:
  created: []
  modified:
    - html/modules/custom/social_ai_indexing/social_ai_indexing.module
    - html/modules/custom/social_ai_indexing/src/EventSubscriber/SearchQuerySubscriber.php
    - html/modules/custom/social_ai_indexing/social_ai_indexing.services.yml

key-decisions:
  - "Use hook_preprocess_ai_deepchat (not hook_deepchat_settings) because connect key is set after the settings hook fires"
  - "Read group_id from AI assistant runner context as fallback when no explicit query option set"

patterns-established:
  - "hook_preprocess_ai_deepchat: json_decode connect string, modify, json_encode back"
  - "AI context propagation: DeepChat -> POST contexts -> runner setContext -> subscriber getContext"

requirements-completed: [UI-01, UI-02]

# Metrics
duration: 2min
completed: 2026-02-27
---

# Phase 5 Plan 1: Group Context Injection Summary

**Group-context-aware DeepChat chatbot injecting group_id into RAG search pipeline via hook_preprocess_ai_deepchat and AI assistant runner context propagation**

## Performance

- **Duration:** 2 min
- **Started:** 2026-02-27T02:50:39Z
- **Completed:** 2026-02-27T02:52:23Z
- **Tasks:** 2
- **Files modified:** 3

## Accomplishments
- DeepChat chatbot now injects current Group ID into connect JSON on Group pages via hook_preprocess_ai_deepchat
- SearchQuerySubscriber reads group_id from AI assistant runner context and passes it to PermissionFilterService for Group-scoped RAG queries
- Community-wide mode (no group_id) remains the default behavior on non-Group pages
- Block placement verified (UI-01 pre-condition satisfied)

## Task Commits

Each task was committed atomically:

1. **Task 1: Implement hook_preprocess_ai_deepchat for Group context injection** - `2d2de7c` (feat)
2. **Task 2: Propagate Group context from AI assistant runner to Search API queries** - `021e4d3` (feat)

## Files Created/Modified
- `html/modules/custom/social_ai_indexing/social_ai_indexing.module` - Added hook_preprocess_ai_deepchat for Group ID injection into DeepChat connect JSON; added social_ai_search_page theme hook
- `html/modules/custom/social_ai_indexing/src/EventSubscriber/SearchQuerySubscriber.php` - Injected AI assistant runner service; reads group_id from runner context for Group-scoped permission filtering
- `html/modules/custom/social_ai_indexing/social_ai_indexing.services.yml` - Added ai_assistant_api.runner argument to search_query_subscriber service

## Decisions Made
- Used hook_preprocess_ai_deepchat instead of hook_deepchat_settings because the connect key does not exist when hook_deepchat_settings fires (it is set after the hook at line 644 of getDeepChatParameters)
- Read group_id from AI assistant runner context as a fallback when no explicit ai_search_scope_group_id query option is set, maintaining backward compatibility with direct query option usage

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness
- hook_preprocess_ai_deepchat is structured to accept Plan 05-03 messageStyles additions (extension point documented in code comments)
- social_ai_search_page theme hook is registered for Plan 05-02 to use
- Group context flows end-to-end: DeepChat -> POST body -> AI runner -> SearchQuerySubscriber -> PermissionFilterService

## Self-Check: PASSED

All files exist, all commits verified.

---
*Phase: 05-user-interface*
*Completed: 2026-02-27*
