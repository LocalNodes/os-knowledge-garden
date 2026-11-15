<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Functional;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Url;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRelationship;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Entity\ImportRun;
use Drupal\social_event\Entity\EventEnrollment;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\localnodes_demo_packs\PackText;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\localnodes_demo_packs\PackChecks;
use Drupal\Tests\localnodes_demo_packs\Traits\PackLoadTrait;
use Drupal\Tests\localnodes_demo_packs\Traits\PackTestTrait;
use Drupal\user\UserInterface;

/**
 * Every control a how-to page names is on the screen the page sends a member to.
 *
 * The rows are the screen rows of tests/fixtures/expect/howto/labels.yml. The
 * test signs in as the account a row names, opens the screen the row names and
 * looks for the label on a visible, enabled control of the kind the row names
 * (PackChecks::controlOnScreen()): a link, tab, button, submit button, field
 * label, option, heading or placeholder. A name that is only part of a longer
 * one (Join inside Joined), a heading or hidden node that repeats the name, and
 * a disabled button do not count. It only reads screens, so no row changes what
 * a later row sees.
 *
 * @group localnodes_demo_packs
 * @group phase22_functional
 */
final class PackHowtoLabelsTest extends BrowserTestBase {

  use PackLoadTrait;
  use PackTestTrait;

  protected $profile = 'social';

  protected $defaultTheme = 'socialblue';

  protected $strictConfigSchema = FALSE;

  /**
   * The smoke group's id, once the pack is loaded.
   */
  private int $groupId = 0;

  /**
   * Smoke member two, who belongs to the smoke group.
   */
  private const MEMBER_UUID = '5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d02';

  /**
   * Smoke organizer one, who runs the smoke group and may import.
   */
  private const ORGANIZER_UUID = '5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d01';

  /**
   * The smoke pack's open quick poll.
   */
  private const OPEN_POLL = '5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d31';

  /**
   * Content this test makes for screens the smoke pack does not cover.
   */
  private ?Group $requestGroup = NULL;

  private ?Node $event = NULL;

  private ?Node $requestEvent = NULL;

  private ?Node $enrolledEvent = NULL;

  private ?Node $importedTopic = NULL;

