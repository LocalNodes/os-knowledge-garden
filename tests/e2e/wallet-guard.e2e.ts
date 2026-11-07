// The wallet guard test (21-41): every refusal of the test wallet fires.
//
// Each case is one guard of the safety rule in wallet/test-wallet.ts. Eight planted
// mutations, one per guard, each turned a named case red and were reverted; the log is in
// .planning/phases/21-real-safe-reads-balances/gates/e2e-wallet-guard-proof.txt.
// Case (11) is the control: a signature by the selected account works, so the refusals
// aren't a broken wallet.
import { expect, test, type Frame, type Page } from '@playwright/test';
import {
  assertSuiteFork,
  forkRpc,
  devAccounts,
  installTestWallet,
  parseWalletError,
  readForksRun,
  TestWalletRefused,
  type ForkRunEntry,
} from './wallet/test-wallet';

const NON_TEST_URL = 'https://fresh3-e2e.ddev.site';

const network = (): string => (JSON.parse(process.env.LN_E2E ?? '{}') as { network?: string }).network ?? 'ethereum_sepolia';

function realEntry(): ForkRunEntry {
  const entry = readForksRun()[network()];
  if (!entry) throw new Error(`no run state for ${network()}`);
  return entry;
}

interface Outcome { ok: boolean; result?: unknown; code?: number; reason?: string; message?: string }

/** window.ethereum.request in the page, with the error's code and reason. */
async function pageRequest(page: Page, method: string, params: unknown[] = []): Promise<Outcome> {
  return page.evaluate(async ({ method, params }) => {
    const eth = (window as unknown as { ethereum?: { request(a: unknown): Promise<unknown> } }).ethereum;
    if (!eth) return { ok: false, message: 'no window.ethereum' };
    try {
      return { ok: true, result: await eth.request({ method, params }) };
    } catch (e) {
      const err = e as { code?: number; reason?: string; data?: { reason?: string }; message?: string };
      return { ok: false, code: err.code, reason: err.data?.reason ?? err.reason, message: String(err.message) };
    }
  }, { method, params });
}

/** Calls the bridge binding directly from a frame: what any script there could do. */
async function bindingFromFrame(frame: Frame): Promise<{ hasEthereum: boolean; ok: boolean; message: string }> {
  return frame.evaluate(async () => {
    const w = window as unknown as { ethereum?: unknown; __lnTestWallet?: (p: unknown) => Promise<unknown> };
    const hasEthereum = typeof w.ethereum !== 'undefined';
    try {
      const r = await w.__lnTestWallet!({ method: 'eth_accounts', params: [] });
      return { hasEthereum, ok: true, message: JSON.stringify(r) };
    } catch (e) {
      return { hasEthereum, ok: false, message: String((e as Error).message) };
    }
  });
}

/** A spy fetch answering as a node would; it records every call. */
function spyFetch(answers: Record<string, unknown>): { fetchImpl: typeof fetch; calls: string[] } {
  const calls: string[] = [];
  const fetchImpl = (async (_url: unknown, init?: { body?: unknown }) => {
    const body = JSON.parse(String(init?.body ?? '{}')) as { method: string };
    calls.push(body.method);
    return new Response(JSON.stringify({ jsonrpc: '2.0', id: 1, result: answers[body.method] ?? null }), {
      headers: { 'content-type': 'application/json' },
    });
  }) as unknown as typeof fetch;
  return { fetchImpl, calls };
}

async function refusal(p: Promise<unknown>): Promise<TestWalletRefused> {
  try {
    await p;
  } catch (e) {
    if (e instanceof TestWalletRefused) return e;
    throw e;
  }
  throw new Error('expected TestWalletRefused, but the call succeeded');
}

