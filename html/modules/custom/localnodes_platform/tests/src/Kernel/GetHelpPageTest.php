<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\localnodes_platform\Controller\GetHelpController;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * The "Get help" page at /help, and its footer link.
 *
 * Several screens tell people to use Get help; this is the page they reach.
 * It says who to ask first, where the report link is, and the one address
 * for a sign-in or site problem. It promises no hours and no response time.
 *
 * Open to everyone: someone who can't sign in must still be able to read it.
 *
 * @group localnodes_platform
 */
final class GetHelpPageTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'filter', 'field', 'block', 'localnodes_platform'];

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  private const PATH = '/help';

  /**
   * The page's lines, word for word, in order.
   */
  private const LINES = [
    "Ask your group's organizers first. They can see your group's settings and documents.",
    "Something wrong in an imported document, such as private information or a mistake? Open it and use its \"Report a problem\" link. That link is only on imported documents; for anything else, ask your group's organizers.",
    "Can't sign in, or the site isn't working? Email LocalNodes at hello@localnodes.xyz.",
    'If someone else got into your account: Hacked or phished?',
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
    User::create(['uid' => 1, 'name' => 'root-reserved', 'status' => 1])->save();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Renders the controller's page and returns its HTML.
   */
  private function renderPage(): string {
    $build = GetHelpController::create($this->container)->page();
    return (string) $this->container->get('renderer')->renderRoot($build);
  }

  /**
   * Folds whitespace so a copy line compares equal to rendered text.
   */
  private function fold(string $text): string {
    return trim((string) preg_replace('/\s+/', ' ', $text));
  }

  /**
   * The route is /help, titled "Get help".
   */
  public function testRouteAndTitle(): void {
    $route = $this->container->get('router.route_provider')->getRouteByName('localnodes_platform.get_help');
    $this->assertSame(self::PATH, $route->getPath());
    $request = Request::create(self::PATH);
    $this->assertSame('Get help', (string) $this->container->get('title_resolver')->getTitle($request, $route));
  }

  /**
   * Anyone may read the page, signed in or not.
   */
  public function testAnonymousVisitorCanReadThePage(): void {
    $anonymous = $this->container->get('current_user');
    $this->assertTrue($anonymous->isAnonymous());
    $this->assertFalse(Role::load('anonymous')->hasPermission('access content'));
    $result = $this->container->get('access_manager')->checkNamedRoute('localnodes_platform.get_help', [], $anonymous, TRUE);
    $this->assertTrue($result->isAllowed());

    $response = $this->container->get('http_kernel')->handle(Request::create(self::PATH));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringContainsString(self::LINES[2], $this->fold(html_entity_decode(strip_tags((string) $response->getContent()), ENT_QUOTES)));
  }

  /**
   * The lines, word for word, in order, with their two links.
   */
  public function testLinesWordForWord(): void {
    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?><body>' . $this->renderPage() . '</body>');
    $xpath = new \DOMXPath($dom);
    $lines = [];
    foreach ($xpath->query('//div[contains(@class, "localnodes-get-help")]//p') as $p) {
      $lines[] = $this->fold($p->textContent);
    }
    $this->assertSame(self::LINES, $lines);

    $mail = $xpath->query('//a[@href="mailto:hello@localnodes.xyz"]');
    $this->assertSame(1, $mail->length);
    $this->assertSame('hello@localnodes.xyz', $mail->item(0)->textContent);
    $hacked = $xpath->query('//a[substring(@href, string-length(@href) - string-length("/help/hacked-or-phished") + 1) = "/help/hacked-or-phished"]');
    $this->assertSame(1, $hacked->length);
    $this->assertSame('Hacked or phished?', $hacked->item(0)->textContent);
  }

  /**
   * No hours and no response-time promise.
   */
  public function testPromisesNoHoursOrResponseTime(): void {
    $text = strtolower($this->fold(strip_tags($this->renderPage())));
    foreach (['hour', 'within', 'business day', 'respond', 'reply', '24/7', 'minutes'] as $word) {
      $this->assertStringNotContainsString($word, $text);
    }
  }

  /**
   * The page reuses the terms reading layout.
   */
  public function testLayout(): void {
    $build = GetHelpController::create($this->container)->page();
    $this->assertContains('localnodes_platform/site_terms', $build['#attached']['library'] ?? []);
  }

}
