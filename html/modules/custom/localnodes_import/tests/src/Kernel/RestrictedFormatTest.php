<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Kernel;

use Drupal\filter\Entity\FilterFormat;
use Drupal\localnodes_import\Service\HtmlSanitizer;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\Role;

/**
 * Imported HTML is cleaned before it is stored, and kept that way (E8).
 *
 * Threat T-22-51: a hostile page must not leave a script, a frame, a form,
 * an image, inline styles, classes or event handlers in a stored Topic
 * body. Quoted writers keep their quotes and credit lines (D-14).
 *
 * @group phase22
 * @group localnodes_import
 */
final class RestrictedFormatTest extends ImportKernelTestBase {

  /**
   * Markup that must never be in a stored or rendered body.
   */
  private const BANNED = [
    '<script', '<style', '<iframe', '<form', '<img', '<button',
    'onerror', 'onclick', 'style=', 'class=',
  ];

  /**
   * The E8 injection page (22-04).
   */
  private function injectionPage(): string {
    $path = dirname(__DIR__, 4) . '/social_ai_indexing/tests/fixtures/eval/injection-page.html';
    $this->assertFileExists($path);
    return (string) file_get_contents($path);
  }

  /**
   * The Bioregionalism action=parse HTML (22-02 P2P fixture).
   */
  private function bioregionalismText(): string {
    $path = dirname(__DIR__, 2) . '/fixtures/mediawiki/bioregionalism.parse.json';
    $this->assertFileExists($path);
    $json = json_decode((string) file_get_contents($path), TRUE, 512, JSON_THROW_ON_ERROR);
    return $json['parse']['text'];
  }

  /**
   * Asserts that none of the banned markup is in $html.
   */
  private function assertClean(string $html, string $what): void {
    foreach (self::BANNED as $banned) {
      $this->assertStringNotContainsStringIgnoringCase($banned, $html, "$what contains $banned");
    }
  }

  /**
   * The E8 page loses every script, style, frame, form, image and handler.
   */
  public function testInjectionPageIsCleaned(): void {
    $clean = (new HtmlSanitizer())->sanitize($this->injectionPage(), 'https://evil.example/skill-share');
    $this->assertClean($clean, 'The sanitised E8 page');
    // The content of removed elements goes too, not just their tags.
    $this->assertStringNotContainsString('background: #000', $clean, 'Style rules are not left behind as text'); // raw-color-allow: test input, the style rule the sanitiser must remove
    $this->assertStringNotContainsString('void 0', $clean, 'Script and handler code is not left behind as text');
    $this->assertStringNotContainsString('<!--', $clean, 'Comments are removed');
    // The readable text stays: it is data, and the answer pipeline fences it.
    $this->assertStringContainsString('Skill-Share Circle', $clean);
    $this->assertStringContainsString('Sessions last an hour', $clean);
  }

  /**
   * Blockquotes and their credit lines survive; wiki links become absolute.
   *
   * Prohibition: quoted third-party writers are never presented as the
   * community's or the wiki's own words (D-14).
   */
  public function testBlockquotesAndLinksOnTheP2pFixture(): void {
    $text = $this->bioregionalismText();
    $this->assertSame(2, substr_count($text, '<blockquote'), 'Fixture precondition: two blockquotes');

    $clean = (new HtmlSanitizer())->sanitize($text, 'https://wiki.p2pfoundation.net/Bioregionalism');
    $this->assertClean($clean, 'The sanitised Bioregionalism page');
    $this->assertSame(2, substr_count($clean, '<blockquote'), 'Every blockquote is kept');
    $this->assertSame(2, substr_count($clean, '</blockquote>'), 'Every blockquote is closed');
    $this->assertStringContainsString('a distinct area with coherent and interconnected plant and animal communities', $clean, 'The first quote keeps its words');
    $this->assertMatchesRegularExpression('#<blockquote>\s*<p>\[Bioregion\] <em>"refers both to a geographical terrain#', $clean, 'The quote stays a quote, with its emphasis');
    $this->assertMatchesRegularExpression('#</blockquote>\s*<p>\(Source: Peter Berg and Raymond Dasmann#', $clean, 'The credit line follows its quote');

    $this->assertStringContainsString('href="https://wiki.p2pfoundation.net/Bioregional_Movement"', $clean, 'Root-relative wiki links are absolute on the wiki host');
    $this->assertDoesNotMatchRegularExpression('#href="/#', $clean, 'No root-relative link is left');
    $this->assertStringNotContainsString('mw-parser-output', $clean);
  }

  /**
   * The imported_html format ships in config/install and renders clean.
   */
  public function testFormatAndStoredBody(): void {
    $format = FilterFormat::load('imported_html');
    $this->assertNotNull($format, 'The imported_html format is installed with the module');
    $filters = $format->filters();
    $this->assertTrue($filters->get('filter_html')->status, 'filter_html is on');
    $this->assertTrue((bool) $filters->get('filter_html')->settings['filter_html_nofollow'], 'Links get rel=nofollow');
    $this->assertTrue($filters->get('filter_htmlcorrector')->status, 'The HTML corrector is on');
    $this->assertNull($this->config('editor.editor.imported_html')->get('format'), 'No editor ships for the format');

    // The sanitiser's tag list is the format's tag list.
    preg_match_all('/<([a-z0-9]+)/', (string) $filters->get('filter_html')->settings['allowed_html'], $matches);
    $format_tags = $matches[1];
    sort($format_tags);
    $sanitizer_tags = HtmlSanitizer::ALLOWED_TAGS;
    sort($sanitizer_tags);
    $this->assertSame($format_tags, $sanitizer_tags);

    // A Topic body, saved and reloaded, holds no banned markup, and renders
    // through the format without any.
    $clean = (new HtmlSanitizer())->sanitize($this->injectionPage(), 'https://evil.example/skill-share');
    $node = Node::create([
      'type' => 'topic',
      'title' => 'Skill-Share Circle',
      'body' => ['value' => $clean, 'format' => 'imported_html'],
    ]);
    $node->save();
    $stored = Node::load($node->id())->get('body');
    $this->assertSame('imported_html', $stored->format);
    $this->assertClean((string) $stored->value, 'The stored body');
    $rendered = (string) check_markup((string) $stored->value, 'imported_html');
    $this->assertClean($rendered, 'The rendered body');
    $this->assertStringContainsString('Skill-Share Circle', $rendered);
  }

  /**
   * Roles that edit imported Topics can use the format (Open Question 5).
   */
  public function testFormatPermission(): void {
    foreach (['sitemanager', 'contentmanager', 'authenticated'] as $rid) {
      $role = Role::load($rid);
      $this->assertNotNull($role, "Role $rid exists");
      $this->assertTrue($role->hasPermission('use text format imported_html'), "$rid can use imported_html after install");
    }
    $this->assertFalse(Role::load('anonymous')->hasPermission('use text format imported_html'), 'Anonymous visitors cannot');

    // Idempotent: running the grant again changes nothing and doesn't fail.
    $this->container->get('module_handler')->loadInclude('localnodes_import', 'install');
    _localnodes_import_grant_text_format();
    $this->assertTrue(Role::load('sitemanager')->hasPermission('use text format imported_html'));
  }

}
