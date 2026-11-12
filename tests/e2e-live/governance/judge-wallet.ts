// The judge's account steps: which MetaMask account the site sees, and SIWE sign-in through the
// site's own button. Shared with the local rehearsal of every account switch, so both run the
// same code. Errors from the site's page carry a fixed category only (failure-dump.ts).
import { expect, type BrowserContext, type Page } from '@playwright/test';
import { categorized } from '../failure-dump';
import { confirmPrompts, openHome, SHARE_PROMPT, switchAccount, SWITCH_PROMPT, within, type ExpectedPrompt, type PromptLog } from '../metamask-steps';

/**
 * A request sent just after another prompt closed can wait in MetaMask with no window.
 * After this long with no prompt, the wallet's prompt page is opened to show it.
 */
export const REOPEN = { reopenIdleMs: 15_000, reopenBeforeClick: true } as const;

/** How long a read from the wallet through the site's page may take. */
export const WALLET_READ_MS = 30_000;

export const fixed = (e: unknown): never => {
  throw categorized(e);
};

/** `p`'s value; an error with a fixed message if it hasn't settled within `ms`. */
export async function bounded<T>(p: Promise<T>, ms: number, what: string): Promise<T> {
  const r = await within(p, ms);
  if (r === 'timeout') throw new Error(`${what} did not answer within ${ms} ms`);
  return r.value;
}

type Eip1193Window = { ethereum: { request(a: unknown): Promise<unknown> } };

/**
 * The expected prompt, with the site's host name, which the prompt must name. MetaMask
 * 13.13.1 titles its connect page with transformOriginToTitle, which is the URL's hostname:
 * no port, so a site on localhost:8080 shows as "localhost".
 */
export function forSite(prompt: ExpectedPrompt, page: Pick<Page, 'url'>): ExpectedPrompt {
  const host = /^https?:/.test(page.url()) ? new URL(page.url()).hostname : '';
  return host ? { ...prompt, origin: host } : prompt;
}

/** Network names MetaMask shows on a switch prompt, by chain id (hex, lower case). */
const NETWORK_NAMES: Record<string, string> = { '0xaa36a7': 'Sepolia' };

/** The network a switch prompt must name, when the judge knows the name. */
export function networkOf(chainId: string): { network?: string } {
  const name = NETWORK_NAMES[chainId.toLowerCase()];
  return name ? { network: name } : {};
}

/** One wallet request from the site's page, bounded, with a fixed error. */
async function pageRequest(page: Page, method: string, params: unknown[] | undefined, ms: number): Promise<unknown> {
  return bounded(
    page.evaluate(([m, p]) => (window as unknown as Eip1193Window).ethereum.request(p === undefined ? { method: m } : { method: m, params: p }), [method, params] as const).catch(fixed),
    ms,
    `the wallet (${method})`,
  );
}

/** What MetaMask shares with the site's page, in its order (the selected account first). */
export async function siteAccounts(page: Page): Promise<string[]> {
  return (await pageRequest(page, 'eth_accounts', undefined, WALLET_READ_MS)) as string[];
}

/** The network the site's page is on (eth_chainId, hex). */
export async function siteChain(page: Page): Promise<string> {
  return String(await pageRequest(page, 'eth_chainId', undefined, WALLET_READ_MS)).toLowerCase();
}

/** Asks MetaMask, from the site's page, to put this site on `chainId` (EIP-3326). */
export async function switchSiteChain(page: Page, w: JudgeWallet, chainId: string): Promise<void> {
  if ((await siteChain(page)) === chainId.toLowerCase()) return;
  let done = false;
  const ask = page
    .evaluate((id) => (window as unknown as Eip1193Window).ethereum.request({ method: 'wallet_switchEthereumChain', params: [{ chainId: id }] }), chainId)
    .catch(fixed)
    .finally(() => { done = true; });
  await Promise.all([ask, confirmPrompts(w.context, w.extensionId, 'switchNetwork', w.log, { done: () => done, timeoutMs: 120_000, ...REOPEN, expect: { ...forSite(SWITCH_PROMPT, page), ...networkOf(chainId) } })]);
  const now = await siteChain(page);
  if (now !== chainId.toLowerCase()) throw new Error(`the site is on network ${now}, not ${chainId}, after the switch`);
}

export interface JudgeWallet {
  context: BrowserContext;
  extensionId: string;
  password: string;
  log: PromptLog & { restoredNetwork?: number };
}

