// Unit guard for confirmPrompts (21-46, first dispatched run 42482750731): MetaMask closes its
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
  opts: { clickError?: string; scrollStays?: boolean; closeOnClick?: boolean; closeBeforeClick?: boolean } = {},
): { page: unknown; state: FakePage } {
  const state: FakePage = { closed: false, confirms: 0, callsAfterClose: [] };
  let closing = false;
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
      closing = true;
    },
    innerText: () => call('innerText', 'Setting up the group fund\nConfirm'),
  });
  const page = {
    isClosed: () => state.closed,
    url: () => `chrome-extension://${EXT}/notification.html#confirmation`,
    waitForLoadState: () => call('waitForLoadState', undefined),
    waitForTimeout: (ms: number) => call(`waitForTimeout(${ms})`, undefined),
    locator: () => locator('body'),
    getByTestId: (id: string) => locator(id),
  };
  return { page, state };
}

function fakeContext(pages: () => unknown[]): BrowserContext {
  return { pages } as unknown as BrowserContext;
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

  // Codex on 332c534..58512ea (P1s): no false success, and no endless scroll loop.
  it('throws when a confirm click is rejected, instead of counting it', async () => {
    const stuck = fakePrompt('confirm-footer-button', { clickError: 'locator.click: element is not attached to the DOM' });
    const log: PromptLog = { clicks: [], warnings: [] };
    await expect(
      confirmPrompts(fakeContext(() => [stuck.page]), EXT, 'rejected', log, { idleMs: 500, timeoutMs: 5_000 }),
    ).rejects.toThrow('element is not attached to the DOM');
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
});
