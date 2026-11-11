<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\localnodes_platform\Drush\Commands\OrganizerCommands;
use Drupal\user\Entity\Role;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The organizer welcome mail's footer is the 22.1-07 template's (23-13, T-5).
 *
 * Tom (2026-11-08, Board 9): the footer "Sent by ‹community name› · a
 * community on LocalNodes" sits under the card, on the grey canvas, once.
 * The canvas drew it inside the card by mistake; the template wins
 * (23-UI-SPEC "Footers (T-5)"). This test renders the real welcome mail
 * through the mailer stack and reads the HTML, so a builder or body template
 * that prints its own footer, or fills the template's footer slot, fails.
 *
 * The mail goes to symfony_mailer_test's null transport; no real mail is sent.
 *
 * @group localnodes_platform
 * @group phase23
 */
final class OrganizerWelcomeFooterTest extends LocalNodesEmailKernelTestBase {

  /**
   * The organizer's address: fictional, on a domain the mail filter lets pass.
   */
  private const ORGANIZER_EMAIL = 'jordan.footer@mail.localnodes.xyz';

  /**
   * The footer line the 22.1-07 template prints.
   */
  private const FOOTER = 'Sent by Repair Collective · a community on LocalNodes';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    Role::create(['id' => 'sitemanager', 'label' => 'Site manager'])->save();
    _localnodes_platform_configure_email();
    putenv('ORGANIZER_EMAIL=' . self::ORGANIZER_EMAIL);
    putenv('ORGANIZER_NAME=' . static::SITE_NAME);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('ORGANIZER_EMAIL');
    putenv('ORGANIZER_NAME');
    parent::tearDown();
  }

  /**
   * Sends the welcome mail through the command and parses its HTML.
   */
  private function welcomeDocument(): \DOMDocument {
    $command = new OrganizerCommands();
    $command->setOutput(new BufferedOutput());
    $this->assertSame(0, $command->organizerWelcome());
    // readMail() asserts there is exactly one mail.
    $this->readMail();
    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . (string) $this->email->getHtmlBody());
    return $dom;
  }

  /**
   * Folds whitespace so rendered text compares equal to a copy line.
   */
  private function fold(string $text): string {
    return trim((string) preg_replace('/\s+/', ' ', str_replace("\u{a0}", ' ', $text)));
  }

  /**
   * The footer line appears exactly once in the mail.
   */
  public function testFooterAppearsExactlyOnce(): void {
    $dom = $this->welcomeDocument();
    $text = $this->fold((string) $dom->getElementsByTagName('body')->item(0)->textContent);
    $this->assertSame(1, substr_count($text, self::FOOTER), 'The footer line is in the mail once.');
    $this->assertSame(1, substr_count($text, 'a community on LocalNodes'), 'No second "a community on LocalNodes" line.');
  }

  /**
   * The footer sits in the template's footer block, after the card, and no part of the card holds it.
   */
  public function testFooterIsUnderTheCardAndNeverInsideIt(): void {
    $dom = $this->welcomeDocument();
    $xpath = new \DOMXPath($dom);

    $footers = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " footer ")]');
    $this->assertSame(1, $footers->length, 'One footer block.');
    $this->assertSame(self::FOOTER, $this->fold($footers->item(0)->textContent));

    $cards = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " card ")]');
    $this->assertSame(1, $cards->length, 'One card.');
    $card = $cards->item(0);
    $this->assertStringNotContainsString('a community on LocalNodes', $card->textContent, 'No part of the card holds the footer.');
    $this->assertStringNotContainsString('Sent by', $card->textContent, 'No "Sent by" line inside the card.');

    // The footer block follows the card as its next element sibling, so it is
    // under the card on the canvas, not nested in it.
    $next = $card->nextSibling;
    while ($next !== NULL && $next->nodeType !== XML_ELEMENT_NODE) {
      $next = $next->nextSibling;
    }
    $this->assertNotNull($next);
    $this->assertTrue($next->isSameNode($footers->item(0)), 'The footer is the card\'s next sibling.');
    $this->assertSame(0, $xpath->query('ancestor::div[contains(concat(" ", normalize-space(@class), " "), " card ")]', $footers->item(0))->length);
  }

  /**
   * The template's optional footer slot row, inside the card, is not rendered.
   */
  public function testCardFooterSlotRowIsNotRendered(): void {
    $dom = $this->welcomeDocument();
    $xpath = new \DOMXPath($dom);

    // The card's content table has one row for the mail body. A footer slot
    // (or a wallet line, or a header slot) would be another row.
    $rows = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " card ")]//td[contains(concat(" ", normalize-space(@class), " "), " wrapper ")]/table/tr | //div[contains(concat(" ", normalize-space(@class), " "), " card ")]//td[contains(concat(" ", normalize-space(@class), " "), " wrapper ")]/table/tbody/tr');
    $this->assertSame(1, $rows->length, 'The card holds the body row only: no header, footer or wallet row.');
    $this->assertStringContainsString('Your community\'s site is ready.', $this->fold($rows->item(0)->textContent));
  }

  /**
   * The subject, and the card's first line, are the community's name.
   */
  public function testSubjectAndCardOpeningCarryTheCommunityName(): void {
    $dom = $this->welcomeDocument();
    $this->assertSubject('Welcome to ' . static::SITE_NAME);

    $xpath = new \DOMXPath($dom);
    $first = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " card ")]//td[contains(concat(" ", normalize-space(@class), " "), " site-name ")]');
    $this->assertSame(1, $first->length, 'The card opens with the name cell.');
    $this->assertSame(static::SITE_NAME, $this->fold($first->item(0)->textContent));

    // Nothing of the card comes before that cell.
    $before = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " card ")]//td[contains(concat(" ", normalize-space(@class), " "), " site-name ")]/preceding::td[ancestor::div[contains(concat(" ", normalize-space(@class), " "), " card ")]]');
    $this->assertSame(0, $before->length, 'The community name is the first thing in the card.');
  }

}
