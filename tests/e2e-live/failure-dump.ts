// The live suite's failure dump.
//
// The nightly run uploads no artifacts, so when a step fails the spec prints a short dump to the
// CI log. The repo and its Actions logs are public, so the dump is an
// allowlist. The dump prints only fixed, structured fields: integers, known enum values, yes or
// no flags, counts, and the keys of known UI strings. It never prints free text from MetaMask,
// the console or Drupal, so nothing a page, a wallet or an RPC provider writes can reach the log.
// The site's alerts print the same way: the key of a known site message (SITE_ALERTS), or
// "unrecognised site message" with a word count. redact() stays as defence in depth; no dump
// line depends on it.
import type { Page } from '@playwright/test';

const MAX_LINE = 600;

/**
 * Removes secrets from one line and caps its length (defence in depth only):
 * - a run of 12 or more lowercase words (a seed phrase, BIP-39 style);
 * - the value after cookie, set-cookie, x-csrf-token or authorization;
 * - the value of a Drupal session cookie pair (SESS… or SSESS…).
 */
export function redact(line: string): string {
  let out = String(line);
  out = out.replace(/\b(?:[a-z]{3,8}\s+){11,}[a-z]{3,8}\b/g, '[redacted words]');
  out = out.replace(/\b(S?SESS[0-9a-f]+)=[^\s;,]+/gi, '$1=[redacted]');
  out = out.replace(/\b(set-cookie|cookie|x-csrf-token|authorization)(\s*[:=]\s*)(?:bearer\s+)?[^\s;,]+/gi, '$1$2[redacted]');
  if (out.length > MAX_LINE) out = `${out.slice(0, MAX_LINE - 12)} [truncated]`;
  return out;
}

/** A value from a known set, "other" for anything else, "-" when absent. */
function known(value: unknown, allowed: readonly string[]): string {
  if (value === undefined || value === null || value === '') return '-';
  return typeof value === 'string' && allowed.includes(value) ? value : 'other';
}

/** SafeAccount statuses and receipt verdicts (safe_smart_accounts 1.0.0-alpha1). */
const ACCOUNT_STATUS = ['pending', 'deploying', 'active', 'failed', 'error'] as const;
const RECEIPT_STATUS = ['confirmed', 'pending', 'failed', 'unverified'] as const;

/** One update-deployment answer: HTTP status, the two enums, and whether it named an error. */
export function updateDeploymentLine(status: number, body: unknown): string {
  const b = body && typeof body === 'object' ? (body as Record<string, unknown>) : {};
  const code = Number.isInteger(status) ? status : 0;
  const error = b.error !== undefined && b.error !== null && b.error !== '' ? 'yes' : 'no';
  return `update-deployment ${code} status=${known(b.status, ACCOUNT_STATUS)} receipt_status=${known(b.receipt_status, RECEIPT_STATUS)} error=${error}`;
}

/**
 * The receipt panel's known strings (group_treasury js/treasury-deployment.js and
 * safe_smart_accounts js/flow-panel.js, 1.0.0-alpha1), in print order.
 */
const PANEL_STRINGS: ReadonlyArray<[string, string]> = [
  ['heading', 'Setting up the group fund'],
  ['step_approval', 'Waiting for your approval'],
  ['help_approval', 'Confirm it in your wallet.'],
  ['step_sending', 'Sending'],
  ['help_sending', 'Your wallet is sending it to the public record.'],
  ['step_confirmation', 'Waiting for confirmation'],
  ['help_confirmation', 'This usually takes less than a minute.'],
  ['step_done', 'Done'],
  ['help_record', "Waiting for the public record to confirm this. We'll check again when you open this page."],
  ['done_ready', 'The group fund is ready.'],
  ['done_open', 'Open the group fund'],
  ['done_copy', 'Copy ID for the group fund'],
  ['setup_failed', "Setting up didn't go through, so no group fund was created. Try setting it up again."],
];

/**
 * The panel as fixed fields: its state (digits only), hidden or shown, whether the Done and error
 * blocks are there, which known strings it holds (by key), and how many other characters it has.
 * The other characters (for example a wallet error's copy) are counted, never printed.
 */
