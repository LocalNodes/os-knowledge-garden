<?php

/**
 * @file
 * Tier 2 check of a demo pack on a running site: the assistant and search rows.
 *
 * Reads tests/fixtures/expect/<pack>/assistant.yml (and, for --search-only,
 * the search_hidden rows of expect.yml) and checks them against the site the
 * pack is loaded on. Run it only on a demo site loaded with that pack.
 *
 * - An assistant row asks the group assistant one question as one of the
 *   pack's members (inside that member's account switch, as a web request
 *   would) and judges the reply with PackAnswerCheck: the pages it must cite,
 *   the phrases it must say, the pages it must not cite, and the refusal
 *   rule for a question the library cannot answer.
 * - A search row (--search-only) is {id, as, keys, title, control_as}. It runs
 *   the site's keyword search as the member `as` (a user uuid or "anonymous")
 *   and as the control member `control_as`, who may see the page, reading
 *   every hit of each. It passes when no hit for `as` carries the title and a
 *   hit for `control_as` does, so a search that finds nothing for anyone
 *   fails instead of passing. It needs Solr and no AI key.
 * - An indexed row (--search-only) is {id, uuid, indexes}. It resolves the
 *   page by uuid and reads search_api_item for each named index: the page
 *   passes when every index tracks it and has indexed it (status 0), and
 *   each index's own backend holds it too: a search query for the page's item
 *   id on the search server, and a point filter on the vector store for the
 *   index of the AI search backend. It fails with "<index> not indexed" (an
 *   item still waiting), "<index> not tracking" (no item at all), "<index>
 *   search backend does not return the page", "<index> vector store holds no
 *   point for the page", or "... not reachable" when a backend cannot be
 *   asked. It proves the page can be found at all before the search rows ask
 *   whether the right person finds it. Nothing a backend holds is printed.
 * - A search_found row (--search-only) is {id, as, keys, title}: the
 *   member `as` runs the keyword search and must find the title. It is the
 *   search_hidden row turned round, with no control member.
 * - The indexes (as drush search-api:list shows them on a development site):
 *   the site's keyword search reads social_content
 *   (Solr; the all-content page reads social_all), and the assistant reads
 *   social_posts (the vector index on the AI Knowledge Garden server) for
 *   pages and social_comments for comments. Pages of the Topic type, which
 *   is what a guide page is, are tracked by social_all, social_content and
 *   social_posts; social_comments tracks comments only and social_groups
 *   and social_users track groups and profiles. So an indexed row for a
 *   guide page names social_content and social_posts.
 * - Every row names its member in `as`, except an indexed row. A row without
 *   one fails; it is not run as a visitor.
 *
 * The script prints one line per row: PASS, FAIL (the row id and the reason
 * codes only; an answer is never printed), SKIP or DRY, and a last line
 * "rows: <n> pass: <p> fail: <f> skip: <s>". It never prints a key, a
 * one-time link, a password or a cookie. Only a run without --dry-run and
 * without --search-only calls the model, and that needs the site's AI key.
 *
 * Usage:
 *   ddev drush php:script html/modules/custom/localnodes_demo_packs/scripts/check_demo_pack.php -- --pack=<name> [options]
 * Options:
 *   --pack=<name>           The pack to check (letters, digits, underscore).
 *   --dry-run               List the rows and exit; no model call, no search.
 *   --without=<need,need>   Skip rows whose needs hold one of these (for
 *                           example shared_pages).
 *   --search-only           Run the search_hidden, indexed and search_found
 *                           rows instead of the assistant rows.
 *   --rows=<id,id>          Only these rows.
 *   --space=20              Seconds between model-calling rows.
 *
 * Exit 0 when no row failed, 1 when a row failed, 2 on bad usage.
 */

use Drupal\ai_assistant_api\Data\UserMessage;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\localnodes_demo_packs\PackAnswerCheck;
use Drupal\localnodes_demo_packs\PackCheckRows;
use Drupal\social_ai_indexing\Service\HybridSearchService;

/**
 * Runs one pack's tier 2 rows.
 */
final class DemoPackCheck {

  /**
   * Seconds to wait before the one retry of a row that hit a quota or 503.
   */
  private const RETRY_WAIT = 60;

  /**
   * Row results by outcome.
   *
   * @var array<string, int>
   */
  private array $counts = ['pass' => 0, 'fail' => 0, 'skip' => 0];

  /**
   * How many rows were listed, run or skipped.
   */
  private int $total = 0;

