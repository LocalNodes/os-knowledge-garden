---
status: resolved
trigger: "Continued: Still showing 'Could not resolve the ollama__nomic-embed-text:latest embeddings engine.'"
created: 2026-02-24T02:30:00Z
updated: 2026-02-24T02:40:00Z
---

## Current Focus

hypothesis: The embeddings_engine format uses the machine name (underscores), not the original model name with dashes/colons
test: Update config to ollama__nomic_embed_text_latest
expecting: Server status shows correct embeddings engine
next_action: Apply the fix

## Symptoms

expected: Server status should show working Ollama embeddings engine connection
actual: "Could not resolve the ollama__nomic-embed-text:latest embeddings engine."
errors: Engine resolution fails because format is wrong
reproduction: View server status page
started: After previous fix applied wrong format

## Eliminated

- hypothesis: Format should be "ollama__nomic-embed-text:latest" (original model name)
  evidence: OllamaProvider::getMachineName() transforms model names to machine format (underscores)
  timestamp: 2026-02-24T02:33:00Z

## Evidence

- timestamp: 2026-02-24T02:30:00Z
  checked: Ollama API for available models
  found: Model is "nomic-embed-text:latest" in Ollama
  implication: Model exists in Ollama

- timestamp: 2026-02-24T02:32:00Z
  checked: OllamaProvider::getMachineName() method
  found: Transforms model name to machine name: replaces non-alphanumeric with underscores, lowercase
  implication: "nomic-embed-text:latest" becomes "nomic_embed_text_latest"

- timestamp: 2026-02-24T02:33:00Z
  checked: AiSearchBackendEmbeddingsEngineTrait::getEmbeddingEnginesOptions()
  found: Option key is "$id . '__' . $model" where $model is the KEY (machine name) from getConfiguredModels()
  implication: The correct format is "ollama__nomic_embed_text_latest" not "ollama__nomic-embed-text:latest"

- timestamp: 2026-02-24T02:34:00Z
  checked: Current configuration via drush
  found: embeddings_engine is set to "ollama__nomic-embed-text:latest"
  implication: Wrong format is in config, needs to be fixed

## Resolution

root_cause: The embeddings_engine config uses the original model name format (nomic-embed-text:latest) but the system expects the machine name format (nomic_embed_text_latest) which is how the model is keyed in the provider's model list
fix: Updated embeddings_engine from "ollama__nomic-embed-text:latest" to "ollama__nomic_embed_text_latest"
verification: |
  - embeddingsVectorSize() returns 768 correctly
  - Engine found in available options: "Ollama | nomic-embed-text:latest"
  - Provider resolves correctly
files_changed: [search_api.server.ai_knowledge_garden config]
