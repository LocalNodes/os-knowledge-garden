// "Confirm your sign-in" (js/signature-proof.js) in a real browser.
//
// The script runs in headless Chrome on a stub page: Drupal, drupalSettings,
// once, fetch and the wallet signer are stubs, so nothing reaches a site or
// a network. Each case checks what the member sees and which requests went
// out, and that the held action never runs on its own.
//
// Run: node --test tests/js/signature-proof.test.mjs from this module.
// Needs playwright-core (PLAYWRIGHT_CORE=/path/to/playwright-core, or
// resolvable from here) and Chrome (CHROME_PATH, default
// /usr/bin/google-chrome). A missing one fails the run, never skips it.
import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const MODULE = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const SCRIPT = resolve(MODULE, 'js/signature-proof.js');
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_CORE || 'playwright-core');

const WALLET = '0x15d34AAf54267DB7D7c367839AAf71A00a2C6A65';
const OTHER = '0x9965507D1a55bcC2695C58ba16FB37d819B0A4dc';

const PAGE = `<!doctype html><html><body><main>
  <div class="actions"><button type="button" class="safe-transaction-execute">Send payment</button><button type="button" data-safe-governance-confirm>Confirm in your wallet</button></div>
  <form method="post" data-ln-signature-proof id="setup"><input name="x" value="1"><button type="submit" id="save">Save</button></form>
  <form method="post" id="plain"><button type="submit" id="plain-save">Search</button></form>
</main></body></html>`;

// Stubs, set before the script loads. `answers` maps a path to a list of
// {status, body}; each call takes the next one (the last one repeats).
function stubs({ uid = 5, answers = {}, signer = 'ok' }) {
  window.calls = [];
  window.actions = [];
  window.Drupal = { t: (s) => s, url: (p) => '/' + p, behaviors: {} };
  window.drupalSettings = { user: { uid } };
  window.once = (id, selector, context) => {
    const root = context && context.querySelectorAll ? context : document;
    // Like core/once: ids are kept in a space-separated data-once attribute.
    const found = Array.from(root.querySelectorAll(selector));
    if (selector === 'html' && !found.includes(document.documentElement)) found.unshift(document.documentElement);
    return found.filter((el) => {
      const ids = (el.getAttribute('data-once') || '').split(' ').filter(Boolean);
      if (ids.includes(id)) return false;
      el.setAttribute('data-once', [...ids, id].join(' '));
      return true;
    });
  };
  const queues = JSON.parse(JSON.stringify(answers));
  window.fetch = async (url, init = {}) => {
    const path = String(url);
    window.calls.push({ path, method: init.method || 'GET', csrf: init.headers?.['X-CSRF-Token'] || null, body: init.body || null });
    const queue = queues[path];
    if (!queue) return new Response('{}', { status: 404, headers: { 'Content-Type': 'application/json' } });
    const answer = queue.length > 1 ? queue.shift() : queue[0];
    const text = typeof answer.body === 'string' ? answer.body : JSON.stringify(answer.body);
    return new Response(text, { status: answer.status, headers: { 'Content-Type': typeof answer.body === 'string' ? 'text/plain' : 'application/json' } });
  };
  if (signer) {
    window.Drupal.siwe = {
      signMessage: ({ message, expectedAddress, signal }) => new Promise((res, rej) => {
        window.signRequests = (window.signRequests || 0) + 1;
        window.lastSignal = signal;
        if (signer === 'hang') { signal.addEventListener('abort', () => rej(new Error('aborted'))); return; }
        res({ address: signer === 'other' ? '0x9965507D1a55bcC2695C58ba16FB37d819B0A4dc' : expectedAddress, signature: '0x' + 'ab'.repeat(65), message });
      }),
    };
  }
  document.querySelector('.safe-transaction-execute').addEventListener('click', () => window.actions.push('send'));
  document.querySelector('[data-safe-governance-confirm]').addEventListener('click', () => window.actions.push('propose-sign'));
  document.querySelector('#save').addEventListener('mousedown', () => window.actions.push('ajax-mousedown'));
  document.querySelector('#setup').addEventListener('submit', (e) => { e.preventDefault(); window.actions.push('setup-submit'); });
  document.querySelector('#plain').addEventListener('submit', (e) => { e.preventDefault(); window.actions.push('plain-submit'); });
}

