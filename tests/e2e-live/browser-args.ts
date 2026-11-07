// Chromium arguments for the local MetaMask recording (21-45 Task A).
//
// "No Sepolia signing" is a mechanism, not a promise: every name fails to resolve except
// fresh3.test (mapped to 127.0.0.1), localhost and the site's ethers CDN. The anvil RPC is
// reached as localhost (see LOCAL_NETWORK). The rules apply to the whole browser, pages and
// the MetaMask extension alike. The fresh3.test rule comes before the catch-all: a mis-ordered
// list silently lets names through, and the "host block" test is what catches it.
//
// Used by both the cache build (cache-local.ts) and the recording (local-anvil.e2e.ts). The
// rules are never loosened to get past a MetaMask request that fails behind them: that is a
// stop-and-report.
export const HOST_RESOLVER_RULES =
  '--host-resolver-rules=MAP fresh3.test 127.0.0.1, MAP * ~NOTFOUND, EXCLUDE localhost, EXCLUDE cdn.jsdelivr.net';

/** DDEV's local certificate isn't trusted by Chromium. */
export const IGNORE_CERT_ERRORS = '--ignore-certificate-errors';

/** The arguments for a browser that loads the MetaMask extension from extensionPath. */
export function metaMaskBrowserArgs(extensionPath: string): string[] {
  return [
    `--disable-extensions-except=${extensionPath}`,
    `--load-extension=${extensionPath}`,
    HOST_RESOLVER_RULES,
    IGNORE_CERT_ERRORS,
  ];
}

/** Where the local wallet's MetaMask profile is cached (gitignored .cache-synpress). */
export const LOCAL_CACHE_NAME = 'local-anvil';

/**
 * The local chain MetaMask uses: Base Sepolia's id on the loopback anvil chain (21-39).
 *
 * The RPC is http://localhost:18546, not the IP literal: `MAP * ~NOTFOUND` also catches
 * 127.0.0.1 (a probe page got ERR_NAME_NOT_RESOLVED for http://127.0.0.1:<port>, while
 * http://localhost:<port> loaded), and `EXCLUDE localhost` is already in the rules. The rules
 * stay exactly as the plan wrote them.
 */
export const LOCAL_NETWORK = {
  name: 'Base Sepolia (local anvil)',
  rpcUrl: 'http://localhost:18546',
  chainId: 84532,
  symbol: 'ETH',
} as const;

/**
 * MetaMask account names: the imported first account (anvil dev 0) and the one the setup adds
 * (dev 1). MetaMask 13.13.1's "Add account" names it itself.
 */
export const ACCOUNT_DEV0 = 'Account 1';
export const ACCOUNT_DEV1 = 'Account 2';
