/**
 * Every Phase 21 form-submit handler keeps the button that submitted the
 * form (21-25 fix 14).
 *
 * The bug: safe-configuration-manager.js disabled "Save approval settings"
 * inside the submit event. A browser leaves a disabled button out of the
 * form data it posts, so the post had no `op` for it. Drupal then took the
 * form's first button ("Remove …"): nothing was saved, no message showed,
 * and the first approver row disappeared.
 *
 * The browser model here follows the HTML spec's order: the submit event's
 * listeners run first, then the browser reads the form's entries (the
 * submitter only if it isn't disabled, with its value at that moment), and
 * only after that do queued timers run. Each handler is loaded in a node
 * `vm` with the real flow-panel.js, so inFlight() really disables the button
 * and changes its value.
 *
 * Handlers covered (every `addEventListener('submit'` in the two modules'
 * js/, pinned by the source test at the end):
 *   - safe_smart_accounts/js/safe-configuration-manager.js (approval settings)
 *   - safe_smart_accounts/js/transaction-form.js (propose a payment)
 *   - group_treasury/js/treasury-forms.js (group propose, link)
 *   - group_treasury/js/treasury-refresh.js (refresh balances)
 *
 * Skips when the gitignored drupal.org clones are absent.
 */
import { describe, expect, it } from 'vitest';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import vm from 'node:vm';

const ROOT = resolve(__dirname, '../..');
const SSA = resolve(ROOT, 'repos/safe_smart_accounts/js');
const GT = resolve(ROOT, 'repos/group_treasury/js');
const present = existsSync(resolve(SSA, 'safe-configuration-manager.js')) && existsSync(resolve(GT, 'treasury-forms.js'));

type Listener = (e: any) => void;

/** An <input type="submit">, as Drupal renders a form button. */
function submitInput(name: string, value: string, attrs: Record<string, string> = {}) {
  const a: Record<string, string> = { ...attrs };
  const classes = new Set<string>();
  return {
    tagName: 'INPUT',
    type: 'submit',
    name,
    value,
    disabled: false,
    hasAttribute: (n: string) => n in a,
    getAttribute: (n: string) => (n in a ? a[n] : null),
    setAttribute: (n: string, v: string) => { a[n] = String(v); },
    removeAttribute: (n: string) => { delete a[n]; },
    classList: { add: (c: string) => classes.add(c), remove: (c: string) => classes.delete(c), contains: (c: string) => classes.has(c) },
    insertAdjacentElement: () => null,
  };
}
type Button = ReturnType<typeof submitInput>;

/** A form with its buttons; the first one in source order is listed first. */
function fakeForm(buttons: Button[], primary: Button, attrs: Record<string, string> = {}) {
  const a: Record<string, string> = { ...attrs };
  const listeners: Record<string, Listener[]> = {};
  const classes = new Set<string>();
  return {
    listeners,
    buttons,
    hasAttribute: (n: string) => n in a,
    getAttribute: (n: string) => (n in a ? a[n] : null),
    setAttribute: (n: string, v: string) => { a[n] = String(v); },
    removeAttribute: (n: string) => { delete a[n]; },
    classList: { add: (c: string) => classes.add(c), contains: (c: string) => classes.has(c) },
    addEventListener: (type: string, fn: Listener) => { (listeners[type] ??= []).push(fn); },
    querySelector: () => primary,
    contains: (el: unknown) => buttons.includes(el as Button),
  };
}
type Form = ReturnType<typeof fakeForm>;

/**
 * The browser's side of a click on `submitter`: no submit at all when the
 * button is disabled; otherwise the submit listeners, then the entry list.
 * Returns what was posted, or null when nothing was.
 */
function click(form: Form, submitter: Button): Array<[string, string]> | null {
  if (submitter.disabled) {
    return null;
  }
  let prevented = false;
  const event = { currentTarget: form, target: form, submitter, preventDefault() { prevented = true; } };
  (form.listeners.submit ?? []).forEach((fn) => fn(event));
  if (prevented) {
    return null;
  }
  // Constructing the entry list: buttons only as the submitter, never disabled.
  return submitter.disabled ? [] : [[submitter.name, submitter.value]];
}

/** Loads flow-panel.js and one handler script; returns the hooks the test drives. */
function load(file: string, selectors: Record<string, unknown[]>) {
  const timers: Array<() => void> = [];
  const windowListeners: Record<string, Listener[]> = {};
  const seen = new Map<unknown, Set<string>>();
  const node = () => ({ className: '', textContent: '', setAttribute() {}, appendChild() {}, classList: { add() {} } });
  const ctx: Record<string, any> = {
    console: { log() {}, warn() {}, error() {} },
    Drupal: { behaviors: {}, t: (s: string) => s, announce() {} },
    drupalSettings: {},
    URL,
    URLSearchParams,
    location: { href: 'https://example.test/group/5/treasury', search: '' },
    history: { state: null, replaceState() {} },
    setTimeout: (fn: () => void) => { timers.push(fn); return timers.length; },
    addEventListener: (type: string, fn: Listener) => { (windowListeners[type] ??= []).push(fn); },
    document: { createElement: node, createElementNS: node, createTextNode: () => ({}), querySelector: () => null, querySelectorAll: () => [] },
    // core/once: each element once per id.
    once: (id: string, selector: string) => (selectors[selector] ?? []).filter((el) => {
      const ids = seen.get(el) ?? new Set<string>();
      if (ids.has(id)) return false;
      ids.add(id);
      seen.set(el, ids);
      return true;
    }),
  };
  ctx.window = ctx;
  vm.createContext(ctx);
  vm.runInContext(readFileSync(resolve(SSA, 'flow-panel.js'), 'utf8'), ctx);
  vm.runInContext(readFileSync(file, 'utf8'), ctx);
  const context = {
    querySelectorAll: (sel: string) => {
      const hit = Object.keys(selectors).find((k) => sel.startsWith(k));
      return hit ? selectors[hit] : [];
    },
  };
  const flush = () => { while (timers.length) timers.shift()!(); };
  const pageshow = (persisted: boolean) => (windowListeners.pageshow ?? []).forEach((fn) => fn({ persisted }));
  return { ctx, context, timers, flush, pageshow };
}

