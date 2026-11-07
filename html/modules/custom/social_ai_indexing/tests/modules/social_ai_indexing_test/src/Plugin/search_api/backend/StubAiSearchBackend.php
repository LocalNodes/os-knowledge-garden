<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing_test\Plugin\search_api\backend;

use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\search_api\Attribute\SearchApiBackend;
use Drupal\search_api\Backend\BackendPluginBase;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Query\ConditionGroupInterface;
use Drupal\search_api\Query\ConditionInterface;
use Drupal\search_api\Query\QueryInterface;

/**
 * Stub AI search backend for leak and fail-closed tests (Phase 22, D-18).
 *
 * Stands in for drupal/ai's SearchApiAiSearchBackend + QdrantProvider so
 * Kernel tests need no Qdrant, Solr or Gemini (AI-SPEC §5 Eval Tooling).
 *
 * Mode comes from state key 'social_ai_indexing_test.backend_mode':
 * - 'honour' (default): applies the query's conditions the way
 *   QdrantProvider::prepareFilters() translates them;
 * - 'ignore': returns every stored item whatever the conditions say, like
 *   prepareFilters() returning [] on an exception (AI-SPEC pitfall 1);
 * - 'throw': throws \RuntimeException from search().
 *
 * Mirrored from QdrantProvider (ai_vdb_provider_qdrant, read 2026-11-04):
 * - convertConditionToQdrant(): a condition on a field the index lacks
 *   (getField() NULL) that is not one of AI_SEARCH_NATIVE_FIELDS is dropped
 *   silently (only a messenger warning there). This is the F-2 mechanism.
 * - createQdrantCondition(): '=' is match value, 'IN' is match any,
 *   '<>' / '!=' / 'NOT IN' are negative conditions.
 * - Multi-value payloads match when any stored value matches.
 * - mapConjunctionToQdrant(): AND -> must (+ must_not for negatives),
 *   OR -> should; an empty nested group is dropped; an empty top level
 *   means no filter at all, so every item matches.
 *
 * Deliberate difference: range operators (<, >, BETWEEN ...) are dropped
 * here, while Qdrant translates them. Dropping is the permissive direction,
 * so a leak test can only get stricter by moving to real Qdrant. No leak
 * test uses a range operator.
 *
 * search() does no entity access check (the real backend's
 * checkEntityAccess() is safeguard 2); this backend tests safeguard 1 alone.
 *
 * Indexed items live in key_value collection
 * 'social_ai_indexing_test.stub_items'. The last condition group that
 * reached search() is stored, in array form, in state key
 * 'social_ai_indexing_test.last_conditions', and the last query's
 * search_api_bypass_access option and processing level in
 * 'social_ai_indexing_test.last_query' (22-12, AI-SPEC pitfall 4).
 *
 * Ranking (22-12): results come back by score, highest first. Every item
 * scores 1.0 unless state key 'social_ai_indexing_test.scores' maps its item
 * id to another score; ties keep storage order. The query's 'limit' option,
 * when set, caps the results, as the real backend does.
 */
#[SearchApiBackend(
  id: 'social_ai_indexing_test_stub',
  label: new TranslatableMarkup('Stub AI search backend (tests)'),
  description: new TranslatableMarkup('Test-only backend with honour, ignore and throw modes.'),
)]
class StubAiSearchBackend extends BackendPluginBase {

  /**
   * State key holding the backend mode.
   */
  public const MODE_KEY = 'social_ai_indexing_test.backend_mode';

  /**
   * State key holding the last condition group, in array form.
   */
  public const LAST_CONDITIONS_KEY = 'social_ai_indexing_test.last_conditions';

  /**
   * State key holding the last query's access option and processing level.
   */
  public const LAST_QUERY_KEY = 'social_ai_indexing_test.last_query';

  /**
   * State key mapping item ids to scores (default 1.0).
   */
  public const SCORES_KEY = 'social_ai_indexing_test.scores';

  /**
   * Key-value collection holding the indexed items.
   */
  public const COLLECTION = 'social_ai_indexing_test.stub_items';

  /**
   * Fields QdrantProvider filters on without an index field.
   *
   * @see \Drupal\ai_vdb_provider_qdrant\Plugin\VdbProvider\QdrantProvider::AI_SEARCH_NATIVE_FIELDS
   */
  protected const NATIVE_FIELDS = [
    'drupal_entity_id',
    'drupal_long_id',
    'content',
    'vector',
    'server_id',
    'index_id',
  ];

