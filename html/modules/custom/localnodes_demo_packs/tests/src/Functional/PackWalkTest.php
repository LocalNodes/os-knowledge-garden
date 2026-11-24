<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Functional;

use Drupal\advancedqueue\Entity\Queue;
use Drupal\Core\Datetime\Entity\DateFormat;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\localnodes_demo_packs\PackText;
use Drupal\localnodes_events_calendar\DemoContent\DemoDate;
use Drupal\localnodes_platform\Export\ExportSwitch;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\localnodes_demo_packs\PackChecks;
use Drupal\Tests\localnodes_demo_packs\PackWalkChecks;
use Drupal\Tests\localnodes_demo_packs\Traits\PackLoadTrait;
use Drupal\Tests\localnodes_demo_packs\Traits\PackTestTrait;
use Drupal\user\UserInterface;

/**
 * The walk of each pack: one ordinary member, step by step.
 *
 * A pack's walk.yml names the witness, the groups and roles that member has
 * and no others, and the checks of each step. The test loads the pack, holds
 * the witness to exactly that access, signs in as the witness and runs every
 * check of every checked step. It collects each miss and fails once, naming
 * the step, the kind and what was missing.
 *
 * @group localnodes_demo_packs
 * @group phase22_functional
 */
final class PackWalkTest extends BrowserTestBase {

  use PackLoadTrait;
  use PackTestTrait;

  protected $profile = 'social';

  protected $defaultTheme = 'socialblue';

  protected $strictConfigSchema = FALSE;

