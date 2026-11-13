/** Fresh proof for one existing export form POST. No sign-in or proof cache. */
(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.localnodesExportConfirmation = {
    attach(context) {
      once('localnodes-export-confirmation', 'form[data-ln-export-signature]', context).forEach((form) => {
        let busy = false;
        let posting = false;
        let active = null;
        const field = (name) => form.elements.namedItem(name);
        const status = form.querySelector('[data-ln-export-status]');
        const clear = () => { field('proof_message').value = ''; field('proof_signature').value = ''; };
        clear();
        form.querySelector('[data-ln-export-cancel]')?.addEventListener('click', (event) => {
          if (busy && active) { event.preventDefault(); active.abort(); }
        });
        form.addEventListener('submit', async (event) => {
          if (posting) { posting = false; return; }
          event.preventDefault();
          // One wallet confirmation at a time on the page (see signature-proof.js).
          if (busy || Drupal.localnodes?.signing || !form.reportValidity()) return;
          const button = event.submitter;
          busy = true;
          Drupal.localnodes = Drupal.localnodes || {};
          Drupal.localnodes.signing = 'export';
          const controller = new AbortController();
          active = controller;
          clear();
          if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); }
          status.textContent = Drupal.t('Confirm with your sign-in.');
          try {
            if (typeof Drupal.siwe?.signMessage !== 'function') throw new Error();
            const token = await fetch(Drupal.url('session/token'), { credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
            if (!token.ok) throw new Error();
            const response = await fetch(form.dataset.lnExportChallenge, {
              method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
              headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': await token.text() },
              body: JSON.stringify({ action: form.dataset.lnExportAction, exportId: form.dataset.lnExportId }),
            });
            if (!response.ok) throw new Error();
            const challenge = await response.json();
            const proof = await Drupal.siwe.signMessage({ message: challenge.message, expectedAddress: challenge.expectedAddress, signal: controller.signal });
            if (controller.signal.aborted) throw new Error();
            if (typeof proof.signature !== 'string' || typeof proof.address !== 'string' || proof.address.toLowerCase() !== challenge.expectedAddress.toLowerCase()) throw new Error();
            if (!form.reportValidity()) throw new Error();
            field('proof_message').value = challenge.message;
            field('proof_signature').value = proof.signature;
            if (button) button.disabled = false;
            posting = true;
            form.requestSubmit(button || undefined);
          }
          catch (_) {
            clear();
            status.textContent = Drupal.t('We could not confirm it is you. Try again or ask for export help.');
          }
          finally {
            busy = false;
            if (Drupal.localnodes.signing === 'export') Drupal.localnodes.signing = null;
            if (active === controller) active = null;
            if (button) { button.disabled = false; button.removeAttribute('aria-busy'); }
          }
        });
        // A restored browser page must ask again, even if its form was retained.
        form.addEventListener('reset', clear);
      });
    },
  };
})(Drupal, once);
