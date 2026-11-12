// Lost hashes and dropped sends on the local chain (21-25 fixes 15 to 17, recorded at 21-30).
//
// Three flows on this run's own "Recovery" groups (seed-e2e.php --groups=<runId>-<network>-recovery):
//   - set-up adopted (fix 16): the group fund set-up's update-deployment posts are blocked, so
//     the deploy lands but its hash never reaches the site. A reload of the group tab finds the
//     fund at its predicted CREATE2 address, with the stored owners and threshold, and
//     activates it.
//   - dropped send (fix 15): automine off, the wallet sends, the hash reaches the site, then
//     anvil drops the transaction from its mempool. A reload notes the block; N blocks later
//     (the registry entry's drop_after_blocks) a reload offers "Send payment" again with a
//     plain note, and the second send reaches "Sent".
//   - lost send hash (fixes 17 and 18): the /execute posts are blocked, so the send lands but
//     the site never gets its hash. Once the fund's nonce has moved, the fund tab's payments
//     list looks for the Safe's execution of this payment before anyone opens its page (fix
//     18), so its row says "Sent", not "Waiting for confirmation"; the payment page then shows
//     "Sent" with that transaction, not "Replaced". (Since fix 18 the list settles it first;
//     the page's own search, fix 17, is covered by the Kernel tests.)
//
// The organizer (dev account 0) and the approver (dev account 1) run a 2-of-2 fund. No chain id
// or contract address is written here: they come from the run state, the site's registry
// entry and the anvil dev accounts. Lane rules (R-D): only anvil dev accounts sign, only on the
// local anvil chains, only for the .test host.
import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { devAccounts, e2eBaseUrl, installTestWallet, toChecksumAddress } from './wallet/test-wallet';
import {
  cast,
  code,
  e2eRun,
  ethBalance,
  owners,
  receipt,

  registry,
  safeNonce,
  sendEth,
  setAutomine,
  threshold,
  type E2eRun,
  type RegistryEntry,
} from './helpers/chain';
import {
  approvePayment,
  flow,
  injectedDriver,
  openFund,
  paymentId,
  paymentStatus,
  proposePayment,
  readPayment,
  receiptPanel,
  refreshBalances,
  safeAddressForGroup,
  seedRunGroups,
  sendPayment,
  setUpGroupFund,
  signIn,
  type RunGroups,
} from './helpers/treasury';

const FUND_WEI = 10n ** 16n; // 0.01 ETH into the fund
const DROPPED_NOTE = "The last try to send this payment didn't go through, so no money moved. It can be sent again.";
const WAITING = 'Waiting for the public record to confirm this.';
// ChainTiming::DEFAULT_DROP_AFTER_BLOCKS, for an entry without drop_after_blocks.
const DEFAULT_DROP_AFTER_BLOCKS = 300;

let run: E2eRun;
let groups: RunGroups;
let net: RegistryEntry & { drop_after_blocks?: number };
let devs: string[] = [];
let fund = '';

/** Signs a fresh context in as dev account `account`. */
async function signedIn(context: BrowserContext, page: Page, account: number): Promise<void> {
  const wallet = await installTestWallet(context, { network: run.network, account });
  expect(wallet.address).toBe(devs[account]);
  await signIn(page);
}

/** The hash of the one pending transaction from `from` in anvil's mempool, or ''. */
function pendingHashFrom(from: string): string {
  const pool = JSON.parse(cast(run.network, ['rpc', 'txpool_content'])) as { pending?: Record<string, Record<string, { hash: string }>> };
  for (const [sender, byNonce] of Object.entries(pool.pending ?? {})) {
    if (sender.toLowerCase() !== from.toLowerCase()) continue;
    const txs = Object.values(byNonce);
    if (txs.length) return txs[0].hash;
  }
  return '';
}

/** The latest block number. */
function blockNumber(): number {
  return Number(cast(run.network, ['block-number']));
}

/** A payment approved by both organizers, ready to send. Returns its page URL. */
async function readyPayment(context: BrowserContext, page: Page, amountText: string, description: string): Promise<string> {
  await signedIn(context, page, 0);
  const url = await proposePayment(page, groups.pay, { to: devs[3], amountText, description });
  await approvePayment(page, url, injectedDriver);
  const other = await context.browser()!.newContext({ baseURL: e2eBaseUrl(), ignoreHTTPSErrors: true });
  try {
    const otherPage = await other.newPage();
    await signedIn(other, otherPage, 1);
    await approvePayment(otherPage, url, injectedDriver);
  } finally {
    await other.close();
  }
  return url;
}

/** Reloads a page and waits for it. */
async function reload(page: Page): Promise<void> {
  await page.reload();
  await page.waitForLoadState('load');
}

