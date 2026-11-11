<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\group\Cache\Context\GroupPermissionsCacheContext;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\localnodes_platform\Controller\GuideController;
use Drupal\social_ai_indexing\Service\AssistantName;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * The organizer guide at /guide (23-13, ONB-03, T-4).
 *
 * Proves the page is signed-in only, that its words are the approved words
 * (title, intro, six numbered h2 steps, then the T-4 sections outside the
 * list, then the help line), that step 2 links the group itself or says it
 * is not ready, that step 5 names the assistant, that the fund and fees
 * sections show only when localnodes_web3 is on, and that no line of the
 * page says a place word or a crypto word.
 *
 * The web3 stack is not installed here. The module handler's module list
 * gets "localnodes_web3" added by hand, which is all the controller asks.
 *
 * @group localnodes_platform
 * @group phase23
 */
final class GuidePageTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Group supplies the user.group_permissions cache context the guide sets.
   */
  protected static $modules = ['system', 'user', 'filter', 'field', 'options', 'entity', 'flexible_permissions', 'group', 'localnodes_platform'];

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  /**
   * The six steps, as [h2, [paragraphs]], for a member who has no group yet.
   *
   * Steps 1 to 5 started as the design spec's section 8. Step 2 here is the not-ready
   * line (the group-link form is its own test). Step 6, bringing in members,
   * follows the stock invite screens and a new member's walk through them.
   */
  private const STEPS = [
    ['Set your password', ["Open the sign-in link in your welcome email. It brings you to your site and asks you to choose a password. Choose one and save it. You're signed in."]],
    ['Open your group', ["Your group isn't ready yet. Get help."]],
    ['Upload your first files', ["To add documents, open your group's Import tab and choose Upload files. Choose your files, tick the box that says you have the right to share them, and press the Import button. Use files whose text you can select and copy: the assistant can't read image-only scans, and a very short file may be too short to use."]],
    ['Wait while your documents are prepared', ['The "Preparing documents for the assistant\'s search" panel on the Import tab shows how many are prepared, for example "18 of 21". This happens in the background. You can leave the page and come back.']],
    ['Ask the AI assistant a question', ['Go to your group\'s page. The AI assistant, called "LocalNodes LiAIson", is at the bottom right. It isn\'t shown on the Import tab. It looks for answers in the pages you can see. Ask something your documents answer. The answer shows numbered sources: open one to check it, because the assistant can get things wrong.']],
    ['Bring in your members', [
      'To invite people, open your group\'s Manage members tab, choose Add members, then Invite users. Enter their email addresses, or their names if they are already on the site, and press "Send your invite(s) by email".',
      'Each person gets an email. Someone new to the site creates an account from it, signs in with the link in the welcome email that follows, then accepts under Invites, in the menu behind their profile picture.',
      'Your members read the documents in the group\'s Topics, where each original file can be opened. To ask, they go to the group\'s page and use the AI assistant at the bottom right. It looks for answers in the pages each member can see.',
    ]],
  ];

  /**
   * The step headings, in order.
   */
  private const HEADINGS = [
    'Set your password', 'Open your group', 'Upload your first files',
    'Wait while your documents are prepared', 'Ask the AI assistant a question',
    'Bring in your members',
  ];

  /**
   * UI-SPEC section 8: the intro line.
   */
  private const INTRO = 'This guide takes you from your sign-in link to your first answer with sources, then to your members reading and asking.';

  /**
   * UI-SPEC section 8: the fund section body, in two parts around the link.
   */
  private const FUND_BODY_BEFORE = 'Each group can have a fund: money the group holds together. A group organizer sets it up from the group\'s Fund tab. Money leaves the fund only when members vote for it and enough of them agree.';

  /**
   * UI-SPEC section 8: the fees section body without its last sentence.
   */
  private const FEES_BODY = 'Some actions in a fund, such as a vote, have a small network fee. Your community pays these fees, so members never do.';

  /**
   * UI-SPEC section 8: the assistant section body.
   */
  private const ASSISTANT_BODY = 'Ask the assistant about anything in your community\'s files and posts, for example "When is the next meeting?" or "What did we decide about the budget?". Every answer lists its sources, so you can check it. It only sees what you\'re allowed to see: two people asking the same question can get different answers. It cannot act for the community, open your email inbox or browse other websites; it can read permitted library text, including an email address written in a document.';

  /**
   * UI-SPEC section 8: the help line.
   */
  private const HELP_LINE = 'Stuck? Get help is in the footer of every page.';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'user', 'filter']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('group');
    $this->container->get('theme_installer')->install(['localnodes_stock_email_test']);
    $this->config('system.theme')->set('default', 'localnodes_stock_email_test')->save();
    // Anonymous (uid 0) and a reserved uid 1 so later users are plain.
    User::create(['uid' => 1, 'name' => 'root-reserved', 'status' => 1])->save();
    $this->provideAssistantName();
  }

  /**
   * Puts the chat's name service in the container, as the full site has it.
   *
   * social_ai_indexing is not installed here (it needs the search stack), so
   * its class is loaded from its folder and the service is set by hand.
   */
  private function provideAssistantName(): void {
    $this->container->get('class_loader')->addPsr4('Drupal\\social_ai_indexing\\', $this->root . '/modules/custom/social_ai_indexing/src');
    $this->container->set('social_ai_indexing.assistant_name', new AssistantName($this->container->get('config.factory')));
  }

  /**
   * A group named after the community, made by a site admin.
   */
  private function communityGroup(string $label): Group {
    $this->installEntitySchema('group_content');
    $this->installEntitySchema('group_config_wrapper');
    $this->installConfig(['field', 'group']);
    GroupType::create(['id' => 'flexible_group', 'label' => 'Flexible group', 'creator_membership' => FALSE])->save();
    $group = Group::create(['type' => 'flexible_group', 'label' => $label]);
    $group->save();
    return $group;
  }

  /**
   * The rendered guide parsed, for xpath queries.
   */
  private function xpathOf(string $html): \DOMXPath {
    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
    return new \DOMXPath($dom);
  }

  /**
   * The paragraphs of one step, whitespace folded.
   *
   * @return string[]
   *   The text of each p in the step's li.
   */
  private function stepParagraphs(\DOMXPath $xpath, int $step): array {
    $out = [];
    foreach ($xpath->query('//ol/li[' . $step . ']//p') as $p) {
      $out[] = $this->fold($p->textContent);
    }
    return $out;
  }

  /**
   * Adds the localnodes_web3 name to the module handler's list.
   */
  private function pretendWeb3IsOn(): void {
    $handler = $this->container->get('module_handler');
    $handler->addModule('localnodes_web3', 'modules/custom/localnodes_platform/modules/localnodes_web3');
    $this->assertTrue($handler->moduleExists('localnodes_web3'));
  }

  /**
   * A signed-in member who may see content.
   */
  private function member(): User {
    $role = Role::load('authenticated');
    $role->grantPermission('access content')->save();
    $user = User::create(['name' => 'guide-member', 'mail' => 'guide-member@mail.localnodes.xyz', 'status' => 1]);
    $user->save();
    return $user;
  }

  /**
   * Renders the controller's page and returns its HTML.
   */
  private function renderGuide(?GuideController $controller = NULL): string {
    $controller ??= GuideController::create($this->container);
    $build = $controller->page();
    return (string) $this->container->get('renderer')->renderRoot($build);
  }

  /**
   * The visible text of the guide, as one string per block, whitespace folded.
   *
   * @return string[]
   *   The text of each h2, p and li of the page.
   */
  private function blocks(string $html): array {
    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
    $out = [];
    foreach ((new \DOMXPath($dom))->query('//h2 | //p') as $node) {
      $out[] = trim((string) preg_replace('/\s+/', ' ', $node->textContent));
    }
    return $out;
  }

  /**
   * Folds whitespace so a copy line compares equal to rendered text.
   */
  private function fold(string $text): string {
    return trim((string) preg_replace('/\s+/', ' ', $text));
  }

  /**
   * The help line's "Get help" links the Get help page.
   */
  public function testHelpLineLinksTheGetHelpPage(): void {
    $this->container->get('router.builder')->rebuild();
    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?><body>' . $this->renderGuide() . '</body>');
    $xpath = new \DOMXPath($dom);
    $links = $xpath->query('//p[starts-with(normalize-space(.), "Stuck?")]//a');
    $this->assertSame(1, $links->length, 'The help line has one link.');
    $this->assertSame('Get help', $links->item(0)->textContent);
    $this->assertMatchesRegularExpression('#/help$#', $links->item(0)->getAttribute('href'));
  }

  /**
   * The route is /guide, titled "Organizer Guide".
   */
  public function testRouteAndTitle(): void {
    $route = $this->container->get('router.route_provider')->getRouteByName('localnodes_platform.guide');
    $this->assertSame('/guide', $route->getPath());
    $this->assertSame('Organizer Guide', (string) $route->getDefault('_title'));
    $request = Request::create('/guide');
    $this->assertSame('Organizer Guide', (string) $this->container->get('title_resolver')->getTitle($request, $route));
  }

  /**
   * A signed-in person with "access content" may open it.
   */
  public function testSignedInPersonGetsAccess(): void {
    $member = $this->member();
    $result = $this->container->get('access_manager')->checkNamedRoute('localnodes_platform.guide', [], $member, TRUE);
    $this->assertTrue($result->isAllowed());
  }

  /**
   * An anonymous visitor is denied; the response is a 403 for the sign-in flow.
   */
  public function testAnonymousVisitorIsDenied(): void {
    // Even where visitors may see content, the guide is for signed-in people.
    Role::load('anonymous')->grantPermission('access content')->save();
    $anonymous = $this->container->get('current_user');
    $this->assertTrue($anonymous->isAnonymous());
    $result = $this->container->get('access_manager')->checkNamedRoute('localnodes_platform.guide', [], $anonymous, TRUE);
    $this->assertFalse($result->isAllowed());
  }

  /**
   * A signed-in person without "access content" is denied too.
   */
  public function testSignedInPersonWithoutAccessContentIsDenied(): void {
    $user = User::create(['name' => 'no-content', 'mail' => 'no-content@mail.localnodes.xyz', 'status' => 1]);
    $user->save();
    $result = $this->container->get('access_manager')->checkNamedRoute('localnodes_platform.guide', [], $user, TRUE);
    $this->assertFalse($result->isAllowed());
  }

  /**
   * The guide is a page of the site: a real request answers 200.
   */
  public function testRequestAnswers200(): void {
    $member = $this->member();
    $this->container->get('current_user')->setAccount($member);
    $response = $this->container->get('http_kernel')->handle(Request::create('/guide'));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringContainsString('Organizer Guide', (string) $response->getContent());
    $this->assertStringContainsString(self::STEPS[0][0], (string) $response->getContent());
  }

  /**
   * The intro and the six steps: numbered list, h2 each, the words in full.
   */
  public function testIntroAndSixNumberedSteps(): void {
    $html = $this->renderGuide();
    $xpath = $this->xpathOf($html);

    // The title is the page title's h1, so the page body has no second one.
    $this->assertSame(0, $xpath->query('//h1')->length, 'The page title is the only h1.');
    $this->assertContains(self::INTRO, $this->blocks($html));

    $this->assertSame(1, $xpath->query('//ol')->length, 'One numbered list.');
    $items = $xpath->query('//ol/li');
    $this->assertSame(6, $items->length, 'Six steps.');
    foreach (self::STEPS as $index => [$title, $paragraphs]) {
      $heading = $xpath->query('.//h2', $items->item($index));
      $this->assertSame(1, $heading->length, "Step " . ($index + 1) . ' has one h2.');
      $this->assertSame($title, $this->fold($heading->item(0)->textContent));
      $this->assertSame($paragraphs, $this->stepParagraphs($xpath, $index + 1), 'Step ' . ($index + 1) . ' words, in full.');
    }
  }

  /**
   * Step 2 links the community's group by its name when the person may open it.
   */
  public function testOpenYourGroupLinksTheGroupByName(): void {
    $group = $this->communityGroup('Bread & Roses Pantry');
    // A site manager may open every group, through an outsider group role
    // tied to the site role, as on the full site.
    $role = Role::create(['id' => 'sitemanager', 'label' => 'Site manager']);
    $role->grantPermission('access content')->save();
    GroupRole::create([
      'id' => 'flexible_group-sitemanager',
      'label' => 'Site manager',
      'group_type' => 'flexible_group',
      'scope' => 'outsider',
      'global_role' => 'sitemanager',
      'permissions' => ['view group'],
    ])->save();
    $manager = User::create(['name' => 'guide-manager', 'mail' => 'guide-manager@mail.localnodes.xyz', 'status' => 1, 'roles' => ['sitemanager']]);
    $manager->save();
    $this->container->get('current_user')->setAccount($manager);
    $this->container->get('router.builder')->rebuild();
    $xpath = $this->xpathOf($this->renderGuide());

    $this->assertSame(['Your community already has a group named after it: Bread & Roses Pantry. Open it. You can rename it, or make more groups, whenever you like.'], $this->stepParagraphs($xpath, 2));
    $links = $xpath->query('//ol/li[2]//a');
    $this->assertSame(1, $links->length);
    $this->assertSame('Bread & Roses Pantry', $links->item(0)->textContent);
    $this->assertSame($group->toUrl()->toString(), $links->item(0)->getAttribute('href'), 'The link goes to the group itself, not the group list.');
  }

  /**
   * Someone who may not open the group gets the not-ready line, which links Get help.
   */
  public function testNotReadyLineWhenThePersonCannotOpenTheGroup(): void {
    $this->communityGroup('Bread & Roses Pantry');
    $this->container->get('current_user')->setAccount($this->member());
    $this->container->get('router.builder')->rebuild();
    $xpath = $this->xpathOf($this->renderGuide());

    $this->assertSame(["Your group isn't ready yet. Get help."], $this->stepParagraphs($xpath, 2));
    $links = $xpath->query('//ol/li[2]//a');
    $this->assertSame(1, $links->length, 'The only link is Get help: the group is not offered.');
    $this->assertSame('Get help', $links->item(0)->textContent);
    $this->assertMatchesRegularExpression('#/help$#', $links->item(0)->getAttribute('href'));
  }

  /**
   * Step 5 names the assistant as the community renamed it.
   */
  public function testAssistantStepUsesTheCommunityName(): void {
    $this->config('social_ai_indexing.settings')->set('assistant_name', 'Pantry Helper')->save();
    $step = $this->stepParagraphs($this->xpathOf($this->renderGuide()), 5);
    $this->assertStringContainsString('The AI assistant, called "Pantry Helper", is at the bottom right.', $step[0]);
    $this->assertStringContainsString("Go to your group's page.", $step[0]);
  }

  /**
   * Without the chat module the step still reads, with no name.
   */
  public function testAssistantStepWithoutTheChat(): void {
    $this->container->set('social_ai_indexing.assistant_name', NULL);
    $step = $this->stepParagraphs($this->xpathOf($this->renderGuide()), 5);
    $this->assertStringStartsWith("Go to your group's page. The AI assistant is at the bottom right. It isn't shown on the Import tab.", $step[0]);
  }

  /**
   * Without localnodes_web3: no fund, no fees; the assistant section and help line stay.
   */
  public function testWithoutWeb3FundAndFeesAreAbsent(): void {
    $html = $this->renderGuide();
    $texts = $this->blocks($html);

    $this->assertNotContains("Your group's fund", $texts);
    $this->assertNotContains('Network fees', $texts);
    $this->assertStringNotContainsString('Fund tab', $this->fold(html_entity_decode(strip_tags($html), ENT_QUOTES)));
    $this->assertContains('Using the assistant', $texts);
    $this->assertContains(self::ASSISTANT_BODY, $texts);
    $this->assertContains(self::HELP_LINE, $texts);
  }

  /**
   * With localnodes_web3: fund, fees and assistant, after the list, in order.
   */
  public function testWithWeb3AllThreeSectionsFollowTheList(): void {
    $this->pretendWeb3IsOn();
    $html = $this->renderGuide();
    $texts = $this->blocks($html);

    $expected = [...self::HEADINGS, "Your group's fund", 'Network fees', 'Using the assistant'];
    $headings = array_values(array_filter($texts, fn (string $text) => in_array($text, $expected, TRUE)));
    $this->assertSame($expected, $headings, 'The three sections follow the six steps, in this order.');

    // They sit outside the numbered list.
    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
    $xpath = new \DOMXPath($dom);
    $this->assertSame(6, $xpath->query('//ol/li')->length);
    $this->assertSame(0, $xpath->query('//ol//h2[. = "Network fees" or . = "Using the assistant" or starts-with(., "Your group")]')->length);

    // The sections' words. Without a first group the Fund tab is named, not linked.
    $this->assertContains(self::FUND_BODY_BEFORE . ' Open the Fund tab to set it up.', $texts);
    $this->assertContains(self::ASSISTANT_BODY, $texts);
    $this->assertSame(self::HELP_LINE, end($texts), 'The help line is last.');
  }

  /**
   * The fees section stops after its second sentence: no monthly-limit page exists to link.
   *
   * 23-13 build record: the tree has no route for an organizer's monthly
   * limit (grep of every *.routing.yml, 2026-11-08), so the last sentence
   * "As an organizer, you can see how much of this month's limit is used."
   * and its link do not ship. This test fails the day the sentence appears
   * without a page behind it.
   */
  public function testFeesSectionOmitsMonthlyLimitSentenceWithoutThePage(): void {
    $this->pretendWeb3IsOn();
    $texts = $this->blocks($this->renderGuide());
    $this->assertContains(self::FEES_BODY, $texts);
    foreach ($texts as $text) {
      $this->assertStringNotContainsString("month's limit", $text);
    }
  }

  /**
   * The Fund tab link shows when the controller has a Fund tab address.
   */
  public function testFundTabIsLinkedWhenItHasAnAddress(): void {
    $this->pretendWeb3IsOn();
    $controller = new class (
      $this->container->get('module_handler'),
      $this->container->get('entity_type.manager'),
      $this->container->get('router.route_provider'),
    ) extends GuideController {

      /**
       * {@inheritdoc}
       */
      protected function fundTabUrl(): ?Url {
        return Url::fromUri('base:group/1/treasury');
      }

    };
    $html = $this->renderGuide($controller);
    $this->assertMatchesRegularExpression('#<a[^>]+href="/group/1/treasury"[^>]*>\s*Open the Fund tab\s*</a>#', $html);
    $this->assertContains(self::FUND_BODY_BEFORE . ' Open the Fund tab to set it up.', $this->blocks($html));
  }

  /**
   * Step 6 links the Manage members tab when the controller has its address.
   */
  public function testManageMembersTabIsLinkedWhenItHasAnAddress(): void {
    $controller = new class (
      $this->container->get('module_handler'),
      $this->container->get('entity_type.manager'),
      $this->container->get('router.route_provider'),
    ) extends GuideController {

      /**
       * {@inheritdoc}
       */
      protected function manageMembersUrl(): ?Url {
        return Url::fromUri('base:group/1/membership');
      }

    };
    $html = $this->renderGuide($controller);
    $this->assertMatchesRegularExpression('#<a[^>]+href="/group/1/membership"[^>]*>\s*Manage members tab\s*</a>#', $html);
  }

  /**
   * Without the tab's page (or the right to open it), the same words, unlinked.
   */
  public function testManageMembersTabIsPlainTextWithoutAnAddress(): void {
    $html = $this->renderGuide();
    $this->assertStringContainsString("open your group's Manage members tab, choose Add members", $this->fold(html_entity_decode(strip_tags($html), ENT_QUOTES)));
    $this->assertDoesNotMatchRegularExpression('#<a[^>]*>\s*Manage members tab#', $html);
  }

  /**
   * Member-facing lines say no place word and no crypto word, with or without web3.
   *
   * The place words are those of scripts/check_copy_rules.mjs. The crypto
   * words are T-4's list.
   */
  public function testNoPlaceWordsAndNoCryptoWords(): void {
    $place = '/\b(your town|your area|local residents|near you|neighbou?rs)\b/i';
    $crypto = '/\b(wallet|token|gas|chain|crypto)/i';
    foreach ([FALSE, TRUE] as $web3) {
      if ($web3) {
        $this->pretendWeb3IsOn();
      }
      $text = $this->fold(strip_tags($this->renderGuide()));
      $this->assertSame(0, preg_match($place, $text), 'No place word' . ($web3 ? ' (web3 on)' : '') . '.');
      $this->assertSame(0, preg_match($crypto, $text), 'No crypto word' . ($web3 ? ' (web3 on)' : '') . '.');
    }
  }

  /**
   * The only reading width is the one the page sets: lines hold to 72ch.
   */
  public function testPageAttachesItsReadingWidthLibrary(): void {
    $build = GuideController::create($this->container)->page();
    $this->assertContains('localnodes_platform/guide', $build['#attached']['library'] ?? []);
  }

  /**
   * The real Group module supplies the controller's permission cache context.
   */
  public function testPageCacheContextsAreAvailable(): void {
    $build = GuideController::create($this->container)->page();
    $contexts = $build['#cache']['contexts'] ?? [];
    $this->assertContains('user.permissions', $contexts);
    $this->assertContains('user.group_permissions', $contexts);
    $this->assertTrue($this->container->get('cache_contexts_manager')->assertValidTokens($contexts));
    $this->assertInstanceOf(GroupPermissionsCacheContext::class, $this->container->get('cache_context.user.group_permissions'));
  }

}