  /**
   * The walk being run, for the checks that name its owner.
   */
  private array $walkData = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->setUpPackSite();
  }

  /**
   * Data provider: one case per pack that has a walk file, keyed by pack.
   *
   * @return array<string, array{0: string}>
   */
  public static function walkProvider(): array {
    $cases = [];
    foreach (self::packs() as $pack) {
      if (self::walk($pack) !== []) {
        $cases[$pack] = [$pack];
      }
    }
    return $cases;
  }

  /**
   * The witness has exactly the stated access and every checked step holds.
   *
   * @dataProvider walkProvider
   */
  public function testWalk(string $pack): void {
    $walk = self::walk($pack);
    $this->walkData = $walk;
    $this->loadPack($pack);
    $this->runActivityQueues();
    $uuid = (string) ($walk['witness']['user'] ?? '');
    $witness = $this->container->get('entity.repository')->loadEntityByUuid('user', $uuid);
    $this->assertInstanceOf(UserInterface::class, $witness, "Pack $pack: the witness is a pack user.");

    $misses = $this->accessMisses($pack, $walk['witness'], $witness);
    $output_enabled = $this->htmlOutputEnabled;
    // No request of the walk may write a browser output file: the files
    // record response headers, which carry the session cookie.
    $this->htmlOutputEnabled = FALSE;
    try {
      $password = bin2hex(random_bytes(12));
      $witness->setPassword($password)->save();
      $witness->passRaw = $password;
      $this->drupalResetSession();
      $this->drupalLogin($witness);
      foreach ($walk['steps'] ?? [] as $step) {
        if (($step['status'] ?? '') !== 'checked') {
          continue;
        }
        foreach ($step['checks'] ?? [] as $check) {
          $kind = (string) ($check['kind'] ?? '');
          foreach ($this->runCheck($pack, $kind, $check) as $miss) {
            $misses[] = "Pack $pack, step {$step['id']}, $kind: $miss";
          }
        }
      }
      $this->drupalLogout();
    }
    finally {
      $this->htmlOutputEnabled = $output_enabled;
    }
    $this->assertSame([], $misses, "The walk of pack $pack has misses.");
    $this->removePack($pack);
  }

  /**
   * Misses in what the witness belongs to and may do.
   *
   * @return string[]
   *   One message for each group the witness is in and should not be, or is
   *   not in and should be, and for a role list that is not the stated one.
   */
  private function accessMisses(string $pack, array $access, UserInterface $witness): array {
    // The memberships are read from storage, not through the membership
    // loader: the loader keeps a list of each user's memberships in a cache
    // that this process may already hold from before the pack's later
    // memberships were added, and that list would miss them.
    $in = [];
    $storage = $this->container->get('entity_type.manager')->getStorage('group_content');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('entity_id', $witness->id())
      ->condition('plugin_id', 'group_membership')
      ->execute();
    foreach ($storage->loadMultiple($ids) as $membership) {
      $in[] = (string) $membership->getGroup()->uuid();
    }
    sort($in);
    $expected = array_map('strval', $access['member_of'] ?? []);
    sort($expected);
    $misses = [];
    foreach (array_diff($in, $expected) as $group) {
      $misses[] = "Pack $pack, witness: is a member of group $group, which member_of does not list.";
    }
    foreach (array_diff($expected, $in) as $group) {
      $misses[] = "Pack $pack, witness: is not a member of group $group, which member_of lists.";
    }
    foreach ($access['not_member_of'] ?? [] as $group) {
      if (in_array((string) $group, $in, TRUE)) {
        $misses[] = "Pack $pack, witness: is a member of group $group, which not_member_of lists.";
      }
    }
    $roles = $witness->getRoles();
    sort($roles);
    $stated = array_map('strval', $access['roles'] ?? []);
    sort($stated);
    if ($roles !== $stated) {
      $misses[] = "Pack $pack, witness: the roles are [" . implode(', ', $roles) . '], not the stated [' . implode(', ', $stated) . '].';
    }
    return $misses;
  }

  /**
   * Runs one check, as the signed-in witness.
   *
   * @return string[]
   *   What the check found missing; none when it holds.
   */
  private function runCheck(string $pack, string $kind, array $check): array {
    return match ($kind) {
      'page' => $this->checkPage($pack, $check),
      'post' => $this->checkPost($check),
      'poll' => $this->checkPoll($check),
      'calendar' => $this->checkCalendar($check),
      'event' => $this->checkEvent($check),
      'shared_page' => $this->checkSharedPage($check),
      'hidden_page' => $this->checkHiddenPage($check),
      'export' => $this->checkExport(),
      default => ["the check kind '$kind' is not one this test knows."],
    };
  }

  /**
   * A page opens for the witness and shows the passage the pack quotes.
   */
  private function checkPage(string $pack, array $check): array {
    $entity = $this->entityByUuid((string) ($check['uuid'] ?? ''));
    if ($entity === NULL) {
      return ['the page ' . ($check['uuid'] ?? '') . ' did not load.'];
    }
    $passage = $this->passage($pack, (string) ($check['passage'] ?? ''));
    if ($passage === NULL) {
      return ['the passage ' . ($check['passage'] ?? '') . ' is not in passages.yml.'];
    }
    $this->drupalGet($entity->toUrl());
    $status = $this->getSession()->getStatusCode();
    if ($status !== 200) {
      return ["the page {$check['uuid']} answers $status to the witness."];
    }
    $expected = PackText::normalize(PackChecks::expectedDate($passage, self::packAnchor()));
    $text = PackText::normalize($this->getSession()->getPage()->getText());
    return str_contains($text, $expected) ? [] : ["the page {$check['uuid']} does not show passage {$check['passage']}."];
  }

  /**
   * The witness's home stream shows the post's phrase; the post has its likes.
   */
  private function checkPost(array $check): array {
    $post = $this->entityByUuid((string) ($check['uuid'] ?? ''));
    if ($post === NULL) {
      return ['the post ' . ($check['uuid'] ?? '') . ' did not load.'];
    }
    $misses = [];
    $this->drupalGet('/stream');
    $status = $this->getSession()->getStatusCode();
    $text = PackText::normalize($this->getSession()->getPage()->getText());
    $phrase = PackText::normalize((string) ($check['phrase'] ?? ''));
    if ($status !== 200) {
      $misses[] = "the home stream answers $status to the witness.";
    }
    elseif ($phrase === '' || !str_contains($text, $phrase)) {
      $misses[] = "the home stream does not show the phrase of post {$check['uuid']}.";
    }
    $likes = (int) $this->container->get('entity_type.manager')->getStorage('vote')->getQuery()
      ->accessCheck(FALSE)
      ->condition('entity_type', $post->getEntityTypeId())
      ->condition('entity_id', $post->id())
      ->count()
      ->execute();
    if ($likes !== (int) ($check['likes'] ?? -1)) {
      $misses[] = "the post {$check['uuid']} has $likes likes, not " . ($check['likes'] ?? '?') . '.';
    }
    return $misses;
  }

  /**
   * The group's Quick polls tab shows the question and each choice's total.
   *
   * No voter's name may appear inside the poll's own element.
   */
  private function checkPoll(array $check): array {
    $poll = $this->entityByUuid((string) ($check['uuid'] ?? ''));
    if ($poll === NULL || $poll->getEntityTypeId() !== 'poll') {
      return ['the poll ' . ($check['uuid'] ?? '') . ' did not load.'];
    }
    $this->container->get('entity_type.manager')->getStorage('poll')->resetCache([$poll->id()]);
    $poll = $this->container->get('entity_type.manager')->getStorage('poll')->load($poll->id());
    $misses = [];
    $database = $this->container->get('database');
    $answers = (int) $database->select('poll_vote', 'v')->condition('pid', $poll->id())->countQuery()->execute()->fetchField();
    if ($answers !== (int) ($check['answers'] ?? -1)) {
      $misses[] = "the poll has $answers answers, not " . ($check['answers'] ?? '?') . '.';
    }
    $closed = !(bool) $poll->get('status')->value;
    if ($closed !== (bool) ($check['closed'] ?? FALSE)) {
      $misses[] = 'the poll is ' . ($closed ? 'closed' : 'open') . ', not as the walk states.';
    }
    $group_id = $poll->get('quick_poll_group')->target_id;
    $this->drupalGet('/group/' . $group_id . '/quick-polls');
    $status = $this->getSession()->getStatusCode();
    if ($status !== 200) {
      return array_merge($misses, ["the Quick polls tab answers $status to the witness."]);
    }
    $question = PackText::normalize((string) ($check['question'] ?? ''));
    $card = NULL;
    foreach ($this->getSession()->getPage()->findAll('css', '.localnodes-quick-poll') as $candidate) {
      if (str_contains(PackText::normalize($candidate->getText()), $question)) {
        $card = $candidate;
        break;
      }
    }
    if ($card === NULL || $question === '') {
      return array_merge($misses, ['the Quick polls tab does not show the question.']);
    }
    $totals = [];
    foreach ($card->findAll('css', 'dt.choice-title') as $title) {
      $result = $title->find('xpath', './following-sibling::dd[1]');
      $found = $result !== NULL && preg_match('/\((\d+) answers?\)/', $result->getText(), $match) === 1;
      $totals[PackText::normalize($title->getText())] = $found ? (int) $match[1] : NULL;
    }
    foreach ($check['totals'] ?? [] as $choice => $count) {
      $shown = $totals[PackText::normalize((string) $choice)] ?? NULL;
      if ($shown !== (int) $count) {
        $misses[] = "the choice at index " . array_search($choice, array_keys($check['totals'])) . ' shows ' . ($shown ?? 'no total') . ", not $count.";
      }
    }
    // Nobody's name: not the account name, not the display name of any voter.
    $card_text = PackText::normalize($card->getText());
    $voters = $database->select('poll_vote', 'v')->fields('v', ['uid'])->condition('pid', $poll->id())->execute()->fetchCol();
    foreach ($this->container->get('entity_type.manager')->getStorage('user')->loadMultiple($voters) as $voter) {
      foreach ([$voter->getAccountName(), $voter->getDisplayName()] as $name) {
        if (trim($name) !== '' && str_contains($card_text, PackText::normalize($name))) {
          $misses[] = 'a voter (user ' . $voter->uuid() . ') is named inside the poll.';
          break;
        }
      }
    }
    return $misses;
  }

  /**
   * The month calendar that holds the event shows its title in the grid.
   */
  private function checkCalendar(array $check): array {
    $event = $this->entityByUuid((string) ($check['event'] ?? ''));
    if ($event === NULL) {
      return ['the event ' . ($check['event'] ?? '') . ' did not load.'];
    }
    $start = (string) $event->get('field_event_date')->value;
    $month = ['query' => ['calendar_timestamp' => substr($start, 0, 10)]];
    if (!empty($check['group'])) {
      $group = $this->entityByUuid((string) $check['group']);
      if ($group === NULL) {
        return ['the group ' . $check['group'] . ' did not load.'];
      }
      $this->drupalGet('/group/' . $group->id() . '/events/calendar', $month);
    }
    else {
      $this->drupalGet('/community-events/calendar', $month);
    }
    $status = $this->getSession()->getStatusCode();
    if ($status !== 200) {
      return ["the calendar answers $status to the witness."];
    }
    $title = PackText::normalize((string) $event->label());
    foreach ($this->getSession()->getPage()->findAll('css', 'table') as $grid) {
      if (str_contains(PackText::normalize($grid->getText()), $title)) {
        return [];
      }
    }
    return ["the calendar of the event's month does not show event {$check['event']} in its grid."];
  }

  /**
   * The event page shows its phrases; it has its enrolments and its start.
   */
  private function checkEvent(array $check): array {
    $event = $this->entityByUuid((string) ($check['uuid'] ?? ''));
    if ($event === NULL) {
      return ['the event ' . ($check['uuid'] ?? '') . ' did not load.'];
    }
    $misses = [];
    $state = $this->container->get('state');
    $state->resetCache();
    $anchor = (int) $state->get('localnodes_demo_packs.anchor');
    $expected = DemoDate::dateTime((string) ($check['start'] ?? ''), $anchor);
    $stored = (string) $event->get('field_event_date')->value;
    if ($stored !== $expected) {
      $misses[] = "the stored start of event {$check['uuid']} is not the pack's start spec at the pack's anchor.";
    }
    $going = $this->goingCount($event);
    if ($going !== (int) ($check['going'] ?? -1)) {
      $misses[] = "the event has $going confirmed going, not " . ($check['going'] ?? '?') . '.';
    }
    $this->drupalGet($event->toUrl());
    $status = $this->getSession()->getStatusCode();
    if ($status !== 200) {
      return array_merge($misses, ["the event page answers $status to the witness."]);
    }
    $text = PackText::normalize($this->getSession()->getPage()->getText());
    foreach ($check['phrases'] ?? [] as $index => $phrase) {
      if (!str_contains($text, PackText::normalize((string) $phrase))) {
        $misses[] = "the event page does not show phrase $index.";
      }
    }
    // The start as the screen shows it: the site's long date and time
    // patterns, in the witness's timezone, from the pack's start spec at the
    // pack's anchor (not from what the event stored).
    $account = $this->loggedInUser;
    $timezone = $account !== FALSE ? ((string) $account->getTimeZone() ?: 'UTC') : 'UTC';
    $date_format = DateFormat::load('social_long_date');
    $time_format = DateFormat::load('social_time');
    if ($date_format === NULL || $time_format === NULL) {
      $misses[] = 'the site has no long date or time pattern to show an event start with.';
    }
    else {
      $shown = PackWalkChecks::shownStart(DemoDate::dateTime((string) ($check['start'] ?? ''), self::packAnchor()), $timezone, $date_format->getPattern(), $time_format->getPattern());
      $misses = array_merge($misses, PackWalkChecks::startProblems($text, $shown));
    }
    return $misses;
  }

  /**
   * How many confirmed enrolments an event has, with a not-going one present.
   *
   * An enrolment whose status is not the going status, by an account other
   * than the witness, is added for the count and deleted right after, so a
   * count that leaves out the status condition comes out one too high.
   */
  private function goingCount(EntityInterface $event): int {
    $manager = $this->container->get('entity_type.manager');
    $storage = $manager->getStorage('event_enrollment');
    $field = FieldStorageConfig::loadByName('event_enrollment', 'field_enrollment_status');
    $other = NULL;
    foreach (array_keys($field !== NULL ? (array) $field->getSetting('allowed_values') : []) as $value) {
      if ((string) $value !== (string) PackWalkChecks::GOING_STATUS) {
        $other = (string) $value;
        break;
      }
    }
    $extra = NULL;
    if ($other !== NULL) {
      $witness = $this->loggedInUser !== FALSE ? (int) $this->loggedInUser->id() : 0;
      $taken = array_map(static fn (EntityInterface $row): int => (int) $row->get('field_account')->target_id, $storage->loadByProperties(['field_event' => $event->id()]));
      $ids = $manager->getStorage('user')->getQuery()->accessCheck(FALSE)->condition('uid', 0, '>')->condition('uid', array_merge([$witness], $taken), 'NOT IN')->sort('uid')->range(0, 1)->execute();
      if ($ids !== []) {
        $uid = (int) reset($ids);
        $extra = $storage->create([
          'field_event' => $event->id(),
          'field_account' => $uid,
          'user_id' => $uid,
          'field_enrollment_status' => $other,
        ]);
        $extra->save();
      }
    }
    try {
      return (int) $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_event', $event->id())
        ->condition('field_enrollment_status', PackWalkChecks::GOING_STATUS)
        ->count()
        ->execute();
    }
    finally {
      if ($extra !== NULL) {
        $extra->delete();
      }
    }
  }

  /**
   * A page of a group the witness is not in opens, and shows the group's name.
   */
  private function checkSharedPage(array $check): array {
    $page = $this->entityByUuid((string) ($check['uuid'] ?? ''));
    $group = $this->entityByUuid((string) ($check['group'] ?? ''));
    if ($page === NULL || $group === NULL) {
      return ['the page or the group of the check did not load.'];
    }
    $misses = [];
    $account = $this->loggedInUser;
    if ($account !== FALSE && $group->getMember($account) !== FALSE) {
      $misses[] = 'the witness is a member of the group the page is shared from.';
    }
    $this->drupalGet($page->toUrl());
    $status = $this->getSession()->getStatusCode();
    if ($status !== 200) {
      return array_merge($misses, ["the shared page answers $status to the witness."]);
    }
    $text = PackText::normalize($this->getSession()->getPage()->getText());
    if (!str_contains($text, PackText::normalize((string) $group->label()))) {
      $misses[] = "the shared page does not show the name of group {$check['group']}.";
    }
    return $misses;
  }

  /**
   * A page the witness may not see is refused, and its title is not shown.
   */
  private function checkHiddenPage(array $check): array {
    $page = $this->entityByUuid((string) ($check['uuid'] ?? ''));
    if ($page === NULL) {
      return ['the page ' . ($check['uuid'] ?? '') . ' did not load.'];
    }
    $title = PackText::normalize((string) $page->label());
    $this->drupalGet($page->toUrl());
    $status = $this->getSession()->getStatusCode();
    $misses = [];
    if (!in_array($status, [403, 404], TRUE)) {
      $misses[] = "the hidden page answers $status to the witness, not 403 or 404.";
    }
    $text = PackText::normalize($this->getSession()->getPage()->getText());
    if ($title !== '' && str_contains($text, $title)) {
      $misses[] = "the response to the witness shows the title of page {$check['uuid']}.";
    }
    return $misses;
  }

  /**
   * The export pages follow the export switch; no export job is made.
   *
   * With the switch off, as on a fresh site, the owner and the witness both
   * get 404 on the export page and its confirm page. With it on, the owner
   * reaches both pages and the witness gets 403. The owner signs in for the
   * check and the witness signs in again after it, so keep this the last
   * check of a walk.
   */
  private function checkExport(): array {
    $owner = $this->entityByUuid((string) ($this->walkData['owner'] ?? ''));
    $witness = $this->loggedInUser;
    if ($owner === NULL || $witness === FALSE) {
      return ['the owner of the walk did not load.'];
    }
    $on = ExportSwitch::enabled($this->container->get('config.factory'));
    // The routes are gone while the switch is off, so there are no URLs to
    // build from them: the paths are written out.
    $offPaths = [
      '/admin/config/system/site-export',
      '/admin/config/system/site-export/confirm/request',
    ];
    $routes = [
      'localnodes_platform.site_export' => [],
      'localnodes_platform.site_export_confirm' => ['operation' => 'request'],
    ];
    $jobs = fn(): int => (int) $this->container->get('database')->select('localnodes_site_export')->countQuery()->execute()->fetchField();
    $before = $jobs();
    $misses = [];
    $password = bin2hex(random_bytes(12));
    $owner->setPassword($password)->save();
    $owner->passRaw = $password;
    $this->drupalLogout();
    $this->drupalLogin($owner);
    foreach ($on ? [] : $offPaths as $path) {
      $this->drupalGet($path);
      $status = $this->getSession()->getStatusCode();
      if ($status !== 404) {
        $misses[] = "the owner gets $status on $path with the export off, not 404.";
      }
    }
    foreach ($on ? $routes : [] as $route => $parameters) {
      $this->drupalGet(Url::fromRoute($route, $parameters));
      $status = $this->getSession()->getStatusCode();
      if ($status !== 200) {
        $misses[] = "the owner gets $status on $route.";
      }
      elseif ($route === 'localnodes_platform.site_export' && !str_contains($this->getSession()->getPage()->getText(), 'Export this site')) {
        $misses[] = 'the owner does not see Export this site.';
      }
      elseif ($route === 'localnodes_platform.site_export_confirm') {
        foreach (PackWalkChecks::exportConfirmProblems($this->getSession()->getPage()->getContent()) as $problem) {
          $misses[] = "for the owner, $problem";
        }
      }
    }
    $this->drupalLogout();
    $this->drupalLogin($witness);
    foreach ($on ? [] : $offPaths as $path) {
      $this->drupalGet($path);
      $status = $this->getSession()->getStatusCode();
      if ($status !== 404) {
        $misses[] = "the witness gets $status on $path with the export off, not 404.";
      }
    }
    foreach ($on ? $routes : [] as $route => $parameters) {
      $this->drupalGet(Url::fromRoute($route, $parameters));
      $status = $this->getSession()->getStatusCode();
      if ($status !== 403) {
        $misses[] = "the witness gets $status on $route, not 403.";
      }
    }
    if ($jobs() !== $before) {
      $misses[] = 'an export job was made.';
    }
    return $misses;
  }

  /**
   * Works through Open Social's activity queues, as cron would.
   */
  private function runActivityQueues(): void {
    $workers = $this->container->get('plugin.manager.queue_worker');
    $factory = $this->container->get('queue');
    $jobs = Queue::load('default');
    for ($round = 0; $round < 10; $round++) {
      $done = 0;
      foreach (array_keys($workers->getDefinitions()) as $name) {
        if (!str_starts_with($name, 'activity')) {
          continue;
        }
        $queue = $factory->get($name);
        $worker = $workers->createInstance($name);
        while ($item = $queue->claimItem()) {
          $worker->processItem($item->data);
          $queue->deleteItem($item);
          $done++;
        }
      }
      if ($jobs !== NULL) {
        $done += $this->container->get('advancedqueue.processor')->processQueue($jobs);
      }
      if ($done === 0) {
        return;
      }
    }
    $this->fail('The activity queues did not empty.');
  }

  /**
   * The text of a passage of the pack, by its id.
   */
  private function passage(string $pack, string $id): ?string {
    foreach (self::expectation($pack, 'passages.yml') as $passage) {
      if (($passage['id'] ?? '') === $id) {
        return (string) $passage['text'];
      }
    }
    return NULL;
  }

}
