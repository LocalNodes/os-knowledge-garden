# Phase 4: Q&A & Search - Research

**Researched:** 2026-02-24
**Domain:** RAG Pipeline, Semantic/Hybrid Search, Citation Linking
**Confidence:** HIGH

## Summary

Phase 4 builds on the existing ai_search + Milvus infrastructure to enable natural language Q&A and semantic search. The Drupal AI ecosystem provides mature modules (ai_assistant_api, ai_chatbot) that handle RAG orchestration out of the box. The key implementation challenge is hybrid search—combining Milvus vector similarity with existing Solr keyword matching—and ensuring citations trace back to source content.

**Primary recommendation:** Enable ai_assistant_api + ai_chatbot modules for RAG Q&A. Implement hybrid search using Reciprocal Rank Fusion (RRF) to combine Milvus semantic results with Solr keyword results. Store entity metadata in vector indexes for citation generation.

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| QA-01 | Users can ask questions in natural language about Group content | ai_assistant_api + ai_chatbot modules provide RAG chatbot |
| QA-02 | AI assistant returns coherent, contextual answers from indexed content | Deepseek LLM configured; RAG pipeline retrieves from Milvus |
| QA-03 | Every answer includes citation links back to source content | Store entity_type, entity_id, url in indexed metadata; prompt engineering |
| QA-04 | AI gracefully responds when no relevant information exists | Configure RAG threshold; prompt instructions for "I couldn't find" |
| QA-05 | Response latency acceptable for demo (<10 seconds) | Enable streaming; optimize retrieval top_k |
| SRCH-01 | Community-wide search across all public Group content | Permission filters already implemented; apply to search queries |
| SRCH-02 | Semantic search returns results based on meaning | ai_search module with Milvus already configured |
| SRCH-03 | Hybrid search combines vector similarity with existing Solr keyword matching | Implement RRF algorithm to merge Milvus + Solr results |
| SRCH-04 | Related content suggestions appear alongside Q&A results | Vector similarity "more like this" using same embeddings |
</phase_requirements>

## Standard Stack

### Core (Already Installed)
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| ai | 1.2.x | Core AI framework | Drupal ecosystem standard |
| ai_search | bundled | Vector search backend | Integrates Milvus with Search API |
| ai_assistant_api | bundled | RAG assistant configuration | Decoupled assistant framework |
| ai_chatbot | bundled | Chatbot UI | Reference frontend for assistants |
| ai_vdb_provider_milvus | bundled | Milvus connector | Already configured |
| ai_provider_deepseek | bundled | LLM provider | Already configured |
| ai_provider_ollama | bundled | Embedding provider | nomic-embed-text, 768 dimensions |

### To Enable
| Module | Purpose | When to Use |
|--------|---------|-------------|
| ai_assistant_api | Configure RAG assistants with thresholds, prompts | Phase 4, Plan 04-01 |
| ai_chatbot | Place chatbot blocks on pages | Phase 4, Plan 04-01 |

### Supporting (Custom)
| Module | Purpose | When to Use |
|--------|---------|-------------|
| social_ai_indexing | Permission filtering, metadata injection | Already active; extend for citations |

**Installation:**
```bash
# Already have ai module; enable submodules
drush en ai_assistant_api ai_chatbot
```

## Architecture Patterns

### Recommended Project Structure
```
html/modules/custom/social_ai_indexing/
├── src/
│   ├── Plugin/
│   │   └── search_api/
│   │       └── processor/
│   │           ├── ContentVisibility.php     # Done
│   │           ├── GroupMetadata.php         # Done
│   │           ├── CommentParentContext.php  # Done
│   │           ├── FileContentExtractor.php  # Done
│   │           └── CitationMetadata.php      # NEW - Add URL, entity info
│   ├── Service/
│   │   ├── PermissionFilterService.php       # Done
│   │   └── HybridSearchService.php           # NEW - RRF merge
│   └── EventSubscriber/
│       └── SearchQuerySubscriber.php         # Done - extend for hybrid
└── modules/
    └── social_ai_chatbot/                    # NEW - Custom chatbot block
        ├── src/
        │   └── Plugin/Block/
        │       └── GroupChatbotBlock.php     # Group-scoped chatbot
        └── social_ai_chatbot.info.yml
```

### Pattern 1: RAG Pipeline with ai_assistant_api

**What:** AI Assistant entity configures RAG behavior—retrieval database, thresholds, system prompts.

