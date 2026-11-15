<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Functional;

use Drupal\localnodes_demo_packs\PackText;
use Drupal\localnodes_demo_packs\Plugin\DemoContent\PackHowto;
use Drupal\node\NodeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\localnodes_demo_packs\Traits\PackLoadTrait;
use Drupal\Tests\localnodes_demo_packs\Traits\PackTestTrait;
use Drupal\user\UserInterface;

/**
 * The how-to pages load as Site guide pages owned by the guide account.
 *
 * @group localnodes_demo_packs
 * @group phase22_functional
 */
final class PackHowtoTest extends BrowserTestBase {

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
   * The smoke pack's export owner, who is not the guide.
   */
  private const MEMBER_OWNER = '5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d01';

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
   * Each how-to page is a Site guide page the guide account owns.
   */
  public function testHowtoPagesAreGuidePages(): void {
    $this->loadPack('smoke');

    $pages = self::entries(self::sharedHowto());
    $this->assertNotEmpty($pages, 'The shared how-to file holds pages.');

    $manager = $this->container->get('entity_type.manager');
    $term = $this->container->get('entity.repository')->loadEntityByUuid('taxonomy_term', PackHowto::GUIDE_TERM_UUID);
    $this->assertNotNull($term, 'The Site guide topic type exists once the module is installed.');
    $this->assertSame('Site guide', $term->label());
    $this->assertSame('topic_types', $term->bundle());

    foreach ($pages as [$uuid, $data]) {
      $node = $this->entityByUuid($uuid);
      $this->assertInstanceOf(NodeInterface::class, $node, "How-to page {$data['title']} loaded.");
      $this->assertSame('topic', $node->bundle());
      $this->assertSame((string) $data['title'], $node->label());

      $owner = $node->getOwner();
      $this->assertSame(PackHowto::GUIDE_ACCOUNT_UUID, $owner->uuid(), "{$data['title']} is owned by the guide account.");
      $this->assertSame('site_guide', $owner->getAccountName());
      $this->assertNotSame(self::MEMBER_OWNER, $owner->uuid(), 'No member owns a how-to page.');

      $this->assertSame([$term->id()], array_column($node->get('field_topic_type')->getValue(), 'target_id'), "{$data['title']} carries the Site guide type.");
      $this->assertSame('community', $node->get('field_content_visibility')->value, "{$data['title']} is visible to members only.");

      // The group module calls its relationship entity group_relationship in
      // version 2 and later, group_content before.
      $relationship_type = $manager->hasDefinition('group_relationship') ? 'group_relationship' : 'group_content';
      $relations = $manager->getStorage($relationship_type)->loadByEntity($node);
      $this->assertSame([], $relations, "{$data['title']} is in no group.");
    }

    // The control: the same lookup finds the smoke topic's group, so an empty
    // answer above means no group and not a lookup that finds nothing.
    $topic = $this->entityByUuid('5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d20');
    $this->assertNotNull($topic);
    $relationship_type = $manager->hasDefinition('group_relationship') ? 'group_relationship' : 'group_content';
    $this->assertNotEmpty($manager->getStorage($relationship_type)->loadByEntity($topic), 'The smoke topic is in its group.');
  }

