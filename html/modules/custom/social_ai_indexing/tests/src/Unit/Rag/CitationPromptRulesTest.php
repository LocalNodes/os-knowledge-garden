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
   * Each prompt holds each rule.
   */
  public function testEveryPromptHoldsEveryRule(): void {
    $module = dirname(__DIR__, 4);
    $prompts = [
      'group_assistant instructions' => Yaml::parseFile($module . '/../localnodes_platform/config/install/ai_assistant_api.ai_assistant.group_assistant.yml')['instructions'],
      'group_assistant agent system_prompt' => Yaml::parseFile($module . '/config/optional/ai_agents.ai_agent.group_assistant.yml')['system_prompt'],
      'overview SYSTEM_PROMPT' => AiOverviewService::SYSTEM_PROMPT,
    ];
    foreach ($prompts as $name => $prompt) {
      foreach (self::RULES as $rule) {
        $this->assertStringContainsString($rule, $prompt, "$name holds: $rule");
      }
    }
  }

}
