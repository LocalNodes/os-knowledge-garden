# Contrib Patch Regeneration Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Regenerate all contrib patches from the current correct state, split per-issue with drupal.org issue metadata files.

**Architecture:** Replace 3 stale bundled patches with 4 accurate per-issue patches. Generate each by diffing original package source (cloned to /tmp/) against current vendor state. Include `.md` files with drupal.org issue fields pre-filled.

**Tech Stack:** cweagans/composer-patches 1.7.3, git diff, composer

---

### Task 1: Clean up stale artifacts

**Files:**
- Delete: `patches/ai-deepchat-and-agent-fixes.patch`
- Delete: `patches/gemini-provider-chat-with-tools-and-roles.patch`
- Delete: `patches/milvus-provider-fix-in-operator-filter.patch`
- Delete: `html/modules/contrib/ai/modules/ai_assistant_api/src/Service/AgentRunner.php.rej`
- Delete: `html/modules/contrib/ai/modules/ai_chatbot/src/Controller/DeepChatApi.php.rej`
- Create: `patches/issues/` directory

**Step 1: Delete old patches and .rej files**

```bash
rm patches/ai-deepchat-and-agent-fixes.patch
rm patches/gemini-provider-chat-with-tools-and-roles.patch
rm patches/milvus-provider-fix-in-operator-filter.patch
rm html/modules/contrib/ai/modules/ai_assistant_api/src/Service/AgentRunner.php.rej
rm html/modules/contrib/ai/modules/ai_chatbot/src/Controller/DeepChatApi.php.rej
mkdir -p patches/issues
```

**Step 2: Discard working tree changes to contrib files**

The working tree has manual edits to 3 files that revert the old patches. After we write new patches and run `composer install`, these files will be correctly regenerated. Discard them now:

```bash
git checkout -- html/modules/contrib/ai/modules/ai_assistant_api/src/Service/AgentRunner.php
git checkout -- html/modules/contrib/ai/modules/ai_chatbot/src/Controller/DeepChatApi.php
git checkout -- html/modules/contrib/gemini_provider/definitions/api_defaults.yml
```

**Step 3: Commit cleanup**

```bash
git add -A patches/ html/modules/contrib/ai/modules/ai_assistant_api/src/Service/AgentRunner.php.rej html/modules/contrib/ai/modules/ai_chatbot/src/Controller/DeepChatApi.php.rej
git commit -m "chore: remove stale contrib patches and .rej files"
```

---

### Task 2: Create Patch 1 — drupal/ai AiAssistantApiRunner null safety

**Files:**
- Create: `patches/ai-assistant-api-runner-null-safety.patch`
- Create: `patches/issues/ai-assistant-api-runner-null-safety.md`

**Step 1: Generate patch from original package source**

Original package cloned to `/tmp/ai-original/` at ref `a641fd9148d4`. The current installed file at `html/modules/contrib/ai/modules/ai_assistant_api/src/AiAssistantApiRunner.php` has the fix applied. Generate a proper git-format patch:

```bash
cd /tmp/ai-original
cp modules/ai_assistant_api/src/AiAssistantApiRunner.php modules/ai_assistant_api/src/AiAssistantApiRunner.php.orig
cp /Users/proofoftom/Code/os-decoupled/fresh3/html/modules/contrib/ai/modules/ai_assistant_api/src/AiAssistantApiRunner.php modules/ai_assistant_api/src/AiAssistantApiRunner.php
git diff -- modules/ai_assistant_api/src/AiAssistantApiRunner.php > /Users/proofoftom/Code/os-decoupled/fresh3/patches/ai-assistant-api-runner-null-safety.patch
git checkout -- modules/ai_assistant_api/src/AiAssistantApiRunner.php
cd /Users/proofoftom/Code/os-decoupled/fresh3
```

Expected patch content — two hunks:
1. Line ~42: `protected UserMessage|NULL $userMessage;` → `protected UserMessage|NULL $userMessage = NULL;`
2. Line ~588: Add null check `if ($this->userMessage)` before `getMessage()`, return `[]` as fallback

**Step 2: Verify patch applies cleanly**

```bash
cd /tmp/ai-original
git apply --check /Users/proofoftom/Code/os-decoupled/fresh3/patches/ai-assistant-api-runner-null-safety.patch
echo "Exit: $?"
cd /Users/proofoftom/Code/os-decoupled/fresh3
```

