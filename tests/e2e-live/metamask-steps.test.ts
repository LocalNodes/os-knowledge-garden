// Unit guard for confirmPrompts: MetaMask closes its
// prompt window after a confirm, sometimes while confirmPrompts is still waiting on that page.
// The loop must treat a closed prompt as done, look for the next one, and never call a method
// on a closed page. These fakes model only what confirmPrompts touches.
import { describe, expect, it } from 'vitest';
import type { BrowserContext } from '@playwright/test';
import { confirmPrompts, promptRefusal, SETUP_SIGN_PROMPT, SHARE_PROMPT, SIGN_PROMPT, SWITCH_PROMPT, type ExpectedPrompt, type PromptLog } from './metamask-steps';
import { forSite } from './governance/judge-wallet';

const EXT = 'fakeextensionid';
const CLOSED = 'page.waitForTimeout: Target page, context or browser has been closed';
const SCROLL = 'confirm-scroll-to-bottom__button';

interface FakePage {
  closed: boolean;
  confirms: number;
  /** Calls made after the page closed; each one throws, and the test requires none. */
  callsAfterClose: string[];
  /** Every call made, by name. */
  calls: string[];
}

/**
 * A MetaMask prompt page with one confirm button. After the button is clicked, the page
 * closes during the next call made on it (as the real window closes during the settle wait),
 * so it still reports open when confirmPrompts next finds it. Make the racing call one that
 * isn't caught (the old waitForTimeout) and the error escapes, as in the CI run.
 */
