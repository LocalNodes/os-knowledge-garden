// Group fund page helpers for the wallet e2e specs (21-42; reused by 21-43 and 21-45).
//
// Locators use roles and the 21-UI-SPEC copy, so a copy change shows up as a red test.
// The WalletDriver seam lets the same steps run with the injected test wallet (21-41,
// which signs as soon as it's asked) or with a real extension wallet driven by Synpress
// (21-45), which needs a click in its own popup.
import { expect, type Locator, type Page, type TestInfo } from '@playwright/test';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { siteScript } from './site';
import { e2eRun, record, type RegistryEntry } from './chain';
import { signInWithEthereum } from '../wallet/test-wallet';

const HERE = dirname(fileURLToPath(import.meta.url));
const E2E_DIR = join(HERE, '..');

/** Confirms whatever the wallet is asking for. The injected wallet needs no click. */
export interface WalletDriver {
  confirm(kind: 'signIn' | 'deploy' | 'approve' | 'send'): Promise<void>;
}

/** The injected test wallet: anvil signs as soon as it's asked, so nothing to click. */
export const injectedDriver: WalletDriver = {
  async confirm() {
    // No popup.
  },
};

export interface RunGroups { fund: number; link: number; pay: number }

/**
 * Seeds this run's three groups on the lane site (seed-e2e.php --groups). Each has
 * e2e-organizer and e2e-approver as organizers and e2e-member as a member.
 *
 * A fresh install has the group fund relation plugin since 21-25 fix 6 (21-42 bug A). The
 * seed only reports it. If it's missing, the run records a failed `relation plugin` step:
 * the fix regressed, and setting up or linking a group fund will fail at submit.
 */
export function seedRunGroups(runId: string, network: string, suffix = ''): RunGroups {
  const users = e2eRun().users;
  // A spec that needs its own groups passes a suffix (send-recovery.e2e.ts: '-recovery').
  const args = [users.organizer.address, users.approver.address, users.member.address, `--groups=${runId}-${network}${suffix}`];
  const out = siteScript(join(E2E_DIR, 'seed-e2e.php'), args);
  const plugin = out.match(/^LN_E2E_RELATION_PLUGIN=(\w+)\s*$/m)?.[1];
  const groups = out.match(/^LN_E2E_GROUPS=(\{.*\})\s*$/m)?.[1];
  if (!plugin || !groups) throw new Error(`seed-e2e.php --groups printed no LN_E2E_GROUPS. Output:\n${out}`);
  if (plugin !== 'present') {
    record(network, 'relation plugin', {
      result: 'fail',
      finding: {
        bug: 'The group_safe_account:safe_account relation plugin is missing on flexible_group, so setting up or linking a group fund fails at submit. social_group_treasury installs it since 21-25 fix 6; this is a regression.',
        plugin,
      },
    });
  }
  return JSON.parse(groups) as RunGroups;
}

/** The fund the group links, read from the site's database. */
export interface FundRow {
  id: number;
  safe_address: string;
  network: string;
  status: string;
  threshold: number;
  deployment_tx_hash: string;
}

/** The group's linked safe_account row (read-fund.php, read-only), or null. */
export function safeAddressForGroup(gid: number): FundRow | null {
  const out = siteScript(join(E2E_DIR, 'read-fund.php'), [String(gid)]);
  const m = out.match(/^LN_E2E_FUND=(.*)$/m);
  if (!m) throw new Error(`read-fund.php printed no LN_E2E_FUND. Output:\n${out}`);
  return JSON.parse(m[1]) as FundRow | null;
}

/**
 * Host network changes (21-43 finding). desktop-dev is shared: other lanes start and stop
 * containers all the time. Each new veth gets an IPv6 link-local address, and Chromium then
 * aborts every request in flight with net::ERR_NETWORK_CHANGED (a page load ends blank, an
 * in-page fetch says "Failed to fetch"). It isn't the product and it isn't this lane.
 *
 * Only requests that change nothing are retried, once: page loads by URL, link follows, and the
 * SIWE sign-in (a new nonce each time). Proposals, approvals and sends are never retried. Each
 * retry is listed in the step's page data (networkRetries) in the results file.
 */
export const networkRetries: string[] = [];

export function isNetworkChange(err: unknown): boolean {
  return /ERR_NETWORK_CHANGED|Failed to fetch/.test(String((err as Error)?.message ?? err));
}