Expected: exit code 0 (clean apply)

**Step 3: Create drupal.org issue metadata**

Write `patches/issues/ai-assistant-api-runner-null-safety.md` with these fields:

```markdown
# drupal.org Issue: AiAssistantApiRunner null safety

## Issue Metadata

- **Title:** AiAssistantApiRunner: fatal error when userMessage is null in getChatHistory()
- **Project:** AI (drupal/ai)
- **Component:** AI Assistant API
- **Category:** Bug report
- **Priority:** Normal
- **Status:** Active

## Issue Summary

### Problem/Motivation

`AiAssistantApiRunner::getChatHistory()` calls `$this->userMessage->getMessage()` without checking if `$userMessage` is null. When an agent is restored from tempstore (continuation request), the `userMessage` property may not be set, causing a fatal error: "Call to a member function getMessage() on null".

Additionally, the `$userMessage` property is declared as `UserMessage|NULL` but has no default value, which can cause an "Typed property must not be accessed before initialization" error in PHP 8.x.

#### Steps to reproduce

1. Configure an AI assistant with an agent that uses tool calls
2. Send a message that triggers a tool call (agent stores to tempstore)
3. The continuation request loads the agent from tempstore
4. `getChatHistory()` is called but `$userMessage` was never set on this request
5. Fatal error on `$this->userMessage->getMessage()`

### Proposed resolution

1. Initialize property with default: `protected UserMessage|NULL $userMessage = NULL;`
2. Add null check in `getChatHistory()` before accessing `getMessage()`
3. Return empty array when no user message is available

### Remaining tasks

- Review and commit

### User interface changes

None.

### API changes

None.

### Data model changes

None.
```

**Step 4: Commit**

```bash
git add patches/ai-assistant-api-runner-null-safety.patch patches/issues/ai-assistant-api-runner-null-safety.md
git commit -m "patch: drupal/ai AiAssistantApiRunner null safety fix"
```

---

### Task 3: Create Patch 2 — drupal/ai DeepChat verbose_mode passthrough

**Files:**
- Create: `patches/ai-deepchat-verbose-mode-passthrough.patch`
- Create: `patches/issues/ai-deepchat-verbose-mode-passthrough.md`

**Step 1: Generate patch**

```bash
cd /tmp/ai-original
cp modules/ai_chatbot/js/deepchat-init.js modules/ai_chatbot/js/deepchat-init.js.orig
cp /Users/proofoftom/Code/os-decoupled/fresh3/html/modules/contrib/ai/modules/ai_chatbot/js/deepchat-init.js modules/ai_chatbot/js/deepchat-init.js
git diff -- modules/ai_chatbot/js/deepchat-init.js > /Users/proofoftom/Code/os-decoupled/fresh3/patches/ai-deepchat-verbose-mode-passthrough.patch
git checkout -- modules/ai_chatbot/js/deepchat-init.js
cd /Users/proofoftom/Code/os-decoupled/fresh3
```

Expected: single hunk adding `verbose_mode: drupalSettings.ai_deepchat.verbose_mode,` to the request body object around line 296.

**Step 2: Verify patch applies cleanly**

```bash
cd /tmp/ai-original
git apply --check /Users/proofoftom/Code/os-decoupled/fresh3/patches/ai-deepchat-verbose-mode-passthrough.patch
echo "Exit: $?"
cd /Users/proofoftom/Code/os-decoupled/fresh3
```

**Step 3: Create drupal.org issue metadata**

Write `patches/issues/ai-deepchat-verbose-mode-passthrough.md`:

```markdown
# drupal.org Issue: DeepChat verbose_mode not sent to server

## Issue Metadata

- **Title:** DeepChat block verbose_mode setting is never sent in API request body
- **Project:** AI (drupal/ai)
- **Component:** AI Chatbot
- **Category:** Bug report
- **Priority:** Normal
- **Status:** Active

## Issue Summary

### Problem/Motivation

The DeepChat block has a `verbose_mode` configuration option (in Advanced settings) that controls whether the agent runs one loop at a time (verbose) or completes the full tool cycle server-side. The block correctly exposes this setting via `drupalSettings.ai_deepchat.verbose_mode` and `DeepChatApi::processMessage()` reads it from `$data['verbose_mode']`.

However, `deepchat-init.js` never includes `verbose_mode` in the request body sent to the API endpoint. As a result, `verbose_mode` is always `FALSE` server-side regardless of the block configuration.

#### Steps to reproduce

1. Place a DeepChat block and enable "Verbose mode" in Advanced settings
2. Send a message to an agent with tool calls
3. Observe that the agent completes the full tool cycle server-side (non-verbose behavior)
4. Inspect the network request body — `verbose_mode` is missing

### Proposed resolution

Add `verbose_mode: drupalSettings.ai_deepchat.verbose_mode` to the request body in `deepchat-init.js`, alongside the existing `assistant_id`, `show_copy_icon`, and `structured_results` fields.

### Remaining tasks

- Review and commit

### User interface changes

None (existing UI setting now works as intended).

### API changes

None (the API already reads this field, it just wasn't being sent).

### Data model changes

None.
```

