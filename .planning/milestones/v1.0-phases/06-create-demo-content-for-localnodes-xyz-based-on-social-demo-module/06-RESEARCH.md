# Phase 6: Create Demo Content for LocalNodes.xyz - Research

**Researched:** 2026-02-24
**Domain:** Open Social social_demo plugin architecture + LocalNodes.xyz content strategy
**Confidence:** HIGH

## Summary

This phase creates a custom Drupal module (`localnodes_demo`) that leverages Open Social's `social_demo` plugin architecture to generate thematic demo content for the LocalNodes.xyz bioregionalism/network nations platform. The social_demo module uses a well-defined Drupal plugin system: `@DemoContent` annotation-based plugins read YAML files from `content/entity/` directories, parse them with a YAML parser service, and create entities (users, groups, nodes, posts, comments, files, taxonomy terms, likes, event enrollments) with cross-references via UUIDs. The key architectural insight is that the `DemoContentManager` (a `DefaultPluginManager`) scans all enabled modules for `Plugin/DemoContent/` directories -- meaning a new module can register its own `@DemoContent` plugins with unique IDs that point to its own YAML files, without modifying social_demo at all.

The content strategy is informed by LocalNodes.xyz (a community-owned regenerative infrastructure platform for bioregional coordination) and "The Infrastructure of Belonging" article (which articulates a cyber-physical polycentric cosmolocal bioregional governance framework). The demo content should populate the platform with realistic personas representing watershed stewards, permaculture designers, community land trust organizers, mutual aid coordinators, and bioregional council members -- organized into groups representing bioregional hubs, project working groups, and governance councils. Content should use the vocabulary of regenerative economics, bioregionalism, polycentric governance, and cosmolocal coordination.

**Primary recommendation:** Create a standalone `localnodes_demo` module in `html/modules/custom/` with its own `@DemoContent` plugins (using unique plugin IDs like `localnodes_user`, `localnodes_group`, etc.) that extend the same base classes from social_demo (`DemoUser`, `DemoGroup`, `DemoNode`, `DemoEntity`, `DemoComment`, `DemoFile`, `DemoTaxonomyTerm`). This module depends on `social_demo` for base classes/services but keeps all content YAML and image files self-contained.

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| social_demo (base classes) | Open Social 12.x | Plugin base classes: DemoUser, DemoGroup, DemoNode, DemoEntity, DemoComment, DemoFile, DemoTaxonomyTerm | Provides all entity creation logic, UUID cross-referencing, group membership, image cropping, and the `@DemoContent` annotation system |
| Symfony YAML | Bundled with Drupal 10 | YAML parsing for content entity files | Used by DemoContentParser service to load content definitions |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| Drush | 12.x+ | `social-demo:add` / `social-demo:remove` commands | To create and remove demo content via CLI |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| New module with own plugins | Modify social_demo YAML directly | Would be lost on updates; not portable; breaks separation of concerns |
| New module with own plugins | Override plugins via hook_alter | More complex, harder to maintain, same content gets mixed |
| Profile-based approach (--profile flag) | The drush command supports `--profile` which prepends a subdirectory path | Only works within the same module's directory; cannot define new plugin IDs; limited to overriding existing content types |

**Installation:**
```bash
# No composer install needed -- all dependencies are already in the Open Social profile
# The module just needs to be placed and enabled:
ddev drush en localnodes_demo
```

## Architecture Patterns

