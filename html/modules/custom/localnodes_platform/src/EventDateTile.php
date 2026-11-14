<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\node\NodeInterface;

/**
 * The date tile on an event card: the start's weekday, day and month.
 *
 * The tile repeats, larger, the date line Open Social prints under the title,
 * so it reads the start the same way: in the viewer's timezone, or in UTC for
 * an all-day event (social_event's _social_event_format_date()). The date
 * line stays for screen readers; the tile is hidden from them.
 */
final class EventDateTile {

  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * The start's weekday, day and month, or NULL when the event has no start.
   *
   * @return array{weekday: string, day: string, month: string}|null
   *   Short, translated names ("Sat", "16", "Mar").
   */
  public function parts(NodeInterface $event): ?array {
    if (!$event->hasField('field_event_date') || $event->get('field_event_date')->isEmpty()) {
      return NULL;
    }
    $start = $event->get('field_event_date')->date;
    if (!$start) {
      return NULL;
    }
    $all_day = $event->hasField('field_event_all_day') && (bool) $event->get('field_event_all_day')->value;
    $timezone = $all_day ? 'UTC' : NULL;
    $timestamp = $start->getTimestamp();
    return [
      'weekday' => $this->dateFormatter->format($timestamp, 'custom', 'D', $timezone),
      'day' => $this->dateFormatter->format($timestamp, 'custom', 'j', $timezone),
      'month' => $this->dateFormatter->format($timestamp, 'custom', 'M', $timezone),
    ];
  }

  /**
   * The tile's render array; only cache metadata when there is no start.
   */
  public function build(NodeInterface $event): array {
    $build = [];
    $parts = $this->parts($event);
    if ($parts !== NULL) {
      $build = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['aria-hidden' => 'true', 'class' => ['ln-event-date']],
      ];
      foreach ($parts as $part => $text) {
        $build[$part] = [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => ['class' => ['ln-event-date__' . $part]],
          '#plain_text' => $text,
        ];
      }
    }
    (new CacheableMetadata())
      ->addCacheableDependency($event)
      ->addCacheContexts(['timezone', 'languages:language_interface'])
      ->applyTo($build);
    return $build;
  }

}
