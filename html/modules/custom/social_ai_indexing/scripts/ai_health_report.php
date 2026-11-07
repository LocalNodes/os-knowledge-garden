<?php

/**
 * @file
 * AI health report over our own log events (Phase 22, plan 22-29; AI-SPEC §7).
 *
 * Reads the answer_summary, permission_abort and guard_fallback records
 * (channel social_ai_indexing) and the import_summary records (channel
 * localnodes_import) that dblog kept for a time window, plus Search API's
 * tracker and the import item rows. Prints one line per §7 metric against
 * its threshold and exits 1 on any breach.
 *
 * It reads counts, reason codes, index ids, latencies, token counts and
 * timestamps only.
 * The events themselves never carry question, answer, title or excerpt text
 * (E10), and nothing here reads content.
 *
 * Usage:
 *   ddev drush php-script html/modules/custom/social_ai_indexing/scripts/ai_health_report.php -- --since=7d
 * On a Coolify node, the same script with drush through docker exec.
 *
 * Options:
 *   --since=7d   Window: a number with d, h or m (days, hours, minutes), or
 *                plain seconds. Default 7d.
 *
 * Line status: OK, BREACH (a §7 alert threshold is crossed), FOLLOW-UP (a §7
 * smart-sampling signal, no alert threshold), N/A (the metric has no data
 * source yet; the line says why).
 *
 * Exit 0: no breach. Exit 1: at least one breach, a bad option, or no
 * watchdog table (dblog off: the events go nowhere queryable).
 */

use Drupal\Core\Database\Connection;

/**
 * Builds and prints the report.
 */
final class AiHealthReport {

  /**
   * §7 thresholds.
   */
  private const FALLBACK_RATE_MAX = 0.20;
  private const FALLBACK_RATE_TRIGGER_B = 0.50;
  private const FALLBACK_MIN_ANSWERS = 20;
  private const ASSISTANT_P95_MAX_MS = 15000;
  private const SINGLE_REQUEST_MAX_MS = 60000;
  private const OLDEST_PENDING_MAX_S = 3600;
  private const TYPICAL_INPUT_TOKENS_X2 = 17_000;
  private const TYPICAL_OUTPUT_TOKENS_X2 = 2800;
  private const OVERVIEW_P50_MAX_MS = 5000;
  private const MONTHLY_COST_MAX_USD = 20.0;

  /**
   * USD per 1M tokens, gemini-3.8-flash, Standard paid tier (orchestrator,
   * 2026-11-07: $0.75 in, $3.75 out; doubling on 2027-01-01). Output
   * includes reasoning (thinking) tokens.
   */
  private const PRICE_INPUT_PER_M = 0.75;
  private const PRICE_OUTPUT_PER_M = 3.75;

  /**
   * The AI search indexes the assistant reads (localnodes_platform config).
   */
  private const AI_INDEXES = ['social_posts', 'social_comments'];

  /**
   * Search API tracker status of an item that still needs indexing.
   */
  private const TRACKER_PENDING = 1;

  private int $breaches = 0;

  public function __construct(
    private readonly Connection $db,
    private readonly int $now,
    private readonly int $window,
  ) {}

