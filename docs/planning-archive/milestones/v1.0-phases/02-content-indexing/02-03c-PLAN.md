---
phase: 02-content-indexing
plan: 03c
type: execute
wave: 3
depends_on: [02-01b, 02-03b]
files_modified: []
autonomous: true
requirements: [IDX-03, IDX-06]
user_setup: []

must_haves:
  truths:
    - "PDF and Office document uploads are parsed and their text content is indexed"
    - "File content is chunked and embedded alongside post/comment content"
    - "Content updates trigger embedding regeneration automatically"
    - "Content deletes trigger embedding invalidation automatically"
    - "Search API tracker correctly handles update/delete events"
  artifacts:
    - path: "search_api.index.social_posts"
      provides: "Complete post index with file content"
      contains: "file_content field"
  key_links:
    - from: "Search API tracker"
      to: "entity hooks"
      via: "hook_entity_update/delete"
      pattern: "tracker.*update|tracker.*delete"
---

<objective>
Verify the complete indexing pipeline handles updates, deletes, and file content extraction correctly.

Purpose: Ensure the pipeline correctly handles content lifecycle (create, update, delete) and file parsing.
Output: Verified update/delete invalidation and file content extraction.

**Dependencies:**
- 02-01b: Requires social_posts index from Plan 02-01b
- 02-03b: Requires FileContentExtractor processor from Plan 02-03b
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
@.planning/phases/02-content-indexing/02-01b-SUMMARY.md
@.planning/phases/02-content-indexing/02-03b-SUMMARY.md
</context>

<tasks>

<task type="auto">
  <name>Task 1: Verify update/delete invalidation</name>
  <files>N/A (runtime verification)</files>
  <action>
Test that Search API correctly handles content updates and deletes:

```bash
cd html

# Check tracker configuration
drush eval "
\$index = \Drupal::entityTypeManager()->getStorage('search_api_index')->load('social_posts');
if (\$index) {
  echo 'Index directly: ' . (\$index->getOption('index_directly') ? 'YES' : 'NO') . PHP_EOL;
  echo 'Cron limit: ' . \$index->getOption('cron_limit') . PHP_EOL;
  
  \$tracker = \$index->getTrackerInstance();
  if (\$tracker) {
    echo 'Tracker class: ' . get_class(\$tracker) . PHP_EOL;
    echo 'Total items: ' . \$tracker->getTotalItemsCount() . PHP_EOL;
    echo 'Indexed items: ' . \$tracker->getIndexedItemsCount() . PHP_EOL;
    echo 'Pending items: ' . (\$tracker->getTotalItemsCount() - \$tracker->getIndexedItemsCount()) . PHP_EOL;
  }
}
"
```

Test update tracking (if content exists):
```bash
drush eval "
// Get a post and trigger re-index
\$nodes = \Drupal::entityTypeManager()->getStorage('node')
  ->getQuery()
  ->condition('type', 'post')
  ->accessCheck(FALSE)
  ->range(0, 1)
  ->execute();

if (!empty(\$nodes)) {
  \$node = \Drupal\node\Entity\Node::load(reset(\$nodes));
  echo 'Testing with node ' . \$node->id() . PHP_EOL;
  
  // Touch the node to trigger tracker
  \$node->save();
  echo 'Node saved - tracker should detect change' . PHP_EOL;
  
  // Check tracker status
  \$index = \Drupal::entityTypeManager()->getStorage('search_api_index')->load('social_posts');
  \$tracker = \$index->getTrackerInstance();
  echo 'Pending after save: ' . (\$tracker->getTotalItemsCount() - \$tracker->getIndexedItemsCount()) . PHP_EOL;
}
"
```
  </action>
  <verify>
    <automated>cd html && drush eval "\$i = \Drupal::entityTypeManager()->getStorage('search_api_index')->load('social_posts'); echo \$i && \$i->getOption('index_directly') ? 'PASS: index_directly enabled' : 'FAIL';"</automated>
    <manual>index_directly should be enabled, tracker should detect changes</manual>
    <sampling_rate>run after this task commits, before next task begins</sampling_rate>
  </verify>
  <done>Update/delete invalidation verified: Search API tracker handles entity lifecycle events</done>
</task>

<task type="auto">
  <name>Task 2: Run full indexing verification</name>
  <files>N/A (runtime verification)</files>
  <action>
Run a comprehensive verification of the complete indexing pipeline:

