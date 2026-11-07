/**
 * @file
 * S5 "Search status" refresh and polling (22-25; UI-SPEC Part 8 S5).
 *
 * - "Refresh search status" reads the status JSON instead of reloading the
 *   page (without JavaScript the button's GET form reloads it).
 * - Polls at most every 30 seconds, only while pages are waiting and the tab
 *   is visible (threat T-22-73). A hidden tab stops polling; coming back
 *   reads again once 30 seconds have passed since the last read.
 * - Numbers change silently. Only the change to "All imported pages are
 *   searchable." is announced, in the panel's one polite live region.
 * - "Updated ‹relative time›" ticks every minute, in the server's words.
 * - Text is written with textContent only; nothing from the response is
 *   parsed as HTML.
 */
((Drupal, once) => {
  const POLL_MS = 30000;
  const TICK_MS = 60000;

  /**
   * "Updated just now", "Updated 5 minutes ago": the same words as PHP.
   */
  function relative(readAtMs) {
    const seconds = Math.max(0, Math.floor((Date.now() - readAtMs) / 1000));
    if (seconds < 60) {
      return Drupal.t('Updated just now');
    }
    if (seconds < 3600) {
      return Drupal.formatPlural(Math.floor(seconds / 60), 'Updated 1 minute ago', 'Updated @count minutes ago');
    }
    return Drupal.formatPlural(Math.floor(seconds / 3600), 'Updated 1 hour ago', 'Updated @count hours ago');
  }

  function init(panel) {
    const url = panel.getAttribute('data-status-url');
    const summary = panel.querySelector('[data-status-summary]');
    const lines = panel.querySelector('[data-status-lines]');
    const all = panel.querySelector('[data-status-all]');
    const allText = all ? all.querySelector('span') : null;
    const live = panel.querySelector('[data-status-live]');
    const time = panel.querySelector('[data-status-time]');
    const relativeText = panel.querySelector('[data-status-relative]');
    const fullText = panel.querySelector('[data-status-full]');
    const form = panel.querySelector('[data-status-refresh]');
    const button = form ? form.querySelector('button') : null;

    let waiting = parseInt(panel.getAttribute('data-waiting'), 10) || 0;
    // The browser's own clock for when the numbers were read: the server's
    // timestamp would be off by any clock difference.
    let readAtMs = Date.now();
    // When the last read started, failed or not: polls are 30 s apart.
    let attemptAtMs = readAtMs;
    let allVisible = all ? !all.hidden : false;
    let pollTimer = null;
    let inFlight = false;

    function tick() {
      if (relativeText) {
        relativeText.textContent = relative(readAtMs);
      }
    }

    function setList(kind, count, label) {
      const details = panel.querySelector(`[data-status-list="${kind}"]`);
      if (!details) {
        // A list that wasn't there on load needs its titles: the next page
        // load shows it.
        return;
      }
      details.hidden = count === 0;
      const toggle = details.querySelector('summary');
      if (toggle && label) {
        toggle.textContent = label;
      }
    }

    function apply(data) {
      if (summary) {
        summary.textContent = data.summary;
      }
      if (lines) {
        lines.replaceChildren(
          ...data.lines.map((line) => {
            const item = document.createElement('li');
            item.textContent = line;
            return item;
          }),
        );
        lines.hidden = data.lines.length === 0;
      }
      const stale = panel.querySelector('[data-status-stale]');
      if (stale && !data.stale) {
        // The server renders the alert once; it is never re-announced.
        stale.hidden = true;
      }
      setList('waiting', data.waiting, data.waiting_label);
      setList('no-text', data.no_text, data.no_text_label);

      if (all) {
        if (allText) {
          allText.textContent = data.all;
        }
        all.hidden = !data.all_visible;
        if (data.all_visible && !allVisible && live) {
          live.textContent = data.all;
        }
        allVisible = data.all_visible;
      }

      waiting = data.waiting;
      readAtMs = Date.now();
      if (time && data.updated_iso) {
        time.setAttribute('datetime', data.updated_iso);
      }
      if (fullText && data.updated_full) {
        fullText.textContent = ` (${data.updated_full})`;
      }
      tick();
    }

    function schedule() {
      window.clearTimeout(pollTimer);
      pollTimer = null;
      if (waiting > 0 && document.visibilityState === 'visible') {
        const wait = attemptAtMs + POLL_MS - Date.now();
        pollTimer = window.setTimeout(read, Math.max(wait, 0));
      }
    }

    async function read() {
      if (inFlight) {
        return;
      }
      inFlight = true;
      attemptAtMs = Date.now();
      if (button) {
        button.setAttribute('aria-disabled', 'true');
      }
      try {
        const response = await fetch(url, {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
          cache: 'no-store',
        });
        if (!response.ok) {
          throw new Error(`status ${response.status}`);
        }
        apply(await response.json());
      } catch (error) {
        // The last good numbers stay with their own "Updated" time, so a
        // failed read never looks current.
        tick();
      } finally {
        inFlight = false;
        if (button) {
          button.removeAttribute('aria-disabled');
        }
        schedule();
      }
    }

    if (form) {
      form.addEventListener('submit', (event) => {
        event.preventDefault();
        read();
      });
    }
    document.addEventListener('visibilitychange', schedule);
    window.setInterval(tick, TICK_MS);
    schedule();
  }

  Drupal.behaviors.localnodesImportStatus = {
    attach(context) {
      once('localnodes-import-status', '[data-localnodes-import-status]', context).forEach(init);
    },
  };
})(Drupal, once);
