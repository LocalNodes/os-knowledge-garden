# Phase 3: Permission-Aware Retrieval - Research

**Researched:** 2026-02-24
**Domain:** RAG Permission Filtering, Drupal Group Access Control, Milvus Metadata Filtering
**Confidence:** HIGH

## Summary

Permission-aware retrieval in RAG systems requires a **defense-in-depth** approach with two layers: **pre-retrieval metadata filtering** (primary defense) and **post-retrieval entity access checks** (secondary defense). The Drupal AI ecosystem already provides the building blocks for both layers.

Pre-retrieval filtering uses Milvus scalar filtering to restrict vector search scope to only content in groups the user can access. Post-retrieval checks use Drupal's core entity access system to validate each result. This two-layer approach ensures that even if one layer fails, unauthorized content never reaches the AI response.

The existing `social_ai_indexing` module already indexes `group_id` metadata via the `GroupMetadata` processor, providing the foundation for pre-retrieval filtering.

**Primary recommendation:** Use Search API conditions with `group_id` field for pre-filtering, combined with `$entity->access('view')` for post-retrieval validation.

<phase_requirements>

## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| PERM-01 | Pre-retrieval metadata filtering respects Drupal Group permissions | Use `group.membership_loader` service to get user's groups, then add Search API condition on `group_id` field |
| PERM-02 | Post-retrieval entity access check provides defense-in-depth | Use `$entity->access('view', $account)` for each retrieved entity; ai_search has built-in support |
| PERM-03 | AI responses only contain content the querying user is authorized to see | Combine PERM-01 + PERM-02 for defense-in-depth |
| PERM-04 | Community-wide search only surfaces public content when queried globally | Handle "no group context" as public-only filter; Open Social has visibility fields |
| PERM-05 | Group-scoped queries only surface content from that Group | Single Group ID filter when context is a specific group |

</phase_requirements>

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| Drupal Group module | 3.x | Group membership and permission management | Required by Open Social; provides `group.membership_loader` service |
| Search API | 1.x | Query abstraction layer | Already used by ai_search; provides condition API |
| Milvus | 2.4.x | Vector database with scalar filtering | Supports `IN` operator for group_id filtering |
| ai_search | 2.0.x | Vector search with entity access checks | Built-in post-query access control |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| ai_vdb_provider_milvus | 1.x | Milvus integration with Search API | Converts Search API conditions to Milvus filter expressions |
| flexible_permissions | 1.x | Open Social flexible group visibility | When checking public content access |

### Key Services
| Service | Purpose |
|---------|---------|
| `group.membership_loader` | Load user's group memberships via `loadByUser($account)` |
| `entity_type.manager` | Load entities for post-retrieval access checks |
| `search_api.query` | Build queries with conditions |

## Architecture Patterns

### Pattern 1: Pre-Retrieval Group Filtering (Primary Defense)

**What:** Filter vector search results by user's accessible Group IDs before similarity search.

**When to use:** Always — this is the primary security layer for permission-aware retrieval.

**Implementation:**
```php
// 1. Get user's accessible group IDs
$memberships = \Drupal::service('group.membership_loader')->loadByUser($account);
$group_ids = [];
foreach ($memberships as $membership) {
  $group_ids[] = $membership->getGroup()->id();
}

// 2. Add condition to Search API query
$query = $index->query();
if (!empty($group_ids)) {
  $query->addCondition('group_id', $group_ids, 'IN');
}

// 3. MilvusProvider converts to: group_id in [1, 2, 3]
```

**Source:** MilvusProvider::processConditionGroup() converts `IN` operator to `JSON_CONTAINS_ANY` for multiple values.

### Pattern 2: Post-Retrieval Entity Access Check (Secondary Defense)

**What:** Validate each retrieved entity against Drupal's access system before including in AI response.

**When to use:** Always — this is defense-in-depth, catching any edge cases pre-filtering misses.

**Implementation:**
```php
// For each retrieved result
$entity = \Drupal::entityTypeManager()
  ->getStorage($entity_type)
  ->load($entity_id);

if (!$entity->access('view', $account)) {
  // Skip this result — user cannot view it
  continue;
}
```

**Source:** ai_search module documentation confirms "Post-query access checks ensure users only see content they are authorized to view."

### Pattern 3: Community-Wide Public Content Filter

**What:** When no group context is specified, only return publicly visible content.

**When to use:** Community-wide search queries that span all groups.

