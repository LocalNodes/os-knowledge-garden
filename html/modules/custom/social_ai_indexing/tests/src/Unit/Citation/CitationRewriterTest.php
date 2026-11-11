<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Unit\Citation;

use Drupal\social_ai_indexing\Citation\CitationInvariants;
use Drupal\social_ai_indexing\Citation\CitationRewriter;
use Drupal\social_ai_indexing\Citation\CitedAnswer;
use Drupal\social_ai_indexing\Citation\SourceRegistry;
use Drupal\social_ai_indexing\Citation\UncitableAnswerException;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the LLM-text-to-cited-answer rewriter (AI-SPEC §5 rewriter cases).
 *
 * One data-provider row per case. Every cited result is also run through
 * CitationInvariants::check(), the same check the live run uses (E3).
 *
 * @coversDefaultClass \Drupal\social_ai_indexing\Citation\CitationRewriter
 * @group phase22
 * @group phase22_unit
 */
final class CitationRewriterTest extends UnitTestCase {

  use CitationTestEntities;

  /**
   * Three distinct nodes, labelled [R1] [R2] [R3].
   */
  private const THREE_NODES = [
    ['node', 1, 'Watershed boundaries'],
    ['node', 2, 'Commons governance'],
    ['node', 3, 'Peer production'],
  ];

  /**
   * Rewrites each case and checks body, citations and invariants.
   *
   * @param string $llm_text
   *   The model's final text.
   * @param list<array> $entities
   *   Entities registered in order: ['node', id, title] or
   *   ['comment', id, parent_id, parent_title].
   * @param array $expected
   *   One of: ['cited', body, list of entity keys in display order],
   *   ['throws', reason] or ['no_answer', copy].
   * @param bool $in_group
   *   Whether the question was asked in a group.
   *
   * @dataProvider rewriterCases
   */
  public function testRewrite(string $llm_text, array $entities, array $expected, bool $in_group = TRUE): void {
    $registry = $this->buildRegistry($entities);
    $rewriter = new CitationRewriter();

    if ($expected[0] === 'throws') {
      try {
        $rewriter->rewrite($llm_text, $registry, $in_group);
        $this->fail('Expected UncitableAnswerException ' . $expected[1]);
      }
      catch (UncitableAnswerException $e) {
        $this->assertSame($expected[1], $e->getMessage());
      }
      return;
    }

    $answer = $rewriter->rewrite($llm_text, $registry, $in_group);
    $markdown = $answer->toMarkdown();

    if ($expected[0] === 'no_answer') {
      $this->assertTrue($answer->noAnswer);
      $this->assertSame($expected[1], $markdown);
      $this->assertStringNotContainsString('Sources', $markdown);
      $this->assertSame([], CitationInvariants::check($markdown, $registry));
      return;
    }

    $this->assertFalse($answer->noAnswer);
    $this->assertSame($expected[1], $answer->body);
    $this->assertSame($expected[2], array_map(static fn ($s): string => $s->key(), $answer->citations));
    $this->assertSame([], CitationInvariants::check($markdown, $registry), 'Rewritten answer breaks a citation invariant.');
    // Nothing the model wrote about sources survives (D-19).
    $this->assertStringNotContainsString('evil.example', $markdown);
    $this->assertDoesNotMatchRegularExpression('/\[R\d+\]/i', $markdown);
  }