  /**
   * The module's directory, relative to the site root.
   */
  private string $module;

  public function __construct(private readonly array $options) {
    $this->module = \Drupal::service('extension.list.module')->getPath('localnodes_demo_packs');
  }

  /**
   * Runs the rows; returns the exit code.
   */
  public function run(): int {
    $pack = $this->options['pack'] ?? NULL;
    if (!is_string($pack) || !preg_match('/^[a-z0-9_]+$/', $pack)) {
      print "usage: --pack=<name> (letters, digits and underscores)\n";
      return 2;
    }
    $search_only = isset($this->options['search-only']);
    $file = $search_only ? 'expect.yml' : 'assistant.yml';
    $path = DRUPAL_ROOT . '/' . $this->module . "/tests/fixtures/expect/$pack/$file";
    if (!is_file($path)) {
      print "No $file for pack $pack.\n";
      return 2;
    }
    $data = Yaml::decode((string) file_get_contents($path)) ?? [];
    $rows = [];
    if ($search_only) {
      foreach (['search_hidden' => 'hidden', 'indexed' => 'indexed', 'search_found' => 'found'] as $key => $kind) {
        foreach ($data[$key] ?? [] as $row) {
          $rows[] = is_array($row) ? $row + ['kind' => $kind] : ['id' => '?', 'kind' => $kind];
        }
      }
    }
    else {
      $rows = $data;
    }
    if (!is_array($rows)) {
      print "$file is not a list of rows.\n";
      return 2;
    }
    $only = isset($this->options['rows']) && is_string($this->options['rows']) ? explode(',', $this->options['rows']) : NULL;
    $without = isset($this->options['without']) && is_string($this->options['without']) ? explode(',', $this->options['without']) : [];
    $space = isset($this->options['space']) && is_numeric($this->options['space']) ? (int) $this->options['space'] : 20;
    $dry = isset($this->options['dry-run']);

    $stop = FALSE;
    $asked = 0;
    foreach ($rows as $row) {
      $id = (string) ($row['id'] ?? '?');
      if ($only !== NULL && !in_array($id, $only, TRUE)) {
        continue;
      }
      $this->total++;
      $blocked = array_values(array_intersect(array_map('strval', $row['needs'] ?? []), $without));
      if ($blocked) {
        $this->counts['skip']++;
        printf("SKIP %s needs %s\n", $id, implode(',', $blocked));
        continue;
      }
      $kind = $search_only ? (string) $row['kind'] : 'assistant';
      $problems = $this->rowProblems($row, $kind);
      if ($problems) {
        $this->report($id, ['pass' => FALSE, 'reasons' => $problems]);
        continue;
      }
      if ($dry && $kind === 'indexed') {
        print "DRY $id indexed in " . implode(',', $row['indexes']) . "\n";
        continue;
      }
      if ($dry) {
        $where = $this->resolve($row);
        if ($where === NULL) {
          $this->report($id, ['pass' => FALSE, 'reasons' => [PackCheckRows::MISSING]]);
          continue;
        }
        [$account, $group] = $where;
        print PackCheckRows::dryLine($id, $account->isAnonymous() ? 'visitor' : $account->getAccountName(), $group?->label()) . "\n";
        continue;
      }
      if ($stop) {
        $this->counts['skip']++;
        printf("SKIP %s the daily quota is spent\n", $id);
        continue;
      }
      if ($search_only) {
        $this->report($id, match ($kind) {
          'indexed' => $this->indexedRow($row),
          'found' => $this->foundRow($row),
          default => $this->searchRow($row),
        });
        continue;
      }
      if ($asked++ > 0 && $space > 0) {
        sleep($space);
      }
      $result = $this->assistantRow($row);
      if (($result['quota'] ?? FALSE) === TRUE) {
        $stop = TRUE;
      }
      $this->report($id, $result);
    }

    printf("rows: %d pass: %d fail: %d skip: %d\n", $this->total, $this->counts['pass'], $this->counts['fail'], $this->counts['skip']);
    return $this->counts['fail'] > 0 ? 1 : 0;
  }

  /**
   * Prints and counts a row's result.
   *
   * @param array{pass: bool, reasons: string[]} $result
   *   The verdict. Only the row id and the reasons are printed.
   */
  private function report(string $id, array $result): void {
    $this->counts[$result['pass'] ? 'pass' : 'fail']++;
    print PackCheckRows::line($id, $result) . "\n";
  }

