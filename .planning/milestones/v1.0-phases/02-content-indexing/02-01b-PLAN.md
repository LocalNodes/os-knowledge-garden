---
phase: 02-content-indexing
plan: 01b
type: execute
wave: 2
depends_on: [02-01a]
files_modified: []
autonomous: true
requirements: [IDX-01, IDX-04, IDX-05]
user_setup: []

must_haves:
  truths:
    - "New posts are automatically indexed with embeddings upon creation"
    - "Post content is chunked into 256-512 token segments with overlap"
    - "Each indexed post has Group ID metadata attached for filtering"
    - "Search API tracker monitors posts for changes"
  artifacts:
    - path: "search_api.index.social_posts"
      provides: "Post index configuration"
      contains: "social_posts"
  key_links:
    - from: "Search API index"
      to: "Ollama embeddings"
      via: "ai_search backend"
      pattern: "nomic-embed-text"
    - from: "Search API index"
      to: "Milvus"
      via: "ai_vdb_provider_milvus"
      pattern: "milvus:19530"
---

<objective>
Create the Search API index for posts with proper chunking configuration and verify Group metadata extraction.

Purpose: Enable automatic embedding generation for posts with Group ID metadata attached for future permission filtering.
Output: Working post index with Group metadata processor, chunking configured, immediate indexing enabled.

**Dependency on 02-01a:** Requires the GroupMetadata processor from Plan 02-01a.
</objective>

<execution_context>
@/Users/proofoftom/.config/opencode/get-shit-done/workflows/execute-plan.md
@/Users/proofoftom/.config/opencode/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/REQUIREMENTS.md
@.planning/phases/02-content-indexing/02-RESEARCH.md
@.planning/phases/02-content-indexing/02-01a-SUMMARY.md
</context>

<tasks>

<task type="auto">
  <name>Task 1: Create Search API index for posts</name>
  <files>N/A (database configuration)</files>
  <action>
Create the Search API index for Open Social posts via Drush:

```bash
cd html

drush eval "
\$index = \Drupal::entityTypeManager()->getStorage('search_api_index')->create([
  'id' => 'social_posts',
  'name' => 'Social Posts',
  'description' => 'Vector index for Open Social posts and topics',
  'read_only' => FALSE,
  'field_settings' => [
    'rendered_item' => [
      'label' => 'Rendered content',
      'property_path' => 'rendered_item',
      'type' => 'text',
      'configuration' => [
        'view_mode' => [
          'entity:node' => [
            'post' => 'search_index',
            'topic' => 'search_index',
          ],
        ],
      ],
    ],
    'title' => [
      'label' => 'Title',
      'datasource_id' => 'entity:node',
      'property_path' => 'title',
      'type' => 'string',
    ],
    'group_id' => [
      'label' => 'Group ID',
      'property_path' => 'group_id',
      'type' => 'integer',
    ],
  ],
  'datasource_settings' => [
    'entity:node' => [
      'bundles' => [
        'default' => FALSE,
        'selected' => ['post', 'topic'],
      ],
      'languages' => [
        'default' => TRUE,
        'selected' => [],
      ],
    ],
  ],
  'processor_settings' => [
    'add_url' => [],
    'rendered_item' => [],
    'group_metadata' => [
      'weights' => ['add_properties' => 0],
    ],
  ],
  'tracker_settings' => [
    'default' => ['indexing_order' => 'fifo'],
  ],
  'options' => [
    'index_directly' => TRUE,
    'cron_limit' => 50,
  ],
  'server' => 'ai_knowledge_garden',
  'status' => TRUE,
]);
\$index->save();
echo 'Created social_posts index';
"
```

Verify the index was created:
```bash
drush search-api:list
```
  </action>
  <verify>
    <automated>cd html && drush eval "print_r(array_keys(\Drupal::entityTypeManager()->getStorage('search_api_index')->loadMultiple()));" | grep social_posts</automated>
    <manual>social_posts index should exist</manual>
    <sampling_rate>run after this task commits, before next task begins</sampling_rate>
  </verify>
  <done>social_posts Search API index created with post and topic content types</done>
</task>

<task type="auto">
  <name>Task 2: Verify chunking configuration</name>
  <files>N/A (runtime verification)</files>
  <action>
Verify the chunking settings on the AI Search backend:

```bash
cd html

# Check the AI Knowledge Garden server chunking config
drush eval "
\$server = \Drupal::entityTypeManager()->getStorage('search_api_server')->load('ai_knowledge_garden');
if (\$server) {
  \$config = \$server->getBackendConfig();
  echo 'Chunk size: ' . (\$config['chunk_size'] ?? 'not set') . PHP_EOL;
  echo 'Chunk overlap: ' . (\$config['chunk_overlap'] ?? 'not set') . PHP_EOL;
} else {
  echo 'Server not found';
}
"
```

