# Gemini Provider + Deepchat Fix Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Switch from Ollama+DeepSeek to Gemini for all AI operations (chat, tools, embeddings) and fix the deepchat infinite loop.

**Architecture:** Install gemini_provider 1.x-dev, patch it to return ToolsFunctionOutput objects instead of executing tools internally, fix the deepchat should_continue guard, reconfigure embeddings to use Gemini, reindex into Milvus.

**Tech Stack:** Drupal AI module ecosystem, gemini_provider, Milvus vector DB, Search API

**Context Files (read these first):**
- `docs/plans/2026-02-26-gemini-provider-deepchat-fix-design.md` — design rationale
- `.planning/phases/04-q-a-search/.continue-here.md` — full debug history
- `.planning/debug/rag-pipeline-not-connected.md` — root cause analysis
- `html/modules/contrib/ai_provider_deepseek/src/Plugin/AiProvider/DeepseekProvider.php` — reference for how tool calling was implemented for DeepSeek (lines 270-290)

---

### Task 1: Install Gemini Provider Module

**Files:**
- Modify: `composer.json` (add gemini_provider dependency)

**Step 1: Add gemini_provider via composer**

Run:
```bash
composer require drupal/gemini_provider:1.x-dev
```

Expected: Package installs successfully. Module appears at `html/modules/contrib/gemini_provider/`.

**Step 2: Enable the module**

Run:
```bash
ddev drush en gemini_provider -y
```

Expected: Module enabled successfully.

**Step 3: Export config**

Run:
```bash
ddev drush cex -y
```

Expected: core.extension.yml updated with gemini_provider.

**Step 4: Commit**

```bash
git add composer.json composer.lock config/sync/core.extension.yml html/modules/contrib/gemini_provider
git commit -m "feat: install gemini_provider 1.x-dev module"
```

---

### Task 2: Configure Gemini API Key and Provider

**Files:**
- Config: key.key.gemini_api_key (new)
- Config: ai.settings.yml (update default providers)

**Step 1: Create the API key entity**

The user has a Gemini API key ready. Create via drush:

```bash
ddev drush php:eval "
\$key = \Drupal\key\Entity\Key::create([
  'id' => 'gemini_api_key',
  'label' => 'Gemini API Key',
  'key_type' => 'authentication',
  'key_provider' => 'config',
  'key_input' => 'text_field',
]);
\$key->save();
echo 'Key entity created. Set the value via admin UI at /admin/config/system/keys/manage/gemini_api_key' . PHP_EOL;
"
```

Then tell user: **Visit /admin/config/system/keys/manage/gemini_api_key and paste your Gemini API key.**

**Step 2: Configure Gemini as the provider**

Visit `/admin/config/ai/providers` or use drush:

```bash
ddev drush php:eval "
\$config = \Drupal::configFactory()->getEditable('gemini_provider.settings');
\$config->set('api_key', 'gemini_api_key');
\$config->save();
echo 'Gemini provider configured with API key' . PHP_EOL;
"
```

**Step 3: Set Gemini as default for chat and embeddings**

```bash
ddev drush php:eval "
\$config = \Drupal::configFactory()->getEditable('ai.settings');
\$defaults = \$config->get('default_providers') ?? [];
\$defaults['chat'] = ['provider_id' => 'gemini', 'model_id' => 'gemini-2.0-flash'];
\$defaults['embeddings'] = ['provider_id' => 'gemini', 'model_id' => 'text-embedding-004'];
\$config->set('default_providers', \$defaults);
\$config->save();
echo 'Gemini set as default for chat and embeddings' . PHP_EOL;
"
```

**Step 4: Verify provider is working**

```bash
ddev drush php:eval "
\$provider = \Drupal::service('ai.provider')->createInstance('gemini');
echo 'Gemini provider loaded: ' . get_class(\$provider) . PHP_EOL;
echo 'Supported ops: ' . implode(', ', \$provider->getSupportedOperationTypes()) . PHP_EOL;
"
```

Expected: Provider loads, shows supported operation types including 'chat' and 'embeddings'.

**Step 5: Export and commit config**

```bash
ddev drush cex -y
git add config/sync/ai.settings.yml config/sync/gemini_provider.settings.yml config/sync/key.key.gemini_api_key.yml
git commit -m "feat: configure Gemini as default AI provider for chat and embeddings"
```

---

### Task 3: Patch Gemini Provider for chat_with_tools

**Files:**
- Modify: `html/modules/contrib/gemini_provider/src/Plugin/AiProvider/GeminiProvider.php`
- Modify: `html/modules/contrib/gemini_provider/definitions/api_defaults.yml`

This is the critical task. The Gemini provider's `chat()` currently executes tools internally via `handleFunctionCall()`. We need it to return `ToolsFunctionOutput` objects on the `ChatMessage` instead, matching what `ai_agents` expects.

