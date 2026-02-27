# Phase 5: User Interface - Research

**Researched:** 2026-02-26
**Domain:** Drupal block placement, DeepChat web component, Open Social theming, AI chatbot UX
**Confidence:** HIGH

## Summary

Phase 5 delivers user-facing interfaces for the AI Knowledge Garden. The key finding is that most infrastructure already exists: the `ai_chatbot` module provides a fully functional DeepChat-based chatbot block (`ai_deepchat_block`) with the `group_assistant` AI assistant already configured, and a block placement (`socialblue_aideepchatchatbot`) already exists in the `content` region with `bottom-right` placement. The hybrid search API endpoint (`/api/ai/search`) is operational from Phase 4.

The primary work is: (1) making the chatbot Group-context-aware by passing the current Group ID through DeepChat's `additionalBodyProps` so the RAG search scopes to that Group, (2) creating a community-wide search page that consumes the existing `/api/ai/search` endpoint with a proper UI, and (3) ensuring citations are clickable links and AI content is visually distinguishable from user content.

**Primary recommendation:** Use the existing `ai_deepchat_block` and `hook_deepchat_settings` alter hook to inject Group context into the chatbot API requests. Build the community search as a new Drupal page route with a simple JS-driven search form that calls the existing hybrid search endpoint. Style citations and AI distinction using CSS within the socialblue theme.

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| UI-01 | Chat interface is available for natural language queries | DeepChat block already placed (`socialblue_aideepchatchatbot`), uses `group_assistant` AI assistant with RAG tool. Block is functional now. |
| UI-02 | Chat interface is accessible within Group context for Group-scoped queries | `hook_deepchat_settings` can inject Group ID into `additionalBodyProps.contexts`. `PermissionFilterService::applyPermissionFilters()` already accepts `$scopeGroupId`. Need to pass Group ID from route to chatbot API and through to RAG search. |
| UI-03 | Community-wide search interface is accessible outside Group context | Existing `/api/ai/search` endpoint provides hybrid search results as JSON. Need a page at `/search/ai` or similar with a form that calls this endpoint via AJAX. Open Social already has `/search/content` for keyword search. |
| UI-04 | Source citations are clickable and navigate to original content | RAG responses already include markdown links (`[Source: Title](url)`) from the `group_assistant` system prompt. DeepChat renders HTML via CommonMark. Citations need CSS styling for visibility. |
| UI-05 | Clear visual distinction between AI-generated content and user-created content | DeepChat's `messageStyles` supports different styling for `ai` vs `user` roles. Related Content block already uses a distinct template. Need CSS for AI badge/indicator. |
</phase_requirements>

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| ai_chatbot | bundled with ai module | DeepChat-based chatbot frontend | Already installed and configured; provides `ai_deepchat_block` block plugin |
| DeepChat | bundled JS (deepchat.bundle.js) | Web component for chat UI | Already bundled in ai_chatbot module; provides `<deep-chat>` element |
| ai_assistant_api | bundled with ai module | AI assistant entity and runner | Already configured with `group_assistant` entity |
| social_ai_indexing | custom module | Hybrid search, permission filtering, related content | Built in Phases 2-4; provides `/api/ai/search` endpoint |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| league/commonmark | installed via composer | Markdown to HTML conversion | Already used by DeepChat API controller for rendering AI responses |
| socialblue theme | Open Social default | Theme regions and component library | Block placement and CSS styling |
| core/drupal.ajax | Drupal core | AJAX framework | For community search form submission |
| core/drupalSettings | Drupal core | Pass PHP config to JS | Already used by DeepChat block for settings |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| DeepChat block | Custom chat UI from scratch | DeepChat already integrated, tested, and styled; no benefit to rebuilding |
| AJAX search form | Views-based search page | Views doesn't support hybrid (vector + keyword) search; custom form needed |
| `hook_deepchat_settings` | Custom block extending DeepChatFormBlock | Hook is the supported API; extending block adds maintenance burden |

**Installation:**
No new packages needed. All dependencies are already installed.

## Architecture Patterns

