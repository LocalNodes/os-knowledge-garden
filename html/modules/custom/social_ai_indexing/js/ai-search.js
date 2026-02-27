/**
 * @file
 * JavaScript behaviors for AI search page and DeepChat link handling.
 */
(function (Drupal) {
  'use strict';

  /**
   * Makes links inside DeepChat shadow DOM clickable.
   *
   * DeepChat renders HTML messages via innerHTML inside its shadow DOM.
   * The built-in linkTarget:"_blank" only applies to DeepChat's own
   * remarkable renderer (text property), not pre-converted HTML. This
   * adds a delegated click handler on the shadow root so citation links
   * in AI responses navigate correctly.
   */
  function enableDeepChatLinks(chats) {
    chats.forEach(function (chat) {
      if (!chat.shadowRoot || chat.dataset.aiLinksEnabled) {
        return;
      }
      chat.dataset.aiLinksEnabled = 'true';
      chat.shadowRoot.addEventListener('click', function (e) {
        var link = e.target.closest('a');
        if (!link || !link.href) {
          return;
        }
        // Don't interfere with DeepChat's internal UI buttons.
        if (link.closest('.deep-chat-temporary-message') ||
            link.classList.contains('deep-chat-button') ||
            link.classList.contains('deep-chat-suggestion-button')) {
          return;
        }
        e.preventDefault();
        window.open(link.href, '_blank');
      });
    });
  }

  // Listen for DeepChat initialization.
  document.addEventListener('DrupalDeepchatInitialized', function (event) {
    enableDeepChatLinks(event.detail.chats);
  });

  // Handle case where DeepChat already initialized before this script ran.
  if (Drupal.behaviors.deepChatToggle &&
      Drupal.behaviors.deepChatToggle.chats &&
      Drupal.behaviors.deepChatToggle.chats.length > 0) {
    enableDeepChatLinks(Drupal.behaviors.deepChatToggle.chats);
  }

  Drupal.behaviors.aiSearch = {
    attach: function (context) {
      var form = context.querySelector('.ai-search-form');
      if (!form || form.dataset.aiSearchAttached) {
        return;
      }
      form.dataset.aiSearchAttached = 'true';

      var resultsContainer = context.querySelector('.ai-search-results');
      var statusContainer = context.querySelector('.ai-search-status');

      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var input = form.querySelector('[name="q"]');
        var query = input.value.trim();

        if (query.length < 2) {
          statusContainer.style.display = 'block';
          statusContainer.innerHTML = '<p class="messages messages--warning">' + Drupal.t('Please enter at least 2 characters.') + '</p>';
          resultsContainer.innerHTML = '';
          return;
        }

        // Show loading state.
        statusContainer.style.display = 'block';
        statusContainer.innerHTML = '<p class="ai-search-loading">' + Drupal.t('Searching...') + '</p>';
        resultsContainer.innerHTML = '';

        fetch('/api/ai/search?q=' + encodeURIComponent(query), {
          credentials: 'same-origin'
        })
          .then(function (response) {
            return response.json();
          })
          .then(function (data) {
            statusContainer.style.display = 'none';

            if (data.error) {
              statusContainer.style.display = 'block';
              statusContainer.innerHTML = '<p class="messages messages--error">' + Drupal.checkPlain(data.message || data.error) + '</p>';
              return;
            }

            if (!data.results || data.results.length === 0) {
              resultsContainer.innerHTML = '<p class="ai-search-no-results">' + Drupal.t('No results found. Try rephrasing your question.') + '</p>';
              return;
            }

            // Show result count.
            statusContainer.style.display = 'block';
            statusContainer.innerHTML = '<p class="ai-search-count">' + Drupal.t('@count results found', {'@count': data.count}) + '</p>';

            // Render results.
            var html = '';
            data.results.forEach(function (result) {
              var title = Drupal.checkPlain(result.title || 'Untitled');
              var snippet = Drupal.checkPlain(result.snippet || '');
              var type = Drupal.checkPlain(result.type || '');
              var url = result.url || '#';

              html += '<div class="ai-search-result">';
              html += '<h3 class="ai-search-result__title"><a href="' + Drupal.checkPlain(url) + '">' + title + '</a></h3>';
              if (snippet) {
                html += '<p class="ai-search-result__snippet">' + snippet + '</p>';
              }
              if (type) {
                html += '<span class="ai-search-result__type badge">' + type + '</span>';
              }
              html += '</div>';
            });

            resultsContainer.innerHTML = html;
          })
          .catch(function () {
            statusContainer.style.display = 'block';
            statusContainer.innerHTML = '<p class="messages messages--error">' + Drupal.t('An error occurred while searching. Please try again.') + '</p>';
          });
      });
    }
  };
})(Drupal);
