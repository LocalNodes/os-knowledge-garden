// Unit guard for confirmPrompts: MetaMask closes its
// prompt window after a confirm, sometimes while confirmPrompts is still waiting on that page.
// The loop must treat a closed prompt as done, look for the next one, and never call a method
// on a closed page. These fakes model only what confirmPrompts touches.
import { describe, expect, it } from 'vitest';
import type { BrowserContext } from '@playwright/test';
import { confirmPrompts, type PromptLog } from './metamask-steps';

const EXT = 'fakeextensionid';
const CLOSED = 'page.waitForTimeout: Target page, context or browser has been closed';
const SCROLL = 'confirm-scroll-to-bottom__button';

interface FakePage {
  closed: boolean;
  confirms: number;
  /** Calls made after the page closed; each one throws, and the test requires none. */
  callsAfterClose: string[];
}

/**
 * A MetaMask prompt page with one confirm button. After the button is clicked, the page
 * closes during the next call made on it (as the real window closes during the settle wait),
 * so it still reports open when confirmPrompts next finds it. Make the racing call one that
 * isn't caught (the old waitForTimeout) and the error escapes, as in the CI run.
 */
function fakePrompt(
  button: string | null,
  opts: { clickError?: string; scrollStays?: boolean; closeOnClick?: boolean; closeBeforeClick?: boolean; stays?: boolean; nextTexts?: string[]; drift?: boolean } = {},
): { page: unknown; state: FakePage } {
  const state: FakePage = { closed: false, confirms: 0, callsAfterClose: [] };
  // `stays`: the window stays open on the same screen after the confirm. `nextTexts`: the
  // window stays open and shows each of these requests in turn, one per confirm. `drift`: the
  // screen's incidental text (a fee estimate) changes on every read; the request stays the same.
  const texts = ['Setting up the group fund\nConfirm', ...(opts.nextTexts ?? [])];
  let closing = false;
  let reads = 0;
  const current = () => Math.min(state.confirms, texts.length - 1);
  // The one call that races the close throws but isn't counted: no caller can avoid it.
  // Every call after that, once isClosed() already says true, is counted.
  const guard = (name: string): void => {
    if (closing && !state.closed) {
      state.closed = true;
      throw new Error(CLOSED);
    }
    if (state.closed) {
      state.callsAfterClose.push(name);
      throw new Error(CLOSED);
    }
  };
  const call = async <T>(name: string, value: T): Promise<T> => {
    guard(name);
    return value;
  };
  const locator = (id: string) => ({
    // `button: null` is a prompt with no confirm button (it stays unconfirmed); `scrollStays`
    // keeps the scroll-to-bottom button visible whatever is clicked.
    isVisible: () => call(`isVisible(${id})`, id === button || (!!opts.scrollStays && id === SCROLL)),
    isEnabled: async () => {
      const enabled = await call(`isEnabled(${id})`, true);
      // The window closes right after the check, before the confirm is clicked (rejected
      // elsewhere, or closed by MetaMask), so the click is skipped.
      if (opts.closeBeforeClick && id === button) state.closed = true;
      return enabled;
    },
    check: () => call(`check(${id})`, undefined),
    click: async () => {
      guard(`click(${id})`);
      if (id === SCROLL) return;
      if (opts.clickError) throw new Error(opts.clickError);
      state.confirms++;
      if (opts.closeOnClick) {
        // The window closes on the confirm itself, so the click rejects with the close error.
        state.closed = true;
        throw new Error('locator.click: Target page, context or browser has been closed');
      }
      if (opts.stays) return;
      if (opts.nextTexts && state.confirms < texts.length) return;
      closing = true;
    },
    innerText: () => call('innerText', `${texts[current()]}${opts.drift ? `\nNetwork fee 0.000${++reads}` : ''}`),
  });
  const page = {
    isClosed: () => state.closed,
    // MetaMask routes each request by its id; each request here has its own.
    url: () => `chrome-extension://${EXT}/notification.html#/confirm-transaction/request-${current()}`,
    waitForLoadState: () => call('waitForLoadState', undefined),
    waitForTimeout: (ms: number) => call(`waitForTimeout(${ms})`, undefined),
    locator: () => locator('body'),
    getByTestId: (id: string) => locator(id),
  };
  return { page, state };
}

function fakeContext(pages: () => unknown[], newPage?: () => Promise<unknown>): BrowserContext {
  return { pages, newPage } as unknown as BrowserContext;
}

/**
 * A page the rescue opens; its navigation fails, so it never becomes the prompt page.
 * `close`: 'rejects' fails and leaves the page open; 'hangs' never settles.
 */
function fakeBlankPage(opts: { close?: 'rejects' | 'hangs' } = {}): { page: unknown; state: { closed: boolean } } {
  const state = { closed: false };
  const page = {
    isClosed: () => state.closed,
    url: () => 'about:blank',
    goto: async () => {
      throw new Error('page.goto: net::ERR_ABORTED');
    },
    close: async () => {
      if (opts.close === 'rejects') throw new Error('page.close: Protocol error');
      if (opts.close === 'hangs') return new Promise<void>(() => {});
      state.closed = true;
    },
  };
  return { page, state };
}

