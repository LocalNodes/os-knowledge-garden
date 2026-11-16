<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Eval;

/**
 * The rules for one planned run in its own process (verify_cited_answers.php).
 *
 * The coordinating process starts one child per planned run with
 * --run-index. A child runs that one run, appends exactly one record and
 * judges that record alone. Checks that compare runs with each other, and
 * any retry they ask for, belong to the coordinating process, which holds
 * every record.
 *
 * Pure and static. Eval tooling only: nothing on a live request path calls
 * it.
 */
final class IsolatedChild {

  /**
   * Whether this process compares runs with each other.
   *
   * @param array $options
   *   The script's options.
   */
  public static function crossChecksHere(array $options): bool {
    return !isset($options['run-index']);
  }

  /**
   * What is wrong with a finished child, or NULL when nothing is.
   *
   * A child exits 1 when its record carries a critical failure and 0
   * otherwise. Any other status, or a status that does not match the
   * record, means the child did not finish as planned. The reason never
   * holds the child's output.
   *
   * @param array[] $records
   *   The records the child appended.
   * @param int $status
   *   The child's exit status.
   * @param bool $expect_record
   *   FALSE for a run that changes the site and writes no record.
   */
  public static function problem(array $records, int $status, bool $expect_record = TRUE): ?string {
    $expected_records = $expect_record ? 1 : 0;
    if (count($records) !== $expected_records) {
      return sprintf('the child process wrote %d records (expected %d)', count($records), $expected_records);
    }
    $expected_status = ($records && !empty($records[0]['critical'])) ? 1 : 0;
    if ($status !== $expected_status) {
      return sprintf('the child process exited with status %d (expected %d)', $status, $expected_status);
    }
    return NULL;
  }

}