**When to use:** Natural language Q&A over indexed content.

**Configuration:**
```yaml
# AI Assistant configuration
id: group_assistant
label: 'Group Knowledge Assistant'
system_prompt: |
  You are a helpful assistant that answers questions about Group content.
  Use ONLY the provided context to answer questions.
  If the context doesn't contain relevant information, say "I couldn't find information about that in the Group content."
  Always cite your sources using [Source: Title](url) format.
rag_enabled: true
rag_database: social_posts  # Search API index
rag_threshold: 0.7
rag_max_results: 5
chat_model: deepseek__deepseek-chat
```

**Code flow:**
```php
// Source: Drupal AI module pattern
$assistant = AiAssistant::load('group_assistant');
$chain = $this->assistantBuilder->createChain($assistant);

// RAG retrieval happens automatically
$response = $chain->invoke($user_question);

// Response includes sources
$sources = $response->getMetadata('sources');
```

### Pattern 2: Hybrid Search with Reciprocal Rank Fusion (RRF)

**What:** Combine semantic (Milvus) and keyword (Solr) search results using rank-based scoring.

**When to use:** When users expect both semantic understanding AND exact keyword matches.

**Algorithm:**
```php
// RRF formula: score = sum(1 / (rank + k)) for each retriever
// k = 60 (recommended smoothing constant)

function reciprocalRankFusion(array $vectorResults, array $keywordResults, int $k = 60): array {
  $scores = [];
  
  // Score vector results
  foreach ($vectorResults as $rank => $result) {
    $id = $result['id'];
    $scores[$id] = ($scores[$id] ?? 0) + (1 / ($rank + 1 + $k));
    $merged[$id] = $result;
  }
  
  // Score keyword results
  foreach ($keywordResults as $rank => $result) {
    $id = $result['id'];
    $scores[$id] = ($scores[$id] ?? 0) + (1 / ($rank + 1 + $k));
    $merged[$id] = $result; // Overwrites if duplicate, same entity
  }
  
  // Sort by fused score
  arsort($scores);
  return array_map(fn($id) => $merged[$id], array_keys($scores));
}
```

**Service implementation:**
```php
// src/Service/HybridSearchService.php
class HybridSearchService {
  
  public function search(string $query, AccountInterface $account, int $limit = 10): array {
    // 1. Semantic search via Milvus (ai_search)
    $vectorQuery = $this->index->query($query);
    $this->permissionFilter->applyPermissionFilters($vectorQuery, $account);
    $vectorResults = $vectorQuery->range(0, $limit * 2)->execute();
    
    // 2. Keyword search via Solr
    $keywordQuery = $this->solrIndex->query($query);
    $keywordResults = $keywordQuery->range(0, $limit * 2)->execute();
    
    // 3. Merge with RRF
    $merged = $this->reciprocalRankFusion(
      $vectorResults->getResultItems(),
      $keywordResults->getResultItems()
    );
    
    // 4. Post-retrieval access check (defense-in-depth)
    return $this->permissionFilter->filterResultsByAccess(
      array_slice($merged, 0, $limit),
      $account
    );
  }
}
```

### Pattern 3: Citation Metadata in Embeddings

**What:** Store entity metadata alongside embeddings for citation generation.

**When to use:** RAG responses need clickable links to source content.

**Metadata fields to index:**
```yaml
# In search_api.index.social_posts.yml
field_settings:
  citation_url:
    label: 'Citation URL'
    property_path: citation_url
    type: string
  citation_title:
    label: 'Citation Title'
    datasource_id: 'entity:node'
    property_path: title
    type: string
  citation_type:
    label: 'Content Type'
    datasource_id: 'entity:node'
    property_path: type
    type: string
```

**Processor to add URL:**
```php
// src/Plugin/search_api/processor/CitationMetadata.php
class CitationMetadata extends ProcessorPluginBase {
  
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
    $properties['citation_url'] = new DataDefinition([
      'label' => 'Citation URL',
      'type' => 'string',
    ]);
    return $properties;
  }
  
  public function addFieldValues(ItemInterface $item) {
    $entity = $item->getOriginalObject()->getValue();
    $url = $entity->toUrl('canonical', ['absolute' => TRUE])->toString();
    
    $item->getField('citation_url')->addValue($url);
    $item->getField('citation_title')->addValue($entity->label());
    $item->getField('citation_type')->addValue($entity->bundle());
  }
}
```

