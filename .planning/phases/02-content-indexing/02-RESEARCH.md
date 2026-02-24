# Phase 2: Content Indexing - Research

**Researched:** 2026-02-24
**Domain:** Drupal AI content indexing, vector embeddings, RAG
**Confidence:** HIGH

## Summary

Drupal's AI ecosystem provides mature modules for content indexing with embeddings. The `ai_search` module (formerly a submodule of AI Core, now standalone) provides Search API integration with vector databases including Milvus. Content indexing follows the established pattern of Search API trackers with optional immediate indexing or cron-based batch processing.

**Key finding:** Do NOT build custom indexing infrastructure. The ai_search module handles chunking, embedding generation, vector storage, and invalidation through Search API's proven tracker system. Open Social uses the Group module (not Organic Groups), and content-to-group relationships are managed via "group content" entities that link nodes to groups.

**Primary recommendation:** Use ai_search with Search API for all content indexing. Configure "Contextual Chunks" embedding strategy with 256-512 token chunks and 10-20% overlap. Store Group ID as metadata in Milvus for permission filtering.

<phase_requirements>

## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| IDX-01 | Open Social posts are automatically indexed with embeddings on create/update | ai_search + Search API "Index items immediately" setting; Open Social post content type |
| IDX-02 | Comments are indexed with parent post context for retrieval | Parent Document Retrieval pattern; include parent context in comment embeddings |
| IDX-03 | File uploads (PDFs, Office docs) are parsed and indexed | Unstructured module or AI File to Text module for parsing |
| IDX-04 | Content is chunked appropriately (256-512 tokens with overlap) | ai_search "Contextual Chunks" strategy; 10-20% overlap recommended |
| IDX-05 | Group ID metadata is attached to all indexed content | Milvus metadata filtering; Group module group_content entities |
| IDX-06 | Stale embeddings are invalidated and regenerated on content updates/deletes | Search API tracker + ai_search handle this automatically |

</phase_requirements>

## Standard Stack

### Core

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| ai | 1.2.x | Core AI abstraction layer | Official Drupal AI module; provider-agnostic |
| ai_search | 2.0.x | Vector search for Search API | Official semantic search implementation |
| ai_vdb_provider_milvus | 1.x | Milvus integration | Official Milvus VDB provider |
| search_api | 1.x | Indexing framework | Drupal standard for content indexing |

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| ai_provider_deepseek | custom | DeepSeek API provider | DeepSeek uses OpenAI-compatible API format |
| unstructured | 2.x | File parsing (PDF, Office) | IDX-03 file parsing |
| ai_file_to_text | 1.x | PHP-native file extraction | Simpler alternative to Unstructured |
| group | 1.x | Group relationship management | Required for Open Social Group integration |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Milvus | Postgres (pgvector) | Simpler ops but lower scale performance |
| ai_search (Search API) | Custom Queue + direct Milvus | Reinventing Search API's proven tracker |
| Unstructured | AI Simple PDF To Text | Unstructured handles more formats |

**Installation:**
```bash
composer require drupal/ai:^1.2 drupal/ai_search:^2.0@alpha drupal/ai_vdb_provider_milvus
composer require drupal/search_api drupal/group
# For file parsing:
composer require drupal/unstructured
```

## Architecture Patterns

### Recommended Project Structure

```
modules/custom/
├── social_ai_indexing/           # Main indexing configuration module
│   ├── src/
│   │   ├── Plugin/
│   │   │   └── SearchApi/
│   │   │       └── Processor/    # Custom processors for Group metadata
│   │   └── EventSubscriber/      # Entity hooks for immediate indexing
│   └── social_ai_indexing.info.yml
└── social_ai_provider_deepseek/  # DeepSeek provider (if not exists)
```

### Pattern 1: Search API + ai_search Configuration

**What:** Use Search API with ai_search backend for all content indexing
**When to use:** Always - this is the standard approach