**Step 4: Commit**

```bash
git add patches/ai-deepchat-verbose-mode-passthrough.patch patches/issues/ai-deepchat-verbose-mode-passthrough.md
git commit -m "patch: drupal/ai DeepChat verbose_mode passthrough fix"
```

---

### Task 4: Create Patch 3 — drupal/gemini_provider chat_with_tools and role fixes

**Files:**
- Create: `patches/gemini-provider-chat-with-tools-and-roles.patch`
- Create: `patches/issues/gemini-provider-chat-with-tools-and-roles.md`

**Step 1: Generate patch**

```bash
cd /tmp/gemini-original
cp src/Plugin/AiProvider/GeminiProvider.php src/Plugin/AiProvider/GeminiProvider.php.orig
cp /Users/proofoftom/Code/os-decoupled/fresh3/html/modules/contrib/gemini_provider/src/Plugin/AiProvider/GeminiProvider.php src/Plugin/AiProvider/GeminiProvider.php
git diff -- src/Plugin/AiProvider/GeminiProvider.php > /Users/proofoftom/Code/os-decoupled/fresh3/patches/gemini-provider-chat-with-tools-and-roles.patch
git checkout -- src/Plugin/AiProvider/GeminiProvider.php
cd /Users/proofoftom/Code/os-decoupled/fresh3
```

Expected: ~7 hunks covering:
- Add `ToolsFunctionOutput` import
- Add `chat_with_tools` to supported operations
- Remove system message from before the message loop
- Map `assistant` → `model` role, `tool` → `user` role
- Handle system prompt from ChatInput with legacy fallback
- Return tool calls via `ToolsFunctionOutput` instead of `handleFunctionCall`
- Fix empty role to `model` in ChatMessage constructor

**Step 2: Verify patch applies cleanly**

```bash
cd /tmp/gemini-original
git apply --check /Users/proofoftom/Code/os-decoupled/fresh3/patches/gemini-provider-chat-with-tools-and-roles.patch
echo "Exit: $?"
cd /Users/proofoftom/Code/os-decoupled/fresh3
```

**Step 3: Create drupal.org issue metadata**

Write `patches/issues/gemini-provider-chat-with-tools-and-roles.md`:

```markdown
# drupal.org Issue: Gemini provider chat_with_tools support and role mapping

## Issue Metadata

- **Title:** GeminiProvider: add chat_with_tools support, fix role mapping for ai_agents compatibility
- **Project:** Gemini Provider (drupal/gemini_provider)
- **Component:** Provider
- **Category:** Bug report
- **Priority:** Normal
- **Status:** Active

## Issue Summary

### Problem/Motivation

The Gemini provider does not work with the AI Agents module (`ai_agents`) for tool-calling workflows. Several issues prevent correct operation:

1. **`chat_with_tools` not in supported operations:** The provider only declares `chat` support, so `ai_agents` cannot use it for tool-calling agents.

2. **Role mapping:** Gemini's API uses `model` (not `assistant`) for AI responses and does not recognize the `tool` role. When `ai_agents` sends chat history with `assistant` and `tool` roles, Gemini rejects the request.

3. **System message handling:** The system message is added as a `Content` entry before the message loop, but `ai_agents` passes the system prompt via `ChatInput::getSystemPrompt()`. The provider should check ChatInput first and fall back to the legacy `setChatSystemRole` approach. Additionally, the system message should use `withSystemInstruction()` rather than being prepended as a content entry.

4. **Tool call handling:** The provider internally calls `handleFunctionCall()` and appends the result as text. For `ai_agents` compatibility, tool calls should be returned as `ToolsFunctionOutput` objects so the agent execution loop can handle tool execution and send results back to the LLM.

5. **Empty role in ChatMessage:** The non-streaming response creates `new ChatMessage('', $text)` with an empty role, which causes issues downstream.

#### Steps to reproduce

1. Install `ai_agents` module with a tool-calling agent
2. Configure the agent to use the Gemini provider
3. Send a message that triggers a tool call
4. Agent fails because `chat_with_tools` is not a supported operation

### Proposed resolution

- Add `chat_with_tools` to the list of supported operations
- Map `assistant` → `model` and `tool` → `user` in message role handling
- Handle system prompt from `ChatInput::getSystemPrompt()` with legacy fallback, using `withSystemInstruction()`
- Return tool calls as `ToolsFunctionOutput` objects instead of executing them internally
- Use `model` as the role in `ChatMessage` constructor

### Remaining tasks

- Review and commit

### User interface changes

None.

### API changes

- `chat_with_tools` is now a supported operation type for the Gemini provider.

### Data model changes

None.
```