  /**
   * Runs the report; returns the exit code.
   */
  public function run(): int {
    $since = $this->now - $this->window;
    printf("AI health report, window %s (since %s UTC, now %s UTC)\n", self::formatWindow($this->window), gmdate('Y-m-d H:i', $since), gmdate('Y-m-d H:i', $this->now));

    if (!$this->db->schema()->tableExists('watchdog')) {
      $this->line('BREACH', 'log storage', 'no watchdog table: dblog is off, so the events go nowhere queryable', 'dblog enabled (localnodes_platform depends on it)');
      return $this->finish();
    }
    $this->coverage($since);

    $answers = $this->events('social_ai_indexing', 'answer_summary', $since);
    $aborts = $this->events('social_ai_indexing', 'permission_abort', $since);
    $guards = $this->events('social_ai_indexing', 'guard_fallback', $since);
    $imports = $this->events('localnodes_import', 'import_summary', $since);

    print "\n-- Permissions and guards (same-day investigation) --\n";
    $this->countLine('permission aborts', $aborts, '@reason', '0 (any abort means a missing index field or a broken filter)');
    $this->countLine('G4 guard fallbacks', $guards, '@reason', '0 (an exit path the tests missed: add a test)');

    $assistant = array_values(array_filter($answers, static fn (array $a): bool => ($a['@path'] ?? 'assistant') === 'assistant'));
    $overview = array_values(array_filter($answers, static fn (array $a): bool => ($a['@path'] ?? '') === 'overview'));

    print "\n-- Answers (assistant path) --\n";
    $this->answers($assistant);

    print "\n-- Overviews --\n";
    $this->overviews($overview);

    print "\n-- Cost --\n";
    $this->tokens($assistant, $overview);

    print "\n-- Index and import health --\n";
    $this->indexBacklog();
    $this->imports($imports);

    print "\n-- Not instrumented --\n";
    $this->line('N/A', 'Gemini error rate', 'no event records provider exceptions or status codes', '5% of calls in a day; any 400 naming thought signatures');

    return $this->finish();
  }

  /**
   * Notes how much of the window dblog still holds.
   *
   * dblog trims to its row limit on cron, so on a busy node the oldest kept
   * row can be newer than the window start.
   */
  private function coverage(int $since): void {
    $oldest = $this->db->select('watchdog', 'w');
    $oldest->addExpression('MIN(w.timestamp)', 'oldest');
    $oldest = $oldest->execute()->fetchField();
    $limit = \Drupal::config('dblog.settings')->get('row_limit');
    if ($oldest === NULL || $oldest === FALSE) {
      $this->line('OK', 'log coverage', 'watchdog is empty', 'covers the window');
      return;
    }
    $limit_text = $limit ? "row limit $limit" : 'no row limit';
    if ((int) $oldest > $since) {
      $this->line('FOLLOW-UP', 'log coverage', sprintf('oldest kept log row %s UTC, after the window start (%s); counts below cover less than the window', gmdate('Y-m-d H:i', (int) $oldest), $limit_text), 'covers the window');
      return;
    }
    $this->line('OK', 'log coverage', sprintf('oldest kept log row %s UTC (%s)', gmdate('Y-m-d H:i', (int) $oldest), $limit_text), 'covers the window');
  }

  /**
   * The variables of one event type in the window, oldest first.
   *
   * dblog stores the message template ('answer_summary path=@path ...') and
   * the values separately, so rows are matched on the template prefix and
   * the values are read from the serialized variables.
   *
   * @return list<array<string, mixed>>
   *   One array of placeholder values per record.
   */
  private function events(string $channel, string $event, int $since): array {
    $query = $this->db->select('watchdog', 'w')
      ->fields('w', ['variables', 'timestamp'])
      ->condition('w.type', $channel)
      ->condition('w.timestamp', $since, '>=')
      ->condition('w.message', $this->db->escapeLike($event . ' ') . '%', 'LIKE')
      ->orderBy('w.wid');
    $records = [];
    foreach ($query->execute() as $row) {
      $variables = @unserialize((string) $row->variables, ['allowed_classes' => FALSE]);
      $records[] = (is_array($variables) ? $variables : []) + ['timestamp' => (int) $row->timestamp];
    }
    return $records;
  }

  /**
   * A must-be-zero count, with its reason codes.
   */
  private function countLine(string $metric, array $records, string $key, string $threshold): void {
    $value = (string) count($records);
    if ($records) {
      $value .= ' (' . self::tally($records, $key) . ')';
    }
    $this->line($records ? 'BREACH' : 'OK', $metric, $value, $threshold);
  }

