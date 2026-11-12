<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_quick_poll\Functional;

use Drupal\Core\Test\AssertMailTrait;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\poll\PollInterface;
use Drupal\search_api\Entity\Server;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;

/**
 * Quick polls are seen and answered by their group's members only.
 *
 * A member asks a quick question in a members-only group; another member
 * answers it once. A signed-in outsider and a signed-out visitor get no
 * page and cannot answer, a member who leaves loses access at once, and the
 * poll module's own add, list and "delete vote" pages are closed. No page a
 * member sees says "vote", "decision" or "proposal", and asking or
 * answering sends no mail and writes no activity.
 *
 * Why Functional: access, page caching and the poll form need the social
 * profile and a real request cycle. localnodes_platform itself is not
 * installed (it pulls in the AI stack); this module is.
 *
 * @group localnodes_platform
 * @group phase22_functional
 */
final class QuickPollAccessTest extends BrowserTestBase {

  use AssertMailTrait;

  /**
   * {@inheritdoc}
   */
  protected $profile = 'social';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'socialblue';

  /**
   * {@inheritdoc}
   *
   * Open Social's shipped config carries third-party settings from modules
   * this test doesn't enable.
   */
  protected $strictConfigSchema = FALSE;

  private const QUESTION = 'Which evening suits the garden workday?';

  /**
   * Words a quick poll must never show, matched case-insensitively.
   */
  private const FORBIDDEN_WORDS = '/\b(vot(e|es|ed|ing)|decision|proposal)\b/i';

  /**
   * The group member who asks the question.
   */
  private UserInterface $asker;

  /**
   * Another group member, who answers.
   */
  private UserInterface $member;

  /**
   * A signed-in account outside the group.
   */
  private UserInterface $outsider;

  /**
   * The members-only group the question is asked in.
   */
  private GroupInterface $group;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // The install's final cron run can reach search_api_solr_cron(), whose
    // request to Solr prints its response; PHPUnit would fail the test for
    // unexpected output.
    ob_start();
    try {
      parent::setUp();
    }
    finally {
      ob_end_clean();
    }

    // Keep the test's content out of the development Solr core.
    $solr = Server::load('social_solr');
    if ($solr !== NULL) {
      $backend_config = $solr->getBackendConfig();
      $backend_config['connector_config']['host'] = 'solr.invalid';
      $solr->setBackendConfig($backend_config)->save();
    }

    $this->container->get('module_installer')->install(['localnodes_quick_poll']);
    $this->rebuildContainer();
    // A page request rebuilds the router when it ends, after the install has
    // disabled the poll module's /polls view; this process has no such end.
    $this->container->get('router.builder')->rebuild();
    // Saving a group renders activity messages, which needs every module's
    // code loaded in this process, as a page request would have it.
    $this->container->get('module_handler')->loadAll();

    $organizer = $this->createAccount('poll_organizer');
    $this->asker = $this->createAccount('poll_asker');
    $this->member = $this->createAccount('poll_member');
    $this->outsider = $this->createAccount('poll_outsider');