/**
 * Makes `name` (at `address`) the account MetaMask shares with this site: selects it in
 * MetaMask, and when the site sees another account first, withdraws the site's access
 * (wallet_revokePermissions, no prompt) and asks again (wallet_requestPermissions).
 *
 * MetaMask 13.13.1 answers a second wallet_requestPermissions from a site that already holds
 * an account with that same account, whatever is selected. After the
 * revoke, the request opens the first-connect prompt, which offers the selected account.
 */
export async function useAccount(page: Page, w: JudgeWallet, name: string, address: string): Promise<void> {
  console.log(`[judge] use ${name}: selecting it in the wallet`);
  const walletPage = await bounded(w.context.newPage(), 30_000, 'a new wallet page');
  try {
    await bounded(
      (async () => {
        await openHome(walletPage, w.extensionId, w.password);
        await switchAccount(walletPage, name);
      })(),
      120_000,
      'the wallet home page',
    );
  } finally {
    // Bounded, and never in place of an error from the steps above.
    if ((await within(walletPage.close(), 10_000).catch(() => 'timeout' as const)) === 'timeout') console.log('[judge] the wallet home page did not close within 10000 ms');
  }
  await bounded(page.bringToFront(), 10_000, 'bringing the site page to the front');
  if (!/^https?:/.test(page.url())) await page.goto('/user/login');
  console.log(`[judge] use ${name}: reading the accounts the site sees`);
  const shared = await siteAccounts(page);
  const already = shared[0]?.toLowerCase() === address.toLowerCase();
  console.log(`[judge] use ${name}: ${already ? 'already shared' : shared.length ? 'the site sees another account; withdrawing its access, then asking again' : 'asking the wallet to share it'}`);
  if (!already) {
    // Withdrawing the site's access may also drop the network it was on; put it back after.
    const chainBefore = shared.length ? await siteChain(page) : null;
    if (shared.length) {
      await pageRequest(page, 'wallet_revokePermissions', [{ eth_accounts: {} }], WALLET_READ_MS);
      const after = await siteAccounts(page);
      if (after.length) throw new Error(`use ${name}: the site still sees ${after.length} account(s) after its access was withdrawn`);
    }
    let done = false;
    const ask = page
      .evaluate(() => (window as unknown as Eip1193Window).ethereum.request({ method: 'wallet_requestPermissions', params: [{ eth_accounts: {} }] }))
      .catch(fixed)
      .finally(() => { done = true; });
    await Promise.all([ask, confirmPrompts(w.context, w.extensionId, 'shareAccount', w.log, { done: () => done, timeoutMs: 120_000, ...REOPEN, expect: forSite(SHARE_PROMPT, page) })]);
    console.log(`[judge] use ${name}: the wallet answered the share request`);
    if (chainBefore !== null && (await siteChain(page)) !== chainBefore) {
      console.log(`[judge] use ${name}: the site's network changed with its access; switching it back`);
      await switchSiteChain(page, w, chainBefore);
      w.log.restoredNetwork = (w.log.restoredNetwork ?? 0) + 1;
    }
  }
  expect((await siteAccounts(page))[0]?.toLowerCase(), `MetaMask shares ${name} with the site first`).toBe(address.toLowerCase());
}

/** SIWE through the site's own button (copied from live-sepolia.e2e.ts). */
export async function signInWithMetaMask(page: Page, w: JudgeWallet): Promise<void> {
  console.log('[judge] sign in: opening the sign-in page');
  await page.goto('/user/login');
  const button = page.locator('#siwe-login-button');
  await button.waitFor({ state: 'visible' });
  let navigated = false;
  const nav = page
    .waitForEvent('framenavigated', { predicate: (f) => f === page.mainFrame(), timeout: 180_000 })
    .then(() => { navigated = true; }, () => undefined);
  console.log('[judge] sign in: pressing the sign-in button');
  await button.click();
  await confirmPrompts(w.context, w.extensionId, 'signIn', w.log, { done: () => navigated, timeoutMs: 180_000 });
  await nav;
  console.log('[judge] sign in: the page moved on');
  await page.waitForLoadState('load');
  await expect(page.locator('#siwe-login-button')).toHaveCount(0);
}

/** Signs out of the site (its session cookie), then signs in as `name` with SIWE. */
export async function signInAs(page: Page, w: JudgeWallet, name: string, address: string): Promise<void> {
  await w.context.clearCookies();
  await page.goto('/user/login');
  await useAccount(page, w, name, address);
  await signInWithMetaMask(page, w);
}