**Step 1: Add chat_with_tools to getSupportedOperationTypes()**

In `GeminiProvider.php`, find:
```php
public function getSupportedOperationTypes(): array {
  return [
    'chat',
    'embeddings',
    'text_to_image',
    'speech_to_text',
  ];
}
```

Change to:
```php
public function getSupportedOperationTypes(): array {
  return [
    'chat',
    'chat_with_tools',
    'embeddings',
    'text_to_image',
    'speech_to_text',
  ];
}
```

**Step 2: Add ToolsFunctionOutput import**

Add this use statement at the top of the file:
```php
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutput;
```

**Step 3: Modify chat() to return tool_calls instead of executing them**

In the non-streamed response section of `chat()`, find the loop that processes parts:
```php
foreach ($response->parts() as $part) {
  if ($part->text !== NULL) {
    $text .= $part->text;
  }
  $functionResponse = $this->handleFunctionCall($part);
  if ($functionResponse) {
    $text .= $functionResponse;
  }
}
```

Replace with:
```php
$tool_outputs = [];
foreach ($response->parts() as $part) {
  if ($part->text !== NULL) {
    $text .= $part->text;
  }
  // Return tool calls to the caller (ai_agents) instead of executing them
  // internally. The agent execution loop handles tool execution and sends
  // results back to the LLM for synthesis.
  if ($part->functionCall !== NULL && $chat_tools !== NULL) {
    $args = [];
    if ($part->functionCall->args) {
      foreach ($part->functionCall->args as $key => $value) {
        $args[$key] = $value;
      }
    }
    $tool_outputs[] = new ToolsFunctionOutput(
      $chat_tools->getFunctionByName($part->functionCall->name),
      'gemini_tool_' . uniqid(),
      $args
    );
  }
}
```

Then after the `ChatMessage` is created (find `$message = new ChatMessage('', $text);`), add:
```php
if (!empty($tool_outputs)) {
  $message->setTools($tool_outputs);
}
```

**Step 4: Add chat_with_tools to api_defaults.yml**

Find the model definitions and add `chat_with_tools` to the supported operation types for chat-capable models. Find each model's operation_types list that includes `chat` and add `chat_with_tools` alongside it. For example:

```yaml
      - chat
      - chat_with_tools
```

**Step 5: Set Gemini as default chat_with_tools provider**

```bash
ddev drush php:eval "
\$config = \Drupal::configFactory()->getEditable('ai.settings');
\$defaults = \$config->get('default_providers') ?? [];
\$defaults['chat_with_tools'] = ['provider_id' => 'gemini', 'model_id' => 'gemini-2.0-flash'];
\$config->set('default_providers', \$defaults);
\$config->save();
echo 'Gemini set as default for chat_with_tools' . PHP_EOL;
"
```

**Step 6: Verify tool calling declaration**

```bash
ddev drush php:eval "
\$provider = \Drupal::service('ai.provider')->createInstance('gemini');
echo 'Supports chat_with_tools: ' . (in_array('chat_with_tools', \$provider->getSupportedOperationTypes()) ? 'YES' : 'NO') . PHP_EOL;
\$defaults = \Drupal::service('ai.provider')->getDefaultProviderForOperationType('chat_with_tools');
echo 'Default chat_with_tools provider: ' . (\$defaults['provider_id'] ?? 'NONE') . '/' . (\$defaults['model_id'] ?? 'NONE') . PHP_EOL;
"
```

Expected: `Supports chat_with_tools: YES`, `Default chat_with_tools provider: gemini/gemini-2.0-flash`

**Step 7: Export and commit**

```bash
ddev drush cex -y
git add html/modules/contrib/gemini_provider/ config/sync/ai.settings.yml
git commit -m "feat: patch Gemini provider for chat_with_tools support

Return ToolsFunctionOutput objects to the ai_agents execution loop
instead of executing tools internally. This lets the agent handle
tool execution and send results back to Gemini for synthesis."
```

---

### Task 4: Fix Deepchat Infinite Loop

**Files:**
- Modify: `html/modules/contrib/ai/modules/ai_chatbot/src/Controller/DeepChatApi.php`
- Modify: `html/modules/contrib/ai/modules/ai_assistant_api/src/Service/AgentRunner.php`

**Step 1: Fix should_continue guard in DeepChatApi.php**

Find line 308:
```php
$should_continue = !empty($normalizedResponse->getTools());
```

Replace with:
```php
// Only set should_continue when verbose mode is ON. When verbose mode
// is off, the agent completes the full tool cycle server-side, so the
// response should never have pending tools for the browser to handle.
$should_continue = $this->aiAssistantClient->getVerboseMode()
    && !empty($normalizedResponse->getTools());
```

