/**
 * @file
 * "Confirm your sign-in": proves this session with the member's wallet, in
 * place, before a group fund or vote change.
 *
 * The server refuses a change from a session that is not proven (403
 * {"error":"signature_proof_required"}). This script asks first, so the
 * wallet is never asked to sign or send a payment or vote that the site
 * would then refuse:
 *
 * - Known fund and vote buttons, and post forms on pages that need the
 *   proof (data-ln-signature-proof), are held until the session is proven.
 *   The hold runs in the capture phase, before the button's own handlers,
 *   and covers Drupal's AJAX buttons, which act on mousedown.
 * - A refusal that still arrives (a fetch or jQuery request) opens the
 *   same panel.
 *
 * The status is read again whenever the tab comes back into view, since
 * the session may have changed in another tab.
 *
 * The panel asks for a message, has the wallet sign it, and sends it back.
 * The member then repeats their action: nothing is ever sent again on their
 * behalf. One confirmation runs at a time, and never while the site export
 * confirmation is waiting for the wallet.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  const ACTIONS = [
    '.safe-deploy-button',
    '.safe-transaction-sign',
    '.safe-transaction-execute',
    '[data-safe-governance-cast]',
    '[data-safe-governance-confirm]',
    '[data-safe-governance-publish]',
    '[data-safe-governance-approve]',
    '[data-safe-governance-approve-changes]',
    '[data-safe-governance-restart]',
    '[data-safe-governance-retry]',
  ].join(', ');
  const FORM = 'form[data-ln-signature-proof]';
  const SUBMITS = 'button, input[type="submit"], input[type="image"]';
  const REFUSAL = 'signature_proof_required';

  Drupal.localnodes = Drupal.localnodes || {};
  const shared = Drupal.localnodes;
  // {proven, canProve, addUrl}; null until the status answer arrives.
  let status = null;
  let statusRequest = null;
  let active = null;
  let panel = null;
  let trigger = null;

  const signedIn = () => Number(drupalSettings?.user?.uid || 0) > 0;
  const noStore = { credentials: 'same-origin', cache: 'no-store' };

  function loadStatus() {
    if (!statusRequest) {
      statusRequest = fetch(Drupal.url('user/signature-proof/status'), noStore)
        .then((response) => (response.ok ? response.json() : null))
        .then((body) => {
          if (body && typeof body.proven === 'boolean') {
            status = { proven: body.proven, canProve: body.canProve === true, addUrl: typeof body.addUrl === 'string' ? body.addUrl : null };
          }
          return status;
        })
        .catch(() => null)
        .finally(() => { statusRequest = null; });
    }
    return statusRequest;
  }

  /** The element whose action needs the proof, or null. */
  function heldTarget(event) {
    const target = event.target instanceof Element ? event.target : null;
    if (!target || target.closest('[data-ln-proof-panel]')) return null;
    if (event.type === 'submit') {
      return target.matches(FORM) && (target.method || '').toLowerCase() === 'post' ? target : null;
    }
    if (event.type === 'mousedown' && event.button !== 0) return null;
    if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') return null;
    const action = target.closest(ACTIONS);
    if (action) return action;
    const control = target.closest(SUBMITS);
    const form = control?.closest(FORM);
    if (control && form && (form.method || '').toLowerCase() === 'post' && (control.type === 'submit' || control.type === 'image')) {
      return control;
    }
    return null;
  }

  function hold(event) {
    if (!signedIn() || status?.proven === true) return;
    const target = heldTarget(event);
    if (!target) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    open(target);
  }

  function text(message) {
    const line = panel?.querySelector('[data-ln-proof-text]');
    if (line) line.textContent = message;
  }

  function buttons(showContinue, busy) {
    const go = panel?.querySelector('[data-ln-proof-continue]');
    if (!go) return;
    go.hidden = !showContinue;
    go.disabled = busy;
    if (busy) go.setAttribute('aria-busy', 'true');
    else go.removeAttribute('aria-busy');
  }

  function build() {
    const element = document.createElement('div');
    element.className = 'ln-proof';
    element.setAttribute('data-ln-proof-panel', '');
    element.setAttribute('role', 'group');
    element.setAttribute('aria-labelledby', 'ln-proof-title');
    element.tabIndex = -1;
    const title = document.createElement('h2');
    title.className = 'ln-proof__title';
    title.id = 'ln-proof-title';
    title.textContent = Drupal.t('Confirm your sign-in');
    const line = document.createElement('p');
    line.className = 'ln-proof__text';
    line.setAttribute('data-ln-proof-text', '');
    line.setAttribute('aria-live', 'polite');
    const add = document.createElement('a');
    add.className = 'ln-proof__add';
    add.setAttribute('data-ln-proof-add', '');
    add.hidden = true;
    add.textContent = Drupal.t('Add a wallet to this account');
    const row = document.createElement('div');
    row.className = 'ln-proof__actions';
    const go = document.createElement('button');
    go.type = 'button';
    go.className = 'button btn btn-primary';
    go.setAttribute('data-ln-proof-continue', '');
    go.textContent = Drupal.t('Continue');
    go.addEventListener('click', prove);
    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'button btn btn-default';
    cancel.setAttribute('data-ln-proof-cancel', '');
    cancel.textContent = Drupal.t('Cancel');
    cancel.addEventListener('click', close);
    row.append(go, cancel);
    element.append(title, line, add, row);
    element.addEventListener('keydown', (event) => { if (event.key === 'Escape') close(); });
    return element;
  }

  /** Where the panel goes: right after the form or button that was held. */
  function place(element, near) {
    const anchor = near?.isConnected ? (near.closest('form') || near) : null;
    if (anchor?.parentNode) anchor.parentNode.insertBefore(element, anchor.nextSibling);
    else (document.querySelector('main') || document.body).prepend(element);
  }

  function noWallet() {
    text(Drupal.t('This account has no wallet yet, so it cannot confirm this step.'));
    const link = panel.querySelector('[data-ln-proof-add]');
    if (status?.addUrl) {
      link.href = status.addUrl;
      link.hidden = false;
    }
    buttons(false, false);
  }

  function ready() {
    if (status && !status.proven && !status.canProve) {
      noWallet();
      return;
    }
    text(Drupal.t('Confirm it is you to continue. This costs nothing.'));
    panel.querySelector('[data-ln-proof-add]').hidden = true;
    panel.querySelector('[data-ln-proof-cancel]').textContent = Drupal.t('Cancel');
    buttons(true, false);
  }

  function open(near) {
    trigger = near || (document.activeElement instanceof HTMLElement ? document.activeElement : null);
    if (!panel) panel = build();
    if (!active) {
      place(panel, trigger);
      ready();
      if (!status) loadStatus().then(() => { if (panel?.isConnected && !active) (status?.proven ? done() : ready()); });
    }
    panel.focus();
  }

  function close() {
    if (active) active.abort();
    if (panel) panel.remove();
    if (trigger?.isConnected) trigger.focus();
    trigger = null;
  }

  function done() {
    status = { ...(status || {}), proven: true, canProve: true };
    text(Drupal.t('Sign-in confirmed. You can continue.'));
    buttons(false, false);
    const cancel = panel.querySelector('[data-ln-proof-cancel]');
    cancel.textContent = Drupal.t('Close');
    if (trigger?.isConnected) trigger.focus();
  }

  async function postJson(url, token, body, signal) {
    return fetch(url, {
      ...noStore,
      method: 'POST',
      signal,
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
      body: JSON.stringify(body),
    });
  }

  async function prove() {
    // One pending message per session: never beside another confirmation.
    if (active || shared.signing) return;
    const controller = new AbortController();
    active = controller;
    shared.signing = 'session';
    buttons(true, true);
    text(Drupal.t('Check your wallet to confirm.'));
    try {
      if (typeof Drupal.siwe?.signMessage !== 'function') {
        text(Drupal.t('Your wallet cannot be reached from this page. Sign in with your wallet, then try again.'));
        return;
      }
      const tokenResponse = await fetch(Drupal.url('session/token'), { ...noStore, signal: controller.signal });
      if (!tokenResponse.ok) throw new Error();
      const token = await tokenResponse.text();
      const challenge = await postJson(Drupal.url('user/signature-proof/challenge'), token, {}, controller.signal);
      if (challenge.status === 409) {
        status = { proven: false, canProve: false, addUrl: status?.addUrl ?? null };
        await loadStatus();
        noWallet();
        return;
      }
      if (challenge.status === 429) {
        text(Drupal.t('Too many tries. Wait an hour, then try again.'));
        return;
      }
      if (!challenge.ok) throw new Error();
      const issued = await challenge.json();
      if (issued.proven === true) {
        done();
        return;
      }
      if (typeof issued.message !== 'string' || typeof issued.expectedAddress !== 'string') throw new Error();
      const proof = await Drupal.siwe.signMessage({ message: issued.message, expectedAddress: issued.expectedAddress, signal: controller.signal });
      if (controller.signal.aborted) throw new Error();
      if (typeof proof?.signature !== 'string' || typeof proof.address !== 'string' || proof.address.toLowerCase() !== issued.expectedAddress.toLowerCase()) throw new Error();
      const answer = await postJson(Drupal.url('user/signature-proof'), token, { message: issued.message, signature: proof.signature }, controller.signal);
      if (answer.status === 429) {
        text(Drupal.t('Too many tries. Wait an hour, then try again.'));
        return;
      }
      const body = answer.ok ? await answer.json() : null;
      if (body?.proven !== true) throw new Error();
      done();
    }
    catch (_) {
      if (!controller.signal.aborted) text(Drupal.t('We could not confirm it is you. Try again.'));
    }
    finally {
      if (active === controller) active = null;
      if (shared.signing === 'session') shared.signing = null;
      if (panel?.isConnected && status?.proven !== true && status?.canProve !== false) buttons(true, false);
    }
  }

  /** A refusal that got past the hold: show the panel where the member is. */
  function refused() {
    if (status) status.proven = false;
    else status = { proven: false, canProve: true, addUrl: null };
    if (!active) open(null);
  }

  function watchRefusals() {
    if (shared.proofWatching) return;
    shared.proofWatching = true;
    const original = window.fetch;
    if (typeof original === 'function') {
      window.fetch = function (...args) {
        return original.apply(this, args).then((response) => {
          if (response.status === 403 && (response.headers.get('Content-Type') || '').includes('json')) {
            response.clone().json().then((body) => { if (body?.error === REFUSAL) refused(); }).catch(() => {});
          }
          return response;
        });
      };
    }
    if (window.jQuery) {
      window.jQuery(document).on('ajaxError', (event, xhr) => {
        if (xhr?.status === 403 && String(xhr.responseText || '').includes(REFUSAL)) refused();
      });
    }
  }

  /**
   * Coming back to this tab: the session may have changed elsewhere (signed
   * out, signed in again by email), so the status is read again. Until the
   * answer arrives, actions are held.
   */
  function recheck() {
    if (document.visibilityState !== 'visible' || active) return;
    status = null;
    loadStatus();
  }

  Drupal.behaviors.localnodesSignatureProof = {
    attach(context) {
      if (!signedIn() || !once('localnodes-signature-proof', 'html', context).length) return;
      ['click', 'mousedown', 'keydown', 'submit'].forEach((type) => document.addEventListener(type, hold, true));
      document.addEventListener('visibilitychange', recheck);
      watchRefusals();
      loadStatus();
    },
  };
})(Drupal, drupalSettings, once);
