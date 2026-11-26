/**
 * @file
 * Brings a failed submit's errors, or a save's status, to a screen reader.
 *
 * On the Group set-up and decision pages, once per page load:
 * - after a failed submit, focus moves to Inline Form Errors' error summary
 *   (the error alert whose links point into a form on the page) or, with no
 *   summary, to the first field marked aria-invalid;
 * - otherwise, after a save, the theme's success alert becomes role=status
 *   and takes focus, so its words are read.
 * It does nothing on a page with neither.
 */
((Drupal, once) => {
  Drupal.behaviors.localnodesFormAnnounce = {
    attach(context) {
      if (once('ln-form-announce', 'html', context).length === 0) {
        return;
      }
      const forms = Array.from(document.querySelectorAll('form'));
      const summary = Array.from(document.querySelectorAll('.alert-danger')).find((alert) =>
        Array.from(alert.querySelectorAll('a[href^="#"]')).some((link) => {
          const id = link.getAttribute('href').slice(1);
          return id !== '' && forms.some((form) => form.querySelector(`[id="${CSS.escape(id)}"]`) !== null);
        }),
      );
      if (summary) {
        summary.setAttribute('tabindex', '-1');
        summary.focus();
        return;
      }
      const invalid = forms.map((form) => form.querySelector('[aria-invalid="true"]')).find((field) => field);
      if (invalid) {
        invalid.focus();
        return;
      }
      const status = document.querySelector('.alert-success');
      if (status) {
        status.setAttribute('role', 'status');
        status.setAttribute('tabindex', '-1');
        status.focus();
      }
    },
  };
})(Drupal, once);
