// Keyboard-only helpers for the 22-30 keyboard run (UI-SPEC Part 3 focus
// rules, Part 5 "Focus visible on everything").
//
// walkTabOrder(page) presses Tab from the top of the page until focus wraps
// (back to the first stop, or to the page itself), records every stop, and
// then walks the same way back with Shift+Tab. For each stop it records:
//   - where it is (tag, id, name, a CSS path) and its section (the nearest
//     landmark or block it sits in);
//   - whether a focus indicator shows: the focused style of the element (and,
//     for a checkbox or radio, of its label and the label's ::before) differs
//     from its unfocused style, read once all transitions have finished;
//   - whether any part of it is in the viewport and not covered by another
//     element (SC 2.4.11: focus not entirely hidden by sticky or floating
//     content).
// expectedTabStops(page) lists, in document order and through open shadow
// roots, every visible element a Tab key should reach (one radio per group,
// no tabindex="-1", nothing disabled or inside a closed <details>). The spec
// compares the two lists.
//
// Nothing here clicks or uses the mouse.
import type { Page } from '@playwright/test';

export interface TabStop {
  index: number;
  tag: string;
  id: string;
  name: string;
  path: string;
  section: string;
  /** Focused style differs from the unfocused one. */
  indicator: boolean;
  /** What differed (for the record). */
  indicatorDetail: string;
  /** Some part of the element is on screen and not covered. */
  visibleInViewport: boolean;
  /** When hidden: what is on top of it ("off screen" when outside the window). */
  coveredBy: string;
  /** Set when the element belongs to a known pre-existing, non-Phase-22 component. */
  preexisting: string | null;
}

export interface ExpectedStop {
  path: string;
  name: string;
  tag: string;
  id: string;
  /** Set when the element belongs to a known pre-existing, non-Phase-22 component. */
  preexisting: string | null;
}

export interface WalkResult {
  forward: TabStop[];
  backward: TabStop[];
  /** Forward walk returned to the start within the cap (no trap). */
  wrapped: boolean;
  /** Backward walk returned to the start within the cap. */
  wrappedBack: boolean;
}

/**
 * Runs in the page: tags every candidate with data-kbd-id and stores its
 * unfocused style signature on window.__kbd.
 */
const INSTALL = `(() => {
  const SELECTOR = 'a[href], area[href], button, input:not([type="hidden"]), select, textarea, summary, iframe, [tabindex], [contenteditable]:not([contenteditable="false"])';
  const all = [];
  const visit = (root) => {
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
    let node = walker.currentNode;
    while (node) {
      if (node.nodeType === 1) {
        if (node.matches && node.matches(SELECTOR)) all.push(node);
        if (node.shadowRoot) visit(node.shadowRoot);
      }
      node = walker.nextNode();
    }
  };
  visit(document);
  const labelOf = (el) => {
    if (el.labels && el.labels.length) return el.labels[0];
    return null;
  };
  const sigOf = (el, pseudo) => {
    const cs = getComputedStyle(el, pseudo || null);
    return [cs.outlineStyle, cs.outlineWidth, cs.outlineColor, cs.outlineOffset, cs.boxShadow, cs.borderTopColor, cs.borderBottomColor, cs.borderTopWidth, cs.backgroundColor, cs.color, cs.textDecorationLine].join('|');
  };
  const signature = (el) => {
    const parts = { self: sigOf(el), before: sigOf(el, '::before'), after: sigOf(el, '::after') };
    // Some widgets draw the ring on a wrapper (DeepChat's question box draws
    // it on #text-input-container), so the parent's style counts too.
    if (el.parentElement) parts.parent = sigOf(el.parentElement);
    const label = labelOf(el);
    if (label && (el.type === 'checkbox' || el.type === 'radio' || el.type === 'file')) {
      parts.label = sigOf(label);
      parts.labelBefore = sigOf(label, '::before');
      parts.labelAfter = sigOf(label, '::after');
    }
    return parts;
  };
  let id = 0;
  const unfocused = {};
  for (const el of all) {
    if (!el.hasAttribute('data-kbd-id')) el.setAttribute('data-kbd-id', String(++id));
    unfocused[el.getAttribute('data-kbd-id')] = signature(el);
  }
  // Pre-existing components that aren't Phase 22's (recorded, not asserted):
  // - Open Social's emoji picker (social_emoji) sits off screen but stays in
  //   the Tab order on pages with a post or comment form;
  // - socialblue's stream teaser image link is aria-hidden="true" yet
  //   focusable, with an empty box.
  const preexisting = (el) => {
    let node = el;
    while (node) {
      if (node.tagName === 'EMOJI-PICKER') return 'emoji-picker (Open Social social_emoji)';
      if (node.matches && node.matches('.teaser__image > a[aria-hidden="true"]')) return 'socialblue teaser image link (aria-hidden, focusable)';
      node = node.parentElement || (node.getRootNode() && node.getRootNode().host) || null;
    }
    return null;
  };
  window.__kbd = { signature, unfocused, labelOf, all, preexisting };
})()`;