const STATUS = '/user/signature-proof/status';
const TOKEN = '/session/token';
const CHALLENGE = '/user/signature-proof/challenge';
const PROVE = '/user/signature-proof';
const notProven = { status: 200, body: { proven: false, canProve: true, addUrl: null } };
const issued = { status: 200, body: { message: 'site.example wants you to sign in', expectedAddress: WALLET } };

let browser;
before(async () => {
  browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome', headless: true });
});
after(async () => { await browser?.close(); });

async function page(options) {
  const p = await browser.newPage();
  p.setDefaultTimeout(5000);
  await p.setContent(PAGE);
  await p.evaluate(stubs, options);
  await p.addScriptTag({ path: SCRIPT });
  await p.evaluate(() => window.Drupal.behaviors.localnodesSignatureProof.attach(document));
  await p.waitForFunction(() => window.calls.some((c) => c.path === '/user/signature-proof/status') || window.drupalSettings.user.uid === 0);
  await p.waitForTimeout(20);
  return p;
}

const panelText = (p) => p.evaluate(() => document.querySelector('[data-ln-proof-text]')?.textContent ?? null);
const calls = (p) => p.evaluate(() => window.calls.map((c) => c.method + ' ' + c.path));
const actions = (p) => p.evaluate(() => window.actions);