**Prompt template for citations:**
```
Context from Group content:
[1] "{title}" ({type}) - {url}
{content_chunk}

[2] "{title}" ({type}) - {url}
{content_chunk}

Instructions: Answer the question using ONLY the context above. 
Cite sources using [1], [2] notation. Include clickable links.
```

### Pattern 4: Related Content via Vector Similarity

**What:** Use existing embeddings to find "more like this" content.

**When to use:** Suggesting related posts alongside Q&A results.

```php
public function findRelated(Node $node, int $limit = 5): array {
  // Get the embedding for this node (already stored in Milvus)
  $itemId = 'entity:node/' . $node->id() . ':' . $node->language()->getId();
  $embedding = $this->getEmbeddingForItem($itemId);
  
  // Search for similar vectors, excluding the source
  $query = $this->index->getQuery();
  $query->addCondition('search_api_id', $itemId, '<>');
  $query->addCondition('search_api_relevance', 0.7, '>=');
  $query->setFulltextFields(['rendered_item']);
  $query->range(0, $limit);
  
  // Use vector similarity search
  return $query->execute()->getResultItems();
}
```

### Anti-Patterns to Avoid

- **Don't call LLM without retrieval:** Always ground responses in retrieved context to prevent hallucinations
- **Don't skip permission filtering:** Even in RAG, apply pre/post retrieval access checks
- **Don't normalize scores across systems:** Vector similarity scores (0-1) and Solr scores are incomparable—use RRF instead
- **Don't embed entire documents:** Use chunking (already configured: 384 tokens, 50 overlap)
- **Don't hardcode citation format:** Make citation format configurable per assistant

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| RAG orchestration | Custom retrieval + prompt assembly | ai_assistant_api | Handles retrieval, prompt templates, streaming |
| Chatbot UI | Custom AJAX chat interface | ai_chatbot block | Reference implementation, streaming support |
| Vector similarity search | Custom Milvus queries | ai_search Search API backend | Already configured, tested |
| Score normalization | Weighted average of vector + keyword scores | Reciprocal Rank Fusion | Rank-based, no score normalization needed |
| Embedding generation | Custom embedding API calls | ai_provider_ollama | Already configured with nomic-embed-text |

**Key insight:** The Drupal AI ecosystem provides the RAG infrastructure. Focus effort on hybrid search (RRF), permission integration, and citation formatting.

## Common Pitfalls

### Pitfall 1: Permission Leakage in RAG
**What goes wrong:** AI assistant returns content the user cannot access because RAG bypasses Drupal entity access.
**Why it happens:** ai_assistant_api doesn't automatically apply permission filters.
**How to avoid:** Apply PermissionFilterService to RAG queries before retrieval; add post-retrieval entity access check.
**Warning signs:** User sees content from Groups they're not members of.

### Pitfall 2: Citation Links Don't Resolve
**What goes wrong:** Citations show URLs that 404 or link to wrong content.
**Why it happens:** URLs not stored in vector metadata; LLM hallucinates URLs.
**How to avoid:** Store canonical entity URLs in index metadata; inject into context; never let LLM generate URLs.
**Warning signs:** Citations have format like `/node/123` instead of human URLs.

### Pitfall 3: Hybrid Search Returns Duplicates
**What goes wrong:** Same content appears multiple times in merged results.
**Why it happens:** Vector and keyword results have different IDs or don't dedupe.
**How to avoid:** Use entity ID (not Search API item ID) as RRF key; dedupe before ranking.
**Warning signs:** Result list has same post at positions 2 and 5.

### Pitfall 4: Slow RAG Responses
**What goes wrong:** Q&A takes 15-30 seconds, users abandon queries.
**Why it happens:** Too many retrieval results, large context windows, no streaming.
**How to avoid:** Limit RAG max_results to 5; enable streaming responses; cache frequent queries.
**Warning signs:** Response time > 10 seconds; timeouts.

### Pitfall 5: "I Don't Know" When Content Exists
**What goes wrong:** AI says it can't find information when relevant content exists.
**Why it happens:** RAG threshold too high; chunking loses context; query embedding mismatch.
**How to avoid:** Set threshold to 0.6-0.7; include parent context for comments; test with real queries.
**Warning signs:** 404-style responses for questions about indexed content.

## Code Examples

