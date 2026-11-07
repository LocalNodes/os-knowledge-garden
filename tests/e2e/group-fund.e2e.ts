// Group fund set-up, funding and balances on the local chain (21-42).
//
// The organizer (dev account 0) sets up a group fund from the group tab itself: "Create a
// new group fund", then "Set up group fund" on "Set Up Group Fund" (21-22's T4 form and
// 21-13's receipt verification). The page, the chain (read with cast) and the database must
// agree. Then the suite funds the Safe and "Refresh balances" must show exactly the chain's
// values.
//
// No chain id or contract address is written here: they come from the run state, the
// site's active registry entry and tests/e2e/fixtures/known-safes.json.
import { expect, test } from '@playwright/test';
import { devAccounts, installTestWallet, toChecksumAddress } from './wallet/test-wallet';
import {
  code,
  e2eRun,
  ethBalance,
  mintTestUsdc,
  owners,
  PROXY_CREATION_TOPIC,
  receipt,
  record,
  registry,
  safeVersion,
  sendEth,
  threshold,
  tokenBalance,
  tokenDecimals,
  tokenSymbol,
  type E2eRun,
  type RegistryEntry,
} from './helpers/chain';
import {
  accountId,
  approverCount,
  balanceText,
  flow,
  formatUnits,
  gotoPage,
  injectedDriver,
  openFund,
  readPayment,
  receiptPanel,
  refreshBalances,
  safeAddressForGroup,
  seedRunGroups,
  setUpGroupFund,
  stampText,
  thresholdSentence,
  signIn,
  type RunGroups,
} from './helpers/treasury';

let run: E2eRun;
let groups: RunGroups;
let net: RegistryEntry;
let fundAddress = '';

/** What the suite puts in the fund: 0.01 ETH and 1,250.5 USDC. */
const FUND_WEI = 10n ** 16n;
const FUND_USDC_UNITS = 1_250_500_000n;

