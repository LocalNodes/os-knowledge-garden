---
phase: 06-create-demo-content-for-localnodes-xyz-based-on-social-demo-module
plan: 05
subsystem: demo-content
tags: [drupal, yaml, demo-content, boulder, regen-tech, ethereum-localism, watershed, localnodes]

# Dependency graph
requires:
  - phase: 06-04
    provides: boulder_demo module with 11 DemoContent plugin classes
  - phase: 06-02
    provides: localnodes_demo YAML content structure as template
provides:
  - 11 YAML content definition files with Boulder/Front Range themed demo content
  - 12 user personas, 5 groups, 10 topics (all 5 types), 18 posts, 14 comments, 8 events
  - Research and Question topic types showcased (new types not in Cascadia)
  - Cross-node Cascadia references for network effect simulation
  - Content designed to exercise AI features (embeddings, semantic search, permission filtering)
affects: [06-06]

# Tech tracking
tech-stack:
  added: []
  patterns: [Boulder-specific YAML content with cross-node references, Research and Question topic types]

key-files:
  created:
    - html/modules/custom/boulder_demo/content/entity/user-terms.yml
    - html/modules/custom/boulder_demo/content/entity/file.yml
    - html/modules/custom/boulder_demo/content/entity/user.yml
    - html/modules/custom/boulder_demo/content/entity/group.yml
    - html/modules/custom/boulder_demo/content/entity/event-type.yml
    - html/modules/custom/boulder_demo/content/entity/event.yml
    - html/modules/custom/boulder_demo/content/entity/topic.yml
    - html/modules/custom/boulder_demo/content/entity/post.yml
    - html/modules/custom/boulder_demo/content/entity/comment.yml
    - html/modules/custom/boulder_demo/content/entity/like.yml
    - html/modules/custom/boulder_demo/content/entity/event-enrollment.yml
  modified: []

key-decisions:
  - "Mapped social_demo named photos (alisonhendrix, benflorez, etc.) to Boulder personas since plan-specified source filenames did not exist"
  - "Used 119 unique UUIDs with zero overlap against both social_demo and localnodes_demo"
  - "Research and Question topic types use exact string names (not UUIDs) per prepareTopicType() method"

patterns-established:
  - "Cross-node references: 3 posts + 1 comment reference Cascadia node for network effect"
  - "Boulder vocabulary density: BDA, quadratic funding, regen tech, cosmo-local, Ethereum localism woven naturally throughout"
  - "Dual new topic types: Research (BDA methodology paper) and Question (tech-land divide open question) demonstrate topic type diversity"

requirements-completed: [DEMO-CONTENT]

# Metrics
duration: 12min
completed: 2026-02-28
---

# Phase 06 Plan 05: Boulder Demo Content YAML Files Summary

**11 YAML content files with 119 Boulder/Front Range themed entities spanning 12 users, 5 groups, 10 topics (all 5 types including Research and Question), 18 posts, 14 comments, and cross-node Cascadia references for network effect**

## Performance

- **Duration:** 12 min
- **Started:** 2026-02-28T10:12:40Z
- **Completed:** 2026-02-28T10:24:53Z
- **Tasks:** 2
- **Files modified:** 28 (11 YAML files + 17 image files)

## Accomplishments
- Created all 11 YAML content definition files with consistent cross-references across 119 unique UUIDs
- All 5 topic types represented: Blog (3), News (2), Dialog (3), Research (1), Question (1) -- Research and Question are new types not used in Cascadia
- Rich Boulder-specific vocabulary: BDA, beaver dam analogs, quadratic funding, Ethereum localism, regen tech, cosmo-local, Front Range, South Platte, Continental Divide
- 3 posts and 1 comment explicitly reference the Cascadia node for inter-node network effect
- Full visibility coverage: public (3 posts), community (8 posts), group (4 posts), and DM (3 posts) for permission testing
- All user timezones America/Denver, all event addresses in Boulder/Front Range CO area

## Task Commits

Each task was committed atomically:

1. **Task 1: Create foundation YAML files (user-terms, files, users, groups)** - `57c9a28` (feat)
2. **Task 2: Create content YAML files (events, topics, posts, comments, likes, enrollments)** - `8b4b22f` (feat)

## Files Created/Modified

### Foundation entities (Task 1)
- `html/modules/custom/boulder_demo/content/entity/user-terms.yml` - 12 profile tag taxonomy terms (6 shared with Cascadia, 6 Boulder-unique)
- `html/modules/custom/boulder_demo/content/entity/file.yml` - 17 file entries (12 profile photos, 5 landscape images)
- `html/modules/custom/boulder_demo/content/entity/user.yml` - 12 user personas with Boulder roles, organizations, expertise
- `html/modules/custom/boulder_demo/content/entity/group.yml` - 5 groups (4 open + 1 closed governance circle)
- `html/modules/custom/boulder_demo/content/files/` - 17 image files (profile photos and landscape images)

