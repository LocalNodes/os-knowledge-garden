/**
 * @file
 * S3 "Import web pages" and the "Refresh from source" confirmation (22-24).
 *
 * - After a submit with errors, focus moves to the error summary (UI-SPEC
 *   Part 3 "Form validation").
 * - When the addresses change, "Check licences" runs through its #ajax, so
 *   the licence lines and the preview update in place. Nothing here moves
 *   focus while the person types.
 * - The confirmations (UI-SPEC Part 3 "Confirmation"; the takedown of 23-05
 *   works the same way): "Keep current
 *   version" has initial focus (its autofocus attribute, applied here too
 *   for browsers that ignore autofocus on links), and Escape cancels, which
 *   goes back to the report the person came from.
 * - "Report a problem" (23-05; 23-UI-SPEC section 10 "Sending", 23-29): once
 *   the form is on its way, "Send report" reads "Sending…", shows the spinner
 *   and is disabled. It is disabled after the submit event, so the form data
 *   (and the button) have already gone with the POST.
 */
(function (Drupal, once) {
  Drupal.behaviors.localnodesImportWeb = {
    attach: function (context) {
      once('localnodes-import-web-error-summary', '[data-ln-error-summary]', context).forEach(function (summary) {
        summary.focus();
      });
      // A change to the addresses runs "Check licences" through its #ajax
      // (the button, not the textarea, is disabled while it runs, so a
      // submit in the meantime still posts the addresses).
      // eventResponse() is what a press of the button runs: it submits the
      // form with the button as the trigger. execute() posted no form
      // values, so the lines never updated (22-30 WK-02).
      once('localnodes-import-web-check', 'textarea[data-localnodes-import-check]', context).forEach(function (textarea) {
        textarea.addEventListener('change', function () {
          const button = textarea.form && textarea.form.querySelector('[name="check_licences"]');
          const ajax = button && Drupal.ajax.instances.find(function (instance) {
            return instance && instance.element === button;
          });
          if (ajax && !ajax.ajaxing) {
            ajax.eventResponse(button, {
              preventDefault: function () {},
              stopPropagation: function () {},
            });
          }
        });
      });
      once('localnodes-import-report-sending', 'form.localnodes-import-report-problem', context).forEach(function (form) {
        const button = form.querySelector('[type="submit"]');
        if (!button) {
          return;
        }
        const label = button.tagName === 'BUTTON' ? button.textContent : button.value;
        const spinner = document.createElement('span');
        spinner.className = 'localnodes-import-spinner';
        spinner.setAttribute('aria-hidden', 'true');
        spinner.hidden = true;
        button.insertAdjacentElement('afterend', spinner);
        const setLabel = function (text) {
          if (button.tagName === 'BUTTON') {
            button.textContent = text;
          }
          else {
            button.value = text;
          }
        };
        form.addEventListener('submit', function (event) {
          if (event.defaultPrevented) {
            return;
          }
          setTimeout(function () {
            setLabel(Drupal.t('Sending…'));
            spinner.hidden = false;
            button.disabled = true;
          }, 0);
        });
        // Back to the page from the browser's history: the form can be sent again.
        window.addEventListener('pageshow', function (event) {
          if (event.persisted) {
            setLabel(label);
            spinner.hidden = true;
            button.disabled = false;
          }
        });
      });
      once('localnodes-import-refresh', 'form.localnodes-import-refresh, form.localnodes-import-takedown', context).forEach(function (form) {
        const cancel = form.querySelector('a[autofocus]');
        if (!cancel) {
          return;
        }
        if (!form.querySelector('[data-ln-error-summary]') && (document.activeElement === document.body || document.activeElement === null)) {
          cancel.focus();
        }
        document.addEventListener('keydown', function (event) {
          if (event.key === 'Escape' && !event.defaultPrevented) {
            window.location.assign(cancel.href);
          }
        });
      });
    },
  };
})(Drupal, once);
