Status: draft, not posted. Reported privately by the owner; details are not kept here.

Attach `patches/social-group-header-blocks-check-access.patch` from our repository.

# Group header blocks check the visitor's access to the group

## Issue Metadata

- **Title:** Group header blocks check the visitor's access to the group
- **Project:** Open Social (goalgorilla/open_social, drupal/social)
- **Version:** 13.1.x (seen on 13.1.0, Drupal core 10.6, group 2.3)
- **Component:** Code (social_group)
- **Category:** Bug report

## What the patch does

- The current-group provider checks that the current user may view a group before returning it from its last lookup step. Groups it takes from the route or from an entity are unchanged.
- The group header block is not render-cached on a page whose route carries no group.
- The provider's unit test covers a group the user may and may not view.

A group the visitor may view shows as before.