### Enable AI Assistant with RAG
```php
// Create AI Assistant programmatically
$assistant = AiAssistant::create([
  'id' => 'group_assistant',
  'label' => 'Group Knowledge Assistant',
  'description' => 'Answers questions about Group content',
  'system_prompt' => 'You are a helpful assistant...',
  'chat_model' => 'deepseek__deepseek-chat',
  'rag_enabled' => TRUE,
  'rag_database' => 'social_posts',
  'rag_threshold' => 0.7,
  'rag_max_results' => 5,
]);
$assistant->save();
```

### Hybrid Search Controller
```php
// src/Controller/HybridSearchController.php
class HybridSearchController extends ControllerBase {
  
  public function search(Request $request) {
    $query = $request->query->get('q');
    $account = $this->currentUser();
    
    $results = $this->hybridSearchService->search($query, $account, 10);
    
    return new JsonResponse([
      'results' => array_map(function($item) {
        return [
          'id' => $item->getId(),
          'title' => $item->getField('title')->getValues()[0],
          'url' => $item->getField('citation_url')->getValues()[0],
          'snippet' => substr($item->getField('rendered_item')->getValues()[0], 0, 200),
          'score' => $item->getScore(),
        ];
      }, $results),
    ]);
  }
}
```

### Related Content Block
```php
// src/Plugin/Block/RelatedContentBlock.php
class RelatedContentBlock extends BlockBase {
  
  public function build() {
    $node = $this->getContextValue('node');
    if (!$node) return [];
    
    $related = $this->relatedContentService->findRelated($node, 5);
    
    return [
      '#theme' => 'item_list',
      '#items' => array_map(function($item) {
        return [
          '#type' => 'link',
          '#title' => $item->getField('title')->getValues()[0],
          '#url' => Url::fromUri($item->getField('citation_url')->getValues()[0]),
        ];
      }, $related),
    ];
  }
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Separate vector + keyword search systems | Unified hybrid search with RRF | 2023-2024 | Better relevance, simpler infrastructure |
| LLM generates URLs | URLs stored in vector metadata | 2024 | Eliminates hallucinated links |
| Post-hoc citation matching | Citation-aware chunking with metadata | 2024 | Accurate source attribution |
| Fixed RAG prompts | Configurable assistant system prompts | Drupal AI 1.2 | Per-group customization |
| Batch LLM responses | Streaming responses | 2024 | Better UX, lower perceived latency |

**Deprecated/outdated:**
- **BM25-only search:** Insufficient for semantic queries; use hybrid
- **Score normalization:** Comparing vector similarity to Solr scores directly; use RRF
- **Global assistants:** Single assistant for all groups; use per-group configuration (v2)

## Open Questions

1. **Per-Group Assistant Customization**
   - What we know: ai_assistant_api supports configurable system prompts
   - What's unclear: Whether to create one assistant per group or parameterize a single assistant
   - Recommendation: Start with single assistant; add group-specific prompts in v2

2. **Citation Format Preference**
   - What we know: Inline citations [1] work well; footnotes also viable
   - What's unclear: User preference for citation style
   - Recommendation: Use inline citations with clickable links; make format configurable

3. **Streaming vs Batch Responses**
   - What we know: ai_chatbot supports streaming; improves perceived latency
   - What's unclear: Whether streaming complicates citation extraction
   - Recommendation: Enable streaming; extract citations from final response

## Sources

### Primary (HIGH confidence)
- drupal.org/project/ai - AI module documentation and submodules
- milvus.io/docs - Milvus hybrid search and BM25 support
- config/sync/search_api.server.ai_knowledge_garden.yml - Current Milvus configuration
- config/sync/search_api.index.social_posts.yml - Current index structure
- html/modules/custom/social_ai_indexing/src/Service/PermissionFilterService.php - Existing permission filtering

### Secondary (MEDIUM confidence)
- WebSearch verified: Drupal AI Assistant API + Chatbot module structure
- WebSearch verified: Reciprocal Rank Fusion algorithm (k=60 standard)
- WebSearch verified: RAG citation best practices 2024

### Tertiary (LOW confidence)
- None - all critical claims verified with multiple sources

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - ai_assistant_api, ai_chatbot already in codebase; ai_search already configured
- Architecture: HIGH - RRF is well-documented; patterns follow Drupal AI conventions
- Pitfalls: HIGH - Permission filtering already implemented; citation metadata is straightforward extension

**Research date:** 2026-02-24
**Valid until:** 2026-03-24 (Drupal AI ecosystem stable; Milvus patterns well-established)
