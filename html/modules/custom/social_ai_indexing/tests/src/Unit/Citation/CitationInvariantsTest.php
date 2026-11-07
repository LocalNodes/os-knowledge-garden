<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Unit\Citation;

use Drupal\social_ai_indexing\Citation\CitationInvariants;
use Drupal\social_ai_indexing\Citation\CitedAnswer;
use Drupal\social_ai_indexing\Citation\SourceRegistry;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the shared citation invariant check (AI-SPEC §5, E3).
 *
 * @coversDefaultClass \Drupal\social_ai_indexing\Citation\CitationInvariants
 * @group phase22
 * @group phase22_unit
 */
final class CitationInvariantsTest extends UnitTestCase {

  use CitationTestEntities;

  /**
   * Builds a registry holding /node/1 "One", /node/2 "Two", /node/3 "Three".
   */
  private function registry(): SourceRegistry {
    $registry = new SourceRegistry();
    $registry->register($this->mockNode(1, 'One'), 'Topic');
    $registry->register($this->mockNode(2, 'Two'), 'Topic');
    $registry->register($this->mockNode(3, 'Three'), 'Topic');
    return $registry;
  }

  /**
   * A well-formed answer and the fixed copies have no violations.
   */
  public function testValidMarkdownPasses(): void {
    $registry = $this->registry();
    $valid = "A [1]. B [2]. A again [1].\n\n**Sources**\n- [1] [Three](/node/3)\n- [2] [One](/node/1)";

    $this->assertSame([], CitationInvariants::check($valid, $registry));
    $this->assertSame([], CitationInvariants::check(CitedAnswer::fallbackMarkdown(), $registry));
    $this->assertSame([], CitationInvariants::check(CitedAnswer::noRelevantContent(TRUE)->toMarkdown(), $registry));
  }

  /**
   * Each broken answer reports a violation with the expected code.
   *
   * @dataProvider brokenAnswers
   */
  public function testReportsViolation(string $markdown, string $code): void {
    $violations = CitationInvariants::check($markdown, $this->registry());

    $this->assertNotSame([], $violations, 'Expected a violation for ' . $code);
    $codes = array_map(static fn (string $v): string => explode(':', $v, 2)[0], $violations);
    $this->assertContains($code, $codes, implode("\n", $violations));
  }

  /**
   * Broken answers: markdown, expected violation code.
   */
  public static function brokenAnswers(): array {
    $sources = "\n\n**Sources**\n";
    return [
      'gap [1], [3]' => [
        "A [1]. B [3]." . $sources . "- [1] [One](/node/1)\n- [2] [Two](/node/2)\n- [3] [Three](/node/3)",
        'numbering',
      ],
      'first uses not rising' => [
        "B [2]. A [1]." . $sources . "- [1] [One](/node/1)\n- [2] [Two](/node/2)",
        'numbering',
      ],
      'dangling number' => [
        "A [1]. B [2]." . $sources . "- [1] [One](/node/1)",
        'dangling',
      ],
      'orphan entry' => [
        "A [1]." . $sources . "- [1] [One](/node/1)\n- [2] [Two](/node/2)",
        'orphan',
      ],
      'duplicate link text' => [
        "A [1]. B [2]." . $sources . "- [1] [Same](/node/1)\n- [2] [Same](/node/2)",
        'duplicate_link_text',
      ],
      'leftover [R2]' => [
        "A [1]. B [R2]." . $sources . "- [1] [One](/node/1)",
        'leftover_label',
      ],
      'https:// in the body' => [
        "A [1], see https://evil.example." . $sources . "- [1] [One](/node/1)",
        'absolute_url_in_body',
      ],
      '//host link in Sources' => [
        "A [1]." . $sources . "- [1] [One](//evil.example/node/1)",
        'url_not_root_relative',
      ],
      'entry not in the registry' => [
        "A [1]." . $sources . "- [1] [Ninety-nine](/node/99)",
        'not_in_registry',
      ],
      'one entity under two numbers' => [
        "A [1]. B [2]." . $sources . "- [1] [One](/node/1)\n- [2] [One again](/node/1)",
        'duplicate_entity',
      ],
      'malformed Sources line' => [
        "A [1]." . $sources . "1. One — /node/1",
        'malformed_sources_line',
      ],
      'link left in the body' => [
        "A [1], see [here](/node/2)." . $sources . "- [1] [One](/node/1)",
        'link_in_body',
      ],
    ];
  }

}
