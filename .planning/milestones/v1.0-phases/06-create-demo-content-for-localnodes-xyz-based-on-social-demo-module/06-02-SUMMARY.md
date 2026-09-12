---
phase: 06-create-demo-content-for-localnodes-xyz-based-on-social-demo-module
plan: 02
subsystem: demo-content
tags: [drupal, yaml, demo-content, bioregionalism, localnodes, social-demo]

# Dependency graph
requires:
  - phase: 06-01
    provides: localnodes_demo module with 11 DemoContent plugin classes
provides:
  - 11 YAML content definition files with bioregionalism-themed demo content
  - 12 user personas, 5 groups, 10 topics, 18 posts, 13 comments, 8 events
  - Content designed to exercise AI features (embeddings, semantic search, permission filtering)
  - Varied visibility levels (public, community, group, recipient) for permission testing
affects: [06-03]

# Tech tracking
tech-stack:
  added: []
  patterns: [YAML content definition with cross-referenced UUIDs, AI coverage annotation comments]

key-files:
  created:
    - html/modules/custom/localnodes_demo/content/entity/user-terms.yml
    - html/modules/custom/localnodes_demo/content/entity/file.yml
    - html/modules/custom/localnodes_demo/content/entity/user.yml
    - html/modules/custom/localnodes_demo/content/entity/group.yml
    - html/modules/custom/localnodes_demo/content/entity/event-type.yml
    - html/modules/custom/localnodes_demo/content/entity/event.yml
    - html/modules/custom/localnodes_demo/content/entity/topic.yml
    - html/modules/custom/localnodes_demo/content/entity/post.yml
    - html/modules/custom/localnodes_demo/content/entity/comment.yml
    - html/modules/custom/localnodes_demo/content/entity/like.yml
    - html/modules/custom/localnodes_demo/content/entity/event-enrollment.yml
  modified: []

key-decisions:
  - "Copied 12 social_demo profile photos renamed for LocalNodes personas rather than downloading new images"
  - "Used 5 social_demo landscape images as group headers rather than downloading from Unsplash (simpler, no network dependency)"
  - "Created 121 unique UUIDs with zero overlap against social_demo"

patterns-established:
  - "AI coverage YAML comments: annotate which content exercises which AI features (embeddings, semantic search, permission filtering, cross-group retrieval)"
  - "Bioregionalism vocabulary density: content uses watershed, polycentric, cosmolocal, commons, mutual aid naturally throughout"

requirements-completed: [DEMO-CONTENT]

# Metrics
duration: 11min
completed: 2026-02-25
---

# Phase 06 Plan 02: Demo Content YAML Files Summary

**11 YAML content files with 121 bioregionalism-themed entities spanning 12 users, 5 groups, 10 topics, 18 posts, 13 comments, and supporting entities for AI feature exercise**

## Performance

- **Duration:** 11 min
- **Started:** 2026-02-25T08:55:39Z
- **Completed:** 2026-02-25T09:07:09Z
- **Tasks:** 2
- **Files modified:** 31 (11 YAML files + 20 image files)

## Accomplishments
- Created all 11 YAML content definition files with consistent cross-references across 121 unique UUIDs
- Designed content to exercise all AI features: embeddings (long topics), semantic search (varied vocabulary), permission filtering (group-only content), cross-group retrieval (overlapping memberships)
- Rich bioregionalism vocabulary throughout: watershed, polycentric governance, cosmolocal, commons, mutual aid, food sovereignty, regenerative economics, data sovereignty
- Full visibility coverage: public, community, group, and recipient-only content for permission testing

## Task Commits

Each task was committed atomically:

1. **Task 1: Create foundation YAML files (user-terms, files, users, groups)** - `b6ebacd` (feat)
2. **Task 2: Create content YAML files (events, topics, posts, comments, likes, enrollments)** - `8075133` (feat)

## Files Created/Modified

### Foundation entities (Task 1)
- `html/modules/custom/localnodes_demo/content/entity/user-terms.yml` - 12 bioregionalism taxonomy terms (profile_tag vocabulary)
- `html/modules/custom/localnodes_demo/content/entity/file.yml` - 20 file entries (12 profile photos, 3 content images, 5 landscape group headers)
- `html/modules/custom/localnodes_demo/content/entity/user.yml` - 12 user personas with bioregionalism roles, organizations, expertise tags
- `html/modules/custom/localnodes_demo/content/entity/group.yml` - 5 groups (4 open + 1 closed governance council)
- `html/modules/custom/localnodes_demo/content/files/` - 20 image files (profile photos and content images)

### Content entities (Task 2)
- `html/modules/custom/localnodes_demo/content/entity/event-type.yml` - 4 event type taxonomy terms
- `html/modules/custom/localnodes_demo/content/entity/event.yml` - 8 events (mix of past/future, varied visibility)
- `html/modules/custom/localnodes_demo/content/entity/topic.yml` - 10 topics (Blog, News, Dialog types with varied visibility)
- `html/modules/custom/localnodes_demo/content/entity/post.yml` - 18 social posts (community, public, group, recipient visibility)
- `html/modules/custom/localnodes_demo/content/entity/comment.yml` - 13 comments with threaded conversations
- `html/modules/custom/localnodes_demo/content/entity/like.yml` - 9 likes on popular topics and posts
- `html/modules/custom/localnodes_demo/content/entity/event-enrollment.yml` - 10 event enrollments

## Entity Counts

| Entity | Count | Plan Target |
|--------|-------|-------------|
| Users | 12 | 12 |
| Groups | 5 | 5 |
| Taxonomy Terms | 16 | 14-16 |
| Files | 20 | 15+ |
| Events | 8 | 6-8 |
| Topics | 10 | 8-10 |
| Posts | 18 | 15-18 |
| Comments | 13 | 10-15 |
| Likes | 9 | 8-12 |
| Enrollments | 10 | 6-10 |
| **Total UUIDs** | **121** | |

## AI Feature Coverage

| AI Feature | Content That Exercises It |
|-----------|--------------------------|
| Embeddings/Chunking | 2 long topics (300+ words): "Welcome to LocalNodes", "What is Bioregionalism?" |
| Semantic Search | Varied bioregionalism vocabulary across all content types |
| Permission Filtering | Group-only topic in Governance Council ("Treasury Allocation"); group-visibility posts |
| Cross-group Retrieval | Users with overlapping group memberships (Maria, Kwame, Suki in 3+ groups) |
| Comment Parent Context | 13 comments across topics, events, and posts with threaded replies |
| Recipient-only Filtering | 3 direct message posts (visibility 0) |

## Decisions Made
- Copied 12 social_demo profile photos renamed for LocalNodes personas rather than downloading external images -- simpler approach with no network dependency
- Used 5 social_demo landscape images as group headers rather than downloading from Unsplash -- equally effective for demo purposes
- Created all 121 UUIDs fresh with zero overlap against social_demo to prevent cross-module conflicts

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered
None.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- All 11 YAML content files ready for import via `drush sda` (social demo add) command
- Ready for Plan 03: Content creation and verification
- Content designed to populate search indexes for AI feature testing

## Self-Check: PASSED

All 11 YAML content files verified present. Both task commits (b6ebacd, 8075133) verified in git log. SUMMARY.md verified present. 14/14 checks passed.

---
*Phase: 06-create-demo-content-for-localnodes-xyz-based-on-social-demo-module*
*Completed: 2026-02-25*
