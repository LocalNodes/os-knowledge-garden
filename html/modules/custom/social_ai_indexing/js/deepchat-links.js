/**
 * @file
 * Makes links inside DeepChat shadow DOM clickable.
 *
 * DeepChat renders HTML messages via innerHTML inside its shadow DOM.
 * The built-in linkTarget:"_blank" only applies to DeepChat's own
 * remarkable renderer (text property), not pre-converted HTML. This
 * adds a delegated click handler on the shadow root so citation links
 * in AI responses navigate correctly.
 */
(function (Drupal) {
  'use strict';

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
})(Drupal);
