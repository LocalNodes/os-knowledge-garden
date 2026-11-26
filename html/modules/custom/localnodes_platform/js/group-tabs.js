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
 * - puts a <button> that reads "More" inside each caret. A press of
 *   the button (mouse, Enter or Space) is a click that bubbles to the span,
 *   so socialblue's own handler still opens and closes the list;
 * - keeps aria-expanded in step with the span's "active" class;
 * - moves the caret before the list, so Tab goes from the button into the
 *   pages it shows;
 * - closes an open list on Escape and puts focus back on the button;
 * - moves a shown tab into the list when the button would cover it;
 * - keeps the current page's tab in view: when socialblue folds it into the
 *   list, it trades places with the last shown tab; when the bar scrolls
 *   sideways (narrow screens), the bar is scrolled to it;
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

  /**
   * Moves shown tabs into the hidden list while the button covers them.
   *
   * socialblue keeps about 50px free for its small arrow; the "More" button
   * is wider, so the last shown tab can sit under it.
   */
  function makeRoom(caret, list) {
    const shown = caret.parentNode.querySelector(':scope > .visible-list');
    if (!shown) {
      return;
    }
    const button = caret.getBoundingClientRect();
    // The last shown tab that is not the current page's: the current tab
    // stays in view (showCurrent()).
    const lastFoldable = function () {
      let tab = shown.lastElementChild;
      while (tab && (tab.classList.contains('active') || tab.querySelector('a.is-active'))) {
        tab = tab.previousElementSibling;
      }
      return tab;
    };
    let last = lastFoldable();
    while (last && shown.children.length > 1) {
      const covered = [...shown.children].some(function (tab) {
        const box = tab.getBoundingClientRect();
        return box.right > button.left && box.left < button.right;
      });
      if (!covered) {
        return;
      }
      list.insertBefore(last, list.firstElementChild);
      last = lastFoldable();
    }
  }

  /**
   * Keeps the current page's tab where it can be seen.
   *
   * From 900 px socialblue folds the tabs that don't fit, in their order,
   * so a tab late in the order (Decisions, Set up) is folded away on its
   * own page. It trades places with the last shown tab, which is folded
   * instead. Below 900 px the bar scrolls sideways; it is scrolled so the
   * current tab shows.
   */
  function showCurrent(nav) {
    const hidden = nav.querySelector('.hidden-list');
    const shown = nav.querySelector('.visible-list');
    const current = hidden && hidden.querySelector(':scope > li.active, :scope > li > a.is-active');
    if (current && shown && shown.lastElementChild) {
      const item = current.closest('li');
      const last = shown.lastElementChild;
      hidden.insertBefore(last, hidden.firstElementChild);
      shown.appendChild(item);
      const caret = nav.querySelector('.caret');
      if (caret && caret.querySelector('.ln-tabs-more')) {
        makeRoom(caret, hidden);
      }
    }
    const active = nav.querySelector('li.active, a.is-active');
    // The element that scrolls sideways (socialblue scrolls the list itself).
    let scroller = active && active.parentElement;
    while (scroller && scroller !== nav && scroller.scrollWidth <= scroller.clientWidth) {
      scroller = scroller.parentElement;
    }
    if (active && scroller && scroller !== nav && scroller.scrollWidth > scroller.clientWidth) {
      const box = scroller.getBoundingClientRect();
      const tab = active.getBoundingClientRect();
      if (tab.left < box.left || tab.right > box.right) {
        scroller.scrollLeft += tab.left - box.left - (box.width - tab.width) / 2;
      }
    }
  }

  function enhance(nav) {
    syncListRoles(nav);
    showCurrent(nav);
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
      // The visible word is the button's name; the arrow is drawn in CSS.
      button.textContent = Drupal.t('More');
      button.setAttribute('aria-controls', list.id);
      caret.appendChild(button);
      sync(caret, button);
      makeRoom(caret, list);
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