/** Visible elements a Tab key should reach, in document order. */
export async function expectedTabStops(page: Page): Promise<ExpectedStop[]> {
  await page.evaluate(INSTALL);
  return page.evaluate(() => {
    const kbd = (window as unknown as { __kbd: { all: Element[]; preexisting: (el: Element) => string | null } }).__kbd;
    const seenRadioGroups = new Set<string>();
    const out: { path: string; name: string; tag: string; id: string; preexisting: string | null }[] = [];
    const isVisible = (el: Element) => {
      const h = el as HTMLElement;
      if (typeof h.checkVisibility === 'function' && !h.checkVisibility({ visibilityProperty: true } as CheckVisibilityOptions)) {
        return false;
      }
      const r = h.getBoundingClientRect();
      return r.width > 0 && r.height > 0;
    };
    const inClosedDetails = (el: Element) => {
      let node: Element | null = el.parentElement;
      while (node) {
        if (node.tagName === 'DETAILS' && !(node as HTMLDetailsElement).open) {
          // The summary of a closed details is reachable; its other content is not.
          const summary = node.querySelector(':scope > summary');
          return !(summary && (summary === el || summary.contains(el)));
        }
        node = node.parentElement;
      }
      return false;
    };
    const radios = (name: string, form: HTMLFormElement | null) =>
      Array.from(document.querySelectorAll<HTMLInputElement>(`input[type="radio"][name="${CSS.escape(name)}"]`)).filter(
        (r) => r.form === form,
      );
    for (const el of kbd.all) {
      const h = el as HTMLElement & { disabled?: boolean };
      if (h.disabled) continue;
      // Chrome reports tabIndex -1 for a contenteditable element without a
      // tabindex attribute, yet Tab reaches it.
      if (h.tabIndex < 0 && !(h.isContentEditable && !h.hasAttribute('tabindex'))) continue;
      // inert on an ancestor, also across a shadow root's host.
      let inert = false;
      for (let node: Element | null = h; node; node = node.parentElement ?? ((node.getRootNode() as ShadowRoot).host ?? null)) {
        if ((node as HTMLElement).inert) {
          inert = true;
          break;
        }
      }
      if (inert) continue;
      if (h.tagName === 'SUMMARY' && h.parentElement?.tagName !== 'DETAILS') continue;
      if (inClosedDetails(h)) continue;
      if (h.tagName === 'INPUT' && (h as HTMLInputElement).type === 'radio') {
        const input = h as HTMLInputElement;
        const key = `${input.form?.id ?? ''}::${input.name}`;
        if (seenRadioGroups.has(key)) continue;
        const group = radios(input.name, input.form).filter((r) => !r.disabled);
        const stop = group.find((r) => r.checked) ?? group[0];
        if (stop !== input) continue;
        seenRadioGroups.add(key);
      }
      if (!isVisible(h)) continue;
      out.push({
        path: h.getAttribute('data-kbd-id') ?? '',
        name: (h.getAttribute('aria-label') ?? h.textContent ?? (h as HTMLInputElement).value ?? '').replace(/\s+/g, ' ').trim().slice(0, 80),
        tag: h.tagName,
        id: h.id,
        preexisting: kbd.preexisting(h),
      });
    }
    return out;
  });
}

