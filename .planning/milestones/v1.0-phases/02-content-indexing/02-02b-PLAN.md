---
phase: 02-content-indexing
plan: 02b
type: execute
wave: 3
depends_on: [02-02a]
files_modified: [modules/custom/social_ai_indexing/src/Plugin/SearchApi/Processor/GroupMetadata.php]
autonomous: true
requirements: [IDX-02, IDX-04, IDX-05]
user_setup: []

must_haves:
  truths:
    - "Comments are indexed with embeddings that include parent post context"
    - "Comment chunks include parent post title and summary for retrieval"
    - "Each indexed comment has Group ID metadata attached for filtering"
    - "Comment indexing respects parent post's group membership"
  artifacts:
    - path: "html/modules/custom/social_ai_indexing/src/Plugin/SearchApi/Processor/GroupMetadata.php"
      provides: "Updated Group ID extraction for comments"
      contains: "comment handling"
  key_links:
    - from: "GroupMetadata processor"
      to: "comment parent entity"
      via: "getCommentedEntityId"
      pattern: "comment.*parent"
---

<objective>
Update GroupMetadata processor to handle comments and verify complete comment indexing.

Purpose: Enable comments to inherit Group membership from their parent posts.
Output: Working comment indexing with parent context and Group metadata.

**Dependency on 02-02a:** Requires the CommentParentContext processor and social_comments index from Plan 02-02a.
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
@.planning/phases/02-content-indexing/02-02a-SUMMARY.md
</context>

<tasks>

<task type="auto">
  <name>Task 1: Update GroupMetadata processor for comments</name>
  <files>html/modules/custom/social_ai_indexing/src/Plugin/SearchApi/Processor/GroupMetadata.php</files>
  <action>
Update the GroupMetadata processor to also handle comments by looking up the parent entity's group membership.

Add comment handling to the `addFieldValues` method. The file was created in 02-01a, now update it:

Add the use statement at the top:
```php
use Drupal\comment\CommentInterface;
```

Update the `addFieldValues` method:
```php
public function addFieldValues(ItemInterface $item): void {
  $entity = $item->getOriginalObject()->getValue();

  if (!$entity || !method_exists($entity, 'getEntityTypeId')) {
    return;
  }

  $entity_type = $entity->getEntityTypeId();
  $entity_id = $entity->id();

  if (!$entity_id) {
    return;
  }

  // For comments, get group from parent entity
  if ($entity_type === 'comment' && $entity instanceof CommentInterface) {
    $parent_type = $entity->getCommentedEntityTypeId();
    $parent_id = $entity->getCommentedEntityId();
    if ($parent_type && $parent_id) {
      $entity_type = $parent_type;
      $entity_id = $parent_id;
    }
  }

  $group_ids = $this->getGroupIdsForEntity($entity_type, (int) $entity_id);

  if (empty($group_ids)) {
    return;
  }

  $fields = $item->getFields(FALSE);
  foreach ($fields as $field) {
    if ($field->getPropertyPath() === 'group_id') {
      foreach ($group_ids as $group_id) {
        $field->addValue((int) $group_id);
      }
    }
  }
}
```

Clear cache:
```bash
cd html
drush cr
```
  </action>
  <verify>
    <automated>cd html && grep -A5 "CommentInterface" html/modules/custom/social_ai_indexing/src/Plugin/SearchApi/Processor/GroupMetadata.php | head -10</automated>
    <manual>GroupMetadata.php should contain comment handling logic</manual>
    <sampling_rate>run after this task commits, before next task begins</sampling_rate>
  </verify>
  <done>GroupMetadata processor updated to handle comments via parent entity lookup</done>
</task>

<task type="auto">
  <name>Task 2: Test comment indexing with parent context</name>
  <files>N/A (runtime verification)</files>
  <action>
Test that comments are being indexed with parent context:

```bash
cd html

# Check comment count
drush eval "
\$comment_count = \Drupal::entityTypeManager()->getStorage('comment')
  ->getQuery()
  ->accessCheck(FALSE)
  ->count()
  ->execute();
echo 'Comments found: ' . \$comment_count . PHP_EOL;
"

# Check index status
drush search-api:status social_comments

# If items exist, run indexing
drush search-api:index social_comments
```

