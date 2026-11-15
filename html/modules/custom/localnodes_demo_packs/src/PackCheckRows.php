<?php

declare(strict_types=1);

namespace Drupal\localnodes_demo_packs;

/**
 * The row rules of the tier 2 script, apart from the site it runs on.
 *
 * scripts/check_demo_pack.php asks the assistant and the search as pack
 * members on a running site. What it decides without the site lives here, so
 * a Unit test can prove it:
 * - which user a row runs as, read from the row's `as` key and never
 *   defaulted;
 * - which group a row asks in: its uuid, or none (a site-wide question) when
 *   the key is empty or absent, never a group picked by default;
 * - what a result prints: the row id and the reason codes, never the
 *   assistant's answer, which can quote a page the member must not see;
 * - how a search row is judged: the member's hits, read in full, must not
 *   carry the hidden title, and a control member who may see the page must
 *   find it, so a search that finds nothing for anyone cannot pass;
 * - how a search_found row is judged: the member's hits, read in full, must
 *   carry the title;
 * - how an indexed row is judged: each index it names must track the page,
 *   have indexed it (no item left waiting) and hold it in its own backend.
 *
 * No Drupal calls.
 */
final class PackCheckRows {

  /**
   * Hits asked for in one search page.
   */
  public const PAGE_SIZE = 50;

  /**
   * Most hits read for one query; a longer result set fails the row.
   */
  public const HIT_CAP = 5000;

  /**
   * The user a row runs as: "anonymous" or a pack user's uuid.
   *
   * @return string|null
   *   NULL when the row names none. There is no default, because a row that
   *   runs as the wrong person passes for the wrong reason.
   */
  public static function persona(array $row): ?string {
    $persona = $row['as'] ?? NULL;
    return is_string($persona) && trim($persona) !== '' ? trim($persona) : NULL;
  }

  /**
   * The reason a row prints when its member or its group is not on the site.
   */
  public const MISSING = 'account or group missing';

  /**
   * The reason a row prints when its `group` value is not a uuid string.
   */
  public const BAD_GROUP = 'group is not a uuid string';

  /**
   * What is wrong with a row's `group` value.
   *
   * @return string[]
   *   Empty when the key is missing, NULL or an empty string (a site-wide row)
   *   or holds a non-blank string; otherwise one fixed reason. A list, a
   *   number, a boolean or a blank string is a row written wrong, and it never
   *   falls through to a site-wide question.
   */
  public static function groupProblems(array $row): array {
    $group = $row['group'] ?? NULL;
    if ($group === NULL || $group === '') {
      return [];
    }
    return is_string($group) && trim($group) !== '' ? [] : [self::BAD_GROUP];
  }

  /**
   * The group a row asks in: a uuid, or NULL for a site-wide question.
   *
   * @return string|null
   *   NULL when `group` is absent, NULL or empty. A row that names a group
   *   which is not on the site is a failure, not a site-wide row.
   *
   * @throws \InvalidArgumentException
   *   When the value is not a string; the message never repeats the value.
   */
  public static function groupUuid(array $row): ?string {
    if (self::groupProblems($row) !== []) {
      throw new \InvalidArgumentException(self::BAD_GROUP);
    }
    $group = $row['group'] ?? NULL;
    return $group === NULL || $group === '' ? NULL : trim($group);
  }

  /**
   * The line a dry run prints for a row.
   *
   * @param string $who
   *   The member's name, or "visitor".
   * @param string|null $group_label
   *   The group's label, or NULL for a site-wide question.
   */
  public static function dryLine(string $id, string $who, ?string $group_label): string {
    return "DRY $id as $who in " . ($group_label ?? 'site-wide');
  }

  /**
   * The line to print for a result: PASS or FAIL, the row id, the reasons.
   *
   * Only `pass` and `reasons` are read, so nothing else a result carries can
   * reach the output. The reasons are fixed codes with indexes and uuids (see
   * PackAnswerCheck::judge()), never phrase or page text.
   *
   * @param array{pass: bool, reasons: string[]} $result
   *   The verdict.
   */
  public static function line(string $id, array $result): string {
    if (!empty($result['pass'])) {
      return "PASS $id";
    }
    $reasons = array_map('strval', $result['reasons'] ?? []);
    return "FAIL $id: " . ($reasons === [] ? 'no reason given' : implode('; ', $reasons));
  }

  /**
   * The reason a search row prints when its hidden_titles is not a list.
   */
  public const BAD_HIDDEN = 'hidden_titles is not a list of titles';

