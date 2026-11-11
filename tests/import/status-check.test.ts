/**
 * The Import tab's progress panel when a check fails.
 *
 * A failed check used to leave the old numbers with no word that they were
 * old. Now it says "Couldn't check progress. Last checked ‹time›. Try
 * again.", keeps the old numbers, and clears the line once a check works.
 *
 * status.js runs in a node `vm` against a small fake of the panel, with a
 * fetch that fails or succeeds on demand.
 */
import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import vm from 'node:vm';

const SOURCE = readFileSync(resolve(__dirname, '../../html/modules/custom/localnodes_import/js/status.js'), 'utf8');

type Listener = (e: any) => void;

function el(attrs: Record<string, string> = {}, text = '') {
  const a: Record<string, string> = { ...attrs };
  const listeners: Record<string, Listener[]> = {};
  return {
    hidden: 'hidden' in a,
    textContent: text,
    children: [] as unknown[],
    listeners,
    getAttribute: (n: string) => (n in a ? a[n] : null),
    setAttribute: (n: string, v: string) => { a[n] = String(v); },
    removeAttribute: (n: string) => { delete a[n]; },
    hasAttribute: (n: string) => n in a,
    addEventListener: (type: string, fn: Listener) => { (listeners[type] ??= []).push(fn); },
    replaceChildren(...kids: unknown[]) { this.children = kids; },
    querySelector: (_: string): any => null,
  };
}

function panel() {
  const parts: Record<string, ReturnType<typeof el>> = {
    '[data-status-summary]': el({}, '18 of 21 documents are prepared for the assistant\'s search.'),
    '[data-status-lines]': el(),
    '[data-status-all]': el({ hidden: '' }),
    '[data-status-live]': el(),
    '[data-status-time]': el(),
    '[data-status-relative]': el({}, 'Updated just now'),
    '[data-status-full]': el(),
    '[data-status-refresh]': el(),
    '[data-status-error]': el({ hidden: '' }),
  };
  const button = el();
  parts['[data-status-refresh]'].querySelector = (s: string) => (s === 'button' ? button : null);
  const root = el({ 'data-status-url': '/status.json', 'data-waiting': '3', 'data-no-text': '0', 'data-updated': '0' });
  root.querySelector = (s: string) => parts[s] ?? null;
  return { root, parts };
}

function load(fetchImpl: () => Promise<any>) {
  const { root, parts } = panel();
  const Drupal: any = {
    behaviors: {},
    t: (s: string, args: Record<string, string> = {}) => Object.entries(args).reduce((out, [k, v]) => out.split(k).join(v), s),
    formatPlural: (n: number, one: string, many: string) => (n === 1 ? one : many.replace('@count', String(n))),
  };
  const context = vm.createContext({
    Drupal,
    once: () => [root],
    window: { setTimeout: () => 0, clearTimeout: () => undefined, setInterval: () => 0 },
    document: { visibilityState: 'hidden', addEventListener: () => undefined, createElement: () => el() },
    fetch: fetchImpl,
    Date,
    Math,
    Error,
  });
  vm.runInContext(SOURCE, context);
  Drupal.behaviors.localnodesImportStatus.attach({});
  const submit = async () => {
    for (const fn of parts['[data-status-refresh]'].listeners.submit ?? []) {
      fn({ preventDefault: () => undefined });
    }
    // Let the fetch promise chain settle.
    for (let i = 0; i < 10; i++) {
      await Promise.resolve();
    }
  };
  return { parts, submit };
}

describe('status.js: a check that fails', () => {
  it('says the check failed, keeps the old count, and names when it was last checked', async () => {
    const { parts, submit } = load(() => Promise.reject(new Error('offline')));
    const before = parts['[data-status-summary]'].textContent;
    await submit();
    const error = parts['[data-status-error]'];
    expect(error.hidden).toBe(false);
    expect(error.textContent).toMatch(/^Couldn't check progress\. Last checked \d{1,2}:\d{2}( ?[AP]M)?\. Try again\.$/);
    expect(parts['[data-status-summary]'].textContent).toBe(before);
  });

  it('a server error reads the same as a network failure', async () => {
    const { parts, submit } = load(() => Promise.resolve({ ok: false, status: 500 }));
    await submit();
    expect(parts['[data-status-error]'].hidden).toBe(false);
    expect(parts['[data-status-error]'].textContent).toMatch(/^Couldn't check progress\./);
  });

  it('a check that works clears the line', async () => {
    let fail = true;
    const data = {
      summary: '21 of 21 documents are prepared for the assistant\'s search.', lines: [], stale: false,
      waiting: 0, no_text: 0, all: 'All documents are prepared.', all_visible: true,
      waiting_label: '', no_text_label: '', updated_iso: '', updated_full: '',
    };
    const { parts, submit } = load(() => (fail ? Promise.reject(new Error('offline')) : Promise.resolve({ ok: true, json: async () => data })));
    await submit();
    expect(parts['[data-status-error]'].hidden).toBe(false);
    fail = false;
    await submit();
    expect(parts['[data-status-error]'].hidden).toBe(true);
    expect(parts['[data-status-summary]'].textContent).toBe(data.summary);
  });
});