**Step 4: Commit**

```bash
git add patches/gemini-provider-chat-with-tools-and-roles.patch patches/issues/gemini-provider-chat-with-tools-and-roles.md
git commit -m "patch: drupal/gemini_provider chat_with_tools and role mapping fixes"
```

---

### Task 5: Create Patch 4 — drupal/ai_vdb_provider_milvus filter expression fixes

**Files:**
- Create: `patches/milvus-provider-fix-filter-expressions.patch`
- Create: `patches/issues/milvus-provider-fix-filter-expressions.md`

**Step 1: Generate patch**

```bash
cd /tmp/milvus-original
cp src/Plugin/VdbProvider/MilvusProvider.php src/Plugin/VdbProvider/MilvusProvider.php.orig
cp /Users/proofoftom/Code/os-decoupled/fresh3/html/modules/contrib/ai_vdb_provider_milvus/src/Plugin/VdbProvider/MilvusProvider.php src/Plugin/VdbProvider/MilvusProvider.php
git diff -- src/Plugin/VdbProvider/MilvusProvider.php > /Users/proofoftom/Code/os-decoupled/fresh3/patches/milvus-provider-fix-filter-expressions.patch
git checkout -- src/Plugin/VdbProvider/MilvusProvider.php
cd /Users/proofoftom/Code/os-decoupled/fresh3
```

Expected: 2 hunks covering:
- Hunk 1 (line ~563): `if` → `elseif` for array IN operator, `getClient()->getPluginId()` → `getPluginId()`
- Hunk 2 (line ~577): Add `IN`/`NOT IN` handling with proper Milvus `in`/`not in` syntax for scalar values

**Step 2: Verify patch applies cleanly**

```bash
cd /tmp/milvus-original
git apply --check /Users/proofoftom/Code/os-decoupled/fresh3/patches/milvus-provider-fix-filter-expressions.patch
echo "Exit: $?"
cd /Users/proofoftom/Code/os-decoupled/fresh3
```

**Step 3: Create drupal.org issue metadata**

Write `patches/issues/milvus-provider-fix-filter-expressions.md`:

```markdown
# drupal.org Issue: MilvusProvider filter expression fixes

## Issue Metadata

- **Title:** MilvusProvider: fix IN/NOT IN filter expressions and array condition handling in buildFilters()
- **Project:** AI VDB Provider Milvus (drupal/ai_vdb_provider_milvus)
- **Component:** Provider
- **Category:** Bug report
- **Priority:** Normal
- **Status:** Active

## Issue Summary

### Problem/Motivation

`MilvusProvider::buildFilters()` has three bugs in the filter expression building logic that cause incorrect Milvus query syntax, resulting in zero results or errors.

**Bug 1: Missing `elseif` for array IN operator (line ~566)**

The array condition handling uses two consecutive `if` statements for `=` and `IN` operators. When the operator is `=`, the first `if` matches and adds a `JSON_CONTAINS_ALL` filter. But then the code falls through to the `else` block (since the second `if` for `IN` doesn't match), which adds a spurious warning message. This should be `elseif`.

**Bug 2: Incorrect `getClient()->getPluginId()` call (line ~571)**

The warning message calls `$this->getClient()->getPluginId()` but `getPluginId()` is a method on the provider itself (`AiVdbProviderClientBase`), not on the Milvus client. This causes a "method not found" error.

**Bug 3: Invalid IN/NOT IN syntax for scalar values (line ~580)**

For scalar (non-array) filter conditions with `IN` or `NOT IN` operators, the code generates `(field IN "val1","val2")` which is invalid Milvus syntax. Milvus requires lowercase `in`/`not in` with bracket-wrapped values: `(field in ["val1","val2"])`.

#### Steps to reproduce

1. Create a Search API index with a Milvus backend
2. Add a field used for filtering (e.g., `group_id`)
3. Search with a filter using `IN` operator (e.g., group membership list)
4. Results return empty because the filter expression is invalid Milvus syntax

### Proposed resolution

1. Change `if` to `elseif` for the array `IN` operator check to prevent fall-through
2. Change `$this->getClient()->getPluginId()` to `$this->getPluginId()`
3. Add explicit handling for `IN`/`NOT IN` operators on scalar values: generate `field in [values]` and `field not in [values]` syntax

### Remaining tasks

- Review and commit

### User interface changes

None.

### API changes

None.

### Data model changes

None.
```