/** The approval settings form: approver rows' "Remove" buttons come first. */
function approvalSettings() {
  const remove = submitInput('signers_additional_signers_0_remove', 'Remove Ana');
  const save = submitInput('op', 'Propose this change', { 'data-safe-inflight-text': 'Saving…' });
  const form = fakeForm([remove, save], save);
  const h = load(resolve(SSA, 'safe-configuration-manager.js'), { '#safe-account-manage-form': [form] });
  h.ctx.Drupal.behaviors.safeConfigurationManager.attach(h.context);
  return { h, form, remove, save };
}

describe.skipIf(!present)('Approval settings: Save is posted with its own value (21-25 fix 14)', () => {
  it('posts op=Propose this change, then shows "Saving…"', () => {
    const { h, form, save } = approvalSettings();
    const posted = click(form, save);
    expect(posted).toEqual([['op', 'Propose this change']]);
    h.flush();
    expect(save.disabled).toBe(true);
    expect(save.value).toBe('Saving…');
  });

  it('never posts twice on a double-click', () => {
    const { h, form, save } = approvalSettings();
    expect(click(form, save)).toEqual([['op', 'Propose this change']]);
    // The second click lands before the in-flight timer ran.
    expect(click(form, save)).toBeNull();
    h.flush();
    // And after it, the button is disabled, so the browser won't submit.
    expect(click(form, save)).toBeNull();
  });

  it('leaves a row\'s "Remove" button alone', () => {
    const { h, form, remove, save } = approvalSettings();
    expect(click(form, remove)).toEqual([['signers_additional_signers_0_remove', 'Remove Ana']]);
    h.flush();
    expect(save.disabled).toBe(false);
    expect(remove.disabled).toBe(false);
  });

  it('brings the button back when the browser restores the page from its cache', () => {
    const { h, form, save } = approvalSettings();
    click(form, save);
    h.flush();
    h.pageshow(true);
    expect(save.disabled).toBe(false);
    expect(save.value).toBe('Propose this change');
    expect(click(form, save)).toEqual([['op', 'Propose this change']]);
  });
});

describe.skipIf(!present)('The other submit handlers keep their submitter (fix 14 sweep)', () => {
  it('transaction-form.js: "Propose payment" is posted, then "Proposing…"; no second post', () => {
    const propose = submitInput('op', 'Propose payment');
    const form = fakeForm([propose], propose);
    const h = load(resolve(SSA, 'transaction-form.js'), { 'form.safe-propose-form': [form] });
    h.ctx.Drupal.behaviors.safeTransactionForm.attach(h.context);
    expect(click(form, propose)).toEqual([['op', 'Propose payment']]);
    expect(click(form, propose)).toBeNull();
    h.flush();
    expect(propose.disabled).toBe(true);
    expect(propose.value).toBe('Proposing…');
  });

  it('treasury-forms.js: the submitter is posted, then shows the form\'s in-flight text', () => {
    const link = submitInput('op', 'Link account');
    const form = fakeForm([link], link, { 'data-inflight-text': 'Checking…' });
    const h = load(resolve(GT, 'treasury-forms.js'), { 'form[data-inflight-text]': [form] });
    h.ctx.Drupal.behaviors.groupTreasuryForms.attach(h.context);
    expect(click(form, link)).toEqual([['op', 'Link account']]);
    h.flush();
    expect(link.disabled).toBe(true);
    expect(link.value).toBe('Checking…');
    expect(click(form, link)).toBeNull();
  });

  it('treasury-refresh.js: "Refresh balances" is posted, then disabled', () => {
    const refresh = submitInput('op', 'Refresh balances');
    const form = fakeForm([refresh], refresh);
    const h = load(resolve(GT, 'treasury-refresh.js'), { 'form.group-treasury-refresh-form': [form] });
    h.ctx.Drupal.behaviors.groupTreasuryRefresh.attach(h.context);
    expect(click(form, refresh)).toEqual([['op', 'Refresh balances']]);
    h.flush();
    expect(refresh.disabled).toBe(true);
    expect(click(form, refresh)).toBeNull();
  });

  it('covers every submit listener in safe_smart_accounts/js and group_treasury/js', () => {
    const covered = [
      'safe_smart_accounts/js/safe-configuration-manager.js',
      'safe_smart_accounts/js/transaction-form.js',
      'group_treasury/js/treasury-forms.js',
      'group_treasury/js/treasury-refresh.js',
    ];
    const found: string[] = [];
    for (const [mod, dir] of [['safe_smart_accounts', SSA], ['group_treasury', GT]] as const) {
      for (const f of readdirSync(dir).filter((n) => n.endsWith('.js'))) {
        if (/addEventListener\(\s*['"]submit['"]/.test(readFileSync(resolve(dir, f), 'utf8'))) {
          found.push(`${mod}/js/${f}`);
        }
      }
    }
    expect(found.sort()).toEqual(covered.sort());
  });
});