### Recommended Module Structure
```
html/modules/custom/localnodes_demo/
  localnodes_demo.info.yml           # Module definition, depends on social_demo
  localnodes_demo.services.yml       # No services needed (reuses social_demo's)
  src/
    Plugin/
      DemoContent/
        LocalnodesUser.php           # @DemoContent plugin for users
        LocalnodesUserTerms.php      # @DemoContent plugin for taxonomy terms
        LocalnodesFile.php           # @DemoContent plugin for files
        LocalnodesGroup.php          # @DemoContent plugin for groups
        LocalnodesEventType.php      # @DemoContent plugin for event type terms
        LocalnodesEvent.php          # @DemoContent plugin for events
        LocalnodesTopic.php          # @DemoContent plugin for topics
        LocalnodesPost.php           # @DemoContent plugin for posts
        LocalnodesComment.php        # @DemoContent plugin for comments
        LocalnodesLike.php           # @DemoContent plugin for likes
        LocalnodesEventEnrollment.php # @DemoContent plugin for event RSVPs
  content/
    entity/
      user.yml                       # LocalNodes user personas
      user-terms.yml                 # Expertise/interest taxonomy terms
      file.yml                       # Image file definitions with crops
      group.yml                      # Bioregional groups
      event-type.yml                 # Event type taxonomy terms
      event.yml                      # Community events
      topic.yml                      # Topics/articles (Blog, News, Dialog)
      post.yml                       # Social posts
      comment.yml                    # Comments on content
      like.yml                       # Likes/reactions
      event-enrollment.yml           # Event RSVPs
    files/
      01.jpg ... NN.jpg              # Generic content images (can reuse social_demo's)
      persona1.jpg ... personaN.jpg  # Profile photos for demo users
```

### Pattern 1: DemoContent Plugin Registration
**What:** Each content type gets a thin PHP plugin class with a `@DemoContent` annotation that declares its plugin ID, label, YAML source file, and entity type. The class extends the appropriate social_demo base class.
**When to use:** For every entity type that needs demo content.
**Example:**
```php
// Source: social_demo/src/Plugin/DemoContent/User.php pattern
<?php

namespace Drupal\localnodes_demo\Plugin\DemoContent;

use Drupal\social_demo\DemoUser;

/**
 * LocalNodes User Plugin for demo content.
 *
 * @DemoContent(
 *   id = "localnodes_user",
 *   label = @Translation("LocalNodes User"),
 *   source = "content/entity/user.yml",
 *   entity_type = "user"
 * )
 */
class LocalnodesUser extends DemoUser {

}
```

### Pattern 2: YAML Content with UUID Cross-References
**What:** Content entities are defined in YAML files keyed by UUID. Entities reference each other via UUIDs (e.g., a post's `uid` references a user UUID, a post's `group` references a group UUID). The UUID must appear both as the YAML key and as the `uuid` field value.
**When to use:** All entity YAML files.
**Example:**
```yaml
# Source: social_demo content/entity/post.yml pattern
a1b2c3d4-e5f6-7890-abcd-ef1234567890:
  uuid: a1b2c3d4-e5f6-7890-abcd-ef1234567890
  langcode: en
  type: post
  uid: <user-uuid-here>
  field_visibility: 1
  created: -3 day|14:30
  group: <group-uuid-here>
  field_post: >
    Just returned from the watershed mapping session...
```

### Pattern 3: Entity Creation Order (Dependencies)
**What:** Entities must be created in dependency order because plugins resolve UUID references at creation time. The order is: user_terms -> files -> users -> groups -> event_types -> events -> topics -> posts -> comments -> likes -> event_enrollments.
**When to use:** When invoking the drush command.
**Example:**
```bash
# Create in dependency order
ddev drush sda localnodes_user_terms localnodes_file localnodes_user localnodes_group localnodes_event_type localnodes_event localnodes_topic localnodes_post localnodes_comment localnodes_like localnodes_event_enrollment
```

### Pattern 4: Date Format Convention
**What:** Dates use a relative format: `[+-]N day|HH:MM` (e.g., `-3 day|14:30` = 3 days ago at 2:30pm, `+10 day|08:00` = 10 days from now). The `now` keyword creates content at current time.
**When to use:** All `created` and date fields.

### Pattern 5: Visibility Values
**What:** Content visibility is controlled by numeric values for posts (`field_visibility`: 0=recipient, 1=community, 2=public, 3=group) and string values for nodes/groups (`field_content_visibility`: `public`, `community`, `group`).
**When to use:** All posts, topics, events, and group definitions.

