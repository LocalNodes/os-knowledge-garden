<?php

/**
 * @file
 * Seeds the fictional a11y personas and their group (Phase 22, plan 22-11).
 *
 * Run with: ddev drush php:script tests/a11y/seed.php
 *
 * Idempotent: rerunning finds the existing users, group and memberships and
 * only fills in anything missing. It never deletes or renames anything; the
 * one thing it resets is the footer group's location and address, which a test
 * adds and takes off again. The users and the groups are fictional fixtures
 * that exist only on the local DDEV site.
 *
 * Also seeds "A11y Cover Garden" (a generated cover picture) and "A11y Footer
 * Garden" (no picture, location or address), each with a description for the
 * header's one-line summary, both with the steward as
 * manager and no other members, so the member's own group lists stay as they
 * were.
 *
 * Also seeds two fictional events in "A11y Garden" for the event cards, one
 * with the generated cover picture and one with a long title and no picture.
 *
 * Prints machine-readable lines, "LN_GROUP=<id>", "LN_COVER_GROUP=<id>",
 * "LN_FOOTER_GROUP=<id>", "LN_EVENT=<nid>" and "LN_EVENT_LONG=<nid>", which
 * tests/a11y/global-setup.ts reads.
 */

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\group\Entity\Group;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;

const A11Y_GROUP_LABEL = 'A11y Garden';
const A11Y_GROUP_TYPE = 'flexible_group';
const A11Y_MANAGER_ROLE = 'flexible_group-group_manager';

/**
 * Loads a fixture user by name, creating it if needed.
 */
function a11y_seed_user(string $name, string $first, string $last): User {
  $existing = \Drupal::entityTypeManager()
    ->getStorage('user')
    ->loadByProperties(['name' => $name]);
  $account = $existing ? reset($existing) : NULL;

  if (!$account) {
    $account = User::create([
      'name' => $name,
      'mail' => $name . '@example.com',
      'pass' => \Drupal::service('password_generator')->generate(32),
      'status' => 1,
    ]);
  }
  if (!$account->isActive()) {
    $account->activate();
  }
  // Open Social gives confirmed members the "verified" role; personas should
  // see the site the way a real member does.
  if (!$account->hasRole('verified')) {
    $account->addRole('verified');
  }
  $account->save();

  // Fictional display names on the Open Social profile, when it exists.
  $profiles = \Drupal::entityTypeManager()
    ->getStorage('profile')
    ->loadByProperties(['uid' => $account->id(), 'type' => 'profile']);
  $profile = $profiles ? reset($profiles) : NULL;
  if ($profile && $profile->hasField('field_profile_first_name')) {
    if ($profile->get('field_profile_first_name')->value !== $first
      || $profile->get('field_profile_last_name')->value !== $last) {
      $profile->set('field_profile_first_name', $first);
      $profile->set('field_profile_last_name', $last);
      $profile->save();
    }
  }

  return $account;
}

$steward = a11y_seed_user('a11y-steward', 'Juniper', 'Steward');
$member = a11y_seed_user('a11y-member', 'Rowan', 'Member');

/**
 * Loads a fixture group by label, creating it if needed, with the steward as
 * its manager and, when given, the member as a plain member.
 */
function a11y_seed_group(string $label, User $steward, ?User $member = NULL): Group {
  $groups = \Drupal::entityTypeManager()
    ->getStorage('group')
    ->loadByProperties(['type' => A11Y_GROUP_TYPE, 'label' => $label]);
  // Oldest first, so a rerun always settles on the same group.
  ksort($groups);
  $group = $groups ? reset($groups) : NULL;

  if (!$group) {
    $group = Group::create([
      'type' => A11Y_GROUP_TYPE,
      'label' => $label,
      'uid' => $steward->id(),
      'field_flexible_group_visibility' => 'community',
      'field_group_allowed_visibility' => ['public', 'community', 'group'],
      'field_group_allowed_join_method' => ['direct'],
    ]);
    // The group type grants the creator a group_manager membership on save.
    $group->save();
  }

  // Steward: group manager. Ensure even if the creator membership was skipped.
  $steward_membership = $group->getMember($steward);
  if (!$steward_membership) {
    $group->addMember($steward, ['group_roles' => [A11Y_MANAGER_ROLE]]);
  }
  elseif (!array_key_exists(A11Y_MANAGER_ROLE, $steward_membership->getRoles(FALSE))) {
    $steward_membership->addRole(A11Y_MANAGER_ROLE);
  }

  // Member: a plain member, no extra group roles.
  if ($member && !$group->getMember($member)) {
    $group->addMember($member);
  }

  return $group;
}

/**
 * A generated cover picture, saved once as a permanent file.
 *
 * Teal from the palette's primary with a lighter band, so a focus ring drawn
 * on the photo instead of inside a button's fill has nothing to stand out on.
 */