/** page.goto, once more after a host network change. */
export async function gotoPage(page: Page, url: string): Promise<void> {
  try {
    await page.goto(url);
  } catch (err) {
    if (!/ERR_NETWORK_CHANGED/.test(String((err as Error)?.message ?? err))) throw err;
    networkRetries.push(`goto ${url}`);
    await page.goto(url);
  }
}

/** Clicks a plain link and waits for the page; on a network-change error page, loads its href. */
export async function followLink(page: Page, link: Locator): Promise<void> {
  const href = await link.getAttribute('href');
  await link.click();
  await page.waitForLoadState('load');
  if (page.url().startsWith('chrome-error://') && href) {
    networkRetries.push(`follow ${href}`);
    await page.goto(href);
  }
}

/** The site's SIWE sign-in, once more if a host network change broke its fetch. */
export async function signIn(page: Page): Promise<void> {
  try {
    await signInWithEthereum(page);
  } catch (err) {
    if (!isNetworkChange(err)) throw err;
    networkRetries.push(`sign-in: ${String((err as Error).message).split('\n')[0].slice(0, 200)}`);
    await signInWithEthereum(page);
  }
}

/** Opens the group fund tab and waits for its card (or empty state) to stream in. */
export async function openFund(page: Page, gid: number): Promise<void> {
  await gotoPage(page, `/group/${gid}/treasury`);
  await page.waitForLoadState('load');
  await expect(page.locator('.group-fund-card--loading')).toHaveCount(0, { timeout: 30_000 });
}

/** The receipt panel of the group set-up. */
export function receiptPanel(page: Page): Locator {
  return page.locator('#safe-deployment-status-container');
}

/**
 * Waits until Drupal's AJAX behaviour is attached to a #ajax button (core marks it with
 * data-once="drupal-ajax"). On a freshly installed site the first page's JS aggregate is built
 * on request, so a click can land before the behaviour attaches. The form then posts without
 * JS: the set-up form saved a `pending` fund and redirected (21-43 finding), never starting
 * the wallet step. Clicking only after this wait keeps the test on the JS path it means to test.
 */
export async function ajaxReady(page: Page, button: Locator): Promise<void> {
  await page.waitForLoadState('load');
  await expect(button).toHaveAttribute('data-once', /(^|\s)drupal-ajax(\s|$)/, { timeout: 30_000 });
}

/**
 * From the group tab: "Create a new group fund", then on "Set Up Group Fund" choose the
 * network, keep every organizer ticked, set the threshold and press "Set up group fund".
 * Returns once the receipt panel shows; the caller waits for the outcome.
 */
export async function setUpGroupFund(
  page: Page,
  gid: number,
  opts: { threshold: number; driver: WalletDriver; network: RegistryEntry; onForm?: (page: Page) => Promise<void> },
): Promise<void> {
  await openFund(page, gid);
  await followLink(page, page.getByRole('link', { name: /Create a new group fund/ }));
  // The page title is "Set Up Group Fund": Title Case (Tom, 2026-11-07), and the T4/U36 h1
  // conflict went to Tom's later list (21-29). The button below stays "Set up group fund".
  await expect(page.getByRole('heading', { name: 'Set Up Group Fund', exact: true, level: 1 })).toBeVisible();
  await expect(page.getByText('A group fund is held in a shared account that needs several organizers to approve payments. Choose who approves, then confirm it in your wallet.')).toBeVisible();
  const networkRadio = page.getByRole('radio', { name: opts.network.label, exact: true });
  if (await networkRadio.count()) await networkRadio.check();
  if (opts.onForm) await opts.onForm(page);
  await page.getByRole('spinbutton', { name: 'How many organizers must approve a payment' }).fill(String(opts.threshold));
  const submit = page.getByRole('button', { name: 'Set up group fund' });
  await ajaxReady(page, submit);
  await submit.click();
  await expect(receiptPanel(page)).toBeVisible({ timeout: 30_000 });
  await opts.driver.confirm('deploy');
}

/** From the group tab: "Link an account you already have", network, ID, "Link account". */
export async function linkExistingSafe(page: Page, gid: number, opts: { network: RegistryEntry; address: string }): Promise<void> {
  await openFund(page, gid);
  await followLink(page, page.getByRole('link', { name: /Link an account you already have/ }));
  const networkRadio = page.getByRole('radio', { name: opts.network.label, exact: true });
  if (await networkRadio.count()) await networkRadio.check();
  await page.getByRole('textbox', { name: 'Account ID' }).fill(opts.address);
  await page.getByRole('button', { name: 'Link account' }).click();
  await page.waitForLoadState('load');
}

