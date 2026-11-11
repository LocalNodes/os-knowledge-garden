<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\localnodes_platform\Controller\SafetyHelpController;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * The "Hacked or Phished?" help page and its footer link (22.1-15).
 *
 * Tom's ruling of 2026-11-09: a member who was tricked, or whose wallet was
 * taken over, finds what to do on every LocalNodes site, and learns that the
 * group's money can still be reached if the site is down. The words are the
 * help page and footer rows of 22.1-15-UI-SPEC-PROPOSAL.md, approved as
 * written in the orchestrator's Q13 answer (22.1-ORCH-INBOX.md).
 *
 * The page is open to everyone: someone locked out of their account must
 * still be able to read it.
 *
 * Kernel tests don't check module dependencies, so localnodes_platform loads
 * here without the AI stack, as in SiteTermsTest. The live page, with the
 * theme and axe, is checked by tests/a11y/safety-help.spec.ts.
 *
 * @group localnodes_platform
 */
final class SafetyHelpPageTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'filter', 'field', 'block', 'localnodes_platform'];

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  private const PATH = '/help/hacked-or-phished';
  private const TITLE = 'Hacked or Phished?';
  private const SEAL_911_URL = 'https://securityalliance.org/our-work/seal-911';

  private const INTRO = 'If someone tricked you or got into your wallet, act fast. Do these steps in order.';

  /**
   * The six steps, in the ruled order (Tom, 2026-11-09).
   */
  private const STEPS = [
    "Stop approving. Don't approve anything else in your wallet until you know what happened.",
    'Move what you can. On a phone or computer you trust, move anything you still can to a new wallet that only you control.',
    'Take back approvals. Your wallet may have let other apps or sites spend from it. Remove those approvals in your wallet, or ask SEAL 911 to help you do it.',
    "Tell your group's other approvers. They can refuse any payment you didn't mean to approve, and change who approves.",
    "Get free emergency help from SEAL 911, a volunteer security team: SEAL 911's website. Use only this link. No real helper will ask for the secret words or password that open your wallet.",
    'Tell the people who run this site: its organizers, or LocalNodes at hello@localnodes.xyz.',
  ];

  /**
   * The "If this site is down" section's two paragraphs.
   */
  private const SITE_DOWN = [
    "Your group's money isn't kept on this site. It's in a standard Safe account on the blockchain. If this site is down, any approver can open the group's Safe in the Safe app at app.safe.global, approve payments there, and pay the network fee from their own wallet.",
    "On your group fund's page, \"Open in Safe app\" takes you straight there. Save that link somewhere you'll find it.",
  ];

  /**
   * Words the steps never use (UI-SPEC proposal, "Words the steps never use").
   *
   * Matched on word boundaries and case-insensitively, except "Safe", which
   * is the product name: "a phone or computer you trust" may say "safe" in a
   * later edit without naming the product.
   */
  private const FORBIDDEN_IN_STEPS = [
    'blockchain', 'crypto', 'token', 'gas', 'transaction', 'sign', 'signing',
    'signature', 'seed', 'phrase', 'private key', 'revoke', 'multisig',
    'smart contract', 'address',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'user', 'filter']);
    $this->installEntitySchema('user');
    $this->container->get('theme_installer')->install(['localnodes_stock_email_test']);
    $this->config('system.theme')->set('default', 'localnodes_stock_email_test')->save();
    $this->config('system.site')->set('name', 'Repair Collective')->save();
    // Anonymous (uid 0) and a reserved uid 1 so later users are plain.
    User::create(['uid' => 1, 'name' => 'root-reserved', 'status' => 1])->save();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Renders the controller's page and returns its HTML.
   */
  private function renderPage(): string {
    $build = SafetyHelpController::create($this->container)->page();
    return (string) $this->container->get('renderer')->renderRoot($build);
  }

  /**
   * Parses HTML into an XPath over a body.
   */
  private function domXpath(string $html): \DOMXPath {
    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
    return new \DOMXPath($dom);
  }

  /**
   * Folds whitespace so a copy line compares equal to rendered text.
   */
  private function fold(string $text): string {
    return trim((string) preg_replace('/\s+/', ' ', $text));
  }

  /**
   * The route is /help/hacked-or-phished, titled "Hacked or Phished?".
   */
  public function testRouteAndTitle(): void {
    $route = $this->container->get('router.route_provider')->getRouteByName('localnodes_platform.safety_help');
    $this->assertSame(self::PATH, $route->getPath());
    $this->assertSame(self::TITLE, (string) $route->getDefault('_title'));
    $request = Request::create(self::PATH);
    $this->assertSame(self::TITLE, (string) $this->container->get('title_resolver')->getTitle($request, $route));
  }

  /**
   * Anyone may read the page, signed in or not.
   */
  public function testAnonymousVisitorCanReadThePage(): void {
    $anonymous = $this->container->get('current_user');
    $this->assertTrue($anonymous->isAnonymous());
    // No "access content" for anonymous: the page must still open.
    $this->assertFalse(Role::load('anonymous')->hasPermission('access content'));
    $result = $this->container->get('access_manager')->checkNamedRoute('localnodes_platform.safety_help', [], $anonymous, TRUE);
    $this->assertTrue($result->isAllowed());

    $response = $this->container->get('http_kernel')->handle(Request::create(self::PATH));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringContainsString(self::INTRO, html_entity_decode((string) $response->getContent(), ENT_QUOTES));
  }

  /**
   * The intro, the six steps in order, word for word.
   */
  public function testStepsWordForWord(): void {
    $xpath = $this->domXpath($this->renderPage());

    $this->assertSame(0, $xpath->query('//h1')->length, 'The route title is the page h1; the body adds none.');
    $this->assertSame(1, $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " localnodes-site-terms ")][contains(concat(" ", normalize-space(@class), " "), " localnodes-safety-help ")]')->length, 'The wrapper carries the terms reading layout and its own class.');
    $this->assertSame(self::INTRO, $this->fold($xpath->query('//p')->item(0)?->textContent ?? ''), 'The intro opens the page.');

    $headings = [];
    foreach ($xpath->query('//section/h2') as $node) {
      $headings[] = $this->fold($node->textContent);
    }
    $this->assertSame(['What to do now', 'If this site is down'], $headings);

    $this->assertSame(1, $xpath->query('//ol')->length, 'The steps are one ordered list.');
    $steps = [];
    foreach ($xpath->query('//section[h2[normalize-space(.)="What to do now"]]/ol/li') as $node) {
      $steps[] = $this->fold($node->textContent);
    }
    $this->assertSame(self::STEPS, $steps, 'Six steps, in the ruled order, with the approved words.');

    $seal = $xpath->query('//ol/li[5]//a')->item(0);
    $this->assertNotNull($seal, 'Step 5 links SEAL 911.');
    $this->assertSame(self::SEAL_911_URL, $seal->getAttribute('href'));
    $this->assertSame("SEAL 911's website", $this->fold($seal->textContent));
    $this->assertSame(self::SEAL_911_URL, SafetyHelpController::SEAL_911_URL);

    $mail = $xpath->query('//ol/li[6]//a')->item(0);
    $this->assertNotNull($mail, 'Step 6 links the help address.');
    $this->assertSame('mailto:hello@localnodes.xyz', $mail->getAttribute('href'));
  }

  /**
   * The steps use none of the words a scared newcomer can't follow.
   */
  public function testStepsAvoidJargon(): void {
    $xpath = $this->domXpath($this->renderPage());
    $section = $xpath->query('//section[h2[normalize-space(.)="What to do now"]]')->item(0);
    $this->assertNotNull($section);
    $text = $this->fold($section->textContent);

    foreach (self::FORBIDDEN_IN_STEPS as $word) {
      $this->assertDoesNotMatchRegularExpression('/\b' . preg_quote($word, '/') . '\b/i', $text, "The steps say \"$word\".");
    }
    $this->assertDoesNotMatchRegularExpression('/\bSafe\b/', $text, 'The steps name the Safe product.');
    // The ruling allows these two.
    $this->assertMatchesRegularExpression('/\bapprove\b/i', $text);
    $this->assertMatchesRegularExpression('/\bwallet\b/i', $text);
  }

  /**
   * The only ways off the site are SEAL 911's official page and the mail link.
   *
   * No chat link, handle or phone number: a scammer posing as a helper is the
   * next thing a phished member meets, so the page gives one checked page.
   */
  public function testOnlyTheOfficialContactsLeaveTheSite(): void {
    $html = $this->renderPage();
    $xpath = $this->domXpath($html);

    $outbound = [];
    foreach ($xpath->query('//a[@href]') as $link) {
      $href = $link->getAttribute('href');
      if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $href)) {
        $outbound[] = $href;
      }
    }
    sort($outbound);
    $this->assertSame(['https://securityalliance.org/our-work/seal-911', 'mailto:hello@localnodes.xyz'], $outbound);

    $text = $this->fold($xpath->query('//body')->item(0)->textContent);
    foreach (['#t\.me\b#i', '#discord#i', '#telegram#i'] as $pattern) {
      $this->assertDoesNotMatchRegularExpression($pattern, $html, "The page matches $pattern.");
    }
    $this->assertDoesNotMatchRegularExpression('/\+?\d[\d\s().-]{6,}\d/', $text, 'The page shows a phone number.');
    $handles = preg_replace('/\bhello@localnodes\.xyz\b/', '', $text);
    $this->assertDoesNotMatchRegularExpression('/@\w/', (string) $handles, 'The page shows an @handle.');
  }

  /**
   * "If this site is down": where the money is, and how to reach it.
   */
  public function testIfThisSiteIsDown(): void {
    $xpath = $this->domXpath($this->renderPage());
    $section = $xpath->query('//section[@id="if-this-site-is-down"]')->item(0);
    $this->assertNotNull($section, 'The section has the id the treasury hint can point at.');
    $this->assertSame('If this site is down', $this->fold($xpath->query('./h2', $section)->item(0)?->textContent ?? ''));

    $paragraphs = [];
    foreach ($xpath->query('./p', $section) as $node) {
      $paragraphs[] = $this->fold($node->textContent);
    }
    $this->assertSame(self::SITE_DOWN, $paragraphs);
    $this->assertStringContainsString('Safe app', $paragraphs[0]);
    $this->assertStringContainsString('network fee from their own wallet', $paragraphs[0]);

    $this->assertSame(1, $xpath->query('//section[h2[normalize-space(.)="What to do now"]]/following-sibling::section[@id="if-this-site-is-down"]')->length, 'The section comes after the steps.');
  }

  /**
   * A site name with markup in it prints as text on the page.
   *
   * The approved words don't name the community, so the body prints no site
   * name. The page's head title does ("Hacked or Phished? | ‹site›"), and a
   * site manager can edit the name, so the delivered page is checked.
   */
  public function testSiteNameIsEscaped(): void {
    $this->config('system.site')->set('name', '<b>x</b>')->save();
    $response = $this->container->get('http_kernel')->handle(Request::create(self::PATH));
    $html = (string) $response->getContent();
    $this->assertStringNotContainsString('<b>x</b>', $html);
    $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
    $this->assertStringNotContainsString('<b>x</b>', $this->renderPage());
  }

  /**
   * The page follows a site rename.
   */
  public function testCacheTags(): void {
    $build = SafetyHelpController::create($this->container)->page();
    $this->assertContains('config:system.site', $build['#cache']['tags'] ?? []);
    $this->assertContains('localnodes_platform/site_terms', $build['#attached']['library'] ?? [], 'The page reuses the terms reading layout.');
  }

  /**
   * The footer links the page after "Site terms", with the same middot.
   */
  public function testFooterLinksTheHelpPage(): void {
    $block = $this->container->get('plugin.manager.block')->createInstance('localnodes_footer');
    $build = $block->build();
    $html = (string) $this->container->get('renderer')->renderRoot($build);

    $this->assertMatchesRegularExpression(
      '#>Site terms</a>\s*<span aria-hidden="true">&middot;</span>\s*<a href="[^"]*/help/hacked-or-phished">Hacked or phished\?</a>#',
      $html,
    );
  }

}
