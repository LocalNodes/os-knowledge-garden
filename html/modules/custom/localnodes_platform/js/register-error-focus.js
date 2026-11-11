/**
 * @file
 * Moves focus to the register form's error summary after a failed submit.
 *
 * 23-UI-SPEC section 9 accessibility row (23-26): "After a failed submit,
 * focus is on the error summary." Inline Form Errors prints the summary as
 * an error message with one link per field; this focuses the message whose
 * links point into the register form, so a screen reader starts there.
 * It does nothing when the form has no error.
 */
((Drupal, once) => {
  Drupal.behaviors.localnodesRegisterErrorFocus = {
    attach(context) {
      once('ln-register-error-focus', '#user-register-form', context).forEach((form) => {
        const summary = Array.from(document.querySelectorAll('.alert-danger')).find((alert) =>
          Array.from(alert.querySelectorAll('a[href^="#"]')).some((link) => {
            const id = link.getAttribute('href').slice(1);
            return id !== '' && form.querySelector(`[id="${CSS.escape(id)}"]`) !== null;
          }),
        );
        if (!summary) {
          return;
        }
        summary.setAttribute('tabindex', '-1');
        summary.focus();
      });
    },
  };
})(Drupal, once);
