<?php

declare(strict_types=1);

namespace Drupal\localnodes_events_calendar\DemoContent;

/**
 * Lets a demo Event plugin read "this month day N" and "next month day N".
 *
 * Use in a subclass of social_demo's Event plugin.
 */
trait MonthAnchoredEventDates {

  /**
   * {@inheritdoc}
   */
  protected function createEventDate($date_string) {
    return DemoDate::dateTime((string) $date_string);
  }

}