  /**
   * Fallback and dropped-label rates, and latency.
   */
  private function answers(array $answers): void {
    $total = count($answers);
    $this->line('OK', 'answers', $total ? "$total (" . self::tally($answers, '@outcome') . ')' : '0', 'none (context for the rates)');

    $fallbacks = count(array_filter($answers, static fn (array $a): bool => ($a['@outcome'] ?? '') === 'fallback'));
    if ($total === 0) {
      $this->line('OK', 'fallback rate', 'no answers in the window', '20% with at least 20 answers');
    }
    else {
      $rate = $fallbacks / $total;
      $value = sprintf('%.1f%% (%d of %d)', $rate * 100, $fallbacks, $total);
      if ($fallbacks) {
        $value .= ', reasons: ' . self::tally(array_filter($answers, static fn (array $a): bool => ($a['@outcome'] ?? '') === 'fallback'), '@reason');
      }
      if ($total < self::FALLBACK_MIN_ANSWERS) {
        $this->line('OK', 'fallback rate', $value . sprintf('; fewer than %d answers, not judged', self::FALLBACK_MIN_ANSWERS), '20% with at least 20 answers');
      }
      else {
        if ($rate > self::FALLBACK_RATE_TRIGGER_B) {
          $value .= '; above 50%: fallback trigger (b)';
        }
        $this->line($rate > self::FALLBACK_RATE_MAX ? 'BREACH' : 'OK', 'fallback rate', $value, '20% with at least 20 answers');
      }
    }

    $with_drops = count(array_filter($answers, static fn (array $a): bool => (int) ($a['@dropped'] ?? 0) > 0));
    $found = array_sum(array_map(static fn (array $a): int => (int) ($a['@found'] ?? 0), $answers));
    $dropped = array_sum(array_map(static fn (array $a): int => (int) ($a['@dropped'] ?? 0), $answers));
    $value = $total
      ? sprintf('%d of %d answers dropped a label; %d of %d labels dropped (%.1f%%)', $with_drops, $total, $dropped, $found, $found ? 100 * $dropped / $found : 0)
      : 'no answers in the window';
    $this->line($with_drops ? 'FOLLOW-UP' : 'OK', 'dropped-label rate', $value, 'no alert; any answer above 0 is a sampling signal');

    $latencies = array_values(array_filter(array_map(static fn (array $a): int => (int) ($a['@latency'] ?? -1), $answers), static fn (int $ms): bool => $ms >= 0));
    sort($latencies);
    if (!$latencies) {
      $this->line('OK', 'assistant latency p50 / p95', 'no timed answers in the window', 'p95 15 s');
      $this->line('OK', 'slowest single answer', 'no timed answers in the window', '60 s (proxy timeout risk)');
    }
    else {
      $p50 = self::percentile($latencies, 50);
      $p95 = self::percentile($latencies, 95);
      $this->line($p95 > self::ASSISTANT_P95_MAX_MS ? 'BREACH' : 'OK', 'assistant latency p50 / p95', sprintf('%.1f s / %.1f s over %d answers', $p50 / 1000, $p95 / 1000, count($latencies)), 'p95 15 s');
      $max = end($latencies);
      $this->line($max > self::SINGLE_REQUEST_MAX_MS ? 'BREACH' : 'OK', 'slowest single answer', sprintf('%.1f s', $max / 1000), '60 s (proxy timeout risk)');
    }
  }

  /**
   * Overview count by outcome, and generation latency p50.
   *
   * The overview's latency_ms covers its generation only (retrieval runs
   * before it), so the p50 here reads a little low against the 5 s target.
   */
  private function overviews(array $overviews): void {
    $total = count($overviews);
    $this->line('OK', 'overviews generated', $total ? "$total (" . self::tally($overviews, '@outcome') . ')' : '0', 'none (context; cache hits log nothing)');
    $latencies = array_values(array_filter(array_map(static fn (array $a): int => (int) ($a['@latency'] ?? -1), $overviews), static fn (int $ms): bool => $ms >= 0));
    sort($latencies);
    if (!$latencies) {
      $this->line('OK', 'overview latency p50', 'no overviews in the window', 'p50 5 s');
      return;
    }
    $p50 = self::percentile($latencies, 50);
    $this->line($p50 > self::OVERVIEW_P50_MAX_MS ? 'BREACH' : 'OK', 'overview latency p50', sprintf('%.1f s over %d overviews (generation only)', $p50 / 1000, count($latencies)), 'p50 5 s');
  }

