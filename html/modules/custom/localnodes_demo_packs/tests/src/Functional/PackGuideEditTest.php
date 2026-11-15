<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Functional;

use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\group\Entity\Group;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\localnodes_demo_packs\Traits\PackLoadTrait;
use Drupal\Tests\localnodes_demo_packs\Traits\PackTestTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Who may change a Site guide page, and what the edit form tells them.
 *
 * The assistant answers "how do I" questions from Site guide pages, so a page
 * that anyone can rewrite, or that a member can mark as the site's own, would
 * let one member change what the assistant tells everybody. Every test loads
 * the smoke pack with the how-to pages and then uninstalls the loader and the
 * demo module, as the entrypoint does after a load, so what is checked is the
 * rule a running site keeps and not a rule that lives in the loader.
 *
 * @group localnodes_demo_packs
 * @group phase22_functional
 */
final class PackGuideEditTest extends BrowserTestBase {

  use PackLoadTrait;
  use PackTestTrait;

  protected $profile = 'social';

  protected $defaultTheme = 'socialblue';

  protected $strictConfigSchema = FALSE;

  /**
   * A member of the smoke pack, who stands for any signed-in member.
   */
  private const MEMBER = '5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d02';

  /**
   * What the edit form of a Site guide page says, word for word.
   */
  private const NOTE = "The assistant answers 'how do I' questions from this page. If you change it, its answers change.";

  /**
   * The roles that may edit a Site guide page.
   */
  private const EDITOR_ROLES = ['sitemanager', 'contentmanager'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->setUpPackSite();
    // A visitor's first response sets a session cookie and the browser output
    // files record response headers, so no request in this class writes one.
    $this->htmlOutputEnabled = FALSE;
  }

  /**
   * Loads smoke with the how-to pages, then removes the loader as a deploy does.
   *
   * @return array
   *   What the tests need from before the uninstall: the first how-to page's
   *   node id (page P02, the second), its title, the guide account's and the
   *   member's user ids, the Site guide term id and a topic type that is not
   *   Site guide.
   */
  private function loadThenUninstall(): array {
    foreach (self::EDITOR_ROLES as $role) {
      $this->assertFalse(Role::load($role)->hasPermission('edit any topic content'), "Before the load, $role holds no edit-any-topic permission.");
    }

    $this->loadPack('smoke');
    $pages = self::entries(self::sharedHowto());
    $this->assertGreaterThanOrEqual(2, count($pages));
    [$uuid, $data] = $pages[1];
    $repository = $this->container->get('entity.repository');
    $page = $repository->loadEntityByUuid('node', $uuid);
    $this->assertInstanceOf(NodeInterface::class, $page, 'The second how-to page loaded.');
    $guide = $repository->loadEntityByUuid('user', 'aab4c2a4-55c4-46b4-bca9-b3e2b05f58b0');
    $this->assertInstanceOf(UserInterface::class, $guide, 'The guide account loaded.');
    $member = $repository->loadEntityByUuid('user', self::MEMBER);
    $this->assertInstanceOf(UserInterface::class, $member, 'The smoke member loaded.');
    $term = $repository->loadEntityByUuid('taxonomy_term', '06b1bc65-e2c1-4831-bc68-d6ada5c4dd21');
    $this->assertInstanceOf(Term::class, $term, 'The Site guide topic type exists.');
    $context = [
      'nid' => (int) $page->id(),
      'title' => (string) $data['title'],
      'guide_uid' => (int) $guide->id(),
      'member_uid' => (int) $member->id(),
      'term' => (int) $term->id(),
      'other_term' => 0,
    ];
    foreach (array_keys($this->allowedTopicTypes()) as $tid) {
      if ($tid !== $context['term']) {
        $context['other_term'] = $tid;
        break;
      }
    }
    $this->assertGreaterThan(0, $context['other_term'], 'The topic type field offers a type besides Site guide.');

    // The entrypoint's step after a load.
    $installer = $this->container->get('module_installer');
    $installer->uninstall(['localnodes_demo_packs', 'social_demo']);
    $this->rebuildAll();
    $handler = $this->container->get('module_handler');
    $this->assertFalse($handler->moduleExists('localnodes_demo_packs'), 'The loader module is uninstalled.');
    $this->assertFalse($handler->moduleExists('social_demo'), 'The demo module is uninstalled.');
    $this->resetEntityCaches();
    $this->assertNotNull($this->container->get('entity.repository')->loadEntityByUuid('node', $uuid), 'The how-to page stays after the uninstall.');
    foreach (self::EDITOR_ROLES as $role) {
      $this->assertFalse(Role::load($role)->hasPermission('edit any topic content'), "After the load, $role holds no edit-any-topic permission.");
    }
    return $context;
  }

