<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\localnodes_platform\Controller\GuideController;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * The organizer guide at /guide (23-13, ONB-03, T-4).
 *
 * Proves the page is signed-in only, that its words are the 23-UI-SPEC
 * section 8 words (title, intro, five numbered h2 steps, then the T-4
 * sections outside the list, then the help line), that the fund and fees
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
   */
  protected static $modules = ['system', 'user', 'filter', 'field', 'localnodes_platform'];

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  /**
   * UI-SPEC section 8: the five steps, as [h2, body].
   */
  private const STEPS = [
    ['Set your password', "Open the sign-in link in your welcome email. It brings you to your site and asks you to choose a password. Choose one and save it. You're signed in."],
    ['Open your group', 'Your community already has one group, named after it. Open it from the menu. You can rename it, or make more groups, whenever you like.'],
    ['Upload your first files', 'In your group, open the Import tab and choose Upload files. Choose your files, tick the box that says you have the right to share them, and press the Import button.'],
    ['Wait for search to catch up', 'The Search status panel on the Import tab shows how many of your pages are searchable, for example "18 of 21". Search catches up in the background, usually within a few minutes. You can leave the page.'],
    ['Ask the AI assistant a question', 'Open the AI assistant and ask something your files answer. The answer shows numbered sources. Open one to check it. When you see Sources under an answer, you got your first cited answer.'],
  ];

  /**
   * UI-SPEC section 8: the intro line.
   */
  private const INTRO = 'This guide takes you from your sign-in link to your first answer with sources.';

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
  private const ASSISTANT_BODY = 'Ask the assistant about anything in your community\'s files and posts, for example "When is the next meeting?" or "What did we decide about the budget?". Every answer lists its sources, so you can check it. It only sees what you\'re allowed to see: two people asking the same question can get different answers. It can\'t change anything on the site yet, and it can\'t see your email or other websites.';

  /**
   * UI-SPEC section 8: the help line.
   */
  private const HELP_LINE = 'Stuck? Use Get help in the footer.';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'user', 'filter']);
    $this->installEntitySchema('user');
    $this->container->get('theme_installer')->install(['localnodes_stock_email_test']);
    $this->config('system.theme')->set('default', 'localnodes_stock_email_test')->save();
    // Anonymous (uid 0) and a reserved uid 1 so later users are plain.
    User::create(['uid' => 1, 'name' => 'root-reserved', 'status' => 1])->save();
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
   * The intro and the five steps: numbered list, h2 each, the approved words.
   */
  public function testIntroAndFiveNumberedSteps(): void {
    $html = $this->renderGuide();
    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
    $xpath = new \DOMXPath($dom);

    // The title is the page title's h1, so the page body has no second one.
    $this->assertSame(0, $xpath->query('//h1')->length, 'The page title is the only h1.');
    $this->assertContains(self::INTRO, $this->blocks($html));

    $list = $xpath->query('//ol');
    $this->assertSame(1, $list->length, 'One numbered list.');
    $items = $xpath->query('//ol/li');
    $this->assertSame(5, $items->length, 'Five steps.');
    foreach (self::STEPS as $index => [$title, $body]) {
      $item = $items->item($index);
      $heading = (new \DOMXPath($dom))->query('.//h2', $item);
      $this->assertSame(1, $heading->length, "Step " . ($index + 1) . ' has one h2.');
      $this->assertSame($title, $this->fold($heading->item(0)->textContent));
      $paragraph = (new \DOMXPath($dom))->query('.//p', $item);
      $this->assertSame($body, $this->fold($paragraph->item(0)->textContent), 'Step ' . ($index + 1) . ' body, word for word.');
    }
  }

  /**
   * Without localnodes_web3: no fund, no fees; the assistant section and help line stay.
   */
  public function testWithoutWeb3FundAndFeesAreAbsent(): void {
    $html = $this->renderGuide();
    $texts = $this->blocks($html);

    $this->assertNotContains("Your group's fund", $texts);
    $this->assertNotContains('Network fees', $texts);
    $this->assertStringNotContainsString('Fund tab', $this->fold(strip_tags($html)));
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

    $headings = array_values(array_filter($texts, fn (string $text) => in_array($text, [
      'Set your password', 'Open your group', 'Upload your first files',
      'Wait for search to catch up', 'Ask the AI assistant a question',
      "Your group's fund", 'Network fees', 'Using the assistant',
    ], TRUE)));
    $this->assertSame([
      'Set your password', 'Open your group', 'Upload your first files',
      'Wait for search to catch up', 'Ask the AI assistant a question',
      "Your group's fund", 'Network fees', 'Using the assistant',
    ], $headings, 'The three sections follow the five steps, in this order.');

    // They sit outside the numbered list.
    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
    $xpath = new \DOMXPath($dom);
    $this->assertSame(5, $xpath->query('//ol/li')->length);
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

}