**Configuration steps:**
1. Create Search API server with "Drupal AI Search" backend
2. Configure Milvus connection via ai_vdb_provider_milvus
3. Create Search API index for each content type (posts, comments, files)
4. Set embedding engine to DeepSeek (via custom or OpenAI-compatible provider)
5. Configure "Contextual Chunks" embedding strategy

```yaml
# Search API server configuration
id: social_content_vector
name: 'Social Content Vector Index'
backend: ai_search
backend_config:
  embedding_engine: deepseek
  vdb_provider: milvus
  embedding_strategy: contextual_chunks
  chunk_size: 384
  chunk_overlap: 50
```

### Pattern 2: Group Metadata Attachment

**What:** Attach Group ID to all indexed content for permission filtering
**When to use:** All content that belongs to a Group

```php
// Source: Custom Search API processor
// src/Plugin/SearchApi/Processor/GroupMetadata.php
namespace Drupal\social_ai_indexing\Plugin\SearchApi\Processor;

use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Item\ItemInterface;

/**
 * Adds Group ID metadata to indexed items.
 *
 * @SearchApiProcessor(
 *   id = "group_metadata",
 *   label = @Translation("Group Metadata"),
 *   description = @Translation("Adds Group ID for permission filtering."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 * )
 */
class GroupMetadata extends ProcessorPluginBase {
  
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
    $properties = [];
    if ($datasource && $datasource->getEntityTypeId() === 'node') {
      $properties['group_id'] = new ItemFieldDefinition([
        'label' => $this->t('Group ID'),
        'type' => 'integer',
      ]);
    }
    return $properties;
  }

  public function addFieldValues(ItemInterface $item) {
    $node = $item->getOriginalObject()->getValue();
    
    // Get Group IDs via group_content entities.
    $group_ids = \Drupal::entityTypeManager()
      ->getStorage('group_content')
      ->getQuery()
      ->condition('entity_id', $node->id())
      ->condition('type', 'group_content_type_for_node', 'CONTAINS')
      ->accessCheck(FALSE)
      ->execute();
    
    $fields = $item->getFields();
    foreach ($fields as $field) {
      if ($field->getPropertyPath() === 'group_id') {
        foreach ($group_ids as $gc_id) {
          $gc = GroupContent::load($gc_id);
          $field->addValue($gc->gid->target_id);
        }
      }
    }
  }
}
```

### Pattern 3: Comment with Parent Context

**What:** Index comments with parent post context for retrieval
**When to use:** IDX-02 comment indexing

```php
// In Search API index configuration for comments:
// Set parent post fields as "Contextual content"
// This enriches comment chunks with parent context

// Comment index field configuration:
// - field_comment_body: Main content (for embedding)
// - parent_post_title: Contextual content
// - parent_post_summary: Contextual content
// - group_id: Metadata (for filtering)
```

### Anti-Patterns to Avoid

- **Custom embedding generation:** Don't call embedding APIs directly; use ai_search
- **Direct Milvus writes:** Don't bypass Search API's tracker system
- **Synchronous embedding on save:** Don't block page saves; use Queue or "Index immediately" setting
- **Hand-rolled chunking:** ai_search's Contextual Chunks strategy handles this

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Embedding generation | Custom API calls | ai_search + AI provider | Rate limiting, error handling, retries built-in |
| Content chunking | Token-based splitter | ai_search Contextual Chunks | Handles overlap, context enrichment |
| Vector storage | Direct Milvus SDK | ai_vdb_provider_milvus | Schema management, connection pooling |
| Invalidation tracking | Custom hooks | Search API tracker | Proven, handles edge cases |
| File parsing | Custom PDF/Office code | Unstructured or AI File to Text | Format edge cases, encoding issues |
| Queue processing | Custom QueueWorker | Search API cron + "Index immediately" | Built-in batching, error recovery |