test('(1) host-page: no wallet and a 4100 host refusal on a non-.test page', async ({ context, page }) => {
  await installTestWallet(context, { network: network(), account: 0 });
  await page.goto(`${NON_TEST_URL}/user/login`);
  const r = await bindingFromFrame(page.mainFrame());
  expect(r.hasEthereum).toBe(false);
  expect(r.ok).toBe(false);
  const err = parseWalletError(r.message);
  expect(err?.code).toBe(4100);
  expect(err?.reason).toBe('host');

  // A fresh3.test frame inside the non-.test page: the frame passes its own host check,
  // so only the bridge's page check can refuse it.
  const inner = 'https://fresh3.test/robots.txt';
  await page.evaluate((src) => {
    const f = document.createElement('iframe');
    f.src = src;
    document.body.appendChild(f);
  }, inner);
  await expect.poll(() => page.frames().some((f) => f.url() === inner), { timeout: 15_000 }).toBe(true);
  const testFrame = page.frames().find((f) => f.url() === inner)!;
  await testFrame.waitForLoadState('load');
  const fr = await bindingFromFrame(testFrame);
  expect(fr.ok, `fresh3.test frame in a non-.test page: ${fr.message}`).toBe(false);
  const ferr = parseWalletError(fr.message);
  expect(ferr?.code).toBe(4100);
  expect(ferr?.reason).toBe('host');
});

test('(2) host-frame: a data: iframe and a cross-host iframe get no wallet and a 4100 host refusal', async ({ context, page }) => {
  await installTestWallet(context, { network: network(), account: 0 });
  await page.goto('/user/login');
  // Cross-host frame: robots.txt, because Drupal pages send X-Frame-Options SAMEORIGIN.
  await page.evaluate((crossHost) => {
    for (const src of ['data:text/html,<p>data frame</p>', crossHost]) {
      const f = document.createElement('iframe');
      f.src = src;
      document.body.appendChild(f);
    }
  }, `${NON_TEST_URL}/robots.txt`);
  // A new iframe starts at about:blank; wait until each frame has its own URL and has loaded.
  const frameAt = async (prefix: string): Promise<Frame> => {
    await expect.poll(() => page.frames().some((f) => f.url().startsWith(prefix)), { timeout: 15_000 }).toBe(true);
    const frame = page.frames().find((f) => f.url().startsWith(prefix))!;
    await frame.waitForLoadState('load');
    return frame;
  };
  const dataFrame = await frameAt('data:');
  const crossFrame = await frameAt(NON_TEST_URL);
  for (const frame of [dataFrame, crossFrame]) {
    const r = await bindingFromFrame(frame);
    expect(r.hasEthereum, frame.url()).toBe(false);
    expect(r.ok, frame.url()).toBe(false);
    const err = parseWalletError(r.message);
    expect(err?.code, `${frame.url()}: ${r.message}`).toBe(4100);
    expect(err?.reason, frame.url()).toBe('host');
  }
  // The top page itself still has the wallet.
  expect(await page.evaluate(() => typeof (window as unknown as { ethereum?: unknown }).ethereum)).toBe('object');
});

test('(3) non-loopback: a non-loopback or remote chain is refused before any network call', async () => {
  const real = realEntry();
  const anvilAnswers = {
    anvil_metadata: { clientVersion: 'anvil/v1.8.6', instanceId: real.instanceId },
    eth_chainId: `0x${real.chainId.toString(16)}`,
  };
  const spy = spyFetch(anvilAnswers);
  const e1 = await refusal(assertSuiteFork(network(), { ...real, hostUrl: 'http://192.0.2.10:18545' }, { fetchImpl: spy.fetchImpl }));
  expect(e1.reason).toBe('fork');
  expect(e1.message).toContain('not a loopback suite chain');
  const e2 = await refusal(assertSuiteFork(network(), { ...real, remote: true }, { fetchImpl: spy.fetchImpl }));
  expect(e2.reason).toBe('fork');
  expect(e2.message).toContain('not a loopback suite chain');
  expect(spy.calls.length).toBe(0);
});

test('(4) not-anvil: a node that isn\'t anvil is refused', async () => {
  const real = realEntry();
  const spy = spyFetch({
    anvil_metadata: { clientVersion: 'Geth/v1.14.11-stable/linux-amd64/go1.23.2', instanceId: real.instanceId },
    eth_chainId: `0x${real.chainId.toString(16)}`,
  });
  const e = await refusal(assertSuiteFork(network(), real, { fetchImpl: spy.fetchImpl }));
  expect(e.reason).toBe('fork');
  expect(e.message).toContain('not an anvil node');
});

