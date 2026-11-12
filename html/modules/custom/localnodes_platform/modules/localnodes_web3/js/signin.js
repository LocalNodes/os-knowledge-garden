/**
 * @file
 * Hands a click on a [data-ln-signin] control to the top-bar sign-in.
 *
 * The top-bar block holds the site's one sign-in ([data-siwe-login-waap]),
 * whose script adds its "Sign in" button once the connector has loaded. That
 * script comes from elsewhere and may never arrive, so a control only opens
 * the sign-in while that button is really there:
 *
 * - Until then, the welcome block's link stays a plain link to the log-in
 *   page, and the wrapper above the password form stays hidden.
 * - Once the button is there, the controls are marked ready: the wrapper
 *   shows, the link takes button semantics (Enter and Space), and a click
 *   clicks the top-bar button.
 * - If the button, or the whole top-bar block, is gone when a ready control
 *   is clicked, the control drops back to its plain state and the visitor is
 *   taken to the password form. A control that goes plain while it has the
 *   focus hands the focus to the form's first field.
 *
 * It also places the sign-in's "Opening sign-in…" line, which the connector
 * adds to the top of <main>, just under the fixed top bar (see signin.css).
 */
((Drupal, once) => {
  const BUTTON = '.siwe-login-waap__button';
  const READY = 'data-ln-signin-ready';

  /**
   * Puts the connector's waiting line just under the fixed top bar.
   */
  function placeWaiting(main) {
    const line = main.querySelector(':scope > .siwe-login-waap__waiting');
    const bar = document.querySelector('.navbar-fixed-top');
    if (line && bar) {
      line.style.top = `${Math.round(bar.getBoundingClientRect().bottom)}px`;
    }
  }

  /**
   * The top-bar sign-in's button, if it is in the page now.
   *
   * Looked up in the live document each time: the whole mount may have been
   * taken out after load, and a detached button opens nothing.
   */
  function liveButton() {
    const button = document.querySelector(`[data-siwe-login-waap] ${BUTTON}`);
    return button && button.isConnected ? button : null;
  }

  /**
   * The first field of the password form a control sits above, if any.
   */
  function firstField(control) {
    return control.closest('form')?.querySelector('input:not([type="hidden"])') ?? null;
  }

  /**
   * Marks a control ready (it opens the sign-in) or plain.
   */
  function setReady(control, ready) {
    control.toggleAttribute(READY, ready);
    const wrap = control.closest('[data-ln-signin-wrap]');
    if (wrap) {
      // Hiding the wrapper would drop the focus to the page; keep it on the
      // form below instead.
      const hadFocus = !ready && wrap.contains(document.activeElement);
      wrap.hidden = !ready;
      if (hadFocus) {
        firstField(control)?.focus();
      }
    }
    if (control.tagName === 'A') {
      if (ready) {
        control.setAttribute('role', 'button');
      }
      else {
        control.removeAttribute('role');
      }
    }
  }

  /**
   * Takes the visitor to the password form, for when the sign-in is missing.
   */
  function toPasswordForm(control) {
    setReady(control, false);
    if (control.tagName === 'A') {
      window.location.assign(control.href);
      return;
    }
    // The wrapper sits on the log-in or sign-up page, above its form.
    const login = Drupal.url('user/login');
    if (window.location.pathname === login) {
      firstField(control)?.focus();
      return;
    }
    window.location.assign(login + window.location.search);
  }

  Drupal.behaviors.localnodesSignIn = {
    attach(context) {
      once('ln-signin-waiting', 'main, [role="main"]', context).forEach((main) => {
        new MutationObserver(() => placeWaiting(main)).observe(main, { childList: true });
      });
      const controls = once('ln-signin', '[data-ln-signin]', context);
      if (!controls.length) {
        return;
      }
      const sync = () => {
        const ready = !!liveButton();
        controls.forEach((control) => {
          if (control.hasAttribute(READY) !== ready) {
            setReady(control, ready);
          }
        });
      };
      sync();
      // Watches the whole body, so that the mount itself going counts too.
      new MutationObserver(sync).observe(document.body, { childList: true, subtree: true });
      controls.forEach((control) => {
        control.addEventListener('click', (event) => {
          if (!control.hasAttribute(READY)) {
            // A plain link goes to the log-in page; the hidden form button
            // has nothing to open.
            if (control.tagName !== 'A') {
              event.preventDefault();
              toPasswordForm(control);
            }
            return;
          }
          event.preventDefault();
          const button = liveButton();
          if (!button) {
            toPasswordForm(control);
            return;
          }
          // A disabled button means a sign-in is already open.
          if (!button.disabled) {
            button.click();
          }
        });
        if (control.tagName === 'A') {
          // A link acting as a button also answers to Space.
          control.addEventListener('keydown', (event) => {
            if (event.key === ' ' && control.hasAttribute(READY)) {
              event.preventDefault();
              control.click();
            }
          });
        }
      });
    },
  };
})(Drupal, once);