  /**
   * Tokens per answer and the monthly cost estimate.
   *
   * From the token fields on answer_summary (22-29). Only answers whose
   * chat calls all reported counts go into the means; the cost scales each
   * path's counted spend up to all its answers in the window, then to 30
   * days. Embedding calls aren't in answer_summary; at 4b prices they're a
   * rounding error (about $0.000006 per question). ai_usage_limits' running
   * totals follow for context only: their period isn't the report window.
   */
  private function tokens(array $assistant, array $overview): void {
    $cost = 0.0;
    $uncounted_note = [];
    foreach (['assistant' => $assistant, 'overview' => $overview] as $path => $answers) {
      $counted = array_values(array_filter($answers, static fn (array $a): bool => isset($a['@chat_calls']) && (int) $a['@chat_calls'] > 0 && (int) ($a['@uncounted_calls'] ?? 0) === 0));
      $with_calls = count(array_filter($answers, static fn (array $a): bool => (int) ($a['@chat_calls'] ?? 0) > 0));
      if ($with_calls > count($counted)) {
        $uncounted_note[] = sprintf('%s: %d of %d answers had a call with no token counts', $path, $with_calls - count($counted), $with_calls);
      }
      if (!$counted) {
        $this->line('OK', "tokens per answer ($path)", $answers ? 'no answer with token counts in the window' : 'no answers in the window', $path === 'assistant' ? sprintf('mean input %d, output %d (2x the 4b typical)', self::TYPICAL_INPUT_TOKENS_X2, self::TYPICAL_OUTPUT_TOKENS_X2) : 'none (context)');
        continue;
      }
      $sum = static fn (string $key): int => array_sum(array_map(static fn (array $a): int => (int) ($a[$key] ?? 0), $counted));
      $n = count($counted);
      $in = $sum('@input_tokens');
      $out = $sum('@output_tokens') + $sum('@reasoning_tokens');
      $mean_in = (int) round($in / $n);
      $mean_out = (int) round($out / $n);
      $value = sprintf('mean input %d, output %d (reasoning %d) over %d answers', $mean_in, $mean_out, (int) round($sum('@reasoning_tokens') / $n), $n);
      if ($path === 'assistant') {
        $over = $mean_in > self::TYPICAL_INPUT_TOKENS_X2 || $mean_out > self::TYPICAL_OUTPUT_TOKENS_X2;
        $this->line($over ? 'BREACH' : 'OK', 'tokens per answer (assistant)', $value, sprintf('mean input %d, output %d (2x the 4b typical)', self::TYPICAL_INPUT_TOKENS_X2, self::TYPICAL_OUTPUT_TOKENS_X2));
      }
      else {
        $this->line('OK', 'tokens per answer (overview)', $value, 'none (context; 4b typical input ~900, output ~600)');
      }
      $spend = ($in * self::PRICE_INPUT_PER_M + $out * self::PRICE_OUTPUT_PER_M) / 1e6;
      $cost += $spend * $with_calls / $n;
    }
    foreach ($uncounted_note as $note) {
      $this->line('FOLLOW-UP', 'uncounted token reports', $note, 'none (the provider gave no usage; those answers are left out of the means)');
    }
    $monthly = $this->window > 0 ? $cost * (30 * 86400) / $this->window : 0.0;
    $this->line($monthly > self::MONTHLY_COST_MAX_USD ? 'BREACH' : 'OK', 'monthly cost estimate', sprintf('$%.2f per node (window spend $%.4f scaled to 30 days; chat only, at $%.2f in / $%.2f out per 1M)', $monthly, $cost, self::PRICE_INPUT_PER_M, self::PRICE_OUTPUT_PER_M), '$20 per node');
    $usage = \Drupal::state()->get('ai_usage_limits', []);
    if (!is_array($usage) || !$usage) {
      print "   context: ai_usage_limits has no running totals\n";
      return;
    }
    foreach ($usage as $provider => $counts) {
      if (!is_array($counts)) {
        continue;
      }
      $start = isset($counts['retention_start_date']) ? gmdate('Y-m-d', (int) $counts['retention_start_date']) : 'unknown';
      printf("   context: ai_usage_limits %s since %s: input %d, output %d, total %d tokens (all calls, not per answer)\n",
        preg_replace('/[^a-z0-9_]/i', '', (string) $provider), $start,
        (int) ($counts['input_token_usage'] ?? 0), (int) ($counts['output_token_usage'] ?? 0), (int) ($counts['total_token_usage'] ?? 0));
    }
  }

