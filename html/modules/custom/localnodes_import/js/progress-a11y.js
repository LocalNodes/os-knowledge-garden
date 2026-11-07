/**
 * @file
 * Accessible progress for the import batch page (UI-SPEC S4, 22-17).
 *
 * Bootstrap's batch.js (replacing core's) swaps the server-rendered bar for
 * one built by Drupal.theme.progressBar, whose wrapper is aria-live, so a
 * screen reader hears every tick. On our batch only (this library is attached through the
 * importer's progress-bar template), every progress update:
 * - gives the bar role="progressbar", aria-valuemin, aria-valuemax,
 *   aria-valuenow and aria-valuetext "‹i› of ‹N›", read from the batch's
 *   "@current of @total" progress message;
 * - shows "‹i› of ‹N›: ‹name›" (the operation's message) as the visible text;
 * - drops aria-live from the bar and announces the latest finished item
 *   through one separate polite region, at most once every 5 seconds.
 *
 * Only ARIA attributes and textContent are written (T-22-50).
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  /**
   * Minimum time between two announcements, in milliseconds.
   */
  const ANNOUNCE_INTERVAL = 5000;

  /**
   * The polite live region, created once per page.
   */
  let region = null;
  let lastAnnounced = 0;
  let pendingText = null;
  let timer = null;
  let lastQueued = '';

  function settings() {
    return (drupalSettings && drupalSettings.localnodesImportProgress) || {};
  }

  /**
   * The two numbers of an "‹i› of ‹N›" text, or null.
   */
  function counts(text) {
    const match = /(\d+)\D+(\d+)/.exec(text || '');
    return match ? [Number(match[1]), Number(match[2])] : null;
  }

  function liveRegion(anchor) {
    if (region && document.body.contains(region)) {
      return region;
    }
    if (!document.body.contains(anchor)) {
      // batch.js styles the bar before it puts it on the page.
      return null;
    }
    region = document.createElement('div');
    region.className = 'visually-hidden localnodes-import-progress-live';
    region.setAttribute('aria-live', 'polite');
    region.setAttribute('data-ln-progress-live', '');
    // Outside [data-drupal-progress], which batch.js empties.
    const host = anchor.closest('[data-drupal-progress]') || anchor;
    host.parentNode.insertBefore(region, host.nextSibling);
    return region;
  }

  function flush() {
    timer = null;
    if (pendingText === null || !region) {
      return;
    }
    region.textContent = pendingText;
    pendingText = null;
    lastAnnounced = Date.now();
  }

  /**
   * Announces text now, or when 5 seconds have passed since the last one.
   *
   * Only the latest text waiting is kept: a burst of finished items is
   * announced once, with the newest.
   */
  function announce(text) {
    if (!text || text === lastQueued) {
      return;
    }
    lastQueued = text;
    pendingText = text;
    const wait = lastAnnounced + ANNOUNCE_INTERVAL - Date.now();
    if (wait <= 0) {
      flush();
    }
    else if (timer === null) {
      timer = window.setTimeout(flush, wait);
    }
  }

  /**
   * Brings one progress bar element up to the S4 contract.
   */
  function enhance(wrapper) {
    if (!wrapper || !wrapper.querySelector) {
      return;
    }
    wrapper.removeAttribute('aria-live');
    const bar = wrapper.querySelector('.progress-bar');
    const message = wrapper.querySelector('.message');
    const label = wrapper.querySelector('.progress-label');
    if (!bar || !message) {
      return;
    }
    bar.setAttribute('role', 'progressbar');
    bar.setAttribute('aria-valuemin', '0');
    if (settings().label) {
      bar.setAttribute('aria-label', settings().label);
    }

    const done = label ? label.textContent.trim() : '';
    // Before the first item finishes the message is "0 of ‹N›"; afterwards
    // the progress message is "‹i› of ‹N›" and the label names the item.
    const numbers = counts(done) || counts(message.textContent);
    if (numbers) {
      bar.setAttribute('aria-valuemax', String(numbers[1]));
      bar.setAttribute('aria-valuenow', String(Math.min(numbers[0], numbers[1])));
      bar.setAttribute('aria-valuetext', Drupal.t('@current of @total', {
        '@current': numbers[0],
        '@total': numbers[1],
      }));
    }

    if (done) {
      // One visible line, "‹i› of ‹N›: ‹name›".
      message.textContent = done;
      label.textContent = '';
      liveRegion(wrapper);
      announce(done);
    }
    else {
      liveRegion(wrapper);
    }
  }

  /**
   * Wraps the progress bar's setProgress once, after every script loaded.
   */
  function wrapProgressBar() {
    const proto = Drupal.ProgressBar && Drupal.ProgressBar.prototype;
    if (!proto || proto.localnodesImportA11y) {
      return;
    }
    const original = proto.setProgress;
    proto.setProgress = function (percentage, message, label) {
      original.call(this, percentage, message, label);
      // core/misc/progress.js keeps the element as a jQuery object.
      enhance(this.element && this.element.jquery ? this.element[0] : this.element);
    };
    proto.localnodesImportA11y = true;
  }

  Drupal.behaviors.localnodesImportProgressA11y = {
    attach(context) {
      wrapProgressBar();
      // A bar batch.js built before this behaviour ran (bootstrap's batch.js
      // replaces the [data-drupal-progress] element with its own bar), or
      // the no-JS bar until batch.js replaces it. This library is attached
      // on the import batch page only.
      once('localnodes-import-progress-a11y', '.progress-wrapper', context).forEach(enhance);
    },
  };
})(Drupal, drupalSettings, once);