function a11y_seed_cover_file(): int {
  $uri = 'public://a11y-fixtures/a11y-cover.png';
  $files = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $uri]);
  if ($files && file_exists($uri)) {
    return (int) reset($files)->id();
  }
  $image = imagecreatetruecolor(1200, 400);
  imagefill($image, 0, 0, imagecolorallocate($image, 0x0f, 0x76, 0x6e));
  imagefilledrectangle($image, 0, 260, 1199, 399, imagecolorallocate($image, 0x5e, 0xa8, 0x9f));
  ob_start();
  imagepng($image);
  $png = ob_get_clean();
  $directory = 'public://a11y-fixtures';
  \Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
  $file = $files ? reset($files) : NULL;
  if ($file) {
    file_put_contents($uri, $png);
  }
  else {
    $file = \Drupal::service('file.repository')->writeData($png, $uri, FileExists::Replace);
  }
  $file->setPermanent();
  $file->save();
  return (int) $file->id();
}

/**
 * Sets a fixture group's description, for the header's one-line summary.
 */
function a11y_seed_description(Group $group, string $html): void {
  if ($group->get('field_group_description')->value !== $html) {
    $group->set('field_group_description', ['value' => $html, 'format' => 'basic_html']);
    $group->save();
  }
}

$group = a11y_seed_group(A11Y_GROUP_LABEL, $steward, $member);

// A group with a cover picture, for the controls drawn over a photo.
$cover = a11y_seed_group('A11y Cover Garden', $steward);
$fid = a11y_seed_cover_file();
if ((int) $cover->get('field_group_image')->target_id !== $fid) {
  $cover->set('field_group_image', ['target_id' => $fid, 'alt' => 'A teal field']);
  $cover->save();
}
a11y_seed_description($cover, '<p>Seed swaps, shared tools and work days by the south fence.</p><p>Second paragraph.</p>');

// A group with no picture, location or address, for the header footer test;
// a test that adds a location takes it off again, and a rerun clears it.
$footer = a11y_seed_group('A11y Footer Garden', $steward);
$dirty = FALSE;
foreach (['field_group_image', 'field_group_location', 'field_group_address'] as $field) {
  if (!$footer->get($field)->isEmpty()) {
    $footer->set($field, NULL);
    $dirty = TRUE;
  }
}
if ($dirty) {
  $footer->save();
}
a11y_seed_description($footer, '<p>&nbsp;</p><p>Raised beds &amp; a rain barrel, open to every neighbor on the block who wants a plot this spring and summer, with work days on the first Saturday of each month and a shared tool shed.</p>');

print 'LN_GROUP=' . $group->id() . PHP_EOL;
print 'LN_COVER_GROUP=' . $cover->id() . PHP_EOL;
print 'LN_FOOTER_GROUP=' . $footer->id() . PHP_EOL;

/**
 * Loads a fixture event in the group by title, creating it if needed.
 *
 * The start is stored at 12:00 UTC, which is the same calendar day in every
 * timezone a person can pick, so the card's date tile is predictable.
 */
function a11y_seed_event(Group $group, User $owner, string $title, string $start, ?int $fid): Node {
  $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['type' => 'event', 'title' => $title]);
  ksort($nodes);
  $event = $nodes ? reset($nodes) : NULL;
  if (!$event) {
    $event = Node::create(['type' => 'event', 'title' => $title, 'uid' => $owner->id()]);
  }
  $want = [
    'field_event_date' => $start,
    'field_event_date_end' => str_replace('T12:00:00', 'T14:00:00', $start),
    'field_event_all_day' => '0',
    'field_content_visibility' => 'community',
    'field_event_image' => $fid ? (string) $fid : NULL,
  ];
  $changed = $event->isNew() || !$event->isPublished();
  foreach ($want as $field => $value) {
    $item = $event->get($field);
    $have = $item->isEmpty() ? NULL : (string) ($field === 'field_event_image' ? $item->target_id : $item->value);
    if ($have !== $value) {
      if ($field === 'field_event_image' && $value !== NULL) {
        $value = ['target_id' => $value, 'alt' => 'A green field'];
      }
      $event->set($field, $value);
      $changed = TRUE;
    }
  }
  // Save only on a change: each save queues the event for search indexing.
  if ($changed) {
    $event->setPublished();
    $event->save();
  }
  if (!$group->getRelationshipsByEntity($event)) {
    $group->addRelationship($event, 'group_node:event');
  }
  return $event;
}

// Two events for the event cards' date tile, far in the future so they list
// as upcoming: Saturday 16 March 2030 with a picture, and Monday 18 March
// 2030 with no picture and a long title that has to wrap at 320px.
$event = a11y_seed_event($group, $steward, 'A11y seed swap', '2030-03-16T12:00:00', $fid);
$event_long = a11y_seed_event($group, $steward, 'A11y work day: rebuilding the raised beds along the south fence, with lunch and tools provided', '2030-03-18T12:00:00', NULL);
print 'LN_EVENT=' . $event->id() . PHP_EOL;
print 'LN_EVENT_LONG=' . $event_long->id() . PHP_EOL;