  /**
   * An account with a password that exists only in this test.
   */
  private function account(array $roles): UserInterface {
    $account = $this->drupalCreateUser([]);
    foreach ($roles as $role) {
      $account->addRole($role);
    }
    $account->save();
    return $account;
  }

  /**
   * The smoke member, with a password of the test's own.
   */
  private function member(array $context): UserInterface {
    $member = User::load($context['member_uid']);
    $password = bin2hex(random_bytes(12));
    $member->setPassword($password)->save();
    $member->passRaw = $password;
    return $member;
  }

  /**
   * Runs a callback as the site's first account, as the pack loader does.
   */
  private function asAdmin(callable $callback): mixed {
    $this->container->get('request_stack')->push(Request::create('/'));
    $this->container->get('current_user')->setAccount(User::load(1));
    try {
      return $callback();
    }
    finally {
      $this->container->get('current_user')->setAccount(User::getAnonymousUser());
      $this->container->get('request_stack')->pop();
    }
  }

  /**
   * A topic of the given owner, published and visible to members.
   */
  private function topic(string $title, int $uid, array $term_ids, array $extra = []): NodeInterface {
    return $this->asAdmin(function () use ($title, $uid, $term_ids, $extra): NodeInterface {
      $node = Node::create($extra + [
        'type' => 'topic',
        'title' => $title,
        'uid' => $uid,
        'status' => NodeInterface::PUBLISHED,
        'field_content_visibility' => 'community',
        'field_topic_type' => array_map(static fn (int $tid): array => ['target_id' => $tid], $term_ids),
        'body' => ['value' => '<p>Text of the page.</p>', 'format' => 'basic_html'],
      ]);
      $node->save();
      return $node;
    });
  }

  /**
   * A flexible group with the given visibility.
   */
  private function house(string $label, string $visibility): Group {
    return $this->asAdmin(function () use ($label, $visibility): Group {
      $group = Group::create([
        'type' => 'flexible_group',
        'label' => $label,
        'uid' => 1,
        'field_flexible_group_visibility' => $visibility,
      ]);
      $group->save();
      return $group;
    });
  }

  /**
   * Puts a topic in a group.
   */
  private function place(Group $group, NodeInterface $node): void {
    $this->asAdmin(static function () use ($group, $node): void {
      $group->addRelationship($node, 'group_node:topic');
    });
  }

  /**
   * The topic types the field offers: every term of its allowed vocabularies.
   *
   * Read from the field's own settings, so the answer does not depend on the
   * rule under test.
   *
   * @return array<int, int>
   *   Term ids, as keys and values.
   */
  private function allowedTopicTypes(): array {
    $field = FieldConfig::loadByName('node', 'topic', 'field_topic_type');
    $this->assertNotNull($field, 'The topic type field exists.');
    $bundles = array_keys($field->getSetting('handler_settings')['target_bundles'] ?? []);
    $this->assertNotEmpty($bundles, 'The field names the vocabularies it offers.');
    $ids = $this->container->get('entity_type.manager')->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', $bundles, 'IN')
      ->execute();
    $ids = array_map('intval', array_values($ids));
    sort($ids);
    return array_combine($ids, $ids);
  }

  /**
   * The topic types the page's form offers, by term id.
   *
   * @return int[]
   */
  private function offered(): array {
    $ids = [];
    foreach ($this->getSession()->getPage()->findAll('css', 'input[type="checkbox"]') as $box) {
      if (preg_match('/^field_topic_type\[(\d+)\]$/', (string) $box->getAttribute('name'), $match)) {
        $ids[] = (int) $match[1];
      }
    }
    sort($ids);
    return $ids;
  }

  /**
   * How many times the edit-form line is on the page, as text and as markup.
   *
   * @return int[]
   *   The line's occurrences in the page text, and the notes in the markup.
   */
  private function noteCount(): array {
    $page = $this->getSession()->getPage();
    return [
      substr_count($page->getText(), self::NOTE),
      count($page->findAll('css', 'p.localnodes-guide-note')),
    ];
  }

  /**
   * The status code of a page, requested as the signed-in account.
   */
  private function status(string $path): int {
    $this->drupalGet($path);
    return (int) $this->getSession()->getStatusCode();
  }