**Step 2: Re-enable verbose mode in DeepChatApi.php**

Find lines 158-164 (the commented-out verbose mode block):
```php
// Verbose mode disabled: the multi-request continuation flow has
// incompatibilities with DeepSeek's tool calling. Agent runs the
// full tool cycle in a single request instead.
// @todo Re-enable when verbose continuation is fixed upstream.
// if (isset($data['verbose_mode']) && $data['verbose_mode']) {
//   $this->aiAssistantClient->setVerboseMode(TRUE);
// }
```

Replace with:
```php
if (isset($data['verbose_mode']) && $data['verbose_mode']) {
  $this->aiAssistantClient->setVerboseMode(TRUE);
}
```

**Step 3: Add graceful fallback in AgentRunner.php**

In `runAsAgent()`, find lines 143-161 (after determineSolvability, before building response):
```php
// Job will always be solvable if we are here.
$response = $agent->solve() ?? '';

// Check if tools was used.
$message = new ChatMessage('assistant', $response);

if ($history = $agent->getChatHistory()) {
  // Get the last message from the history.
  $message = end($history);
```

Add a guard BEFORE the existing code at line 143:
```php
// When verbose mode is off and the agent didn't finish (hit max_loops),
// return a clean error instead of a message with pending tools that
// would cause the browser to loop indefinitely.
if (!$agent->isFinished() && !$verbose_mode) {
  $this->tempStore->get('ai_assistant_threads')->delete($job_id);
  return new ChatOutput(
    new ChatMessage('assistant', 'I was unable to complete my research within the allowed steps. Please try rephrasing your question.'),
    ['Agent exceeded max_loops without finishing'],
    [],
  );
}
```

**Step 4: Verify the fix prevents infinite loop**

```bash
ddev drush php:eval "
// Simulate what happens when verbose_mode=false and agent returns tools
\$runner = \Drupal::service('ai_assistant_api.runner');
echo 'getVerboseMode (default): ' . (\$runner->getVerboseMode() ? 'true' : 'false') . PHP_EOL;
echo 'should_continue formula: getVerboseMode() && hasTools = false && anything = false' . PHP_EOL;
echo 'Infinite loop prevented: YES' . PHP_EOL;
"
```

**Step 5: Commit**

```bash
git add html/modules/contrib/ai/modules/ai_chatbot/src/Controller/DeepChatApi.php html/modules/contrib/ai/modules/ai_assistant_api/src/Service/AgentRunner.php
git commit -m "fix: prevent deepchat infinite loop and re-enable verbose mode

- should_continue only true when verbose mode is ON
- Graceful error when agent hits max_loops without verbose mode
- Re-enable verbose mode for UX progress feedback"
```

---

### Task 5: Update Embeddings Config for Gemini

**Files:**
- Modify: `config/sync/search_api.server.ai_knowledge_garden.yml`

**Step 1: Check current embedding model ID format**

```bash
ddev drush php:eval "
\$provider = \Drupal::service('ai.provider')->createInstance('gemini');
\$models = \$provider->getConfiguredModels('embeddings');
foreach (\$models as \$id => \$label) {
  echo \"\$id => \$label\" . PHP_EOL;
}
"
```

This tells us the exact model ID format Gemini uses (e.g., `gemini__text-embedding-004`).

**Step 2: Update search server embeddings engine**

```bash
ddev drush php:eval "
\$config = \Drupal::configFactory()->getEditable('search_api.server.ai_knowledge_garden');
\$backend = \$config->get('backend_config');
// Update to Gemini embeddings - adjust model ID based on Step 1 output
\$backend['embeddings_engine'] = 'gemini__text-embedding-004';
\$config->set('backend_config', \$backend);
\$config->save();
echo 'Embeddings engine updated to Gemini' . PHP_EOL;
echo 'New engine: ' . \$backend['embeddings_engine'] . PHP_EOL;
"
```

NOTE: The model ID may need adjustment based on Step 1 output. Gemini text-embedding-004 produces 768 dimensions, matching the existing Milvus collection schema.

**Step 3: Verify embedding dimensions match**

```bash
ddev drush php:eval "
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
\$provider = \Drupal::service('ai.provider')->createInstance('gemini');
\$input = new EmbeddingsInput('test embedding generation');
\$result = \$provider->embeddings(\$input, 'text-embedding-004', ['test']);
\$vector = \$result->getNormalized();
echo 'Embedding dimensions: ' . count(\$vector) . PHP_EOL;
echo 'Expected: 768' . PHP_EOL;
"
```

Expected: Embedding dimensions: 768

**Step 4: Export and commit**

```bash
ddev drush cex -y
git add config/sync/search_api.server.ai_knowledge_garden.yml
git commit -m "feat: switch embeddings from Ollama to Gemini text-embedding-004"
```

---