  /**
   * The titles a search row forbids besides its own.
   *
   * @return string[]
   *   The list; empty when the row has none or the value is malformed (see
   *   searchRowProblems(), which fails a malformed one).
   */
  public static function hiddenTitles(array $row): array {
    $titles = $row['hidden_titles'] ?? [];
    return is_array($titles) && self::isTitleList($titles) ? array_values($titles) : [];
  }

  /**
   * Whether a value is a non-empty list of non-blank strings.
   */
  private static function isTitleList(array $titles): bool {
    if ($titles === [] || !array_is_list($titles)) {
      return FALSE;
    }
    foreach ($titles as $title) {
      if (!is_string($title) || trim($title) === '') {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * What a search row lacks.
   *
   * @return string[]
   *   One reason for each missing part; none when the row is complete.
   */
  public static function searchRowProblems(array $row): array {
    $problems = [];
    $persona = self::persona($row);
    if ($persona === NULL) {
      $problems[] = 'no member to search as';
    }
    foreach (['keys' => 'no query', 'title' => 'no title'] as $key => $problem) {
      if (!is_string($row[$key] ?? NULL) || trim($row[$key]) === '') {
        $problems[] = $problem;
      }
    }
    $control = $row['control_as'] ?? NULL;
    if (!is_string($control) || trim($control) === '') {
      $problems[] = 'no control member';
    }
    elseif ($persona !== NULL && trim($control) === $persona) {
      $problems[] = 'the control member is the one searching';
    }
    if (array_key_exists('hidden_titles', $row) && (!is_array($row['hidden_titles']) || !self::isTitleList($row['hidden_titles']))) {
      $problems[] = self::BAD_HIDDEN;
    }
    return $problems;
  }

  /**
   * Reads every hit of a query, one page at a time.
   *
   * @param callable $page
   *   Takes an offset and a size; returns that page's hits.
   * @param int $size
   *   Hits asked for per page.
   * @param int $cap
   *   Most hits to read.
   *
   * @return array{hits: array, complete: bool}
   *   The hits and whether the last page was short, so none were left unread.
   */
  public static function allHits(callable $page, int $size = self::PAGE_SIZE, int $cap = self::HIT_CAP): array {
    $hits = [];
    $offset = 0;
    while ($offset < $cap) {
      $items = array_values($page($offset, $size));
      $hits = array_merge($hits, $items);
      if (count($items) < $size) {
        return ['hits' => $hits, 'complete' => TRUE];
      }
      $offset += $size;
    }
    return ['hits' => $hits, 'complete' => FALSE];
  }

  /**
   * Judges a search row from the two members' hits.
   *
   * @param string $title
   *   The hidden page's title.
   * @param array{hits: array, complete: bool} $member
   *   What allHits read for the member who must not see the page.
   * @param array{hits: array, complete: bool} $control
   *   What allHits read for the member who may.
   * @param string[] $hidden
   *   More titles the member must not find, such as every page of a set when
   *   the row searches for one of them.
   *
   * @return array{pass: bool, reasons: string[]}
   *   The verdict. The reasons are fixed; no title is printed, and a hidden
   *   title is named by its position in the list.
   */
  public static function searchVerdict(string $title, array $member, array $control, array $hidden = []): array {
    $reasons = [];
    if (!$member['complete'] || !$control['complete']) {
      $reasons[] = 'the hits were not all read';
    }
    if (in_array($title, $member['hits'], TRUE)) {
      $reasons[] = 'a hit carries the hidden title';
    }
    foreach (array_values($hidden) as $index => $other) {
      if (in_array($other, $member['hits'], TRUE)) {
        $reasons[] = "hidden_titles[$index]";
      }
    }
    if (!in_array($title, $control['hits'], TRUE)) {
      $reasons[] = 'the control member does not find the title';
    }
    return ['pass' => $reasons === [], 'reasons' => $reasons];
  }

  /**
   * What an indexed row lacks: a page uuid and a list of index ids.
   *
   * @return string[]
   *   One reason for each missing part; none when the row is complete.
   */
  public static function indexedRowProblems(array $row): array {
    $problems = [];
    if (!is_string($row['uuid'] ?? NULL) || trim($row['uuid']) === '') {
      $problems[] = 'no page uuid';
    }
    $indexes = $row['indexes'] ?? NULL;
    if (!is_array($indexes) || !self::isTitleList($indexes)) {
      $problems[] = 'indexes is not a list of index ids';
    }
    return $problems;
  }

  /**
   * What a search_found row lacks: a member, a query and a title.
   *
   * @return string[]
   *   One reason for each missing part; none when the row is complete.
   */
  public static function foundRowProblems(array $row): array {
    $problems = [];
    if (self::persona($row) === NULL) {
      $problems[] = 'no member to search as';
    }
    foreach (['keys' => 'no query', 'title' => 'no title'] as $key => $problem) {
      if (!is_string($row[$key] ?? NULL) || trim($row[$key]) === '') {
        $problems[] = $problem;
      }
    }
    return $problems;
  }

  /**
   * Judges a search_found row from the member's hits.
   *
   * @param string $title
   *   The page's title.
   * @param array{hits: array, complete: bool} $member
   *   What allHits read for the member who must find the page.
   *
   * @return array{pass: bool, reasons: string[]}
   *   The verdict. The reasons are fixed; no title is printed.
   */
  public static function foundVerdict(string $title, array $member): array {
    $reasons = [];
    if (!$member['complete']) {
      $reasons[] = 'the hits were not all read';
    }
    if (!in_array($title, $member['hits'], TRUE)) {
      $reasons[] = 'the member does not find the title';
    }
    return ['pass' => $reasons === [], 'reasons' => $reasons];
  }

  /**
   * The search item id of an entity, as the indexer writes it.
   *
   * It is the id the tracker holds and the one a vector point carries in its
   * drupal_entity_id payload.
   */
  public static function itemId(string $entity_type, int|string $id, string $langcode): string {
    return "entity:$entity_type/$id:$langcode";
  }

  /**
   * How an index is asked whether it holds a page: search query or vector store.
   *
   * The index of the AI search backend keeps its items as points in a vector
   * store; every other index is asked with a search query.
   */
  public static function backendKind(string $backend_plugin_id): string {
    return $backend_plugin_id === 'search_api_ai_search' ? 'vector' : 'search';
  }

  /**
   * The vector store's filter for the points one index wrote for one item.
   *
   * @return array{must: array<int, array{key: string, match: array{value: string}}>}
   *   A filter on the payload keys the indexer writes.
   */
  public static function vectorFilter(string $item_id, string $index_id): array {
    return ['must' => [
      ['key' => 'drupal_entity_id', 'match' => ['value' => $item_id]],
      ['key' => 'index_id', 'match' => ['value' => $index_id]],
    ]];
  }

  /**
   * Judges an indexed row from the tracker and from each index's backend.
   *
   * @param array<string, int|null> $states
   *   The index id to the status of the page's item in that index (0 means
   *   indexed, 1 means waiting to be indexed), or NULL when the index holds
   *   no item for the page at all.
   * @param array<string, array{kind: string, result: string}> $proofs
   *   The index id to what its backend said when asked for the page: the kind
   *   (backendKind()) and the result: found, missing or down (the backend
   *   could not be asked).
   *
   * @return array{pass: bool, reasons: string[]}
   *   The verdict. The reasons are fixed and name only the index: "<index> not
   *   tracking" (no item in the tracker), "<index> not indexed" (an item
   *   waiting), and for the backend "<index> search backend does not return
   *   the page", "<index> vector store holds no point for the page", "<index>
   *   search backend not reachable", "<index> vector store not reachable" or
   *   "<index> backend not checked" (no usable proof). An index passes only
   *   when the tracker and the backend both say it holds the page.
   */
  public static function indexedVerdict(array $states, array $proofs): array {
    $reasons = [];
    foreach ($states as $index => $status) {
      if ($status === NULL) {
        $reasons[] = "$index not tracking";
      }
      elseif ($status !== 0) {
        $reasons[] = "$index not indexed";
      }
      $proof = $proofs[$index] ?? NULL;
      $kind = is_array($proof) ? ($proof['kind'] ?? '') : '';
      $result = is_array($proof) ? ($proof['result'] ?? '') : '';
      if (!in_array($kind, ['search', 'vector'], TRUE) || !in_array($result, ['found', 'missing', 'down'], TRUE)) {
        $reasons[] = "$index backend not checked";
        continue;
      }
      $backend = $kind === 'vector' ? 'vector store' : 'search backend';
      if ($result === 'down') {
        $reasons[] = "$index $backend not reachable";
      }
      elseif ($result === 'missing') {
        $reasons[] = $kind === 'vector' ? "$index vector store holds no point for the page" : "$index search backend does not return the page";
      }
    }
    if ($states === []) {
      $reasons[] = 'no index named';
    }
    return ['pass' => $reasons === [], 'reasons' => $reasons];
  }

}