### Content entities (Task 2)
- `html/modules/custom/boulder_demo/content/entity/event-type.yml` - 4 event type taxonomy terms
- `html/modules/custom/boulder_demo/content/entity/event.yml` - 8 events (mix of past/future, varied visibility, Boulder/Front Range addresses)
- `html/modules/custom/boulder_demo/content/entity/topic.yml` - 10 topics using all 5 types (Blog, News, Dialog, Research, Question)
- `html/modules/custom/boulder_demo/content/entity/post.yml` - 18 social posts with cross-node references
- `html/modules/custom/boulder_demo/content/entity/comment.yml` - 14 comments with threaded conversations
- `html/modules/custom/boulder_demo/content/entity/like.yml` - 9 likes on popular topics and posts
- `html/modules/custom/boulder_demo/content/entity/event-enrollment.yml` - 10 event enrollments for future events

## Entity Counts

| Entity | Count | Plan Target |
|--------|-------|-------------|
| Users | 12 | 12 |
| Groups | 5 | 5 |
| Taxonomy Terms | 16 | 16 |
| Files | 17 | 17 |
| Events | 8 | 8 |
| Topics | 10 | 10 |
| Posts | 18 | 18 |
| Comments | 14 | 13+ |
| Likes | 9 | 9 |
| Enrollments | 10 | 10 |
| **Total UUIDs** | **119** | |

## AI Feature Coverage

| AI Feature | Content That Exercises It |
|-----------|--------------------------|
| Embeddings/Chunking | 3 long topics (300+ words): "Welcome to Boulder Node", "Ethereum Localism", "BDA Research" |
| Semantic Search | Boulder-specific vocabulary (BDA, quadratic funding, regen tech) distinct from Cascadia |
| Permission Filtering | Group-only topic in Governance Circle ("Treasury Allocation"); group-visibility posts |
| Cross-group Retrieval | Users with overlapping memberships: Sage, Finn, Jess in 3+ groups |
| New Topic Types | Research (BDA methodology paper) and Question (tech-land divide) |
| Cross-node Network | 3 posts + 1 comment referencing Cascadia node |
| Comment Parent Context | 14 comments across topics, events, and posts with threaded replies |
| Recipient-only Filtering | 3 direct message posts (visibility 0) |

## Decisions Made
- Mapped social_demo named photos to Boulder personas (alisonhendrix.jpg -> mira_solano.jpg, etc.) since plan-specified source filenames (judemiller.jpg, etc.) did not exist in social_demo
- Used all 5 topic types including Research and Question with exact string names for field_topic_type (not UUIDs), matching prepareTopicType() lookup behavior
- Created 119 unique UUIDs with zero overlap against both social_demo and localnodes_demo

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Corrected profile photo source filenames**
- **Found during:** Task 1 (foundation files)
- **Issue:** Plan specified social_demo source filenames (judemiller.jpg, jeanbaptiste.jpg, timburke.jpg, etc.) that do not exist in social_demo content/files/
- **Fix:** Mapped available social_demo named photos (alisonhendrix.jpg, benflorez.jpg, michelleclark.jpg, thomaswolf.jpg, susanwilliams.jpg, frankanderson.jpg, robertandrews.jpg, paulharris.jpg, chrishall.jpg, petershaw.jpg, jannamiesner.jpg, extramale3.jpg) to Boulder persona names
- **Files modified:** Image file copies in boulder_demo/content/files/
- **Verification:** All 12 profile photos and 5 landscape images present (17 total)
- **Committed in:** 57c9a28 (Task 1 commit)

---

**Total deviations:** 1 auto-fixed (1 blocking)
**Impact on plan:** Source filename correction was necessary since plan-specified files did not exist. No impact on content quality -- same social_demo photos used with different filename mapping.

## Issues Encountered
None beyond the source filename deviation noted above.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- All 11 YAML content files ready for import via boulder_demo module
- Ready for Plan 06: Boulder demo content verification and testing
- Content designed to exercise AI features including new Research and Question topic types

## Self-Check: PASSED

All 11 YAML content files verified present. Both task commits (57c9a28, 8b4b22f) verified in git log. SUMMARY.md verified present. 17 image files confirmed. 14/14 checks passed.

---
*Phase: 06-create-demo-content-for-localnodes-xyz-based-on-social-demo-module*
*Completed: 2026-02-28*
