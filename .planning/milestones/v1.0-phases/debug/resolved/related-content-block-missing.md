---
status: resolved
trigger: "The related content block is no longer displaying on Topics or Event pages."
created: 2026-02-27T00:00:00Z
updated: 2026-02-28T00:00:00Z
resolved: 2026-02-28T00:00:00Z
---

## Current Focus

RESOLVED — Root cause was Milvus instability. Permanently fixed by migrating to Qdrant.

## Symptoms

expected: AI-curated related items should display — topics on topic pages, events on event pages
actual: The block is missing entirely from Topic and Event pages
errors: TypeError on vectorSearch filters type mismatch after Qdrant migration
reproduction: Visit any Topic or Event page — the related content block is absent
started: Broke after recent code changes in the last few days/sessions

## Eliminated

## Evidence

- timestamp: 2026-02-27T00:01:00Z
  checked: Block config (block.block.social_ai_related_topics.yml, block.block.social_ai_related_events.yml)
  found: Both blocks enabled, correct region (complementary_bottom), correct visibility (entity_bundle:node for topic/event)
  implication: Block configuration is correct, issue is not config-related

- timestamp: 2026-02-27T00:02:00Z
  checked: Active Drupal block entity via drush
  found: Block loaded, plugin=social_ai_related_content, region=complementary_bottom, theme=socialblue, status=enabled
  implication: Blocks are correctly deployed in Drupal

- timestamp: 2026-02-27T00:03:00Z
  checked: RelatedContentService::findRelated() directly via drush eval
  found: "cURL error 6: Could not resolve host: milvus" - service returns 0 results
  implication: Milvus VDB is unreachable, causing search to fail silently

- timestamp: 2026-02-27T00:04:00Z
  checked: DDEV container status (ddev describe)
  found: Milvus container status is "exited" while etcd, minio, attu are OK
  implication: Milvus container crashed/exited

- timestamp: 2026-02-27T00:05:00Z
  checked: Milvus container logs
  found: "Proxy disconnected from etcd, process will exit" - etcd connection timeout caused Milvus to shut down
  implication: Infrastructure issue - Milvus needs restart

- timestamp: 2026-02-28T00:01:00Z
  checked: Post-migration Qdrant vectorSearch
  found: TypeError — QdrantProvider::vectorSearch() $filters param typed as string, but prepareFilters() returns array
  implication: Type mismatch in Qdrant provider needed patching

## Resolution

root_cause: Two issues. (1) Original: Milvus container instability — etcd connection timeouts caused Milvus to exit, making RelatedContentService return empty results and hiding the block. (2) After migration to Qdrant: QdrantProvider::vectorSearch() and querySearch() had `string $filters` type hints but prepareFilters() returns an array (Qdrant's native filter format).
fix: |
  1. Migrated vector database from Milvus (4 containers) to Qdrant (1 container) — eliminates Milvus instability permanently.
  2. Patched QdrantProvider to accept `string|array` filters in vectorSearch/querySearch (patches/qdrant-provider-fix-filters-type-hint.patch).
  Committed as eb800b0.
verification: RelatedContentService::findRelated() returns results for both topics (4 items) and events (5 items) via drush eval. Qdrant collection has 31 points, status green.
files_changed:
  - .ddev/.env (COMPOSE_PROFILES=qdrant)
  - .ddev/docker-compose.ai-overrides.yaml (deleted)
  - composer.json (new patch registered)
  - config/sync/core.extension.yml (milvus → qdrant module)
  - config/sync/search_api.server.ai_knowledge_garden.yml (database: qdrant)
  - config/sync/ai_vdb_provider_qdrant.settings.yml (new)
  - config/sync/ai_vdb_provider_milvus.settings.yml (deleted)
  - patches/qdrant-provider-fix-filters-type-hint.patch (new)
