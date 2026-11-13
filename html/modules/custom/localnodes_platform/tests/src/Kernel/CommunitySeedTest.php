<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\filter\Entity\FilterFormat;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_platform\Drush\Commands\OrganizerCommands;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Drupal\user\UserInterface;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The community's four texts, applied by drush localnodes:seed-community.
 *
 * Phase 23 plan 34 (T-2, ONB-06, PLACE-01). Plan 23-33 turns the organizer's
 * description into four short texts; the provisioning workflow puts them in
 * LOCALNODES_COMMUNITY_SEED as base64url JSON. The command applies them once:
 * the site tagline and an About page, the first group's description, the
 * assistant's context and a draft welcome post. They are content and per-site
 * values, never module config.
 *
 * The command finds the organizer by address and the first group by name,
 * type and owner (the way localnodes:organizer-welcome made it); it makes
 * neither. A draft welcome post is an unpublished, sticky Topic in that group.
 *
 * @group localnodes_platform
 * @group phase23
 */
final class CommunitySeedTest extends GroupAccessKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * localnodes_platform is enabled for its class autoloading only (see
   * TopicTypesCommandTest).
   */
  protected static $modules = ['localnodes_platform'];

  /**
   * The organizer's address: fictional, on a reserved domain; no mail is read.
   */
  protected const ORGANIZER_EMAIL = 'robin.organizer@example.invalid';

  /**
   * The community's name.
   */
  protected const SITE_NAME = 'Riverside Neighbors';

  /**
   * The state key the command sets once all parts are applied.
   */
  protected const MARKER = 'localnodes.install_step.community_seed';

  /**
   * A valid set of four texts.
   */
  protected const TEXTS = [
    'about' => 'We are neighbors along the river who share tools, meals and repair days. Everyone on our streets is welcome to join in.',
    'group_description' => 'A place for Riverside neighbors to plan repair days and share tools.',
    'assistant_context' => 'Riverside Neighbors is a neighborhood group that shares tools and plans repair days and meals.',
    'welcome_post' => 'Welcome to Riverside Neighbors. Say hello, tell us what you can lend, and ask for help when you need it.',
  ];

  /**
   * The state key that holds the assistant's context.
   */
  protected const CONTEXT_KEY = 'localnodes_platform.community_context';

  /**
   * The organizer.
   */
  protected UserInterface $organizer;

  /**
   * The community's first group.
   */
  protected GroupInterface $group;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Open Social's page type, from its own YAML. The page requires a body and
    // a visibility value, like the live site.
    $this->installShippedConfig('social_page', [
      'node.type.page',
      'field.field.node.page.body',
      'field.field.node.page.field_content_visibility',
    ]);
    // What a live site's site manager role holds for content (read with
    // Role::getPermissions() on the lane site, 2026-11-08): the shipped role
    // YAML carries none of it in a Kernel test.
    user_role_grant_permissions('sitemanager', ['bypass node access', 'administer nodes', 'view unpublished post entities']);
    // What Open Social grants a member for pages (social_page_install()).
    user_role_grant_permissions('verified', ['view node.page.field_content_visibility:community content']);
    // The format the command uses for rich text on a real site.
    FilterFormat::create([
      'format' => 'basic_html',
      'name' => 'Basic HTML',
      'filters' => ['filter_html' => ['status' => 1, 'settings' => ['allowed_html' => '<p>']]],
    ])->save();
    // The group description field and the group-type's Topic relationship are
    // in the base; the description field is shipped by Open Social.
    $this->installShippedConfig('social_group', ['field.storage.group.field_group_description']);
    $this->installShippedConfig('social_group_flexible_group', ['field.field.group.flexible_group.field_group_description']);
    // Open Social's four default page types; Topic requires one.
    foreach (['Blog', 'Content', 'Dialog', 'News'] as $name) {
      Term::create(['vid' => 'topic_types', 'name' => $name])->save();
    }
    $this->config('system.site')->set('name', self::SITE_NAME)->set('slogan', '')->save();
    $this->organizer = $this->createUser([], 'robin_organizer', FALSE, [
      'mail' => self::ORGANIZER_EMAIL,
      'roles' => ['sitemanager'],
    ]);
    // The first group, as localnodes:organizer-welcome made it: named after the
    // community, owned by the organizer, visible to the community.
    $this->group = Group::create([
      'type' => 'flexible_group',
      'label' => self::SITE_NAME,
      'uid' => $this->organizer->id(),
      'field_flexible_group_visibility' => 'community',
    ]);
    $this->group->save();
    if (!$this->group->getMember($this->organizer)) {
      $this->group->addMember($this->organizer, ['group_roles' => ['flexible_group-group_manager']]);
    }
    putenv('ORGANIZER_EMAIL=' . self::ORGANIZER_EMAIL);
    putenv('SITE_NAME=' . self::SITE_NAME);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach (['LOCALNODES_COMMUNITY_SEED', 'ORGANIZER_EMAIL', 'SITE_NAME'] as $variable) {
      putenv($variable);
    }
    parent::tearDown();
  }

  /**
   * Encodes a value as base64url JSON, unpadded (23-33's format).
   */
  protected function encode(mixed $value): string {
    return rtrim(strtr(base64_encode(is_string($value) ? $value : json_encode($value)), '+/', '-_'), '=');
  }

  /**
   * Sets LOCALNODES_COMMUNITY_SEED the way the workflow does.
   *
   * @param array<string, mixed> $overrides
   *   Texts to replace in the valid set.
   */
  protected function setSeed(array $overrides = []): void {
    putenv('LOCALNODES_COMMUNITY_SEED=' . $this->encode($overrides + self::TEXTS));
  }

  /**
   * Runs the command once.
   *
   * @return array{0: int, 1: string}
   *   The exit code and everything the command printed.
   */
  protected function runCommand(): array {
    $output = new BufferedOutput();
    $command = new OrganizerCommands();
    $command->setOutput($output);
    $code = $command->seedCommunity();
    return [$code, trim($output->fetch())];
  }

  /**
   * Every page node.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The pages.
   */
  protected function pages(): array {
    return array_values($this->container->get('entity_type.manager')->getStorage('node')->loadByProperties(['type' => 'page']));
  }

  /**
   * Nothing is set: the command says so, exits 0 and creates nothing.
   *
   * @dataProvider emptyValueProvider
   */
  public function testUnsetOrEmptySeedDoesNothing(?string $value): void {
    $value === NULL ? putenv('LOCALNODES_COMMUNITY_SEED') : putenv('LOCALNODES_COMMUNITY_SEED=' . $value);
    [$code, $printed] = $this->runCommand();

    $this->assertSame(0, $code);
    $this->assertSame('No community seed.', $printed);
    $this->assertSame([], $this->pages());
    $this->assertSame('', $this->config('system.site')->get('slogan'));
    $this->assertEmpty($this->container->get('state')->get(self::MARKER), 'No marker is set when there is nothing to apply.');
  }

  /**
   * Absent, empty and blank.
   */
  public static function emptyValueProvider(): array {
    return [
      'absent' => [NULL],
      'empty' => [''],
      'blank' => ['   '],
    ];
  }

  /**
   * The slogan is the About text's first sentence, and the About page holds it all.
   */
  public function testAppliesTaglineAndAboutPage(): void {
    $this->setSeed();
    [$code, $printed] = $this->runCommand();

    $this->assertSame(0, $code);
    $this->assertSame('Community seed applied.', $printed);
    $this->assertSame(
      'We are neighbors along the river who share tools, meals and repair days.',
      $this->config('system.site')->get('slogan'),
      'The slogan is the first sentence of the About text.'
    );
    $pages = $this->pages();
    $this->assertCount(1, $pages, 'One About page is made.');
    $page = $pages[0];
    $this->assertSame('About ' . self::SITE_NAME, $page->label());
    $this->assertTrue($page->isPublished());
    $this->assertSame((int) $this->organizer->id(), (int) $page->getOwnerId(), 'The organizer owns the page.');
    $this->assertSame('basic_html', $page->get('body')->format);
    $this->assertSame('<p>' . self::TEXTS['about'] . '</p>', $page->get('body')->value);
    $this->assertNotEmpty($this->container->get('state')->get(self::MARKER), 'The marker is set once the parts are done.');
  }

  /**
   * The About page has the first group's visibility: community.
   */
  public function testAboutPageIsVisibleToTheCommunityOnly(): void {
    $this->setSeed();
    $this->runCommand();
    $page = $this->pages()[0];

    $this->assertSame('community', $page->get('field_content_visibility')->value);
    $this->assertTrue($page->access('view', $this->createPersona('neighbor_one')), 'A member can read the About page.');
    $this->assertFalse($page->access('view', $this->anonymous()), 'A visitor cannot.');
  }

  /**
   * The slogan is the whole text when there is no sentence end, and 255 at most.
   *
   * @dataProvider sloganProvider
   */
  public function testSloganRule(string $about, string $expected): void {
    $this->setSeed(['about' => $about]);
    $this->runCommand();

    $this->assertSame($expected, $this->config('system.site')->get('slogan'));
    $this->assertLessThanOrEqual(255, mb_strlen($this->config('system.site')->get('slogan')));
  }

  /**
   * About texts and the slogan each gives.
   */
  public static function sloganProvider(): array {
    $long = str_repeat('word ', 80) . 'end.';
    return [
      'two sentences' => ['First thing we do. Second thing we do.', 'First thing we do.'],
      'a question first' => ['Who are we? A group of neighbors.', 'Who are we?'],
      'no sentence end' => ['A group of neighbors sharing tools', 'A group of neighbors sharing tools'],
      'a dot inside a word is not an end' => ['We meet at v2.5 of the hall. Join us.', 'We meet at v2.5 of the hall.'],
      'a long first sentence is cut at 255 characters' => [$long, rtrim(mb_substr($long, 0, 255))],
    ];
  }

  /**
   * A second run, after the organizer edited things, changes nothing.
   */
  public function testSecondRunChangesNothing(): void {
    $this->setSeed();
    $this->runCommand();
    $page = $this->pages()[0];
    $page->set('body', ['value' => '<p>The organizer rewrote this.</p>', 'format' => 'basic_html'])->save();
    $this->config('system.site')->set('slogan', 'My own tagline')->save();

    [$code, $printed] = $this->runCommand();

    $this->assertSame(0, $code);
    $this->assertSame('Community seed already applied.', $printed);
    $this->assertCount(1, $this->pages(), 'No second About page.');
    $this->assertSame('<p>The organizer rewrote this.</p>', $this->pages()[0]->get('body')->value);
    $this->assertSame('My own tagline', $this->config('system.site')->get('slogan'));
  }

  /**
   * A run that stopped after saving the page but before the marker is resumed.
   *
   * The page is found again by title and owner, not made twice.
   */
  public function testResumeAfterAnInterruptedRunMakesNoSecondPage(): void {
    $this->setSeed();
    Node::create([
      'type' => 'page',
      'title' => 'About ' . self::SITE_NAME,
      'uid' => $this->organizer->id(),
      'status' => NodeInterface::PUBLISHED,
      'field_content_visibility' => 'community',
      'body' => ['value' => '<p>Left by the interrupted run.</p>', 'format' => 'basic_html'],
    ])->save();

    [$code, $printed] = $this->runCommand();

    $this->assertSame(0, $code);
    $this->assertSame('Community seed applied.', $printed);
    $this->assertCount(1, $this->pages());
  }

  /**
   * Input that is not a community seed stops the command and creates nothing.
   *
   * The printed line is fixed: it never repeats the value.
   *
   * @dataProvider invalidInputProvider
   */
  public function testInvalidInputExitsNonZeroWithoutCreating(string $value): void {
    putenv('LOCALNODES_COMMUNITY_SEED=' . $value);
    [$code, $printed] = $this->runCommand();

    $this->assertSame(1, $code);
    $this->assertSame('LOCALNODES_COMMUNITY_SEED is not a community seed.', $printed);
    $this->assertFalse(str_contains($printed, $value), 'The value is never echoed.');
    $this->assertSame([], $this->pages());
    $this->assertSame('', $this->config('system.site')->get('slogan'));
    $this->assertEmpty($this->container->get('state')->get(self::MARKER));
  }

  /**
   * Values that are not the base64url JSON object of four texts.
   */
  public static function invalidInputProvider(): array {
    $encode = static fn ($value) => rtrim(strtr(base64_encode(is_string($value) ? $value : json_encode($value)), '+/', '-_'), '=');
    $texts = self::TEXTS;
    return [
      'not base64' => ['%%%not-base64%%%'],
      'plain JSON, not encoded' => ['{"about":"x"}'],
      'base64 of text, not JSON' => [$encode('plain words, no JSON')],
      'a JSON list' => [$encode(['a', 'b', 'c', 'd'])],
      'a JSON string' => [$encode('About')],
      'a key missing' => [$encode(array_diff_key($texts, ['welcome_post' => 1]))],
      'an extra key' => [$encode($texts + ['extra' => 'More text here.'])],
      'a number for a text' => [$encode(['about' => 5] + $texts)],
      'null for a text' => [$encode(['about' => NULL] + $texts)],
      'a list for a text' => [$encode(['about' => ['One.']] + $texts)],
      'a text that is empty' => [$encode(['group_description' => ''] + $texts)],
      'a text that is only spaces and control characters' => [$encode(['assistant_context' => " \t\n "] + $texts)],
      'over 16384 characters' => [str_repeat('A', 16385)],
    ];
  }

  /**
   * Markup in a text is stored as plain text, and control characters go.
   */
  public function testMarkupIsEscapedAndControlCharactersBecomeSpaces(): void {
    $this->setSeed(['about' => "We <b>share</b> tools & meals.\nJoin\tus\x07today. <script>alert(1)</script>"]);
    [$code] = $this->runCommand();

    $this->assertSame(0, $code);
    $body = $this->pages()[0]->get('body')->value;
    $this->assertStringNotContainsString('<b>', $body);
    $this->assertStringNotContainsString('<script', $body);
    $this->assertStringContainsString('&lt;b&gt;share&lt;/b&gt;', $body);
    $this->assertStringContainsString('&amp; meals. Join us today.', $body, 'Newline, tab and the bell character became spaces.');
    $this->assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $body);
    $this->assertSame(1, preg_match_all('/<p>/', $body), 'One paragraph.');
    $slogan = $this->config('system.site')->get('slogan');
    $this->assertStringNotContainsString('<', $slogan);
    $this->assertStringNotContainsString('>', $slogan);
  }

  /**
   * A text over its length cap is cut, not refused.
   */
  public function testTextOverTheCapIsCut(): void {
    $this->setSeed(['about' => str_repeat('a', 5000)]);
    [$code] = $this->runCommand();

    $this->assertSame(0, $code);
    $this->assertSame('<p>' . str_repeat('a', 600) . '</p>', $this->pages()[0]->get('body')->value);
  }

  /**
   * Without the rich text format, the page gets plain text, never raw markup.
   */
  public function testWithoutBasicHtmlTheBodyIsPlainText(): void {
    FilterFormat::load('basic_html')->delete();
    $this->setSeed(['about' => 'Tom & <i>Jerry</i> share tools.']);
    $this->runCommand();
    $body = $this->pages()[0]->get('body');

    $this->assertSame('plain_text', $body->format);
    $this->assertSame('Tom & Jerry share tools.', $body->value, 'The tags are removed; the plain text format escapes the rest on output.');
  }

  /**
   * No organizer account: the command stops, makes nothing, sets no marker.
   *
   * It looks the account up by address and never creates one, so it works the
   * same when the account is blocked.
   */
  public function testMissingOrganizerFailsClosed(): void {
    $this->organizer->delete();
    $this->setSeed();
    [$code, $printed] = $this->runCommand();

    $this->assertSame(1, $code);
    $this->assertSame('The organizer account does not exist.', $printed);
    $this->assertSame([], $this->pages());
    $this->assertEmpty($this->container->get('state')->get(self::MARKER));
  }

  /**
   * A blocked organizer account is enough: the command never needs a sign-in.
   */
  public function testBlockedOrganizerIsFine(): void {
    $this->organizer->block()->save();
    $this->setSeed();
    [$code, $printed] = $this->runCommand();

    $this->assertSame(0, $code);
    $this->assertSame('Community seed applied.', $printed);
    $this->assertSame((int) $this->organizer->id(), (int) $this->pages()[0]->getOwnerId());
  }

  /**
   * 23-35: on a sign-up site, organizer-setup (twice) makes one blocked
   * account and one first group with it as group manager, and the seed then
   * applies with that blocked organizer (the 23-34 order: account and group,
   * then seed).
   */
  public function testOrganizerSetupThenSeedWithABlockedOrganizer(): void {
    $email = 'kai.signup@example.invalid';
    $site_name = 'Hillside Neighbors';
    putenv('ORGANIZER_EMAIL=' . $email);
    putenv('ORGANIZER_NAME=Kai');
    putenv('SITE_NAME=' . $site_name);
    try {
      foreach ([1, 2] as $run) {
        $output = new BufferedOutput();
        $command = new OrganizerCommands();
        $command->setOutput($output);
        $this->assertSame(0, $command->organizerSetup(), "organizer-setup run $run exits 0.");
      }

      $users = $this->container->get('entity_type.manager')->getStorage('user');
      $users->resetCache();
      $found = $users->loadByProperties(['mail' => $email]);
      $this->assertCount(1, $found, 'One account.');
      $organizer = reset($found);
      $this->assertTrue($organizer->isBlocked(), 'The organizer is blocked.');

      $groups = $this->container->get('entity_type.manager')->getStorage('group')
        ->loadByProperties(['type' => 'flexible_group', 'label' => $site_name, 'uid' => $organizer->id()]);
      $this->assertCount(1, $groups, 'One first group, owned by the organizer.');
      $member = reset($groups)->getMember($organizer);
      $this->assertNotFalse($member);
      $this->assertArrayHasKey('flexible_group-group_manager', $member->getRoles(FALSE));

      $this->setSeed();
      [$code, $printed] = $this->runCommand();
      $this->assertSame([0, 'Community seed applied.'], [$code, $printed]);
      $about = $this->container->get('entity_type.manager')->getStorage('node')
        ->loadByProperties(['type' => 'page', 'title' => 'About ' . $site_name, 'uid' => $organizer->id()]);
      $this->assertCount(1, $about, 'The About page belongs to the blocked organizer.');
    }
    finally {
      putenv('ORGANIZER_NAME');
    }
  }

  /**
   * Without ORGANIZER_EMAIL the command stops with the variable's name.
   */
  public function testMissingOrganizerEmailVariableFailsClosed(): void {
    putenv('ORGANIZER_EMAIL');
    $this->setSeed();
    [$code, $printed] = $this->runCommand();

    $this->assertSame(1, $code);
    $this->assertSame('ORGANIZER_EMAIL is not set.', $printed);
    $this->assertSame([], $this->pages());
  }

  /**
   * The command prints fixed sentences only, never a text.
   */
  public function testOutputNeverHoldsATextOrTheSeed(): void {
    $this->setSeed();
    [, $printed] = $this->runCommand();
    [, $printed_again] = $this->runCommand();

    foreach (self::TEXTS as $text) {
      $this->assertStringNotContainsString(substr($text, 0, 25), $printed . $printed_again);
    }
    $this->assertStringNotContainsString(self::ORGANIZER_EMAIL, $printed . $printed_again);
  }

  /**
   * The draft welcome posts (Topics titled "Draft: welcome post").
   *
   * @return \Drupal\node\NodeInterface[]
   *   The drafts.
   */
  protected function drafts(): array {
    return array_values($this->container->get('entity_type.manager')->getStorage('node')->loadByProperties(['type' => 'topic', 'title' => 'Draft: welcome post']));
  }

  /**
   * Reloads the first group.
   */
  protected function freshGroup(): GroupInterface {
    return $this->container->get('entity_type.manager')->getStorage('group')->loadUnchanged($this->group->id());
  }

  /**
   * The first group gets the group text as its description.
   */
  public function testSetsTheFirstGroupsDescription(): void {
    $this->setSeed();
    [$code] = $this->runCommand();

    $this->assertSame(0, $code);
    $description = $this->freshGroup()->get('field_group_description');
    $this->assertSame('basic_html', $description->format);
    $this->assertSame('<p>' . self::TEXTS['group_description'] . '</p>', $description->value);
  }

  /**
   * A description the group already has is never replaced.
   */
  public function testExistingGroupDescriptionIsKept(): void {
    $this->group->set('field_group_description', ['value' => '<p>Written by hand.</p>', 'format' => 'basic_html'])->save();
    $this->setSeed();
    $this->runCommand();

    $this->assertSame('<p>Written by hand.</p>', $this->freshGroup()->get('field_group_description')->value);
  }

  /**
   * The assistant's context is stored as the per-site value, as plain text.
   */
  public function testStoresTheAssistantContextAsTheSiteValue(): void {
    $this->setSeed();
    $this->runCommand();

    $this->assertSame(self::TEXTS['assistant_context'], $this->container->get('state')->get(self::CONTEXT_KEY));
  }

  /**
   * The context is cleaned: control characters to spaces, 600 characters.
   */
  public function testStoredContextIsCleanedAndCapped(): void {
    $this->setSeed(['assistant_context' => "Line one.\nLine\x07two. " . str_repeat('x', 700)]);
    $this->runCommand();
    $stored = $this->container->get('state')->get(self::CONTEXT_KEY);

    $this->assertSame(600, mb_strlen($stored));
    $this->assertStringStartsWith('Line one. Line two. xxx', $stored);
  }

  /**
   * One unpublished, sticky Topic by the organizer, in the first group.
   */
  public function testMakesOneDraftWelcomePost(): void {
    $this->setSeed();
    $this->runCommand();
    $drafts = $this->drafts();

    $this->assertCount(1, $drafts, 'One draft welcome post.');
    $draft = $drafts[0];
    $this->assertFalse($draft->isPublished(), 'It is not published.');
    $this->assertTrue($draft->isSticky(), 'It is pinned.');
    $this->assertSame((int) $this->organizer->id(), (int) $draft->getOwnerId());
    $this->assertSame('<p>' . self::TEXTS['welcome_post'] . '</p>', $draft->get('body')->value);
    $this->assertSame('basic_html', $draft->get('body')->format);
    $relationships = $this->freshGroup()->getRelationshipsByEntity($draft, 'group_node:topic');
    $this->assertCount(1, $relationships, 'It is in the first group.');
    $terms = $draft->get('field_topic_type')->referencedEntities();
    $this->assertCount(1, $terms);
    $this->assertSame('News', $terms[0]->label(), 'The required page type is News.');
    $this->assertSame('community', $draft->get('field_content_visibility')->value);
  }

  /**
   * The organizer and a site manager see the draft; a member and a visitor do not.
   */
  public function testDraftIsHiddenFromMembersAndVisitors(): void {
    $member = $this->createPersona('neighbor_two');
    $this->addMember($this->group, $member);
    $other_manager = $this->createUser([], 'second_manager', FALSE, ['roles' => ['sitemanager']]);
    $this->setSeed();
    $this->runCommand();
    $draft = $this->drafts()[0];

    $this->assertTrue($draft->access('view', $this->organizer), 'The organizer can view the draft.');
    $this->assertTrue($draft->access('view', $other_manager), 'A site manager can view the draft.');
    $this->assertFalse($draft->access('view', $member), 'A member of the group cannot.');
    $this->assertFalse($draft->access('view', $this->createPersona('neighbor_three')), 'A community member outside the group cannot.');
    $this->assertFalse($draft->access('view', $this->anonymous()), 'A visitor cannot.');
  }

  /**
   * A second run makes no second post and changes nothing the organizer edited.
   */
  public function testSecondRunChangesNoDescriptionContextOrPost(): void {
    $this->setSeed();
    $this->runCommand();
    $this->group->set('field_group_description', ['value' => '<p>Edited.</p>', 'format' => 'basic_html'])->save();
    $this->container->get('state')->set(self::CONTEXT_KEY, 'Edited context.');
    $draft = $this->drafts()[0];
    $draft->set('title', 'Draft: welcome post')->set('body', ['value' => '<p>Edited post.</p>', 'format' => 'basic_html'])->save();

    [$code, $printed] = $this->runCommand();

    $this->assertSame('Community seed already applied.', $printed);
    $this->assertSame(0, $code);
    $this->assertCount(1, $this->drafts());
    $this->assertSame('<p>Edited.</p>', $this->freshGroup()->get('field_group_description')->value);
    $this->assertSame('Edited context.', $this->container->get('state')->get(self::CONTEXT_KEY));
    $this->assertSame('<p>Edited post.</p>', $this->drafts()[0]->get('body')->value);
  }

  /**
   * An interrupted run (draft saved, marker not set) does not post twice.
   */
  public function testResumeAfterInterruptionMakesNoSecondDraft(): void {
    $this->setSeed();
    $this->runCommand();
    $this->container->get('state')->delete(self::MARKER);
    $this->container->get('state')->delete(self::CONTEXT_KEY);

    [$code, $printed] = $this->runCommand();

    $this->assertSame(0, $code);
    $this->assertSame('Community seed applied.', $printed);
    $this->assertCount(1, $this->drafts(), 'No second draft.');
    $this->assertCount(1, $this->pages(), 'No second About page.');
    $this->assertCount(1, $this->freshGroup()->getRelationshipsByEntity($this->drafts()[0], 'group_node:topic'), 'One group relationship.');
    $this->assertSame(self::TEXTS['assistant_context'], $this->container->get('state')->get(self::CONTEXT_KEY));
  }

  /**
   * Without the first group nothing is applied and no marker is set.
   */
  public function testMissingFirstGroupFailsClosed(): void {
    $this->group->delete();
    $this->setSeed();
    [$code, $printed] = $this->runCommand();

    $this->assertSame(1, $code);
    $this->assertSame('The first group does not exist.', $printed);
    $this->assertSame([], $this->pages());
    $this->assertSame([], $this->drafts());
    $this->assertSame('', $this->config('system.site')->get('slogan'));
    $this->assertNull($this->container->get('state')->get(self::CONTEXT_KEY));
    $this->assertEmpty($this->container->get('state')->get(self::MARKER));
  }

  /**
   * Without SITE_NAME there is no way to find the group: fail closed.
   */
  public function testMissingSiteNameFailsClosed(): void {
    putenv('SITE_NAME');
    $this->setSeed();
    [$code, $printed] = $this->runCommand();

    $this->assertSame(1, $code);
    $this->assertSame('The first group does not exist.', $printed);
    $this->assertEmpty($this->container->get('state')->get(self::MARKER));
  }

  /**
   * The texts are content: no config object holds any of them.
   */
  public function testNoTextIsWrittenToConfig(): void {
    $this->setSeed();
    $this->runCommand();
    $names = $this->container->get('config.factory')->listAll();
    foreach ($names as $name) {
      $data = json_encode($this->config($name)->getRawData());
      foreach (['group_description', 'assistant_context', 'welcome_post'] as $key) {
        $this->assertStringNotContainsString(substr(self::TEXTS[$key], 0, 30), (string) $data, "$name holds no seed text.");
      }
    }
    // The slogan is the one per-site config value (like the site name).
    $this->assertSame(['system.site'], array_values(array_filter($names, fn ($n) => str_contains(json_encode($this->config($n)->getRawData()), substr(self::TEXTS['about'], 0, 30)))));
  }


}