describe('confirmPrompts', () => {
  it('treats a prompt that closes after its confirm as done, without calling the closed page', async () => {
    const first = fakePrompt('confirm-footer-button');
    const log: PromptLog = { clicks: [], warnings: [] };
    await confirmPrompts(fakeContext(() => [first.page]), EXT, 'group fund', log, { idleMs: 500, timeoutMs: 20_000 });
    expect(first.state.confirms).toBe(1);
    expect(first.state.callsAfterClose).toEqual([]);
    expect(log.clicks.map((c) => c.button)).toEqual(['confirm-footer-button']);
  }, 30_000);

  it('confirms the next prompt that opens after the first one closed', async () => {
    const first = fakePrompt('confirm-btn');
    const second = fakePrompt('confirm-footer-button');
    const pages = () => (first.state.closed ? [first.page, second.page] : [first.page]);
    const log: PromptLog = { clicks: [], warnings: [] };
    await confirmPrompts(fakeContext(pages), EXT, 'connect then sign', log, { idleMs: 500, timeoutMs: 20_000 });
    expect(first.state.confirms).toBe(1);
    expect(second.state.confirms).toBe(1);
    expect([...first.state.callsAfterClose, ...second.state.callsAfterClose]).toEqual([]);
    expect(log.clicks.map((c) => c.button)).toEqual(['confirm-btn', 'confirm-footer-button']);
  }, 30_000);

  it('still throws when no prompt shows up at all', async () => {
    const log: PromptLog = { clicks: [], warnings: [] };
    await expect(
      confirmPrompts(fakeContext(() => []), EXT, 'nothing', log, { idleMs: 500, timeoutMs: 1_500 }),
    ).rejects.toThrow('no MetaMask prompt to confirm for nothing within 1500 ms');
  }, 30_000);

  // No false success, and no endless scroll loop.
  it('throws when a confirm click is rejected, instead of counting it', async () => {
    const stuck = fakePrompt('confirm-footer-button', { clickError: 'locator.click: element is not attached to the DOM' });
    const log: PromptLog = { clicks: [], warnings: [] };
    await expect(
      confirmPrompts(fakeContext(() => [stuck.page]), EXT, 'rejected', log, { idleMs: 500, timeoutMs: 5_000 }),
    ).rejects.toThrow(/^other \(Error\)$/);
    expect(log.clicks).toEqual([]);
  }, 30_000);

  it('throws when done() stays false and the required follow-up prompt never appears', async () => {
    const first = fakePrompt('confirm-btn');
    const log: PromptLog = { clicks: [], warnings: [] };
    await expect(
      confirmPrompts(fakeContext(() => [first.page]), EXT, 'connect, no sign', log, {
        done: () => false,
        idleMs: 500,
        timeoutMs: 5_000,
      }),
    ).rejects.toThrow('connect, no sign: not done within 5000 ms');
    expect(first.state.confirms).toBe(1);
  }, 30_000);

  it('throws when a follow-up prompt opens and stays unconfirmed', async () => {
    const first = fakePrompt('confirm-btn');
    const second = fakePrompt(null);
    const pages = () => (first.state.closed ? [first.page, second.page] : [first.page]);
    const log: PromptLog = { clicks: [], warnings: [] };
    await expect(
      confirmPrompts(fakeContext(pages), EXT, 'unconfirmed', log, { idleMs: 500, timeoutMs: 5_000 }),
    ).rejects.toThrow('unconfirmed: a MetaMask prompt is still open and unconfirmed after 5000 ms');
  }, 30_000);

  it('ends at the deadline with an error when the scroll button stays visible', async () => {
    const scrolling = fakePrompt('confirm-footer-button', { scrollStays: true });
    const log: PromptLog = { clicks: [], warnings: [] };
    await expect(
      confirmPrompts(fakeContext(() => [scrolling.page]), EXT, 'scroll', log, { idleMs: 500, timeoutMs: 3_000 }),
    ).rejects.toThrow('scroll: a MetaMask prompt is still open and unconfirmed after 3000 ms');
    expect(scrolling.state.confirms).toBe(0);
  }, 30_000);

  it('counts a confirm click that rejects because the window closed on it', async () => {
    const prompt = fakePrompt('confirm-footer-button', { closeOnClick: true });
    const log: PromptLog = { clicks: [], warnings: [] };
    await confirmPrompts(fakeContext(() => [prompt.page]), EXT, 'close on click', log, { idleMs: 500, timeoutMs: 5_000 });
    expect(prompt.state.confirms).toBe(1);
    expect(prompt.state.callsAfterClose).toEqual([]);
    expect(log.clicks.map((c) => c.button)).toEqual(['confirm-footer-button']);
  }, 30_000);

  it('does not count a confirm that was skipped because the window closed before the click', async () => {
    const prompt = fakePrompt('confirm-footer-button', { closeBeforeClick: true });
    const log: PromptLog = { clicks: [], warnings: [] };
    await expect(
      confirmPrompts(fakeContext(() => [prompt.page]), EXT, 'closed first', log, { idleMs: 500, timeoutMs: 3_000 }),
    ).rejects.toThrow('no MetaMask prompt to confirm for closed first within 3000 ms');
    expect(prompt.state.confirms).toBe(0);
    expect(prompt.state.callsAfterClose).toEqual([]);
    expect(log.clicks).toEqual([]);
  }, 30_000);

  it('does not confirm the same request twice when its screen stays the same after the click', async () => {
    const prompt = fakePrompt('confirm-footer-button', { stays: true });
    const log: PromptLog = { clicks: [], warnings: [] };
    await expect(
      confirmPrompts(fakeContext(() => [prompt.page]), EXT, 'stays', log, { done: () => false, idleMs: 500, timeoutMs: 8_000 }),
    ).rejects.toThrow('stays: not done within 8000 ms');
    expect(prompt.state.confirms).toBe(1);
  }, 30_000);

  it('confirms the next request when the same window moves on to it', async () => {
    const prompt = fakePrompt('confirm-footer-button', { nextTexts: ['Setting up voting\nConfirm', 'The voting rules\nConfirm'] });
    const log: PromptLog = { clicks: [], warnings: [] };
    await confirmPrompts(fakeContext(() => [prompt.page]), EXT, 'chain', log, { done: () => prompt.state.confirms >= 3, idleMs: 500, timeoutMs: 20_000 });
    expect(prompt.state.confirms).toBe(3);
  }, 30_000);

  it('closes every page the rescue opened, even when its navigation failed', async () => {
    const first = fakePrompt('confirm-footer-button');
    const opened: Array<{ closed: boolean }> = [];
    const newPage = async () => {
      const blank = fakeBlankPage();
      opened.push(blank.state);
      return blank.page;
    };
    const log: PromptLog = { clicks: [], warnings: [] };
    await expect(
      confirmPrompts(fakeContext(() => [first.page], newPage), EXT, 'rescue', log, { done: () => false, idleMs: 500, timeoutMs: 6_000, reopenIdleMs: 300 }),
    ).rejects.toThrow('rescue: not done within 6000 ms');
    expect(first.state.confirms).toBe(1);
    expect(opened.length).toBeGreaterThan(1);
    expect(opened.every((o) => o.closed)).toBe(true);
  }, 30_000);

  it('does not confirm the same request twice when only its incidental text changes', async () => {
    const prompt = fakePrompt('confirm-footer-button', { stays: true, drift: true });
    const log: PromptLog = { clicks: [], warnings: [] };
    await expect(
      confirmPrompts(fakeContext(() => [prompt.page]), EXT, 'drift', log, { done: () => false, idleMs: 500, timeoutMs: 8_000 }),
    ).rejects.toThrow('drift: not done within 8000 ms');
    expect(prompt.state.confirms).toBe(1);
  }, 30_000);

  it('fails instead of returning when a page it opened cannot be closed', async () => {
    const first = fakePrompt('confirm-footer-button');
    const opened: Array<{ closed: boolean }> = [];
    const newPage = async () => {
      const blank = fakeBlankPage({ close: 'rejects' });
      opened.push(blank.state);
      return blank.page;
    };
    const log: PromptLog = { clicks: [], warnings: [] };
    await expect(
      confirmPrompts(fakeContext(() => [first.page], newPage), EXT, 'stuck close', log, { done: () => opened.length > 0, idleMs: 500, timeoutMs: 20_000, reopenIdleMs: 300 }),
    ).rejects.toThrow('stuck close: a wallet page opened here could not be closed');
  }, 30_000);

  it('bounds a close that never settles', async () => {
    const first = fakePrompt('confirm-footer-button');
    const newPage = async () => fakeBlankPage({ close: 'hangs' }).page;
    const log: PromptLog = { clicks: [], warnings: [] };
    await expect(
      confirmPrompts(fakeContext(() => [first.page], newPage), EXT, 'hung close', log, { done: () => false, idleMs: 500, timeoutMs: 6_000, reopenIdleMs: 300 }),
    ).rejects.toThrow('hung close: a wallet page opened here could not be closed');
  }, 30_000);

  it('bounds opening the prompt page, and closes a page that opens after it gave up', async () => {
    const first = fakePrompt('confirm-footer-button');
    const late = fakeBlankPage();
    let calls = 0;
    let askedAt = 0;
    const newPage = () => {
      calls++;
      askedAt = Date.now();
      return new Promise((resolve) => setTimeout(() => resolve(late.page), 9_000));
    };
    const log: PromptLog = { clicks: [], warnings: [] };
    const began = Date.now();
    await expect(
      confirmPrompts(fakeContext(() => [first.page], newPage), EXT, 'slow open', log, { done: () => false, idleMs: 500, timeoutMs: 5_000, reopenIdleMs: 300 }),
    ).rejects.toThrow("slow open: the wallet's prompt page did not open within");
    expect(Date.now() - began).toBeLessThan(8_000);
    expect(calls).toBe(1);
    // The page appears 9 s after it was asked for; give its close a moment after that.
    await new Promise((r) => setTimeout(r, askedAt + 9_500 - Date.now()));
    expect(late.state.closed).toBe(true);
  }, 30_000);
});