test('(5) wrong-instance: an anvil chain this suite didn\'t start is refused', async () => {
  const real = realEntry();
  const spy = spyFetch({
    anvil_metadata: { clientVersion: 'anvil/v1.8.6', instanceId: `0x${'ab'.repeat(32)}` },
    eth_chainId: `0x${real.chainId.toString(16)}`,
  });
  const e = await refusal(assertSuiteFork(network(), real, { fetchImpl: spy.fetchImpl }));
  expect(e.reason).toBe('fork');
  expect(e.message).toContain('instanceId');
});

test('(6) wrong-chain: the running chain with a different recorded chain id is refused', async () => {
  const real = realEntry();
  const e = await refusal(assertSuiteFork(network(), { ...real, chainId: 1 }));
  expect(e.reason).toBe('chain');
  expect(e.message).toContain("isn't the recorded 1");
});

test('(7) swapped-mid-session: a signature is refused once the chain isn\'t the one installed', async ({ context, page }) => {
  const wallet = await installTestWallet(context, { network: network(), account: 0 });
  await page.goto('/user/login');
  wallet.overrideExpectedInstanceIdForTest('0xdead');
  const r = await pageRequest(page, 'personal_sign', ['0x68656c6c6f', wallet.address.toLowerCase()]);
  expect(r.ok).toBe(false);
  expect(r.code).toBe(4100);
  expect(r.reason).toBe('fork');
  expect(wallet.signedMessages).toHaveLength(0);
});

test('(8) no-chain-control: chain-control and raw-signing methods are refused with 4200', async ({ context, page }) => {
  const wallet = await installTestWallet(context, { network: network(), account: 0 });
  await page.goto('/user/login');
  const before = await forkRpc(network(), 'eth_getBalance', [wallet.address, 'latest']);
  const r = await pageRequest(page, 'anvil_setBalance', [wallet.address, '0x1']);
  expect(r.ok).toBe(false);
  expect(r.code).toBe(4200);
  expect(r.reason).toBe('method');
  expect(await forkRpc(network(), 'eth_getBalance', [wallet.address, 'latest'])).toBe(before);
  for (const method of ['evm_mine', 'hardhat_mine', 'debug_traceTransaction', 'eth_sign', 'eth_signTransaction', 'eth_sendRawTransaction', 'anvil_impersonateAccount']) {
    const m = await pageRequest(page, method, []);
    expect(m.code, method).toBe(4200);
  }
});

test('(9) wrong-account: a send from another account is refused with 4100', async ({ context, page }) => {
  const wallet = await installTestWallet(context, { network: network(), account: 0 });
  await page.goto('/user/login');
  const accounts = await devAccounts(network());
  const r = await pageRequest(page, 'eth_sendTransaction', [{ from: accounts[5], to: accounts[6], value: '0x1' }]);
  expect(r.ok).toBe(false);
  expect(r.code).toBe(4100);
  expect(r.reason).toBe('account');
  expect(wallet.address).toBe(accounts[0]);
});

test('(10) switch-away: switching to a chain that isn\'t a suite chain is refused with 4902', async ({ context, page }) => {
  const wallet = await installTestWallet(context, { network: network(), account: 0 });
  await page.goto('/user/login');
  const r = await pageRequest(page, 'wallet_switchEthereumChain', [{ chainId: '0x1' }]);
  expect(r.ok).toBe(false);
  expect(r.code).toBe(4902);
  const id = await pageRequest(page, 'eth_chainId');
  expect(id.result).toBe(wallet.chainIdHex);
});

test('(11) control: the selected account signs on fresh3.test', async ({ context, page }) => {
  const wallet = await installTestWallet(context, { network: network(), account: 0 });
  await page.goto('/user/login');
  const r = await pageRequest(page, 'personal_sign', ['0x68656c6c6f', wallet.address.toLowerCase()]);
  expect(r.ok, r.message).toBe(true);
  expect(String(r.result)).toMatch(/^0x[0-9a-f]{130}$/);
  expect(wallet.signedMessages).toEqual(['hello']);
});
