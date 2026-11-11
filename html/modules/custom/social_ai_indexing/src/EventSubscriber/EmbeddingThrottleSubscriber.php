<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\EventSubscriber;

use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\ai\OperationType\Embeddings\EmbeddingsCollectionInput;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps indexing embeddings under a per-minute budget (22.2-12, D-07).
 *
 * Gemini's free tier allows about 100 embedding requests a minute. A full
 * reindex sends far more than that, and every 429 costs a retry. This
 * subscriber counts the embeddings calls that indexing makes and holds the
 * count of the last 60 seconds to
 * social_ai_indexing.settings:embedding_requests_per_minute (default 60).
 *
 * What counts. Only calls tagged 'indexing' (ai_search tags every chunk
 * embedding it makes during an index run that way; the search backend's
 * query embeddings carry no such tag, so search is never held back). The
 * unit is the text, not the request: one batch call of N texts costs N units.
 * 22.2-11 could not measure how Gemini counts a batch on the free tier, and
 * counting texts can only pace indexing more slowly than needed, never
 * overrun the limit (22.2-NOTES.md, "Batch embeddings", WINDOWS 57).
 *
 * What happens when the budget is used up:
 * - Under drush or cron (CLI) the call waits until enough of the window has
 *   aged out, at most 60 seconds per wait, and then goes on.
 * - In a web request it never waits. A member's save runs post-request
 *   indexing in a PHP-FPM worker, and a worker that waits is a worker lost.
 *   The call raises an AiRateLimitException instead. ThrottledQdrantProvider
 *   treats that as "stop and leave the items tracked": old vectors stay, the
 *   items are retried by cron, and its usual 60-second backoff applies. The
 *   window frees within that time anyway, so one rule covers both.
 *
 * A call whose own units exceed the whole budget (a batch of 50 texts under a
 * budget of 30) goes through when the window is empty, so it cannot be
 * refused forever.
 *
 * Priority 40: after the usage-ceiling check (50), which throws first so that
 * a call stopped there costs no budget, and before ai_usage_limits (0).
 */
class EmbeddingThrottleSubscriber implements EventSubscriberInterface {

  /**
   * State key: the calls of the last 60 seconds, as [time, units] pairs.
   */
  public const WINDOW_STATE_KEY = 'social_ai_indexing.embedding_window';

  /**
   * The lock that guards the window against concurrent index runs.
   */
  public const LOCK_NAME = 'social_ai_indexing_embedding_throttle';

  /**
   * The exception message (no content).
   */
  public const MESSAGE = 'Local embeddings budget reached';

  /**
   * The budget used when the setting is missing (existing instances).
   */
  public const DEFAULT_BUDGET = 60;

  /**
   * The length of the sliding window, in seconds.
   */
  protected const WINDOW_SECONDS = 60;

  /**
   * The longest single wait, in seconds.
   */
  protected const MAX_WAIT_SECONDS = 60;

  /**
   * Most reserve attempts of one call before it gives up.
   */
  protected const MAX_ATTEMPTS = 10;

  /**
   * The operation types that embed text.
   */
  protected const EMBEDDING_OPERATIONS = ['embeddings', 'embeddings_collection'];