### Anti-Patterns to Avoid
- **Reusing existing social_demo UUIDs:** Every UUID in localnodes_demo MUST be unique and not overlap with social_demo UUIDs. The system checks for UUID existence before creating and will skip duplicates silently.
- **Creating content before dependencies exist:** If a post references a user UUID that hasn't been created, the plugin logs an error and skips that entry. Always create in dependency order.
- **Modifying social_demo files directly:** These are in `profiles/contrib/` and will be overwritten on updates. Always use a separate module.
- **Using numeric IDs instead of UUIDs for references:** The system is UUID-based. Entity IDs are resolved from UUIDs at creation time.
- **Inconsistent UUID key/value:** The YAML key MUST match the `uuid` field value exactly, or the entry is skipped with an error log.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Entity creation logic | Custom entity creation code | social_demo base classes (DemoUser, DemoGroup, DemoNode, etc.) | These handle UUID resolution, group membership, profile filling, image cropping, follow creation, comment threading, and dozens of edge cases |
| YAML parsing | Custom YAML parser | `social_demo.yaml_parser` service (DemoContentParser) | Already handles module-relative paths and profile subdirectories |
| Plugin discovery | Custom plugin system | `plugin.manager.demo_content` service (DemoContentManager) | DefaultPluginManager with annotation discovery -- just register plugins in the right namespace |
| Group content relationship | Custom group_content creation | DemoNode::createGroupRelationship() | Handles the complex group_content_type resolution and GroupRelationship creation |
| User profile creation | Manual profile entity creation | DemoUser::fillProfile() | Handles the auto-created profile pattern Open Social uses |
| Image cropping | Manual crop entity management | DemoFile::applyCrops() + crop data in YAML | Integrates with image_widget_crop module correctly |
| Content removal | Custom deletion logic | DemoContent::removeContent() | Handles loading by UUID and clean deletion |

**Key insight:** The social_demo base classes handle all the complex entity relationships and Drupal API interactions. The custom module only needs thin plugin subclasses and YAML data files. Zero custom PHP logic required beyond the plugin annotations.

## Common Pitfalls

### Pitfall 1: UUID Collisions with Existing social_demo Content
**What goes wrong:** If you reuse UUIDs from social_demo's content files, the system will skip your entities with a "already exists" warning, and you'll have partial content creation with broken references.
**Why it happens:** The creation code checks for UUID existence before creating.
**How to avoid:** Generate completely fresh UUIDs (v4) for ALL entities in localnodes_demo. Use a UUID generator tool. Never copy-paste UUIDs from social_demo.
**Warning signs:** "already exists" warnings in drush output; missing entities after creation.

### Pitfall 2: Wrong Entity Creation Order
**What goes wrong:** Posts reference users and groups. If users/groups haven't been created yet, posts are silently skipped with error logs.
**Why it happens:** UUID lookups fail when referenced entities don't exist yet.
**How to avoid:** Always create in strict dependency order: terms -> files -> users -> groups -> event_types -> events -> topics -> posts -> comments -> likes -> event_enrollments.
**Warning signs:** "doesn't exists" errors in drush output; entity counts lower than expected.