### Recommended Project Structure
```
html/modules/custom/social_ai_indexing/
├── social_ai_indexing.module          # hook_deepchat_settings, hook_theme (EXTEND)
├── social_ai_indexing.routing.yml     # Add search page route (EXTEND)
├── social_ai_indexing.libraries.yml   # NEW: JS/CSS for search page
├── src/
│   ├── Controller/
│   │   ├── HybridSearchController.php   # EXISTS: /api/ai/search
│   │   └── AiSearchPageController.php   # NEW: renders search page
│   ├── Service/
│   │   ├── HybridSearchService.php      # EXISTS
│   │   └── PermissionFilterService.php  # EXISTS
│   └── Plugin/Block/
│       └── RelatedContentBlock.php      # EXISTS
├── templates/
│   ├── social-ai-search-page.html.twig  # NEW: search page template
│   └── social-ai-related-content.html.twig  # EXISTS
├── css/
│   └── ai-search.css                   # NEW: search + citation styling
└── js/
    └── ai-search.js                    # NEW: AJAX search form handler
```

### Pattern 1: Group Context Injection via hook_deepchat_settings
**What:** Use `hook_deepchat_settings()` to inject the current Group ID into the DeepChat `connect.additionalBodyProps.contexts` so the backend knows to scope RAG search to that Group.
**When to use:** When the chatbot block is rendered on a Group page.
**Example:**
```php
// Source: ai_chatbot/ai_chatbot.api.php hook documentation + codebase analysis
/**
 * Implements hook_deepchat_settings().
 */
function social_ai_indexing_deepchat_settings(array &$deepchat_settings) {
  // Detect current Group from route context.
  $group = \Drupal::service('social_group.current_group')->fromRunTimeContexts();
  if ($group) {
    // Inject group_id into the API request body.
    $connect = json_decode($deepchat_settings['connect'] ?? '{}', TRUE);
    $connect['additionalBodyProps']['contexts']['group_id'] = (int) $group->id();
    $deepchat_settings['connect'] = json_encode($connect);
  }
}
```

### Pattern 2: Group-Scoped RAG Search via SearchQuerySubscriber
**What:** The existing `SearchQuerySubscriber` already applies permission filters on search queries. To support explicit Group scoping from the chatbot, the context `group_id` passed through DeepChat needs to reach the `PermissionFilterService::applyPermissionFilters()` with the `$scopeGroupId` parameter.
**When to use:** When the AI agent's RAG tool executes a search and the chatbot request includes a `group_id` context.
**How it flows:**
1. DeepChat sends POST to `/api/deepchat` with `contexts.group_id`
2. `DeepChatApi::api()` calls `$this->aiAssistantClient->setContext($data['contexts'])`
3. AI agent invokes `ai_search:rag_search` tool
4. `RagTool::execute()` creates a Search API query
5. `SearchQuerySubscriber` intercepts the query and applies permission filters
6. Need: `SearchQuerySubscriber` must read the stored context to get `group_id` and pass it to `applyPermissionFilters()`

### Pattern 3: Community Search Page with AJAX
**What:** A Drupal page route that renders a search form. JavaScript handles form submission, calls `/api/ai/search?q=...` via fetch(), and renders results in the page.
**When to use:** For the community-wide search at `/search/ai`.
**Example:**
```php
// Source: standard Drupal controller pattern
class AiSearchPageController extends ControllerBase {
  public function page(): array {
    return [
      '#theme' => 'social_ai_search_page',
      '#attached' => [
        'library' => ['social_ai_indexing/ai-search'],
      ],
    ];
  }
}
```

### Pattern 4: Citation Styling via CSS
**What:** AI responses rendered by DeepChat already contain markdown links converted to `<a>` tags. Style these citation links distinctively.
**When to use:** Citations in AI chat responses and search results.
**Example:**
```css
/* Style citation links in AI responses */
.ai-deepchat .message-bubble a[href*="/node/"],
.ai-deepchat .message-bubble a[href*="/group/"] {
  color: #4a90d9;
  text-decoration: none;
  border-bottom: 1px dashed #4a90d9;
  font-weight: 500;
}

.ai-deepchat .message-bubble a[href*="/node/"]:hover {
  border-bottom-style: solid;
}
```

### Pattern 5: AI Content Visual Distinction
**What:** Use DeepChat's `messageStyles` and `avatars` configuration to make AI messages visually distinct from user messages.
**When to use:** For UI-05 requirement.
**Example via hook_deepchat_settings:**
```php
// Add AI badge/icon and distinct styling
function social_ai_indexing_deepchat_settings(array &$deepchat_settings) {
  // The ai_deepchat_block already sets bot_image and bot_name,
  // but we can enhance the visual distinction.
  $message_styles = json_decode($deepchat_settings['messageStyles'] ?? '{}', TRUE);
  $message_styles['default']['ai']['bubble']['borderLeft'] = '3px solid #4a90d9';
  $deepchat_settings['messageStyles'] = json_encode($message_styles);
}
```

