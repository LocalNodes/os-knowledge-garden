<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Functional;

/**
 * The overview's JSON holds only allow-listed HTML and local links (E8).
 *
 * AI-SPEC pitfall 20, T-22-63. Imported pages can carry prompt injection,
 * so the model may emit markup. Given a scripted reply with a script
 * element, event handlers, an iframe and evil.example links, the HTTP
 * response's summary_html holds only p, strong, em, ul, ol and li, no
 * evil.example, and every Sources url is root-relative.
 *
 * @group social_ai_indexing
 * @group phase22
 * @group phase22_functional
 */
final class OverviewSanitiseTest extends OverviewFunctionalTestBase {

  /**
   * The allowed tags.
   */
  private const ALLOWED_TAGS = ['p', 'strong', 'em', 'ul', 'ol', 'li'];

  /**
   * Model markup is filtered on the server before it is sent.
   */
  public function testSummaryIsFilteredOverHttp(): void {
    $this->setHttpScript([
      'mode' => 'text',
      'text' => "<script>document.location='https://evil.example/steal'</script><p onclick=\"steal()\" style=\"color:red\">Fictional notes <strong>matter</strong> [R1].</p>\n\nRead <a href=\"https://evil.example/x\">this page</a> or https://evil.example/y <iframe src=\"https://evil.example/z\"></iframe> <img src=\"x\" onerror=\"steal()\"> <em>soon</em> [R2].", // raw-color-allow: test input, a style attribute the sanitiser must strip
    ]);
    $data = $this->requestOverview($this->personas['P-MEM'], self::MEMBERS_ONLY_KEYWORD);
    $html = (string) $data['summary_html'];
    $this->assertNotSame('', $html, 'An answer was returned (else the check is vacuous).');
    $this->assertCount(1, $this->httpOverviewCalls(), 'One model call.');

    preg_match_all('#</?\s*([a-zA-Z0-9]+)#', $html, $matches);
    foreach (array_unique(array_map('strtolower', $matches[1])) as $tag) {
      $this->assertContains($tag, self::ALLOWED_TAGS, "Tag <$tag> is allow-listed.");
    }
    foreach (['evil.example', 'onclick', 'onerror', 'style=', 'href', 'src='] as $needle) {
      $this->assertStringNotContainsString($needle, $html, "summary_html holds no '$needle'.");
    }
    $this->assertStringContainsString('<strong>matter</strong>', $html, 'Allowed emphasis is kept.');
    $this->assertMatchesRegularExpression('/\[1\].*\[2\]/s', $html, 'Markers run [1], [2].');

    $this->assertNotEmpty($data['sources']);
    foreach ($data['sources'] as $i => $source) {
      $this->assertSame($i + 1, $source['n']);
      $this->assertStringStartsWith('/', $source['url'], "Source {$source['n']} is root-relative.");
      $this->assertStringStartsNotWith('//', $source['url']);
      $this->assertStringNotContainsString('evil.example', $source['url']);
      $this->assertNotSame('', $source['title']);
    }
  }

}
