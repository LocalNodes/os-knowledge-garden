<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Unit\Rag;

use Drupal\social_ai_indexing\Service\AiOverviewService;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The citation rules are the same in all three prompts (22-27).
 *
 * The group assistant's instructions, its agent's system prompt and the
 * overview's system prompt each carry the support rules. The rerun's
 * consensus labels (2026-11-07) found a dropped hedge, an added list item,
 * a completed cut-off name and two passages joined into one claim; one
 * rule names each. A prompt edited in one place only would let them back.
 *
 * 22.2-15 adds two rules. PLAIN_LANGUAGE (D-27) asks all three prompts for
 * everyday words inside the support rules: a plainer sentence still says
 * only what its passage says. ANSWER_AFTER_SEARCH (D-24) is for the two
 * agent prompts only (the overview calls no tool): gemini-3.8-flash
 * answered RQ-01 with its own "Called ai_search_rag_search {...}" lines.
 * SEARCH_WITH_QUESTION_WORDS (D-24, orchestrator ruling 2026-11-07, option
 * C) is for the two agent prompts too: the model searched the bare keyword
 * "bioregionalism", which ranks the page's answering chunk 7th, past the
 * excerpt; the question's own words rank it 2nd.
 *
 * @group phase22
 * @group phase22_unit
 * @group social_ai_indexing
 */
final class CitationPromptRulesTest extends UnitTestCase {

  /**
   * Phrases every citing prompt must hold.
   */
  private const RULES = [
    "Each sentence states only what the passage it cites says.",
    "Keep the passage's qualifiers (for example 'mostly', 'often', 'some'): never make a hedged statement absolute.",
    "Don't add examples or list items the passage doesn't give.",
    "If a passage is cut off mid-word or mid-name, don't complete it.",
    "Don't join separate passages into a claim neither makes.",
    "When asked how things relate, say what each passage says side by side, and state a link only if a passage states it (otherwise say the pages don't spell out the link).",
  ];

  /**
   * Plain words, still tied to the passage (D-27): all three prompts.
   */
  private const PLAIN_LANGUAGE = "Write in plain, everyday words for someone new to the topic: put a specialist term in simpler words, using only what the passage says, and keep the term itself (in quotes) only where the passage gives no plainer wording.";

  /**
   * Answer once the results are in, never with the search list (D-24).
   *
   * The two agent prompts only: the overview calls no tool.
   */
  private const ANSWER_AFTER_SEARCH = "When the search results arrive, answer from them in sentences with their [R#] labels, or reply exactly NO_RELEVANT_CONTENT. Your reply is never a list of searches.";

  /**
   * Search with the question's words, not a keyword (D-24, option C).
   *
   * The two agent prompts only: the overview calls no tool.
   */
  private const SEARCH_WITH_QUESTION_WORDS = "Search with the question's own words: use the whole current question as search_string (with names written out in place of 'it' or 'they'), never a shortened keyword.";

  /**
   * Each prompt holds each rule.
   */
  public function testEveryPromptHoldsEveryRule(): void {
    foreach ($this->prompts() as $name => $prompt) {
      foreach (self::RULES as $rule) {
        $this->assertStringContainsString($rule, $prompt, "$name holds: $rule");
      }
    }
  }

  /**
   * Each prompt holds the plain-language rule (D-27).
   */
  public function testEveryPromptHoldsThePlainLanguageRule(): void {
    foreach ($this->prompts() as $name => $prompt) {
      $this->assertStringContainsString(self::PLAIN_LANGUAGE, $prompt, "$name holds the plain-language rule");
    }
  }

  /**
   * Both agent prompts hold the answer-after-search rule (D-24).
   */
  public function testAgentPromptsHoldTheAnswerAfterSearchRule(): void {
    foreach ($this->prompts() as $name => $prompt) {
      if ($name === 'overview SYSTEM_PROMPT') {
        continue;
      }
      $this->assertStringContainsString(self::ANSWER_AFTER_SEARCH, $prompt, "$name holds the answer-after-search rule");
    }
  }

  /**
   * Both agent prompts hold the search-with-the-question's-words rule.
   */
  public function testAgentPromptsHoldTheSearchWithQuestionWordsRule(): void {
    foreach ($this->prompts() as $name => $prompt) {
      if ($name === 'overview SYSTEM_PROMPT') {
        continue;
      }
      $this->assertStringContainsString(self::SEARCH_WITH_QUESTION_WORDS, $prompt, "$name holds the search-with-the-question's-words rule");
    }
  }

  /**
   * The three citing prompts, by name.
   *
   * @return array<string, string>
   *   Prompt text keyed by where it lives.
   */
  private function prompts(): array {
    $module = dirname(__DIR__, 4);
    return [
      'group_assistant instructions' => Yaml::parseFile($module . '/../localnodes_platform/config/install/ai_assistant_api.ai_assistant.group_assistant.yml')['instructions'],
      'group_assistant agent system_prompt' => Yaml::parseFile($module . '/config/optional/ai_agents.ai_agent.group_assistant.yml')['system_prompt'],
      'overview SYSTEM_PROMPT' => AiOverviewService::SYSTEM_PROMPT,
    ];
  }

}