### Anti-Patterns to Avoid
- **Building a custom chat UI:** The ai_chatbot module with DeepChat handles all the chat mechanics (SSE streaming, message history, session management). Do not rebuild this.
- **Duplicating search logic:** The `/api/ai/search` endpoint already does hybrid search with RRF and permission filtering. The search page should call this API, not re-implement search.
- **Hardcoding Group IDs:** Use `social_group.current_group` service to detect Group context dynamically, never hardcode Group IDs.
- **Bypassing permission filters:** All search paths must flow through `PermissionFilterService`. The community search page should call the same endpoint that already applies permissions.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Chat interface | Custom WebSocket chat | ai_chatbot DeepChat block | Session management, streaming, history, CSRF — all handled |
| Markdown rendering | Custom MD parser | league/commonmark (already installed) | Edge cases in markdown parsing are numerous |
| Permission filtering | Custom access checks | PermissionFilterService | Already handles group membership, visibility fields, anonymous users |
| Group context detection | Route parsing | `social_group.current_group` service | Official Open Social API, handles all route patterns |
| Search results | Custom Milvus/Solr queries | `/api/ai/search` endpoint via HybridSearchService | Already implements RRF, permission filtering, result normalization |

**Key insight:** Phase 5 is primarily a UI wiring and configuration phase, not a backend-building phase. The backend services (RAG, hybrid search, permissions, citations) are all built and verified from Phases 1-4. The work is connecting these backends to user-facing interfaces.

## Common Pitfalls

### Pitfall 1: DeepChat connect property is JSON-encoded string
**What goes wrong:** The `connect` attribute on the `<deep-chat>` element is a JSON string, not an object. In `hook_deepchat_settings`, `$deepchat_settings['connect']` is already JSON-encoded by `DeepChatFormBlock::getDeepChatParameters()`.
**Why it happens:** The block's `build()` method calls `Json::encode()` on all array values before setting them as element attributes.
**How to avoid:** In `hook_deepchat_settings`, decode the JSON string, modify, and re-encode. Check the actual data type before manipulating.
**Warning signs:** Chatbot sends requests without the group_id context.

### Pitfall 2: Group Context Not Available on All Pages
**What goes wrong:** `social_group.current_group->fromRunTimeContexts()` returns NULL when not on a Group page (e.g., homepage, user profile, content page not in a Group).
**Why it happens:** The Group context provider only resolves when the route has a `{group}` parameter.
**How to avoid:** Always check for NULL before injecting Group context. When NULL, the chatbot operates in community-wide mode (which is correct behavior).
**Warning signs:** PHP errors on non-Group pages.

### Pitfall 3: Context Not Reaching RAG Tool
**What goes wrong:** Group ID is injected into DeepChat request but RAG search doesn't scope to that Group.
**Why it happens:** The `contexts` data from `DeepChatApi::api()` is stored in the assistant runner session, but `RagTool::execute()` doesn't read it. The `SearchQuerySubscriber` intercepts queries but may not have access to the chatbot session context.
**How to avoid:** The most reliable approach is to modify `SearchQuerySubscriber` to read the stored Group context from the assistant runner session, or to use a request-scoped service that stores the Group ID.
**Warning signs:** Chatbot returns community-wide results even when on a Group page.

### Pitfall 4: CSRF Token for Search API
**What goes wrong:** AJAX calls to `/api/ai/search` fail with 403.
**Why it happens:** The existing route requires `_permission: 'access content'` but no CSRF token. However, if the site has strict session handling, anonymous AJAX may fail.
**How to avoid:** The hybrid search route already uses GET method with only `_permission: 'access content'`. Use `fetch()` with credentials included. If needed, use `Drupal.ajax` which handles CSRF automatically.
**Warning signs:** 403 errors in browser console when searching.

### Pitfall 5: Block Visibility Conflicts
**What goes wrong:** Chatbot block appears on admin pages, or doesn't appear on Group pages.
**Why it happens:** The current block placement (`socialblue_aideepchatchatbot`) has `visibility: {}` (no restrictions), meaning it shows everywhere.
**How to avoid:** Keep the global chatbot placement as-is (community-wide access). For Group-scoped behavior, the Group context injection handles scoping dynamically based on the current page, not through block visibility.
**Warning signs:** Chatbot showing on admin pages, or needing separate block placements per Group.

