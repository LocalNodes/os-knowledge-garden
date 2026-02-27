---
created: 2026-02-27T12:04:52.470Z
title: Change Topic Type field to single select and update taxonomy terms
area: content
files:
  - config/sync/field.storage.node.field_topic_type.yml:18
  - config/sync/field.field.node.topic.field_topic_type.yml
  - config/sync/core.entity_form_display.node.topic.default.yml:143-148
  - config/sync/taxonomy.vocabulary.topic_types.yml
---

## Problem

When creating a new Topic at `/node/add/topic`, the Type field currently allows multiple selections via checkboxes (Blog, Content, Dialog, News). This is confusing because a topic should have exactly one type. Additionally, "Content" is a non-category that doesn't help users classify their posts.

## Solution

**Field changes:**
1. Change field storage cardinality from `-1` (unlimited) to `1` (single value) in `field.storage.node.field_topic_type.yml`
2. Consider changing widget from `options_buttons` to `options_select` for a cleaner single-select dropdown UI

**Taxonomy term changes:**
- **Remove:** Content (non-category)
- **Add:** Question — for seeking help, knowledge, advice
- **Add:** Resource — for sharing tools, links, references, toolkits
- **Keep:** Blog, Dialog, News

**Final topic types:**
| Type | Purpose |
|------|---------|
| Blog | Long-form storytelling, reflections |
| Dialog | Proposals, structured conversations, coordination |
| News | Announcements, updates |
| Question | Seeking help, knowledge, advice |
| Resource | Sharing tools, links, references |

**Data migration:**
- Update any existing topics that have multiple types assigned (pick primary type)
- Reassign any topics using "Content" type to appropriate new type