/** "Unlink group fund", confirmed in its modal. Lands on the tab's empty state. */
export async function unlinkFund(page: Page, gid: number): Promise<void> {
  await openFund(page, gid);
  await page.getByRole('link', { name: 'Unlink group fund' }).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible({ timeout: 30_000 });
  // Drupal's dialog copies the form's buttons into its button pane and hides the originals.
  await dialog.locator('.ui-dialog-buttonpane').getByRole('button', { name: 'Unlink group fund' }).click();
  await page.waitForURL(/[?&]unlinked=1/, { timeout: 30_000 });
  await page.waitForLoadState('load');
}

/** Presses "Refresh balances" and waits for the reload with ?refreshed=1 (or its removal). */
export async function refreshBalances(page: Page): Promise<void> {
  const button = page.getByRole('button', { name: 'Refresh balances' });
  await expect(button).toHaveCount(1);
  await Promise.all([page.waitForURL(/\/treasury/, { waitUntil: 'load' }), button.click()]);
  await page.waitForLoadState('load');
  await expect(page.locator('.group-fund-card--loading')).toHaveCount(0, { timeout: 30_000 });
}

/** The amount cell of a Balances row, by its asset name ("Ether") or token symbol. */
export async function balanceText(page: Page, asset: string): Promise<string> {
  const row = page.locator('table.group-fund-balances tbody tr').filter({ has: page.locator('th', { hasText: new RegExp(`^\\s*${asset}\\s*$`) }) });
  await expect(row).toHaveCount(1);
  return (await row.locator('td').innerText()).trim();
}

export async function stampText(page: Page): Promise<string> {
  return (await page.locator('.group-fund-card__stamp').innerText()).trim();
}

export async function approverCount(page: Page): Promise<number> {
  return page.locator('.group-fund-approvers .group-fund-approver').count();
}

export async function thresholdSentence(page: Page): Promise<string> {
  return (await page.locator('.group-fund-card__threshold').innerText()).trim();
}

/** The full account ID under "Account details" (inside a closed <details>, so textContent). */
export async function accountId(page: Page): Promise<string> {
  return ((await page.locator('.group-fund-account code.safe-full-id').textContent()) ?? '').trim();
}

/** The UI-SPEC amount rule: grouped integer part, trailing zeros dropped ("1,250.5 USDC"). */
export function formatUnits(value: bigint, decimals: number, symbol: string): string {
  const base = 10n ** BigInt(decimals);
  const whole = value / base;
  let frac = (value % base).toString().padStart(decimals, '0').replace(/0+$/, '');
  if (decimals === 0) frac = '';
  const grouped = whole.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  return `${grouped}${frac ? `.${frac}` : ''} ${symbol}`;
}

/** One group payment as stored (read-payment.php, read-only). */
export interface PaymentRow {
  id: number;
  safe_account: number;
  status: string;
  to_address: string;
  value: string;
  nonce: number | null;
  blockchain_tx_hash: string;
  safe_tx_hash: string;
  approvals: string[];
}

export function readPayment(id: number): PaymentRow | null {
  const out = siteScript(join(E2E_DIR, 'read-payment.php'), [String(id)]);
  const m = out.match(/^LN_E2E_PAYMENT=(.*)$/m);
  if (!m) throw new Error(`read-payment.php printed no LN_E2E_PAYMENT. Output:\n${out}`);
  return JSON.parse(m[1]) as PaymentRow | null;
}

/** The payment id at the end of a group payment URL. */
export function paymentId(url: string): number {
  const m = new URL(url).pathname.match(/\/treasury\/transaction\/(\d+)$/);
  if (!m) throw new Error(`not a group payment URL: ${url}`);
  return Number(m[1]);
}

/**
 * The group "Propose a payment" form (21-23's T5): who, amount, what it's for, then "Propose
 * payment". Returns the payment page URL it lands on. A propose is never retried.
 */
