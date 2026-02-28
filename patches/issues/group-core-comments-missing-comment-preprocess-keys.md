# drupal.org Issue: CommentGroupContentFormatter missing #comment_type and #comment_display_mode keys

## Issue Metadata

- **Title:** CommentGroupContentFormatter loses #comment_type and #comment_display_mode keys, causing PHP warnings
- **Project:** Open Social (goalgorilla/open_social)
- **Component:** group_core_comments
- **Category:** Bug report
- **Priority:** Normal
- **Status:** Active
- **Version:** 13.0.0

## Issue Summary

### Problem/Motivation

When viewing group content nodes (events, topics) that use the `comment_group_content` field formatter, PHP warnings are thrown:

```
Warning: Undefined array key "#comment_display_mode" in comment_preprocess_field() (line 720 of core/modules/comment/comment.module).
Warning: Undefined array key "#comment_type" in comment_preprocess_field() (line 721 of core/modules/comment/comment.module).
```

The root cause is in `CommentGroupContentFormatter::viewElements()`. The parent class `CommentDefaultFormatter::viewElements()` sets `#comment_type` and `#comment_display_mode` on `$output[0]`, but the child class has multiple code paths that destroy or recreate `$output[0]` without preserving these keys:

1. **Line 240:** `unset($output[0])` followed by `$output[0]['comments'] = [...]` — this destroys the entire element and creates a new one with only a `comments` key.

2. **Lines 248-264:** Replaces `$output[0]['comments']` with a lazy builder array, which is fine on its own but doesn't restore keys if they were already lost.

3. **Lines 279-282:** Sets `$output[0]['comment_form']` for anonymous users, which also doesn't restore missing keys.

Core's `comment_preprocess_field()` unconditionally reads `$element[0]['#comment_display_mode']` and `$element[0]['#comment_type']` for any comment field, so these keys must always be present.

#### Steps to reproduce

1. Install Open Social 13.0.0
2. Create a group and add a node (event or topic) to it
3. View the node as an authenticated user who is a member of the group
4. Observe PHP warnings in the error log about undefined array keys

### Proposed resolution

1. Save `#comment_type` and `#comment_display_mode` from `$output[0]` before any modifications
2. After all modifications, use `$output[0] += [...]` to restore any missing keys

This ensures the keys survive all code paths (unset/recreate, access denied, anonymous user, lazy builder replacement).

### Remaining tasks

- Review and commit

### User interface changes

None.

### API changes

None.

### Data model changes

None.
