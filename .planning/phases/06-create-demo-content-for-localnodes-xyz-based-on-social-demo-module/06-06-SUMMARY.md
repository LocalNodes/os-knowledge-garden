---
phase: 06-create-demo-content-for-localnodes-xyz-based-on-social-demo-module
plan: 06
subsystem: demo-content
tags: [drupal, drush, demo-content, boulder, content-install, search-api, qdrant]

# Dependency graph
requires:
  - phase: 06-05
    provides: 11 YAML content definition files with 119 Boulder/Front Range themed entities
  - phase: 06-04
    provides: boulder_demo module with 11 DemoContent plugin classes
provides:
  - Fully populated Boulder LocalNodes instance with 119 demo entities
  - Clean environment with only boulder_demo content (no localnodes/social_demo remnants)
  - Search API indexes updated with new boulder content for AI features
  - Verified entity integrity (memberships, visibility, threading, timezones)
affects: []

# Tech tracking
tech-stack:
  added: []
  patterns: [drush sdr/sda content lifecycle management, entity verification via direct SQL and entity API]

key-files:
  created: []
  modified: []

key-decisions:
  - "Ran localnodes content removal twice -- first pass removed most entities but left events; second pass cleaned remaining"
  - "Ran localnodes_demo update hook 10002 to rename Resource topic type to Research before content install"

patterns-established:
  - "Demo content lifecycle: sdr in reverse dependency order, then sda in forward dependency order"
  - "Entity count verification via direct SQL queries to avoid entity cache false positives"

requirements-completed: [DEMO-INSTALL]

# Metrics
duration: 8min
completed: 2026-02-28
---

# Phase 06 Plan 06: Boulder Demo Content Install and Verification Summary

**Removed localnodes demo content, installed 119 Boulder entities via drush sda, verified entity counts, visibility distribution, group memberships, comment threading, topic types, and Search API indexing**

## Performance

- **Duration:** 8 min
- **Started:** 2026-02-28T10:28:24Z
- **Completed:** 2026-02-28T10:36:24Z
- **Tasks:** 2
- **Files modified:** 0 (operational tasks only -- database changes via drush)

## Accomplishments
- Cleanly removed all localnodes_demo content (users, groups, topics, events, posts, comments, likes, enrollments) for a fresh Boulder-only environment
- Installed all 119 boulder_demo entities in correct dependency order with zero errors
- Verified all entity counts match plan targets: 12 users, 5 groups, 10 topics, 8 events, 18 posts, 14 comments, 9 likes, 10 enrollments
- Confirmed Research and Question topic types display correctly on their respective topics
- Verified group memberships (5-8 members per group), visibility distribution (all 4 post levels, 3 topic levels), comment threading (3 threaded replies), and America/Denver timezones for all 12 users
- Search API indexes (social_posts, social_comments) confirmed up to date for AI knowledge garden features

## Task Commits

Both tasks were operational (drush commands against the database) with no file changes:

1. **Task 1: Remove existing demo content and install boulder_demo content** - No commit (operational: drush sdr + sda)
2. **Task 2: Verify content integrity and cross-references** - No commit (verification only)

**Plan metadata:** (docs commit below)

## Files Created/Modified

No files created or modified -- this plan was purely operational, executing drush commands to manage database content.

## Entity Verification Results

| Check | Expected | Actual | Status |
|-------|----------|--------|--------|
| Users (uid>1) | 12 | 12 | PASS |
| Groups | 5 | 5 | PASS |
| Topics | 10 | 10 | PASS |
| Events | 8 | 8 | PASS |
| Posts | 18 | 18 | PASS |
| Comments | 13+ | 14 | PASS |
| Likes | 9 | 9 | PASS |
| Enrollments | 10 | 10 | PASS |
| Threaded replies | 2+ | 3 | PASS |
| Research type topics | 1+ | 1 | PASS |
| Question type topics | 1+ | 1 | PASS |
| America/Denver users | 12 | 12 | PASS |

## Visibility Coverage

| Level | Posts | Topics |
|-------|-------|--------|
| Recipient (0) | 3 | - |
| Community (1) | 8 | 8 |
| Public (2) | 3 | 1 |
| Group (3) | 4 | 1 |

## Group Membership

| Group | Members |
|-------|---------|
| Front Range Watershed Hub | 8 |
| Boulder Regen Tech Collective | 7 |
| Front Range Food Commons | 6 |
| RegenHub Boulder | 8 |
| Boulder Governance Circle | 5 |

## Comment Distribution

| Bundle | Count |
|--------|-------|
| comment (on topics/events) | 9 |
| post_comment (on posts) | 5 |

## Decisions Made
- Ran localnodes content removal twice because the first sdr pass left localnodes event entities in the database (the sdr command reported success but 8 event nodes persisted, likely due to a timing/cache issue with the initial bulk removal)
- Ran pending database update hook (localnodes_demo_update_10002) to rename "Resource" topic type to "Research" before installing boulder content

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Localnodes event entities not removed on first pass**
- **Found during:** Task 1 (content removal and installation)
- **Issue:** After running `ddev drush sdr` for all localnodes entities, post/group/user/topic entities were removed but 8 event nodes persisted. Entity counts showed 36 posts, 10 groups, 26 nodes instead of expected 18/5/18.
- **Fix:** Ran `ddev drush sdr localnodes_event` again, then a second pass of `ddev drush sdr` for remaining entity types (posts, groups, users, files, comments, likes, enrollments) to clean up all duplicates.
- **Files modified:** None (database operations only)
- **Verification:** Direct SQL counts confirmed all entity types at expected Boulder-only counts
- **Committed in:** N/A (no file changes)

---

**Total deviations:** 1 auto-fixed (1 bug)
**Impact on plan:** Required extra removal pass but no impact on final content quality. All entity counts verified correct after cleanup.

## Issues Encountered
- The `social_event_type` module warning appeared during both sdr and sda operations but did not affect entity creation or removal -- event types were handled by the event plugin's createContent method.
- Entity API `loadMultiple()` returned stale cached results within the same drush session after sdr; verified with direct SQL queries to confirm actual database state.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Boulder LocalNodes instance fully populated with 119 themed demo entities
- All content indexed in Search API for AI knowledge garden features (Qdrant vector search)
- Phase 06 (Demo Content) is now complete
- Platform ready for demonstration of AI-powered group assistants, semantic search, and permission-aware retrieval

## Self-Check: PASSED

SUMMARY.md verified present. Entity counts verified via direct SQL (Posts=18, Groups=5, Nodes=18). No task commits expected (operational plan with no file changes). 3/3 checks passed.

---
*Phase: 06-create-demo-content-for-localnodes-xyz-based-on-social-demo-module*
*Completed: 2026-02-28*
