# Contrib Patch Regeneration Design

## Problem

Three contrib packages have local modifications applied via `cweagans/composer-patches`. The existing patches are stale — they include code (verbose mode, setLooped, try/catch) that was later found to be incorrect and manually reverted in the working tree. Additionally, patches are bundled by package rather than by logical issue, making upstream submission difficult.

## Goal

Regenerate all contrib patches from the current correct state, split into per-issue granular patches suitable for submission to drupal.org issue queues. Include a `.md` file per patch with pre-filled drupal.org issue metadata.

## Packages & Patches

### drupal/ai (3 patches)

**Patch 1: `ai-assistant-api-runner-null-safety.patch`**
- File: `modules/ai_assistant_api/src/AiAssistantApiRunner.php`
- Fix: Initialize `$userMessage = NULL`, add null check before `getMessage()`
- Issue: Uninitialized property causes fatal error when agent history exists but no new user message

**Patch 2: `ai-deepchat-should-continue-fix.patch`**
- Files: `modules/ai_chatbot/src/Controller/DeepChatApi.php`, `modules/ai_chatbot/js/deepchat-init.js`
- Fix: `should_continue` checks `!empty($normalizedResponse->getTools())` unconditionally (remove verbose mode gate). Pass `verbose_mode` setting to JS.
- Issue: When verbose mode is off, pending tools are never communicated to the browser, breaking the tool execution cycle

**Patch 3: `ai-agent-runner-remove-debug-logging.patch`**
- Files: `modules/ai_assistant_api/src/Service/AgentRunner.php`, `modules/ai_chatbot/src/Controller/DeepChatApi.php`
- Fix: Remove `\Drupal::logger()` debug calls that were left in from development
- Issue: Debug logging in production code adds noise to watchdog

### drupal/gemini_provider (1 patch)

**Patch 4: `gemini-provider-remove-duplicate-chat-with-tools.patch`**
- File: `definitions/api_defaults.yml`
- Fix: Remove `chat_with_tools` section that duplicates `chat` configuration
- Issue: Duplicate definition causes configuration conflicts

### drupal/ai_vdb_provider_milvus (1 patch)

**Patch 5: `milvus-provider-fix-filter-expressions.patch`**
- File: `src/Plugin/VdbProvider/MilvusProvider.php`
- Fixes:
  - IN/NOT IN operator: generate `field in ["val1","val2"]` syntax instead of `field IN "val1","val2"`
  - Array condition: change `if` to `elseif` to prevent fall-through to scalar handling
  - `getPluginId()`: fix `getClient()->getPluginId()` → `getPluginId()` (method is on the provider, not the client)
- Issue: Filter expressions generate invalid Milvus syntax, causing zero results for authenticated users with group filtering

## Deliverables Per Patch

Each patch gets:
1. `patches/<patch-name>.patch` — the actual patch file
2. `patches/issues/<patch-name>.md` — drupal.org issue metadata (title, summary, category, priority)

## composer.json Updates

Replace the 3 bundled entries in `extra.patches` with 5 granular entries:

```json
{
  "drupal/ai": {
    "AiAssistantApiRunner: fix uninitialized userMessage null safety": "patches/ai-assistant-api-runner-null-safety.patch",
    "DeepChat: should_continue must check tools unconditionally": "patches/ai-deepchat-should-continue-fix.patch",
    "Remove debug logging from AgentRunner and DeepChatApi": "patches/ai-agent-runner-remove-debug-logging.patch"
  },
  "drupal/gemini_provider": {
    "Remove duplicate chat_with_tools definition from api_defaults": "patches/gemini-provider-remove-duplicate-chat-with-tools.patch"
  },
  "drupal/ai_vdb_provider_milvus": {
    "Fix IN/NOT IN filter expressions and array condition handling": "patches/milvus-provider-fix-filter-expressions.patch"
  }
}
```

## Verification

1. Delete existing patches
2. Write new patches
3. Run `composer install` to verify patches apply cleanly
4. Verify no working tree changes remain in contrib files after install