  /**
   * What a row lacks before it can run, dry or not.
   *
   * @return string[]
   *   Reasons; none when the row can run.
   */
  private function rowProblems(array $row, string $kind): array {
    if ($kind === 'indexed') {
      return PackCheckRows::indexedRowProblems($row);
    }
    if ($kind === 'found') {
      return PackCheckRows::foundRowProblems($row);
    }
    if ($kind === 'hidden') {
      return PackCheckRows::searchRowProblems($row);
    }
    $problems = PackCheckRows::groupProblems($row);
    if (PackCheckRows::persona($row) === NULL) {
      $problems[] = 'no member to ask as';
    }
    if (!is_string($row['question'] ?? NULL) || trim($row['question']) === '') {
      $problems[] = 'no question';
    }
    return $problems;
  }

  /**
   * Asks the assistant a row's question and judges the reply.
   *
   * @return array{pass: bool, reasons: string[], quota?: bool}
   *   The verdict. It never carries the answer.
   */
  private function assistantRow(array $row): array {
    $where = $this->resolve($row);
    if ($where === NULL) {
      return ['pass' => FALSE, 'reasons' => [PackCheckRows::MISSING]];
    }
    [$account, $group] = $where;
    $last = ['pass' => FALSE, 'reasons' => ['no attempt']];
    for ($attempt = 1; $attempt <= 2; $attempt++) {
      $switcher = \Drupal::service('account_switcher');
      $switcher->switchTo($account);
      try {
        /** @var \Drupal\ai_assistant_api\AiAssistantApiRunner $runner */
        $runner = \Drupal::service('ai_assistant_api.runner');
        $assistant = \Drupal::entityTypeManager()->getStorage('ai_assistant')->load('group_assistant');
        $runner->setAssistant($assistant);
        // A fresh thread each attempt, so no history leaks between rows.
        $runner->setThreadsKey('packcheck_' . bin2hex(random_bytes(6)));
        $runner->setContext($group === NULL ? [] : ['group_id' => (string) $group->id()]);
        $runner->setVerboseMode(FALSE);
        $runner->setThrowException(TRUE);
        $runner->setUserMessage(new UserMessage((string) $row['question']));
        $message = $runner->process()->getNormalized();
        $answer = $message instanceof ChatMessage ? $message->getText() : '';
        return PackAnswerCheck::judge($answer, $this->citedSources($answer), $row);
      }
      catch (\Throwable $e) {
        $code = self::errorCode($e);
        $last = ['pass' => FALSE, 'reasons' => ["infrastructure: $code"], 'quota' => str_contains($code, 'daily_quota')];
        if ($last['quota']) {
          return $last;
        }
      }
      finally {
        $switcher->switchBack();
      }
      if ($attempt === 1) {
        printf("  %s: %s; waiting %d s, then one retry\n", $row['id'] ?? '?', $last['reasons'][0], self::RETRY_WAIT);
        sleep(self::RETRY_WAIT);
      }
    }
    return $last;
  }

  /**
   * Runs a search row.
   *
   * No hit for the row's member may carry the hidden page's title, and a hit
   * for the control member must, so a search that finds nothing for anyone
   * cannot pass. Every hit is read, a page at a time.
   *
   * @return array{pass: bool, reasons: string[]}
   *   The verdict.
   */
  private function searchRow(array $row): array {
    $index = \Drupal::entityTypeManager()->getStorage('search_api_index')->load('social_content');
    if ($index === NULL || !$index->status()) {
      return ['pass' => FALSE, 'reasons' => ['the social_content index is not on']];
    }
    try {
      $member = $this->searchHits($index, $this->account((string) PackCheckRows::persona($row)), (string) $row['keys']);
      $control = $this->searchHits($index, $this->account((string) $row['control_as']), (string) $row['keys']);
    }
    catch (\RuntimeException) {
      return ['pass' => FALSE, 'reasons' => ['a member is not on this site; is the pack loaded?']];
    }
    catch (\Throwable $e) {
      return ['pass' => FALSE, 'reasons' => ['search failed: ' . get_class($e)]];
    }
    return PackCheckRows::searchVerdict((string) $row['title'], $member, $control, PackCheckRows::hiddenTitles($row));
  }