If chunking needs adjustment, update the server configuration:
```bash
# Target: 256-512 tokens with 10-20% overlap
# Using 384 tokens (middle of range) with 50 token overlap
drush config:set search_api.server.ai_knowledge_garden backend_config.chunk_size 384 -y
drush config:set search_api.server.ai_knowledge_garden backend_config.chunk_overlap 50 -y
```
  </action>
  <verify>
    <automated>cd html && drush config:get search_api.server.ai_knowledge_garden backend_config | grep -E "chunk_size|chunk_overlap"</automated>
    <manual>Chunk size should be ~384, overlap should be ~50</manual>
    <sampling_rate>run after this task commits, before next task begins</sampling_rate>
  </verify>
  <done>Chunking configured: ~384 tokens with 50 token overlap (within 256-512 range)</done>
</task>

<task type="auto">
  <name>Task 3: Test post indexing with Group metadata</name>
  <files>N/A (runtime verification)</files>
  <action>
Test that posts are being indexed with Group metadata:

```bash
cd html

# Check if there are any posts to index
drush eval "
\$node_count = \Drupal::entityTypeManager()->getStorage('node')
  ->getQuery()
  ->condition('type', ['post', 'topic'], 'IN')
  ->accessCheck(FALSE)
  ->count()
  ->execute();
echo 'Posts/topics found: ' . \$node_count . PHP_EOL;

\$group_count = \Drupal::entityTypeManager()->getStorage('group')
  ->getQuery()
  ->accessCheck(FALSE)
  ->count()
  ->execute();
echo 'Groups found: ' . \$group_count . PHP_EOL;

\$gc_count = \Drupal::entityTypeManager()->getStorage('group_content')
  ->getQuery()
  ->accessCheck(FALSE)
  ->count()
  ->execute();
echo 'Group content relations found: ' . \$gc_count . PHP_EOL;
"
```

If content exists, verify indexing:
```bash
# Check index status
drush search-api:status social_posts

# If items need indexing, run a batch
drush search-api:index social_posts
```
  </action>
  <verify>
    <automated>cd html && drush search-api:status social_posts 2>&1 | head -20</automated>
    <manual>Index status should show indexed items or "all items indexed"</manual>
    <sampling_rate>run after this task commits, before next task begins</sampling_rate>
  </verify>
  <done>Post index operational, items being indexed with Group metadata</done>
</task>

<task type="auto">
  <name>Task 4: Verify Group metadata in indexed items</name>
  <files>N/A (runtime verification)</files>
  <action>
Verify that Group ID is being added to indexed items:

```bash
cd html

# Check that the processor is extracting Group IDs correctly
drush eval "
\$processor = \Drupal::getContainer()
  ->get('plugin.manager.search_api.processor')
  ->createInstance('group_metadata');

if (\$processor) {
  echo 'Group Metadata processor loaded successfully' . PHP_EOL;
  
  // Test with a sample node if available
  \$nodes = \Drupal::entityTypeManager()->getStorage('node')
    ->getQuery()
    ->condition('type', ['post', 'topic'], 'IN')
    ->accessCheck(FALSE)
    ->range(0, 1)
    ->execute();
  
  if (!empty(\$nodes)) {
    \$node = \Drupal\node\Entity\Node::load(reset(\$nodes));
    echo 'Testing with node ' . \$node->id() . ' (' . \$node->bundle() . ')' . PHP_EOL;
    
    \$reflection = new \ReflectionClass(\$processor);
    \$method = \$reflection->getMethod('getGroupIdsForEntity');
    \$method->setAccessible(TRUE);
    \$group_ids = \$method->invoke(\$processor, \$node->getEntityTypeId(), (int) \$node->id());
    
    echo 'Group IDs: ' . (empty(\$group_ids) ? 'none' : implode(', ', \$group_ids)) . PHP_EOL;
  } else {
    echo 'No posts/topics found for testing' . PHP_EOL;
  }
} else {
  echo 'Processor not found';
}
"
```
  </action>
  <verify>
    <automated>cd html && drush eval "echo \Drupal::getContainer()->get('plugin.manager.search_api.processor')->hasDefinition('group_metadata') ? 'PASS: Processor registered' : 'FAIL';"</automated>
    <manual>Processor should be registered and extract Group IDs from nodes</manual>
    <sampling_rate>run after this task commits, before next task begins</sampling_rate>
  </verify>
  <done>Group metadata processor verified: extracts Group IDs from group_content relationships</done>
</task>

</tasks>

<verification>
## Phase 2 Plan 01b Verification

1. **Search API Index:**
   - [ ] social_posts index exists
   - [ ] Index configured for post and topic bundles
   - [ ] Group ID field present in index

2. **Chunking Configuration:**
   - [ ] Chunk size ~384 tokens
   - [ ] Chunk overlap ~50 tokens

3. **Indexing Status:**
   - [ ] Items being tracked
   - [ ] `index_directly` enabled
</verification>

<success_criteria>
1. New posts are automatically indexed with embeddings upon creation (index_directly enabled)
2. Content is chunked appropriately (384 tokens with 50 overlap, within 256-512 range)
3. Each indexed post has Group ID metadata attached for permission filtering
4. Search API tracker monitors posts for changes (default tracker enabled)
</success_criteria>

<output>
After completion, create `.planning/phases/02-content-indexing/02-01b-SUMMARY.md` with:
- Index configuration
- Chunking settings
- Test results for Group ID extraction
- Any issues encountered
</output>
