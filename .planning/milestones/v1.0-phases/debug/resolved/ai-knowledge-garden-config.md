---
status: resolved
trigger: "AI Knowledge Garden server shows errors and crashes on edit"
created: 2026-02-24T00:00:00Z
updated: 2026-02-24T01:50:00Z
---

## Current Focus

hypothesis: RESOLVED
test: All configuration issues fixed
expecting: Server Edit form works without TypeError
next_action: Archive session

## Symptoms

expected: The ai_knowledge_garden Search API server should show a working Ollama embeddings engine connection and connected Milvus database. Editing the server should show the configuration form.
actual: |
  1. Server status page shows "Embeddings engine: Could not resolve the ollama embeddings engine"
  2. Server status shows "Error: Code: 800, Message: database not found, database: knowledge_garden"
  3. Editing the server throws a TypeError
errors: |
  TypeError: Drupal\ai_provider_ollama\Plugin\AiProvider\OllamaProvider::embeddingsVectorSize(): Argument #1 ($model_id) must be of type string, null given in Drupal\ai_provider_ollama\Plugin\AiProvider\OllamaProvider->embeddingsVectorSize() (line 277 of modules/contrib/ai_provider_ollama/src/Plugin/AiProvider/OllamaProvider.php).
  
  Call stack:
  - OllamaProvider->embeddingsVectorSize() (line 277)
  - ProviderProxy->__call() (line 136)
  - AiSearchBackendPluginBase->engineConfigurationForm() (line 57)
  - AiSearchBackendPluginBase->buildConfigurationForm() (line 264)
  - SearchApiAiSearchBackend->buildConfigurationForm() (line 222)
  - ServerForm->buildBackendConfigForm() (line 222)
reproduction: |
  1. Navigate to https://fresh3.ddev.site/admin/config/search/search-api/server/ai_knowledge_garden
  2. Observe error messages in server status
  3. Click "Edit" to trigger TypeError
started: Issue discovered after Phase 2 UAT completion

## Eliminated

<!-- APPEND only - prevents re-investigating -->

## Evidence

- timestamp: 2026-02-24T00:00:00Z
  checked: OllamaProvider.php line 277
  found: embeddingsVectorSize() method requires string $model_id parameter
  implication: The method signature is correct, problem is the caller passing null

- timestamp: 2026-02-24T00:00:00Z
  checked: Search API server configuration via drush
  found: embeddings_engine is set to "ollama" (just provider name, no model)
  implication: The config should be "ollama__model_name" format

- timestamp: 2026-02-24T00:00:00Z
  checked: AiSearchBackendEmbeddingsEngineTrait.php lines 134-136
  found: Code explodes embeddings_engine on "__" expecting "provider__model" format, then passes $parts[1] (model) to embeddingsVectorSize()
  implication: When config is just "ollama", $parts[1] is null, causing TypeError

- timestamp: 2026-02-24T01:40:00Z
  checked: Form building after embeddings_engine fix
  found: embedding_strategy was "default" which doesn't exist (valid: average_pool, contextual_chunks)
  implication: Updated embedding_strategy to "average_pool"

- timestamp: 2026-02-24T01:45:00Z
  checked: Complete form building test after all fixes
  found: Form builds successfully, provider loads, vector size returns 768
  implication: TypeError on Edit form is fully resolved

## Resolution

root_cause: Two configuration issues: (1) embeddings_engine was "ollama" instead of "ollama__model" format, causing null model_id in embeddingsVectorSize() call; (2) database_name was "knowledge_garden" but Milvus standalone doesn't support database creation via REST API
fix: |
  1. Updated embeddings_engine from "ollama" to "ollama__nomic-embed-text:latest" (INCORRECT - see v2 session)
  2. Updated database_name from "knowledge_garden" to "default" (Milvus standalone only supports "default" database via REST API)
  3. Created the "knowledge_garden" collection in the default database with 768 dimensions and COSINE metric
  
  **REVISED in v2 session:** The correct format is "ollama__nomic_embed_text_latest" (machine name with underscores, not original model name with dashes/colons)
verification: |
  - embeddingsVectorSize() returns 768 correctly
  - Milvus ping succeeds
  - Collection "knowledge_garden" created successfully in default database
  - Collection has correct configuration: 768 dimensions, COSINE metric, loaded state
files_changed: []
