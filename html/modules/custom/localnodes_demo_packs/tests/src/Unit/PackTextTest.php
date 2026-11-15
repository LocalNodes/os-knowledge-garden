<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Unit;

use Drupal\localnodes_demo_packs\PackText;
use Drupal\Tests\UnitTestCase;

/**
 * The word-for-word helpers fold markup and typography, never words.
 *
 * @coversDefaultClass \Drupal\localnodes_demo_packs\PackText
 * @group localnodes_demo_packs
 */
final class PackTextTest extends UnitTestCase {

  /**
   * Markdown markers go; the words and a username's underscore stay.
   *
   * @dataProvider markdownProvider
   */
  public function testNormalizeMarkdown(string $markdown, string $expected): void {
    $this->assertSame($expected, PackText::normalizeMarkdown($markdown));
  }

  /**
   * Cases for testNormalizeMarkdown.
   */
  public static function markdownProvider(): array {
    return [
      'bold and italic' => ["Budget is **fixed** and *firm*.", 'Budget is fixed and firm.'],
      'underscore emphasis' => ['An _important_ date', 'An important date'],
      'username underscore stays' => ['- smoke_one (Smoke One): hello', 'smoke_one (Smoke One): hello'],
      'heading' => ["## People\n\nSam", 'People Sam'],
      'bullets and numbers' => ["- one\n* two\n1. three\n2) four", 'one two three four'],
      'blockquote' => ['> Quiet hours start at 10 pm.', 'Quiet hours start at 10 pm.'],
      'table' => ["| Name | Cost |\n|---|---|\n| Fan | \$64 |", 'Name Cost Fan $64'],
      'backticks' => ['Press `Save` now', 'Press Save now'],
      'link' => ['See [the budget](https://example.com/b) page', 'See the budget page'],
      'wrapped lines' => ["one line\nnext line\n\n\nnew paragraph", 'one line next line new paragraph'],
      'curly quotes fold' => ["Don\u{2019}t move Pete\u{2019}s shelf", "Don't move Pete's shelf"],
    ];
  }

  /**
   * Each block element of an HTML value comes out as one normalized string.
   */
  public function testBlocks(): void {
    $html = "<h2>Quiet  hours</h2>\n<p>Start at <strong>10&nbsp;pm</strong>.</p>"
      . '<ul><li>First</li><li>Second<br>line</li></ul>'
      . '<table><tr><th>Item</th><td>Fan &amp; light</td></tr></table>'
      . '<blockquote>Be kind</blockquote>';
    $blocks = PackText::blocks($html);
    foreach (['Quiet hours', 'Start at 10 pm.', 'First', 'Second line', 'Item', 'Fan & light', 'Be kind'] as $expected) {
      $this->assertContains($expected, $blocks);
    }
  }

  /**
   * A plain string is its own single block; empty input has none.
   */
  public function testBlocksOfPlainText(): void {
    $this->assertSame(['Smoke page'], PackText::blocks("Smoke   page"));
    $this->assertSame([], PackText::blocks('   '));
  }

  /**
   * Text outside any block element is still checked.
   */
  public function testTextOutsideBlocksIsNotLost(): void {
    $blocks = PackText::blocks('Loose words<p>Then a paragraph</p>');
    $this->assertContains('Loose words', $blocks);
    $this->assertContains('Then a paragraph', $blocks);
  }

  /**
   * Every text node comes out once, whatever element holds it.
   *
   * A division beside a paragraph is not skipped, and text inside a division
   * inside a list is not lost.
   */
  public function testBlocksCollectEveryTextNodeOnce(): void {
    $this->assertSame(['ok', 'changed'], PackText::blocks('<p>ok</p><div>changed</div>'));
    $this->assertSame(['ok', 'unapproved text'], PackText::blocks('<p>ok</p><div><span>unapproved</span> text</div>'));
    $this->assertSame(['x'], PackText::blocks('<div><p>x</p></div>'), 'A nested block is read once.');
    $this->assertSame(['x', 'y'], PackText::blocks('<blockquote><p>x</p></blockquote><section>y</section>'));
    $this->assertSame(['Title', 'body'], PackText::blocks('<h1>Title</h1><p>body</p>'));
    $this->assertSame(['one', 'two', 'tail'], PackText::blocks('<ul><li>one</li><li><div>two</div></li></ul>tail'));
    $this->assertSame(['Second line'], PackText::blocks('<p>Second<br>line</p>'));
  }

