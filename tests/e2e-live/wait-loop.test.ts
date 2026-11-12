// Unit guard for the live judge's wait loop: a reload the page itself cut short (it refreshed or
// moved on by itself at the same moment) is one more pass, not a failure, and the deadline holds.
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { isAbortedNavigation, reloadOnce, until, type ReloadingPage } from './wait-loop';

const ABORTED = 'page.reload: net::ERR_ABORTED; maybe frame was detached?';

/** A page whose reloads fail with the given messages in turn (null: the reload works); its loads fail with loadFails. */
function fakePage(reloads: Array<string | null>, clock?: { t: number }, loadFails = false) {
  const calls = { reload: 0, load: 0, waited: 0 };
  const page = {
    async waitForTimeout(ms: number) {
      calls.waited++;
      if (clock) clock.t += ms;
    },
    async reload() {
      const next = reloads[calls.reload++] ?? null;
      if (next !== null) throw new Error(next);
      return null;
    },
    async waitForLoadState() {
      calls.load++;
      if (loadFails) throw new Error('page.waitForLoadState: Timeout 30000ms exceeded.');
    },
  } as unknown as ReloadingPage;
  return { page, calls };
}

describe('isAbortedNavigation', () => {
  it('knows an aborted reload, a detached frame and an interrupted navigation', () => {
    expect(isAbortedNavigation(new Error(ABORTED))).toBe(true);
    expect(isAbortedNavigation(new Error('page.reload: net::ERR_ABORTED at http://x.test/'))).toBe(true);
    expect(isAbortedNavigation(new Error('page.reload: frame was detached'))).toBe(true);
    expect(isAbortedNavigation(new Error('page.reload: Navigation to "http://x.test/" is interrupted by another navigation to "http://x.test/y"'))).toBe(true);
  });

  it('does not excuse other failures', () => {
    expect(isAbortedNavigation(new Error('page.reload: net::ERR_CONNECTION_REFUSED'))).toBe(false);
    expect(isAbortedNavigation(new Error('page.reload: Timeout 30000ms exceeded.'))).toBe(false);
    expect(isAbortedNavigation(new Error('Target page, context or browser has been closed'))).toBe(false);
  });
});

describe('reloadOnce', () => {
  it('waits for load after a reload that works', async () => {
    const { page, calls } = fakePage([null]);
    expect(await reloadOnce(page)).toBe('reloaded');
    expect(calls.reload).toBe(1);
    expect(calls.load).toBe(1);
  });

  it('counts an aborted reload as done and still waits for the page to load', async () => {
    const { page, calls } = fakePage([ABORTED]);
    expect(await reloadOnce(page)).toBe('aborted');
    expect(calls.load).toBe(1);
  });

  it('throws any other reload error', async () => {
    const { page } = fakePage(['page.reload: net::ERR_CONNECTION_REFUSED']);
    await expect(reloadOnce(page)).rejects.toThrow('ERR_CONNECTION_REFUSED');
  });

  it('throws when the page fails to load after a reload that works', async () => {
    const { page } = fakePage([null], undefined, true);
    await expect(reloadOnce(page)).rejects.toThrow('waitForLoadState');
  });

  it('throws when the page it moved to fails to load after an aborted reload', async () => {
    const { page, calls } = fakePage([ABORTED], undefined, true);
    await expect(reloadOnce(page)).rejects.toThrow('waitForLoadState');
    expect(calls.load).toBe(1);
  });
});

describe('until', () => {
  it('reads the page again after an aborted reload and finds it ready', async () => {
    const { page, calls } = fakePage([ABORTED]);
    let reads = 0;
    const result = await until(page, 'Group voting is ready.', async () => ++reads === 2, [], 20 * 60_000, 10);
    expect(result).toEqual({ aborted: 1 });
    expect(calls.reload).toBe(1);
    expect(reads).toBe(2);
  });

  it('still stops at once on a stop text after an aborted reload', async () => {
    const { page } = fakePage([ABORTED]);
    let reads = 0;
    await expect(
      until(page, 'Group voting is ready.', async () => false, [['Setup failed', async () => ++reads === 2]], 20 * 60_000, 10),
    ).rejects.toThrow('Group voting is ready.: the page shows "Setup failed"');
  });

  it('keeps the same deadline when every reload is aborted', async () => {
    const clock = { t: 0 };
    const { page, calls } = fakePage(Array(100).fill(ABORTED), clock);
    await expect(until(page, 'Carried out', async () => false, [], 5 * 60_000, 60_000, () => clock.t)).rejects.toThrow(
      'Carried out: not shown within 5 minutes',
    );
    expect(calls.reload).toBe(6);
  });

  it('fails on any other reload error', async () => {
    const { page } = fakePage(['page.reload: net::ERR_CONNECTION_REFUSED']);
    await expect(until(page, 'Carried out', async () => false, [], 60_000, 10)).rejects.toThrow('ERR_CONNECTION_REFUSED');
  });
});

describe('the judge uses this loop', () => {
  it('has no reload of its own, and its three slow waits call it', () => {
    const judge = readFileSync(join(dirname(fileURLToPath(import.meta.url)), 'governance-judge.e2e.ts'), 'utf8');
    expect(judge).toMatch(/import \{ until \} from '\.\/wait-loop';/);
    expect(judge).not.toMatch(/page\.reload\(/);
    expect(judge).not.toMatch(/function until\(/);
    for (const what of ['Group voting is ready.', 'three members can vote', 'Carried out']) {
      expect(judge).toContain(`await until(page, '${what}', `);
    }
    expect(judge.match(/\buntil\(/g)?.length).toBe(3);
  });
});