**Implementation:**
```php
// Option 1: Filter by group visibility (Open Social specific)
// Public groups have specific visibility settings
$query->addCondition('group_visibility', 'public');

// Option 2: Use a special "public" marker in metadata
// During indexing, mark public content with group_id = 0 or a flag
```

### Recommended Project Structure

```
html/modules/custom/social_ai_retrieval/
├── social_ai_retrieval.info.yml
├── social_ai_retrieval.services.yml
├── src/
│   ├── Service/
│   │   ├── PermissionFilterService.php    # Pre-retrieval filtering
│   │   └── AccessCheckService.php         # Post-retrieval validation
│   └── Plugin/
│       └── search_api/
│           └── processor/
│               └── PublicContentMarker.php # Mark public content at index time
```

### Anti-Patterns to Avoid

- **Only post-retrieval filtering:** Inefficient and insecure — data already fetched into memory
- **Hand-rolling Milvus filter expressions:** Use Search API conditions; MilvusProvider converts them
- **Ignoring public content:** Community-wide search needs explicit public content handling
- **Assuming membership = access:** Open Social visibility fields can override group membership
- **Caching permission decisions:** Permissions can change; always re-check at query time

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Get user's groups | Custom SQL query | `group.membership_loader->loadByUser()` | Handles all group types, caching, edge cases |
| Filter by group_id | Raw Milvus filter string | `$query->addCondition('group_id', $ids, 'IN')` | MilvusProvider handles conversion, escaping |
| Check entity access | Custom permission logic | `$entity->access('view', $account)` | Respects Group module permissions, visibility fields |
| Build filter expressions | String concatenation | Search API Query conditions | Proper escaping, handles multiple value types |

**Key insight:** The Drupal AI ecosystem already provides the security primitives. The task is wiring them together correctly, not building new permission systems.

## Common Pitfalls

### Pitfall 1: Data Leakage Through Multiple Group Membership
**What goes wrong:** Content belongs to both a private group (user is member) and a secret group (user is not member). If only checking membership, user might see content they shouldn't.

**Why it happens:** Pre-filtering with `IN` operator includes content if it matches ANY group, not ALL groups.

**How to avoid:** Post-retrieval access check catches this — always use both layers.

**Warning signs:** Content appearing in results that user couldn't access via normal navigation.

### Pitfall 2: Stale Permission Caching
**What goes wrong:** User is removed from a group but can still search that group's content.

**Why it happens:** Permission decisions cached at index time or query time.

**How to avoid:** Never cache permission decisions. Always re-evaluate at query time using live services.

**Warning signs:** Recently removed members still finding group content.

### Pitfall 3: Public Content in Private Groups
**What goes wrong:** Content marked "public" within a private group appears in community-wide search.

**Why it happens:** Open Social allows per-content visibility settings within groups.

**How to avoid:** Check both group visibility AND content visibility fields. Public content in private groups may need special handling based on requirements.

**Warning signs:** Private group content appearing in public search results.

### Pitfall 4: Missing Group ID Metadata
**What goes wrong:** Some content types don't have `group_id` in the index, causing permission bypass.

**Why it happens:** New content types added without updating GroupMetadata processor.

**How to avoid:** Verify all indexed content types have `group_id` field configured in Search API index.

**Warning signs:** Search results include content from unexpected sources.

## Code Examples

### Get User's Accessible Group IDs

```php
<?php
// Source: Drupal Group module API
// File: PermissionFilterService.php

namespace Drupal\social_ai_retrieval\Service;

use Drupal\Core\Session\AccountInterface;

class PermissionFilterService {

  /**
   * Get Group IDs the user can access.
   */
  public function getAccessibleGroupIds(AccountInterface $account): array {
    $memberships = \Drupal::service('group.membership_loader')
      ->loadByUser($account);
    
    $group_ids = [];
    foreach ($memberships as $membership) {
      $group_ids[] = (int) $membership->getGroup()->id();
    }
    
    return array_unique($group_ids);
  }

  /**
   * Check if query is community-wide (no group context).
   */
  public function isCommunityWideQuery(): bool {
    // Check if we're in a group context via route
    $group = \Drupal::routeMatch()->getParameter('group');
    return empty($group);
  }

}
```

### Pre-Retrieval Filter Integration

