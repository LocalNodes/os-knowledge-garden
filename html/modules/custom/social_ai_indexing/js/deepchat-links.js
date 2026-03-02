/**
 * @file
 * Makes links inside DeepChat shadow DOM clickable.
 *
 * DeepChat renders HTML messages via innerHTML inside its shadow DOM.
 * The built-in linkTarget:"_blank" only applies to DeepChat's own
 * remarkable renderer (text property), not pre-converted HTML. This
 * adds a delegated click handler on the shadow root so citation links
 * in AI responses navigate in the same tab.
 */
(function (Drupal) {
  'use strict';

  function enableDeepChatLinks(chats) {
    chats.forEach(function (chat) {
      if (!chat.shadowRoot || chat.dataset.aiLinksEnabled) {
        return;
      }
      chat.dataset.aiLinksEnabled = 'true';
      // Use capture phase so this fires before DeepChat's own click handler
      // which would otherwise open links in a new tab.
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
        e.stopImmediatePropagation();
        window.location.href = link.href;
      }, true);
      // Also strip target="_blank" that DeepChat may add to links after render.
      new MutationObserver(function (mutations) {
        mutations.forEach(function (m) {
          m.addedNodes.forEach(function (node) {
            if (node.nodeType !== 1) {
              return;
            }
            var links = node.querySelectorAll ? node.querySelectorAll('a[target="_blank"]') : [];
            links.forEach(function (a) {
              a.removeAttribute('target');
            });
            if (node.tagName === 'A' && node.getAttribute('target') === '_blank') {
              node.removeAttribute('target');
            }
          });
        });
      }).observe(chat.shadowRoot, { childList: true, subtree: true });
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
})(Drupal);
