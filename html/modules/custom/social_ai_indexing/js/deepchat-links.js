/**
 * @file
 * Makes links inside DeepChat shadow DOM clickable and linkifies inline
 * citation numbers (e.g., [1]) by matching them to the Sources section.
 *
 * DeepChat renders HTML messages via innerHTML inside its shadow DOM.
 * The built-in linkTarget:"_blank" only applies to DeepChat's own
 * remarkable renderer (text property), not pre-converted HTML. This
 * adds a delegated click handler on the shadow root so citation links
 * in AI responses navigate in the same tab, and a MutationObserver that
 * post-processes new messages to turn plain [N] text into linked citations.
 */
(function (Drupal) {
  'use strict';

  /**
   * Parse the Sources section from a message element to build a map of
   * citation number → {url, title}.
   */
  function parseSources(messageEl) {
    var sources = {};
    var links = messageEl.querySelectorAll('a');
    links.forEach(function (a) {
      // Walk backwards from the link to find a preceding [N] pattern.
      // Sources are formatted as: [N] <a href="url">Title</a>
      var prev = a.previousSibling;
      if (!prev) {
        return;
      }
      var text = (prev.nodeType === 3) ? prev.textContent : '';
      var match = text.match(/\[(\d+)\]\s*$/);
      if (match) {
        sources[parseInt(match[1], 10)] = {
          url: a.href,
          title: a.textContent
        };
      }
    });
    return sources;
  }

  /**
   * Replace plain [N] text nodes in the message body (outside the Sources
   * section) with linked citations that have title attributes.
   */
  function linkifyCitations(messageEl) {
    var sources = parseSources(messageEl);
    if (!Object.keys(sources).length) {
      return;
    }

    // Walk all text nodes in the message looking for [N] patterns.
    var walker = document.createTreeWalker(
      messageEl,
      NodeFilter.SHOW_TEXT,
      null,
      false
    );

    var nodesToProcess = [];
    var node;
    while ((node = walker.nextNode())) {
      if (/\[\d+\]/.test(node.textContent)) {
        // Skip text nodes inside anchor tags (already linked in Sources).
        if (node.parentNode && node.parentNode.tagName === 'A') {
          continue;
        }
        nodesToProcess.push(node);
      }
    }

    nodesToProcess.forEach(function (textNode) {
      var parts = textNode.textContent.split(/(\[\d+\])/g);
      if (parts.length <= 1) {
        return;
      }

      var frag = document.createDocumentFragment();
      parts.forEach(function (part) {
        var citMatch = part.match(/^\[(\d+)\]$/);
        if (citMatch) {
          var num = parseInt(citMatch[1], 10);
          var source = sources[num];
          if (source) {
            var a = document.createElement('a');
            a.href = source.url;
            a.title = source.title;
            a.textContent = '[' + num + ']';
            a.style.cssText = 'color:#4a90d9;font-weight:600;font-size:0.85em;text-decoration:none;cursor:pointer;';
            frag.appendChild(a);
            return;
          }
        }
        frag.appendChild(document.createTextNode(part));
      });

      textNode.parentNode.replaceChild(frag, textNode);
    });
  }

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

      // Observe new messages for target="_blank" removal and citation linkification.
      new MutationObserver(function (mutations) {
        mutations.forEach(function (m) {
          m.addedNodes.forEach(function (node) {
            if (node.nodeType !== 1) {
              return;
            }
            // Strip target="_blank" from links.
            var links = node.querySelectorAll ? node.querySelectorAll('a[target="_blank"]') : [];
            links.forEach(function (a) {
              a.removeAttribute('target');
            });
            if (node.tagName === 'A' && node.getAttribute('target') === '_blank') {
              node.removeAttribute('target');
            }

            // Linkify inline [N] citations in AI message bubbles.
            var bubble = node.closest
              ? (node.classList.contains('message-bubble') ? node : node.querySelector('.message-bubble'))
              : null;
            if (bubble && bubble.querySelector('a')) {
              linkifyCitations(bubble);
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