  /**
   * Backlog and oldest pending item per AI index (is the worker alive?).
   */
  private function indexBacklog(): void {
    if (!$this->db->schema()->tableExists('search_api_item')) {
      $this->line('N/A', 'index backlog', 'no search_api_item table', 'oldest pending item 1 h');
      return;
    }
    $storage = \Drupal::entityTypeManager()->getStorage('search_api_index');
    foreach (self::AI_INDEXES as $index_id) {
      $index = $storage->load($index_id);
      if ($index === NULL) {
        $this->line('N/A', "index $index_id", 'index not installed', 'oldest pending item 1 h');
        continue;
      }
      $query = $this->db->select('search_api_item', 's')
        ->condition('s.index_id', $index_id)
        ->condition('s.status', self::TRACKER_PENDING);
      $query->addExpression('COUNT(*)', 'pending');
      $query->addExpression('MIN(s.changed)', 'oldest');
      $row = $query->execute()->fetchAssoc();
      $pending = (int) ($row['pending'] ?? 0);
      $status = $index->status() ? '' : ' (index disabled)';
      if ($pending === 0) {
        $this->line('OK', "index $index_id", 'no pending items' . $status, 'oldest pending item 1 h');
        continue;
      }
      $age = $this->now - (int) $row['oldest'];
      $this->line($age > self::OLDEST_PENDING_MAX_S ? 'BREACH' : 'OK', "index $index_id", sprintf('%d pending, oldest %s%s', $pending, self::formatWindow(max(0, $age)), $status), 'oldest pending item 1 h');
    }
  }

  /**
   * Import runs in the window, and import items still waiting.
   */
  private function imports(array $imports): void {
    if (!$imports) {
      $this->line('OK', 'imports', 'no import runs finished in the window', 'none (context)');
    }
    else {
      $sum = static fn (string $key): int => array_sum(array_map(static fn (array $r): int => (int) ($r[$key] ?? 0), $imports));
      $imported = $sum('@imported');
      $not_searchable = $sum('@imported_not_searchable');
      $failed = $sum('@failed');
      $topics = $imported + $not_searchable;
      $this->line('OK', 'imports', sprintf('%d runs (%s): %d imported, %d not searchable, %d failed, %d duplicate, %d refreshed, %d refused, %d not uploaded',
        count($imports), self::tally($imports, '@status'), $imported, $not_searchable, $failed, $sum('@duplicate'), $sum('@refreshed'), $sum('@refused'), $sum('@not_uploaded')), 'none (context)');
      $value = $topics ? sprintf('%.1f%% (%d of %d imported pages)', 100 * $not_searchable / $topics, $not_searchable, $topics) : 'no imported pages';
      $this->line($not_searchable || $failed ? 'FOLLOW-UP' : 'OK', 'import not-searchable share', $value . ($failed ? "; $failed failed rows" : ''), 'no alert; any not-searchable or failed row is a sampling signal');
    }

    if (!$this->db->schema()->tableExists('localnodes_import_item')) {
      $this->line('N/A', 'oldest pending import item', 'no import item table', '1 h (follow-up)');
      return;
    }
    $query = $this->db->select('localnodes_import_item', 'i')
      ->condition('i.outcome', ['queued', 'processing'], 'IN');
    $query->addExpression('COUNT(*)', 'pending');
    $query->addExpression('MIN(i.created)', 'oldest');
    $row = $query->execute()->fetchAssoc();
    $pending = (int) ($row['pending'] ?? 0);
    if ($pending === 0) {
      $this->line('OK', 'oldest pending import item', 'none waiting', '1 h (follow-up)');
      return;
    }
    $age = $this->now - (int) $row['oldest'];
    $this->line($age > self::OLDEST_PENDING_MAX_S ? 'FOLLOW-UP' : 'OK', 'oldest pending import item', sprintf('%d waiting, oldest %s', $pending, self::formatWindow(max(0, $age))), '1 h (follow-up: a stopped batch)');
  }

