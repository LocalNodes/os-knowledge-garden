<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Traits;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\IndexInterface;
use Drupal\social_ai_indexing\Controller\AiOverviewController;
use Drupal\social_ai_indexing_test\EventSubscriber\ScriptedLlmSubscriber;
use Symfony\Component\HttpFoundation\Request;

/**
 * The search page's AI overview over both retrieval legs (Phase 22, 22-22).
 *
 * The overview runs HybridSearchService: a vector leg on social_posts (here
 * the stub AI search backend, which ignores keys and honours conditions the
 * way QdrantProvider does) and a keyword leg on Open Social's social_content
 * index (here search_api_db with the shipped processors). Only the model is a
 * double: the scripted LLM answers at PreGenerateResponseEvent, and the
 * echoai provider behind it is never called.
 *
 * Use with GroupAccessKernelTestBase, LeakFixtureTrait, IndexFixtureTrait and
 * StubSearchTrait. The test class must list social_ai_indexing_test and
 * ai_test in $modules.
 */
trait OverviewTrait {

  /**
   * A term only the keyword leg matches: G-MO's title canary.
   *
   * The vector leg never returns G-MO (no site-wide query gets 'group'
   * visibility there, 22-09), so G-MO can only reach an overview through
   * the keyword leg.
   */
  protected const KEYWORD_ONLY_TERM = 'ZQ7-TITLE';

  /**
   * Builds the stub vector index, the keyword index and the §5 fixtures.
   */
  protected function setUpOverviewSearch(): void {
    $stub = $this->createStubSearchServer();
    $posts = $this->createShippedIndex('social_posts', $stub);
    $content = $this->createKeywordIndex($this->createTestSearchServer());
    $this->setUpLeakFixtures();
    $this->assertSame(6, $this->indexAllItems($posts), 'Every Topic fixture is in the vector index.');
    $this->assertSame(6, $this->indexAllItems($content), 'Every Topic fixture is in the keyword index.');
    $this->setStubMode('honour');
    // The overview uses the site's default chat provider. Test-only: echoai
    // (the scripted LLM answers before it is ever called).
    $this->config('ai.settings')
      ->set('default_providers.chat', ['provider_id' => 'echoai', 'model_id' => 'gpt-test'])
      ->save();
  }

  /**
   * Saves Open Social's shipped social_content index on a test server.
   *
   * Changes from the shipped file, all test-only:
   * - the two event date fields go (social_event isn't enabled);
   * - social_search's tagging processor goes (social_search needs Solr);
   * - field_content_visibility and groups are added, the fields Open
   *   Social's locked social_node and social_group processors add to the
   *   real index on save. 'groups' is filled from the GroupMetadata
   *   processor here, because the computed node 'groups' field comes from a
   *   module the Kernel base doesn't enable;
   * - type is a string, as social_node's processor declares it, so the
   *   hybrid's type condition can run on search_api_db.
   */
  protected function createKeywordIndex(string $server_id): IndexInterface {
    $path = $this->container->get('extension.list.module')->getPath('social_search')
      . '/config/install/search_api.index.social_content.yml';
    $values = Yaml::decode((string) file_get_contents(DRUPAL_ROOT . '/' . $path));
    unset(
      $values['dependencies'],
      $values['field_settings']['field_event_date'],
      $values['field_settings']['field_event_date_end'],
      $values['processor_settings']['social_search_tagging_query'],
    );
    $values['server'] = $server_id;
    $values['field_settings']['type']['type'] = 'string';
    $values['field_settings']['field_content_visibility'] = [
      'label' => 'Content visibility',
      'datasource_id' => 'entity:node',
      'property_path' => 'field_content_visibility',
      'type' => 'string',
    ];
    $values['field_settings']['groups'] = [
      'label' => 'Groups',
      'property_path' => 'group_id',
      'type' => 'integer',
    ];
    $values['processor_settings']['group_metadata'] = [];
    foreach (['tokenizer', 'ignorecase', 'transliteration'] as $processor) {
      $fields = $values['processor_settings'][$processor]['fields'] ?? [];
      $values['processor_settings'][$processor]['fields'] = array_values(array_diff($fields, ['type']));
    }
    $values['options']['index_directly'] = FALSE;
    Index::create($values)->save();
    return Index::load('social_content');
  }

  /**
   * The overview controller, built from the container.
   */
  protected function overviewController(): AiOverviewController {
    return AiOverviewController::create($this->container);
  }

