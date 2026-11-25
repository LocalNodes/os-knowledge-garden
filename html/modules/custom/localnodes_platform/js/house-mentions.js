/**
 * @file
 * Gives each house mention input its server-built page context.
 */
(function ($, Drupal, once) {
  'use strict';

  function applyContext(element) {
    let context;
    try {
      context = JSON.parse(element.getAttribute('data-house-mention-context'));
    }
    catch (error) {
      return;
    }
    const source = Drupal.url('mentions-autocomplete') + '?' + $.param(context);
    const input = $(element);
    const instance = input.data('mentionsInput');
    if (!instance) {
      return;
    }
    // Open Social uses both the textarea and CKEditor mention implementations.
    if (instance.settings) {
      instance.settings.source = source;
    }
    if (instance.options) {
      instance.options.source = source;
      const widget = instance.options.widget;
      if (widget && typeof input[widget] === 'function') {
        input[widget]('option', 'source', source);
      }
    }
  }

  Drupal.behaviors.houseMentionContext = {
    attach(context) {
      once('house-mention-context', 'textarea[data-house-mention-context]', context).forEach((element) => {
        applyContext(element);
        $(element).on('focusin.houseMentionContext', () => applyContext(element));
        if (window.CKEDITOR && typeof window.CKEDITOR.on === 'function') {
          window.CKEDITOR.on('instanceReady', () => applyContext(element));
        }
      });
    }
  };
})(jQuery, Drupal, once);