/** Describes the element that has focus now (through shadow roots). */
async function describeFocus(page: Page, index: number): Promise<TabStop | null> {
  // Let the focus ring's transition finish before reading the style.
  await page.evaluate(async () => {
    let a = document.activeElement;
    while (a && a.shadowRoot && a.shadowRoot.activeElement) a = a.shadowRoot.activeElement;
    if (!a) return;
    const animations = [a, ...(a as HTMLInputElement).labels ?? []].flatMap((el) => el.getAnimations({ subtree: false }));
    await Promise.race([Promise.all(animations.map((x) => x.finished.catch(() => null))), new Promise((r) => setTimeout(r, 1000))]);
  });
  return page.evaluate((i) => {
    type Kbd = {
      signature: (el: Element) => Record<string, string>;
      unfocused: Record<string, Record<string, string>>;
      labelOf: (el: Element) => Element | null;
      preexisting: (el: Element) => string | null;
    };
    const kbd = (window as unknown as { __kbd: Kbd }).__kbd;
    let a: Element | null = document.activeElement;
    while (a && a.shadowRoot && a.shadowRoot.activeElement) a = a.shadowRoot.activeElement;
    if (!a || a === document.body || a === document.documentElement) return null;
    const id = a.getAttribute('data-kbd-id') ?? '';
    const now = kbd.signature(a);
    const before = kbd.unfocused[id] ?? {};
    const changed = Object.keys(now).filter((k) => now[k] !== before[k]);
    const outline = getComputedStyle(a);
    const hasOutline = outline.outlineStyle !== 'none' && parseFloat(outline.outlineWidth) > 0;
    const indicator = changed.length > 0 || (!id && hasOutline);

    // Visible: some sampled point of the element (or its label, for an input
    // drawn by its label) is inside the viewport and hit-tests to it.
    const targets = [a];
    const label = kbd.labelOf(a);
    if (label) targets.push(label);
    const r0 = (t: Element) => {
      const r = t.getBoundingClientRect();
      return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
    };
    const owns = (hit: Element | null) => {
      if (!hit) return false;
      for (const t of targets) {
        if (t === hit || t.contains(hit) || hit.contains(t)) return true;
        // Inside a shadow root: the hit test returns the host.
        const root = t.getRootNode() as ShadowRoot;
        if (root.host && (root.host === hit || hit.contains(root.host))) {
          const inner = root.elementFromPoint(r0(t).x, r0(t).y);
          return !inner || t === inner || t.contains(inner) || inner.contains(t);
        }
      }
      return false;
    };
    let visibleInViewport = false;
    let coveredBy = '';
    for (const t of targets) {
      const r = t.getBoundingClientRect();
      if (r.width === 0 || r.height === 0) continue;
      const xs = [r.left + 2, r.left + r.width / 2, r.right - 2];
      const ys = [r.top + 2, r.top + r.height / 2, r.bottom - 2];
      for (const x of xs) {
        for (const y of ys) {
          if (x < 0 || y < 0 || x > innerWidth || y > innerHeight) continue;
          const hit = document.elementFromPoint(x, y);
          if (owns(hit)) visibleInViewport = true;
          else if (hit && !coveredBy) {
            const named = hit.closest('[id], [class]');
            coveredBy = named ? `${named.tagName.toLowerCase()}${named.id ? '#' + named.id : ''}.${String(named.className).split(' ')[0]}` : hit.tagName;
            if (hit.closest('.ai-deepchat')) coveredBy = 'ai-deepchat ' + coveredBy;
          }
        }
      }
    }

    const section = (() => {
      let node: Element | null = a;
      while (node) {
        const role = node.getAttribute('role');
        if (node.id && /^block-/.test(node.id)) return '#' + node.id;
        if (['MAIN', 'HEADER', 'FOOTER', 'NAV', 'ASIDE', 'FORM'].includes(node.tagName) || role === 'complementary' || role === 'region') {
          return node.tagName.toLowerCase() + (node.id ? '#' + node.id : '') + (node.getAttribute('aria-label') ? `[${node.getAttribute('aria-label')}]` : '');
        }
        const parent: Element | null = node.parentElement ?? ((node.getRootNode() as ShadowRoot).host ?? null);
        node = parent;
      }
      return 'document';
    })();

    return {
      index: i,
      tag: a.tagName,
      id: a.id,
      name: (a.getAttribute('aria-label') ?? a.textContent ?? (a as HTMLInputElement).value ?? '').replace(/\s+/g, ' ').trim().slice(0, 80),
      path: id,
      section,
      indicator,
      indicatorDetail: changed.length ? changed.map((k) => `${k}: ${before[k] ?? '?'} -> ${now[k]}`).join(' ; ').slice(0, 400) : hasOutline ? 'outline (untracked element)' : 'none',
      visibleInViewport,
      coveredBy: visibleInViewport ? '' : coveredBy || 'off screen',
      preexisting: kbd.preexisting(a),
    };
  }, index);
}