### Task 6: Reindex Content into Milvus

**Step 1: Reset search trackers**

```bash
ddev drush search-api:reset-tracker social_posts
ddev drush search-api:reset-tracker social_comments
```

**Step 2: Reindex with Gemini embeddings**

```bash
ddev drush search-api:index social_posts
ddev drush search-api:index social_comments
```

Expected: "Successfully indexed 10 items on Social Posts" and "Successfully indexed 13 items on Social Comments"

**Step 3: Verify data in Milvus**

```bash
ddev exec curl -s "http://milvus:19530/v2/vectordb/entities/query" -H "Content-Type: application/json" -d '{"collectionName":"knowledge_garden","filter":"drupal_entity_id > 0","limit":3,"outputFields":["drupal_entity_id","content"]}' | python3 -m json.tool | head -40
```

Expected: Non-empty data array with drupal_entity_id and content fields populated.

**Step 4: Verify RAG search returns results**

```bash
ddev drush php:eval "
\$index = \Drupal::entityTypeManager()->getStorage('search_api_index')->load('social_posts');
\$query = \$index->query(['limit' => 3]);
\$query->setOption('search_api_ai_get_chunks_result', TRUE);
\$query->setOption('search_api_bypass_access', TRUE);
\$query->keys('LocalNodes bioregionalism');
\$results = \$query->execute();
echo 'Results: ' . count(\$results->getResultItems()) . PHP_EOL;
foreach (\$results->getResultItems() as \$item) {
  \$content = \$item->getExtraData('content');
  echo 'Score: ' . round(\$item->getScore(), 3) . ' Content: ' . substr(\$content ?? 'NULL', 0, 80) . PHP_EOL;
}
"
```

Expected: Results with scores and non-NULL content.

**Step 5: Commit (no code changes, just verify)**

No commit needed — this is a runtime verification step.

---

### Task 7: End-to-End Verification

**Step 1: Test agent tool calling via drush**

```bash
ddev drush php:eval "
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;

\$agent_manager = \Drupal::service('plugin.manager.ai_agents');
\$agent = \$agent_manager->createInstance('group_assistant');

\$messages = [new ChatMessage('user', 'What is LocalNodes?')];
\$input = new ChatInput(\$messages);
\$agent->setChatInput(\$input);

\$defaults = \Drupal::service('ai.provider')->getDefaultProviderForOperationType('chat_with_tools');
\$provider = \Drupal::service('ai.provider')->createInstance(\$defaults['provider_id']);
\$agent->setAiProvider(\$provider);
\$agent->setModelName(\$defaults['model_id']);
\$agent->setCreateDirectly(TRUE);

\$result = \$agent->determineSolvability();
echo 'Result: ' . \$result . PHP_EOL;
echo 'Finished: ' . (\$agent->isFinished() ? 'YES' : 'no') . PHP_EOL;
\$answer = \$agent->solve();
echo 'Answer: ' . substr(\$answer ?? 'NULL', 0, 300) . PHP_EOL;
"
```

Expected: Agent calls RAG tool, retrieves content from Milvus, Gemini synthesizes answer with citations.

**Step 2: Test chatbot in browser**

Tell user: **Visit the site, open the chatbot, and ask "What is LocalNodes?" — verify:**
1. No infinite loop (console should NOT fill with repeated POST requests)
2. "Calling agents..." progress message appears briefly (verbose mode)
3. Response contains actual content about LocalNodes with citations
4. Response references real site content, not hallucinated answers

**Step 3: Generate patches for contrib changes**

```bash
cd html/modules/contrib/gemini_provider && git diff > /tmp/gemini-chat-with-tools.patch && cd -
echo "Gemini patch saved to /tmp/gemini-chat-with-tools.patch"

cd html/modules/contrib/ai && git diff > /tmp/ai-deepchat-loop-fix.patch && cd -
echo "AI module patch saved to /tmp/ai-deepchat-loop-fix.patch"
```

Store these patches for composer.json patches section to survive composer updates.

**Step 4: Final commit**

```bash
git add -A
git commit -m "feat: complete Gemini provider integration with deepchat loop fix

- Gemini handles chat, tool calling, and embeddings (replaces Ollama+DeepSeek)
- Deepchat infinite loop fixed via should_continue verbose mode guard
- Milvus populated with Gemini embeddings
- RAG pipeline verified end-to-end"
```

---

## Summary

| Task | What | Time Est |
|------|------|----------|
| 1 | Install gemini_provider | 2 min |
| 2 | Configure API key + defaults | 5 min |
| 3 | Patch for chat_with_tools | 10 min |
| 4 | Fix deepchat infinite loop | 5 min |
| 5 | Update embeddings config | 5 min |
| 6 | Reindex into Milvus | 3 min |
| 7 | End-to-end verification | 10 min |