  /**
   * The guide account is an ordinary, safe member account.
   */
  public function testGuideAccountIsSafe(): void {
    $this->loadPack('smoke');
    $account = $this->container->get('entity.repository')->loadEntityByUuid('user', PackHowto::GUIDE_ACCOUNT_UUID);
    $this->assertInstanceOf(UserInterface::class, $account, 'The guide account exists.');
    $this->assertSame('site_guide', $account->getAccountName());
    $this->assertTrue($account->isActive());
    // Open Social adds verified to every new account; the guide gets no role
    // that edits or manages anything.
    $this->assertSame([], array_values(array_diff($account->getRoles(), ['authenticated', 'verified'])), 'The guide account holds no role beyond authenticated and verified.');
    $this->assertStringEndsWith('.example', (string) $account->getEmail(), 'Its address is on a reserved domain.');
    $this->assertNotSame('', (string) $account->getPassword(), 'It has a password hash.');
    $password = $this->container->get('password');
    $this->assertFalse($password->check('site_guide', (string) $account->getPassword()), 'Its password is not its username.');

    $profiles = $this->container->get('entity_type.manager')->getStorage('profile')->loadByProperties(['uid' => $account->id(), 'type' => 'profile']);
    $profile = reset($profiles);
    $this->assertNotFalse($profile, 'The guide account has a profile.');
    $this->assertSame('Site', $profile->get('field_profile_first_name')->value);
    $this->assertSame('guide', $profile->get('field_profile_last_name')->value);
  }

  /**
   * A visitor is refused a how-to page and a signed-in member reads it.
   */
  public function testOnlyMembersReadHowtoPages(): void {
    $this->loadPack('smoke');
    $pages = self::entries(self::sharedHowto());
    $this->assertNotEmpty($pages);
    [$uuid, $data] = $pages[0];
    $node = $this->entityByUuid($uuid);
    $this->assertInstanceOf(NodeInterface::class, $node);

    // The control first: a signed-in member reads the page, so the visitor's
    // refusal below comes from the page's visibility.
    $member = $this->container->get('entity.repository')->loadEntityByUuid('user', self::MEMBER);
    $this->assertInstanceOf(UserInterface::class, $member);
    $password = bin2hex(random_bytes(12));
    $member->setPassword($password)->save();
    $member->passRaw = $password;
    $this->drupalResetSession();
    $this->drupalLogin($member);
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertStringContainsString((string) $data['title'], $this->getSession()->getPage()->getText(), 'A member sees the how-to page.');
    $this->drupalLogout();

    $this->drupalResetSession();
    $this->drupalGet($node->toUrl());
    $status = $this->getSession()->getStatusCode();
    $path = (string) parse_url($this->getSession()->getCurrentUrl(), PHP_URL_PATH);
    $this->assertTrue(
      in_array($status, [403, 404], TRUE) || str_ends_with($path, '/user/login'),
      "A visitor is refused the how-to page (status $status at $path)."
    );
    $this->assertStringNotContainsString((string) $data['title'], $this->getSession()->getPage()->getText(), 'A visitor does not see the title.');
  }

  /**
   * Each page holds its passage word for word, and no page is missing.
   */
  public function testHowtoPassagesLoad(): void {
    $this->loadPack('smoke');
    $passages = self::expectation('howto', 'passages.yml');
    $this->assertNotEmpty($passages, 'The how-to expectations state passages.');
    foreach ($passages as $passage) {
      $id = (string) ($passage['id'] ?? '?');
      $node = $this->entityByUuid((string) ($passage['uuid'] ?? ''));
      $this->assertInstanceOf(NodeInterface::class, $node, "How-to passage $id: its page loaded.");
      $field = (string) ($passage['field'] ?? 'body');
      $this->assertStringContainsString(
        PackText::normalize(PackText::untoken((string) $passage['text'])),
        PackText::normalize($this->fieldText($node, $field)),
        "How-to passage $id is on its loaded page word for word."
      );
    }

    $term = $this->container->get('entity.repository')->loadEntityByUuid('taxonomy_term', PackHowto::GUIDE_TERM_UUID);
    $this->assertNotNull($term);
    $guide_pages = $this->container->get('entity_type.manager')->getStorage('node')->loadByProperties([
      'type' => 'topic',
      'field_topic_type' => $term->id(),
    ]);
    $expected = (int) (self::expectation('howto', 'expect.yml')['counts']['howto'] ?? -1);
    $this->assertCount($expected, $guide_pages, 'The site holds the expected number of Site guide pages.');
  }