test.describe.serial('lost hashes and dropped sends on the local chain', () => {
  test.beforeAll(async () => {
    run = e2eRun();
    net = (await registry(run.network)) as RegistryEntry & { drop_after_blocks?: number };
    devs = await devAccounts(run.network);
    groups = seedRunGroups(run.runId, run.network, '-recovery');
  });

  test('set-up adopted after its hash was lost', async ({ context, page }, info) => {
    await flow(run.network, 'set-up adopted after its hash was lost', page, info, async (p, c) => {
      await signedIn(context, page, 0);
      // Every update-deployment post fails: the deploy lands, the site never hears of it.
      let blocked = 0;
      await page.route('**/safe-accounts/*/update-deployment', async (route) => {
        blocked += 1;
        await route.abort('failed');
      });
      await setUpGroupFund(page, groups.pay, { threshold: 2, driver: injectedDriver, network: net });
      // fix 12's copy: the wallet may have sent it; no button comes back. Wait for the final
      // copy, after the page's second post also failed, before the posts are let through again.
      await expect(receiptPanel(page)).toContainText(/we couldn't check it yet|on the public record, but/, { timeout: 120_000 });
      p.panelAfterLostHash = (await receiptPanel(page).innerText()).replace(/\s+/g, ' ').trim();
      p.updateDeploymentPostsBlocked = blocked;
      expect(blocked).toBeGreaterThan(0);
      const before = safeAddressForGroup(groups.pay);
      c.rowBeforeReload = before;
      expect(before?.status).toBe('pending');
      expect(before?.safe_address ?? '').toBe('');
      expect(before?.deployment_tx_hash ?? '').toBe('');
      await page.unroute('**/safe-accounts/*/update-deployment');

      // "Reload this page": the tab finds the fund at its predicted address.
      await openFund(page, groups.pay);
      const after = safeAddressForGroup(groups.pay);
      c.rowAfterReload = after;
      expect(after?.status).toBe('active');
      fund = toChecksumAddress(after!.safe_address);
      c.fund = fund;
      c.code = code(run.network, fund) !== '0x';
      c.owners = owners(run.network, fund);
      c.threshold = threshold(run.network, fund);
      expect(c.code).toBe(true);
      expect((c.owners as string[]).map((a) => a.toLowerCase()).sort()).toEqual([devs[0], devs[1]].map((a) => a.toLowerCase()).sort());
      expect(c.threshold).toBe(2);
      p.fundTabShowsFund = await page.getByText('Balances').first().isVisible();
      await sendEth(run.network, 0, fund, FUND_WEI);
      c.ethBalance = ethBalance(run.network, fund);
    });
  });

  test('dropped send can be sent again', async ({ context, page }, info) => {
    await flow(run.network, 'dropped send can be sent again', page, info, async (p, c) => {
      expect(fund, 'the adopted fund').toMatch(/^0x[0-9a-fA-F]{40}$/);
      const url = await readyPayment(context, page, '0.0001', `E2E dropped send ${run.runId}`);
      p.url = url;
      const id = paymentId(url);
      const nonce = safeNonce(run.network, fund);
      c.nonceBefore = nonce;
      const n = net.drop_after_blocks && net.drop_after_blocks > 0 ? net.drop_after_blocks : DEFAULT_DROP_AFTER_BLOCKS;
      c.dropAfterBlocks = n;

      setAutomine(run.network, false);
      try {
        const execute = page
          .waitForResponse((r) => r.request().method() === 'POST' && /\/transactions\/\d+\/execute$/.test(new URL(r.url()).pathname), { timeout: 20_000 })
          .then(() => true, () => false);
        await sendPayment(page, url, injectedDriver);
        await expect.poll(() => pendingHashFrom(devs[0]), { timeout: 30_000 }).toMatch(/^0x[0-9a-fA-F]{64}$/);
        const hash = pendingHashFrom(devs[0]);
        c.droppedHash = hash;
        expect(await execute, 'the page stored the send hash').toBe(true);
        expect(readPayment(id)?.blockchain_tx_hash).toBe(hash);
        // The node loses it.
        cast(run.network, ['rpc', 'anvil_dropTransaction', hash]);
        c.poolAfterDrop = pendingHashFrom(devs[0]);
        expect(c.poolAfterDrop).toBe('');
      } finally {
        setAutomine(run.network, true);
      }

      // First view: still waiting (the block height is noted).
      await reload(page);
      p.statusFirstView = await paymentStatus(page);
      p.waitingFirstView = await page.getByText(WAITING).first().isVisible();
      expect(p.waitingFirstView).toBe(true);
      c.blockFirstView = blockNumber();

      // N - 1 blocks on: no change yet.
      cast(run.network, ['rpc', 'anvil_mine', `0x${(n - 1).toString(16)}`]);
      await reload(page);
      p.sendShownUnderN = await page.getByRole('button', { name: 'Send payment', exact: true }).isVisible();
      expect(p.sendShownUnderN).toBe(false);

      // N blocks on: dropped, and the Safe is still at its nonce.
      cast(run.network, ['rpc', 'anvil_mine', '0x1']);
      c.blockDropped = blockNumber();
      await reload(page);
      p.statusAfterN = await paymentStatus(page);
      p.droppedNote = await page.getByText(DROPPED_NOTE).first().isVisible();
      p.sendShownAfterN = await page.getByRole('button', { name: 'Send payment', exact: true }).isVisible();
      c.rowAfterN = readPayment(id);
      expect(p.droppedNote).toBe(true);
      expect(p.sendShownAfterN).toBe(true);
      expect((c.rowAfterN as { blockchain_tx_hash: string | null }).blockchain_tx_hash ?? '').toBe('');
      expect(safeNonce(run.network, fund)).toBe(nonce);

      // Sent again: it goes through.
      await sendPayment(page, url, injectedDriver);
      await expect(page.locator('[data-safe-flow-panel]')).toContainText('Payment sent.', { timeout: 120_000 });
      await reload(page);
      p.statusAfterResend = await paymentStatus(page);
      c.rowAfterResend = readPayment(id);
      c.nonceAfter = safeNonce(run.network, fund);
      const sent = (c.rowAfterResend as { blockchain_tx_hash: string }).blockchain_tx_hash;
      c.resendReceipt = receipt(run.network, sent).status;
      expect(p.statusAfterResend).toBe('Sent');
      expect(c.nonceAfter).toBe(nonce + 1);
    });
  });

  test('lost send hash shows Sent, not Replaced', async ({ context, page }, info) => {
    await flow(run.network, 'lost send hash shows Sent, not Replaced', page, info, async (p, c) => {
      expect(fund, 'the adopted fund').toMatch(/^0x[0-9a-fA-F]{40}$/);
      const description = `E2E lost send hash ${run.runId}`;
      const url = await readyPayment(context, page, '0.0002', description);
      p.url = url;
      const id = paymentId(url);
      const nonce = safeNonce(run.network, fund);
      c.nonceBefore = nonce;
      const from = blockNumber();

      // Every /execute post fails: the send lands, the site never gets its hash.
      let blocked = 0;
      await page.route('**/transactions/*/execute', async (route) => {
        blocked += 1;
        await route.abort('failed');
      });
      await sendPayment(page, url, injectedDriver);
      // The final copy, after the page's second post also failed (a "Checking…" step comes
      // first; letting /execute through then would hand the site the hash after all).
      await expect(page.locator('[data-safe-flow-panel]')).toContainText(/we couldn't check it yet|moved past this one/, { timeout: 120_000 });
      p.panelAfterLostHash = (await page.locator('[data-safe-flow-panel]').innerText()).replace(/\s+/g, ' ').trim();
      p.executePostsBlocked = blocked;
      await page.unroute('**/transactions/*/execute');
      await expect.poll(() => safeNonce(run.network, fund), { timeout: 30_000 }).toBe(nonce + 1);
      c.nonceAfter = nonce + 1;
      c.rowBeforeReload = readPayment(id);
      expect((c.rowBeforeReload as { blockchain_tx_hash: string | null }).blockchain_tx_hash ?? '').toBe('');
      // The site's own record of the event, for the comparison below.
      const logs = JSON.parse(cast(run.network, ['logs', '--from-block', String(from), '--address', fund, '--json', 'ExecutionSuccess(bytes32,uint256)'])) as Array<{ transactionHash: string }>;
      c.executionSuccessTx = logs.at(-1)?.transactionHash ?? '';

      // The fund's chain read moves past the nonce (after 21-15's 15 s skip window).
      await openFund(page, groups.pay);
      await page.waitForTimeout(16_000);
      await refreshBalances(page);

      // Fix 18: the list, before anyone opens the payment's page.
      const row = page.locator('tr.safe-payment-row', { has: page.getByRole('link', { name: description, exact: true }) });
      await expect(row).toHaveCount(1);
      p.listStatus = (await row.locator('.safe-status-badge').innerText()).replace(/\s+/g, ' ').trim();
      c.rowAfterList = readPayment(id);
      expect(p.listStatus).toBe('Sent');
      expect((c.rowAfterList as { status: string }).status).toBe('executed');

      await page.goto(url);
      await page.waitForLoadState('load');
      p.status = await paymentStatus(page);
      c.rowAfterReload = readPayment(id);
      const stored = (c.rowAfterReload as { blockchain_tx_hash: string | null; status: string });
      expect(p.status).toBe('Sent');
      expect(stored.status).toBe('executed');
      expect((stored.blockchain_tx_hash ?? '').toLowerCase()).toBe(String(c.executionSuccessTx).toLowerCase());
    });
  });
});