export function panelSummary(p: { state: string | null; hidden: boolean; text: string; hasDone: boolean; hasError: boolean }): string {
  const state = p.state === null ? '-' : /^\d{1,2}$/.test(p.state) ? p.state : 'other';
  let rest = String(p.text ?? '');
  const found = new Set<string>();
  // Longest first, so a short string never eats part of a longer one.
  for (const [key, text] of [...PANEL_STRINGS].sort((a, b) => b[1].length - a[1].length)) {
    if (rest.includes(text)) {
      found.add(key);
      rest = rest.split(text).join(' ');
    }
  }
  const keys = PANEL_STRINGS.map(([key]) => key).filter((key) => found.has(key));
  const unrecognised = rest.replace(/\s+/g, '').length;
  return `receipt panel: state=${state} ${p.hidden ? 'hidden' : 'shown'} done=${p.hasDone ? 'yes' : 'no'} error=${p.hasError ? 'yes' : 'no'} keys=${keys.join(',') || '-'} unrecognised=${unrecognised}`;
}

/** A console line's category: one of the site's known [Safe …] messages, else its level. */
export function consoleKey(type: string, text: string): string | null {
  const t = String(text);
  if (t.startsWith('[Safe Deployment] update-deployment refused')) return 'safe:update_refused';
  if (t.startsWith('[Safe Deployment] Entity update error')) return 'safe:entity_update_error';
  if (t.startsWith('[Safe] Wallet or network error')) return 'safe:wallet_error';
  if (t.startsWith('[Safe')) return 'safe:other';
  if (type === 'error') return 'error';
  if (type === 'warning') return 'warning';
  return null;
}

/** A block number from a hex quantity, as an integer, or "rpc_error". */
export function blockValue(hex: unknown): string {
  return typeof hex === 'string' && /^0x[0-9a-f]{1,16}$/i.test(hex) ? String(parseInt(hex, 16)) : 'rpc_error';
}

/** A failed block read as a fixed category; the error's own message is never printed. */
export function blockCategory(error: unknown): 'timeout' | 'rpc_error' | 'no_provider' {
  const m = error instanceof Error ? error.message : String(error);
  if (/no answer in|timeout|timed out/i.test(m)) return 'timeout';
  if (/ethereum|provider/i.test(m)) return 'no_provider';
  return 'rpc_error';
}

/** A page URL as a fixed route key; path segments are never printed. */
export function routeKey(
  url: string,
): 'group_treasury_create' | 'group_treasury' | 'group_treasury_propose' | 'group_treasury_payment' | 'user_login' | 'other' {
  let path: string;
  try {
    path = new URL(url).pathname.replace(/\/+$/, '');
  }
  catch {
    return 'other';
  }
  if (/^\/group\/\d+\/treasury\/create$/.test(path)) return 'group_treasury_create';
  if (/^\/group\/\d+\/treasury$/.test(path)) return 'group_treasury';
  if (/^\/group\/\d+\/treasury\/propose$/.test(path)) return 'group_treasury_propose';
  // The payment page is /group/N/treasury/transaction/N (singular; tests/e2e/helpers/treasury.ts).
  if (/^\/group\/\d+\/treasury\/transaction\/\d+$/.test(path)) return 'group_treasury_payment';
  if (path === '/user/login') return 'user_login';
  return 'other';
}

/** The voting setup's two JSON routes as fixed keys, null for any other URL. */
export function setupRouteKey(url: string): 'setup_messages' | 'setup_approve' | null {
  let path: string;
  try {
    path = new URL(url).pathname.replace(/\/+$/, '');
  }
  catch {
    return null;
  }
  if (/^\/group\/\d+\/votes\/setup\/messages$/.test(path)) return 'setup_messages';
  if (/^\/group\/\d+\/votes\/setup\/approve$/.test(path)) return 'setup_approve';
  return null;
}

/**
 * The approve-the-setup error the page showed, as a fixed key: the alert's text is matched
 * against the screen's known sentences and never printed.
 */
const APPROVE_ALERTS: ReadonlyArray<[string, RegExp]> = [
  ['expired', /ran out of time/i],
  ['rejected', /didn.t confirm it in your wallet/i],
  ['wrong_network', /on a different network/i],
  ['not_approver', /isn.t one of this group fund.s approvers/i],
  ['wallet_rpc', /wallet ran into a problem/i],
  ['server', /went wrong on our side/i],
];

export function approveAlertKey(text: string): string {
  for (const [key, re] of APPROVE_ALERTS) if (re.test(text)) return key;
  return 'other';
}