  /**
   * Runs a search_found row: the member finds the page by its keys.
   *
   * @return array{pass: bool, reasons: string[]}
   *   The verdict.
   */
  private function foundRow(array $row): array {
    $index = \Drupal::entityTypeManager()->getStorage('search_api_index')->load('social_content');
    if ($index === NULL || !$index->status()) {
      return ['pass' => FALSE, 'reasons' => ['the social_content index is not on']];
    }
    try {
      $member = $this->searchHits($index, $this->account((string) PackCheckRows::persona($row)), (string) $row['keys']);
    }
    catch (\RuntimeException) {
      return ['pass' => FALSE, 'reasons' => ['a member is not on this site; is the pack loaded?']];
    }
    catch (\Throwable $e) {
      return ['pass' => FALSE, 'reasons' => ['search failed: ' . get_class($e)]];
    }
    return PackCheckRows::foundVerdict((string) $row['title'], $member);
  }

  /**
   * Runs an indexed row: every named index tracks the page, has indexed it
   * and holds it in its own backend.
   *
   * The tracker (search_api_item) says what Drupal believes. The backend proof
   * asks the backend itself: a search query for the page's item id on an index
   * of the search server, and a point filter on the vector store for the index
   * of the AI search backend. Nothing the backend holds is read or printed.
   *
   * @return array{pass: bool, reasons: string[]}
   *   The verdict.
   */
  private function indexedRow(array $row): array {
    $page = $this->entity('node', (string) $row['uuid']);
    if ($page === NULL) {
      return ['pass' => FALSE, 'reasons' => [PackCheckRows::MISSING]];
    }
    $item_id = PackCheckRows::itemId('node', $page->id(), $page->language()->getId());
    $database = \Drupal::database();
    $states = [];
    $proofs = [];
    foreach ($row['indexes'] as $index_id) {
      $status = $database->select('search_api_item', 'i')
        ->fields('i', ['status'])
        ->condition('index_id', (string) $index_id)
        ->condition('item_id', $item_id)
        ->execute()
        ->fetchField();
      $states[(string) $index_id] = $status === FALSE ? NULL : (int) $status;
      $proofs[(string) $index_id] = $this->backendProof((string) $index_id, $item_id);
    }
    return PackCheckRows::indexedVerdict($states, $proofs);
  }

  /**
   * Asks an index's own backend whether it holds an item.
   *
   * @return array{kind: string, result: string}
   *   The kind of backend (PackCheckRows::backendKind()) and found, missing or
   *   down. An index that is not on, or whose server cannot be read, gives an
   *   empty kind and result, which the verdict reports as not checked.
   */
  private function backendProof(string $index_id, string $item_id): array {
    $index = \Drupal::entityTypeManager()->getStorage('search_api_index')->load($index_id);
    if ($index === NULL || !$index->status()) {
      return ['kind' => '', 'result' => ''];
    }
    try {
      $server = $index->getServerInstance();
      $kind = PackCheckRows::backendKind($server->getBackend()->getPluginId());
    }
    catch (\Throwable) {
      return ['kind' => '', 'result' => ''];
    }
    try {
      if ($kind === 'vector') {
        $config = $server->getBackendConfig();
        $provider = \Drupal::service('ai.vdb_provider')->createInstance($config['database']);
        $points = $provider->querySearch(
          (string) $config['database_settings']['collection'],
          ['id'],
          PackCheckRows::vectorFilter($item_id, $index_id),
          1,
          0,
          (string) ($config['database_settings']['database_name'] ?? 'default')
        );
        return ['kind' => $kind, 'result' => $points === [] ? 'missing' : 'found'];
      }
      $query = $index->query();
      $query->range(0, 1);
      $query->setOption('search_api_bypass_access', TRUE);
      $query->addCondition('search_api_id', $item_id);
      $found = FALSE;
      foreach ($query->execute()->getResultItems() as $item) {
        $found = $found || $item->getId() === $item_id;
      }
      return ['kind' => $kind, 'result' => $found ? 'found' : 'missing'];
    }
    catch (\Throwable) {
      return ['kind' => $kind, 'result' => 'down'];
    }
  }