```php
<?php
// Source: MilvusProvider::processConditionGroup() pattern
// Applies group_id filter to Search API query

public function applyPermissionFilter(QueryInterface $query, AccountInterface $account): void {
  $group_ids = $this->getAccessibleGroupIds($account);
  
  if ($this->isCommunityWideQuery()) {
    // Community-wide: only public content
    // Requires a "is_public" field or similar marker
    $query->addCondition('is_public', TRUE);
  }
  elseif (!empty($group_ids)) {
    // Group-scoped: filter by accessible groups
    $query->addCondition('group_id', $group_ids, 'IN');
  }
  else {
    // No groups accessible: return no results
    $query->addCondition('group_id', -1, '='); // Impossible condition
  }
}
```

### Post-Retrieval Access Check

```php
<?php
// Source: Drupal Entity API, ai_search pattern

public function filterResultsByAccess(array $results, AccountInterface $account): array {
  $filtered = [];
  
  foreach ($results as $result) {
    // Extract entity info from result
    $entity_type = $result['drupal_entity_type'] ?? 'node';
    $entity_id = $result['drupal_entity_id'] ?? NULL;
    
    if (!$entity_id) {
      continue;
    }
    
    $entity = \Drupal::entityTypeManager()
      ->getStorage($entity_type)
      ->load($entity_id);
    
    if ($entity && $entity->access('view', $account)) {
      $filtered[] = $result;
    }
  }
  
  return $filtered;
}
```

### Milvus Filter Expression (Generated by MilvusProvider)

```php
// Source: MilvusProvider::processConditionGroup()
// Input: $query->addCondition('group_id', [1, 2, 3], 'IN')
// Output filter expression:

// For single-value field (integer type):
"group_id in [1, 2, 3]"

// For multi-value field (JSON array):
"JSON_CONTAINS_ANY(group_id, [1, 2, 3])"

// Combined with other filters:
"(group_id in [1, 2, 3]) && (content_type == \"post\")"
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Post-retrieval filtering only | Pre-retrieval + post-retrieval (defense-in-depth) | 2024 RAG security best practices | Prevents data leakage, improves efficiency |
| Custom permission queries | Search API conditions | ai_search 2.0 | Standard API, proper escaping |
| Single group filter | IN operator for multiple groups | Milvus 2.4 native | Handles users in multiple groups |

**Deprecated/outdated:**
- OG (Organic Groups) module: Open Social now uses the Group module; OG-specific patterns don't apply
- Hardcoded permission checks: Use Drupal's access system for flexibility

## Open Questions

1. **How should "public" content be identified in the index?**
   - What we know: Open Social has visibility fields but they're on groups, not individual content
   - What's unclear: Should we add an `is_public` metadata field during indexing, or derive it at query time?
   - Recommendation: Add `is_public` boolean field during indexing via a new processor that checks group visibility settings

2. **What about content in multiple groups with different visibility?**
   - What we know: Content can belong to multiple groups (e.g., public group + private group)
   - What's unclear: Should the content be visible if user has access to ANY of the groups, or only the "most restrictive"?
   - Recommendation: Follow Drupal's access pattern — if user can access ANY of the groups, they can see the content. Post-retrieval check handles edge cases.

3. **Should we handle anonymous users differently?**
   - What we know: Anonymous users have no group memberships
   - What's unclear: Should community-wide search show public content to anonymous users?
   - Recommendation: Yes — if requirements allow anonymous access to public content, the `is_public` filter will handle this automatically.

## Sources

### Primary (HIGH confidence)
- MilvusProvider::processConditionGroup() - Examined source code for filter expression generation
- MilvusProvider::vectorSearch() - Confirmed filter parameter is passed through to Milvus
- GroupMetadata.php - Existing processor that indexes group_id metadata
- Group module membership_loader service - Drupal.org API documentation

### Secondary (MEDIUM confidence)
- Drupal.org ai_search project page - Confirmed "Post-query access checks" feature
- Milvus scalar filtering documentation - Verified filter expression syntax
- RAG security best practices 2024 - Defense-in-depth pattern confirmed

### Tertiary (LOW confidence)
- Open Social group visibility fields - Need validation during implementation for exact field names

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - All components already installed and configured
- Architecture: HIGH - Patterns verified against source code and documentation
- Pitfalls: MEDIUM - Based on general RAG security best practices; Open Social specifics need validation during implementation

**Research date:** 2026-02-24
**Valid until:** 30 days - Core patterns stable; Open Social visibility fields may need re-verification