test.describe.serial('group fund on the local chain', () => {
  test.beforeAll(async () => {
    run = e2eRun();
    net = await registry(run.network);
    groups = seedRunGroups(run.runId, run.network);
  });

  test('set up the group fund from the group tab', async ({ context, page }, info) => {
    await flow(run.network, 'set up', page, info, async (p, c) => {
      const wallet = await installTestWallet(context, { network: run.network, account: 0 });
      expect(wallet.address).toBe(run.users.organizer.address);
      await signIn(page);
      p.group = groups.fund;

      await setUpGroupFund(page, groups.fund, {
        threshold: 2,
        driver: injectedDriver,
        network: net,
        onForm: async (form) => {
          const fieldset = form.getByRole('group', { name: 'Organizers who approve payments', exact: true }).first();
          p.h1 = (await form.getByRole('heading', { level: 1 }).innerText()).trim();
          await expect(fieldset).toBeVisible();
          const text = await fieldset.innerText();
          const you = text.indexOf('Wren Organizer (you)');
          const other = text.indexOf('Linden Approver');
          expect(you).toBeGreaterThanOrEqual(0);
          expect(other).toBeGreaterThan(you);
          await expect(fieldset.getByRole('checkbox', { name: 'Linden Approver' })).toBeChecked();
          p.approversOffered = ['Wren Organizer (you)', 'Linden Approver'];
        },
      });

      const panel = receiptPanel(page);
      await expect(panel.getByText('Setting up the group fund')).toBeVisible();
      await expect(panel.getByText('The group fund is ready.')).toBeVisible({ timeout: 120_000 });
      const open = panel.getByRole('link', { name: 'Open the group fund' });
      await expect(open).toBeVisible();
      p.done = 'The group fund is ready.';

      // No automatic redirect (preview decision 9).
      const url = page.url();
      await page.waitForTimeout(5_000);
      expect(page.url()).toBe(url);
      p.urlAfter5s = url;

      await open.click();
      await page.waitForURL(new RegExp(`/group/${groups.fund}/treasury`));
      await openFund(page, groups.fund);
      p.approvers = await approverCount(page);
      p.thresholdSentence = await thresholdSentence(page);
      expect(p.approvers).toBe(2);
      expect(p.thresholdSentence).toBe('Needs 2 of 2 organizers to approve a payment.');

      // Database: the group's linked row.
      const row = safeAddressForGroup(groups.fund);
      expect(row).not.toBeNull();
      c.row = row;
      expect(row!.status).toBe('active');
      expect(row!.network).toBe(run.network);
      expect(row!.safe_address).toMatch(/^0x[0-9a-fA-F]{40}$/);
      const address = toChecksumAddress(row!.safe_address);
      fundAddress = address;
      c.address = address;
      p.accountId = await accountId(page);
      expect(p.accountId).toBe(address);

      // Chain.
      const devs = await devAccounts(run.network);
      c.owners = owners(run.network, address);
      c.threshold = threshold(run.network, address);
      c.version = safeVersion(run.network, address);
      expect(new Set(c.owners as string[])).toEqual(new Set([devs[0], devs[1]]));
      expect((c.owners as string[]).length).toBe(2);
      expect(c.threshold).toBe(2);
      expect(c.version).toBe('1.4.1');
      expect(code(run.network, address)).not.toBe('0x');

      const r = receipt(run.network, row!.deployment_tx_hash);
      c.deploymentTx = r.transactionHash;
      c.receiptStatus = r.status;
      expect(['0x1', '1']).toContain(r.status);
      const factory = net.contracts.safe_proxy_factory.toLowerCase();
      const log = r.logs.find((l) => l.address.toLowerCase() === factory && l.topics[0]?.toLowerCase() === PROXY_CREATION_TOPIC);
      expect(log, 'a ProxyCreation log from the registry factory').toBeDefined();
      expect(toChecksumAddress(`0x${log!.topics[1].slice(-40)}`)).toBe(address);
      c.proxyCreation = { factory: net.contracts.safe_proxy_factory, proxy: address };
      c.ethBalance = ethBalance(run.network, address);
    });
  });

  test('fund the group fund', async ({ page }, info) => {
    await flow(run.network, 'fund', page, info, async (_p, c) => {
      expect(fundAddress, 'the set-up test made the fund').toMatch(/^0x[0-9a-fA-F]{40}$/);
      const token = net.tokens[0].address;
      c.address = fundAddress;
      c.token = token;
      const eth = await sendEth(run.network, 0, fundAddress, FUND_WEI);
      c.ethTx = eth.transactionHash;
      try {
        const mint = await mintTestUsdc(run.network, fundAddress, FUND_USDC_UNITS);
        c.mintTx = mint.transactionHash;
      } catch (err) {
        // Recorded and reported; the ETH half still counts (plan: never pick another token).
        record(run.network, 'mint', { result: 'fail', chain: { token }, finding: String((err as Error).message) });
        throw err;
      }
      c.ethBalance = ethBalance(run.network, fundAddress);
      c.tokenBalance = tokenBalance(run.network, token, fundAddress);
      c.decimals = tokenDecimals(run.network, token);
      expect(c.ethBalance).toBe(FUND_WEI);
      expect(c.tokenBalance).toBe(FUND_USDC_UNITS);
      expect(c.decimals).toBe(6);
    });
  });

  test('balances come from the chain', async ({ context, page }, info) => {
    await flow(run.network, 'balances', page, info, async (p, c) => {
      expect(fundAddress, 'the set-up test made the fund').toMatch(/^0x[0-9a-fA-F]{40}$/);
      await installTestWallet(context, { network: run.network, account: 0 });
      await signIn(page);
      await openFund(page, groups.fund);

      // 21-15: a refresh within 15 s of the last read attempt is skipped. Wait it out from
      // this page view (the latest read any test could have caused).
      await page.waitForTimeout(16_000);
      await refreshBalances(page);
      p.url = page.url();

      const token = net.tokens[0].address;
      const wei = ethBalance(run.network, fundAddress);
      const units = tokenBalance(run.network, token, fundAddress);
      const decimals = tokenDecimals(run.network, token);
      const symbol = tokenSymbol(run.network, token);
      c.ethBalance = wei;
      c.tokenBalance = units;
      c.decimals = decimals;
      c.symbol = symbol;

      const expectEth = formatUnits(wei, net.native_currency.decimals, net.native_currency.symbol);
      const expectToken = formatUnits(units, decimals, symbol);
      p.eth = await balanceText(page, net.native_currency.name);
      p.token = await balanceText(page, symbol);
      p.stamp = await stampText(page);
      expect(p.eth).toBe(expectEth);
      expect(p.token).toBe(expectToken);
      // The amounts the suite sent, by the UI-SPEC rule.
      expect(p.eth).toBe(`0.01 ${net.native_currency.symbol}`);
      expect(p.token).toBe(`1,250.5 ${symbol}`);
      expect(p.stamp).toMatch(/^Updated/);
    });
  });

  // 21-25 fix 14: "Save approval settings" with JavaScript on. Before the fix,
  // safe-configuration-manager.js disabled the button inside the submit event, so the post
  // had no op for it and Drupal took the first "Remove …" button: nothing was saved, no
  // message showed and an approver row went. First an unchanged save (only Save reaches
  // "Nothing changed"), then a real change, which must propose a change and nothing more.
  test('approval settings save with JavaScript on', async ({ context, page }, info) => {
    await flow(run.network, 'approval settings save', page, info, async (p, c) => {
      expect(fundAddress, 'the set-up test made the fund').toMatch(/^0x[0-9a-fA-F]{40}$/);
      const row = safeAddressForGroup(groups.fund);
      expect(row?.status).toBe('active');
      await installTestWallet(context, { network: run.network, account: 0 });
      await signIn(page);
      const path = `/user/${run.users.organizer.uid}/safe-accounts/${row!.id}`;
      const isSavePost = (r: { method(): string; url(): string }) => r.method() === 'POST' && new URL(r.url()).pathname === path;
      const save = page.getByRole('button', { name: 'Save approval settings' });
      const removes = page.getByRole('button', { name: /^Remove / });
      const changes = page.locator('table.safe-payment-list tbody tr');
      const ownersBefore = owners(run.network, fundAddress);
      const thresholdBefore = threshold(run.network, fundAddress);
      c.owners = ownersBefore;
      c.threshold = thresholdBefore;

      // 1. Unchanged save.
      await gotoPage(page, path);
      await expect(save).toBeVisible();
      await expect(page.locator('#safe-account-manage-form.safe-config-processed')).toHaveCount(1);
      const rowsBefore = await removes.count();
      const changesBefore = await changes.count();
      p.removeButtons = rowsBefore;
      let posted = page.waitForRequest(isSavePost);
      await save.click();
      let body = new URLSearchParams((await posted).postData() ?? '');
      p.unchangedOp = body.getAll('op');
      expect(body.getAll('op')).toContain('Save approval settings');
      expect([...body.keys()].filter((k) => /remove/i.test(k))).toEqual([]);
      await expect(page.getByText('Nothing changed, so nothing was proposed.')).toBeVisible();
      await expect(removes).toHaveCount(rowsBefore);
      await expect(changes).toHaveCount(changesBefore);

      // 2. A real change: one approval instead of two. It is proposed, not sent.
      await page.getByRole('spinbutton', { name: 'How many organizers must approve a payment' }).fill('1');
      posted = page.waitForRequest(isSavePost);
      await save.click();
      body = new URLSearchParams((await posted).postData() ?? '');
      p.changeOp = body.getAll('op');
      expect(body.getAll('op')).toContain('Save approval settings');
      await expect(page.getByText("Change proposed. It shows in the list below until it's approved and sent.")).toBeVisible();
      await expect(removes).toHaveCount(rowsBefore);
      await expect(changes).toHaveCount(changesBefore + 1);
      const href = (await changes.first().getByRole('link').first().getAttribute('href')) ?? '';
      const id = Number(href.match(/\/transactions?\/(\d+)$/)?.[1] ?? NaN);
      expect(id, `the new change links to its page (${href})`).toBeGreaterThan(0);
      const change = readPayment(id);
      p.change = change;
      expect(change?.safe_account).toBe(row!.id);
      expect(change?.to_address.toLowerCase()).toBe(fundAddress.toLowerCase());
      expect(change?.blockchain_tx_hash).toBe('');
      // Nothing was sent: the chain still has the same owners and threshold.
      c.ownersAfter = owners(run.network, fundAddress);
      c.thresholdAfter = threshold(run.network, fundAddress);
      expect(c.ownersAfter).toEqual(ownersBefore);
      expect(c.thresholdAfter).toBe(thresholdBefore);
    });
  });
});