Verify parent context extraction:
```bash
drush eval "
\$processor = \Drupal::getContainer()
  ->get('plugin.manager.search_api.processor')
  ->createInstance('comment_parent_context');

if (\$processor) {
  echo 'Comment Parent Context processor loaded' . PHP_EOL;
  
  \$comments = \Drupal::entityTypeManager()->getStorage('comment')
    ->getQuery()
    ->accessCheck(FALSE)
    ->range(0, 1)
    ->execute();
  
  if (!empty(\$comments)) {
    \$comment = \Drupal\comment\Entity\Comment::load(reset(\$comments));
    echo 'Testing with comment ' . \$comment->id() . PHP_EOL;
    
    \$reflection = new \ReflectionClass(\$processor);
    \$method = \$reflection->getMethod('getParentEntity');
    \$method->setAccessible(TRUE);
    \$parent = \$method->invoke(\$processor, \$comment);
    
    if (\$parent) {
      echo 'Parent entity: ' . \$parent->getEntityTypeId() . '/' . \$parent->id() . PHP_EOL;
      echo 'Parent title: ' . \$parent->label() . PHP_EOL;
    }
  }
}
"
```
  </action>
  <verify>
    <automated>cd html && drush search-api:status social_comments 2>&1 | head -15</automated>
    <manual>Comment index status should show items being indexed</manual>
    <sampling_rate>run after this task commits, before next task begins</sampling_rate>
  </verify>
  <done>Comment indexing operational with parent post context enrichment</done>
</task>

<task type="auto">
  <name>Task 3: Verify end-to-end comment indexing</name>
  <files>N/A (runtime verification)</files>
  <action>
Run a complete verification of comment indexing:

```bash
cd html

drush eval "
echo '=== Comment Indexing Verification ===' . PHP_EOL . PHP_EOL;

// 1. Check index exists
echo '1. Checking index...' . PHP_EOL;
\$index = \Drupal::entityTypeManager()->getStorage('search_api_index')->load('social_comments');
if (\$index) {
  echo '   Index: ' . \$index->label() . PHP_EOL;
  echo '   Server: ' . \$index->getServerId() . PHP_EOL;
  echo '   Status: ' . (\$index->status() ? 'Enabled' : 'Disabled') . PHP_EOL;
  
  // 2. Check fields
  echo PHP_EOL . '2. Checking fields...' . PHP_EOL;
  \$fields = \$index->getFields();
  foreach (['rendered_item', 'parent_post_title', 'parent_post_summary', 'group_id'] as \$field_id) {
    \$status = isset(\$fields[\$field_id]) ? 'present' : 'MISSING';
    echo '   ' . \$field_id . ': ' . \$status . PHP_EOL;
  }
  
  // 3. Check processors
  echo PHP_EOL . '3. Checking processors...' . PHP_EOL;
  \$processors = \$index->getProcessors();
  foreach (['comment_parent_context', 'group_metadata'] as \$proc_id) {
    \$status = isset(\$processors[\$proc_id]) ? 'enabled' : 'MISSING';
    echo '   ' . \$proc_id . ': ' . \$status . PHP_EOL;
  }
  
  // 4. Check tracker
  echo PHP_EOL . '4. Checking tracker...' . PHP_EOL;
  \$tracker = \$index->getTrackerInstance();
  if (\$tracker) {
    \$total = \$tracker->getTotalItemsCount();
    \$indexed = \$tracker->getIndexedItemsCount();
    echo '   Total items: ' . \$total . PHP_EOL;
    echo '   Indexed: ' . \$indexed . PHP_EOL;
  }
}

echo PHP_EOL . '=== Verification Complete ===' . PHP_EOL;
"
```
  </action>
  <verify>
    <automated>cd html && drush eval "\$i = \Drupal::entityTypeManager()->getStorage('search_api_index')->load('social_comments'); echo \$i && isset(\$i->getProcessors()['comment_parent_context']) ? 'PASS' : 'FAIL';"</automated>
    <manual>All verification checks should pass</manual>
    <sampling_rate>run after this task commits, before next task begins</sampling_rate>
  </verify>
  <done>Comment indexing fully verified: index, fields, processors, tracker all operational</done>
</task>

</tasks>

<verification>
## Phase 2 Plan 02b Verification

1. **GroupMetadata for Comments:**
   - [ ] GroupMetadata.php updated for comment handling
   - [ ] Comments inherit parent's group membership

2. **Indexing Status:**
   - [ ] Comments being tracked
   - [ ] `index_directly` enabled
   - [ ] Items indexed with parent context
</verification>

<success_criteria>
1. Comments are indexed with parent post context (title and summary included)
2. Comment chunks include parent post title for better retrieval
3. Each indexed comment has Group ID metadata (via parent entity lookup)
4. Comment indexing respects parent post's group membership
</success_criteria>

<output>
After completion, create `.planning/phases/02-content-indexing/02-02b-SUMMARY.md` with:
- GroupMetadata update for comments
- Test results for parent context extraction
- Any issues encountered
</output>
