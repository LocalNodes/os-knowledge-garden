/**
 * The Stop button while files upload.
 *
 * "Stop uploading" didn't say what happens to files already sent. The button
 * now reads "Stop remaining uploads", and a line beside it, which the button
 * names as its description, says "Files already uploaded will still be
 * added." The line shows and hides with the button.
 *
 * upload.js runs in a node `vm` against a small fake DOM: just enough nodes
 * for the uploader to build its panel.
 */
import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import vm from 'node:vm';

const SOURCE = readFileSync(resolve(__dirname, '../../html/modules/custom/localnodes_import/js/upload.js'), 'utf8');

class FakeNode {
  tagName: string;
  className = '';
  textContent = '';
  hidden = false;
  disabled = false;
  type = '';
  id = '';
  value = '';
  style: Record<string, string> = {};
  dataset: Record<string, string> = {};
  attrs: Record<string, string> = {};
  children: FakeNode[] = [];
  parentNode: FakeNode | null = null;
  listeners: Record<string, Array<(e: unknown) => void>> = {};
  classList = {
    add: (c: string) => { this.className = (this.className + ' ' + c).trim(); },
    remove: (c: string) => { this.className = this.className.split(' ').filter((x) => x !== c).join(' '); },
    contains: (c: string) => this.className.split(' ').includes(c),
  };

  constructor(tag: string) {
    this.tagName = tag.toUpperCase();
  }

  setAttribute(n: string, v: string) { this.attrs[n] = String(v); if (n === 'id') this.id = String(v); }
  getAttribute(n: string) { return n === 'id' && this.id ? this.id : (this.attrs[n] ?? null); }
  removeAttribute(n: string) { delete this.attrs[n]; }
  addEventListener(t: string, fn: (e: unknown) => void) { (this.listeners[t] ??= []).push(fn); }
  appendChild(child: FakeNode) {
    child.parentNode?.removeChild(child);
    child.parentNode = this;
    this.children.push(child);
    return child;
  }
  removeChild(child: FakeNode) {
    this.children = this.children.filter((c) => c !== child);
    child.parentNode = null;
    return child;
  }
  insertBefore(child: FakeNode, ref: FakeNode | null) {
    child.parentNode?.removeChild(child);
    child.parentNode = this;
    const i = ref ? this.children.indexOf(ref) : -1;
    if (i === -1) this.children.push(child); else this.children.splice(i, 0, child);
    return child;
  }
  insertAdjacentElement(_where: string, node: FakeNode) {
    this.parentNode?.insertBefore(node, this.nextSibling);
    return node;
  }
  get nextSibling(): FakeNode | null {
    if (!this.parentNode) return null;
    const kids = this.parentNode.children;
    return kids[kids.indexOf(this) + 1] ?? null;
  }
  focus() {}
  closest() { return null; }
  querySelector() { return null; }
  querySelectorAll() { return []; }
  all(): FakeNode[] { return [this, ...this.children.flatMap((c) => c.all())]; }
}

function load() {
  const form = new FakeNode('form');
  form.className = 'localnodes-import-upload';
  const input = new FakeNode('input');
  input.type = 'file';
  const actions = new FakeNode('div');
  const submit = new FakeNode('button');
  form.appendChild(input);
  form.appendChild(actions);
  actions.appendChild(submit);
  form.querySelector = ((s: string) => {
    if (s === 'input[type="file"]') return input;
    if (s === '.form-actions [type="submit"]') return submit;
    // The hidden fields finish() fills.
    if (s.startsWith('input[name="') && !s.includes('rights') && !s.includes('license')) return new FakeNode('input');
    return null;
  }) as never;
  const document = {
    createElement: (tag: string) => new FakeNode(tag),
    createElementNS: (_ns: string, tag: string) => new FakeNode(tag),
  };
  const t = (s: string, args: Record<string, unknown> = {}) =>
    Object.entries(args).reduce((out, [k, v]) => out.split(k).join(String(v)), s);
  const Drupal: any = { behaviors: {}, t, formatPlural: (n: number, a: string, b: string) => (n === 1 ? a : b.replace('@count', String(n))) };
  const drupalSettings = { localnodes_import: { upload: { extensions: ['pdf'], maxBytes: 1e7, limitMb: 10 } } };
  const once = (id: string) => (id === 'localnodes-import-upload' ? [form] : []);
  vm.runInNewContext(SOURCE, { Drupal, drupalSettings, once, document, window: {}, fetch: () => new Promise(() => {}) });
  Drupal.behaviors.localnodesImportUpload.attach(form);
  const uploader = (form as any).localnodesImportUploader;
  const byClass = (c: string) => form.all().filter((n) => n.classList.contains(c));
  return { uploader, byClass };
}

describe('the Stop button', () => {
  it('says only the remaining uploads stop', () => {
    const { byClass } = load();
    const [stop] = byClass('localnodes-import-chosen__stop');
    expect(stop.textContent).toBe('Stop remaining uploads');
  });

  it('is described by a line saying uploaded files are still added', () => {
    const { byClass } = load();
    const [stop] = byClass('localnodes-import-chosen__stop');
    const [note] = byClass('localnodes-import-chosen__stop-note');
    expect(note.textContent).toBe('Files already uploaded will still be added.');
    expect(note.id).not.toBe('');
    expect(stop.getAttribute('aria-describedby')).toBe(note.id);
    expect(stop.nextSibling).toBe(note);
  });

  it('shows and hides the line with the button', () => {
    const { uploader, byClass } = load();
    const [stop] = byClass('localnodes-import-chosen__stop');
    const [note] = byClass('localnodes-import-chosen__stop-note');
    expect([stop.hidden, note.hidden]).toEqual([true, true]);
    uploader.importable = () => [];
    uploader.next = () => {};
    uploader.start();
    expect([stop.hidden, note.hidden]).toEqual([false, false]);
    uploader.form.submit = () => {};
    uploader.finish();
    expect([stop.hidden, note.hidden]).toEqual([true, true]);
  });
});