  /**
   * Constructs an EmbeddingThrottleSubscriber.
   */
  public function __construct(
    protected readonly StateInterface $state,
    protected readonly LockBackendInterface $lock,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly TimeInterface $time,
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreGenerateResponseEvent::EVENT_NAME => ['onPreGenerate', 40],
    ];
  }

  /**
   * Counts an indexing embeddings call, and waits or stops when over budget.
   *
   * @throws \Drupal\ai\Exception\AiRateLimitException
   *   In a web request, when the budget for the last minute is used up.
   */
  public function onPreGenerate(PreGenerateResponseEvent $event): void {
    if (!in_array($event->getOperationType(), static::EMBEDDING_OPERATIONS, TRUE)
      || !in_array('indexing', $event->getTags(), TRUE)) {
      return;
    }
    $budget = $this->budget();
    if ($budget < 1) {
      // A budget below 1 switches the throttle off.
      return;
    }
    $units = $this->unitsFor($event);
    for ($attempt = 0; $attempt < static::MAX_ATTEMPTS; $attempt++) {
      $seconds = $this->reserve($units, $budget);
      if ($seconds === 0) {
        return;
      }
      if (!$this->canWait()) {
        throw new AiRateLimitException(static::MESSAGE);
      }
      $this->logger->info('embedding_throttle_wait seconds=@seconds units=@units budget=@budget', [
        '@seconds' => $seconds,
        '@units' => $units,
        '@budget' => $budget,
      ]);
      $this->wait($seconds);
    }
    throw new AiRateLimitException(static::MESSAGE);
  }

  /**
   * The units one embeddings call uses: its texts.
   *
   * A single 'embeddings' call is one text. A batch ('embeddings_collection')
   * is as many as it has prompts. See the class comment for why texts.
   */
  public function unitsFor(PreGenerateResponseEvent $event): int {
    $input = $event->getInput();
    if ($event->getOperationType() === 'embeddings_collection' && $input instanceof EmbeddingsCollectionInput) {
      return max(1, $input->getPromptCount());
    }
    return 1;
  }

  /**
   * Whether this run may wait: only drush and cron (PHP CLI).
   */
  protected function canWait(): bool {
    return PHP_SAPI === 'cli';
  }

  /**
   * Waits for the window to free. The only pause in this module.
   *
   * @param int $seconds
   *   How long; capped at 60.
   */
  protected function wait(int $seconds): void {
    sleep(max(0, min($seconds, static::MAX_WAIT_SECONDS)));
  }

  /**
   * The current time in seconds, with a fraction.
   */
  protected function now(): float {
    return $this->time->getCurrentMicroTime();
  }

  /**
   * The configured budget, in units per minute.
   */
  protected function budget(): int {
    $value = $this->configFactory->get('social_ai_indexing.settings')->get('embedding_requests_per_minute');
    return $value === NULL ? static::DEFAULT_BUDGET : (int) $value;
  }

  /**
   * Records the units in the window, or says how long to wait.
   *
   * The window lives in State and is read and written under a lock, so
   * parallel index runs share one budget. State caches its keys in memory, so
   * it is reset after the lock is taken: another process may have written
   * since this one first read it.
   *
   * @return int
   *   0 when the units are recorded; otherwise the seconds (1 to 60) until
   *   enough of the window has aged out.
   */
  protected function reserve(int $units, int $budget): int {
    if (!$this->lock->acquire(static::LOCK_NAME, 10.0)) {
      // Can't see the window, so assume it is full.
      return 1;
    }
    try {
      $this->state->resetCache();
      $now = $this->now();
      $window = $this->state->get(static::WINDOW_STATE_KEY, []);
      $window = array_values(array_filter(
        is_array($window) ? $window : [],
        static fn($entry): bool => is_array($entry) && isset($entry[0], $entry[1]) && (float) $entry[0] > $now - static::WINDOW_SECONDS,
      ));
      $used = (int) array_sum(array_map(static fn(array $entry): int => (int) $entry[1], $window));

      if ($used === 0 || $used + $units <= $budget) {
        $window[] = [$now, $units];
        $this->state->set(static::WINDOW_STATE_KEY, $window);
        return 0;
      }

      // Find when enough of the oldest calls have aged out.
      $this->state->set(static::WINDOW_STATE_KEY, $window);
      $remaining = $used;
      $free_at = $now + 1;
      foreach ($window as [$time, $entry_units]) {
        $remaining -= (int) $entry_units;
        $free_at = (float) $time + static::WINDOW_SECONDS;
        if ($remaining === 0 || $remaining + $units <= $budget) {
          break;
        }
      }
      return (int) max(1, min(static::MAX_WAIT_SECONDS, ceil($free_at - $now)));
    }
    finally {
      $this->lock->release(static::LOCK_NAME);
    }
  }

}
