<?php

/**
 * @file
 * Cross-process probe: a declined event invitation in a long-lived process.
 *
 * Codex review of 05468b5 (P1 4): Open Social's EntityAccessHelper allows a
 * view of an event to anyone with a not-declined invitation, read through
 * event_enrollment entity storage. A long-lived process that already loaded
 * the enrollment keeps it in memory, so an invitation declined by ANOTHER
 * process kept granting access. A Kernel test can't install social_event
 * cheaply, so this runs on a real Open Social site with two real processes:
 * this one stays warm; a second drush process declines the invitation.
 *
 * LOCAL DDEV ONLY, on a throwaway site with social_event_invite enabled
 * (fresh3-a11y). Creates a fictional user and event and deletes both at the
 * end. Run:
 *   ddev drush php-script html/modules/custom/social_ai_indexing/scripts/probe_event_invite_cross_process.php
 *
 * Prints one line per check and "PROBE PASS" or "PROBE FAIL". Without
 * RetrievalAccessCache::fresh() the warm process must still say ALLOWED
 * after the decline (the gap is real); with it, DENIED.
 */

use Drupal\social_ai_indexing\Access\RetrievalAccessCache;
use Drupal\social_event\EventEnrollmentInterface;

$etm = \Drupal::entityTypeManager();
$user = $etm->getStorage('user')->create([
  'name' => 'fictional_probe_invitee_' . time(),
  'mail' => 'fictional_probe_invitee_' . time() . '@example.invalid',
  'status' => 1,
]);
$user->save();
// Open Social adds 'verified' to new accounts, and it grants community
// view by itself; without it, the invitation is the only way in.
$user->removeRole('verified');
$user->save();
$event = $etm->getStorage('node')->create([
  'type' => 'event',
  'title' => 'Fictional probe event',
  'field_content_visibility' => 'community',
  'field_event_date' => gmdate('Y-m-d\TH:i:s', time() + 86400),
  'field_event_date_end' => gmdate('Y-m-d\TH:i:s', time() + 90000),
  'uid' => 1,
  'status' => 1,
]);
$event->save();
$enrollment = $etm->getStorage('event_enrollment')->create([
  'field_account' => $user->id(),
  'field_event' => $event->id(),
  'field_enrollment_status' => 0,
  'field_request_or_invite_status' => EventEnrollmentInterface::INVITE_PENDING_REPLY,
  'user_id' => 1,
]);
$enrollment->save();

$ok = TRUE;
$check = static function (string $label, bool $actual, bool $expected) use (&$ok): void {
  printf("%-62s %s (expected %s)\n", $label, $actual ? 'ALLOWED' : 'DENIED', $expected ? 'ALLOWED' : 'DENIED');
  $ok = $ok && $actual === $expected;
};

try {
  // Warm: the invitee (not verified, so no community view) is let in by
  // the pending invitation, and this process now holds the enrollment.
  $check('before: invited, warm process', $event->access('view', $user), TRUE);

  // ANOTHER process declines the invitation through the entity API.
  $id = (int) $enrollment->id();
  $drush = DRUPAL_ROOT . '/../vendor/bin/drush';
  $code = sprintf('$e = \Drupal::entityTypeManager()->getStorage("event_enrollment")->load(%d); $e->set("field_request_or_invite_status", %d); $e->save(); echo "declined";', $id, EventEnrollmentInterface::REQUEST_OR_INVITE_DECLINED);
  $out = shell_exec(escapeshellarg($drush) . ' --root=' . escapeshellarg(DRUPAL_ROOT) . ' php-eval ' . escapeshellarg($code) . ' 2>&1');
  printf("%-62s %s\n", 'other process:', trim((string) $out));

  // Control: a brand-new process (what a web request sees) must deny.
  $code = sprintf('$u = \Drupal\user\Entity\User::load(%d); $n = \Drupal\node\Entity\Node::load(%d); echo json_encode(["roles" => $u->getRoles(), "allowed" => $n->access("view", $u)]);', $user->id(), $event->id());
  $control = json_decode(trim((string) shell_exec(escapeshellarg($drush) . ' --root=' . escapeshellarg(DRUPAL_ROOT) . ' php-eval ' . escapeshellarg($code) . ' 2>/dev/null')), TRUE);
  printf("%-62s roles %s\n", 'control (new process): invitee', implode(',', (array) ($control['roles'] ?? [])));
  $check('control: after decline, a brand-new process', (bool) ($control['allowed'] ?? TRUE), FALSE);

  // The warm process without a fresh context: the gap.
  $etm->getAccessControlHandler('node')->resetCache();
  $check('after decline, access cache reset only (the gap)', $event->access('view', $user), TRUE);

  // With the fresh access context: the fix.
  $fresh = RetrievalAccessCache::fresh($etm, $user);
  $node = $etm->getStorage('node')->load($event->id());
  $check('after decline, RetrievalAccessCache::fresh()', $node->access('view', $fresh), FALSE);
}
finally {
  $etm->getStorage('event_enrollment')->resetCache();
  foreach ([$etm->getStorage('event_enrollment')->load($enrollment->id()), $etm->getStorage('node')->load($event->id()), $etm->getStorage('user')->load($user->id())] as $entity) {
    $entity?->delete();
  }
}
print $ok ? "PROBE PASS\n" : "PROBE FAIL\n";