  /**
   * Requests the overview as one persona, through the controller.
   *
   * @return array
   *   The decoded JSON response.
   */
  protected function askOverview(AccountInterface $account, string $query): array {
    $this->setCurrentUser($account);
    $this->resetAccessCaches();
    $response = $this->overviewController()->overview(Request::create('/api/ai/overview', 'GET', ['q' => $query]));
    $this->assertSame(200, $response->getStatusCode());
    return (array) Json::decode((string) $response->getContent());
  }

  /**
   * Clears per-request access and membership caches between personas.
   */
  protected function resetAccessCaches(): void {
    $entity_type_manager = $this->container->get('entity_type.manager');
    foreach (['node', 'comment', 'group'] as $entity_type_id) {
      $entity_type_manager->getAccessControlHandler($entity_type_id)->resetCache();
      $entity_type_manager->getStorage($entity_type_id)->resetCache();
    }
    $entity_type_manager->getStorage('group_content')->resetCache();
    drupal_static_reset();
    // In-memory caches a web request starts without: static entity and
    // permission caches, group's membership memory cache and the like. The
    // persistent bins (and cache.default) are left alone.
    foreach ($this->container->getServiceIds() as $id) {
      if ($id === 'entity.memory_cache' || preg_match('/^cache\.(static|[a-z0-9_]*memory[a-z0-9_]*)$/', $id) === 1) {
        $service = $this->container->get($id);
        if ($service instanceof CacheBackendInterface) {
          $service->deleteAll();
        }
      }
    }
  }

  /**
   * The scripted LLM double.
   */
  protected function scriptedLlm(): ScriptedLlmSubscriber {
    return $this->container->get('social_ai_indexing_test.scripted_llm');
  }

  /**
   * Chat requests the overview made (tagged social_ai_overview).
   *
   * @return list<array>
   *   ScriptedLlmSubscriber records.
   */
  protected function overviewCalls(): array {
    return array_values(array_filter(
      $this->scriptedLlm()->inputs(),
      static fn (array $record): bool => in_array('social_ai_overview', $record['tags'], TRUE),
    ));
  }

  /**
   * Every chat request recorded, tagged or not.
   */
  protected function allCalls(): array {
    return $this->scriptedLlm()->inputs();
  }

  /**
   * The [R#] labels given to the model in a request, in first-seen order.
   *
   * @return list<string>
   *   Labels such as 'R1'.
   */
  protected static function contextLabels(ChatInput $input): array {
    $labels = [];
    foreach (self::contextTexts($input) as $text) {
      preg_match_all('/^\[(R\d+)\] /m', $text, $matches);
      foreach ($matches[1] as $label) {
        $labels[$label] = $label;
      }
    }
    return array_values($labels);
  }

  /**
   * The texts a request gives the model its sources in.
   *
   * Since 22.2-09 (D-12) the overview's sources are in the system prompt and
   * its user turn is only the query; before, they were in the user turn. Both
   * places are read, so a test doesn't depend on the layout.
   *
   * @return list<string>
   *   The system prompt, then every non-assistant message's text.
   */
  protected static function contextTexts(ChatInput $input): array {
    $texts = [(string) $input->getSystemPrompt()];
    foreach ($input->getMessages() as $message) {
      if ($message instanceof ChatMessage && $message->getRole() !== 'assistant') {
        $texts[] = $message->getText();
      }
    }
    return $texts;
  }

  /**
   * The title line of each labelled block, keyed by label.
   *
   * @return array<string, string>
   *   'R1' => 'Fictional G public notice'.
   */
  protected static function contextTitles(ChatInput $input): array {
    $titles = [];
    foreach (self::contextTexts($input) as $text) {
      preg_match_all('/^\[(R\d+)\] (.+)$/m', $text, $matches, PREG_SET_ORDER);
      foreach ($matches as $match) {
        $titles[$match[1]] ??= trim($match[2]);
      }
    }
    return $titles;
  }

  /**
   * A model turn citing every label it was given, or NO_RELEVANT_CONTENT.
   */
  protected static function citeAllTurn(): \Closure {
    return static function (ChatInput $input) {
      $labels = self::contextLabels($input);
      if ($labels === []) {
        return ScriptedLlmSubscriber::text('NO_RELEVANT_CONTENT');
      }
      $sentences = array_map(static fn (string $label, int $i): string => 'Fictional point ' . ($i + 1) . " [$label].", $labels, array_keys($labels));
      return ScriptedLlmSubscriber::text(implode(' ', $sentences));
    };
  }

  /**
   * Arms the scripted LLM with many cite-everything turns.
   */
  protected function armCiteAll(int $turns = 20): void {
    $this->scriptedLlm()->reset();
    $this->scriptedLlm()->script(array_fill(0, $turns, self::citeAllTurn()));
  }

}
