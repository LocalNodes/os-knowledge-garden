Status: draft, not posted

Before posting, search the Open Social issue queue for an existing report ("private message recipient missing", "massageFormValues target_id", "SocialPrivateMessageEntityAutocomplete") and add to that one if it exists. Attach `patches/social-private-message-keep-recipients.patch` from our repository.

# drupal.org Issue: A new private message is saved without its recipient

## Issue Metadata

- **Title:** A new private message is saved without its recipient
- **Project:** Open Social (goalgorilla/open_social, drupal/social)
- **Version:** 13.1.x (seen on 13.1.0 with private_message 3.0.7)
- **Component:** Code (social_private_message)
- **Category:** Bug report
- **Priority:** Major
- **Status:** Active

## Problem/Motivation

On `/private-message/create`, the recipient field is a `social_private_message_entity_autocomplete` element. Its `validateEntityAutocomplete()` runs the parent validation, then replaces the whole `members` form value with its inner `target_id` list:

```php
$value = $form_state->getValue('members');
$form_state->setValue('members', $value['target_id']);
```

private_message 3.x's `PrivateMessageThreadMemberWidget::massageFormValues()` reads `$values['target_id']`. After the flattening that key is gone, so PHP logs `Warning: Undefined array key "target_id"` and the widget returns `NULL`. The thread is then built from the sender alone: the message is saved, the sender is sent to their inbox, and the recipient never sees it.

Replies on an existing conversation page are not affected; they reuse the thread's members.

### Steps to reproduce

1. Sign in as member A.
2. Open `/private-message/create?recipient=<uid of B>` (or type B's name in the recipient field) and send a message.
3. The log shows `Undefined array key "target_id" in PrivateMessageThreadMemberWidget->massageFormValues()`.
4. In `private_message_thread__members`, the new thread has only A as a member. B's inbox does not show it.

## Proposed resolution

Remove the override, so the element keeps the value shape the core entity autocomplete gives and private_message's widget expects (`members[target_id]` is a list of `['target_id' => …]` items). Nothing else in Open Social reads the flattened value.

The patch is in this issue. With it, the same steps give a thread with both A and B as members, B sees the message, and no warning is logged.

## Remaining tasks

- Review.
- Check whether an older private_message version needed the flattening, and whether Open Social still supports that version.

## AI disclosure

This report and patch were prepared with the help of an AI coding assistant and checked on a running site before posting.
