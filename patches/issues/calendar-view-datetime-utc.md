Status: draft, not posted

Before posting, search the Calendar View issue queue for an existing report ("wrong day timezone", "events shifted UTC", "datetime offset strtotime") and add to that one if it exists. Attach `patches/calendar-view-datetime-utc.patch` from our repository.

# drupal.org Issue: Date and time fields land on the wrong day when the site is not in UTC

## Issue Metadata

- **Title:** Date and time fields land on the wrong day when the site is not in UTC
- **Project:** Calendar View (drupal/calendar_view)
- **Version:** 2.1.x (seen on 2.1.10)
- **Component:** Code
- **Category:** Bug report
- **Priority:** Normal
- **Status:** Active

## Problem/Motivation

A datetime field stores its value as a UTC string without an offset, for example `2026-11-21T01:00:00` for 18:00 on 20 November in Denver.

`CalendarViewBase::getDateFieldValues()` returns the raw item values, and `_calendar_view_ensure_timestamp_value()` turns them into timestamps with `strtotime()`. `strtotime()` reads a string without an offset in PHP's default time zone, and Drupal sets that to the site's or the current user's time zone. The stored UTC time is read as local time, so every event moves by the zone's offset: in Denver an event at 18:00 or later shows on the next day, and the time printed in the cell is seven hours late.

Smart date and daterange fields that store timestamps are not affected.

### Steps to reproduce

1. Set the site's default time zone to America/Denver.
2. Create a node with a datetime field set to 20 November, 18:00.
3. Build a month calendar on that field.
4. The node shows on 21 November.

## Proposed resolution

In `getDateFieldValues()`, when the item is a `DateTimeItemInterface` and a value has a time of day, parse it with `DateTimeItemInterface::DATETIME_STORAGE_FORMAT` in `DateTimeItemInterface::STORAGE_TIMEZONE` and return the timestamp. `_calendar_view_ensure_timestamp_value()` then passes the number through unchanged. Date-only values (no `T`) are left as they are.

The patch is in this issue. With it, the same node shows on 20 November at 18:00, an event at 23:30 stays on its day, and an event in summer time (MDT) lands correctly too.

## Remaining tasks

- Review.
- A test: a datetime field value at 18:00 in a site time zone west of UTC renders in that day's cell.

## API changes

None.

## AI disclosure

This report and patch were drafted with an AI assistant and checked by the account holder before posting.