/** The site's alert regions on the page under test; the outermost match of each is read. */
export const ALERT_SELECTOR = '[role="alert"], .alert-danger, .messages--error, .messages--warning';

/** The receipt panel, which can hold a wallet's error copy: an alert in it or around it is skipped. */
const RECEIPT_PANEL = '#safe-deployment-status-container';

/** At most this many alerts are printed. */
const MAX_ALERTS = 5;

/**
 * The site's own alert messages the judge can meet, by key, verbatim from the modules' source
 * (safe_governance at the pinned commit: VotesController, VotesList, ApproveMembersForm,
 * VotingSetupForm, ProposalFormBase and the propose, vote, approve-members and setup-approve
 * scripts; the approve flow's wallet errors from safe_smart_accounts' wallet-errors.js). A
 * runtime value is matched by a pattern, never printed.
 */
const SITE_ALERTS: ReadonlyArray<[string, string | RegExp]> = [
  // The voting setup page.
  ['setup_failed', "Setting up voting didn't go through. Nothing changed. Organizers need to approve it again."],
  ['rules_refused', "The voting rules weren't accepted. Organizers need to approve them again."],
  ['setup_fee_refused', "The community's network fee account refused this step, so nothing was sent."],
  ['setup_fee_limit', "The community's network fee limit is reached"],
  ['setup_name_taken', /Someone reserved .+ first\. Choose another name, then organizers approve again\./],
  ['setup_unknown', 'Something went wrong on our side. Refresh this page to try again.'],
  ['setup_start_failed', 'Something went wrong on our side. Try setting up voting again in a minute.'],
  ['approvals_expired', 'The approvals ran out of time. Approvals for the voting rules last 6 days, so organizers need to approve again.'],
  ['host_control', "Something gives this site's host control over the group's votes, so new proposals are paused."],
  ['host_unread_setup', "Couldn't check the group's voting setup right now."],
  ['host_unread_approvers', "Couldn't check the group fund's approvers right now."],
  ['approve_rejected', "You didn't confirm it in your wallet, so the setup isn't approved yet."],
  ['approve_wrong_network', /Your wallet is on a different network\. Switch it to .+, then approve again\./],
  ['approve_not_approver', "The account in your wallet isn't one of this group fund's approvers. Switch to the account you use on this site, then approve again."],
  ['approve_not_connected', "Your wallet isn't connected in this browser. Connect it, then come back to this page."],
  ['approve_wallet_problem', 'Your wallet ran into a problem. Check your wallet, then try again.'],
  ['approve_server', "Something went wrong on our side, so your approval wasn't saved."],
  // Approving members.
  ['members_failed', "These changes didn't go through. Nothing changed. Approve them again."],
  ['members_server', "Something went wrong on our side, so the changes weren't sent for approval. Try again in a minute."],
  ['members_rejected', "You didn't confirm it in your wallet, so the changes aren't approved yet."],
  // The votes list.
  ['votes_unread', "Couldn't load the votes right now."],
  ['votes_stale', "Couldn't load the latest votes right now."],
  // A proposal.
  ['publish_hub_failed', "We couldn't publish the proposal right now. Your draft is saved. Try publishing again in a minute."],
  ['publish_not_yet', 'You can propose a minute or so after organizers approve you. Try publishing again shortly.'],
  ['publish_server', "Something went wrong on our side, so the proposal wasn't published. Your draft is saved."],
  ['publish_rejected', "You didn't confirm it in your wallet, so the proposal wasn't published."],
  ['publish_wrong_account', "The account in your wallet isn't the one linked to your profile here. Switch to it, then publish again."],
  // A vote.
  ['vote_busy', 'Lots of votes are arriving right now. Try casting it again in a minute.'],
  ['vote_hub_failed', "We couldn't record your vote right now. Try casting it again in a minute."],
  ['vote_server', "Something went wrong on our side, so your vote wasn't recorded."],
  ['vote_ended', "Voting ended before your vote arrived, so it wasn't counted."],
  ['vote_rejected', "You didn't confirm your vote, so it wasn't counted."],
  ['vote_wrong_account', "The account in your wallet isn't the one linked to your profile here. Switch to it, then cast your vote again."],
  ['vote_not_yet', 'You were approved to vote after this one started, so you can vote on the next one.'],
  ['vote_choose', 'Choose For, Against or Abstain.'],
];

