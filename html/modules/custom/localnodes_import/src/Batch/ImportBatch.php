<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Batch;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Url;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Entity\ImportRun;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Batch API callbacks for an import run: one operation per source.
 *
 * Each operation (RESEARCH Pattern 2, D-12, D-21):
 * 1. saves the item as "processing" before the risky step, so a fatal leaves
 *    a row the finish callback turns into "failed";
 * 2. puts social_posts and social_comments into batch tracking, so the new
 *    Topic is tracked (search_api_item status 1) but not embedded in this
 *    request; the background worker indexes it;
 * 3. lets the factory create the Topic and its group relationship (a file
 *    through fromFile(), a web page through fromWebPage());
 * 4. saves the final outcome;
 * 5. leaves batch tracking in `finally`.
 */
final class ImportBatch {

  /**
   * The AI indexes whose post-request indexing is deferred during import.
   */
  public const DEFERRED_INDEXES = ['social_posts', 'social_comments'];

  /**
   * Builds the batch definition for a run.
   *
   * @return array
   *   A batch array for batch_set(). The set carries 'localnodes_import' =>
   *   TRUE so the batch page can recognise it (Pattern 10).
   */
  public static function build(ImportRun $run): array {
    $ids = \Drupal::entityTypeManager()->getStorage('localnodes_import_item')->getQuery()
      ->accessCheck(FALSE)
      ->condition('run', $run->id())
      ->sort('weight')
      ->sort('id')
      ->execute();

    // UI-SPEC S4 copy: the run title, and "‹i› of ‹N›" before and while
    // items finish. progress-a11y.js reads the two numbers for the bar's ARIA
    // values.
    $builder = (new BatchBuilder())
      // Page titles are Title Case (UI-SPEC Part 6, Tom 2026-11-07).
      ->setTitle($run->get('kind')->value === ImportRun::KIND_WEB ? t('Importing Your Web Pages') : t('Importing Your Files'))
      ->setInitMessage(t('@current of @total', ['@current' => 0, '@total' => count($ids)]))
      ->setProgressMessage(t('@current of @total'))
      ->setErrorMessage(t('The import stopped. Files already imported are kept.'))
      ->setFinishCallback([self::class, 'finished']);
    foreach ($ids as $id) {
      $builder->addOperation([self::class, 'importItem'], [(int) $id]);
    }
    $batch = $builder->toArray();
    $batch['localnodes_import'] = TRUE;
    return $batch;
  }

  /**
   * Batch operation: imports one source.
   */
  public static function importItem(int $item_id, array &$context): void {
    $entity_type_manager = \Drupal::entityTypeManager();
    $item = $entity_type_manager->getStorage('localnodes_import_item')->load($item_id);
    if (!$item instanceof ImportItem) {
      return;
    }
    $context['results']['run_id'] = $item->getRunId();
    $context['results']['items'][] = $item_id;
    // "‹i› of ‹N›: ‹name›" (UI-SPEC S4 run progress text): shown under the
    // bar once this operation returns, so it names the item just done.
    $context['results']['total'] ??= self::batchSize($item->getRunId());
    $context['message'] = t('@current of @total: @name', [
      '@current' => count($context['results']['items']),
      '@total' => $context['results']['total'],
      '@name' => $item->label(),
    ]);
    // Only a queued row is work; a re-run must not import a source twice.
    if ($item->get('outcome')->value !== ImportItem::OUTCOME_QUEUED) {
      return;
    }

    // Durable before the risky step (Pitfall 3).
    $item->set('outcome', ImportItem::OUTCOME_PROCESSING)->save();

    $indexes = [];
    try {
      $indexes = array_filter($entity_type_manager->getStorage('search_api_index')->loadMultiple(self::DEFERRED_INDEXES));
    }
    catch (\Throwable) {
      // search_api missing or broken: the import itself still works.
    }
    // Start and stop the same loaded set (Pitfall 8).
    foreach ($indexes as $index) {
      $index->startBatchTracking();
    }
    try {
      $run = $item->get('run')->entity;
      if (!$run instanceof ImportRun) {
        throw new \RuntimeException('Import item has no run.');
      }
      // Web items share the request's one SafeFetcher (through the factory's
      // extractor), which spaces requests to the same host by at least
      // SafeFetcher::MIN_HOST_INTERVAL and remembers bot challenges.
      $factory = \Drupal::service('localnodes_import.topic_factory');
      $outcome = $run->get('kind')->value === ImportRun::KIND_WEB
        ? $factory->fromWebPage($item, $run)
        : $factory->fromFile($item, $run);
      $item->set('outcome', $outcome->code)
        ->set('reason', $outcome->reason)
        ->set('nid', $outcome->nid);
      // Only a Topic this item created is its search item; a duplicate links
      // a Topic another item owns, so it must not count it twice (S5).
      $creates_topic = in_array($outcome->code, [ImportItem::OUTCOME_IMPORTED, ImportItem::OUTCOME_NOT_SEARCHABLE], TRUE);
      if ($creates_topic && $outcome->nid !== NULL && ($node = $entity_type_manager->getStorage('node')->load($outcome->nid))) {
        $item->set('search_item_id', 'entity:node/' . $node->id() . ':' . $node->language()->getId());
      }
      $item->save();
    }
    catch (\Throwable $e) {
      \Drupal::logger('localnodes_import')->error('Import item @id failed: @class: @message', [
        '@id' => $item_id,
        '@class' => get_class($e),
        '@message' => $e->getMessage(),
      ]);
      $item->set('outcome', ImportItem::OUTCOME_FAILED)->set('reason', 'unknown')->save();
    }
    finally {
      foreach ($indexes as $index) {
        $index->stopBatchTracking();
      }
    }
  }

  /**
   * How many operations the run's batch has: one per item.
   *
   * Refused and not-uploaded items are operations too (they return at
   * once), so the count matches the batch's own "@current of @total".
   */
  protected static function batchSize(?int $run_id): int {
    if ($run_id === NULL) {
      return 0;
    }
    return (int) \Drupal::entityTypeManager()->getStorage('localnodes_import_item')->getQuery()
      ->accessCheck(FALSE)
      ->condition('run', $run_id)
      ->count()
      ->execute();
  }

  /**
   * Batch finish callback: closes the run and opens its report.
   *
   * $results is only a hint for the run id; the item rows are the report.
   */
  public static function finished(bool $success, array $results, array $operations, string $elapsed): ?RedirectResponse {
    $run_id = $results['run_id'] ?? NULL;
    $entity_type_manager = \Drupal::entityTypeManager();
    $run = $run_id ? $entity_type_manager->getStorage('localnodes_import_run')->load($run_id) : NULL;
    if (!$run instanceof ImportRun) {
      return NULL;
    }

    // Rows left 'processing' (the operation died: memory fatal, timeout) or
    // 'queued' (never reached) become failed; the run is closed. An errored
    // batch is recorded as stopped.
    \Drupal::service('localnodes_import.run_finalizer')->finalize(
      $run,
      $success ? ImportRun::STATUS_FINISHED : ImportRun::STATUS_STOPPED,
    );

    $url = Url::fromRoute('localnodes_import.run_report', [
      'group' => $run->getGroupId(),
      'localnodes_import_run' => $run->id(),
    ]);
    return new RedirectResponse($url->toString());
  }

}
