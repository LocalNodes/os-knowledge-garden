/**
 * @file
 * Accessible citation links in the DeepChat answer (UI-SPEC S7, KNOW-04).
 *
 * The server rewrites every group assistant answer (CitedAnswer::toMarkdown)
 * to: answer paragraphs with [1], [2] markers, then a "**Sources**" line and
 * one "- [n] [Page title](/root/relative/url)" item per source. DeepChatApi
 * turns that into <p><strong>Sources</strong></p><ul><li>[n] <a>…</a></li>.
 *
 * This script, inside DeepChat's shadow root:
 * - finds the Sources label and the list after it, and rebuilds the list as
 *   an ordered list of "[n] ‹page title›" items with the title as link text;
 * - turns each inline [n] into a link to that source whose visible text stays
 *   [n] and whose accessible name is "Source ‹n›: ‹page title›";
 * - keeps links in the same tab;
 * - names the send button and the scrolling message list, and puts the typed
 *   question back in the input when the service fails, so the person can
 *   send it again;
 * - announces each new answer once through Drupal.announce() (22-30).
 *
 * Links are built only from the server-rendered Sources anchors, and only for
 * root-relative addresses. Every node is made with createElement and
 * textContent (T-22-60). Answers without a Sources list (no-answer and
 * fallback copy) are left as they are. Colours and sizes live in the style
 * file (deepchat_styles/localnodes.yml); this script only adds ln-* classes.
 */
