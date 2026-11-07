<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Traits;

use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Runs the assistant's RAG tool the way the agent does (Phase 22, 22-12).
 *
 * Use with GroupAccessKernelTestBase and StubSearchTrait. The test class
 * must list 'social_ai_indexing_test' and 'social_email_broadcast' in
 * $modules: the function-call plugin manager's action deriver instantiates
 * every action plugin, and social_group's SocialSendEmail action needs the
 * social_email_broadcast service.
 */
trait RagToolTrait {

  /**
   * Builds ai_search:rag_search through the plugin manager and runs it.
   *
   * @param string $index_id
   *   The index context value.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The asker; becomes the current user.
   * @param mixed $scope
   *   The runner context group_id (DeepChat contexts.group_id); NULL for no
   *   hint.
   * @param int $amount
   *   The amount context value.
   * @param float $min_score
   *   The min_score context value.
   */
  protected function runRagTool(string $index_id, AccountInterface $account, mixed $scope, int $amount = 10, float $min_score = 0.0): ExecutableFunctionCallInterface {
    $this->setCurrentUser($account);
    $this->container->get('ai_assistant_api.runner')->setContext($scope === NULL ? [] : ['group_id' => $scope]);
    /** @var \Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface $tool */
    $tool = $this->container->get('plugin.manager.ai.function_calls')->createInstance('ai_search:rag_search');
    $tool->setContextValue('index', $index_id);
    $tool->setContextValue('search_string', 'fictional');
    $tool->setContextValue('amount', $amount);
    $tool->setContextValue('min_score', $min_score);
    $tool->execute();
    return $tool;
  }

  /**
   * Empties the request-wide source registry (a new agent run).
   */
  protected function resetSourceRegistry(): void {
    $this->container->get('social_ai_indexing.source_registry')->reset();
  }

  /**
   * The label lines of a tool output, in output order.
   *
   * @return array<int, string>
   *   Label number => title, e.g. [1 => 'Fictional G public notice'].
   */
  protected static function labelLines(string $output): array {
    preg_match_all('/^\[R(\d+)\] (.*)$/m', $output, $matches, PREG_SET_ORDER);
    $labels = [];
    foreach ($matches as $match) {
      $labels[(int) $match[1]] = $match[2];
    }
    return $labels;
  }

  /**
   * The fenced excerpts of a tool output, in output order.
   *
   * @return string[]
   *   The text between each tool-written fence pair.
   */
  protected static function fencedExcerpts(string $output): array {
    preg_match_all("/^\\[R\\d+\\] [^\\n]*\\n<<<SOURCE_TEXT\\n(.*?)\\nSOURCE_TEXT>>>$/ms", $output, $matches);
    return $matches[1];
  }

  /**
   * Gives comment records the content the real AI backend would store.
   *
   * The stub stores rendered_item as content. A group comment renders empty
   * (rendered_item renders as anonymous, who can't see group comments), but
   * ai_search.index.social_comments embeds subject + rendered_item as main
   * content and parent_post_title + parent_post_summary as contextual
   * content. Measured in Kernel: subject and rendered_item are empty for a
   * group comment, so the payload is the parent's title (for G-MO-COMMENT
   * that is ZQ7-TITLE). Without this the comment door would be vacuous.
   */
  protected function mirrorCommentPayloads(): void {
    $store = $this->container->get('keyvalue')->get('social_ai_indexing_test.stub_items');
    foreach ($store->getAll() as $key => $record) {
      if ($record['index_id'] !== 'social_comments') {
        continue;
      }
      $parts = [];
      foreach (['subject', 'rendered_item', 'parent_post_title', 'parent_post_summary'] as $field) {
        foreach ($record['fields'][$field] ?? [] as $value) {
          $parts[] = trim(strip_tags((string) $value));
        }
      }
      $record['extra']['content'] = implode("\n", array_filter($parts));
      $store->set($key, $record);
    }
  }

}
