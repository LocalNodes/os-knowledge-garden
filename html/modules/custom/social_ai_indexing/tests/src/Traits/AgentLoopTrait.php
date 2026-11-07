<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Traits;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_assistant_api\Data\UserMessage;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\SessionManagerInterface;
use Drupal\social_ai_indexing\Citation\CitationInvariants;
use Drupal\social_ai_indexing_test\EventSubscriber\ScriptedLlmSubscriber;

/**
 * Runs the Group assistant through the real agent loop (Phase 22, 22-16).
 *
 * The path is the one DeepChat takes: AiAssistantApiRunner::process() ->
 * ai_assistant_api.agent_runner (our CitationGuardAgentRunner) -> the
 * group_assistant agent's loop -> PermissionAwareRagTool -> the citation
 * rewrite at AgentFinishedExecutionEvent. Only the model is a double: the
 * scripted LLM answers each chat request at PreGenerateResponseEvent, and
 * the echoai provider behind it is never called.
 *
 * The agent and assistant are loaded from their shipped YAML. Two test-only
 * changes to the assistant: llm_provider/llm_model point at echoai (the
 * shipped gemini provider isn't installed in Kernel), and every run gets a
 * fresh thread key so no case replays another's history (P-NON and P-FORGE
 * are one account).
 *
 * Use with GroupAccessKernelTestBase, LeakFixtureTrait, IndexFixtureTrait,
 * StubSearchTrait and RagToolTrait. The test class must list
 * social_ai_indexing_test, social_email_broadcast (ai's action deriver needs
 * it, see RagToolTrait), ai_test, ai_agents and modeler_api in $modules.
 */
trait AgentLoopTrait {

  /**
   * The case ids the leak matrix runs (AI-SPEC §5 RQ-07 to RQ-12).
   */
  protected static array $leakCaseIds = ['RQ-07', 'RQ-08', 'RQ-09', 'RQ-10', 'RQ-11', 'RQ-12'];

  /**
   * Builds the stub search stack, the §5 fixtures and the group assistant.
   */
  protected function setUpAgentLoop(): void {
    $server = $this->createStubSearchServer();
    $posts = $this->createShippedIndex('social_posts', $server);
    $comments = $this->createShippedIndex('social_comments', $server);
    $this->setUpLeakFixtures();
    $this->assertSame(6, $this->indexAllItems($posts), 'Every Topic fixture is indexed.');
    $this->assertSame(2, $this->indexAllItems($comments), 'Every comment fixture is indexed.');
    $this->mirrorCommentPayloads();
    $this->resetSourceRegistry();

    $this->installConfig(['ai_agents']);
    $this->installShippedConfigFrom('social_ai_indexing', 'config/optional', ['ai_agents.ai_agent.group_assistant']);
    $this->installShippedConfigFrom('localnodes_platform', 'config/install', ['ai_assistant_api.ai_assistant.group_assistant'], static function (array $data): array {
      // Test-only: the shipped provider (gemini) isn't installed in Kernel.
      // The scripted LLM answers before echoai is ever called.
      $data['llm_provider'] = 'echoai';
      $data['llm_model'] = 'gpt-test';
      return $data;
    });
  }

  /**
   * Imports config objects from a module folder, optionally transformed.
   *
   * @param string $module
   *   Module machine name.
   * @param string $folder
   *   The folder: 'config/install' or 'config/optional'.
   * @param string[] $names
   *   Config object names, without .yml.
   * @param callable|null $alter
   *   Optional function(array $data): array applied before saving.
   */
  protected function installShippedConfigFrom(string $module, string $folder, array $names, ?callable $alter = NULL): void {
    $path = $this->container->get('extension.list.module')->getPath($module) . '/' . $folder;
    $source = new FileStorage($path);
    $config_manager = $this->container->get('config.manager');
    foreach ($names as $name) {
      $data = $source->read($name);
      if ($data === FALSE) {
        throw new \RuntimeException("Shipped config $name not found in $path");
      }
      if ($alter) {
        $data = $alter($data);
      }
      $entity_type_id = $config_manager->getEntityTypeIdByName($name);
      $storage = $this->container->get('entity_type.manager')->getStorage($entity_type_id);
      // installConfig() also installs every module's config/optional whose
      // dependencies are met, so the object may already be there (from the
      // same file). Replace it so the shipped data, altered, is what runs.
      $existing = $storage->load($data[$storage->getEntityType()->getKey('id')]);
      if ($existing !== NULL) {
        $existing->delete();
      }
      $storage->createFromStorageRecord($data)->save();
    }
  }