  /**
   * AI-SPEC §5 rewriter cases plus the planner's comment/parent decision.
   */
  public static function rewriterCases(): array {
    $group = "I couldn't find anything about that in this group's documents. Try asking with different words, or open the group's documents yourself.";
    $site = "I couldn't find anything about that on this site. Try asking with different words, or use search.";

    return [
      'first-use order: [R3] then [R1] becomes [1] then [2]' => [
        'Rivers set the border [R3]. The commons is local [R1].',
        self::THREE_NODES,
        ['cited', 'Rivers set the border [1]. The commons is local [2].', ['node:3', 'node:1']],
      ],
      'grouped [R1, R3] becomes [1][2]' => [
        'Both pages agree [R1, R3].',
        self::THREE_NODES,
        ['cited', 'Both pages agree [1][2].', ['node:1', 'node:3']],
      ],
      'repeated [R1][R1] becomes [1]' => [
        'One claim [R1][R1].',
        self::THREE_NODES,
        ['cited', 'One claim [1].', ['node:1']],
      ],
      'unknown [R9] is dropped' => [
        'Real claim [R2]. Invented claim [R9].',
        self::THREE_NODES,
        ['cited', 'Real claim [1].', ['node:2']],
      ],
      'plain [2] from stale history is dropped' => [
        'Earlier answer [2] and now [R1].',
        self::THREE_NODES,
        ['cited', 'Earlier answer and now [1].', ['node:1']],
      ],
      'model-written Sources list is removed' => [
        "Peer production scales [R3].\n\n**Sources:**\n1. [Fake page](https://evil.example/fake)\n2. Peer production",
        self::THREE_NODES,
        ['cited', 'Peer production scales [1].', ['node:3']],
      ],
      'model-written ## Sources heading is removed' => [
        "Commons need rules [R2].\n\n## Sources\n- [R2] Commons governance",
        self::THREE_NODES,
        ['cited', 'Commons need rules [1].', ['node:2']],
      ],
      '[text](https://evil.example) becomes text' => [
        'See [the join page](https://evil.example/join) for rules [R1].',
        self::THREE_NODES,
        ['cited', 'See the join page for rules [1].', ['node:1']],
      ],
      'bare URL is removed' => [
        'Visit https://evil.example/join today [R1].',
        self::THREE_NODES,
        ['cited', 'Visit today [1].', ['node:1']],
      ],
      'HTML anchor becomes its text' => [
        'Read <a href="https://evil.example">this page</a> first [R2].',
        self::THREE_NODES,
        ['cited', 'Read this page first [1].', ['node:2']],
      ],
      'lowercase [r2] is still a label' => [
        'A fact [r2].',
        self::THREE_NODES,
        ['cited', 'A fact [1].', ['node:2']],
      ],
      'label wrapped in a link keeps its citation' => [
        'A fact [R3](https://evil.example/r3).',
        self::THREE_NODES,
        ['cited', 'A fact [1].', ['node:3']],
      ],
      'same node from both indexes gets one number' => [
        'First [R1]. Second [R2]. First again [R1].',
        [['node', 10, 'Bioregion'], ['node', 10, 'Bioregion'], ['node', 11, 'Watershed']],
        ['cited', 'First [1]. Second [2]. First again [1].', ['node:10', 'node:11']],
      ],
      'comment and its parent page are two entries' => [
        'The page says so [R1]. A reply adds detail [R2].',
        [['node', 5, 'Mapping'], ['comment', 7, 5, 'Mapping']],
        ['cited', 'The page says so [1]. A reply adds detail [2].', ['node:5', 'comment:7']],
      ],
      'every label unknown throws no_valid_citations' => [
        'Claim [R7] and claim [R8].',
        self::THREE_NODES,
        ['throws', 'no_valid_citations'],
      ],
      'no labels at all throws no_valid_citations' => [
        'A confident answer with no citations.',
        self::THREE_NODES,
        ['throws', 'no_valid_citations'],
      ],
      'empty output throws empty_output' => [
        '',
        self::THREE_NODES,
        ['throws', 'empty_output'],
      ],
      'whitespace-only output throws empty_output' => [
        " \n\t ",
        self::THREE_NODES,
        ['throws', 'empty_output'],
      ],
      'only a Sources list throws empty_body' => [
        "**Sources**\n- [1] [One](/node/1)",
        self::THREE_NODES,
        ['throws', 'empty_body'],
      ],
      'an uncited sentence after a cited one is removed' => [
        'Supported fact [R1]. Fabricated fact.',
        self::THREE_NODES,
        ['cited', 'Supported fact [1].', ['node:1']],
      ],
      'an uncited sentence in a list item is removed, the cited item stays' => [
        "- Cited point [R2].\n- An uncited claim about something.",
        self::THREE_NODES,
        ['cited', '- Cited point [1].', ['node:2']],
      ],
      'allowlist: a list intro ending in a colon stays' => [
        "Here is what the group's pages say:\n- A point [R1].",
        self::THREE_NODES,
        ['cited', "Here is what the group's pages say:\n- A point [1].", ['node:1']],
      ],
      'allowlist: saying the pages do not spell out a link stays' => [
        "The first page covers one thing [R1]. The second covers another [R2]. The pages don't spell out the link between them.",
        self::THREE_NODES,
        ['cited', "The first page covers one thing [1]. The second covers another [2]. The pages don't spell out the link between them.", ['node:1', 'node:2']],
      ],
      'a heading that is not a fixed intro is removed (merge review R2)' => [
        "## Seed saving\nThe group saves seeds [R1].",
        self::THREE_NODES,
        ['cited', "The group saves seeds [1].", ['node:1']],
      ],
      'allowlist: a fixed heading stays' => [
        "## Key points\nThe group saves seeds [R1].",
        self::THREE_NODES,
        ['cited', "## Key points\nThe group saves seeds [1].", ['node:1']],
      ],
      'allowlist: a fixed colon intro stays' => [
        "In short:\n- The group saves seeds [R1].",
        self::THREE_NODES,
        ['cited', "In short:\n- The group saves seeds [1].", ['node:1']],
      ],
      'Codex R3: a cited heading keeps its citation; numbering starts at [1], no orphan' => [
        "## Supported heading [R1].\nAnother supported fact [R2].",
        self::THREE_NODES,
        ['cited', "## Supported heading [1].\nAnother supported fact [2].", ['node:1', 'node:2']],
      ],
      'Codex R3: a removed uncited sentence takes its label number with it' => [
        "An uncited claim. Another supported fact [R2]. A third [R3].",
        self::THREE_NODES,
        ['cited', "Another supported fact [1]. A third [2].", ['node:2', 'node:3']],
      ],
      'Codex R3: a heading whose only label is invented is removed, and numbering starts at [1]' => [
        "## The group was founded on the moon [R9]\nA real point [R2].",
        self::THREE_NODES,
        ['cited', "A real point [1].", ['node:2']],
      ],
      'Codex R4: a cited heading loses its uncited sentence' => [
        "## Fact [R1]. The group was founded on the moon in 1850.\nA real point [R2].",
        self::THREE_NODES,
        ['cited', "## Fact [1].\nA real point [2].", ['node:1', 'node:2']],
      ],
      'Codex R4: an uncited first sentence in a heading goes, the cited one stays' => [
        "## The group was founded on the moon in 1850. Fact [R1].",
        self::THREE_NODES,
        ['cited', "## Fact [1].", ['node:1']],
      ],
      'Codex R2: an uncited claim as a heading is removed' => [
        "Supported fact [R1].\n## The group was founded on the moon in 1850",
        self::THREE_NODES,
        ['cited', 'Supported fact [1].', ['node:1']],
      ],
      'Codex R2: an uncited claim ending in a colon is removed' => [
        "Supported fact [R1].\nThe group was founded on the moon in 1850:",
        self::THREE_NODES,
        ['cited', 'Supported fact [1].', ['node:1']],
      ],
      'a long colon intro is removed even if it reads like one' => [
        "Here is what the group's many pages say about it:\n- A point [R1].",
        self::THREE_NODES,
        ['cited', '- A point [1].', ['node:1']],
      ],
      'an absence statement that adds a claim is not boilerplate' => [
        "A point [R1]. The pages don't mention it, but the founder lived in Paris.",
        self::THREE_NODES,
        ['cited', 'A point [1].', ['node:1']],
      ],
      'absence statement carrying a claim is removed (orchestrator example)' => [
        "The meeting place is the round room [R1]. The pages don't say the meeting moved to Friday.",
        self::THREE_NODES,
        ['cited', 'The meeting place is the round room [1].', ['node:1']],
      ],
      'absence statement with a number is removed' => [
        "A point [R1]. The sources do not mention what happened in 1850.",
        self::THREE_NODES,
        ['cited', 'A point [1].', ['node:1']],
      ],
      'absence statement with an unknown opening is removed' => [
        "A point [R1]. Nobody knows what the link between them is.",
        self::THREE_NODES,
        ['cited', 'A point [1].', ['node:1']],
      ],
      'absence statement as an indirect question stays (rerun 5 RQ-18 shape)' => [
        "A point [R1]. The provided texts do not explicitly state what bioregionalism organizes life around.",
        self::THREE_NODES,
        ['cited', "A point [1]. The provided texts do not explicitly state what bioregionalism organizes life around.", ['node:1']],
      ],
      'every sentence uncited after dropping invented labels throws no_valid_citations' => [
        'A claim [R9]. Another claim.',
        self::THREE_NODES,
        ['throws', 'no_valid_citations'],
      ],
      'no sources: a reply in the model\'s own words is the no-answer copy' => [
        "I couldn't find anything about that in this group's pages.",
        [],
        ['no_answer', $group],
      ],
      'no sources: invented labels are the no-answer copy too' => [
        'A claim [R1].',
        [],
        ['no_answer', $group],
      ],
      'NO_RELEVANT_CONTENT in a group gives the group copy' => [
        'NO_RELEVANT_CONTENT',
        self::THREE_NODES,
        ['no_answer', $group],
      ],
      'NO_RELEVANT_CONTENT outside a group gives the site copy' => [
        'NO_RELEVANT_CONTENT',
        [],
        ['no_answer', $site],
        FALSE,
      ],
    ];
  }

