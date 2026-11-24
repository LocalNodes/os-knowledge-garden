<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\ai_chatbot\Controller\DeepChatApi;
use Drupal\Component\Utility\Xss;
use Drupal\KernelTests\KernelTestBase;
use Drupal\social_ai_indexing\Citation\CitedAnswer;
use Drupal\social_ai_indexing\Citation\RetrievedSource;

/**
 * A Sources entry renders as one link with the name shown as written.
 *
 * Runs the answer's markdown through the chat's own steps: DeepChatApi's
 * link rewrite, the site's CommonMark converter (ai.commonmark_converter)
 * and the Xss filter with DeepChatApi's allowed tags.
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class CitationMarkdownRenderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'key', 'ai', 'ai_chatbot'];

  /**
   * Punctuation in titles and names stays text, inside exactly one link.
   *
   * @dataProvider punctuation
   */
  public function testSourcesEntryRendersOneLinkWithLiteralText(string $title, string $author): void {
    $answer = CitedAnswer::cited('A point [1].', [new RetrievedSource(1, 'node', 36, $title, 'Blog', '/node/36', $author)]);
    $html = $this->renderChat($answer->toMarkdown());

    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>');
    $xpath = new \DOMXPath($dom);
    $links = $xpath->query('//li//a');
    $this->assertSame(1, $links->length, $html);
    $this->assertSame('/node/36', $links->item(0)->getAttribute('href'));
    $this->assertSame($title . ', by ' . $author, $links->item(0)->textContent, $html);
    $this->assertSame(0, $xpath->query('//li//em | //li//strong | //li//code | //li//del | //script')->length, $html);
  }

  /**
   * Titles and names with characters markdown or HTML would read.
   */
  public static function punctuation(): array {
    return [
      'trailing backslash' => ['Notes', 'Ana\\'],
      'backticks' => ['Use `code` here', 'Bo `x`'],
      'asterisks and underscores' => ['A *bold* claim', 'Ana **Ruiz** _x_'],
      'entity text' => ['Fish &amp; chips', 'Ana &copy; Ruiz'],
      'angle brackets' => ['Less <b>more</b>', 'Ana <script>alert(1)</script>'],
      'tilde and hash' => ['~~gone~~ #1', 'Cy ~~D~~'],
    ];
  }

  /**
   * Renders answer markdown the way DeepChatApi does.
   */
  private function renderChat(string $markdown): string {
    $api = (new \ReflectionClass(DeepChatApi::class))->newInstanceWithoutConstructor();
    $markdown = $api->rewriteMarkdownMessage($markdown);
    $html = $this->container->get('ai.commonmark_converter')->convert($markdown)->getContent();
    return Xss::filter($html, DeepChatApi::ALLOWED_TAGS);
  }

}