(function (Drupal) {
  'use strict';

  var SOURCES_LABEL = 'Sources';

  /**
   * Whether an href is root-relative ("/node/5", "/node/5#comment-3").
   */
  function isRootRelative(href) {
    return typeof href === 'string' && /^\/(?!\/)/.test(href);
  }

  /**
   * Finds the Sources label paragraph and the list that follows it.
   *
   * @return {?{label: Element, list: Element}}
   *   The two elements, or null when the answer has no Sources list.
   */
  function findSourcesBlock(bubble) {
    var paragraphs = bubble.querySelectorAll('p');
    for (var i = 0; i < paragraphs.length; i++) {
      var p = paragraphs[i];
      if (p.textContent.trim() !== SOURCES_LABEL) {
        continue;
      }
      var list = p.nextElementSibling;
      if (list && (list.tagName === 'UL' || list.tagName === 'OL')) {
        return {label: p, list: list};
      }
    }
    return null;
  }

  /**
   * Reads "[n] <a href>Title</a>" list items into a number -> source map.
   *
   * @return {Object<number, {href: string, title: string}>}
   *   Sources by display number.
   */
  function readSources(list) {
    var sources = {};
    Array.prototype.forEach.call(list.children, function (li) {
      if (li.tagName !== 'LI') {
        return;
      }
      var link = li.querySelector('a[href]');
      if (!link) {
        return;
      }
      var match = li.textContent.match(/^\s*\[(\d+)\]/);
      var href = link.getAttribute('href');
      if (!match || !isRootRelative(href)) {
        return;
      }
      var title = link.textContent.trim();
      if (title === '') {
        return;
      }
      sources[parseInt(match[1], 10)] = {href: href, title: title};
    });
    return sources;
  }

  /**
   * Builds the ordered Sources list from the map.
   */
  function buildSourcesList(sources) {
    var ol = document.createElement('ol');
    ol.className = 'ln-sources';
    Object.keys(sources).map(Number).sort(function (a, b) {
      return a - b;
    }).forEach(function (n) {
      var li = document.createElement('li');
      li.appendChild(document.createTextNode('[' + n + '] '));
      var a = document.createElement('a');
      a.className = 'ln-source';
      a.setAttribute('href', sources[n].href);
      a.textContent = sources[n].title;
      li.appendChild(a);
      ol.appendChild(li);
    });
    return ol;
  }

  /**
   * Makes an inline marker link: visible "[n]", name "Source n: title".
   */
  function buildMarker(n, source) {
    var a = document.createElement('a');
    a.className = 'ln-cite';
    a.setAttribute('href', source.href);
    a.setAttribute('aria-label', 'Source ' + n + ': ' + source.title);
    a.textContent = '[' + n + ']';
    return a;
  }

  /**
   * Replaces plain [n] text in the answer body with marker links.
   */
  function linkifyMarkers(bubble, block, sources) {
    var walker = document.createTreeWalker(bubble, NodeFilter.SHOW_TEXT, {
      acceptNode: function (node) {
        if (!/\[\d+\]/.test(node.textContent)) {
          return NodeFilter.FILTER_SKIP;
        }
        // Not the Sources block, and never text already inside a link.
        if (block.label.contains(node) || block.list.contains(node)) {
          return NodeFilter.FILTER_REJECT;
        }
        if (node.parentNode && node.parentNode.closest && node.parentNode.closest('a')) {
          return NodeFilter.FILTER_REJECT;
        }
        return NodeFilter.FILTER_ACCEPT;
      }
    });

    var textNodes = [];
    var node;
    while ((node = walker.nextNode())) {
      textNodes.push(node);
    }

    textNodes.forEach(function (textNode) {
      var parts = textNode.textContent.split(/(\[\d+\])/g);
      if (parts.length <= 1) {
        return;
      }
      var frag = document.createDocumentFragment();
      parts.forEach(function (part) {
        var marker = part.match(/^\[(\d+)\]$/);
        if (marker) {
          var n = parseInt(marker[1], 10);
          if (sources[n]) {
            frag.appendChild(buildMarker(n, sources[n]));
            return;
          }
        }
        if (part !== '') {
          frag.appendChild(document.createTextNode(part));
        }
      });
      textNode.parentNode.replaceChild(frag, textNode);
    });
  }

  /**
   * Post-processes one message bubble once.
   */
  function processBubble(bubble) {
    if (!bubble || bubble.getAttribute('data-ln-cited')) {
      return;
    }
    var block = findSourcesBlock(bubble);
    if (!block) {
      return;
    }
    var sources = readSources(block.list);
    if (!Object.keys(sources).length) {
      return;
    }
    bubble.setAttribute('data-ln-cited', '1');
    block.label.classList.add('ln-sources-label');
    var ol = buildSourcesList(sources);
    block.list.parentNode.replaceChild(ol, block.list);
    block.list = ol;
    linkifyMarkers(bubble, block, sources);
  }

  /**
   * Processes every message bubble in or under a node.
   */
  function processNode(node) {
    if (node.nodeType !== 1) {
      return;
    }
    if (node.classList && node.classList.contains('message-bubble')) {
      processBubble(node);
    }
    if (node.querySelectorAll) {
      node.querySelectorAll('.message-bubble').forEach(processBubble);
    }
  }

  /**
   * Names DeepChat's send button (axe aria-command-name).
   */
  function nameInputButtons(root) {
    root.querySelectorAll('.input-button').forEach(function (button) {
      if (!button.getAttribute('aria-label')) {
        button.setAttribute('aria-label', Drupal.t('Send question'));
      }
    });
  }

  /**
   * Makes the scrolling message list keyboard reachable and named (axe
   * scrollable-region-focusable): a long answer scrolls inside it.
   */
  function labelMessages(root) {
    var messages = root.getElementById('messages');
    if (messages && !messages.hasAttribute('tabindex')) {
      messages.setAttribute('tabindex', '0');
      messages.setAttribute('role', 'region');
      messages.setAttribute('aria-label', Drupal.t('Chat messages'));
    }
  }

  /**
   * After a service error, puts the last question back in an empty input.
   */
  function restoreQuestion(chat) {
    if (!chat.shadowRoot || typeof chat.getMessages !== 'function') {
      return;
    }
    var input = chat.shadowRoot.getElementById('text-input');
    if (!input || input.textContent !== '') {
      return;
    }
    var messages = chat.getMessages() || [];
    for (var i = messages.length - 1; i >= 0; i--) {
      if (messages[i].role === 'user' && typeof messages[i].text === 'string' && messages[i].text !== '') {
        input.textContent = messages[i].text;
        // Let DeepChat drop its placeholder and enable the send button.
        input.dispatchEvent(new Event('input', {bubbles: true}));
        return;
      }
    }
  }

  /**
   * The plain text of an answer's HTML, one space between blocks.
   *
   * DOMParser builds an inert document: nothing in it runs or loads.
   */
  function answerText(html) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    doc.body.querySelectorAll('p, li, ul, ol, br, h1, h2, h3, h4').forEach(function (block) {
      block.appendChild(doc.createTextNode(' '));
    });
    return doc.body.textContent.replace(/\s+/g, ' ').trim();
  }

  /**
   * Announces each new answer once (22-30 SR-01).
   *
   * DeepChat adds answers to its message list without telling a screen
   * reader, and the list isn't a live region. UI-SPEC Part 5 sends changes
   * that happen without a page load through the page's one polite live
   * region, which is Drupal.announce(). The text is the answer as shown,
   * the Sources list included, or the no-answer copy. Earlier messages
   * (isHistory) and the person's own question aren't announced.
   */
  function announceAnswers(chat) {
    chat.addEventListener('message', function (event) {
      var detail = event.detail || {};
      var message = detail.message || {};
      if (detail.isHistory || message.role !== 'ai') {
        return;
      }
      var text = typeof message.html === 'string' && message.html !== '' ? answerText(message.html) : (message.text || '');
      if (text && Drupal.announce) {
        Drupal.announce(text, 'polite');
      }
      showAnswerStart(chat);
    });
  }

  /**
   * Scrolls the message list to the start of the newest answer (22-30,
   * Codex CX-35).
   *
   * DeepChat scrolls to the bottom when a message arrives, so a long answer
   * opened at its end. After DeepChat has scrolled, the list is moved back
   * so the answer's first line is at the top of the list.
   *
   * DeepChat can scroll down again a little later (60ms after content
   * loads), so the check runs a few times over the first second. It stops
   * as soon as the member scrolls, touches or types in the list.
   */
  function showAnswerStart(chat) {
    var root = chat.shadowRoot;
    var list = root && root.getElementById('messages');
    if (!list) {
      return;
    }
    var timers = [];
    var events = ['wheel', 'touchstart', 'pointerdown', 'keydown'];
    var stop = function () {
      timers.forEach(window.clearTimeout);
      events.forEach(function (name) {
        list.removeEventListener(name, stop);
      });
    };
    events.forEach(function (name) {
      list.addEventListener(name, stop, { passive: true });
    });
    [50, 200, 600, 1000].forEach(function (delay, i, delays) {
      timers.push(window.setTimeout(function () {
        var answers = list.querySelectorAll('.ai-message-text');
        var last = answers[answers.length - 1];
        if (last) {
          var offset = last.getBoundingClientRect().top - list.getBoundingClientRect().top;
          if (offset < 0) {
            list.scrollTop += offset - 8;
          }
        }
        if (i === delays.length - 1) {
          stop();
        }
      }, delay));
    });
  }

  function enableDeepChatLinks(chats) {
    chats.forEach(function (chat) {
      if (!chat || !chat.shadowRoot || chat.dataset.aiLinksEnabled) {
        return;
      }
      chat.dataset.aiLinksEnabled = 'true';
      var root = chat.shadowRoot;

      // Capture phase, so this runs before DeepChat's own click handler,
      // which would open links in a new tab.
      root.addEventListener('click', function (e) {
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

      chat.addEventListener('error', function () {
        restoreQuestion(chat);
      });
      announceAnswers(chat);

      nameInputButtons(root);
      labelMessages(root);
      root.querySelectorAll('.message-bubble').forEach(processBubble);

      new MutationObserver(function (mutations) {
        mutations.forEach(function (m) {
          m.addedNodes.forEach(function (node) {
            if (node.nodeType !== 1) {
              return;
            }
            // Same tab: strip target="_blank" from links.
            if (node.tagName === 'A' && node.getAttribute('target') === '_blank') {
              node.removeAttribute('target');
            }
            if (node.querySelectorAll) {
              node.querySelectorAll('a[target="_blank"]').forEach(function (a) {
                a.removeAttribute('target');
              });
            }
            processNode(node);
            // Content set into a bubble that was already in the DOM.
            var host = node.closest ? node.closest('.message-bubble') : null;
            if (host) {
              processBubble(host);
            }
          });
        });
        nameInputButtons(root);
        labelMessages(root);
      }).observe(root, {childList: true, subtree: true});
    });
  }

  /**
   * Keeps the collapsed chat panel out of the Tab order and the
   * accessibility tree (22-30 KB-02), and closes it with Escape (KB-07).
   *
   * ai_chatbot's sticky-chatbot.js collapses the panel by moving it below
   * the window, so its message list, question box and send button stayed
   * focusable while nobody could see them. The panel is inert whenever the
   * "AI assistant" header says it's collapsed (aria-expanded="false").
   */
  Drupal.behaviors.localnodesChatInert = {
    attach: function (context) {
      var scope = context.querySelectorAll ? context : document;
      scope.querySelectorAll('.ai-deepchat.chat-container').forEach(function (container) {
        if (container.dataset.lnInert) {
          return;
        }
        var header = container.querySelector('.ai-deepchat--header');
        var panel = container.querySelector('.chat-element');
        if (!header || !panel) {
          return;
        }
        container.dataset.lnInert = 'true';
        var sync = function () {
          panel.inert = header.getAttribute('aria-expanded') !== 'true';
        };
        sync();
        new MutationObserver(sync).observe(header, {attributes: true, attributeFilter: ['aria-expanded']});

        // Escape closes the open panel (22-30 KB-07). From inside the panel,
        // focus goes back to the "AI assistant" header that opened it; from
        // anywhere else on the page, focus stays where it is, so a control
        // the panel covers can always be uncovered (SC 2.4.11).
        document.addEventListener('keydown', function (event) {
          if (event.key !== 'Escape' || event.defaultPrevented || header.getAttribute('aria-expanded') !== 'true') {
            return;
          }
          var inside = container.contains(document.activeElement);
          header.click();
          if (inside) {
            header.focus();
          }
        });
      });
    }
  };

  // Listen for DeepChat initialization.
  document.addEventListener('DrupalDeepchatInitialized', function (event) {
    enableDeepChatLinks(event.detail.chats);
  });

  // Handle the case where DeepChat initialized before this script ran.
  if (Drupal.behaviors.deepChatToggle &&
      Drupal.behaviors.deepChatToggle.chats &&
      Drupal.behaviors.deepChatToggle.chats.length > 0) {
    enableDeepChatLinks(Drupal.behaviors.deepChatToggle.chats);
  }
})(Drupal);
