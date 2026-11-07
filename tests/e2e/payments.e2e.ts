// A group payment end to end on the local chain (21-43): propose, two approvals, send, and
// a reload while the send waits for its block. This is the payment half of 21-30 Run 2.
//
// The organizer (dev account 0) and the approver (dev account 1) run a 2-of-2 group fund on
// this run's "Pay" group. Each test has its own browser context, wallet and sign-in, so each
// has its own trace and video. Approvals are the suite's first eth_signTypedData_v4 calls.
// The page, the chain (read with cast) and the database must agree.
//
// No chain id or contract address is written here: they come from the run state, the
// site's active registry entry and the anvil dev accounts.
import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { devAccounts, e2eBaseUrl, installTestWallet, toChecksumAddress } from './wallet/test-wallet';
import {
  cast,
  e2eRun,
  ethBalance,
  mine,
  receipt,
  record,
  registry,
  safeNonce,
  sendEth,
  setAutomine,
  type E2eRun,
  type RegistryEntry,
} from './helpers/chain';
import {
  approvalsSentence,
  approvePayment,
  balanceText,
  flow,
  formatUnits,
  injectedDriver,
  networkRetries,
  openFund,
  paymentId,
  paymentPanel,
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
const PAY_WEI = 10n ** 15n; // the payment: 0.001 ETH
const WAIT_WEI = 10n ** 14n; // the reload-while-waiting payment: 0.0001 ETH
const WAITING = 'Waiting for the public record to confirm this.';

let run: E2eRun;
let groups: RunGroups;
let net: RegistryEntry;
let fund = '';
let recipient = '';
let devs: string[] = [];
let paymentUrl = '';
let nonceBefore = -1;
let recipientBefore = 0n;

/** ExecutionSuccess(bytes32 txHash, uint256 payment), from cast sig-event. */
function executionSuccessTopic(): string {
  return cast(run.network, ['sig-event', 'ExecutionSuccess(bytes32,uint256)']).toLowerCase();
}

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

/** The explorer tx link the registry builds for a hash. */
function explorerTxUrl(hash: string): string {
  const template = net.explorer?.tx_url ?? '';
  expect(template, 'the registry entry has an explorer tx_url').toContain('{hash}');
  return template.replace('{hash}', hash);
}

test.describe.serial('a group payment on the local chain', () => {
  test.beforeAll(async ({ browser }) => {
    run = e2eRun();
    net = await registry(run.network);
    devs = await devAccounts(run.network);
    recipient = devs[3];
    // The run's own "Pay" group: group-fund.e2e.ts sets up "Fund" in the same run.
    groups = seedRunGroups(run.runId, run.network);

    // Set up a 2-of-2 fund from the group tab (21-42's path) and put 0.01 ETH in it.
    // Playwright traces this context too (trace: 'on'); the hook's trace goes with the first test.
    const context = await browser.newContext({ baseURL: e2eBaseUrl(), ignoreHTTPSErrors: true });
    const page = await context.newPage();
    const p: Record<string, unknown> = { group: groups.pay };
    networkRetries.splice(0);
    const retries = (): Record<string, unknown> => (networkRetries.length ? { networkRetries: networkRetries.splice(0) } : {});
    const c: Record<string, unknown> = {};
    try {
      await signedIn(context, page, 0);
      await setUpGroupFund(page, groups.pay, { threshold: 2, driver: injectedDriver, network: net });
      await expect(receiptPanel(page).getByText('The group fund is ready.')).toBeVisible({ timeout: 120_000 });
      const row = safeAddressForGroup(groups.pay);
      expect(row?.status).toBe('active');
      fund = toChecksumAddress(row!.safe_address);
      await sendEth(run.network, 0, fund, FUND_WEI);
      c.fund = fund;
      c.ethBalance = ethBalance(run.network, fund);
      expect(c.ethBalance).toBe(FUND_WEI);
      record(run.network, 'pay set up', { result: 'pass', page: { ...p, ...retries() }, chain: c });
    } catch (err) {
      record(run.network, 'pay set up', { result: 'fail', page: { ...p, ...retries(), url: page.url() }, chain: c, finding: String((err as Error).message).slice(0, 2000) });
      throw err;
    } finally {
      await context.close();
    }
  });

  test('propose', async ({ context, page }, info) => {
    await flow(run.network, 'propose', page, info, async (p, c) => {
      expect(fund, 'beforeAll set up the fund').toMatch(/^0x[0-9a-fA-F]{40}$/);
      nonceBefore = safeNonce(run.network, fund);
      recipientBefore = ethBalance(run.network, recipient);
      c.nonceBefore = nonceBefore;
      c.recipientBefore = recipientBefore;

      await signedIn(context, page, 0);
      paymentUrl = await proposePayment(page, groups.pay, {
        to: recipient,
        amountText: '0.001',
        description: `E2E payment ${run.runId}`,
      });
      p.url = paymentUrl;
      await expect(page.getByText('Payment proposed. Organizers can now approve it.')).toBeVisible();
      p.message = 'Payment proposed. Organizers can now approve it.';
      p.approvals = await approvalsSentence(page);
      expect(p.approvals).toBe('Approved by 0 of 2. Needs 2.');
      p.status = await paymentStatus(page);

      // The stored amount must be exactly what was entered (the open DecimalItem rounding bug).
      const row = readPayment(paymentId(paymentUrl));
      c.row = row;
      expect(row?.value).toBe(PAY_WEI.toString());
      expect(row?.to_address.toLowerCase()).toBe(recipient.toLowerCase());
    });
  });

  test('approve (organizer)', async ({ context, page }, info) => {
    await flow(run.network, 'approve (organizer)', page, info, async (p, c) => {
      expect(paymentUrl, 'the propose test made the payment').not.toBe('');
      await signedIn(context, page, 0);
      p.panel = await approvePayment(page, paymentUrl, injectedDriver);
      expect(p.panel).toContain('You approved this payment.');
      expect(p.panel).toContain('Approved by 1 of 2.');
      c.row = readPayment(paymentId(paymentUrl));
      expect((c.row as { approvals: string[] }).approvals).toEqual([devs[0].toLowerCase()]);
    });
  });

  test('approve (approver)', async ({ context, page }, info) => {
    await flow(run.network, 'approve (approver)', page, info, async (p, c) => {
      expect(paymentUrl, 'the propose test made the payment').not.toBe('');
      await signedIn(context, page, 1);
      p.panel = await approvePayment(page, paymentUrl, injectedDriver);
      expect(p.panel).toContain('Approved by 2 of 2.');
      expect(p.panel).toContain("It's ready to send.");
      c.row = readPayment(paymentId(paymentUrl));
      expect(new Set((c.row as { approvals: string[] }).approvals)).toEqual(new Set([devs[0].toLowerCase(), devs[1].toLowerCase()]));
    });
  });

  test('send', async ({ context, page }, info) => {
    await flow(run.network, 'send', page, info, async (p, c) => {
      expect(paymentUrl, 'the propose test made the payment').not.toBe('');
      await signedIn(context, page, 0);
      // The organizer reloads the payment page: "Send payment" is now the primary button.
      await sendPayment(page, paymentUrl, injectedDriver);
      const panel = paymentPanel(page);
      // Wait for the panel's final state ("Payment sent." or the waiting line), so the chain
      // and database facts below are recorded whichever it is.
      await expect(panel).toContainText(/Payment sent\.|Waiting for the public record to confirm this\.|didn't go through/, { timeout: 120_000 });
      p.panel = (await panel.innerText()).replace(/\s+/g, ' ').trim();
      const row = readPayment(paymentId(paymentUrl));
      c.row = row;
      const hash = row?.blockchain_tx_hash ?? '';
      expect(hash, 'the page stored the send hash').toMatch(/^0x[0-9a-fA-F]{64}$/);

      // Chain.
      c.nonceAfter = safeNonce(run.network, fund);
      c.recipientAfter = ethBalance(run.network, recipient);
      const r = receipt(run.network, hash);
      c.receiptStatus = r.status;
      const topic = executionSuccessTopic();
      const log = r.logs.find((l) => l.address.toLowerCase() === fund.toLowerCase() && l.topics[0]?.toLowerCase() === topic);
      c.executionSuccess = log ? { safe: fund, tx: hash, topics: log.topics, data: log.data } : null;
      expect(c.nonceAfter).toBe(nonceBefore + 1);
      expect((c.recipientAfter as bigint) - recipientBefore).toBe(PAY_WEI);
      expect(['0x1', '1']).toContain(r.status);
      expect(log, 'an ExecutionSuccess log from the Safe').toBeDefined();

      // Page and database.
      expect(p.panel).toContain('Payment sent.');
      expect(row?.status).toBe('executed');
      expect(row?.approvals.length).toBe(2);
      expect(row?.nonce).toBe(nonceBefore);
      const live = panel.getByRole('link', { name: /See the public record on/ });
      p.liveRecordHref = await live.getAttribute('href');
      expect(p.liveRecordHref).toBe(explorerTxUrl(hash));

      // The server-rendered page after a reload.
      await page.reload();
      await page.waitForLoadState('load');
      p.status = await paymentStatus(page);
      expect(p.status).toBe('Sent');
      const recordLink = page.getByRole('link', { name: /See the public record on/ });
      p.recordHref = await recordLink.getAttribute('href');
      expect(p.recordHref).toBe(explorerTxUrl(hash));

      // The fund tab shows the chain's new balance (after 21-15's 15 s skip window).
      await openFund(page, groups.pay);
      await page.waitForTimeout(16_000);
      await refreshBalances(page);
      const wei = ethBalance(run.network, fund);
      c.fundBalance = wei;
      expect(wei).toBe(FUND_WEI - PAY_WEI);
      p.fundEth = await balanceText(page, net.native_currency.name);
      expect(p.fundEth).toBe(formatUnits(wei, net.native_currency.decimals, net.native_currency.symbol));
    });
  });

  test('reload while waiting', async ({ context, page }, info) => {
    await flow(run.network, 'reload while waiting', page, info, async (p, c) => {
      expect(fund, 'beforeAll set up the fund').toMatch(/^0x[0-9a-fA-F]{40}$/);
      await signedIn(context, page, 0);
      const url = await proposePayment(page, groups.pay, {
        to: recipient,
        amountText: '0.0001',
        description: `E2E waiting payment ${run.runId}`,
      });
      p.url = url;
      const id = paymentId(url);
      c.proposed = readPayment(id);
      expect((c.proposed as { value: string }).value).toBe(WAIT_WEI.toString());
      await approvePayment(page, url, injectedDriver);

      // The approver's approval, in a second context of this test.
      const other = await context.browser()!.newContext({ baseURL: e2eBaseUrl(), ignoreHTTPSErrors: true });
      try {
        const otherPage = await other.newPage();
        await signedIn(other, otherPage, 1);
        await approvePayment(otherPage, url, injectedDriver);
      } finally {
        await other.close();
      }

      const nonce = safeNonce(run.network, fund);
      c.nonceBefore = nonce;
      let executePosted = false;
      setAutomine(run.network, false);
      try {
        const execute = page
          .waitForResponse((r) => r.request().method() === 'POST' && /\/transactions\/\d+\/execute$/.test(new URL(r.url()).pathname), { timeout: 20_000 })
          .then(() => true, () => false);
        await sendPayment(page, url, injectedDriver);
        // The wallet sent it: anvil holds it unmined.
        await expect.poll(() => pendingHashFrom(devs[0]), { timeout: 30_000 }).toMatch(/^0x[0-9a-fA-F]{64}$/);
        c.pendingHash = pendingHashFrom(devs[0]);
        // The page must have stored the hash before the reload (the plan's precondition).
        executePosted = await execute;
        p.executePostedBeforeMined = executePosted;
        c.rowBeforeMined = readPayment(id);

        await page.reload();
        await page.waitForLoadState('load');
        p.statusBeforeMined = await paymentStatus(page);
        p.waitingShown = await page.getByText(WAITING).first().isVisible();
        p.waitingCount = await page.getByText(WAITING).count();
        // 21-25 fix 10: a pending row with a hash shows the existing waiting state, titled
        // "Sending the payment" (no new `submitted` status).
        p.sendingShown = await page.getByText('Sending the payment', { exact: true }).first().isVisible();
        p.sendButtonShown = await page.getByRole('button', { name: 'Send payment', exact: true }).isVisible();
      } finally {
        // Mine the waiting block, then automine back on, always.
        try {
          mine(run.network);
        } finally {
          setAutomine(run.network, true);
        }
      }
      c.mined = receipt(run.network, c.pendingHash as string).status;
      c.nonceAfter = safeNonce(run.network, fund);

      await page.reload();
      await page.waitForLoadState('load');
      p.statusAfterMined = await paymentStatus(page);
      c.rowAfterMined = readPayment(id);

      // The plan's checks, in order, after everything above is recorded.
      expect(executePosted, 'the page stored the send hash before the block was mined').toBe(true);
      expect(p.waitingShown, `"${WAITING}" after a reload before the block`).toBe(true);
      // The plan's UI outcome (21-43 finding 2): no second "Send payment" mid-send.
      expect(p.sendButtonShown, 'no "Send payment" after a reload before the block').toBe(false);
      expect(p.statusBeforeMined).not.toBe('Sent');
      expect(p.statusAfterMined).toBe('Sent');
      expect(c.nonceAfter).toBe(nonce + 1);
    });
  });
});