  /**
   * A comment and its parent show different link texts in Sources.
   */
  public function testCommentAndParentHaveDifferentLinkTexts(): void {
    $registry = $this->buildRegistry([['node', 5, 'Mapping'], ['comment', 7, 5, 'Mapping']]);
    $markdown = (new CitationRewriter())->rewrite('Page [R1]. Reply [R2].', $registry)->toMarkdown();

    $this->assertStringContainsString("\n\n**Sources**\n", $markdown);
    $this->assertStringContainsString('- [1] [Mapping](/node/5)', $markdown);
    $this->assertStringContainsString('- [2] [Comment on Mapping](/node/5#comment-7)', $markdown);
  }

  /**
   * The rewriter leaves the rewritten flag to its caller (22-16 subscriber).
   */
  public function testRewriteDoesNotMarkRegistry(): void {
    $registry = $this->buildRegistry(self::THREE_NODES);
    (new CitationRewriter())->rewrite('A fact [R1].', $registry);
    $this->assertFalse($registry->wasRewritten());
    $this->assertInstanceOf(CitedAnswer::class, (new CitationRewriter())->rewrite('A fact [R1].', $registry));
  }

  /**
   * Builds a registry from entity specs.
   */
  private function buildRegistry(array $entities): SourceRegistry {
    $registry = new SourceRegistry();
    foreach ($entities as $spec) {
      if ($spec[0] === 'comment') {
        $registry->register($this->mockComment($spec[1], $this->mockNode($spec[2], $spec[3])), 'comment');
      }
      else {
        $registry->register($this->mockNode($spec[1], $spec[2]), 'Topic');
      }
    }
    return $registry;
  }

}