  /**
   * Submits the topic form as an account, as the form system itself would.
   *
   * The browser helper refuses a value the form does not offer, so a forged
   * value is sent through the form builder. The form is bound to a fresh,
   * unsaved topic, and the values take the shape the topic type widget posts.
   *
   * @param int[] $term_ids
   *   The topic types to submit.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The form state after validation and submission.
   */
  private function submitTopicForm(AccountInterface $account, string $title, array $term_ids): FormStateInterface {
    $this->container->get('request_stack')->push(Request::create('/'));
    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($account);
    // Building the topic form without an upload makes the file widgets read a
    // first upload row that a programmed submit does not create, and PHPUnit
    // turns that warning into a failure. Only that warning is let through.
    $previous = NULL;
    $previous = set_error_handler(static function (int $number, string $message, string $file, int $line) use (&$previous): bool {
      if (str_ends_with($file, '/FileWidget.php')) {
        return TRUE;
      }
      return $previous !== NULL && (bool) $previous($number, $message, $file, $line);
    }, E_WARNING);
    try {
      $form_object = $this->container->get('entity_type.manager')->getFormObject('node', 'default');
      $form_object->setEntity(Node::create(['type' => 'topic', 'uid' => $account->id()]));
      $form_state = new FormState();
      $form_state->setValues([
        'title' => [['value' => $title]],
        'field_topic_type' => array_combine($term_ids, $term_ids),
        'body' => [['value' => '<p>Text of the page.</p>', 'format' => 'basic_html']],
        'field_content_visibility' => 'community',
        'op' => 'Create topic',
      ]);
      $this->container->get('form_builder')->submitForm($form_object, $form_state);
    }
    finally {
      restore_error_handler();
      $switcher->switchBack();
      $this->container->get('request_stack')->pop();
    }
    return $form_state;
  }