  /**
   * The scripted LLM double.
   */
  protected function scriptedLlm(): ScriptedLlmSubscriber {
    return $this->container->get('social_ai_indexing_test.scripted_llm');
  }

  /**
   * Asks the group assistant one question as one persona, end to end.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The asker; becomes the current user.
   * @param mixed $scope
   *   The DeepChat contexts.group_id value; NULL for none.
   * @param string $question
   *   The question, asked verbatim.
   * @param array $script
   *   The model turns (ScriptedLlmSubscriber::script()).
   * @param bool $verbose
   *   Run the assistant in verbose mode.
   * @param string|null $thread
   *   The thread key; NULL for a fresh one (no history replay).
   *
   * @return array{answer: string, role: string, inputs: list<string>, sources: list<\Drupal\social_ai_indexing\Citation\RetrievedSource>, violations: list<string>, rewritten: bool, unused_turns: int}
   *   The reply text and role, every LLM input snapshot, the registry as it
   *   stood when the reply was returned, and CitationInvariants violations.
   */
  protected function askAssistant(AccountInterface $account, mixed $scope, string $question, array $script, bool $verbose = FALSE, ?string $thread = NULL): array {
    $this->setCurrentUser($account);
    // drupal/ai 1.4 gives every assistant run a progress thread, so
    // ai_agents' AgentStatusSubscriber writes the run's progress to private
    // tempstore, which refuses to work without a started session. A real
    // DeepChat request has one (the session cookie, or DeepChatApi's
    // setSession); a test process doesn't. Report it as started, as
    // ai_agents' own AiAgentStatusPollerServiceTest does. This must happen
    // before the first agent event builds the status storage service.
    if (!$this->container->get('session_manager')->isStarted()) {
      $session = $this->createMock(SessionManagerInterface::class);
      $session->method('isStarted')->willReturn(TRUE);
      $this->container->set('session_manager', $session);
    }
    $llm = $this->scriptedLlm();
    $llm->reset();
    $llm->script($script);

    /** @var \Drupal\ai_assistant_api\AiAssistantApiRunner $runner */
    $runner = $this->container->get('ai_assistant_api.runner');
    $assistant = $this->container->get('entity_type.manager')->getStorage('ai_assistant')->load('group_assistant');
    $runner->setAssistant($assistant);
    // A fresh thread per run unless given: no history replay between cases.
    $runner->setThreadsKey($thread ?? 'phase22_' . bin2hex(random_bytes(6)));
    $runner->setContext($scope === NULL ? [] : ['group_id' => $scope]);
    $runner->setVerboseMode($verbose);
    $runner->setUserMessage(new UserMessage($question));
    $output = $runner->process();

    $message = $output->getNormalized();
    $answer = $message instanceof ChatMessage ? $message->getText() : '';
    $registry = $this->container->get('social_ai_indexing.source_registry');
    return [
      'answer' => $answer,
      'role' => $message instanceof ChatMessage ? $message->getRole() : '',
      'inputs' => array_column($llm->inputs(), 'text'),
      'sources' => $registry->all(),
      'violations' => CitationInvariants::check($answer, $registry),
      'rewritten' => $registry->wasRewritten(),
      'unused_turns' => $llm->remaining(),
    ];
  }

  /**
   * A model turn that searches one index for the question.
   */
  protected static function searchTurn(string $index, string $question): \Closure {
    return static fn (ChatInput $input) => ScriptedLlmSubscriber::toolCall(
      'ai_search_rag_search',
      ['index' => $index, 'search_string' => $question],
      $input,
    );
  }

