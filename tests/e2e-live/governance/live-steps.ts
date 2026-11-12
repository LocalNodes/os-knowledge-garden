// Live governance steps shared by the judge and anything else that drives the voting screens in
// real MetaMask. Log lines hold fixed fields only: the CI logs are public (failure-dump.ts).
import { expect, type BrowserContext, type Page } from '@playwright/test';
import { approveAlertKey, setupRouteKey } from '../failure-dump';
import { confirmPrompts, SETUP_SIGN_PROMPT, within, type PromptLog } from '../metamask-steps';
import { forSite } from './judge-wallet';
import { COPY, fill, pattern, setupProgress } from './pages';

/** How long one check on the page may take; a slower one counts as "nothing seen yet". */
const CHECK_MS = 5_000;

/** `p`'s value, or `fallback` if it fails or hasn't settled within `ms`. */
async function settle<T>(p: Promise<T>, ms: number, fallback: T): Promise<T> {
  const r = await within(p, ms).catch(() => 'timeout' as const);
  return r === 'timeout' ? fallback : r.value;
}

/** Page errors a second round may follow: a passing fault on the site or in the wallet's RPC. */
const RETRYABLE = ['server', 'wallet_rpc'];

/**
 * Approves the setup as the signed-in approver, and returns each "Confirmation n of 3" the
 * page showed, sorted.
 *
 * - The page after "Start" shows the button before Drupal binds its click handler, and a click
 *   then does nothing at all. So this waits for the load and for once() to mark the panel.
 * - A round has started when the button hides, an error shows, or the messages route answers.
 *   A press that starts nothing is pressed once more.
 * - Done only when the page says "You approved the setup.", which it shows once the server has
 *   accepted all three approvals; status codes alone never count.
 * - An error on the page ends the wait at once. Only a passing fault gets one more round.
 * - It logs one line of fixed fields: route keys with status codes, the error as a known key,
 *   the button's state and the confirmations seen.
 */
export async function approveSetup(page: Page, context: BrowserContext, extensionId: string, log: PromptLog, who: string): Promise<string[]> {
  const v7 = setupProgress(page);
  await expect(v7.approveTheSetup).toBeVisible({ timeout: 60_000 });
  await page.waitForLoadState('load', { timeout: 60_000 });
  const panel = page.locator('[data-safe-governance-setup-approve]');
  await expect(panel, `${who}: the approve button has its click handler`).toHaveAttribute('data-once', /(^|\s)safe-governance-setup-approve(\s|$)/, { timeout: 30_000 });
  const alert = panel.locator('[data-safe-governance-errors] .alert-danger');
  const routes: string[] = [];
  let messagesAnswers = 0;
  const onResponse = (r: { url(): string; status(): number }) => {
    const key = setupRouteKey(r.url());
    if (!key) return;
    if (key === 'setup_messages') messagesAnswers++;
    if (routes.length < 20) routes.push(`${key}=${r.status()}`);
  };
  page.on('response', onResponse);
  // Only the three exact sentences are kept, by number; any other "Confirmation … of …" text is
  // counted, never kept, so no page text reaches the log.
  const known = [1, 2, 3].map((n) => fill(COPY.confirmation, n, 3));
  const seen = new Set<number>();
  let others = 0;
  let watching = true;
  const watch = (async () => {
    while (watching) {
      for (const text of await settle(page.getByText(pattern(COPY.confirmation, true)).allInnerTexts(), CHECK_MS, [] as string[])) {
        const index = known.indexOf(text.trim());
        if (index >= 0) seen.add(index + 1);
        else others++;
      }
      await new Promise((r) => setTimeout(r, 250));
    }
  })();
  const notes: string[] = [];
  try {
    for (let round = 1; ; round++) {
      const answersBefore = messagesAnswers;
      await v7.approveTheSetup.click({ timeout: 30_000 });
      let started = false;
      for (const end = Date.now() + 15_000; !started && Date.now() < end; ) {
        started = messagesAnswers > answersBefore || (await settle(alert.count(), CHECK_MS, 0)) > 0 || (await settle(v7.approveTheSetup.isHidden(), CHECK_MS, false));
        if (!started) await page.waitForTimeout(200);
      }
      if (!started) {
        notes.push(`round ${round}: the press started nothing`);
        if (round < 2) continue;
        throw new Error(`${who}: pressing "${COPY.approveTheSetup}" started nothing, twice`);
      }
      let shown = '';
      try {
        await confirmPrompts(context, extensionId, 'approveSetup', log, {
          done: async () => {
            if (await settle(alert.count(), CHECK_MS, 0)) {
              shown = approveAlertKey((await alert.first().textContent({ timeout: 5_000 })) ?? '');
              throw new Error(`${who}: the page showed the "${shown}" error`);
            }
            return settle(v7.youApprovedTheSetup.isVisible(), CHECK_MS, false);
          },
          timeoutMs: 300_000,
          reopenIdleMs: 20_000,
          expect: forSite(SETUP_SIGN_PROMPT, page),
        });
        break;
      } catch (e) {
        if (!shown || round >= 2 || !RETRYABLE.includes(shown)) throw e;
        notes.push(`round ${round}: the page showed the "${shown}" error`);
        await expect(v7.approveTheSetup).toBeVisible({ timeout: 30_000 });
      }
    }
  } finally {
    watching = false;
    await settle(watch, 2 * CHECK_MS, undefined);
    page.off('response', onResponse);
    const hidden = await settle(v7.approveTheSetup.isHidden(), CHECK_MS, true);
    const alerts = await settle(alert.count(), CHECK_MS, -1);
    console.log(`[judge] ${who} approve: ${[...notes, `routes ${routes.join(' ') || 'none'}`, `button ${hidden ? 'hidden' : 'shown'}`, `alerts ${alerts}`, `confirmations seen ${[...seen].sort().join(',') || 'none'}`, `other confirmation texts ${others}`].join('; ')}`);
  }
  const sorted = [...seen].sort().map((n) => known[n - 1]);
  expect(others, `${who} saw no other confirmation text`).toBe(0);
  expect(sorted, `${who} read Confirmation 1 of 3 to 3 of 3`).toEqual(known);
  return sorted;
}
