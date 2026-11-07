/**
 * @file
 * Fills the search page's AI overview panel (22-UI-SPEC S8).
 *
 * The endpoint returns {summary_html, sources}. summary_html is filtered on
 * the server to p, strong, em, ul, ol and li (AI-SPEC pitfall 20), so it is
 * the only server string inserted as HTML. Everything else is built with
 * createElement and textContent: the inline [n] links and the Sources list
 * come from the structured sources, and only root-relative URLs become
 * links. With no overview, or on any error, aria-busy is cleared and the
 * panel is removed; the search results below are the answer.
 */
(function (Drupal, drupalSettings) {
  'use strict';

  var SKELETON_DELAY_MS = 400;

  /**
   * Whether a URL is root-relative: starts with '/' but not '//' or '/\'.
   */
  function isRootRelative(url) {
    return typeof url === 'string' && url.charAt(0) === '/' && url.charAt(1) !== '/' && url.charAt(1) !== '\\';
  }

  /**
   * Says a status change through the page's one polite live region.
   */
  function announce(text) {
    if (Drupal.announce) {
      Drupal.announce(text, 'polite');
    }
  }

  /**
   * Removes the panel, clearing aria-busy first.
   */
  function removePanel(container) {
    container.removeAttribute('aria-busy');
    if (container.parentNode) {
      container.parentNode.removeChild(container);
    }
  }

  /**
   * Three skeleton lines in the answer's shape, plus hidden status text.
   */
  function buildSkeleton() {
    var wrapper = document.createElement('div');
    wrapper.className = 'ai-overview__loading';
    var status = document.createElement('span');
    status.className = 'visually-hidden';
    status.textContent = Drupal.t('Writing an overview of the results');
    wrapper.appendChild(status);
    ['full', 'long', 'short'].forEach(function (size) {
      var line = document.createElement('span');
      line.className = 'ai-overview__skeleton-line ai-overview__skeleton-line--' + size;
      line.setAttribute('aria-hidden', 'true');
      wrapper.appendChild(line);
    });
    return wrapper;
  }

  /**
   * Turns [n] markers in text nodes into links named "Source n: title".
   */
  function linkMarkers(root, byNumber) {
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
    var nodes = [];
    while (walker.nextNode()) {
      if (/\[\d+\]/.test(walker.currentNode.nodeValue)) {
        nodes.push(walker.currentNode);
      }
    }
    nodes.forEach(function (node) {
      var parts = node.nodeValue.split(/(\[\d+\])/);
      var fragment = document.createDocumentFragment();
      parts.forEach(function (part) {
        var match = /^\[(\d+)\]$/.exec(part);
        var source = match ? byNumber[parseInt(match[1], 10)] : null;
        if (!source) {
          if (part !== '') {
            fragment.appendChild(document.createTextNode(part));
          }
          return;
        }
        var link = document.createElement('a');
        link.className = 'ai-overview__marker';
        link.href = source.url;
        link.textContent = '[' + source.n + ']';
        link.setAttribute('aria-label', Drupal.t('Source @n: @title', {'@n': source.n, '@title': source.title}));
        fragment.appendChild(link);
      });
      node.parentNode.replaceChild(fragment, node);
    });
  }

  /**
   * The "Sources" label and ordered list of page-title links.
   */
  function buildSources(sources, labelId) {
    var wrapper = document.createElement('div');
    wrapper.className = 'ai-overview__sources';
    var label = document.createElement('p');
    label.className = 'ai-overview__sources-label';
    label.id = labelId;
    label.textContent = Drupal.t('Sources');
    wrapper.appendChild(label);
    var list = document.createElement('ol');
    list.className = 'ai-overview__source-list';
    list.setAttribute('aria-labelledby', labelId);
    sources.forEach(function (source) {
      var item = document.createElement('li');
      var link = document.createElement('a');
      link.href = source.url;
      link.textContent = source.title;
      item.appendChild(link);
      list.appendChild(item);
    });
    wrapper.appendChild(list);
    return wrapper;
  }

  Drupal.behaviors.aiOverview = {
    attach: function (context) {
      var container = context.querySelector ? context.querySelector('[data-ai-overview]') : null;
      if (!container || container.dataset.aiOverviewAttached) {
        return;
      }
      container.dataset.aiOverviewAttached = 'true';
      var body = container.querySelector('[data-ai-overview-body]');

      var query = (drupalSettings.aiOverview && drupalSettings.aiOverview.query) || '';
      if (!query || !body) {
        removePanel(container);
        return;
      }

      container.setAttribute('aria-busy', 'true');
      var skeletonTimer = window.setTimeout(function () {
        body.textContent = '';
        body.appendChild(buildSkeleton());
        container.hidden = false;
        // The panel is aria-busy, so a live region inside it would be held
        // back; the page's polite region (Drupal.announce) is outside it
        // (22-30 SR-02, UI-SPEC Part 3 "Loading").
        announce(Drupal.t('Writing an overview of the results'));
      }, SKELETON_DELAY_MS);

      fetch('/api/ai/overview?q=' + encodeURIComponent(query), {
        credentials: 'same-origin',
        headers: {'Accept': 'application/json'}
      })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('overview unavailable');
          }
          return response.json();
        })
        .then(function (data) {
          window.clearTimeout(skeletonTimer);
          var sources = (data && Array.isArray(data.sources) ? data.sources : []).filter(function (source) {
            return source && isRootRelative(source.url) && typeof source.title === 'string' && source.title !== '';
          });
          if (!data || typeof data.summary_html !== 'string' || data.summary_html === '' || sources.length === 0) {
            removePanel(container);
            return;
          }
          var byNumber = {};
          sources.forEach(function (source) {
            byNumber[parseInt(source.n, 10)] = source;
          });

          var summary = document.createElement('div');
          summary.className = 'ai-overview__summary';
          // Filtered on the server to p, strong, em, ul, ol and li.
          summary.innerHTML = data.summary_html;
          linkMarkers(summary, byNumber);

          body.textContent = '';
          body.appendChild(summary);
          body.appendChild(buildSources(sources, 'ai-overview-sources-label'));
          container.hidden = false;
          container.removeAttribute('aria-busy');
          // Once, when it's ready: the panel's own header and disclosure
          // line, so the person knows an overview is there and what it is.
          announce(Drupal.t('AI overview') + '. ' + Drupal.t('Written by AI from pages you can see. Check the sources.'));
        })
        .catch(function () {
          window.clearTimeout(skeletonTimer);
          removePanel(container);
        });
    }
  };
})(Drupal, drupalSettings);