```bash
cd html

drush eval "
echo '=== Complete Indexing Pipeline Verification ===' . PHP_EOL . PHP_EOL;

// 1. Check all indexes
echo '1. Index Status' . PHP_EOL;
echo '   ==================' . PHP_EOL;
foreach (['social_posts', 'social_comments'] as \$index_id) {
  \$index = \Drupal::entityTypeManager()->getStorage('search_api_index')->load(\$index_id);
  if (\$index) {
    \$tracker = \$index->getTrackerInstance();
    echo '   ' . \$index->label() . ':' . PHP_EOL;
    echo '     - Enabled: ' . (\$index->status() ? 'Yes' : 'No') . PHP_EOL;
    echo '     - Index directly: ' . (\$index->getOption('index_directly') ? 'Yes' : 'No') . PHP_EOL;
    echo '     - Total items: ' . \$tracker->getTotalItemsCount() . PHP_EOL;
    echo '     - Indexed: ' . \$tracker->getIndexedItemsCount() . PHP_EOL;
  }
}

// 2. Check processors
echo PHP_EOL . '2. Processor Status' . PHP_EOL;
echo '   ==================' . PHP_EOL;
\$processors = ['group_metadata', 'comment_parent_context', 'file_content_extractor'];
foreach (\$processors as \$proc_id) {
  \$exists = \Drupal::getContainer()
    ->get('plugin.manager.search_api.processor')
    ->hasDefinition(\$proc_id);
  echo '   ' . \$proc_id . ': ' . (\$exists ? 'Available' : 'MISSING') . PHP_EOL;
}

// 3. Check AI services
echo PHP_EOL . '3. AI Services' . PHP_EOL;
echo '   ==================' . PHP_EOL;
try {
  \$providers = \Drupal::service('ai.provider')->getDefinitions();
  echo '   Available providers: ' . implode(', ', array_keys(\$providers)) . PHP_EOL;
} catch (\Exception \$e) {
  echo '   Warning: ' . \$e->getMessage() . PHP_EOL;
}

// 4. Check Milvus connection
echo PHP_EOL . '4. Vector Database' . PHP_EOL;
echo '   ==================' . PHP_EOL;
try {
  \$server = \Drupal::entityTypeManager()->getStorage('search_api_server')->load('ai_knowledge_garden');
  if (\$server) {
    echo '   Server: ' . \$server->label() . PHP_EOL;
    echo '   Backend: ' . \$server->getBackendId() . PHP_EOL;
  }
} catch (\Exception \$e) {
  echo '   Warning: ' . \$e->getMessage() . PHP_EOL;
}

echo PHP_EOL . '=== Verification Complete ===' . PHP_EOL;
"
```
  </action>
  <verify>
    <automated>cd html && drush eval "\$p = \Drupal::getContainer()->get('plugin.manager.search_api.processor'); echo \$p->hasDefinition('group_metadata') && \$p->hasDefinition('file_content_extractor') ? 'PASS: All processors available' : 'FAIL';"</automated>
    <manual>All indexes and processors should be operational</manual>
    <sampling_rate>run after this task commits, before next task begins</sampling_rate>
  </verify>
  <done>Complete indexing pipeline verified: posts, comments, files all indexed with Group metadata</done>
</task>

<task type="auto">
  <name>Task 3: Test file content extraction</name>
  <files>N/A (runtime verification)</files>
  <action>
Test the file content extraction capability:

```bash
cd html

# Check if there are any files to test with
drush eval "
\$files = \Drupal::entityTypeManager()->getStorage('file')
  ->getQuery()
  ->condition('filemime', [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  ], 'IN')
  ->accessCheck(FALSE)
  ->range(0, 5)
  ->execute();

echo 'Files found for testing: ' . count(\$files) . PHP_EOL;

if (!empty(\$files)) {
  foreach (\$files as \$fid) {
    \$file = \Drupal\file\Entity\File::load(\$fid);
    echo '  - File ' . \$fid . ': ' . \$file->getFilename() . ' (' . \$file->getMimeType() . ')' . PHP_EOL;
  }
}
"
```

If files exist, test extraction:
```bash
drush eval "
if (\Drupal::hasService('ai_file_to_text.extractor')) {
  echo 'File extraction service available' . PHP_EOL;
  
  \$files = \Drupal::entityTypeManager()->getStorage('file')
    ->getQuery()
    ->condition('filemime', 'application/pdf')
    ->accessCheck(FALSE)
    ->range(0, 1)
    ->execute();
  
  if (!empty(\$files)) {
    \$file = \Drupal\file\Entity\File::load(reset(\$files));
    echo 'Testing extraction from: ' . \$file->getFilename() . PHP_EOL;
    
    try {
      \$extractor = \Drupal::service('ai_file_to_text.extractor');
      \$content = \$extractor->extract(\$file);
      echo 'Extracted ' . strlen(\$content) . ' characters' . PHP_EOL;
      echo 'Preview: ' . substr(\$content, 0, 200) . '...' . PHP_EOL;
    } catch (\Exception \$e) {
      echo 'Error: ' . \$e->getMessage() . PHP_EOL;
    }
  }
} else {
  echo 'File extraction service not available' . PHP_EOL;
}
"
```
  </action>
  <verify>
    <automated>cd html && drush eval "echo \Drupal::hasService('ai_file_to_text.extractor') ? 'PASS: Extraction service available' : 'FAIL: No service';"</automated>
    <manual>File extraction service should be available and extract content</manual>
    <sampling_rate>run after this task commits, before next task begins</sampling_rate>
  </verify>
  <done>File content extraction tested and operational for PDFs and Office documents</done>
</task>

</tasks>

<verification>
## Phase 2 Plan 03c Verification

1. **Update/Delete Handling:**
   - [ ] index_directly enabled
   - [ ] Tracker detects entity changes
   - [ ] Tracker handles deletes

2. **Pipeline Status:**
   - [ ] All indexes operational
   - [ ] All processors available

3. **File Extraction:**
   - [ ] ai_file_to_text service available
   - [ ] PDF extraction works
   - [ ] Office document extraction works
</verification>

<success_criteria>
1. File uploads (PDFs, Office docs) are parsed and their content is indexed
2. File content is chunked and embedded alongside post content
3. Content updates trigger embedding regeneration (via Search API tracker)
4. Content deletes trigger embedding invalidation (via Search API tracker)
5. Search API tracker correctly handles update/delete events
</success_criteria>

<output>
After completion, create `.planning/phases/02-content-indexing/02-03c-SUMMARY.md` with:
- Update/delete invalidation test results
- Complete pipeline verification output
- File extraction test results
- Any issues encountered
</output>
