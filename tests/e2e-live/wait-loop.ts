// The live judge's wait for a slow on-chain step: reload the page until it shows the result.
import type { Page } from '@playwright/test';

/** The page calls the wait makes, so a unit test can stand in for a browser. */
export type ReloadingPage = Pick<Page, 'waitForTimeout' | 'reload' | 'waitForLoadState'>;

/**
 * Whether a navigation error means only that the page moved on by itself while it was reloaded
 * (its own refresh or redirect won the race), not that the site failed.
 */
export function isAbortedNavigation(error: unknown): boolean {
  const m = error instanceof Error ? error.message : String(error);
  return /net::ERR_ABORTED|frame was detached|navigation .*interrupted by another navigation/i.test(m);
}

/**
 * Reloads the page once and waits for it to load. A reload the page aborted by moving on by
 * itself counts as done once the page it moved to has loaded: the next pass reads that page. Any
 * other reload error, and a page that then fails to load, is thrown.
 */
export async function reloadOnce(page: ReloadingPage): Promise<'reloaded' | 'aborted'> {
  try {
    await page.reload();
  }
  catch (error) {
    if (!isAbortedNavigation(error)) throw error;
    await page.waitForLoadState('load');
    return 'aborted';
  }
  await page.waitForLoadState('load');
  return 'reloaded';
}

/**
 * Reloads a page until `ready` shows, failing at once on any of `stops`, and failing when
 * `timeoutMs` passes. An aborted reload is one more pass inside the same deadline.
 */
export async function until(
  page: ReloadingPage,
  what: string,
  ready: () => Promise<boolean>,
  stops: Array<[string, () => Promise<boolean>]>,
  timeoutMs: number,
  everyMs = 30_000,
  now: () => number = Date.now,
): Promise<{ aborted: number }> {
  const end = now() + timeoutMs;
  let aborted = 0;
  for (;;) {
    if (await ready()) return { aborted };
    for (const [name, stop] of stops) {
      if (await stop()) throw new Error(`${what}: the page shows "${name}"`);
    }
    if (now() > end) throw new Error(`${what}: not shown within ${Math.round(timeoutMs / 60_000)} minutes`);
    await page.waitForTimeout(everyMs);
    if ((await reloadOnce(page)) === 'aborted') {
      aborted++;
      console.log(`[judge] ${what}: a reload was cut short by the page moving on; reading it again`);
    }
  }
}