export async function proposePayment(page: Page, gid: number, opts: { to: string; amountText: string; description: string }): Promise<string> {
  await gotoPage(page, `/group/${gid}/treasury/propose`);
  await expect(page.getByRole('heading', { name: 'Propose a Payment', exact: true, level: 1 })).toBeVisible();
  const who = page.getByRole('textbox', { name: 'Who receives the payment' });
  await who.fill(opts.to);
  await who.press('Escape');
  await page.getByRole('textbox', { name: /^Amount \(/ }).fill(opts.amountText);
  await page.getByRole('textbox', { name: "What it's for" }).fill(opts.description);
  await Promise.all([
    page.waitForURL(new RegExp(`/group/${gid}/treasury/transaction/\\d+`), { timeout: 60_000 }),
    page.getByRole('button', { name: 'Propose payment' }).click(),
  ]);
  await page.waitForLoadState('load');
  const url = new URL(page.url());
  return `${url.origin}${url.pathname}`;
}

/** Waits until the transaction manager has bound a payment button (it adds safe-tx-processed). */
async function paymentButtonReady(page: Page, name: string): Promise<Locator> {
  const button = page.getByRole('button', { name, exact: true });
  await expect(button).toBeVisible({ timeout: 30_000 });
  await page.waitForLoadState('load');
  await expect(button).toHaveClass(/(^|\s)safe-tx-processed(\s|$)/, { timeout: 30_000 });
  return button;
}

/** The payment page's wallet panel (approval or receipt). */
export function paymentPanel(page: Page): Locator {
  return page.locator('[data-safe-flow-panel]');
}

/**
 * "Approve payment" on the payment page; the wallet signs (eth_signTypedData_v4) and the page
 * posts the approval, which the server checks against the chain (21-34). Returns the panel's
 * text once it says "You approved this payment." Never retried.
 */
export async function approvePayment(page: Page, url: string, driver: WalletDriver): Promise<string> {
  await gotoPage(page, url);
  const button = await paymentButtonReady(page, 'Approve payment');
  await button.click();
  await driver.confirm('approve');
  const panel = paymentPanel(page);
  await expect(panel).toContainText('You approved this payment.', { timeout: 60_000 });
  return (await panel.innerText()).replace(/\s+/g, ' ').trim();
}

/** "Send payment" on the payment page; the wallet sends. The caller waits for the outcome. */
export async function sendPayment(page: Page, url: string, driver: WalletDriver): Promise<void> {
  await gotoPage(page, url);
  const button = await paymentButtonReady(page, 'Send payment');
  await button.click();
  await driver.confirm('send');
}

/** The payment's status badge word ("Ready to send", "Sent", …). */
export async function paymentStatus(page: Page): Promise<string> {
  return (await page.locator('.safe-status-badge').first().innerText()).replace(/\s+/g, ' ').trim();
}

/** The Approvals count sentence ("Approved by 1 of 2. Needs 2."). */
export async function approvalsSentence(page: Page): Promise<string> {
  return (await page.locator('.safe-payment-approvals__count').innerText()).replace(/\s+/g, ' ').trim();
}

/** The visible text a failure left on the page: alerts, messages and the receipt panel. */
export async function onScreen(page: Page): Promise<string> {
  const parts: string[] = [];
  for (const sel of ['#safe-deployment-status-container', '[role="alert"]', '.alert', '[data-drupal-messages]', '.form-item--error-message']) {
    for (const el of await page.locator(sel).all()) {
      if (await el.isVisible().catch(() => false)) parts.push((await el.innerText().catch(() => '')).trim());
    }
  }
  return [...new Set(parts.filter(Boolean))].join(' | ');
}

/**
 * Runs one flow and records it in results-<network>.json: "pass" with the page and chain
 * values the body collected, or "fail" with the on-screen text and the trace path (the lane
 * rule for a product bug: step, exact text, trace).
 */
export async function flow(
  network: string,
  step: string,
  page: Page,
  info: TestInfo,
  body: (page: Record<string, unknown>, chain: Record<string, unknown>) => Promise<void>,
): Promise<void> {
  const pageData: Record<string, unknown> = {};
  const chainData: Record<string, unknown> = {};
  networkRetries.splice(0);
  const retries = (): Record<string, unknown> => (networkRetries.length ? { networkRetries: networkRetries.splice(0) } : {});
  try {
    await body(pageData, chainData);
    record(network, step, { result: 'pass', page: { ...pageData, ...retries() }, chain: chainData });
  } catch (err) {
    record(network, step, {
      result: 'fail',
      page: { ...pageData, ...retries(), url: page.url(), text: await onScreen(page).catch(() => '') },
      chain: chainData,
      finding: { error: String((err as Error)?.message ?? err).replace(/\u001b\[[0-9;]*m/g, '').slice(0, 2000), trace: info.outputPath('trace.zip') },
    });
    throw err;
  }
}