**Key insight:** The ai_search module was extracted from AI Core specifically because it's production-ready. Search API's tracker system has been battle-tested for 10+ years.

## Common Pitfalls

### Pitfall 1: Blocking Saves with Synchronous Embedding

**What goes wrong:** Generating embeddings during `hook_entity_presave()` causes page save timeouts, especially for long content
**Why it happens:** Embedding API calls take 1-5+ seconds; PHP timeout limits
**How to avoid:** Enable Search API "Index items immediately" (uses Queue internally) or rely on cron
**Warning signs:** Page save > 5 seconds, timeout errors, PHP memory exhaustion

### Pitfall 2: Missing Group ID in Vector Metadata

**What goes wrong:** Content indexed without Group ID; permission filtering fails
**Why it happens:** Group relationship not exposed as Search API field
**How to avoid:** Create custom Search API processor that extracts Group ID via group_content entities
**Warning signs:** Search returns content from wrong groups; permission errors in logs

### Pitfall 3: Stale Embeddings After Content Updates

**What goes wrong:** Embedding doesn't match current content; search returns irrelevant results
**Why it happens:** Tracker not configured, or content type not tracked
**How to avoid:** Verify Search API index is tracking the content type; enable "Index items immediately"
**Warning signs:** Search finds old content that was edited; deletes still return in search

### Pitfall 4: DeepSeek Embedding API Incompatibility

**What goes wrong:** ai_search configured for OpenAI but using DeepSeek
**Why it happens:** DeepSeek uses OpenAI-compatible format but requires different base URL
**How to avoid:** Configure custom provider with DeepSeek base URL (`https://api.deepseek.com/v1`)
**Warning signs:** 401 errors, "model not found" errors, wrong embedding dimensions

### Pitfall 5: Context Loss in Comment Chunks

**What goes wrong:** Comment chunks retrieved without parent context; LLM can't understand relevance
**Why it happens:** Comments indexed alone without parent post fields
**How to avoid:** Configure parent post fields as "Contextual content" in ai_search index
**Warning signs:** RAG responses lack context; "I don't understand" LLM responses

## Code Examples

### Search API Index Configuration for Posts

```yaml
# config/install/search_api.index.social_posts.yml
id: social_posts
name: 'Social Posts'
description: 'Vector index for Open Social posts'
read_only: false
field_settings:
  rendered_item:
    label: 'Rendered HTML'
    property_path: rendered_item
    type: text
    configuration:
      view_mode:
        entity:node:
          post: default
  title:
    label: Title
    datasource_id: 'entity:node'
    property_path: title
    type: string
  group_id:
    label: 'Group ID'
    property_path: group_id
    type: integer
datasource_settings:
  'entity:node':
    bundles:
      default: false
      selected:
        - post
        - topic
    languages:
      default: true
      selected: { }
processor_settings:
  add_url: { }
  rendered_item: { }
  group_metadata:
    weights:
      add_properties: 0
tracker_settings:
  default:
    indexing_order: fifo
options:
  index_directly: true  # "Index items immediately"
  cron_limit: 50
server: social_vector_server
```

### DeepSeek Provider Configuration

```php
// If ai_provider_deepseek doesn't exist, create minimal provider
// Using OpenAI-compatible endpoint format

// ai_provider_deepseek.services.yml
services:
  ai.provider.deepseek:
    class: Drupal\ai_provider_deepseek\Plugin\AiProvider\DeepSeekProvider
    arguments: ['@logger.factory', '@http_client']
    tags:
      - { name: ai_provider, id: deepseek }

// src/Plugin/AiProvider/DeepSeekProvider.php
namespace Drupal\ai_provider_deepseek\Plugin\AiProvider;

use Drupal\ai\AiProvider\PluginBase;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;

/**
 * DeepSeek provider using OpenAI-compatible API.
 *
 * @AiProvider(
 *   id = "deepseek",
 *   label = @Translation("DeepSeek"),
 * )
 */
class DeepSeekProvider extends PluginBase {
  
  protected string $baseUrl = 'https://api.deepseek.com/v1';
  
  public function getEmbedding(EmbeddingsInput $input): array {
    // DeepSeek uses OpenAI-compatible format
    // Model: deepseek-embedding-v2 or similar
    $response = $this->httpClient->post($this->baseUrl . '/embeddings', [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->getApiKey(),
        'Content-Type' => 'application/json',
      ],
      'json' => [
        'model' => 'deepseek-embedding-v2',
        'input' => $input->getText(),
      ],
    ]);
    
    $data = json_decode($response->getBody(), TRUE);
    return $data['data'][0]['embedding'];
  }
}
```