describe('Confirm your sign-in', () => {
  it('holds a payment button and offers the step in place', async () => {
    const p = await page({ answers: { [STATUS]: [notProven] } });
    await p.click('.safe-transaction-execute');
    assert.deepEqual(await actions(p), []);
    assert.equal(await p.textContent('#ln-proof-title'), 'Confirm your sign-in');
    assert.equal(await panelText(p), 'Confirm it is you to continue. This costs nothing.');
    assert.equal(await p.evaluate(() => document.querySelector('[data-ln-proof-panel]').previousElementSibling.className), 'safe-transaction-execute', 'The panel sits right after the button.');
    assert.equal(await p.evaluate(() => document.activeElement.hasAttribute('data-ln-proof-panel')), true, 'The panel takes focus.');
    assert.deepEqual(await calls(p), ['GET ' + STATUS], 'Nothing is asked of the server until Continue.');
    await p.close();
  });

  it('holds AJAX buttons on mousedown and submits of marked forms; plain forms pass', async () => {
    const p = await page({ answers: { [STATUS]: [notProven] } });
    await p.dispatchEvent('#save', 'mousedown', { button: 0 });
    await p.evaluate(() => document.querySelector('#setup').requestSubmit());
    assert.deepEqual(await actions(p), []);
    await p.evaluate(() => document.querySelector('#plain').requestSubmit());
    assert.deepEqual(await actions(p), ['plain-submit']);
    await p.close();
  });

  it('proves the session, then the member repeats the action; nothing is replayed', async () => {
    const p = await page({ answers: { [STATUS]: [notProven], [TOKEN]: [{ status: 200, body: 'tok-1' }], [CHALLENGE]: [issued], [PROVE]: [{ status: 200, body: { proven: true } }] } });
    await p.click('.safe-transaction-execute');
    await p.click('[data-ln-proof-continue]');
    await p.waitForFunction(() => document.querySelector('[data-ln-proof-text]').textContent === 'Sign-in confirmed. You can continue.');
    assert.deepEqual(await calls(p), ['GET ' + STATUS, 'GET ' + TOKEN, 'POST ' + CHALLENGE, 'POST ' + PROVE]);
    const sent = await p.evaluate(() => window.calls.filter((c) => c.method === 'POST'));
    assert.ok(sent.every((c) => c.csrf === 'tok-1'), 'Both posts carry the CSRF header.');
    assert.deepEqual(JSON.parse(sent[1].body), { message: 'site.example wants you to sign in', signature: '0x' + 'ab'.repeat(65) });
    assert.deepEqual(await actions(p), [], 'The payment is not sent on the member\'s behalf.');
    assert.equal(await p.isHidden('[data-ln-proof-continue]'), true);
    await p.click('.safe-transaction-execute');
    assert.deepEqual(await actions(p), ['send'], 'The member\'s own second press goes through.');
    await p.close();
  });

  it('holds the proposal signing button', async () => {
    const p = await page({ answers: { [STATUS]: [notProven] } });
    await p.click('[data-safe-governance-confirm]');
    assert.deepEqual(await actions(p), []);
    assert.equal(await p.isVisible('[data-ln-proof-panel]'), true);
    await p.close();
  });

  it('reads the status again when the tab comes back, and holds if the session changed', async () => {
    const proven = { status: 200, body: { proven: true, canProve: true, addUrl: null } };
    const p = await page({ answers: { [STATUS]: [proven, notProven] } });
    await p.click('.safe-transaction-execute');
    assert.deepEqual(await actions(p), ['send']);
    await p.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    await p.waitForFunction(() => window.calls.filter((c) => c.path === '/user/signature-proof/status').length === 2);
    await p.waitForTimeout(20);
    await p.click('.safe-transaction-execute');
    assert.deepEqual(await actions(p), ['send'], 'The second press waits for a new confirmation.');
    assert.equal(await panelText(p), 'Confirm it is you to continue. This costs nothing.');
    await p.close();
  });

  it('a hold while the status is still loading ends as confirmed when it was proven', async () => {
    const proven = { status: 200, body: { proven: true, canProve: true, addUrl: null } };
    const p = await page({ answers: { [STATUS]: [proven] } });
    await p.evaluate(() => {
      const original = window.fetch;
      window.fetch = (url, init) => new Promise((res) => setTimeout(() => res(original(url, init)), 150));
      document.dispatchEvent(new Event('visibilitychange'));
    });
    await p.click('.safe-transaction-execute');
    assert.deepEqual(await actions(p), [], 'Held while the answer is pending.');
    await p.waitForFunction(() => document.querySelector('[data-ln-proof-text]')?.textContent === 'Sign-in confirmed. You can continue.');
    await p.click('.safe-transaction-execute');
    assert.deepEqual(await actions(p), ['send']);
    await p.close();
  });

  it('lets everything through when the session is already proven', async () => {
    const p = await page({ answers: { [STATUS]: [{ status: 200, body: { proven: true, canProve: true, addUrl: null } }] } });
    await p.click('.safe-transaction-execute');
    await p.dispatchEvent('#save', 'mousedown', { button: 0 });
    assert.deepEqual(await actions(p), ['send', 'ajax-mousedown']);
    assert.equal(await p.$('[data-ln-proof-panel]'), null);
    await p.close();
  });

  it('holds nothing for a signed-out visitor', async () => {
    const p = await page({ uid: 0, answers: {} });
    await p.click('.safe-transaction-execute');
    assert.deepEqual(await actions(p), ['send']);
    assert.deepEqual(await calls(p), []);
    await p.close();
  });

  it('without a wallet: one plain sentence and the add link when the page exists', async () => {
    const p = await page({ answers: { [STATUS]: [{ status: 200, body: { proven: false, canProve: false, addUrl: '/user/add-wallet' } }] } });
    await p.click('.safe-transaction-execute');
    assert.equal(await panelText(p), 'This account has no wallet yet, so it cannot confirm this step.');
    assert.equal(await p.getAttribute('[data-ln-proof-add]', 'href'), '/user/add-wallet');
    assert.equal(await p.isVisible('[data-ln-proof-add]'), true);
    assert.equal(await p.isHidden('[data-ln-proof-continue]'), true);
    await p.close();

    const q = await page({ answers: { [STATUS]: [{ status: 200, body: { proven: false, canProve: false, addUrl: null } }] } });
    await q.click('.safe-transaction-execute');
    assert.equal(await panelText(q), 'This account has no wallet yet, so it cannot confirm this step.');
    assert.equal(await q.isHidden('[data-ln-proof-add]'), true, 'No link while the add page is absent.');
    await q.close();
  });

  it('a challenge answering wallet_not_attached shows the same sentence', async () => {
    const p = await page({ answers: { [STATUS]: [notProven, { status: 200, body: { proven: false, canProve: false, addUrl: null } }], [TOKEN]: [{ status: 200, body: 't' }], [CHALLENGE]: [{ status: 409, body: { error: 'wallet_not_attached' } }] } });
    await p.click('.safe-transaction-execute');
    await p.click('[data-ln-proof-continue]');
    await p.waitForFunction(() => document.querySelector('[data-ln-proof-text]').textContent.startsWith('This account has no wallet'));
    assert.equal(await p.isHidden('[data-ln-proof-continue]'), true);
    await p.close();
  });

  it('without the signer it says so plainly and asks the server for nothing', async () => {
    const p = await page({ signer: null, answers: { [STATUS]: [notProven] } });
    await p.click('.safe-transaction-execute');
    await p.click('[data-ln-proof-continue]');
    await p.waitForFunction(() => document.querySelector('[data-ln-proof-text]').textContent.startsWith('Your wallet cannot be reached'));
    assert.deepEqual(await calls(p), ['GET ' + STATUS]);
    await p.close();
  });

  it('a signature from another wallet is not sent', async () => {
    const p = await page({ signer: 'other', answers: { [STATUS]: [notProven], [TOKEN]: [{ status: 200, body: 't' }], [CHALLENGE]: [issued] } });
    await p.click('.safe-transaction-execute');
    await p.click('[data-ln-proof-continue]');
    await p.waitForFunction(() => document.querySelector('[data-ln-proof-text]').textContent === 'We could not confirm it is you. Try again.');
    assert.ok(!(await calls(p)).includes('POST ' + PROVE));
    assert.equal(await p.isEnabled('[data-ln-proof-continue]'), true, 'The member can try again.');
    await p.close();
  });

  it('too many tries reads plainly', async () => {
    const p = await page({ answers: { [STATUS]: [notProven], [TOKEN]: [{ status: 200, body: 't' }], [CHALLENGE]: [{ status: 429, body: { error: 'too_many_attempts' } }] } });
    await p.click('.safe-transaction-execute');
    await p.click('[data-ln-proof-continue]');
    await p.waitForFunction(() => document.querySelector('[data-ln-proof-text]').textContent === 'Too many tries. Wait an hour, then try again.');
    await p.close();
  });

  it('Cancel while the wallet is open stops it and sends nothing more', async () => {
    const p = await page({ signer: 'hang', answers: { [STATUS]: [notProven], [TOKEN]: [{ status: 200, body: 't' }], [CHALLENGE]: [issued] } });
    await p.click('.safe-transaction-execute');
    await p.click('[data-ln-proof-continue]');
    await p.waitForFunction(() => window.signRequests === 1);
    await p.click('[data-ln-proof-cancel]');
    assert.equal(await p.evaluate(() => window.lastSignal.aborted), true);
    assert.equal(await p.$('[data-ln-proof-panel]'), null);
    assert.equal(await p.evaluate(() => document.activeElement.className), 'safe-transaction-execute', 'Focus goes back to the button.');
    await p.waitForTimeout(30);
    assert.ok(!(await calls(p)).includes('POST ' + PROVE));
    assert.equal(await p.evaluate(() => window.Drupal.localnodes.signing ?? null), null, 'The page is free for another confirmation.');
    await p.close();
  });

  it('one confirmation at a time: not while an export confirmation waits', async () => {
    const p = await page({ answers: { [STATUS]: [notProven], [TOKEN]: [{ status: 200, body: 't' }], [CHALLENGE]: [issued] } });
    await p.evaluate(() => { window.Drupal.localnodes.signing = 'export'; });
    await p.click('.safe-transaction-execute');
    await p.click('[data-ln-proof-continue]');
    await p.waitForTimeout(30);
    assert.deepEqual(await calls(p), ['GET ' + STATUS]);
    await p.close();
  });

  it('a refusal that gets past the hold opens the panel', async () => {
    const p = await page({ answers: { [STATUS]: [{ status: 200, body: { proven: true, canProve: true, addUrl: null } }], '/api/vote': [{ status: 403, body: { error: 'signature_proof_required' } }] } });
    const status = await p.evaluate(async () => (await fetch('/api/vote', { method: 'POST' })).status);
    assert.equal(status, 403, 'The page still gets its answer.');
    await p.waitForSelector('[data-ln-proof-panel]');
    assert.equal(await panelText(p), 'Confirm it is you to continue. This costs nothing.');
    await p.click('[data-ln-proof-cancel]');
    await p.click('.safe-transaction-execute');
    assert.deepEqual(await actions(p), [], 'The page now knows the session is not proven.');
    await p.close();
  });
});
