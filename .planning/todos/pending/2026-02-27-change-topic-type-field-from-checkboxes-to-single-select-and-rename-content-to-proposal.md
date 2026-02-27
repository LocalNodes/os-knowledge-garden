---
created: 2026-02-27T12:04:52.470Z
title: Change Topic Type field from checkboxes to single select and rename Content to Proposal
area: content
files:
  - config/sync/field.storage.node.field_topic_type.yml:18
  - config/sync/field.field.node.topic.field_topic_type.yml
  - config/sync/core.entity_form_display.node.topic.default.yml:143-148
  - config/sync/taxonomy.vocabulary.topic_types.yml
---

## Problem

When creating a new Topic at `/node/add/topic`, the Type field currently allows multiple selections via checkboxes (Blog, Content, Dialog, News). This is confusing because a topic should have exactly one type. Additionally, "Blog" and "Content" seem redundant - what's the purpose of "Content" as a type?

## Solution

1. Change field storage cardinality from `-1` (unlimited) to `1` (single value) in `field.storage.node.field_topic_type.yml`
2. Consider changing widget from `options_buttons` to `options_select` for a cleaner single-select dropdown UI
3. Evaluate renaming "Content" to something more useful like "Proposal" or removing it entirely if redundant with "Blog"
4. Update any existing topics that may have multiple types assigned (data migration consideration)