### Immediate Indexing Toggle

```php
// Ensure immediate indexing is enabled for critical content
// In hook_entity_insert() for posts/topics:

/**
 * Implements hook_entity_insert().
 */
function social_ai_indexing_entity_insert(EntityInterface $entity) {
  if (!in_array($entity->bundle(), ['post', 'topic', 'comment'])) {
    return;
  }
  
  // Search API's "index_directly" option handles this
  // But we can force immediate indexing if needed:
  if ($entity->getEntityTypeId() === 'node') {
    /** @var \Drupal\search_api\IndexInterface $index */
    $index = Index::load('social_posts');
    if ($index) {
      $index->indexSpecificItems([$entity->id()]);
    }
  }
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Custom embedding code | ai_search module | 2024-2025 | Standardized, maintained |
| Single chunk per entity | Multi-chunk with Contextual Chunks | 2024 | Better retrieval accuracy |
| Solr keyword search | Hybrid vector + keyword | 2024-2025 | Semantic + exact matching |
| Synchronous embedding | Queue-based async | 2024 | No timeout issues |

**Deprecated/outdated:**
- **Search API AI (deprecated):** Merged into ai_search
- **OpenAI Embeddings module:** Deprecated in favor of ai_search
- **Single chunk per entity:** Multi-chunk is now standard for long content

## Open Questions

1. **DeepSeek embedding model dimensions**
   - What we know: DeepSeek uses OpenAI-compatible API format
   - What's unclear: Exact embedding dimensions (OpenAI ada-002 is 1536)
   - Recommendation: Test during Phase 1 setup; ai_search should auto-detect

2. **Open Social comment entity type**
   - What we know: Open Social uses standard Drupal comment entity
   - What's unclear: Custom fields or bundles specific to Open Social
   - Recommendation: Inspect Open Social schema during implementation

3. **Group visibility field interaction**
   - What we know: Open Social has `field_group_allowed_visibility`
   - What's unclear: How visibility affects indexing (should secret content be indexed?)
   - Recommendation: Clarify with product requirements; may need to exclude secret groups

## Sources

### Primary (HIGH confidence)
- drupal.org/project/ai - AI module architecture and providers
- drupal.org/project/ai_search - Search API vector integration, chunking strategies
- drupal.org/project/ai_vdb_provider_milvus - Milvus integration specifics
- git.drupalcode.org/project/ai_search - README with installation/configuration

### Secondary (MEDIUM confidence)
- Multiple WebSearch results verified against official docs
- DrupalCon 2024-2025 session content on AI Search
- Open Social documentation on Group module usage

### Tertiary (LOW confidence)
- WebSearch-only findings on DeepSeek embedding specifics (verify during Phase 1)
- Open Social entity type specifics (verify during implementation)

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - Official Drupal modules, well-documented
- Architecture: HIGH - Based on official ai_search patterns
- Pitfalls: HIGH - Common issues documented in issue queues and community posts
- Open Social specifics: MEDIUM - Group module well-known, Open Social specifics need verification

**Research date:** 2026-02-24
**Valid until:** 2026-04-24 (Drupal AI ecosystem evolving rapidly)
