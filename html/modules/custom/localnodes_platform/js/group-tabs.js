/**
 * @file
 * A real button for socialblue's "more tabs" arrow (22-30 KB-08, Q1).
 *
 * From 900 px wide, socialblue's navbar-secondary.js moves the group tabs
 * that don't fit into a list it opens and closes from a <span class="caret">
 * on click. The span has no role, no name and no Tab stop, so a keyboard
 * can't reach those tabs, and a screen reader doesn't know they're there.
 *
 * This script, without changing socialblue:
 * - puts a <button> named "More group pages" inside each caret. A press of
 *   the button (mouse, Enter or Space) is a click that bubbles to the span,
 *   so socialblue's own handler still opens and closes the list;
 * - keeps aria-expanded in step with the span's "active" class;
 * - moves the caret before the list, so Tab goes from the button into the
 *   pages it shows;
 * - closes an open list on Escape and puts focus back on the button;
 * - keeps the tab list a valid list while socialblue wraps its items.
 * socialblue rebuilds the tab bar on resize; a MutationObserver gives each
 * new caret its button.
 */
(function (Drupal, once) {
  'use strict';

  let count = 0;

  function sync(caret, button) {
    button.setAttribute('aria-expanded', caret.classList.contains('active') ? 'true' : 'false');
  }

  /**
   * Keeps the tab list a valid list while socialblue wraps it.
   *
   * socialblue wraps the <li>s in two <div>s (shown and hidden) and appends
   * the caret inside the <ul>, so the <ul> holds non-list children. While
   * the wrappers are there, the <ul> has no role and each wrapper is the
   * list; when socialblue unwraps (below 900 px), the <ul> is the list again.
   */
  function syncListRoles(nav) {
    nav.querySelectorAll('ul.navbar-nav').forEach(function (ul) {
      const wrappers = ul.querySelectorAll(':scope > .visible-list, :scope > .hidden-list');
      if (wrappers.length) {
        ul.setAttribute('role', 'none');
        wrappers.forEach(function (wrapper) {
          wrapper.setAttribute('role', 'list');
        });
      }
      else if (ul.getAttribute('role') === 'none') {
        ul.removeAttribute('role');
      }
    });
  }

  function enhance(nav) {
    syncListRoles(nav);
    nav.querySelectorAll('.caret').forEach(function (caret) {
      if (caret.querySelector('.ln-tabs-more')) {
        return;
      }
      const list = caret.parentNode && caret.parentNode.querySelector('.hidden-list');
      if (!list) {
        return;
      }
      if (!list.id) {
        count += 1;
        list.id = 'ln-tabs-more-list-' + count;
      }
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'ln-tabs-more';
      button.setAttribute('aria-label', Drupal.t('More group pages'));
      button.setAttribute('aria-controls', list.id);
      caret.appendChild(button);
      sync(caret, button);
      new MutationObserver(function () {
        sync(caret, button);
      }).observe(caret, {attributes: true, attributeFilter: ['class']});
      if (caret.nextElementSibling !== list) {
        caret.parentNode.insertBefore(caret, list);
      }
    });
  }

  Drupal.behaviors.localnodesGroupTabs = {
    attach: function (context) {
      once('ln-group-tabs', '.navbar-secondary', context).forEach(function (nav) {
        enhance(nav);
        new MutationObserver(function () {
          enhance(nav);
        }).observe(nav, {childList: true, subtree: true});

        nav.addEventListener('keydown', function (event) {
          if (event.key !== 'Escape') {
            return;
          }
          const caret = nav.querySelector('.caret.active');
          const button = caret && caret.querySelector('.ln-tabs-more');
          if (!button) {
            return;
          }
          event.preventDefault();
          button.click();
          button.focus();
        });
      });
    }
  };
})(Drupal, once);