  /**
   * {@inheritdoc}
   */
  public function supportsDataType($type) {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function indexItems(IndexInterface $index, array $items) {
    $store = $this->store();
    $indexed = [];
    foreach ($items as $item_id => $item) {
      $fields = [];
      $content = NULL;
      foreach ($item->getFields() as $field_id => $field) {
        $values = array_map([$this, 'scalar'], $field->getValues());
        $fields[$field_id] = $values;
        if ($content === NULL && $field_id === 'rendered_item') {
          $content = implode(' ', $values);
        }
      }
      if ($content === NULL) {
        foreach ($item->getFields() as $field_id => $field) {
          if ($field->getType() === 'text') {
            $content = implode(' ', $fields[$field_id]);
            break;
          }
        }
      }
      $store->set($this->key($index, $item_id), [
        'index_id' => $index->id(),
        'item_id' => $item_id,
        'fields' => $fields,
        'extra' => [
          'drupal_entity_id' => $item_id,
          'content' => (string) $content,
          'citation_type' => $fields['citation_type'][0] ?? NULL,
        ],
      ]);
      $indexed[] = $item_id;
    }
    return $indexed;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItems(IndexInterface $index, array $item_ids) {
    $keys = [];
    foreach ($item_ids as $item_id) {
      $keys[] = $this->key($index, $item_id);
    }
    $this->store()->deleteMultiple($keys);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAllIndexItems(IndexInterface $index, $datasource_id = NULL) {
    $prefix = $index->id() . '|';
    $keys = [];
    foreach ($this->store()->getAll() as $key => $record) {
      if (!str_starts_with($key, $prefix)) {
        continue;
      }
      if ($datasource_id !== NULL && !str_starts_with($record['item_id'], $datasource_id . '/')) {
        continue;
      }
      $keys[] = $key;
    }
    $this->store()->deleteMultiple($keys);
  }

  /**
   * {@inheritdoc}
   */
  public function search(QueryInterface $query) {
    $index = $query->getIndex();
    $condition_group = $query->getConditionGroup();
    \Drupal::state()->set(self::LAST_CONDITIONS_KEY, self::conditionGroupToArray($condition_group));
    \Drupal::state()->set(self::LAST_QUERY_KEY, [
      'search_api_bypass_access' => $query->getOption('search_api_bypass_access'),
      'processing_level' => $query->getProcessingLevel(),
      'limit' => $query->getOption('limit'),
    ]);

    $mode = \Drupal::state()->get(self::MODE_KEY, 'honour');
    if ($mode === 'throw') {
      throw new \RuntimeException('Stub AI search backend: throw mode.');
    }

    $results = $query->getResults();
    $prefix = $index->id() . '|';
    $scores = \Drupal::state()->get(self::SCORES_KEY, []);
    $matched = [];
    foreach ($this->store()->getAll() as $key => $record) {
      if (!str_starts_with($key, $prefix)) {
        continue;
      }
      if ($mode !== 'ignore' && !$this->matchesGroup($index, $condition_group, $record['fields'])) {
        continue;
      }
      $record['score'] = (float) ($scores[$record['item_id']] ?? 1.0);
      $matched[] = $record;
    }
    // Highest score first; usort is stable, so ties keep storage order.
    usort($matched, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
    $limit = $query->getOption('limit');
    if (is_numeric($limit) && (int) $limit > 0) {
      $matched = array_slice($matched, 0, (int) $limit);
    }
    foreach ($matched as $record) {
      $result_item = $this->getFieldsHelper()->createItem($index, $record['item_id']);
      $result_item->setScore($record['score']);
      foreach ($record['extra'] as $extra_key => $extra_value) {
        $result_item->setExtraData($extra_key, $extra_value);
      }
      $results->addResultItem($result_item);
    }
    $results->setResultCount(count($results->getResultItems()));
  }

  /**
   * Converts a condition group to a plain array (for state and assertions).
   *
   * @return array
   *   ['conjunction' => 'AND'|'OR', 'tags' => string[], 'conditions' =>
   *   list of ['field', 'value', 'operator'] or nested groups].
   */
  public static function conditionGroupToArray(ConditionGroupInterface $group): array {
    $conditions = [];
    foreach ($group->getConditions() as $condition) {
      if ($condition instanceof ConditionGroupInterface) {
        $conditions[] = self::conditionGroupToArray($condition);
        continue;
      }
      $conditions[] = [
        'field' => $condition->getField(),
        'value' => $condition->getValue(),
        'operator' => $condition->getOperator(),
      ];
    }
    return [
      'conjunction' => strtoupper($group->getConjunction()),
      'tags' => array_values($group->getTags()),
      'conditions' => $conditions,
    ];
  }

  /**
   * Evaluates a condition group like QdrantProvider's translation.
   *
   * @return bool
   *   TRUE when the item passes the group.
   */
  protected function matchesGroup(IndexInterface $index, ConditionGroupInterface $group, array $fields): bool {
    $positive = [];
    $negative = [];
    foreach ($group->getConditions() as $condition) {
      if ($condition instanceof ConditionGroupInterface) {
        // An empty nested filter is dropped (convertConditionGroupToQdrant).
        if (!$this->groupHasEffect($index, $condition)) {
          continue;
        }
        $positive[] = $this->matchesGroup($index, $condition, $fields);
        continue;
      }
      $evaluated = $this->evaluateCondition($index, $condition, $fields);
      if ($evaluated === NULL) {
        // Dropped, as Qdrant drops it.
        continue;
      }
      [$is_negative, $matches] = $evaluated;
      if ($is_negative) {
        $negative[] = $matches;
      }
      else {
        $positive[] = $matches;
      }
    }

    if (!$positive && !$negative) {
      return TRUE;
    }

    if (strtoupper($group->getConjunction()) === 'OR') {
      // should: at least one positive. Negatives become must_not over all
      // of them together (mapConjunctionToQdrant), so the item is excluded
      // only when every negative's inner condition matches.
      $should = !$positive || in_array(TRUE, $positive, TRUE);
      $must_not = $negative && !in_array(FALSE, $negative, TRUE);
      return $should && !$must_not;
    }

    // Qdrant 'must' plus 'must_not'.
    return !in_array(FALSE, $positive, TRUE) && !in_array(TRUE, $negative, TRUE);
  }

  /**
   * Whether a nested group survives translation (has a non-dropped part).
   */
  protected function groupHasEffect(IndexInterface $index, ConditionGroupInterface $group): bool {
    foreach ($group->getConditions() as $condition) {
      if ($condition instanceof ConditionGroupInterface) {
        if ($this->groupHasEffect($index, $condition)) {
          return TRUE;
        }
        continue;
      }
      if ($this->isTranslatable($index, $condition)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether QdrantProvider would translate (not drop) this condition.
   */
  protected function isTranslatable(IndexInterface $index, ConditionInterface $condition): bool {
    $field = $condition->getField();
    if (!$index->getField($field) instanceof FieldInterface && !in_array($field, self::NATIVE_FIELDS, TRUE)) {
      return FALSE;
    }
    return in_array($condition->getOperator(), ['=', 'IN', '<>', '!=', 'NOT IN'], TRUE);
  }

  /**
   * Evaluates one condition.
   *
   * @return array|null
   *   NULL when the condition is dropped, else [is_negative, inner_matches]
   *   where inner_matches is the match result of the positive form.
   */
  protected function evaluateCondition(IndexInterface $index, ConditionInterface $condition, array $fields): ?array {
    if (!$this->isTranslatable($index, $condition)) {
      return NULL;
    }
    $field = $condition->getField();
    $operator = $condition->getOperator();
    $value = $condition->getValue();
    $stored = array_map('strval', $fields[$field] ?? []);
    $wanted = array_map([$this, 'scalar'], is_array($value) ? $value : [$value]);
    $wanted = array_map('strval', $wanted);
    $any = (bool) array_intersect($stored, $wanted);

    return match ($operator) {
      '=', 'IN' => [FALSE, $any],
      '<>', '!=', 'NOT IN' => [TRUE, $any],
    };
  }

  /**
   * Reduces a field value to a scalar.
   */
  protected function scalar(mixed $value): mixed {
    if (is_object($value)) {
      return (string) $value;
    }
    if (is_bool($value)) {
      return $value ? '1' : '0';
    }
    return $value;
  }

  /**
   * The key for an item in the store.
   */
  protected function key(IndexInterface $index, string $item_id): string {
    return $index->id() . '|' . $item_id;
  }

  /**
   * The item store.
   */
  protected function store(): KeyValueStoreInterface {
    return \Drupal::keyValue(self::COLLECTION);
  }

}