## Code Examples

### Example 1: hook_deepchat_settings for Group Context
```php
// Source: Codebase analysis of DeepChatFormBlock::getDeepChatParameters() and ai_chatbot.api.php
function social_ai_indexing_deepchat_settings(array &$deepchat_settings) {
  // Get current Group from route context.
  $group = \Drupal::service('social_group.current_group')->fromRunTimeContexts();

  if ($group) {
    // Decode the connect JSON string.
    $connect = json_decode($deepchat_settings['connect'], TRUE);
    if (is_array($connect)) {
      // Add group_id to the contexts sent with each message.
      $connect['additionalBodyProps']['contexts']['group_id'] = (int) $group->id();
      $deepchat_settings['connect'] = json_encode($connect);
    }
  }
}
```

### Example 2: Community Search Page Route
```yaml
# Source: Standard Drupal routing pattern
social_ai_indexing.search_page:
  path: '/search/ai'
  defaults:
    _controller: '\Drupal\social_ai_indexing\Controller\AiSearchPageController::page'
    _title: 'AI Search'
  requirements:
    _permission: 'access content'
```

### Example 3: Search Page JavaScript
```javascript
// Source: Standard fetch API pattern with Drupal integration
(function (Drupal) {
  'use strict';

  Drupal.behaviors.aiSearch = {
    attach: function (context) {
      const form = context.querySelector('.ai-search-form');
      if (!form) return;

      form.addEventListener('submit', function (e) {
        e.preventDefault();
        const query = form.querySelector('[name="q"]').value;
        const resultsContainer = context.querySelector('.ai-search-results');

        fetch('/api/ai/search?q=' + encodeURIComponent(query))
          .then(response => response.json())
          .then(data => {
            resultsContainer.innerHTML = '';
            if (data.results && data.results.length > 0) {
              data.results.forEach(function (result) {
                const item = document.createElement('div');
                item.className = 'ai-search-result';
                item.innerHTML =
                  '<h3><a href="' + Drupal.checkPlain(result.url) + '">' +
                  Drupal.checkPlain(result.title) + '</a></h3>' +
                  '<p class="ai-search-snippet">' + Drupal.checkPlain(result.snippet) + '</p>' +
                  '<span class="ai-search-type">' + Drupal.checkPlain(result.type) + '</span>';
                resultsContainer.appendChild(item);
              });
            } else {
              resultsContainer.innerHTML = '<p>' + Drupal.t('No results found.') + '</p>';
            }
          });
      });
    }
  };
})(Drupal);
```

### Example 4: Search Page Template
```twig
{# social-ai-search-page.html.twig #}
<div class="ai-search-wrapper">
  <form class="ai-search-form">
    <div class="form-group">
      <label for="ai-search-input">{{ 'Ask a question or search the community'|t }}</label>
      <div class="input-group">
        <input type="text" id="ai-search-input" name="q"
               class="form-control" placeholder="{{ 'What would you like to know?'|t }}"
               autocomplete="off" />
        <span class="input-group-btn">
          <button type="submit" class="btn btn-primary">{{ 'Search'|t }}</button>
        </span>
      </div>
    </div>
  </form>
  <div class="ai-search-results"></div>
</div>
```