  private bool $invited = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->setUpPackSite();
    // The browser output files record response headers, so no request in this
    // class writes one.
    $this->htmlOutputEnabled = FALSE;
    // The address an import problem report goes to; a made-up one, never used
    // here because no report is sent.
    $this->writeSettings(['settings' => ['localnodes_host_report_email' => (object) ['value' => 'host@report.example', 'required' => TRUE]]]);
  }

  /**
   * The request-to-join method the P01 rows rely on is on this site.
   *
   * Without it a group could only be joined directly, and a miss on the
   * request rows would blame the page instead of the missing module.
   */
  public function testRequestModuleIsOn(): void {
    $this->assertTrue($this->container->get('module_handler')->moduleExists('social_group_request'), 'The request-to-join module is on.');
    $this->assertTrue($this->container->get('plugin.manager.social_group.join')->hasMethod('flexible_group', 'request'), 'A flexible group can use the request method.');
  }

  /**
   * Every screen row's label is on its screen.
   *
   * The misses are collected and reported once, so one run shows every page
   * that sends a member looking for a control the site does not have.
   */
  public function testScreenLabels(): void {
    $this->loadPack('smoke');
    $group = $this->entityByUuid('5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d10');
    $this->assertNotNull($group, 'The smoke group loaded.');
    $this->groupId = (int) $group->id();

    $by_account = [];
    $rows = self::expectation('howto', 'labels.yml');
    $this->assertNotEmpty($rows, 'labels.yml states rows.');
    foreach ($rows as $row) {
      if (($row['check'] ?? '') === 'screen') {
        $by_account[(string) $row['as']][] = $row;
      }
    }
    $this->assertNotEmpty($by_account, 'labels.yml holds screen rows.');

    $misses = [];
    foreach ($by_account as $as => $rows_of_account) {
      $this->drupalResetSession();
      if ($as !== 'anonymous') {
        $this->drupalLogin($this->accountFor($as));
      }
      foreach ($rows_of_account as $row) {
        $id = (string) $row['id'];
        $label = (string) $row['label'];
        $where = (string) $row['where'];
        $this->drupalGet($this->screenUrl($where));
        $status = $this->getSession()->getStatusCode();
        if ($status !== 200) {
          $misses[] = "$id: the screen '$where' answered $status for $as";
        }
        elseif (!PackChecks::controlOnScreen((string) $this->getSession()->getPage()->getContent(), $label, (string) $row['control'], isset($row['within']) ? (string) $row['within'] : NULL)) {
          $region = isset($row['within']) ? " inside '{$row['within']}'" : '';
          $misses[] = "$id: no {$row['control']} named '$label'$region is on the screen '$where' for $as";
        }
      }
      if ($as !== 'anonymous') {
        $this->drupalLogout();
      }
    }
    $this->assertSame([], $misses, "A how-to page names a control a screen lacks:\n" . implode("\n", $misses));
  }

  /**
   * A member who has answered a quick poll is offered no way to take it back.
   *
   * The P06 page says an answer stays once given. The smoke pack has the
   * member answer the open poll; on the group's quick polls the member must
   * see no Take back my answer control anywhere on the screen, and the poll
   * must still hold the member's answer.
   */
  public function testAnsweredPollOffersNoTakeBack(): void {
    $this->loadPack('smoke');
    $group = $this->entityByUuid('5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d10');
    $this->assertNotNull($group, 'The smoke group loaded.');
    $repository = $this->container->get('entity.repository');
    $poll = $repository->loadEntityByUuid('poll', self::OPEN_POLL);
    $this->assertNotNull($poll, 'The open smoke poll exists.');
    $member = $repository->loadEntityByUuid('user', self::MEMBER_UUID);
    $answers = (int) $this->container->get('database')->select('poll_vote', 'v')
      ->condition('pid', $poll->id())
      ->condition('uid', $member->id())
      ->countQuery()->execute()->fetchField();
    $this->assertSame(1, $answers, 'The member has answered the open poll.');

    $this->drupalLogin($this->accountFor('member'));
    $this->drupalGet(Url::fromUserInput('/group/' . $group->id() . '/quick-polls'));
    $this->assertSame(200, $this->getSession()->getStatusCode(), 'The member opens the quick polls.');
    $html = (string) $this->getSession()->getPage()->getContent();
    foreach (['submit', 'button', 'link'] as $kind) {
      $this->assertFalse(PackChecks::controlOnScreen($html, 'Take back my answer', $kind), "No $kind takes an answer back.");
    }
    $this->assertStringNotContainsString('Take back my answer', $html, 'The screen does not name a way back.');
  }

  /**
   * Every label the copy names is in the body of its loaded page.
   *
   * The YAML check in the Unit test fails faster; this one reads the nodes as
   * the load saved them, so a paragraph lost in the load fails here. The body
   * value has its tags replaced by spaces and its spaces collapsed. A second
   * pass drops one paragraph of one loaded body, in memory only, and must see
   * the loss.
   */
  public function testLoadedPagesNameTheirLabels(): void {
    $this->loadPack('smoke');
    $texts = [];
    $bodies = [];
    foreach (self::entries(self::sharedHowto()) as $index => [$uuid]) {
      $page = sprintf('P%02d', $index + 1);
      $node = $this->entityByUuid($uuid);
      $this->assertInstanceOf(NodeInterface::class, $node, "The page $page loaded.");
      $bodies[$page] = (string) $node->get('body')->value;
      $texts[$page] = self::plain($bodies[$page]);
    }
    $this->assertCount(10, $texts, 'Ten how-to pages loaded.');

    $rows = self::expectation('howto', 'labels.yml');
    $this->assertSame([], PackChecks::copyLabelProblems($rows, $texts), 'A loaded page lost a label the copy names.');

    $row = NULL;
    foreach ($rows as $candidate) {
      if (!empty($candidate['in_copy']) && $candidate['page'] === 'P06') {
        $row = $candidate;
        break;
      }
    }
    $this->assertNotNull($row, 'P06 names a label.');
    $paragraphs = preg_split('#(?<=</p>)#', $bodies['P06'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $label = PackText::normalize((string) $row['label']);
    $kept = array_filter($paragraphs, static fn (string $paragraph): bool => !str_contains(self::plain($paragraph), $label));
    $this->assertLessThan(count($paragraphs), count($kept), 'One paragraph held the label.');
    $dropped = $texts;
    $dropped['P06'] = self::plain(implode('', $kept));
    $this->assertContains((string) $row['id'], PackChecks::copyLabelProblems($rows, $dropped), 'A dropped paragraph is seen.');
  }

  /**
   * A body as a member reads it: tags replaced by spaces, spaces collapsed.
   */
  private static function plain(string $html): string {
    return PackText::normalize((string) preg_replace('/<[^>]+>/', ' ', $html));
  }

  /**
   * The route of a screen a row names.
   *
   * Every screen is opened with a GET and nothing on it is submitted, so no row
   * changes what a later row sees. The few screens that need content the smoke
   * pack does not hold (a group that takes requests, an event, an unanswered
   * poll) make it here, once, the first time a row asks for it.
   */
  private function screenUrl(string $where): Url {
    $group = $this->groupId;
    return match ($where) {
      'front page' => Url::fromRoute('<front>'),
      'group page' => Url::fromUserInput('/group/' . $group),
      'group stream' => Url::fromUserInput('/group/' . $group . '/stream'),
      'group events' => Url::fromUserInput('/group/' . $group . '/events'),
      'group calendar' => Url::fromUserInput('/group/' . $group . '/events/calendar'),
      'group quick polls' => $this->quickPollsUrl(),
      'leave form' => Url::fromUserInput('/group/' . $group . '/leave'),
      'topic add form' => Url::fromUserInput('/node/add/topic'),
      'account edit form' => Url::fromUserInput('/user/' . $this->memberId() . '/edit'),
      'request group page' => $this->requestGroup()->toUrl(),
      'request form' => $this->requestGroup()->toUrl('group-request-membership'),
      'event page' => $this->event()->toUrl(),
      'request event page' => $this->requestEvent()->toUrl(),
      'enrolled event page' => $this->enrolledEvent()->toUrl(),
      'help page' => Url::fromUserInput('/help'),
      'import page' => Url::fromUserInput('/group/' . $group . '/import'),
      'import files form' => Url::fromUserInput('/group/' . $group . '/import/files'),
      'invitations page' => $this->invitationsUrl(),
      'imported page' => $this->importedTopic()->toUrl(),
      'report form' => Url::fromRoute('localnodes_import.report_problem', ['topic' => $this->importedTopic()->id()]),
      default => throw new \LogicException("labels.yml names a screen the test does not know: $where"),
    };
  }

  /**
   * The id of the member account the screen rows use.
   */
  private function memberId(): int {
    $account = $this->container->get('entity.repository')->loadEntityByUuid('user', self::MEMBER_UUID);
    $this->assertInstanceOf(UserInterface::class, $account, 'The member account exists.');
    return (int) $account->id();
  }

  /**
   * A group that takes requests to join, which the smoke pack does not hold.
   */
  private function requestGroup(): Group {
    if ($this->requestGroup === NULL) {
      $owner = $this->container->get('entity.repository')->loadEntityByUuid('user', '5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d01');
      $this->assertNotNull($owner, 'The smoke organizer exists.');
      $this->requestGroup = Group::create([
        'type' => 'flexible_group',
        'label' => 'Request house',
        'uid' => $owner->id(),
        'field_flexible_group_visibility' => 'public',
        'field_group_allowed_visibility' => ['public', 'community', 'group'],
        'field_group_allowed_join_method' => 'request',
      ]);
      $this->requestGroup->save();
    }
    return $this->requestGroup;
  }

  /**
   * The member's invitations screen, once an organizer has invited the member.
   *
   * The invitation is the one a group's invite form saves: a group_invitation
   * relationship from an organizer to the member, pending. The group takes
   * members by invitation only, so the member is not in it.
   */
  private function invitationsUrl(): Url {
    if (!$this->invited) {
      $organizer = $this->userId(self::ORGANIZER_UUID);
      $member = $this->container->get('entity.repository')->loadEntityByUuid('user', self::MEMBER_UUID);
      $this->assertInstanceOf(UserInterface::class, $member, 'The member account exists.');
      $group = Group::create([
        'type' => 'flexible_group',
        'label' => 'Invite house',
        'uid' => $organizer,
        'field_flexible_group_visibility' => 'public',
        'field_group_allowed_visibility' => ['public', 'community', 'group'],
        'field_group_allowed_join_method' => 'added',
      ]);
      $group->save();
      GroupRelationship::create([
        'type' => 'flexible_group-group_invitation',
        'gid' => $group->id(),
        'uid' => $organizer,
        'entity_id' => $member->id(),
        'invitee_mail' => $member->getEmail(),
      ])->save();
      $this->invited = TRUE;
    }
    return Url::fromUserInput('/user/' . $this->memberId() . '/group-invites');
  }

  /**
   * A topic the importer says it imported, in the smoke group.
   *
   * It carries what the Source box reads (an import run and an imported item
   * naming the topic), so the page shows "Report a problem with this page".
   * Nothing is imported and no report is sent.
   */
  private function importedTopic(): Node {
    if ($this->importedTopic === NULL) {
      $organizer = $this->userId(self::ORGANIZER_UUID);
      $group = Group::load($this->groupId);
      $this->assertNotNull($group, 'The smoke group exists.');
      $topic = Node::create([
        'type' => 'topic',
        'title' => 'Pottery primer',
        'uid' => $organizer,
        'status' => NodeInterface::PUBLISHED,
        'body' => ['value' => '<p>Kiln notes.</p>', 'format' => 'plain_text'],
        'field_content_visibility' => 'public',
      ]);
      $topic->save();
      $group->addRelationship($topic, 'group_node:topic');
      $manager = $this->container->get('entity_type.manager');
      $run = $manager->getStorage('localnodes_import_run')->create([
        'gid' => $group->id(),
        'uid' => $organizer,
        'kind' => ImportRun::KIND_FILES,
        'status' => ImportRun::STATUS_FINISHED,
        'visibility' => 'public',
        'rights_confirmed_by' => $organizer,
        'rights_confirmed_at' => 1000,
      ]);
      $run->save();
      $manager->getStorage('localnodes_import_item')->create([
        'run' => $run->id(),
        'gid' => $group->id(),
        'source_name' => 'primer.pdf',
        'outcome' => ImportItem::OUTCOME_IMPORTED,
        'nid' => $topic->id(),
      ])->save();
      $this->importedTopic = $topic;
    }
    return $this->importedTopic;
  }

  /**
   * An upcoming public event with enrollment open, which the smoke pack lacks.
   */
  private function event(): Node {
    if ($this->event === NULL) {
      $this->event = $this->makeEvent('Kettle night', $this->memberId(), 1);
    }
    return $this->event;
  }

  /**
   * An upcoming event whose enrollment needs the organizer's approval.
   *
   * The organizer owns it, because the owner is never asked to request.
   */
  private function requestEvent(): Node {
    if ($this->requestEvent === NULL) {
      $this->requestEvent = $this->makeEvent('Lantern night', $this->userId(self::ORGANIZER_UUID), 2);
    }
    return $this->requestEvent;
  }

  /**
   * An upcoming event the member is enrolled in.
   */
  private function enrolledEvent(): Node {
    if ($this->enrolledEvent === NULL) {
      $organizer = $this->userId(self::ORGANIZER_UUID);
      $this->enrolledEvent = $this->makeEvent('Porch night', $organizer, 1);
      EventEnrollment::create([
        'user_id' => $organizer,
        'status' => 1,
        'field_event' => $this->enrolledEvent->id(),
        'field_account' => $this->memberId(),
        'field_enrollment_status' => 1,
      ])->save();
    }
    return $this->enrolledEvent;
  }

  /**
   * An upcoming public event with enrollment open.
   *
   * @param int $method
   *   The enroll method: 1 joins at once, 2 asks the organizer.
   */
  private function makeEvent(string $title, int $owner, int $method): Node {
    $start = new \DateTimeImmutable('+30 days', new \DateTimeZone('UTC'));
    $event = Node::create([
      'type' => 'event',
      'title' => $title,
      'uid' => $owner,
      'status' => NodeInterface::PUBLISHED,
      'field_event_date' => $start->format('Y-m-d\TH:i:s'),
      'field_event_date_end' => $start->modify('+2 hours')->format('Y-m-d\TH:i:s'),
      'field_content_visibility' => 'public',
      'field_event_enroll' => 1,
      'field_enroll_method' => $method,
    ]);
    $event->save();
    return $event;
  }

  /**
   * The id of a pack account.
   */
  private function userId(string $uuid): int {
    $account = $this->container->get('entity.repository')->loadEntityByUuid('user', $uuid);
    $this->assertInstanceOf(UserInterface::class, $account, 'The pack account exists.');
    return (int) $account->id();
  }

  /**
   * The group's quick polls, with the open poll unanswered by the member.
   *
   * The smoke pack has the member answer both of its polls, and a member who
   * has answered no longer sees Answer. The test removes that answer in
   * storage before it opens the screen; members themselves cannot.
   */
  private function quickPollsUrl(): Url {
    $repository = $this->container->get('entity.repository');
    $poll = $repository->loadEntityByUuid('poll', self::OPEN_POLL);
    $this->assertNotNull($poll, 'The open smoke poll exists.');
    $member = $repository->loadEntityByUuid('user', self::MEMBER_UUID);
    $this->container->get('poll_vote.storage')->cancelVote($poll, $member);
    Cache::invalidateTags($poll->getCacheTags());
    Cache::invalidateTags(['poll-votes:' . $poll->id()]);
    return Url::fromUserInput('/group/' . $this->groupId . '/quick-polls');
  }

  /**
   * The account a row's `as` names, with a password made up for this test.
   */
  private function accountFor(string $as): UserInterface {
    $uuid = match ($as) {
      // smoke_two belongs to the smoke group; smoke_out belongs to none.
      'member' => self::MEMBER_UUID,
      'non_member' => '5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d03',
      'organizer' => self::ORGANIZER_UUID,
      default => NULL,
    };
    if ($uuid === NULL) {
      $account = $this->drupalCreateUser();
      $account->addRole($as);
    }
    else {
      $account = $this->container->get('entity.repository')->loadEntityByUuid('user', $uuid);
    }
    $this->assertInstanceOf(UserInterface::class, $account, "The account for $as exists.");
    $password = bin2hex(random_bytes(12));
    $account->setPassword($password)->save();
    $account->passRaw = $password;
    return $account;
  }

}