  /**
   * Every hit's title for a query as one account.
   *
   * A hit whose page cannot be loaded counts as an empty title, so a full
   * page of results is still a full page.
   *
   * @return array{hits: string[], complete: bool}
   *   What PackCheckRows::allHits read.
   */
  private function searchHits(object $index, AccountInterface $account, string $keys): array {
    $switcher = \Drupal::service('account_switcher');
    $switcher->switchTo($account);
    try {
      return PackCheckRows::allHits(function (int $offset, int $size) use ($index, $account, $keys): array {
        $query = $index->query();
        $query->keys($keys);
        $query->range($offset, $size);
        $query->addCondition('type', ['post', 'topic', 'event'], 'IN');
        \Drupal::service('social_ai_indexing.permission_filter')
          ->applyMemberAwareFilters($query, $account, HybridSearchService::KEYWORD_GROUP_FIELD);
        $titles = [];
        foreach ($query->execute()->getResultItems() as $item) {
          $title = '';
          if (preg_match('#^entity:([a-z_]+)/(\d+):#', $item->getId(), $m)) {
            $entity = \Drupal::entityTypeManager()->getStorage($m[1])->load((int) $m[2]);
            $title = $entity === NULL ? '' : (string) $entity->label();
          }
          $titles[] = $title;
        }
        return $titles;
      });
    }
    finally {
      $switcher->switchBack();
    }
  }

  /**
   * The pages an answer cites, as uuid and title.
   *
   * Reads the Sources lines of the answer and maps each link to the page the
   * source registry holds for it.
   *
   * @return array<int, array{uuid: string, title: string}>
   *   The cited pages.
   */
  private function citedSources(string $answer): array {
    $by_url = [];
    foreach (\Drupal::service('social_ai_indexing.source_registry')->all() as $source) {
      $by_url[$source->url] = $source;
    }
    $cited = [];
    if (preg_match_all('/^- \[(\d+)\] \[(.+)\]\((\S+)\)$/m', $answer, $matches, PREG_SET_ORDER)) {
      foreach ($matches as $match) {
        $source = $by_url[$match[3]] ?? NULL;
        if ($source === NULL) {
          continue;
        }
        $entity = \Drupal::entityTypeManager()->getStorage($source->entityType)->load($source->entityId);
        if ($entity !== NULL) {
          $cited[] = ['uuid' => (string) $entity->uuid(), 'title' => $match[2]];
        }
      }
    }
    return $cited;
  }

  /**
   * The member and the group a row asks as and in.
   *
   * A row with no group asks site-wide: the group is NULL and the runner gets
   * an empty context. A row that names a group or a member the site does not
   * have is not site-wide; it cannot run, so a row for another library never
   * runs by accident on a pack site.
   *
   * @return array{0: \Drupal\Core\Session\AccountInterface, 1: \Drupal\group\Entity\GroupInterface|null}|null
   *   The pair, or NULL when the member or the named group is not on the site.
   */
  private function resolve(array $row): ?array {
    try {
      $account = $this->account((string) PackCheckRows::persona($row));
    }
    catch (\RuntimeException) {
      return NULL;
    }
    $uuid = PackCheckRows::groupUuid($row);
    if ($uuid === NULL) {
      return [$account, NULL];
    }
    $group = $this->entity('group', $uuid);
    return $group === NULL ? NULL : [$account, $group];
  }

  /**
   * The account for a persona: "anonymous" or a pack user's uuid.
   */
  private function account(string $persona): AccountInterface {
    if ($persona === 'anonymous') {
      return new AnonymousUserSession();
    }
    $account = $this->entity('user', $persona);
    if ($account === NULL) {
      throw new \RuntimeException("No user for persona $persona; is the pack loaded?");
    }
    return $account;
  }

  /**
   * Loads an entity by uuid.
   */
  private function entity(string $type, string $uuid): mixed {
    return \Drupal::service('entity.repository')->loadEntityByUuid($type, $uuid);
  }

  /**
   * A short code for an error: its class, an HTTP status and quota words.
   *
   * The message itself is not printed, since a provider error can repeat the
   * question it was given.
   */
  private static function errorCode(\Throwable $e): string {
    $message = $e->getMessage();
    $status = preg_match('/\b(429|500|503|400|403)\b/', $message, $m) ? $m[1] : '';
    $quota = stripos($message, 'quota') !== FALSE ? ' quota' : '';
    if (preg_match('/per ?day|PerDay/i', $message)) {
      $quota .= ' daily_quota';
    }
    return get_class($e) . ($status ? " $status" : '') . $quota;
  }

}

// Options from "drush php:script ... -- --pack=smoke --dry-run".
$options = [];
foreach (($extra ?? []) as $arg) {
  if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', (string) $arg, $m)) {
    $options[$m[1]] = $m[2] ?? TRUE;
  }
}
$code = (new DemoPackCheck($options))->run();
if ($code !== 0) {
  exit($code);
}