    $this->group = Group::create([
      'type' => 'flexible_group',
      'label' => 'Juniper House',
      'uid' => $organizer->id(),
      'field_flexible_group_visibility' => 'members',
      'field_group_allowed_visibility' => ['group'],
      'field_group_allowed_join_method' => 'added',
    ]);
    $this->group->save();
    $this->group->addMember($this->asker);
    $this->group->addMember($this->member);
  }

  /**
   * A member asks, another answers once, and both see the answers.
   */
  public function testMemberAsksAndAnswers(): void {
    $mails_before = count($this->getMails());
    $activities_before = $this->countActivities();

    $this->drupalLogin($this->asker);
    $list = '/group/' . $this->group->id() . '/quick-polls';
    $this->drupalGet('/group/' . $this->group->id() . '/events');
    $this->assertSession()->linkByHrefExists($list);
    $this->drupalGet($list);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('No quick polls yet.');
    $this->assertNoForbiddenWords();
    $this->clickLink('Ask a quick question');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Only members of Juniper House can see this question');
    $this->assertNoForbiddenWords();
    $this->submitForm([
      'question[0][value]' => self::QUESTION,
      'choice[0][choice]' => 'Tuesday',
      'choice[1][choice]' => 'Thursday',
    ], 'Ask');
    $this->assertSession()->addressEquals($list);
    $this->assertSession()->pageTextContains(self::QUESTION);
    $this->assertNoForbiddenWords();

    $poll = $this->loadPoll();
    $this->assertSame((int) $this->group->id(), (int) $poll->get('quick_poll_group')->target_id);
    $this->assertFalse((bool) $poll->getAnonymousVoteAllow());
    $this->assertFalse((bool) $poll->getCancelVoteAllow());
    $this->assertTrue((bool) $poll->getResultVoteAllow());

    $this->drupalLogin($this->member);
    $this->drupalGet('/poll/' . $poll->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertNoForbiddenWords();
    $this->submitForm(['choice' => $this->choiceId($poll, 'Thursday')], 'Answer');
    $this->assertSession()->pageTextContains('Your answer has been saved.');
    $this->assertSession()->pageTextContains('1 answer');
    $this->assertNoForbiddenWords();
    // Each member answers once: no button to answer again or take it back.
    $this->assertSession()->buttonNotExists('Answer');
    $this->assertSession()->buttonNotExists('Take back my answer');
    $this->drupalGet('/poll/' . $poll->id() . '/delete/vote/' . $this->member->id());
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSame(1, $this->countAnswers($poll));
    // The answer is kept with the account only: no internet address.
    $this->assertSame([''], $this->container->get('database')->select('poll_vote', 'v')
      ->fields('v', ['hostname'])
      ->condition('pid', $poll->id())
      ->condition('uid', $this->member->id())
      ->execute()->fetchCol());

    // The asker sees the answer on the group's page.
    $this->drupalLogin($this->asker);
    $this->drupalGet($list);
    $this->assertSession()->pageTextContains(self::QUESTION);
    $this->assertSession()->buttonExists('See answers so far');

    $this->assertCount($mails_before, $this->getMails(), 'Asking and answering a quick poll sends no mail.');
    $this->assertSame($activities_before, $this->countActivities(), 'Asking and answering a quick poll writes no activity.');
  }

  /**
   * People outside the group get no page and cannot answer.
   */
  public function testOutsidersCannotSeeOrAnswer(): void {
    $poll = $this->createPoll();
    $paths = [
      '/poll/' . $poll->id(),
      '/group/' . $this->group->id() . '/quick-polls',
      '/group/' . $this->group->id() . '/quick-polls/add',
    ];

    $this->drupalLogin($this->outsider);
    foreach ($paths as $path) {
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(403);
      $this->assertSession()->pageTextNotContains(self::QUESTION);
    }
    // An answer posted straight to the poll's address is refused too.
    $response = $this->getHttpClient()->post($this->buildUrl('/poll/' . $poll->id()), [
      'form_params' => [
        'choice' => $this->choiceId($poll, 'Tuesday'),
        'op' => 'Answer',
        'form_id' => 'poll_view_form_' . $poll->id(),
      ],
      'cookies' => $this->getSessionCookies(),
      'http_errors' => FALSE,
    ]);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame(0, $this->countAnswers($poll));

    $this->drupalLogout();
    foreach ($paths as $path) {
      $this->drupalGet($path);
      $this->assertSession()->pageTextNotContains(self::QUESTION);
      $refused = $this->getSession()->getStatusCode() === 403
        || str_contains($this->getSession()->getCurrentUrl(), '/user/login');
      $this->assertTrue($refused, "A signed-out visitor is refused or sent to sign in at $path.");
    }
  }

  /**
   * A member who leaves the group loses access at once.
   */
  public function testLeaverLosesAccess(): void {
    $poll = $this->createPoll();
    $paths = [
      '/poll/' . $poll->id(),
      '/group/' . $this->group->id() . '/quick-polls',
    ];

    $this->drupalLogin($this->member);
    foreach ($paths as $path) {
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->pageTextContains(self::QUESTION);
    }

    $this->group->removeMember($this->member);

    // The same addresses straight after, so a cached page would show here.
    foreach ($paths as $path) {
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(403);
      $this->assertSession()->pageTextNotContains(self::QUESTION);
    }
  }

  /**
   * The poll module's own pages stay with site administrators.
   */
  public function testPollModulePagesClosed(): void {
    $poll = $this->createPoll();
    $this->drupalLogin($this->asker);
    $this->drupalGet('/poll/add');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('/poll/' . $poll->id() . '/edit');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('/poll/' . $poll->id() . '/delete');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('/polls');
    $this->assertSession()->statusCodeEquals(404);
    $this->drupalGet('/admin/content/poll');
    $this->assertSession()->statusCodeEquals(403);

    // A poll outside any group is for administrators only.
    $loose = $this->container->get('entity_type.manager')->getStorage('poll')->create([
      'question' => 'Loose question',
      'uid' => $this->asker->id(),
      'choice' => [['entity' => $this->createChoice('One')], ['entity' => $this->createChoice('Two')]],
    ]);
    $loose->save();
    $this->drupalGet('/poll/' . $loose->id());
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * The poll module's "Most recent poll" block shows no group's question.
   *
   * The block picks the newest poll on the whole site and renders it without
   * asking whether the reader may see it.
   */
  public function testRecentPollBlockClosed(): void {
    $this->drupalPlaceBlock('poll_recent_block', ['region' => 'content']);
    $this->createPoll();
    foreach ([$this->outsider, $this->member] as $account) {
      $this->drupalLogin($account);
      $this->drupalGet('/user/' . $account->id());
      $this->assertSession()->pageTextNotContains(self::QUESTION);
    }
  }

  /**
   * Another group may ask the same question; nobody learns it was asked.
   *
   * The poll module turns down a question already asked anywhere on the
   * site, and its message repeats the question, so a member of one group
   * could test for another group's private questions.
   */
  public function testSameQuestionInAnotherGroup(): void {
    $this->createPoll();
    $other = Group::create([
      'type' => 'flexible_group',
      'label' => 'Cedar Row',
      'uid' => $this->outsider->id(),
      'field_flexible_group_visibility' => 'members',
      'field_group_allowed_visibility' => ['group'],
      'field_group_allowed_join_method' => 'added',
    ]);
    $other->save();
    $other->addMember($this->outsider);

    $this->drupalLogin($this->outsider);
    $this->drupalGet('/group/' . $other->id() . '/quick-polls/add');
    $this->submitForm([
      'question[0][value]' => self::QUESTION,
      'choice[0][choice]' => 'Monday',
      'choice[1][choice]' => 'Friday',
    ], 'Ask');
    $this->assertSession()->addressEquals('/group/' . $other->id() . '/quick-polls');
    $this->assertSession()->pageTextNotContains('already exists');
    $this->assertSession()->pageTextContains(self::QUESTION);
  }

  /**
   * A member who looks at the answers first can still go back and answer.
   */
  public function testBackToQuestionAfterLooking(): void {
    $poll = $this->createPoll();
    $this->drupalLogin($this->member);
    $this->drupalGet('/poll/' . $poll->id());
    $this->submitForm([], 'See answers so far');
    $this->assertSession()->buttonNotExists('Answer');
    $this->assertNoForbiddenWords();
    $this->submitForm([], 'Back to the question');
    $this->submitForm(['choice' => $this->choiceId($poll, 'Tuesday')], 'Answer');
    $this->assertSession()->pageTextContains('Your answer has been saved.');
    $this->assertSame(1, $this->countAnswers($poll));
  }

  /**
   * A second answer form shown before the first answer saves nothing.
   *
   * A form left open in another tab, or reached again through "Back to the
   * question", is still sent after the member has answered.
   */
  public function testSecondFormAfterAnsweringSavesNothing(): void {
    $poll = $this->createPoll();
    $this->drupalLogin($this->member);
    $this->drupalGet('/poll/' . $poll->id());
    $this->submitForm([], 'See answers so far');
    $this->submitForm([], 'Back to the question');
    // Keep this answer form, as a second tab would.
    $form = $this->getSession()->getPage()->find('css', 'form.poll-view-form, form[id^="poll-view-form"]');
    $this->assertNotNull($form);
    $action = $form->getAttribute('action');
    $hidden = [];
    foreach ($form->findAll('css', 'input[type="hidden"]') as $input) {
      $hidden[$input->getAttribute('name')] = $input->getAttribute('value');
    }

    // Answer through another copy of the form. Drupal forgets a cached form
    // once it is sent, so the kept one must be a different copy.
    $this->drupalGet('/poll/' . $poll->id());
    $this->submitForm(['choice' => $this->choiceId($poll, 'Tuesday')], 'Answer');
    $this->assertSession()->pageTextContains('Your answer has been saved.');

    // Send the kept form, choosing the other answer.
    $this->getSession()->getDriver()->getClient()->request('POST', $this->getAbsoluteUrl($action), $hidden + [
      'choice' => $this->choiceId($poll, 'Thursday'),
      'op' => 'Answer',
    ]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('You have already answered this question.');
    $this->assertSession()->pageTextNotContains('Your answer has been saved.');

    $rows = $this->container->get('database')->select('poll_vote', 'v')
      ->fields('v', ['chid', 'hostname'])
      ->condition('pid', $poll->id())
      ->execute()->fetchAll(\PDO::FETCH_ASSOC);
    $this->assertSame([['chid' => $this->choiceId($poll, 'Tuesday'), 'hostname' => '']], array_map(fn ($row) => array_map('strval', $row), $rows), 'One answer, the first, with no address.');
  }

  /**
   * No answer row ever holds a signed-in member's address, even for a moment.
   *
   * The database itself refuses to store an address with a member's answer,
   * and refuses any later change to an answer, so a save that wrote the
   * address and blanked it afterwards fails here, and a failed blanking
   * cannot leave the address behind.
   */
  public function testAnswerNeverWritesAnAddress(): void {
    $poll = $this->createPoll();
    $database = $this->container->get('database');
    $table = $database->getPrefix() . 'poll_vote';
    // Drupal's query layer takes one statement without semicolons; a
    // trigger body needs them, so it goes to the connection directly.
    $pdo = $database->getClientConnection();
    $pdo->exec("CREATE TRIGGER {$table}_no_address BEFORE INSERT ON {$table} FOR EACH ROW BEGIN IF NEW.uid > 0 AND NEW.hostname <> '' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'address stored with a member answer'; END IF; END");
    $pdo->exec("CREATE TRIGGER {$table}_no_change BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'answer changed after saving'");
    try {
      $this->drupalLogin($this->member);
      $this->drupalGet('/poll/' . $poll->id());
      $this->submitForm(['choice' => $this->choiceId($poll, 'Thursday')], 'Answer');
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->pageTextContains('Your answer has been saved.');
      $this->assertSame([''], $database->select('poll_vote', 'v')
        ->fields('v', ['hostname'])
        ->condition('pid', $poll->id())
        ->execute()->fetchCol());
    }
    finally {
      $pdo->exec("DROP TRIGGER IF EXISTS {$table}_no_address");
      $pdo->exec("DROP TRIGGER IF EXISTS {$table}_no_change");
    }
  }

  /**
   * Fails if the current page shows a forbidden word to a reader.
   *
   * Checks the text and the attributes people read or hear: button values,
   * titles, labels for screen readers, placeholders and image alt text.
   * Class names and form ids are not shown, so they are not checked.
   */
  private function assertNoForbiddenWords(): void {
    $xpath = new \DOMXPath($this->domDocument());
    $parts = [];
    foreach ($xpath->query('//body//text()[not(ancestor::script) and not(ancestor::style)]') as $node) {
      $parts[] = $node->nodeValue;
    }
    foreach ($xpath->query('//@value[parent::input[@type="submit" or @type="button"] or parent::button] | //@title | //@aria-label | //@placeholder | //@alt') as $node) {
      $parts[] = $node->nodeValue;
    }
    foreach ($xpath->query('//head/title') as $node) {
      $parts[] = $node->nodeValue;
    }
    $text = implode("\n", $parts);
    $this->assertDoesNotMatchRegularExpression(self::FORBIDDEN_WORDS, $text, 'Page ' . $this->getSession()->getCurrentUrl() . ' shows a word a quick poll must not use.');
  }

  /**
   * Parses the current page.
   */
  private function domDocument(): \DOMDocument {
    $document = new \DOMDocument();
    libxml_use_internal_errors(TRUE);
    $document->loadHTML($this->getSession()->getPage()->getContent());
    libxml_clear_errors();
    return $document;
  }

  /**
   * Creates a quick poll in the group, asked by the asker.
   */
  private function createPoll(): PollInterface {
    $poll = $this->container->get('entity_type.manager')->getStorage('poll')->create([
      'question' => self::QUESTION,
      'uid' => $this->asker->id(),
      'quick_poll_group' => $this->group->id(),
      'choice' => [['entity' => $this->createChoice('Tuesday')], ['entity' => $this->createChoice('Thursday')]],
    ]);
    $poll->save();
    return $poll;
  }

  /**
   * Creates an unsaved poll choice.
   */
  private function createChoice(string $label) {
    return $this->container->get('entity_type.manager')->getStorage('poll_choice')->create(['choice' => $label]);
  }

  /**
   * Loads the poll asked through the form.
   */
  private function loadPoll(): PollInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('poll');
    $storage->resetCache();
    $polls = $storage->loadByProperties(['question' => self::QUESTION]);
    $this->assertCount(1, $polls);
    return reset($polls);
  }

  /**
   * The id of one of a poll's choices.
   */
  private function choiceId(PollInterface $poll, string $label): string {
    foreach ($poll->get('choice')->referencedEntities() as $choice) {
      if ($choice->choice->value === $label) {
        return (string) $choice->id();
      }
    }
    $this->fail("No choice $label.");
  }

  /**
   * How many answers a poll has.
   */
  private function countAnswers(PollInterface $poll): int {
    return (int) $this->container->get('database')->select('poll_vote')
      ->condition('pid', $poll->id())
      ->countQuery()->execute()->fetchField();
  }

  /**
   * How many activity records the site holds.
   */
  private function countActivities(): int {
    return (int) $this->container->get('entity_type.manager')->getStorage('activity')
      ->getQuery()->accessCheck(FALSE)->count()->execute();
  }

  /**
   * Creates a verified account.
   */
  private function createAccount(string $name): UserInterface {
    $account = $this->createUser([], $name, FALSE, ['roles' => ['verified']]);
    $this->assertInstanceOf(UserInterface::class, $account);
    return $account;
  }

}