  /**
   * A second load leaves an organizer's edit alone and logs a warning.
   */
  public function testReloadKeepsAnOrganizersEdit(): void {
    $this->container->get('module_installer')->install(['dblog']);
    $this->rebuildAll();
    $this->loadPack('smoke');
    $this->resetEntityCaches();

    $pages = self::entries(self::sharedHowto());
    $this->assertGreaterThanOrEqual(2, count($pages), 'There is a second page to edit.');
    [$uuid, $data] = $pages[1];
    $node = $this->entityByUuid($uuid);
    $this->assertInstanceOf(NodeInterface::class, $node);
    $nid = (int) $node->id();
    $before = (int) $this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(FALSE)->count()->execute();

    // An editor who may change any topic. The sitemanager role of this
    // distribution does not hold that permission (localnodes_platform narrows
    // the site roles on install, so they cannot reach private house content),
    // so the test gives a role of its own the permission to edit any topic and
    // the text format the body uses. The account also holds the site manager
    // role, because only the editor roles may change a Site guide page whatever
    // other permission an account holds. This test is about what a reload does
    // to an edit that was made. The password exists only in this test.
    $format = (string) $node->get('body')->format;
    $permissions = ['access content', 'edit any topic content'];
    if ($format !== '') {
      $permissions[] = "use text format $format";
    }
    $organizer = $this->drupalCreateUser($permissions);
    $organizer->addRole('sitemanager');
    $password = bin2hex(random_bytes(12));
    $organizer->setPassword($password)->save();
    $organizer->passRaw = $password;
    $this->assertTrue($node->access('update', $organizer), 'The editor may update the how-to page.');
    $this->drupalResetSession();
    $this->drupalLogin($organizer);
    $edited_title = $data['title'] . ' (edited by the organizer)';
    $edited_body = '<p>The organizer wrote this sentence after the page loaded.</p>';
    $this->drupalGet("/node/$nid/edit");
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([
      'title[0][value]' => $edited_title,
      'body[0][value]' => $edited_body,
    ], 'Save');
    $this->drupalLogout();

    // The edit is saved, so the reload below has something to overwrite.
    $this->resetEntityCaches();
    $node = $this->entityByUuid($uuid);
    $this->assertSame($edited_title, $node->label(), 'The edit form saved the organizer\'s title.');
    $this->assertStringContainsString('The organizer wrote this sentence', $this->fieldText($node, 'body'));

    $database = $this->container->get('database');
    $database->delete('watchdog')->condition('type', 'social_demo')->execute();

    // The second load, as the entrypoint would on a site that has the pages.
    $this->runPlugins(['pack_howto'], 'createContent', self::packProfile('smoke'));
    $this->resetEntityCaches();
    $this->container->get('state')->resetCache();

    $node = $this->entityByUuid($uuid);
    $this->assertInstanceOf(NodeInterface::class, $node);
    $this->assertSame($edited_title, $node->label(), 'The reload kept the organizer\'s title.');
    $this->assertStringContainsString('The organizer wrote this sentence', $this->fieldText($node, 'body'), 'The reload kept the organizer\'s body.');
    $after = (int) $this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(FALSE)->count()->execute();
    $this->assertSame($before, $after, 'The reload created no new node.');
    $this->assertCount(1, $this->container->get('entity_type.manager')->getStorage('node')->loadByProperties(['uuid' => $uuid]), 'The page exists once.');

    $rows = $database->select('watchdog', 'w')
      ->fields('w', ['message', 'severity'])
      ->condition('type', 'social_demo')
      ->execute()
      ->fetchAll();
    $warnings = array_filter($rows, fn($row) => (int) $row->severity === 4 && str_contains((string) $row->message, $uuid) && str_contains((string) $row->message, 'already exists'));
    $this->assertNotEmpty($warnings, 'The reload logs the already-exists warning for the edited page.');
    $errors = array_filter($rows, fn($row) => (int) $row->severity <= 3);
    $this->assertSame([], array_values($errors), 'The reload logs no error.');
  }

}
