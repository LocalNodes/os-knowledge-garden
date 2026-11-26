Status: draft, not posted. Report privately, by the owner; details are not kept here.

Attach `patches/social-group-choice-checks-access.patch` from our repository.

# The group choice on a content form checks the visitor's access to the group

## Issue Metadata

- **Title:** The group choice on a content form checks the visitor's access to the group
- **Project:** Open Social (goalgorilla/open_social, drupal/social)
- **Version:** 13.1.x (seen on 13.1.0, Drupal core 10.6, group 2.3)
- **Component:** Code (social_group)
- **Category:** Bug report

## What the patch does

- When someone chooses a group on a content form, the form uses that group only if the person may view it. A group the person may not view is treated the same as a group that does not exist.
- A group the person may view behaves as before.