  /**
   * Text after a stray closing tag is still read.
   *
   * A closing div ends the wrapper the reader puts around a value, so what
   * follows would sit beside it. Every text node is read, wherever the
   * parser puts it.
   */
  public function testTextAfterAStrayClosingTagIsKept(): void {
    foreach ([
      '<p>ok</p></div><p>added words</p>',
      '<p>ok</p></div>added words',
      '<p>ok</p></div></body></html><p>added words</p>',
      '<p>ok</p></body>added words',
      '<p>ok</p></div><div>added words</div>',
    ] as $html) {
      $this->assertContains('ok', PackText::blocks($html), $html);
      $this->assertContains('added words', PackText::blocks($html), "Text after the stray tag is read: $html");
    }
  }

  /**
   * A markdown source is read as blocks: a line with a marker is its own block.
   */
  public function testMarkdownBlocks(): void {
    $markdown = "## People\n\n- smoke_one (Smoke One): First\n  wrapped on.\n- smoke_two (Smoke Two): Second\n\n"
      . "A paragraph that\nwraps over two lines.\n\n> Quiet\n> hours\n\n| Name | Cost |\n|---|---|\n| Fan | \$64 |\n";
    $this->assertSame([
      'People',
      'smoke_one (Smoke One): First wrapped on.',
      'smoke_two (Smoke Two): Second',
      'A paragraph that wraps over two lines.',
      'Quiet hours',
      'Name Cost',
      'Fan $64',
    ], PackText::markdownBlocks($markdown));
  }

  /**
   * Copy matches inside one source block, on whole words.
   */
  public function testInBlocksMatchesWholeWordsInOneBlock(): void {
    $source = PackText::markdownBlocks("## People\n\n- smoke_one (Smoke One): A test account.\n\n### Smoke page\n\nWe meet again on day +7.\n");
    $this->assertTrue(PackText::inBlocks('Smoke page', $source));
    $this->assertTrue(PackText::inBlocks('We meet again on day +7.', $source));
    $this->assertTrue(PackText::inBlocks('smoke_one (Smoke One)', $source));
    $this->assertFalse(PackText::inBlocks('Smoke pag', $source), 'Half a word is not a match.');
    $this->assertFalse(PackText::inBlocks('moke page', $source), 'Half a word is not a match.');
    $this->assertFalse(PackText::inBlocks('People smoke_one', $source), 'Text from two blocks is not one match.');
    $this->assertFalse(PackText::inBlocks('account. Smoke page', $source), 'Text from two blocks is not one match.');
    $this->assertFalse(PackText::inBlocks('we meet again', $source), 'Case counts unless asked to ignore it.');
    $this->assertTrue(PackText::inBlocks('we meet again', $source, TRUE));
    $this->assertFalse(PackText::inBlocks('', $source));
  }

  /**
   * A day token reads as "day +N" again, as the source file says it.
   */
  public function testUntoken(): void {
    $this->assertSame('on day +7 at', PackText::untoken('on {{day:+7}} at'));
    $this->assertSame('day -14 and day +1', PackText::untoken('{{day:-14}} and {{day:+1}}'));
  }

  /**
   * Typography folds, words do not.
   */
  public function testNormalize(): void {
    $this->assertSame("It's a \"fine\" day", PackText::normalize("It\u{2019}s\u{00A0}a \u{201C}fine\u{201D}   day "));
    $this->assertNotSame(PackText::normalize('Fixed'), PackText::normalize('fixed'), 'Case is kept.');
  }

}
