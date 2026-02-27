/**
 * @file
 * Fetches and renders an AI-generated overview for search queries.
 */
(function (Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.aiOverview = {
    attach: function (context) {
      var container = context.querySelector('[data-ai-overview]');
      if (!container || container.dataset.aiOverviewAttached) {
        return;
      }
      container.dataset.aiOverviewAttached = 'true';

      var query = (drupalSettings.aiOverview && drupalSettings.aiOverview.query) || '';
      if (!query) {
        container.style.display = 'none';
        return;
      }

      // Show loading skeleton.
      container.innerHTML =
        '<div class="ai-overview__loading">' +
          '<div class="ai-overview__loading-bar"></div>' +
          '<div class="ai-overview__loading-bar ai-overview__loading-bar--short"></div>' +
          '<div class="ai-overview__loading-bar ai-overview__loading-bar--medium"></div>' +
        '</div>';

      fetch('/api/ai/overview?q=' + encodeURIComponent(query), {
        credentials: 'same-origin'
      })
        .then(function (response) {
          return response.json();
        })
        .then(function (data) {
          if (!data.summary) {
            container.style.display = 'none';
            return;
          }

          var html = '<div class="ai-overview__content">';
          html += '<div class="ai-overview__header">';
          html += '<span class="ai-overview__label">' + Drupal.t('AI Overview') + '</span>';
          html += '</div>';
          html += '<div class="ai-overview__summary">' + data.summary + '</div>';

          if (data.citations && data.citations.length > 0) {
            html += '<div class="ai-overview__citations">';
            html += '<span class="ai-overview__citations-label">' + Drupal.t('Sources') + '</span>';
            html += '<ul class="ai-overview__citation-list">';
            data.citations.forEach(function (cite, i) {
              var title = Drupal.checkPlain(cite.title);
              var url = Drupal.checkPlain(cite.url);
              var type = Drupal.checkPlain(cite.type || '');
              html += '<li class="ai-overview__citation">';
              html += '<span class="ai-overview__citation-num">[' + (i + 1) + ']</span> ';
              if (url) {
                html += '<a href="' + url + '" class="ai-overview__citation-link">' + title + '</a>';
              } else {
                html += '<span>' + title + '</span>';
              }
              if (type) {
                html += ' <span class="ai-overview__citation-type">' + type + '</span>';
              }
              html += '</li>';
            });
            html += '</ul>';
            html += '</div>';
          }

          html += '</div>';
          container.innerHTML = html;
        })
        .catch(function () {
          container.style.display = 'none';
        });
    }
  };
})(Drupal, drupalSettings);
