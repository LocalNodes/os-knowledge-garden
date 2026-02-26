# Design: Switch to Gemini Provider + Fix Deepchat Loop

## Problem

Three interconnected issues prevent the AI chatbot from working:

1. **Ollama not running** - embeddings provider is offline, Milvus is empty, RAG returns nothing
2. **Deepchat infinite loop** - `should_continue: true` returned even when verbose mode is off, causing the browser to POST endlessly
3. **DeepSeek tool calling fragile** - hand-patched chat() method, tool execution cycle doesn't complete reliably

## Solution

Replace Ollama + DeepSeek with Gemini as the single provider for chat, tool calling, and embeddings. Fix the deepchat continuation logic to prevent infinite loops regardless of provider.

### Architecture Change

```
Before:  Ollama (embeddings, local) + DeepSeek (chat/tools, cloud)
After:   Gemini (embeddings + chat + tools, cloud)
```

### Part 1: Install & Configure Gemini Provider

- `composer require drupal/gemini_provider:1.x-dev`
- Enable the module
- Configure Gemini API key via Key module
- Set Gemini as default provider for `chat`, `chat_with_tools`, and `embeddings`

### Part 2: Patch Gemini Provider for Tool Calling

The Gemini provider's `chat()` method currently executes tools internally via `handleFunctionCall()` and returns text. For proper `ai_agents` integration, it must return tool_calls to the agent loop:

- Add `'chat_with_tools'` to `getSupportedOperationTypes()`
- Add `chat_with_tools` to model capabilities in `api_defaults.yml`
- Modify `chat()`: when Gemini returns a `functionCall`, create `ToolsFunctionOutput` objects and set on `ChatMessage` instead of executing tools inline
- The `ai_agents` `determineSolvability()` loop handles tool execution and sending results back to Gemini

### Part 3: Fix Deepchat Infinite Loop

**DeepChatApi.php line 308:**

```php
// Only set should_continue when verbose mode is ON
$should_continue = $this->aiAssistantClient->getVerboseMode()
    && !empty($normalizedResponse->getTools());
```

Re-enable verbose mode (uncomment lines 162-164) for UX feedback.

**AgentRunner.php:** Add graceful fallback when verbose_mode=FALSE and agent doesn't finish within max_loops.

### Part 4: Update Embeddings Config

- Change search server `embeddings_engine` from `ollama__nomic-embed-text` to `gemini__text-embedding-004`
- Both produce 768 dimensions - Milvus collection schema compatible
- Reset tracker and reindex social_posts + social_comments

### Part 5: End-to-End Verification

- Milvus has data (non-empty collection)
- RAG via drush returns content with citations
- Browser chatbot: no infinite loop, shows progress, returns RAG answer

## Risks

- Gemini API costs (vs free local Ollama)
- gemini_provider is 1.x-dev (beta quality)
- All existing data must be reindexed (fine since Milvus is empty)