/**
 * Puts the sequential-focus starting point at the start or the end of the
 * page: focuses a temporary tabindex="-1" marker there, which Tab never
 * returns to, and removes it on the next focus change.
 */
async function startFrom(page: Page, where: 'start' | 'end') {
  await page.evaluate((at) => {
    document.querySelectorAll('[data-kbd-marker]').forEach((m) => m.remove());
    const marker = document.createElement('span');
    marker.setAttribute('tabindex', '-1');
    marker.setAttribute('data-kbd-marker', at);
    marker.style.cssText = 'position:absolute;width:1px;height:1px;overflow:hidden;';
    if (at === 'start') {
      document.body.prepend(marker);
      window.scrollTo(0, 0);
    }
    else {
      document.body.append(marker);
    }
    marker.focus({ preventScroll: at === 'start' });
    marker.addEventListener('blur', () => marker.remove(), { once: true });
  }, where);
}

/**
 * Tabs through the whole page and back. Starts from the top of the page
 * (focus on the document), as after a page load.
 */
export async function walkTabOrder(page: Page, { cap = 600 } = {}): Promise<WalkResult> {
  // Start at the top, as after a page load. blur() alone would leave the
  // browser's sequential-focus starting point on the last focused element,
  // so focus a temporary unfocusable-by-Tab marker at the start of <body>.
  await startFrom(page, 'start');
  // Read every element's unfocused style now that nothing on the page has
  // focus (a setup may have left focus on a field or an error summary).
  await page.evaluate(INSTALL);
  const forward: TabStop[] = [];
  let wrapped = false;
  for (let i = 0; i < cap; i++) {
    await page.keyboard.press('Tab');
    const stop = await describeFocus(page, i);
    if (process.env.LN_KBD_DEBUG) console.log('Tab', i, Date.now(), stop?.tag, stop?.path, stop?.name);
    if (!stop) {
      wrapped = true;
      break;
    }
    if (forward.length && stop.path && stop.path === forward[0].path) {
      wrapped = true;
      break;
    }
    forward.push(stop);
  }

  // Back: from the end of the page, Shift+Tab to the first stop.
  await startFrom(page, 'end');
  const backward: TabStop[] = [];
  let wrappedBack = false;
  for (let i = 0; i < cap; i++) {
    await page.keyboard.press('Shift+Tab');
    const stop = await describeFocus(page, i);
    if (process.env.LN_KBD_DEBUG) console.log('Shift+Tab', i, Date.now(), stop?.tag, stop?.path, stop?.name);
    if (!stop) {
      if (backward.length) {
        wrappedBack = true;
        break;
      }
      continue;
    }
    if (backward.length && stop.path && stop.path === backward[0].path) {
      wrappedBack = true;
      break;
    }
    backward.push(stop);
  }
  return { forward, backward, wrapped, wrappedBack };
}

/** The element that has focus, through shadow roots, as a short description. */
export async function focused(page: Page): Promise<{ tag: string; id: string; name: string; cls: string; href: string | null }> {
  return page.evaluate(() => {
    let a: Element | null = document.activeElement;
    while (a && a.shadowRoot && a.shadowRoot.activeElement) a = a.shadowRoot.activeElement;
    const el = a as HTMLElement;
    return {
      tag: el?.tagName ?? '',
      id: el?.id ?? '',
      name: (el?.getAttribute('aria-label') ?? el?.textContent ?? '').replace(/\s+/g, ' ').trim().slice(0, 120),
      cls: typeof el?.className === 'string' ? el.className : '',
      href: el?.getAttribute('href') ?? null,
    };
  });
}

/**
 * Presses Tab (or Shift+Tab) until `match` returns true for the focused
 * element. Fails after `cap` presses.
 */
export async function tabUntil(
  page: Page,
  match: (f: Awaited<ReturnType<typeof focused>>) => boolean,
  { cap = 220, back = false, what = 'the target' } = {},
): Promise<void> {
  for (let i = 0; i < cap; i++) {
    await page.keyboard.press(back ? 'Shift+Tab' : 'Tab');
    if (match(await focused(page))) return;
  }
  throw new Error(`Tab never reached ${what} in ${cap} presses`);
}