### Pitfall 3: Group Type Mismatch
**What goes wrong:** Groups must use `type: flexible_group` (Open Social's standard group bundle). Using a wrong type causes entity creation failures.
**Why it happens:** Open Social has specific group types with specific field configurations.
**How to avoid:** Always use `type: flexible_group` and ensure the required fields (`field_flexible_group_visibility`, `field_group_allowed_join_method`, `field_group_allowed_visibility`) are present.
**Warning signs:** EntityStorageException errors during group creation.

### Pitfall 4: Missing Required Fields in YAML
**What goes wrong:** The base classes expect certain fields to exist in the YAML data. Missing fields cause PHP errors or null reference exceptions.
**Why it happens:** Each entity type has specific required fields that the getEntry() method accesses.
**How to avoid:** Study the existing social_demo YAML files and match the exact field structure. Keep the same field names.
**Warning signs:** PHP notices/warnings about undefined array keys.

### Pitfall 5: Image Files Not Found
**What goes wrong:** Files referenced in file.yml must physically exist at the path specified. Missing files cause copy failures.
**Why it happens:** DemoFile copies files from the module directory to public://.
**How to avoid:** Either include placeholder images in `content/files/` or reference existing social_demo images by reusing their UUIDs (though this creates a dependency on social_demo content being loaded first).
**Warning signs:** File copy errors in drush output.

### Pitfall 6: Comment field_name Must Match Entity Type
**What goes wrong:** Comments have a `field_name` property that must match the comment field on the parent entity: `field_topic_comments` for topics, `field_event_comments` for events, `field_post_comments` for posts.
**Why it happens:** Drupal's comment system requires knowing which comment field on the parent entity owns the comment.
**How to avoid:** Use `field_topic_comments` for topic comments, `field_event_comments` for event comments, `field_post_comments` for post comments. The `type` field must also match: `comment` for node comments, `post_comment` for post comments.
**Warning signs:** Comments created but not displayed on their parent entity.

### Pitfall 7: Topic Type Must Be Existing Taxonomy Term Name
**What goes wrong:** Topics have `field_topic_type` which loads terms by name (not UUID). The term must already exist in the vocabulary.
**Why it happens:** The Topic plugin's `prepareTopicType()` method uses `loadByProperties(['name' => $name])`.
**How to avoid:** Use only existing topic types: `Blog`, `News`, `Dialog`. These are created during Open Social installation. Do NOT put UUIDs here -- use the term name string.
**Warning signs:** Topics created without a topic type tag.

### Pitfall 8: social_demo Module Must Be Enabled
**What goes wrong:** The base classes, services, and plugin manager all live in social_demo. If it's not enabled, the localnodes_demo module cannot function.
**Why it happens:** PHP class autoloading and service container depend on module being enabled.
**How to avoid:** Declare `social_demo` as a dependency in `localnodes_demo.info.yml`.
**Warning signs:** Class not found errors, service not found errors.

## Code Examples

### Module Info File
```yaml
# localnodes_demo.info.yml
name: 'LocalNodes Demo Content'
type: module
description: 'Provides demo content themed for the LocalNodes.xyz bioregional community platform.'
core_version_requirement: ^9 || ^10
package: LocalNodes
dependencies:
  - social_demo:social_demo
```

### Plugin Class Example (User)
```php
<?php
// Source: Pattern from social_demo/src/Plugin/DemoContent/User.php
namespace Drupal\localnodes_demo\Plugin\DemoContent;

use Drupal\social_demo\DemoUser;

/**
 * LocalNodes User Plugin for demo content.
 *
 * @DemoContent(
 *   id = "localnodes_user",
 *   label = @Translation("LocalNodes User"),
 *   source = "content/entity/user.yml",
 *   entity_type = "user"
 * )
 */
class LocalnodesUser extends DemoUser {

}
```

### Plugin Class Example (Group)
```php
<?php
namespace Drupal\localnodes_demo\Plugin\DemoContent;

use Drupal\social_demo\DemoGroup;

/**
 * LocalNodes Group Plugin for demo content.
 *
 * @DemoContent(
 *   id = "localnodes_group",
 *   label = @Translation("LocalNodes Group"),
 *   source = "content/entity/group.yml",
 *   entity_type = "group"
 * )
 */
class LocalnodesGroup extends DemoGroup {

}
```

### Plugin Class Example (Topic)
```php
<?php
namespace Drupal\localnodes_demo\Plugin\DemoContent;

use Drupal\social_demo\DemoNode;

/**
 * LocalNodes Topic Plugin for demo content.
 *
 * @DemoContent(
 *   id = "localnodes_topic",
 *   label = @Translation("LocalNodes Topic"),
 *   source = "content/entity/topic.yml",
 *   entity_type = "node"
 * )
 */
class LocalnodesTopic extends \Drupal\social_demo\Plugin\DemoContent\Topic {

}
```

### Plugin Class Example (Post)
```php
<?php
namespace Drupal\localnodes_demo\Plugin\DemoContent;

use Drupal\social_demo\Plugin\DemoContent\Post as SocialDemoPost;

/**
 * LocalNodes Post Plugin for demo content.
 *
 * @DemoContent(
 *   id = "localnodes_post",
 *   label = @Translation("LocalNodes Post"),
 *   source = "content/entity/post.yml",
 *   entity_type = "post"
 * )
 */
class LocalnodesPost extends SocialDemoPost {

}
```

### User YAML Example
```yaml
# content/entity/user.yml
# Note: All UUIDs must be freshly generated v4 UUIDs
f47ac10b-58cc-4372-a567-0e02b2c3d479:
  uuid: f47ac10b-58cc-4372-a567-0e02b2c3d479
  created: -30 day|10:00
  name: riverkeeper_maria
  mail: maria.watershed@localnodes.example
  timezone: America/Los_Angeles
  status: 1
  image: <file-uuid>
  image_alt: Maria Chen
  first_name: Maria
  last_name: Chen
  organization: Salish Sea Bioregional Collective
  function: Watershed Steward
  phone_number:
  expertise:
    - <term-uuid-for-watershed-ecology>
    - <term-uuid-for-bioregionalism>
  interests:
    - <term-uuid-for-regenerative-agriculture>
  address: Portland, OR
  self_introduction: >
    I coordinate watershed monitoring and restoration projects across the Salish Sea bioregion. I believe in polycentric governance...
```

### Group YAML Example
```yaml
# content/entity/group.yml
b2c3d479-58cc-4372-a567-f47ac10b0e02:
  uuid: b2c3d479-58cc-4372-a567-f47ac10b0e02
  langcode: en
  image: <file-uuid>
  image_alt: Cascadia bioregion landscape
  uid: <user-uuid>
  created: -60 day|09:00
  label: Cascadia Bioregional Hub
  type: flexible_group
  field_flexible_group_visibility: community
  field_group_allowed_join_method: direct
  field_group_allowed_visibility:
    - community
    - group
  description: >
    <p>A hub for coordinating regenerative projects across the Cascadia bioregion. From watershed restoration to community land trusts, this is where we share knowledge, coordinate resources, and practice polycentric governance at the bioregional scale.</p>
  members:
    - <user-uuid-1>
    - <user-uuid-2>
    - <user-uuid-3>
```

### Drush Usage
```bash
# Remove old social_demo content first (optional)
ddev drush sdr like event_enrollment comment post event topic group user file user_terms

# Create LocalNodes demo content in dependency order
ddev drush sda localnodes_user_terms localnodes_file localnodes_user localnodes_group localnodes_event_type localnodes_event localnodes_topic localnodes_post localnodes_comment localnodes_like localnodes_event_enrollment
```

## Content Strategy

### Target Audience / Community Context

LocalNodes.xyz serves communities practicing **bioregional coordination** -- organizing governance and resource management around living systems (watersheds, ecosystems) rather than arbitrary political boundaries. Informed by "The Infrastructure of Belonging" article, the demo content should reflect:

- **Polycentric governance**: Multiple overlapping decision-making centers, each suited to its scale
- **Cosmolocal coordination**: Global knowledge sharing with local implementation
- **Regenerative economics**: Community-owned infrastructure that creates value locally
- **Cyber-physical infrastructure**: Digital coordination tools serving physical place-based communities

### Demo User Personas (10-12 users)

| Persona | Role | Organization | Expertise |
|---------|------|--------------|-----------|
| Maria Chen | Watershed Steward | Salish Sea Bioregional Collective | Watershed ecology, bioregionalism |
| Kwame Asante | Community Land Trust Director | Cascadia Commons Trust | Land stewardship, cooperative governance |
| Juniper Walsh | Permaculture Designer | Rewild Cascadia | Regenerative agriculture, food forests |
| Anil Patel | Solar Cooperative Coordinator | Sunroot Energy Collective | Community energy, mutual aid |
| Suki Yamamoto | Bioregional Council Facilitator | Pacific Rim Governance Circle | Facilitation, polycentric governance |
| River Thompson | Youth Climate Organizer | Next Gen Bioregion | Climate action, youth engagement |
| Elena Vasquez | Mutual Aid Network Lead | Solidarity Cascadia | Mutual aid, community care |
| Omar Hassan | Data Steward / Platform Admin | LocalNodes Core Team | Data sovereignty, digital infrastructure |
| Willow Redcedar | Indigenous Land Relations | Coast Salish Relations | Traditional ecological knowledge, indigenous sovereignty |
| Sage Moreno | Urban Farmer | City Roots Collective | Urban agriculture, food sovereignty |
| Noah Eriksen | Watershed Scientist | Cascadia Water Institute | Hydrology, ecological monitoring |
| Site Manager (localnodes_admin) | Site Manager role | LocalNodes Platform | Platform management |

### Demo Groups (5 groups)

| Group | Type | Visibility | Purpose |
|-------|------|-----------|---------|
| Cascadia Bioregional Hub | flexible_group | community/direct | Main bioregional coordination hub |
| Watershed Guardians Collective | flexible_group | community/direct | Watershed monitoring and restoration |
| Cascadia Commons Land Trust | flexible_group | community/direct | Community land stewardship |
| Regenerative Economics Working Group | flexible_group | community/direct | Resource pooling, mutual aid |
| Cascadia Governance Council | flexible_group | community/added (closed) | Governance decision-making (group-only visibility) |

### Demo Topics (8-10 topics, mix of Blog/News/Dialog)

- "Welcome to LocalNodes: Building the Infrastructure of Belonging" (Blog, public)
- "What is Bioregionalism? A Living Systems Approach to Governance" (Blog, community)
- "Watershed Restoration Progress: Q1 Update" (News, community, Watershed group)
- "Proposal: Community Solar Installation at the Commons" (Dialog, community, Land Trust group)
- "Cosmolocal Coordination: Sharing Knowledge, Acting Locally" (Blog, community, Bioregional Hub)
- "Polycentric Governance in Practice: Lessons from Our First Year" (Blog, community)
- "Food Forest Design Workshop Follow-up" (News, community, Watershed group)
- "How to Contribute to the LocalNodes Platform" (Blog, public)
- "Treasury Allocation Discussion for Q2" (Dialog, group, Governance Council)
- "Mapping Our Bioregion: Participatory GIS Project" (Dialog, community, Bioregional Hub)

### Demo Events (6-8 events)

- "Cascadia Bioregional Assembly" (future, community, Bioregional Hub)
- "Watershed Monitoring Training" (future, public)
- "Community Land Trust Open House" (future, public, Land Trust group)
- "Monthly Governance Circle" (past, community, Governance Council)
- "Seed Swap and Plant Exchange" (future, community)
- "Regenerative Economics Workshop" (future, community, Economics Working Group)
- "Solstice Gathering: Celebrating Place" (future, public)
- "Data Sovereignty Workshop" (past, community, Bioregional Hub)

### Demo Posts (15-18 social posts)

Mix of:
- Welcome messages and community building
- Resource sharing and coordination
- Event announcements and follow-ups
- Questions and mutual aid requests
- Cross-group updates
- Some with images, some with group context

### Demo Comments, Likes, Event Enrollments

- Comments should show natural conversation threads (replies, engagement)
- At least 10-15 comments across topics and events
- Likes on popular topics and posts
- Event enrollments for upcoming events

### Vocabulary and Tone

**Use these terms naturally in content:**
- Bioregion, bioregionalism, bioregional
- Watershed, ecosystem, living systems
- Polycentric governance, distributed authority
- Cosmolocal (global knowledge, local action)
- Commons, commoning, shared stewardship
- Regenerative, restoration, resilience
- Mutual aid, solidarity, reciprocity
- Sovereignty (data, land, food, energy)
- Place-based, rooted, grounded
- Permissionless, credibly neutral
- Infrastructure of belonging

**Tone:** Warm, practical, community-oriented. Not academic or jargon-heavy. These are real people coordinating real projects. Content should feel like authentic community platform usage, not marketing copy.

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Hardcoded demo content in install profile | Plugin-based YAML demo content system | Open Social 8.x era | Modular, extensible, removable demo content |
| group_content entity type | GroupRelationship entity type | Group module 2.x/3.x | The DemoNode class already uses GroupRelationship |

**Deprecated/outdated:**
- The `--profile` flag on drush commands is designed for sub-profiles within the same module, not for cross-module content overriding. Creating a new module with its own plugins is the correct approach.

## Open Questions

1. **Profile Images**
   - What we know: social_demo includes 500x500 JPEG profile photos. The DemoFile system copies them from module directory to public://.
   - What's unclear: Whether we should include actual profile images or use placeholders. Stock photos with appropriate creative commons licenses would be ideal.
   - Recommendation: Include simple placeholder images (solid color or generic avatars) initially. These can be replaced with better images later. Alternatively, reuse social_demo's existing profile photos by referencing their UUIDs (requires social_demo content to be loaded first).

2. **Content Images**
   - What we know: social_demo includes 24 generic content images (numbered 01.jpg through 24.jpg) for topics, events, groups, and posts.
   - What's unclear: Whether we should create nature/landscape themed images for bioregional content.
   - Recommendation: Reuse social_demo's generic content images initially by referencing their file UUIDs (simpler approach, fewer files to manage). The images are generic enough (people at tables, nature, etc.) to work for any community context.

3. **Coexistence with social_demo Content**
   - What we know: localnodes_demo plugins have unique IDs, so they don't conflict with social_demo plugins. However, both sets of content would coexist in the platform.
   - What's unclear: Whether the user wants to remove social_demo content before loading localnodes_demo content, or have both.
   - Recommendation: Provide clear instructions for both approaches. Default recommendation: remove social_demo content first (`drush sdr ...`), then load localnodes_demo content. This gives a clean, themed experience.

## Sources

### Primary (HIGH confidence)
- social_demo module source code -- Full codebase analysis of all PHP classes, YAML files, annotations, services, and Drush commands (direct file reads from local project)
- DemoContentManager.php -- Plugin discovery mechanism using DefaultPluginManager with 'Plugin/DemoContent' subdir scanning across all modules
- DemoContentParser.php -- YAML file resolution logic: `module_path / profile / source_file`

### Secondary (MEDIUM confidence)
- https://www.localnodes.xyz/ -- WebFetch of project homepage for mission, audience, and aesthetic context
- https://omniharmonic.substack.com/p/the-infrastructure-of-belonging -- WebFetch of article for thematic vocabulary, concepts, and community personas

### Tertiary (LOW confidence)
- None. All findings are based on direct source code analysis and primary web sources.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - Direct source code analysis of all plugin classes, base classes, and YAML structures
- Architecture: HIGH - Complete understanding of Drupal plugin system used, verified by reading every file in the module
- Content strategy: MEDIUM - Based on WebFetch of LocalNodes.xyz and the article; the specific personas and content are recommendations that may need user refinement
- Pitfalls: HIGH - Identified directly from source code analysis of error handling paths and entity creation logic

**Research date:** 2026-02-24
**Valid until:** 2026-06-24 (stable architecture, unlikely to change)
