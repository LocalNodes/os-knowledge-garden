/**
 * Phase 21 plan 23: group_treasury/treasury_forms (js/treasury-forms.js).
 *
 * After a group propose, the group payment page shows "Payment proposed.
 * Organizers can now approve it." with data-group-treasury-focus. A
 * member's page has no transaction_manager (D-15), so this behaviour is
 * what moves focus there. The propose form's data-inflight-text
 * ("Proposing…") goes to flowPanel.inFlight() for the submitting button.
 *
 * Run in a node `vm` with stub Drupal, once and DOM objects. Skips when the
 * gitignored drupal.org clone is absent.
 */
import { describe, expect, it } from 'vitest';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import vm from 'node:vm';

const ROOT = resolve(__dirname, '../..');
const FILE = resolve(ROOT, 'repos/group_treasury/js/treasury-forms.js');
const present = existsSync(FILE);

type Target = { attrs: Record<string, string>; focused: number; hasAttribute(n: string): boolean; setAttribute(n: string, v: string): void; focus(): void };

function target(attrs: Record<string, string> = {}): Target {
  return {
    attrs: { ...attrs },
    focused: 0,
    hasAttribute(n) { return n in this.attrs; },
    setAttribute(n, v) { this.attrs[n] = v; },
    focus() { this.focused++; },
  };
}

function load(selectors: Record<string, unknown[]>) {
  const seen = new Map<unknown, Set<string>>();
  const inFlight: Array<[unknown, string]> = [];
  const timers: Array<() => void> = [];
  const Drupal: any = {
    behaviors: {},
    safeSmartAccounts: { flowPanel: { inFlight: (b: unknown, text: string) => inFlight.push([b, text]) } },
  };
  // once(): each element once per id, like core/once.
  const once = (id: string, selector: string) => (selectors[selector] ?? []).filter((el) => {
    const ids = seen.get(el) ?? new Set<string>();
    if (ids.has(id)) return false;
    ids.add(id);
    seen.set(el, ids);
    return true;
  });
  const window: any = {
    setTimeout: (fn: () => void) => { timers.push(fn); },
    location: { href: 'https://example.test/group/5/treasury/transaction/9' },
    history: { state: null, replaceState() {} },
    URL,
  };
  const ctx = vm.createContext({ Drupal, once, window, URL });
  vm.runInContext(readFileSync(FILE, 'utf8'), ctx);
  return { Drupal, inFlight, timers };
}

describe.skipIf(!present)('Group fund forms: focus and in-flight (21-23)', () => {
  it('focuses the propose success alert once, keeping its tabindex', () => {
    const alert = target({ tabindex: '-1', 'data-group-treasury-focus': '' });
    const h = load({ '[data-group-treasury-focus]': [alert] });
    h.Drupal.behaviors.groupTreasuryForms.attach({});
    h.Drupal.behaviors.groupTreasuryForms.attach({});
    expect(alert.focused).toBe(1);
    expect(alert.attrs.tabindex).toBe('-1');
  });

  it('shows "Proposing…" on the button that submitted the form', () => {
    const listeners: Record<string, (e: unknown) => void> = {};
    const button = { type: 'submit' };
    const form = {
      getAttribute: (n: string) => (n === 'data-inflight-text' ? 'Proposing…' : null),
      addEventListener: (type: string, fn: (e: unknown) => void) => { listeners[type] = fn; },
      querySelector: () => button,
      contains: (el: unknown) => el === button,
    };
    const h = load({ 'form[data-inflight-text]': [form] });
    h.Drupal.behaviors.groupTreasuryForms.attach({});
    listeners.submit({ submitter: button });
    expect(h.inFlight).toEqual([]);
    h.timers.forEach((fn) => fn());
    expect(h.inFlight).toEqual([[button, 'Proposing…']]);
  });
});
