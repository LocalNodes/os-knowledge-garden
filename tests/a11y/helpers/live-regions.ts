// Screen-reader proxy (22-30 Task 3): records what a screen reader would be
// told without the person moving through the page.
//
// recordAnnouncements(page) must run before the page loads. It installs, in
// every document the page opens (addInitScript) and in every shadow root
// created there (DeepChat's included):
//   - a MutationObserver on every aria-live region and every element with
//     role status, alert, log or progressbar. Each change to such a region's
//     text is logged with a timestamp: that text is what a screen reader
//     announces. A region that's aria-busy (or inside one) is logged as
//     "suppressed", because screen readers hold busy regions back;
//   - progress bars: each aria-valuetext change;
//   - focus moves: a screen reader reads the newly focused element's name
//     (an error summary that takes focus is announced this way, not through
//     a live region);
//   - the live regions already filled when the page loaded ("initial"),
//     which screen readers don't announce as a change;
//   - each page's title on load.
// Entries reach the test through an exposed binding, so they survive page
// loads. Nothing here changes the page.
import type { Page } from '@playwright/test';

export type AnnouncementKind = 'live' | 'suppressed' | 'progress' | 'focus' | 'initial' | 'title';

export interface Announcement {
  /** Milliseconds since the epoch. */
  t: number;
  kind: AnnouncementKind;
  /** polite, assertive, or '' (focus, title). */
  politeness: string;
  /** A short description of the element: tag#id.class[role]. */
  source: string;
  text: string;
  url: string;
}