// 21-43 finding 3 / 21-25 fix 11: a set-up posted without Drupal AJAX (JavaScript off, or a
// click before the button was bound) must create nothing. It runs on its own seeded groups,
// so a regression can't take the serial set-up above with it.
test.describe('group fund set-up without JavaScript', () => {
  let plainRun: E2eRun;
  let plainGroups: RunGroups;
  let plainNet: RegistryEntry;

  test.beforeAll(async () => {
    plainRun = e2eRun();
    plainNet = await registry(plainRun.network);
    plainGroups = seedRunGroups(`${plainRun.runId}-nojs`, plainRun.network);
  });

  test('a plain set-up post creates no fund', async ({ context, page }, info) => {
    await flow(plainRun.network, 'no-JS set-up', page, info, async (p, c) => {
      await installTestWallet(context, { network: plainRun.network, account: 0 });
      await signIn(page);
      const gid = plainGroups.fund;
      p.group = gid;
      c.rowBefore = safeAddressForGroup(gid);
      expect(c.rowBefore, 'the seeded group has no fund yet').toBeNull();

      await openFund(page, gid);
      await page.getByRole('link', { name: /Create a new group fund/ }).click();
      await page.waitForLoadState('load');
      const networkRadio = page.getByRole('radio', { name: plainNet.label, exact: true });
      if (await networkRadio.count()) await networkRadio.check();
      await page.getByRole('spinbutton', { name: 'How many organizers must approve a payment' }).fill('2');

      // A plain browser post, as with JavaScript off: form.submit() skips every handler,
      // and op names the button so Drupal picks it as the triggering element.
      const submit = page.getByRole('button', { name: 'Set up group fund' });
      const posted = page.waitForResponse((r) => r.request().method() === 'POST' && /\/treasury\/create/.test(r.url()), { timeout: 30_000 });
      await submit.evaluate((button) => {
        const form = (button as HTMLButtonElement).form!;
        const op = document.createElement('input');
        op.type = 'hidden';
        op.name = 'op';
        op.value = 'Set up group fund';
        form.appendChild(op);
        form.submit();
      });
      const response = await posted;
      p.post = `${response.request().method()} ${response.status()} ${new URL(response.url()).pathname}${new URL(response.url()).search}`;
      await page.waitForLoadState('load');
      p.url = page.url();

      const warning = "Nothing was set up, because setting up a group fund needs your wallet and the page hadn't finished loading. Wait a moment, then try again.";
      // Drupal's status message shows a moment after the load event, so wait for it.
      p.warningShown = await page
        .getByText(warning)
        .filter({ visible: true })
        .first()
        .waitFor({ state: 'visible', timeout: 10_000 })
        .then(() => true, () => false);
      p.alreadyHasFund = await page.getByText('This group already has a group fund.').count();
      c.rowAfter = safeAddressForGroup(gid);

      expect(p.post).not.toContain('ajax_form');
      expect(p.warningShown, 'the fix-11 warning').toBe(true);
      expect(p.alreadyHasFund, 'no "already has a group fund" page').toBe(0);
      expect(c.rowAfter, 'no fund row for the group').toBeNull();
    });
  });
});