  /**
   * The scripted model for doors 1-3: search both indexes, cite everything.
   *
   * One tool call on social_posts, one on social_comments, then an answer
   * citing every label it was given ([R1]…[Rk]), or NO_RELEVANT_CONTENT when
   * it was given none. Citing everything is the worst case: any hidden
   * source in the registry would reach the Sources list.
   */
  protected function citeEverythingScript(string $question): array {
    return [
      self::searchTurn('social_posts', $question),
      self::searchTurn('social_comments', $question),
      static function (ChatInput $input) {
        $labels = ScriptedLlmSubscriber::toolLabels($input);
        if ($labels === []) {
          return ScriptedLlmSubscriber::text('NO_RELEVANT_CONTENT');
        }
        $sentences = array_map(static fn (string $label, int $i): string => 'Point ' . ($i + 1) . " from the group's pages [$label].", $labels, array_keys($labels));
        return ScriptedLlmSubscriber::text(implode(' ', $sentences));
      },
    ];
  }

  /**
   * The scripted model for door 4: cite only what mentions the topic.
   *
   * Searches both indexes, then cites the labels whose block (title and
   * fenced excerpt) contains $term, case-insensitively, or replies
   * NO_RELEVANT_CONTENT. If a hidden page reached the model, it would be
   * cited and the reply would differ from the nonsense question's.
   */
  protected function citeRelevantScript(string $question, string $term): array {
    return [
      self::searchTurn('social_posts', $question),
      self::searchTurn('social_comments', $question),
      static function (ChatInput $input) use ($term) {
        $labels = [];
        foreach ($input->getMessages() as $message) {
          if (!$message instanceof ChatMessage || $message->getRole() !== 'tool') {
            continue;
          }
          $blocks = preg_split('/^(?=\[R\d+\] )/m', $message->getText());
          foreach ($blocks as $block) {
            if (preg_match('/^\[(R\d+)\] /', $block, $m) === 1 && stripos($block, $term) !== FALSE) {
              $labels[$m[1]] = "[{$m[1]}]";
            }
          }
        }
        return ScriptedLlmSubscriber::text($labels ? 'The pages answer this ' . implode('', $labels) . '.' : 'NO_RELEVANT_CONTENT');
      },
    ];
  }

  /**
   * The leak cases from cases.yml, one row per (case, scope value).
   *
   * @return list<array{id: string, persona: string, scope: string, question: string, expect: string, forbidden: list<string>}>
   *   Cases in file order; RQ-10's scope list becomes three rows.
   */
  protected function leakCases(): array {
    $path = $this->container->get('extension.list.module')->getPath('social_ai_indexing') . '/tests/fixtures/eval/cases.yml';
    $rows = [];
    foreach (Yaml::decode((string) file_get_contents($path)) as $case) {
      if (!in_array($case['id'], static::$leakCaseIds, TRUE)) {
        continue;
      }
      foreach ((array) $case['scope'] as $scope) {
        $rows[] = [
          'id' => $case['id'],
          'persona' => $case['persona'],
          'scope' => (string) $scope,
          'question' => $case['question'],
          'expect' => $case['expect'],
          'forbidden' => array_map('strval', $case['forbidden'] ?? []),
        ];
      }
    }
    return $rows;
  }

  /**
   * The account behind a persona id (P-FORGE is P-NON sending forged hints).
   */
  protected function personaAccount(string $persona): AccountInterface {
    return $this->personas[$persona === 'P-FORGE' ? 'P-NON' : $persona];
  }

  /**
   * The value DeepChat would send for a case's scope.
   *
   * Fixture group keys become the group id as a string (JSON from the
   * browser); forged values are sent as given.
   */
  protected function scopeValue(string $scope): string {
    return isset($this->groups[$scope]) ? (string) $this->groups[$scope]->id() : $scope;
  }

  /**
   * Distinctive strings per fixture (all fictional).
   *
   * @return array<string, list<string>>
   *   Fixture key => strings only that fixture carries.
   */
  protected function fixtureCanaries(): array {
    return [
      'G-PUB' => ['Fictional G public notice', 'public notice for group G'],
      'G-COM' => ['Fictional G community notice', 'community notice for group G'],
      'G-MO' => [self::ZQ7_TITLE, self::ZQ7_BODY, self::ZQ7_FACT, 'old mill'],
      'G-MO-COMMENT' => [self::ZQ7_COMMENT],
      'G-PUB-COMMENT' => ['fictional public reply'],
      'H-MO' => [self::HK3_BODY, 'Fictional H members notes'],
      'H-PUB' => ['Fictional H public notice', 'public notice for group H'],
      'S-MO' => [self::SX9_BODY, 'Fictional S members notes'],
    ];
  }

}