const INIT = `(() => {
  if (window.__lnLiveInstalled) return;
  window.__lnLiveInstalled = true;
  const send = (entry) => {
    try { window.__lnAnnounce(Object.assign({ t: Date.now() }, entry)); } catch (e) {}
  };
  const LIVE_ROLES = { alert: 'assertive', status: 'polite', log: 'polite' };
  const norm = (s) => (s || '').replace(/\\s+/g, ' ').trim();
  const parentOf = (n) => n.parentElement || (n.getRootNode && n.getRootNode().host) || null;
  const describe = (el) => {
    const cls = typeof el.className === 'string' && el.className ? '.' + el.className.trim().split(/\\s+/).slice(0, 2).join('.') : '';
    const role = el.getAttribute('role') ? '[role=' + el.getAttribute('role') + ']' : '';
    const live = el.getAttribute('aria-live') ? '[aria-live=' + el.getAttribute('aria-live') + ']' : '';
    return el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + cls + role + live;
  };
  const liveRoot = (node) => {
    for (let el = node.nodeType === 1 ? node : node.parentNode && node.parentNode.nodeType === 1 ? node.parentNode : parentOf(node); el; el = parentOf(el)) {
      if (el.nodeType !== 1) continue;
      const live = el.getAttribute('aria-live');
      if (live && live !== 'off') return { el, politeness: live };
      const role = el.getAttribute('role');
      if (role && LIVE_ROLES[role]) return { el, politeness: el.getAttribute('aria-live') || LIVE_ROLES[role] };
    }
    return null;
  };
  const busy = (el) => {
    for (let n = el; n; n = parentOf(n)) if (n.nodeType === 1 && n.getAttribute('aria-busy') === 'true') return true;
    return false;
  };
  const shown = (el) => {
    for (let n = el; n; n = parentOf(n)) {
      if (n.nodeType !== 1) continue;
      if (n.hidden || n.getAttribute('aria-hidden') === 'true' || n.inert) return false;
      const cs = getComputedStyle(n);
      if (cs.display === 'none' || cs.visibility === 'hidden') return false;
    }
    return true;
  };
  const accName = (el) => {
    const by = el.getAttribute('aria-labelledby');
    if (by) {
      const root = el.getRootNode();
      const text = by.split(/\\s+/).map((id) => { const t = (root.getElementById ? root.getElementById(id) : null) || document.getElementById(id); return t ? t.textContent : ''; }).join(' ');
      if (norm(text)) return norm(text);
    }
    if (el.getAttribute('aria-label')) return norm(el.getAttribute('aria-label'));
    if (el.labels && el.labels.length) return norm(el.labels[0].textContent);
    return norm(el.textContent).slice(0, 300);
  };
  const last = new WeakMap();
  const pending = new Set();
  let timer = null;
  const flush = () => {
    timer = null;
    pending.forEach((root) => {
      const text = norm(root.el.textContent);
      if (last.get(root.el) === text) return;
      last.set(root.el, text);
      if (!text || !shown(root.el)) return;
      send({ kind: busy(root.el) ? 'suppressed' : 'live', politeness: root.politeness, source: describe(root.el), text });
    });
    pending.clear();
  };
  // Content the HTML parser inserts while the page loads isn't a change a
  // screen reader announces; only what changes after DOMContentLoaded is.
  let ready = document.readyState !== 'loading';
  const queue = (node) => {
    if (!ready) return;
    const root = liveRoot(node);
    if (!root) return;
    for (const p of pending) if (p.el === root.el) return;
    pending.add(root);
    if (!timer) timer = setTimeout(flush, 30);
  };
  const observer = new MutationObserver((mutations) => {
    for (const m of mutations) {
      if (m.type === 'attributes') {
        const el = m.target;
        if (el.getAttribute('role') === 'progressbar' && (m.attributeName === 'aria-valuetext' || m.attributeName === 'aria-valuenow')) {
          const text = el.getAttribute('aria-valuetext') || el.getAttribute('aria-valuenow') || '';
          if (last.get(el) !== text) {
            last.set(el, text);
            send({ kind: 'progress', politeness: '', source: describe(el), text: norm(text) });
          }
        }
        continue;
      }
      queue(m.target);
      m.addedNodes.forEach((n) => queue(n));
    }
  });
  const watch = (root) => observer.observe(root, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['aria-valuetext', 'aria-valuenow'] });
  watch(document);
  const attach = Element.prototype.attachShadow;
  Element.prototype.attachShadow = function (init) {
    const root = attach.call(this, init);
    watch(root);
    root.addEventListener('focusin', onFocus, true);
    return root;
  };
  function onFocus(event) {
    const el = event.composedPath ? event.composedPath()[0] : event.target;
    if (!el || el.nodeType !== 1 || el === document.body) return;
    if (el.getRootNode() !== document && event.currentTarget === document) return;
    send({ kind: 'focus', politeness: '', source: describe(el), text: accName(el) });
  }
  document.addEventListener('focusin', onFocus, true);
  const initial = () => {
    ready = true;
    send({ kind: 'title', politeness: '', source: 'title', text: norm(document.title) });
    document.querySelectorAll('[aria-live], [role="status"], [role="alert"], [role="log"]').forEach((el) => {
      const root = liveRoot(el);
      if (!root || root.el !== el) return;
      const text = norm(el.textContent);
      last.set(el, text);
      if (text && shown(el)) send({ kind: 'initial', politeness: root.politeness, source: describe(el), text });
    });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initial, { once: true });
  else initial();
})()`;

/** Starts recording; returns the log, which keeps filling as the page runs. */
export async function recordAnnouncements(page: Page): Promise<Announcement[]> {
  const log: Announcement[] = [];
  await page.exposeBinding('__lnAnnounce', (source, entry: Omit<Announcement, 'url'>) => {
    log.push({ ...entry, url: source.page.url() });
  });
  await page.addInitScript(INIT);
  return log;
}

/** Entries of one kind whose text matches. */
export function find(log: Announcement[], kind: AnnouncementKind | AnnouncementKind[], text?: RegExp | string): Announcement[] {
  const kinds = Array.isArray(kind) ? kind : [kind];
  return log.filter(
    (a) => kinds.includes(a.kind) && (text === undefined || (typeof text === 'string' ? a.text.includes(text) : text.test(a.text))),
  );
}

/** Milliseconds between consecutive entries. */
export function gaps(entries: Announcement[]): number[] {
  return entries.slice(1).map((a, i) => a.t - entries[i].t);
}