  /**
   * Prints one metric line.
   */
  private function line(string $status, string $metric, string $value, string $threshold): void {
    if ($status === 'BREACH') {
      $this->breaches++;
    }
    printf("%-9s %s: %s [threshold: %s]\n", $status, $metric, $value, $threshold);
  }

  /**
   * Prints the result line; returns the exit code.
   */
  private function finish(): int {
    print "\n" . ($this->breaches ? "Result: {$this->breaches} breach(es)\n" : "Result: no breach\n");
    return $this->breaches ? 1 : 0;
  }

  /**
   * Counts records by one placeholder value: 'a 3, b 1'.
   */
  private static function tally(array $records, string $key): string {
    $counts = [];
    foreach ($records as $record) {
      $value = preg_replace('/[^A-Za-z0-9_:\\\\.-]/', '', (string) ($record[$key] ?? 'unknown'));
      $counts[$value] = ($counts[$value] ?? 0) + 1;
    }
    arsort($counts);
    $parts = [];
    foreach ($counts as $value => $count) {
      $parts[] = "$value $count";
    }
    return implode(', ', $parts);
  }

  /**
   * Nearest-rank percentile of a sorted list.
   */
  private static function percentile(array $sorted, int $p): int {
    $rank = (int) ceil($p / 100 * count($sorted));
    return $sorted[max(0, $rank - 1)];
  }

  /**
   * Seconds as '7d', '5h', '12m' or '40s'.
   */
  public static function formatWindow(int $seconds): string {
    foreach (['d' => 86400, 'h' => 3600, 'm' => 60] as $unit => $size) {
      if ($seconds >= $size) {
        return round($seconds / $size, 1) . $unit;
      }
    }
    return $seconds . 's';
  }

  /**
   * Parses --since: '7d', '12h', '30m' or seconds. NULL when invalid.
   */
  public static function parseWindow(string $value): ?int {
    if (!preg_match('/^(\d+)([dhm]?)$/', $value, $m) || (int) $m[1] === 0) {
      return NULL;
    }
    return (int) $m[1] * ['d' => 86400, 'h' => 3600, 'm' => 60, '' => 1][$m[2]];
  }

}

// Options from "drush php-script ... -- --since=7d".
$options = [];
foreach (($extra ?? []) as $arg) {
  if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', (string) $arg, $m)) {
    $options[$m[1]] = $m[2] ?? TRUE;
  }
}
$window = AiHealthReport::parseWindow((string) ($options['since'] ?? '7d'));
if ($window === NULL) {
  print "Invalid --since: use a number with d, h or m (for example 7d), or seconds.\n";
  exit(1);
}
$report = new AiHealthReport(\Drupal::database(), \Drupal::time()->getCurrentTime(), $window);
$code = $report->run();
if ($code !== 0) {
  exit($code);
}