function fakePrompt(
  button: string | null,
  opts: { clickError?: string; scrollStays?: boolean; closeOnClick?: boolean; closeBeforeClick?: boolean; stays?: boolean; nextTexts?: string[]; drift?: boolean; hang?: string; route?: string; text?: string; urlTail?: string } = {},
): { page: unknown; state: FakePage } {
  const state: FakePage = { closed: false, confirms: 0, callsAfterClose: [], calls: [] };
  // `stays`: the window stays open on the same screen after the confirm. `nextTexts`: the
  // window stays open and shows each of these requests in turn, one per confirm. `drift`: the
  // screen's incidental text (a fee estimate) changes on every read; the request stays the same.
  const texts = [opts.text ?? 'Setting up the group fund\nConfirm', ...(opts.nextTexts ?? [])];
  let closing = false;
  let reads = 0;
  const current = () => Math.min(state.confirms, texts.length - 1);
  // The one call that races the close throws but isn't counted: no caller can avoid it.
  // Every call after that, once isClosed() already says true, is counted.
  // `hang`: the call of this name never settles, as on a prompt window that stopped answering.
  const never = new Promise<never>(() => {});
  const guard = (name: string): void => {
    state.calls.push(name);
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
    if (name === opts.hang) return never;
    return value;
  };
  const locator = (id: string) => ({
    // `button: null` is a prompt with no confirm button (it stays unconfirmed); `scrollStays`
    // keeps the scroll-to-bottom button visible whatever is clicked.
    isVisible: () => call(`isVisible(${id})`, id === button || (!!opts.scrollStays && id === SCROLL)),
    isEnabled: async (_o?: unknown) => {
      const enabled = await call(`isEnabled(${id})`, true);
      // The window closes right after the check, before the confirm is clicked (rejected
      // elsewhere, or closed by MetaMask), so the click is skipped.
      if (opts.closeBeforeClick && id === button) state.closed = true;
      return enabled;
    },
    check: () => call(`check(${id})`, undefined),
    click: async (_o?: unknown) => {
      guard(`click(${id})`);
      if (`click(${id})` === opts.hang) return never;
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
    url: () => `chrome-extension://${EXT}/notification.html#/${opts.route ?? 'confirm-transaction'}/request-${current()}${opts.urlTail ?? ''}`,
    // Opened by the rescue: it is already on the prompt page.
    goto: async () => undefined,
    close: async () => {
      state.closed = true;
    },
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

  it('opens the prompt page before any click when asked to', async () => {
    const opened: Array<{ closed: boolean }> = [];
    const newPage = async () => {
      const blank = fakeBlankPage();
      opened.push(blank.state);
      return blank.page;
    };
    const log: PromptLog = { clicks: [], warnings: [] };
    await expect(
      confirmPrompts(fakeContext(() => [], newPage), EXT, 'switch', log, { done: () => false, timeoutMs: 3_000, reopenIdleMs: 300, reopenBeforeClick: true, expect: SWITCH_PROMPT }),
    ).rejects.toThrow('switch: not done within 3000 ms (0 MetaMask click(s)');
    expect(opened.length).toBeGreaterThan(0);
    expect(opened.every((o) => o.closed)).toBe(true);
  }, 30_000);

  // The reopened page shows whatever request is pending. A transaction waiting there during a
  // network switch must not be confirmed by the switch's call.
  it('refuses a pending transaction that the reopened page shows during a switch', async () => {
    const tx = fakePrompt('confirm-footer-button', { route: 'confirm-transaction' });
    let opened = false;
    const newPage = async () => {
      opened = true;
      return tx.page;
    };
    const log: PromptLog = { clicks: [], warnings: [] };
    await expect(
      confirmPrompts(fakeContext(() => (opened ? [tx.page] : []), newPage), EXT, 'switchNetwork', log, {
        done: () => false,
        timeoutMs: 10_000,
        reopenIdleMs: 300,
        reopenBeforeClick: true,
        expect: SWITCH_PROMPT,
      }),
    ).rejects.toThrow('switchNetwork: the open wallet prompt is a confirm-transaction request, not the expected confirmation request; it was not confirmed');
    expect(opened).toBe(true);
    expect(tx.state.confirms).toBe(0);
    expect(tx.state.calls.filter((c) => c.startsWith('click(') || c.startsWith('check('))).toEqual([]);
    expect(log.clicks).toEqual([]);
    // The page it opened is closed on the way out.
    expect(tx.state.closed).toBe(true);
  }, 30_000);

  it('refuses a signature prompt already open during a share request', async () => {
    const sig = fakePrompt('confirm-footer-button', { route: 'confirm-transaction' });
    const log: PromptLog = { clicks: [], warnings: [] };
    await expect(
      confirmPrompts(fakeContext(() => [sig.page]), EXT, 'shareAccount', log, { done: () => false, timeoutMs: 10_000, reopenIdleMs: 300, reopenBeforeClick: true, expect: SHARE_PROMPT }),
    ).rejects.toThrow('shareAccount: the open wallet prompt is a confirm-transaction request, not the expected connect request');
    expect(sig.state.confirms).toBe(0);
  }, 30_000);

  it('confirms the expected switch prompt that the reopened page shows', async () => {
    const sw = fakePrompt('confirmation-submit-button', { route: 'confirmation' });
    let opened = false;
    const newPage = async () => {
      opened = true;
      return sw.page;
    };
    const log: PromptLog = { clicks: [], warnings: [] };
    await confirmPrompts(fakeContext(() => (opened ? [sw.page] : []), newPage), EXT, 'switchNetwork', log, {
      done: () => sw.state.confirms > 0,
      timeoutMs: 10_000,
      reopenIdleMs: 300,
      reopenBeforeClick: true,
      expect: SWITCH_PROMPT,
    });
    expect(sw.state.confirms).toBe(1);
    expect(log.clicks.map((c) => c.button)).toEqual(['confirmation-submit-button']);
  }, 30_000);

  it('presses only the expected buttons on the expected request', async () => {
    // The right route, but a button that confirms sends and signatures: never pressed.
    const odd = fakePrompt('confirm-footer-button', { route: 'confirmation' });
    const log: PromptLog = { clicks: [], warnings: [] };
    await expect(
      confirmPrompts(fakeContext(() => [odd.page]), EXT, 'switchNetwork', log, { done: () => false, timeoutMs: 3_000, reopenIdleMs: 300, reopenBeforeClick: true, expect: SWITCH_PROMPT }),
    ).rejects.toThrow('switchNetwork: not done within 3000 ms (0 MetaMask click(s)');
    expect(odd.state.confirms).toBe(0);
  }, 30_000);

  it('will not open the prompt page without an expected request', async () => {
    const log: PromptLog = { clicks: [], warnings: [] };
    await expect(
      confirmPrompts(fakeContext(() => []), EXT, 'switch', log, { done: () => false, timeoutMs: 3_000, reopenIdleMs: 300, reopenBeforeClick: true }),
    ).rejects.toThrow("switch: opening the wallet's prompt page needs the expected request");
  }, 30_000);

  it('does not open the prompt page before a click unless asked to', async () => {
    let calls = 0;
    const newPage = async () => {
      calls++;
      return fakeBlankPage().page;
    };
    const log: PromptLog = { clicks: [], warnings: [] };
    await expect(
      confirmPrompts(fakeContext(() => [], newPage), EXT, 'plain', log, { done: () => false, timeoutMs: 3_000, reopenIdleMs: 300 , expect: SIGN_PROMPT }),
    ).rejects.toThrow('plain: not done within 3000 ms');
    expect(calls).toBe(0);
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
      confirmPrompts(fakeContext(() => [first.page], newPage), EXT, 'rescue', log, { done: () => false, idleMs: 500, timeoutMs: 6_000, reopenIdleMs: 300 , expect: SIGN_PROMPT }),
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
      confirmPrompts(fakeContext(() => [first.page], newPage), EXT, 'stuck close', log, { done: () => opened.length > 0, idleMs: 500, timeoutMs: 20_000, reopenIdleMs: 300 , expect: SIGN_PROMPT }),
    ).rejects.toThrow('stuck close: a wallet page opened here could not be closed');
  }, 30_000);

  it('bounds a close that never settles', async () => {
    const first = fakePrompt('confirm-footer-button');
    const newPage = async () => fakeBlankPage({ close: 'hangs' }).page;
    const log: PromptLog = { clicks: [], warnings: [] };
    await expect(
      confirmPrompts(fakeContext(() => [first.page], newPage), EXT, 'hung close', log, { done: () => false, idleMs: 500, timeoutMs: 6_000, reopenIdleMs: 300 , expect: SIGN_PROMPT }),
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
      confirmPrompts(fakeContext(() => [first.page], newPage), EXT, 'slow open', log, { done: () => false, idleMs: 500, timeoutMs: 5_000, reopenIdleMs: 300 , expect: SIGN_PROMPT }),
    ).rejects.toThrow("slow open: the wallet's prompt page did not open within");
    expect(Date.now() - began).toBeLessThan(8_000);
    expect(calls).toBe(1);
    // The page appears 9 s after it was asked for; give its close a moment after that.
    await new Promise((r) => setTimeout(r, askedAt + 9_500 - Date.now()));
    expect(late.state.closed).toBe(true);
  }, 30_000);

  // A prompt window that stops answering: one call on it never settles. Each case must end with
  // the "not done" error near the deadline, never hang. On the old code these hung until the
  // vitest timeout.
  const BUTTON = 'confirm-footer-button';
  const ACK = 'alert-modal-acknowledge-checkbox';
  const HANGS = ['waitForLoadState', 'innerText', `isVisible(${SCROLL})`, `isVisible(${ACK})`, `isVisible(${BUTTON})`, `isEnabled(${BUTTON})`];
  for (const hang of HANGS) {
    it(`ends near the deadline when ${hang} never settles`, async () => {
      const prompt = fakePrompt(BUTTON, { hang });
      const log: PromptLog = { clicks: [], warnings: [] };
      const began = Date.now();
      await expect(
        confirmPrompts(fakeContext(() => [prompt.page]), EXT, 'stuck window', log, { done: () => false, idleMs: 500, timeoutMs: 3_000 }),
      ).rejects.toThrow(/^stuck window: not done within 3000 ms \(0 MetaMask click\(s\); .*; the prompt window stopped answering [1-9]\d* time\(s\)\)$/);
      expect(Date.now() - began).toBeLessThan(3_000 + 8_000);
      expect(prompt.state.confirms).toBe(0);
      expect(log.clicks).toEqual([]);
    }, 20_000);
  }

  it('says the prompt is not answering when its state check never settles', async () => {
    const prompt = fakePrompt(BUTTON, { hang: `isVisible(${BUTTON})` });
    const log: PromptLog = { clicks: [], warnings: [] };
    await expect(
      confirmPrompts(fakeContext(() => [prompt.page]), EXT, 'stuck state', log, { done: () => false, idleMs: 500, timeoutMs: 3_000 }),
    ).rejects.toThrow('a prompt is open and not answering');
  }, 20_000);

  it('presses a click that never settles only once, and does not count it', async () => {
    const prompt = fakePrompt(BUTTON, { hang: `click(${BUTTON})` });
    const log: PromptLog = { clicks: [], warnings: [] };
    const began = Date.now();
    await expect(
      confirmPrompts(fakeContext(() => [prompt.page]), EXT, 'stuck click', log, { done: () => false, idleMs: 500, timeoutMs: 8_000 }),
    ).rejects.toThrow(/^stuck click: not done within 8000 ms \(0 MetaMask click\(s\); a prompt is open, showing confirm-footer-button; the prompt window stopped answering 1 time\(s\)\)$/);
    expect(Date.now() - began).toBeLessThan(8_000 + 8_000);
    expect(prompt.state.calls.filter((c) => c === `click(${BUTTON})`)).toHaveLength(1);
    expect(log.clicks).toEqual([]);
  }, 30_000);

  it('treats a done() check that never settles as "not yet", and still ends at the deadline', async () => {
    const log: PromptLog = { clicks: [], warnings: [] };
    const began = Date.now();
    await expect(
      confirmPrompts(fakeContext(() => []), EXT, 'stuck done', log, { done: () => new Promise<boolean>(() => {}), idleMs: 500, timeoutMs: 3_000 }),
    ).rejects.toThrow(/^stuck done: not done within 3000 ms \(0 MetaMask click\(s\); no prompt open; the done check didn't answer 1 time\(s\)\)$/);
    expect(Date.now() - began).toBeLessThan(3_000 + 10_000 + 3_000);
  }, 30_000);
});

// Texts as MetaMask 13.13.1 showed them in the local recording (11-12, fresh3.test, anvil).
const REC = {
  share: 'F\nfresh3.test\nConnect this website with MetaMask\nAccounts\nPermissions\nAccount 1\nEdit accounts\nCancel\nConnect',
  switchNew: 'Review permissions\nfresh3.test is asking for your approval to:\nUse your enabled networks\nRequested now for\nS\nSepolia\nCancel\nConfirm',
  addNetwork: 'Add Rec Local\nA site is suggesting additional network details.\nRequest from\nfresh3.test\nNetwork\nRec Local\nRPC\nlocalhost:18546\nCancel\nConfirm',
  typedSign: 'Account 1\nSignature request\nNetwork\nBase Sepolia (local anvil)\nRequest from\nfresh3.test\nMessage\nPrimary type:\nSafeTx\nCancel\nConfirm',
  send: 'Transfer request\n0 ETH\nFrom\nAccount 1\nTo\nAccount 1\nNetwork\nBase Sepolia (local anvil)\nRequest from\nfresh3.test\nNetwork fee\nCancel\nConfirm',
};
const SITE = 'fresh3.test';
const SWITCH_TO_SEPOLIA: ExpectedPrompt = { ...SWITCH_PROMPT, origin: SITE, network: 'Sepolia' };
const SETUP_SIGN: ExpectedPrompt = { ...SETUP_SIGN_PROMPT, origin: SITE };

describe('promptRefusal (recorded MetaMask 13.13.1 prompts)', () => {
  it('accepts each expected request as recorded', () => {
    expect(promptRefusal({ ...SHARE_PROMPT, origin: SITE }, '#connect/x', REC.share)).toBeUndefined();
    expect(promptRefusal(SWITCH_TO_SEPOLIA, '#connect/x/confirm-permissions', REC.switchNew)).toBeUndefined();
    expect(promptRefusal(SETUP_SIGN, '#confirm-transaction/x/signature-request', REC.typedSign)).toBeUndefined();
  });
  it('names a site with a port by its host name, as the connect page shows it', () => {
    const page = { url: () => 'http://localhost:8080/user/login' };
    const share = forSite(SHARE_PROMPT, page);
    expect(share.origin).toBe('localhost');
    // The connect page as MetaMask titles a localhost:8080 site (hostname only).
    expect(promptRefusal(share, '#connect/x', REC.share.replace('fresh3.test', 'localhost'))).toBeUndefined();
    expect(promptRefusal(share, '#connect/x', REC.share.replace('fresh3.test', 'evil.example'))).toBe('it does not name the site localhost');
  });
  it('refuses a prompt from another site', () => {
    expect(promptRefusal({ ...SHARE_PROMPT, origin: SITE }, '#connect/x', REC.share.replace('fresh3.test', 'evil.example'))).toBe('it does not name the site fresh3.test');
  });
  it('refuses add-network, sharing, or another network during a switch', () => {
    expect(promptRefusal(SWITCH_TO_SEPOLIA, '#confirmation/x', REC.addNetwork)).toBe('it adds a network instead of switching');
    expect(promptRefusal(SWITCH_TO_SEPOLIA, '#connect/x', REC.share)).toBe('it shares an account instead of switching');
    expect(promptRefusal(SWITCH_TO_SEPOLIA, '#connect/x/confirm-permissions', REC.switchNew.replace('Sepolia', 'Ethereum'))).toBe('it does not name the network Sepolia');
  });
  it('refuses a send or an add-network during setup signing', () => {
    expect(promptRefusal(SETUP_SIGN, '#confirm-transaction/x', REC.send)).toBe('it is not a signature request (a send or a network request)');
    expect(promptRefusal(SETUP_SIGN, '#confirm-transaction/x', REC.addNetwork)).toBe('it is not a signature request (a send or a network request)');
  });
});

describe('confirmPrompts: same-route requests that are not the expected one', () => {
  const reopen = { done: () => false, timeoutMs: 3_000, reopenIdleMs: 300, reopenBeforeClick: true } as const;
  it('a prompt from another site is refused, unconfirmed', async () => {
    const other = fakePrompt('confirm-btn', { route: 'connect', text: REC.share.replace('fresh3.test', 'evil.example') });
    const log: PromptLog = { clicks: [], warnings: [] };
    await expect(confirmPrompts(fakeContext(() => [other.page]), EXT, 'shareAccount', log, { ...reopen, expect: { ...SHARE_PROMPT, origin: SITE } }))
      .rejects.toThrow(/^shareAccount: the open wallet prompt is not the expected request \(it does not name the site fresh3\.test\); it was not confirmed$/);
    // The prompt's own text (here the other site's name) stays out of the error and the CI log.
    await expect(confirmPrompts(fakeContext(() => [other.page]), EXT, 'shareAccount', log, { ...reopen, expect: { ...SHARE_PROMPT, origin: SITE } }))
      .rejects.toThrow(expect.objectContaining({ message: expect.not.stringContaining('evil.example') }));
    expect(other.state.confirms).toBe(0);
  }, 30_000);
  it('add-network during a switch is refused, unconfirmed', async () => {
    const add = fakePrompt('confirmation-submit-button', { route: 'confirmation', text: REC.addNetwork });
    const log: PromptLog = { clicks: [], warnings: [] };
    await expect(confirmPrompts(fakeContext(() => [add.page]), EXT, 'switchNetwork', log, { ...reopen, expect: SWITCH_TO_SEPOLIA }))
      .rejects.toThrow('(it adds a network instead of switching); it was not confirmed');
    expect(add.state.confirms).toBe(0);
  }, 30_000);
  it('a send during setup signing is refused, unconfirmed', async () => {
    const send = fakePrompt('confirm-footer-button', { route: 'confirm-transaction', text: REC.send });
    const log: PromptLog = { clicks: [], warnings: [] };
    await expect(confirmPrompts(fakeContext(() => [send.page]), EXT, 'approveSetup', log, { ...reopen, expect: SETUP_SIGN }))
      .rejects.toThrow('(it is not a signature request (a send or a network request)); it was not confirmed');
    expect(send.state.confirms).toBe(0);
  }, 30_000);
  it('the setup signature itself is confirmed', async () => {
    const sig = fakePrompt('confirm-footer-button', { route: 'confirm-transaction', text: REC.typedSign, urlTail: '/signature-request' });
    const log: PromptLog = { clicks: [], warnings: [] };
    await confirmPrompts(fakeContext(() => [sig.page]), EXT, 'approveSetup', log, { done: () => sig.state.confirms > 0, timeoutMs: 10_000, reopenIdleMs: 300, expect: SETUP_SIGN });
    expect(sig.state.confirms).toBe(1);
  }, 30_000);
  it('a switch to a new network on the connect route is confirmed', async () => {
    const sw = fakePrompt('page-container-footer-next', { route: 'connect', text: REC.switchNew, urlTail: '/confirm-permissions' });
    const log: PromptLog = { clicks: [], warnings: [] };
    await confirmPrompts(fakeContext(() => [sw.page]), EXT, 'switchNetwork', log, { done: () => sw.state.confirms > 0, timeoutMs: 10_000, reopenIdleMs: 300, expect: SWITCH_TO_SEPOLIA });
    expect(sw.state.confirms).toBe(1);
    expect(log.clicks.map((c) => c.button)).toEqual(['page-container-footer-next']);
  }, 30_000);
});
