<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_import\Entity\ImportRun;
use Drupal\localnodes_import\Service\ImportRunFinalizer;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * S1: the group's Import tab ("Bring in your group's documents").
 *
 * Copy is the 22-UI-SPEC Copywriting Contract's, word for word. Top to
 * bottom: the intro, the S5 search status panel once the group has an
 * imported page (22-25), two choice cards, "Upload files" (S2) and "Import
 * web pages" (S3), the empty state while nothing is imported (D-08), and
 * "Past imports" once the group has a run (D-21).
 *
 * Past imports lists every run of the group, newest first, 50 per page with
 * Drupal's standard pager (D-22). Each entry reopens that run's S4 report.
 * A run still open whose items stopped changing more than 30 minutes ago
 * can't still be running, so it is closed here as the report closes it.
 */
class ImportLandingController extends ControllerBase {

  use RunBreakdownTrait;

  /**
   * Runs per page of Past imports (D-22).
   */
  public const PAST_PAGE_SIZE = 50;

  /**
   * Pager element of Past imports. The S5 lists use 1 and 2.
   */
  public const ELEMENT_PAST = 0;

  public function __construct(
    protected Connection $database,
    protected ImportRunFinalizer $finalizer,
    protected TimeInterface $time,
    protected DateFormatterInterface $dateFormatter,
    protected ClassResolverInterface $classResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('localnodes_import.run_finalizer'),
      $container->get('datetime.time'),
      $container->get('date.formatter'),
      $container->get('class_resolver'),
    );
  }

  /**
   * Renders the landing page.
   */
  public function view(GroupInterface $group): array {
    $gid = (int) $group->id();
    $upload_url = Url::fromRoute('localnodes_import.files', ['group' => $gid])->toString();
    $web_url = Url::fromRoute('localnodes_import.web', ['group' => $gid])->toString();

    // Past imports first: closing a stale run can change its items' outcomes,
    // which the status panel then counts.
    $past = $this->pastImports($gid);

    /** @var \Drupal\localnodes_import\Controller\SearchStatusController $status */
    $status = $this->classResolver->getInstanceFromDefinition(SearchStatusController::class);
    $panel = $status->panel($group);

    $empty = NULL;
    if ($panel === NULL) {
      $empty = [
        'heading' => $this->t('Nothing imported yet'),
        'body' => $this->t('Pages you import appear in the group\'s Topics list. Start with <a href=":upload">Upload files</a> or <a href=":web">Import web pages</a>.', [
          ':upload' => $upload_url,
          ':web' => $web_url,
        ]),
      ];
    }

    $build = [
      '#theme' => 'localnodes_import_landing',
      // No promise that every item becomes searchable: a scanned file never
      // does, and the rest take a few minutes (22-30, Codex CX-05).
      '#intro' => $this->t("Add files or web pages to this group. Each one becomes its own page in the group, and most are ready for search and the group's AI assistant within a few minutes."),
      '#cards' => [
        [
          'url' => $upload_url,
          'title' => $this->t('Upload files'),
          'description' => $this->t('PDF, Word, OpenDocument, text or Markdown files from your computer.'),
          'icon' => 'file',
        ],
        [
          'url' => $web_url,
          'title' => $this->t('Import web pages'),
          'description' => $this->t('Public web or wiki pages, by their address. Each page keeps its source and licence.'),
          'icon' => 'link',
        ],
      ],
      '#empty' => $empty,
      '#status' => $panel,
      '#past' => $past,
      '#attached' => ['library' => ['localnodes_import/status']],
      '#cache' => [
        'tags' => ['localnodes_import_item_list', 'localnodes_import_run_list'],
        'contexts' => ['user.group_permissions', 'url.query_args.pagers:' . self::ELEMENT_PAST],
      ],
    ];
    if ($panel !== NULL || ($past !== NULL && $past['in_progress'])) {
      // The tracker has no cache tag, and an open run may go stale.
      $build['#cache']['max-age'] = 0;
    }
    elseif ($past !== NULL && $past['relative']) {
      // "5 minutes ago" is right for a minute.
      $build['#cache']['max-age'] = 60;
    }
    return $build;
  }

  /**
   * One page of the group's runs, newest first, or NULL before the first.
   *
   * @return array{heading_id: string, rows: array, pager: array, in_progress: bool}|null
   *   The rows (id, url, date, datetime, kind, by, in_progress, lines) and
   *   the pager.
   */
  protected function pastImports(int $gid): ?array {
    $storage = $this->entityTypeManager()->getStorage('localnodes_import_run');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('gid', $gid)
      ->sort('created', 'DESC')
      ->sort('id', 'DESC')
      ->pager(self::PAST_PAGE_SIZE, self::ELEMENT_PAST)
      ->execute();
    if (!$ids) {
      return NULL;
    }
    $storage->resetCache($ids);
    /** @var \Drupal\localnodes_import\Entity\ImportRun[] $runs */
    $runs = $storage->loadMultiple($ids);

    $this->closeStaleRuns($runs);
    $counts = $this->countsByRun(array_keys($runs));

    $rows = [];
    $any_open = FALSE;
    foreach ($ids as $id) {
      $run = $runs[$id] ?? NULL;
      if (!$run instanceof ImportRun) {
        continue;
      }
      $in_progress = $run->get('status')->value === ImportRun::STATUS_IN_PROGRESS;
      $any_open = $any_open || $in_progress;
      $owner = $run->getOwner();
      $created = (int) $run->get('created')->value;
      $rows[] = [
        'id' => (int) $run->id(),
        'url' => Url::fromRoute('localnodes_import.run_report', [
          'group' => $gid,
          'localnodes_import_run' => $run->id(),
        ])->toString(),
        // Under 24 hours, a relative time with the full one in its name
        // (UI-SPEC Part 6 "Numbers and dates"; 22-30, Codex review).
        'date' => $this->pastDate($created),
        'date_full' => $this->dateFormatter->format($created, 'custom', 'j F Y, H:i'),
        'datetime' => $this->dateFormatter->format($created, 'custom', 'Y-m-d\TH:i:sP'),
        'kind' => $run->get('kind')->value === ImportRun::KIND_WEB ? $this->t('Web pages') : $this->t('Files'),
        'by' => $owner instanceof UserInterface ? $owner->getDisplayName() : '',
        'in_progress' => $in_progress,
        'lines' => $this->breakdownLines($counts[(int) $run->id()] ?? []),
      ];
    }

    return [
      'rows' => $rows,
      'in_progress' => $any_open,
      // A relative date ("5 minutes ago") goes stale (22-30).
      'relative' => (bool) array_filter($rows, fn (array $row) => $row['date'] !== $row['date_full']),
      'pager' => [
        '#type' => 'pager',
        '#theme' => 'pager__localnodes_import',
        '#element' => self::ELEMENT_PAST,
        '#route_name' => 'localnodes_import.landing',
        '#route_parameters' => ['group' => $gid],
        '#localnodes_import_label' => $this->t('Past imports pages'),
      ],
    ];
  }

  /**
   * Closes open runs that made no progress for RunReportController::STALE_AFTER.
   *
   * The newest item change is a run's last sign of life; a run without
   * items falls back to its creation time (as the report decides).
   *
   * @param \Drupal\localnodes_import\Entity\ImportRun[] $runs
   *   Runs keyed by id.
   */
  protected function closeStaleRuns(array $runs): void {
    $open = array_filter($runs, static fn(ImportRun $run): bool => $run->get('status')->value === ImportRun::STATUS_IN_PROGRESS);
    if (!$open) {
      return;
    }
    $query = $this->database->select('localnodes_import_item', 'i');
    $query->addField('i', 'run');
    $query->addExpression('MAX(i.changed)', 'last');
    $query->condition('i.run', array_keys($open), 'IN');
    $query->groupBy('i.run');
    $last_change = $query->execute()->fetchAllKeyed();

    $now = (int) $this->time->getRequestTime();
    foreach ($open as $id => $run) {
      $last = max((int) $run->get('created')->value, (int) ($last_change[$id] ?? 0));
      if ($now - $last > RunReportController::STALE_AFTER) {
        $this->finalizer->finalize($run);
      }
    }
  }

  /**
   * Items per outcome for each run, in one query.
   *
   * @param int[] $run_ids
   *   The runs on this page.
   *
   * @return array<int, array<string, int>>
   *   Run id => outcome code => count.
   */
  protected function countsByRun(array $run_ids): array {
    $query = $this->database->select('localnodes_import_item', 'i');
    $query->fields('i', ['run', 'outcome']);
    $query->addExpression('COUNT(i.id)', 'items');
    $query->condition('i.run', $run_ids, 'IN');
    $query->groupBy('i.run');
    $query->groupBy('i.outcome');
    $counts = [];
    foreach ($query->execute() as $row) {
      $counts[(int) $row->run][(string) $row->outcome] = (int) $row->items;
    }
    return $counts;
  }


  /**
   * A past import's date: relative under 24 hours, else the full date.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   "just now", "5 minutes ago", "3 hours ago", or "4 November 2026, 09:12".
   */
  protected function pastDate(int $created): TranslatableMarkup|string {
    $seconds = max(0, (int) $this->time->getRequestTime() - $created);
    if ($seconds < 60) {
      return $this->t('just now');
    }
    if ($seconds < 3600) {
      return $this->formatPlural(intdiv($seconds, 60), '1 minute ago', '@count minutes ago');
    }
    if ($seconds < 86400) {
      return $this->formatPlural(intdiv($seconds, 3600), '1 hour ago', '@count hours ago');
    }
    return $this->dateFormatter->format($created, 'custom', 'j F Y, H:i');
  }

}