/** Whitespace collapsed and curly apostrophes straightened, for matching only. */
function plain(text: string): string {
  return String(text ?? '').replace(/[\u2018\u2019]/g, "'").replace(/\s+/g, ' ').trim();
}

/**
 * One site alert as a dump line: the keys of the known site messages it holds, else
 * "unrecognised site message" and its length in words. No word, number or hex from the page is
 * ever printed. Empty text prints nothing.
 */
export function alertLine(text: string): string | null {
  const t = plain(text);
  if (!t) return null;
  const keys = SITE_ALERTS.filter(([, m]) => (typeof m === 'string' ? t.includes(m) : m.test(t))).map(([key]) => key);
  if (keys.length) return `alert: ${keys.join(',')}`;
  return `alert: unrecognised site message (${t.split(' ').length} words)`;
}

/** A JSON-RPC hex quantity as a bigint; anything else throws "rpc_error (bad_result)". */
export function hexQuantity(value: unknown): bigint {
  if (typeof value !== 'string' || !/^0x[0-9a-f]+$/i.test(value)) throw new Error('rpc_error (bad_result)');
  return BigInt(value);
}

/** A receipt's outcome from its status, read only as 0x1 or 0x0. */
export function receiptOutcome(receipt: unknown): 'success' | 'failed' | 'pending' | 'bad_result' {
  if (!receipt || typeof receipt !== 'object') return 'pending';
  const status = (receipt as { status?: unknown }).status;
  if (status === undefined || status === null) return 'pending';
  if (status === '0x1') return 'success';
  if (status === '0x0') return 'failed';
  return 'bad_result';
}

/** A transaction hash for a message: itself if it is 32 bytes of hex, else "bad_hash". */
export function txHashText(hash: unknown): string {
  return typeof hash === 'string' && /^0x[0-9a-f]{64}$/i.test(hash) ? hash : 'bad_hash';
}

export type ErrorCategory = 'rpc_error' | 'timeout' | 'user_rejected' | 'wallet_closed' | 'playwright_timeout' | 'other';

/** Error names that may be printed; any other name prints as "Error". */
const ERROR_NAMES = ['Error', 'TypeError', 'RangeError', 'SyntaxError', 'TimeoutError', 'FetchError', 'AbortError'];

/** An error's fixed category, read from its name, code and message; the message is never kept. */
export function errorCategory(error: unknown): ErrorCategory {
  if (!(error instanceof Error)) return 'other';
  const m = error.message;
  const code = (error as { code?: unknown }).code;
  if (error.name === 'TimeoutError') return 'playwright_timeout';
  if (/has been closed/i.test(m)) return 'wallet_closed';
  if (code === 4001 || code === 'ACTION_REJECTED' || /user (rejected|denied)|rejected the request/i.test(m)) return 'user_rejected';
  if (/no answer in|timed? ?out/i.test(m)) return 'timeout';
  if (/\brpc\b|json-rpc|HTTP \d{3}|fetch failed|ECONN|ENOTFOUND|-32\d{3}/i.test(m)) return 'rpc_error';
  return 'other';
}

/**
 * A new Error for the public log: "<category> (<name>)". The original message (which can carry an
 * RPC URL with a key) and its stack are dropped.
 */
export function categorized(error: unknown): Error {
  if (!(error instanceof Error)) return new Error('other (unknown)');
  const name = ERROR_NAMES.includes(error.name) ? error.name : 'Error';
  return new Error(`${errorCategory(error)} (${name})`);
}

const SAFE_KEYS = ['safe:update_refused', 'safe:entity_update_error', 'safe:wallet_error', 'safe:other'] as const;

/** Counts and fixed lines recorded while a test runs. */
export class FailureDump {
  private errors = 0;
  private warnings = 0;
  private pageErrors = 0;
  private readonly pageErrorLines: string[] = [];
  private failedRequests = 0;
  private readonly safe = new Map<string, number>();
  private readonly answers: string[] = [];

  /** One console message: counted by level, and by [Safe …] key. Its text is dropped. */
  console(type: string, text: string): void {
    if (type === 'error') this.errors++;
    if (type === 'warning') this.warnings++;
    const key = consoleKey(type, text);
    if (key && key.startsWith('safe:')) this.safe.set(key, (this.safe.get(key) ?? 0) + 1);
  }