  /**
   * Nodes with a title, read from the database.
   */
  private function nodesTitled(string $title): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    return $storage->loadByProperties(['title' => $title]);
  }

  /**
   * Only the roles that may edit a guide page reach its form, and it warns them.
   */
  public function testOnlyEditorsEditGuidePagesAndAreTold(): void {
    $context = $this->loadThenUninstall();
    $nid = $context['nid'];
    $guide = $context['term'];
    $member = $this->member($context);
    $organizer = $this->account(['verified', 'sitemanager']);
    $manager = $this->account(['verified', 'contentmanager']);
    $own_topic = $this->topic('A member topic about the garden', $context['member_uid'], [$context['other_term']]);
    $allowed = array_values($this->allowedTopicTypes());
    $this->assertContains($guide, $allowed, 'The field offers the Site guide type.');

    // A member reads a guide page but cannot change it. The read comes first,
    // so the refusal below is about editing.
    $this->drupalLogin($member);
    $this->assertSame(200, $this->status("/node/$nid"), 'A member reads the guide page.');
    $this->assertSame(403, $this->status("/node/$nid/edit"), 'A member cannot edit the guide page.');

    // The member's own topic: the edit form opens and carries no line.
    $this->assertSame(200, $this->status('/node/' . $own_topic->id() . '/edit'), 'A member edits their own topic.');
    $this->assertSame([0, 0], $this->noteCount(), "A member's own topic form carries no line.");
    $this->assertSame(array_values(array_diff($allowed, [$guide])), $this->offered(), "A member's own topic form offers every type but Site guide.");

    // The add form: no line, and no Site guide option for a member.
    $this->assertSame(200, $this->status('/node/add/topic'));
    $this->assertSame([0, 0], $this->noteCount(), 'The add form carries no line.');
    $this->assertSame(array_values(array_diff($allowed, [$guide])), $this->offered(), "A member's add form offers every type but Site guide.");
    $this->assertNotEmpty($this->offered(), 'The member form does offer other types.');

    // The two editing roles open the form and read the line, once, above the
    // title.
    foreach ([$organizer, $manager] as $account) {
      $this->drupalLogin($account);
      $role = implode(',', array_intersect($account->getRoles(), self::EDITOR_ROLES));
      $this->assertSame(200, $this->status("/node/$nid"), "$role reads the guide page.");
      $this->assertSame(200, $this->status("/node/$nid/edit"), "$role edits the guide page.");
      $this->assertSame([1, 1], $this->noteCount(), "$role sees the line once on the guide page's form.");
      $this->assertNotNull(
        $this->getSession()->getPage()->find('xpath', '//p[contains(concat(" ", normalize-space(@class), " "), " localnodes-guide-note ")]/following::input[@name="title[0][value]"]'),
        "$role sees the line above the title."
      );
      $this->assertSame($allowed, $this->offered(), "$role's form for the guide page offers every type, Site guide included.");
      $this->assertTrue($this->getSession()->getPage()->findField("field_topic_type[$guide]")->isChecked(), 'The guide page holds the Site guide type.');
    }

    // A site manager's add form offers Site guide and carries no line.
    $this->drupalLogin($organizer);
    $this->assertSame(200, $this->status('/node/add/topic'));
    $this->assertSame($allowed, $this->offered(), "A site manager's add form offers every type, Site guide included.");
    $this->assertSame([0, 0], $this->noteCount());

    // Saving the page without touching its type keeps Site guide, and the
    // change itself is saved.
    $edited_title = $context['title'] . ' (revised)';
    $edited_body = '<p>The organizer revised this page after it loaded.</p>';
    $this->drupalGet("/node/$nid/edit");
    $this->submitForm(['title[0][value]' => $edited_title, 'body[0][value]' => $edited_body], 'Save');
    $this->resetEntityCaches();
    $saved = Node::load($nid);
    $this->assertSame($edited_title, $saved->label(), "The site manager's title change saved.");
    $this->assertStringContainsString('The organizer revised this page', $this->fieldText($saved, 'body'), "The site manager's body change saved.");
    $this->assertSame([$guide], array_map('intval', array_column($saved->get('field_topic_type')->getValue(), 'target_id')), 'The page is still a Site guide page.');

    // The note on the saved page is still there, so a second edit is warned too.
    $this->assertSame(200, $this->status("/node/$nid/edit"));
    $this->assertSame([1, 1], $this->noteCount(), 'The line shows again on the next edit.');
  }

  /**
   * The grant stops at guide pages, at pages a person can see, and at roles.
   */
  public function testGrantStopsAtGuidePagesAndAtWhatIsVisible(): void {
    $context = $this->loadThenUninstall();
    $guide = $context['term'];
    $editors = [
      'sitemanager' => $this->account(['verified', 'sitemanager']),
      'contentmanager' => $this->account(['verified', 'contentmanager']),
    ];

    // A topic that is not a guide page: an editor reads it and cannot edit it.
    $plain = $this->topic('A member topic about the compost', $context['member_uid'], [$context['other_term']]);
    $plain_nid = (int) $plain->id();
    foreach ($editors as $role => $account) {
      $this->drupalLogin($account);
      $this->assertSame(200, $this->status("/node/$plain_nid"), "$role reads a member's topic.");
      $this->assertSame(403, $this->status("/node/$plain_nid/edit"), "$role cannot edit a member's topic.");
    }
    $this->drupalLogin($this->member($context));
    $this->assertSame(200, $this->status("/node/$plain_nid/edit"), 'The topic is editable by its owner, so the refusal above is the editors\'.');

    // Guide pages in a closed house and in a secret house, where nobody here is
    // a member: the editors cannot read or edit them.
    $closed = $this->house('Closed house for guide pages', 'community');
    $secret = $this->house('Secret house for guide pages', 'members');
    $in_closed = $this->topic('A guide page in a closed house', $context['guide_uid'], [$guide], ['field_content_visibility' => 'group']);
    $in_secret = $this->topic('A guide page in a secret house', $context['guide_uid'], [$guide]);
    $this->place($closed, $in_closed);
    $this->place($secret, $in_secret);
    $this->resetEntityCaches();
    foreach ([$in_closed, $in_secret] as $node) {
      $id = (int) $node->id();
      foreach ($editors as $role => $account) {
        $this->drupalLogin($account);
        $this->assertSame(403, $this->status("/node/$id"), "$role cannot read '{$node->label()}'.");
        $this->assertSame(403, $this->status("/node/$id/edit"), "$role cannot edit '{$node->label()}'.");
      }
    }

    // A member of the house with no site role reads its page and cannot edit
    // it: the grant belongs to the site roles and not to membership.
    $housemate = $this->account(['verified']);
    $this->asAdmin(function () use ($closed, $secret, $housemate): void {
      $closed->addMember($housemate);
      $secret->addMember($housemate);
    });
    $this->resetEntityCaches();
    $this->drupalLogin($housemate);
    foreach ([$in_closed, $in_secret] as $node) {
      $id = (int) $node->id();
      $this->assertSame(200, $this->status("/node/$id"), "A member of the house reads '{$node->label()}'.");
      $this->assertSame(403, $this->status("/node/$id/edit"), "A member of the house cannot edit '{$node->label()}'.");
    }

    // An unpublished guide page, in no group, that the editors cannot read:
    // the grant needs view access on its own.
    $draft = $this->topic('A guide page not yet published', $context['guide_uid'], [$guide], ['status' => NodeInterface::NOT_PUBLISHED]);
    $draft_id = (int) $draft->id();
    $this->resetEntityCaches();
    // The draft is readable by an account that bypasses node access, so the
    // editors' refusal below is about their own rights.
    $reader = $this->drupalCreateUser(['access content', 'bypass node access']);
    $this->assertTrue($draft->access('view', $reader), 'An account that bypasses node access reads this draft.');
    foreach ($editors as $role => $account) {
      foreach (['administer nodes', 'bypass node access'] as $permission) {
        $this->assertFalse($account->hasPermission($permission), "$role does not hold '$permission'.");
      }
      $this->assertNotSame((int) $account->id(), $context['guide_uid'], "$role does not own the draft.");
      $this->drupalLogin($account);
      $this->assertSame(403, $this->status("/node/$draft_id"), "$role cannot read the draft.");
      $this->assertSame(403, $this->status("/node/$draft_id/edit"), "$role cannot edit a draft it cannot read.");
    }
  }

  /**
   * A member who owns a page loses it once the page becomes a Site guide page.
   *
   * The roles that edit guide pages are the only ones that may change or
   * delete one, so an owner's own-content rights must not outlive the page
   * becoming a guide page.
   */
  public function testOwnerLosesControlWhenAPageBecomesASiteGuide(): void {
    $context = $this->loadThenUninstall();
    $guide = $context['term'];
    $member = $this->member($context);
    $organizer = $this->account(['verified', 'sitemanager']);
    $page = $this->topic('A member topic that becomes a guide page', $context['member_uid'], [$context['other_term']]);
    $page_id = (int) $page->id();
    $plain = $this->topic('A member topic that stays a plain topic', $context['member_uid'], [$context['other_term']]);
    $plain_id = (int) $plain->id();

    // Before: the owner edits and deletes their page. The same account is the
    // control for the refusals below.
    $this->drupalLogin($member);
    $this->assertSame(200, $this->status("/node/$page_id/edit"), 'The owner edits the page before it is a guide page.');
    $this->assertSame(200, $this->status("/node/$page_id/delete"), 'The owner reaches the delete form before the page is a guide page.');

    // An organizer retags it: the owner is unchanged.
    $this->asAdmin(function () use ($page, $guide): void {
      $page->set('field_topic_type', [['target_id' => $guide]]);
      $page->save();
    });
    $this->resetEntityCaches();
    $this->assertSame((int) $context['member_uid'], (int) Node::load($page_id)->getOwnerId(), 'The member still owns the page.');
    $this->assertTrue(\Drupal\localnodes_platform\SiteGuide::isGuide(Node::load($page_id)), 'The page is a guide page now.');

    $this->drupalLogin($member);
    $this->assertSame(200, $this->status("/node/$page_id"), 'The owner still reads the guide page.');
    $this->assertSame(403, $this->status("/node/$page_id/edit"), 'The owner cannot edit the page once it is a guide page.');
    $this->assertSame(403, $this->status("/node/$page_id/delete"), 'The owner cannot delete the page once it is a guide page.');
    $fresh = Node::load($page_id);
    $this->assertFalse($fresh->access('update', $member), 'Update is refused to the owner through the access API.');
    $this->assertFalse($fresh->access('delete', $member), 'Delete is refused to the owner through the access API.');
    $this->assertTrue($fresh->access('view', $member), 'Viewing is unchanged for the owner.');
    $this->assertTrue($fresh->access('view', $this->account(['verified'])), 'Viewing is unchanged for another member.');

    // Controls: the same member still controls a plain topic of their own, and
    // an editor still edits the guide page.
    $this->assertSame(200, $this->status("/node/$plain_id/edit"), "The member still edits a plain topic of their own.");
    $this->assertSame(200, $this->status("/node/$plain_id/delete"), 'The member still reaches the delete form of a plain topic.');
    $this->assertTrue(Node::load($plain_id)->access('update', $member), 'Update on a plain topic is still allowed to its owner.');
    $this->assertTrue(Node::load($plain_id)->access('delete', $member), 'Delete on a plain topic is still allowed to its owner.');
    $this->drupalLogin($organizer);
    $this->assertSame(200, $this->status("/node/$page_id/edit"), 'An editor still edits the guide page.');
  }

  /**
   * A member cannot mark a page as a guide page, even by sending the value.
   */
  public function testMemberCannotMarkAPageAsSiteGuide(): void {
    $context = $this->loadThenUninstall();
    $member = $this->member($context);
    $organizer = $this->account(['verified', 'sitemanager']);

    // The control first: with a topic type the member may choose, the same
    // submit saves a topic and the form state holds no error.
    $state = $this->submitTopicForm($member, 'A member topic sent through the form', [$context['other_term']]);
    $this->assertNotNull($state->getTriggeringElement(), 'The submit reached the form button.');
    $this->assertSame([], $state->getErrors(), 'The allowed value raises no error.');
    $saved = $this->nodesTitled('A member topic sent through the form');
    $this->assertCount(1, $saved, 'The allowed value saves a topic.');
    $this->assertSame([$context['other_term']], array_map('intval', array_column(reset($saved)->get('field_topic_type')->getValue(), 'target_id')));

    // The forged value: the Site guide type, which the form does not offer a
    // member.
    $state = $this->submitTopicForm($member, 'A member topic marked as the site guide', [$context['term']]);
    $this->assertNotNull($state->getTriggeringElement(), 'The forged submit reached the form button too.');
    $keys = array_keys($state->getErrors());
    $on_type = array_filter($keys, static fn ($key): bool => str_starts_with((string) $key, 'field_topic_type'));
    $this->assertNotEmpty($on_type, 'The form refuses the value on the topic type field (errors on: ' . implode(', ', $keys) . ').');
    $this->assertSame([], $this->nodesTitled('A member topic marked as the site guide'), 'No topic was saved with the forged value.');

    // A site manager may choose it, so the refusal is about who asks.
    $state = $this->submitTopicForm($organizer, 'A topic a site manager marked as the site guide', [$context['term']]);
    $this->assertNotNull($state->getTriggeringElement());
    $this->assertSame([], $state->getErrors(), 'A site manager may choose the Site guide type.');
    $saved = $this->nodesTitled('A topic a site manager marked as the site guide');
    $this->assertCount(1, $saved, "A site manager's Site guide topic saves.");
    $this->assertSame([$context['term']], array_map('intval', array_column(reset($saved)->get('field_topic_type')->getValue(), 'target_id')));
  }

  /**
   * Without a Site guide type, the rules do nothing.
   */
  public function testSiteWithoutTheSiteGuideTypeBehavesAsBefore(): void {
    $context = $this->loadThenUninstall();
    $term = Term::load($context['term']);
    $this->assertNotNull($term);
    $term->delete();
    $this->resetEntityCaches();
    $this->assertNull(Term::load($context['term']), 'The Site guide type is gone.');

    $organizer = $this->account(['verified', 'sitemanager']);
    $manager = $this->account(['verified', 'contentmanager']);
    $member = $this->member($context);
    $topic = $this->topic('A member topic with no type at all', $context['member_uid'], []);
    $allowed = array_values($this->allowedTopicTypes());
    $this->assertNotEmpty($allowed);
    $this->assertNotContains($context['term'], $allowed, 'The field no longer offers the deleted type.');

    foreach ([$organizer, $manager] as $account) {
      $this->drupalLogin($account);
      $role = implode(',', array_intersect($account->getRoles(), self::EDITOR_ROLES));
      $this->assertSame(200, $this->status('/node/' . $topic->id()), "$role reads the untyped topic.");
      $this->assertSame(403, $this->status('/node/' . $topic->id() . '/edit'), "$role cannot edit a topic that has no type.");
      // The page that used to carry the type is only a topic now.
      $this->assertSame(403, $this->status('/node/' . $context['nid'] . '/edit'), "$role cannot edit the old guide page.");
      $this->assertSame(200, $this->status('/node/add/topic'));
      $this->assertSame($allowed, $this->offered(), "$role's add form offers exactly the field's own types.");
      $this->assertSame([0, 0], $this->noteCount(), 'No form shows the line.');
    }

    $this->drupalLogin($member);
    $this->assertSame(200, $this->status('/node/' . $topic->id() . '/edit'), 'The owner edits the untyped topic.');
    $this->assertSame([0, 0], $this->noteCount(), "A member's topic form shows no line.");
    $this->assertSame($allowed, $this->offered(), "A member's edit form offers exactly the field's own types.");
    $this->assertSame(200, $this->status('/node/add/topic'));
    $this->assertSame($allowed, $this->offered(), "A member's add form offers exactly the field's own types.");
  }

}