**Step 4: Commit**

```bash
git add patches/milvus-provider-fix-filter-expressions.patch patches/issues/milvus-provider-fix-filter-expressions.md
git commit -m "patch: drupal/ai_vdb_provider_milvus filter expression fixes"
```

---

### Task 6: Update composer.json and verify patches apply

**Files:**
- Modify: `composer.json`

**Step 1: Update composer.json patches section**

Replace the `extra.patches` section with:

```json
{
  "drupal/ai": {
    "AiAssistantApiRunner: fix uninitialized userMessage null safety": "patches/ai-assistant-api-runner-null-safety.patch",
    "DeepChat: pass verbose_mode setting in API request body": "patches/ai-deepchat-verbose-mode-passthrough.patch"
  },
  "drupal/gemini_provider": {
    "Add chat_with_tools support, fix role mapping for ai_agents compatibility": "patches/gemini-provider-chat-with-tools-and-roles.patch"
  },
  "drupal/ai_vdb_provider_milvus": {
    "Fix IN/NOT IN filter expressions and array condition handling": "patches/milvus-provider-fix-filter-expressions.patch"
  }
}
```

Note: The old `drupal/ai` patch had 4 files. The new setup has 2 smaller patches touching only 2 files total. The AgentRunner.php and DeepChatApi.php changes from the old patch are no longer needed (reverted to upstream).

**Step 2: Force reinstall patched packages**

Composer-patches only repatches when packages change. Since the package versions haven't changed, we need to force reinstall:

```bash
rm -rf html/modules/contrib/ai html/modules/contrib/gemini_provider html/modules/contrib/ai_vdb_provider_milvus
composer install
```

Expected: composer downloads packages and applies all 4 patches successfully. Watch for "Applying patch" messages in output.

**Step 3: Verify no unexpected changes**

```bash
git diff --stat HEAD -- html/modules/contrib/
```

Expected: Only the files targeted by our patches should differ from the committed state. The old patched changes to `AgentRunner.php`, `DeepChatApi.php`, and `api_defaults.yml` should now be gone (replaced by upstream originals).

**Step 4: Commit updated contrib files and composer.json**

```bash
git add composer.json html/modules/contrib/
git commit -m "chore: regenerate contrib patches as per-issue granular patches

Replaces 3 stale bundled patches with 4 accurate per-issue patches:
- ai-assistant-api-runner-null-safety.patch (drupal/ai)
- ai-deepchat-verbose-mode-passthrough.patch (drupal/ai)
- gemini-provider-chat-with-tools-and-roles.patch (drupal/gemini_provider)
- milvus-provider-fix-filter-expressions.patch (drupal/ai_vdb_provider_milvus)

Old patches included verbose mode, setLooped, try/catch, and debug logging
changes that were later found incorrect and reverted. New patches contain
only the verified-correct fixes."
```

---

### Task 7: Clean up temporary files

**Step 1: Remove cloned repos**

```bash
rm -rf /tmp/ai-original /tmp/gemini-original /tmp/milvus-original
rm -f /tmp/agentrunner-desired.php /tmp/deepchatapi-desired.php
```

**Step 2: Verify final state**

```bash
git status
git log --oneline -6
ls patches/
ls patches/issues/
```

Expected:
- Clean working tree
- 6 new commits (cleanup, 4 patches, composer update)
- `patches/` has 4 `.patch` files
- `patches/issues/` has 4 `.md` files