  /**
   * One uncaught page error: counted, and the first 5 listed by their allowlisted error name and
   * the receipt panel's state when it happened (digits, else "other"; "-" when unknown).
   */
  pageError(error?: unknown, panelState?: string | null): void {
    this.pageErrors++;
    if (this.pageErrorLines.length >= 5) return;
    const name = error instanceof Error ? (ERROR_NAMES.includes(error.name) ? error.name : 'Error') : 'unknown';
    const state = panelState === undefined || panelState === null ? '-' : /^\d{1,2}$/.test(panelState) ? panelState : 'other';
    this.pageErrorLines.push(`pageerror: name=${name} panel_state=${state}`);
  }

  requestFailed(): void {
    this.failedRequests++;
  }

  answer(status: number, body: unknown): void {
    this.answers.push(updateDeploymentLine(status, body));
    if (this.answers.length > 10) this.answers.shift();
  }

  lines(): string[] {
    return [
      `console: errors=${this.errors} warnings=${this.warnings} ${SAFE_KEYS.map((k) => `${k}=${this.safe.get(k) ?? 0}`).join(' ')}`,
      `pageerrors=${this.pageErrors} update-deployment requests failed=${this.failedRequests}`,
      ...this.pageErrorLines,
      ...(this.answers.length ? this.answers : ['update-deployment: no answer recorded']),
    ];
  }

  /** Records the page's console, page errors, update-deployment answers and failed requests. */
  watch(page: Page): void {
    page.on('console', (msg) => this.console(msg.type(), msg.text()));
    page.on('pageerror', (error) => {
      page
        .locator('#safe-deployment-status-container [data-safe-panel-state]')
        .first()
        .getAttribute('data-safe-panel-state', { timeout: 1_000 })
        .catch(() => null)
        .then((state) => this.pageError(error, state));
    });
    page.on('response', (response) => {
      if (!/\/safe-accounts\/\d+\/update-deployment(\?|$)/.test(response.url())) return;
      response
        .json()
        .catch(() => null)
        .then((body) => this.answer(response.status(), body));
    });
    page.on('requestfailed', (request) => {
      if (/\/safe-accounts\/\d+\/update-deployment(\?|$)/.test(request.url())) this.requestFailed();
    });
  }

  /** Prints the dump: the page's path shape, the receipt panel, message counts, then lines(). */
  async print(page: Page): Promise<void> {
    const out: string[] = [];
    out.push(`page: ${routeKey(page.url())}`);
    try {
      const panel = page.locator('#safe-deployment-status-container');
      if (await panel.count()) {
        const state = await panel.locator('[data-safe-panel-state]').first().getAttribute('data-safe-panel-state', { timeout: 2_000 }).catch(() => null);
        const hidden = (await panel.getAttribute('hidden', { timeout: 2_000 }).catch(() => null)) !== null;
        const text = (await panel.textContent({ timeout: 2_000 }).catch(() => '')) ?? '';
        const hasDone = (await panel.locator('.safe-receipt-panel__done').count()) > 0;
        const hasError = (await panel.locator('.safe-receipt-panel__error').count()) > 0;
        out.push(panelSummary({ state, hidden, text, hasDone, hasError }));
      }
      else {
        out.push('receipt panel: absent');
      }
      const count = (sel: string) => page.locator(sel).count().catch(() => -1);
      out.push(`messages: status=${await count('.messages--status')} warning=${await count('.messages--warning')} error=${await count('.messages--error')} alerts=${await count('[role="alert"]')} danger=${await count('.alert-danger')}`);
      const texts = await page
        .locator(ALERT_SELECTOR)
        .evaluateAll(
          (els, [sel, panel]) =>
            els
              .filter((el) => !el.parentElement?.closest(sel) && !el.closest(panel) && !el.querySelector(panel))
              .map((el) => (el as HTMLElement).innerText ?? el.textContent ?? ''),
          [ALERT_SELECTOR, RECEIPT_PANEL] as const,
        )
        .catch(() => null);
      if (texts === null) out.push('alert text: read failed');
      else {
        const lines = texts.map(alertLine).filter((l): l is string => l !== null);
        out.push(...lines.slice(0, MAX_ALERTS));
        if (lines.length > MAX_ALERTS) out.push(`alert text: ${lines.length - MAX_ALERTS} more not printed`);
      }
    }
    catch {
      out.push('page read failed');
    }
    out.push(...this.lines());
    console.log(`[live] failure dump:\n${out.map((l) => `  ${l}`).join('\n')}`);
  }
}