### Example 5: AI Visual Distinction CSS
```css
/* AI-generated content indicator */
.ai-deepchat .message-bubble[data-role="ai"]::before,
.ai-search-result::before {
  content: 'AI';
  display: inline-block;
  font-size: 10px;
  font-weight: 700;
  color: #fff;
  background: #4a90d9;
  border-radius: 3px;
  padding: 1px 4px;
  margin-right: 6px;
  vertical-align: middle;
  letter-spacing: 0.5px;
}

/* Citation links in AI responses */
.deepchat-element a {
  color: #4a90d9;
  text-decoration: underline;
  text-decoration-style: dotted;
}
.deepchat-element a:hover {
  text-decoration-style: solid;
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| ai_chatbot ChatFormBlock (deprecated) | ai_deepchat_block (DeepChatFormBlock) | ai module 1.1.0 | Use DeepChat block, not legacy ChatForm block. The ChatFormBlock explicitly warns to use DeepChat instead. |
| Streaming with agents | Non-streaming for agent-based assistants | ai module 1.1.0 | DeepChat block auto-disables streaming when assistant has an agent (our case). Verbose mode shows step-by-step instead. |
| Custom RAG actions | Tool-based agents with `ai_search:rag_search` | ai module 1.1.0 | Our `group_assistant` uses the tool pattern, not legacy actions. |

**Deprecated/outdated:**
- `ai_chatbot_block` (ChatFormBlock): Deprecated in favor of `ai_deepchat_block`. A deprecation warning is shown in block config form.
- Legacy `actions_enabled` pattern: Replaced by `tools` and `tool_usage_limits` in AI agent config.

## Open Questions

1. **Group ID propagation through agent tool calls**
   - What we know: DeepChat passes `contexts` to the API, and `DeepChatApi::api()` stores them via `setContext()`. The `group_assistant` agent uses `ai_search:rag_search` tool.
   - What's unclear: Whether the stored context (group_id) automatically influences the Search API query executed by `RagTool::execute()`. The `SearchQuerySubscriber` uses `currentRouteMatch` for group detection, but the DeepChat API route (`/api/deepchat`) is not a Group route, so `isCommunityWideQuery()` will return TRUE even when the chatbot is on a Group page.
   - Recommendation: Modify `SearchQuerySubscriber` or `PermissionFilterService` to accept an explicit `group_id` from the request context. Store the group_id in a request-scoped state, or modify the subscriber to check for a `group_id` in the AI assistant runner's context data.

2. **Search page URL and navigation placement**
   - What we know: Open Social has `/search/content` for keyword search. We need a separate AI-powered search.
   - What's unclear: Whether to add AI search as a tab on the existing search page or as a separate page.
   - Recommendation: Create `/search/ai` as a separate page. This avoids modifying the existing search infrastructure and makes the AI feature clearly distinct. A link can be added from the existing search page.

3. **Block placement scope for chatbot**
   - What we know: Current placement shows chatbot everywhere (visibility: {}).
   - What's unclear: Whether we want the chatbot on every page or only on Group pages and the homepage.
   - Recommendation: Keep the current global placement. The chatbot works in community-wide mode on non-Group pages and automatically scopes to the Group on Group pages. This provides the best UX.

## Sources

### Primary (HIGH confidence)
- Codebase analysis: `html/modules/contrib/ai/modules/ai_chatbot/src/Plugin/Block/DeepChatFormBlock.php` - DeepChat block configuration and build logic
- Codebase analysis: `html/modules/contrib/ai/modules/ai_chatbot/src/Controller/DeepChatApi.php` - Chat API endpoint, context handling
- Codebase analysis: `html/modules/contrib/ai/modules/ai_chatbot/ai_chatbot.api.php` - Hook documentation for `hook_deepchat_settings`
- Codebase analysis: `config/sync/block.block.socialblue_aideepchatchatbot.yml` - Existing chatbot block placement
- Codebase analysis: `config/sync/ai_agents.ai_agent.group_assistant.yml` - AI agent configuration with RAG tool
- Codebase analysis: `html/modules/custom/social_ai_indexing/src/Service/PermissionFilterService.php` - Permission filtering with Group scoping support
- Codebase analysis: `html/modules/custom/social_ai_indexing/src/Controller/HybridSearchController.php` - Existing search API endpoint
- Codebase analysis: `html/profiles/contrib/social/modules/social_features/social_group/src/CurrentGroupService.php` - Official Group context detection
- Context7: `/ovidijusparsiunas/deep-chat` - DeepChat connect API, messageStyles, avatars configuration

### Secondary (MEDIUM confidence)
- Codebase analysis: `html/profiles/contrib/social/themes/socialblue/socialblue.info.yml` - Theme regions (content, complementary_top/bottom, sidebar_first/second, hero)
- Codebase analysis: `config/sync/block.block.socialblue_groupheroblock.yml` - Example of Group page block visibility patterns using `request_path`

### Tertiary (LOW confidence)
- None

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - All components already exist in the codebase and are verified working from Phases 1-4
- Architecture: HIGH - Patterns are well-established in the codebase (hooks, controllers, block plugins, JS behaviors)
- Pitfalls: HIGH - Identified through direct code reading of the DeepChat block, API controller, and permission service
- Group context propagation: MEDIUM - The exact flow from chatbot context to RAG tool query needs validation during implementation

**Research date:** 2026-02-26
**Valid until:** 2026-03-26 (stable — all dependencies are already installed and configured)
