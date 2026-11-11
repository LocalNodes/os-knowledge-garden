<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Functional;

/**
 * The search page says search words go to the AI service, overview or not.
 *
 * The overview script removes its panel when no overview comes back, so a
 * line inside the panel would vanish with it. The line comes from the
 * overview block, outside the panel: it is checked on the page as served,
 * and again with the panel taken out, as the script does.
 *
 * @group social_ai_indexing
 */
final class OverviewNoticeTest extends OverviewFunctionalTestBase {

  private const NOTICE = 'Your search words, and passages from pages you can see, are sent to the AI service to write an overview.';

  /**
   * The line is on the search page and stays when the panel is removed.
   */
  public function testTheLineStaysWithoutThePanel(): void {
    $this->drupalLogin($this->personas['P-MEM']);
    $this->drupalGet('/search/all/' . self::MEMBERS_ONLY_KEYWORD);
    $this->assertSame(200, $this->getSession()->getStatusCode());

    $dom = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    $dom->loadHTML($this->getSession()->getPage()->getContent());
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new \DOMXPath($dom);

    $panels = $xpath->query('//*[@data-ai-overview]');
    $this->assertSame(1, $panels->length, 'Precondition: the overview panel is on the page.');

    // What the script does when no overview comes back.
    $panel = $panels->item(0);
    $panel->parentNode->removeChild($panel);

    $lines = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " ai-overview-notice ")]');
    $this->assertSame(1, $lines->length, 'One AI service line, outside the panel');
    $line = $lines->item(0);
    $this->assertSame(self::NOTICE, trim($line->textContent));
    for ($node = $line; $node instanceof \DOMElement; $node = $node->parentNode) {
      $this->assertFalse($node->hasAttribute('hidden'), 'Neither the line nor what holds it is hidden');
      $this->assertStringNotContainsString('visually-hidden', $node->getAttribute('class'));
    }
  }

  /**
   * Without the overview there is no overview line either.
   */
  public function testNoLineForVisitorsWithoutTheOverview(): void {
    user_role_revoke_permissions('anonymous', ['use ai overview']);
    $this->drupalGet('/search/all/' . self::MEMBERS_ONLY_KEYWORD);
    $this->assertSession()->elementNotExists('css', '[data-ai-overview]');
    $this->assertSession()->pageTextNotContains(self::NOTICE);
  }

}
