<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Kernel;

use Drupal\localnodes_import\Batch\ImportBatch;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Entity\ImportRun;

/**
 * A closed tab or a PHP fatal never loses a submitted item (KNOW-01, D-21).
 *
 * The item rows are the report (RESEARCH anti-pattern: $context['results']
 * as the only report). Finalising an interrupted run fails what was left.
 *
 * @group phase22
 * @group localnodes_import
 */
final class InterruptedRunTest extends ImportKernelTestBase {

  /**
   * One imported, one died mid-parse, three never started.
   */
  public function testInterruptedRunIsFinalised(): void {
    $this->assertTrue($this->container->has('localnodes_import.run_finalizer'), 'The run finaliser service is registered');
    [$run, $ids] = $this->createRun(ImportRun::STATUS_IN_PROGRESS, [
      ImportItem::OUTCOME_IMPORTED,
      ImportItem::OUTCOME_PROCESSING,
      ImportItem::OUTCOME_QUEUED,
      ImportItem::OUTCOME_QUEUED,
      ImportItem::OUTCOME_QUEUED,
    ]);

    $this->container->get('localnodes_import.run_finalizer')->finalize($run);

    $this->assertSame([
      [ImportItem::OUTCOME_IMPORTED, NULL],
      [ImportItem::OUTCOME_FAILED, 'unreadable'],
      [ImportItem::OUTCOME_FAILED, 'unknown'],
      [ImportItem::OUTCOME_FAILED, 'unknown'],
      [ImportItem::OUTCOME_FAILED, 'unknown'],
    ], $this->outcomes($ids), 'Processing became unreadable, never-started became unknown, the import stayed');
    $run = $this->reloadRun($run);
    $this->assertSame(ImportRun::STATUS_FINISHED, $run->get('status')->value, 'The run is finished');
    $this->assertNotEmpty($run->get('finished')->value, 'The run has a finish time');
  }

  /**
   * Finalising a run that is already closed changes nothing.
   */
  public function testFinishedRunIsLeftAlone(): void {
    $this->assertTrue($this->container->has('localnodes_import.run_finalizer'), 'The run finaliser service is registered');
    [$run, $ids] = $this->createRun(ImportRun::STATUS_FINISHED, [
      ImportItem::OUTCOME_IMPORTED,
      ImportItem::OUTCOME_PROCESSING,
      ImportItem::OUTCOME_QUEUED,
    ], 1700000000);

    $this->container->get('localnodes_import.run_finalizer')->finalize($run);

    $this->assertSame([
      [ImportItem::OUTCOME_IMPORTED, NULL],
      [ImportItem::OUTCOME_PROCESSING, NULL],
      [ImportItem::OUTCOME_QUEUED, NULL],
    ], $this->outcomes($ids), 'No item changed');
    $run = $this->reloadRun($run);
    $this->assertSame(ImportRun::STATUS_FINISHED, $run->get('status')->value);
    $this->assertSame(1700000000, (int) $run->get('finished')->value, 'The finish time is unchanged');
  }

  /**
   * The batch finish callback closes the run through the finaliser.
   *
   * A batch that ended with an error is recorded as stopped, with every
   * left-over item failed.
   */
  public function testBatchFinishedDelegates(): void {
    $this->assertTrue($this->container->has('localnodes_import.run_finalizer'), 'The run finaliser service is registered');
    [$run, $ids] = $this->createRun(ImportRun::STATUS_IN_PROGRESS, [
      ImportItem::OUTCOME_IMPORTED,
      ImportItem::OUTCOME_QUEUED,
    ]);

    $response = ImportBatch::finished(FALSE, ['run_id' => $run->id()], [], '1 sec');

    $this->assertNotNull($response, 'The finish callback opens the report');
    $this->assertSame([
      [ImportItem::OUTCOME_IMPORTED, NULL],
      [ImportItem::OUTCOME_FAILED, 'unknown'],
    ], $this->outcomes($ids));
    $this->assertSame(ImportRun::STATUS_STOPPED, $this->reloadRun($run)->get('status')->value, 'An errored batch is recorded as stopped');
  }

  /**
   * Creates a run with one item per outcome, in order.
   *
   * @return array
   *   [the run, the item ids].
   */
  private function createRun(string $status, array $outcomes, ?int $finished = NULL): array {
    $group = $this->createFlexibleGroup('Interrupted group');
    $owner = $this->createVerifiedUser('interrupted_organizer');
    $this->addManager($group, $owner);
    $entity_type_manager = $this->container->get('entity_type.manager');
    $run = $entity_type_manager->getStorage('localnodes_import_run')->create([
      'gid' => $group->id(),
      'uid' => $owner->id(),
      'kind' => ImportRun::KIND_FILES,
      'status' => $status,
      'finished' => $finished,
    ]);
    $run->save();
    $ids = [];
    foreach ($outcomes as $weight => $outcome) {
      $item = $entity_type_manager->getStorage('localnodes_import_item')->create([
        'run' => $run->id(),
        'gid' => $group->id(),
        'weight' => $weight,
        'source_name' => "file-$weight.pdf",
        'outcome' => $outcome,
      ]);
      $item->save();
      $ids[] = (int) $item->id();
    }
    return [$run, $ids];
  }

  /**
   * [outcome, reason] per item id, freshly loaded.
   */
  private function outcomes(array $ids): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('localnodes_import_item');
    $storage->resetCache($ids);
    $items = $storage->loadMultiple($ids);
    $this->assertCount(count($ids), $items, 'No submitted item disappeared');
    return array_values(array_map(static fn (ImportItem $item): array => [
      $item->get('outcome')->value,
      $item->get('reason')->value,
    ], array_replace(array_flip($ids), $items)));
  }

  /**
   * The run, freshly loaded.
   */
  private function reloadRun(ImportRun $run): ImportRun {
    $storage = $this->container->get('entity_type.manager')->getStorage('localnodes_import_run');
    $storage->resetCache([$run->id()]);
    return $storage->load($run->id());
  }

}
