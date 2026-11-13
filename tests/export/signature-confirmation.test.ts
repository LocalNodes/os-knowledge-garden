import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
const path = 'html/modules/custom/localnodes_platform/modules/localnodes_web3/js/export-confirmation.js';
function setup({ signer = true, reject = false, valid = true, denied = false, wrongAccount = false, tokenDenied = false, pending = false } = {}) {
  const fields: any = { proof_message: { value: '' }, proof_signature: { value: '' } };
  const status: any = { textContent: '' };
  const button: any = { disabled: false, setAttribute() {}, removeAttribute() {} };
  let handler: any; let cancelHandler: any; let posted = 0; const calls: any[] = []; const logs: any[] = [];
  const form: any = { dataset: { lnExportAction: 'download', lnExportId: 'aaa', lnExportChallenge: '/challenge' },
    elements: { namedItem: (n: string) => fields[n] }, querySelector: (selector: string) => selector === '[data-ln-export-cancel]' ? { addEventListener: (_: string, f: any) => { cancelHandler = f; } } : status,
    addEventListener: (event: string, f: any) => { if (event === 'submit') handler = f; }, reportValidity: () => valid,
    requestSubmit: () => { posted++; } };
  const Drupal: any = { behaviors: {}, t: (s: string) => s, url: (s: string) => '/' + s,
    siwe: signer ? { signMessage: async (data: any) => { calls.push(data); if (pending) await new Promise((_, fail) => data.signal.addEventListener('abort', () => fail(Error('cancelled')))); if (reject) throw Error('secret provider error'); return { address: wrongAccount ? '0xother' : '0xabc', signature: 'secret signature' }; } } : {} };
  vm.runInNewContext(readFileSync(path, 'utf8'), { Drupal, AbortController, once: () => [form], console: { warn: (...v: any[]) => logs.push(v) }, fetch: async (url: string, init: any) => {
    calls.push([url, init]);
    return url === '/session/token' ? { ok: !tokenDenied, text: async () => 'csrf' } : { ok: !denied, json: async () => ({ message: 'exact message', expectedAddress: '0xabc' }) };
  }});
  Drupal.behaviors.localnodesExportConfirmation.attach({});
  return { submit: () => handler({ preventDefault() {}, submitter: button }), cancel: () => cancelHandler({ preventDefault() {} }), fields, status, button, calls, logs, posted: () => posted };
}
describe('fresh export confirmation', () => {
  it('signs only server text and submits the existing form once', async () => {
    const s = setup(); await s.submit();
    expect(s.posted()).toBe(1);
    expect(s.calls[2]).toMatchObject({ message: 'exact message', expectedAddress: '0xabc' });
    expect(s.fields.proof_signature.value).toBe('secret signature');
    expect(s.calls[1][1].headers['X-CSRF-Token']).toBe('csrf');
  });
  for (const config of [{ signer: false }, { reject: true }, { denied: true }, { wrongAccount: true }, { tokenDenied: true }]) {
    it(`shows fixed refusal and help without submitting ${JSON.stringify(config)}`, async () => {
      const s = setup(config); await s.submit();
      expect(s.posted()).toBe(0); expect(s.button.disabled).toBe(false);
      expect(s.status.textContent).toBe('We could not confirm it is you. Try again or ask for export help.');
      expect(s.fields.proof_signature.value).toBe(''); expect(s.logs).toEqual([]);
    });
  }
  it('requires the acknowledgement before requesting a challenge', async () => {
    const s = setup({ valid: false }); await s.submit(); expect(s.calls).toEqual([]);
  });
  it('cancels a pending signature in place and keeps help available', async () => {
    const s = setup({ pending: true });
    const attempt = s.submit();
    for (let i = 0; i < 20 && s.calls.length < 3; i++) await Promise.resolve();
    expect(s.calls.length).toBe(3);
    s.cancel(); await attempt;
    expect(s.posted()).toBe(0); expect(s.button.disabled).toBe(false);
    expect(s.fields.proof_signature.value).toBe('');
    expect(s.status.textContent).toBe('We could not confirm it is you. Try again or ask for export help.');
  });

});
