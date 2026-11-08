// Unit tests for the chain-clock wait that gates ENS register (25-03 review fix 1). `node --test`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { COMMIT_AGE_TARGET_OFFSET, waitForChainTime } from '../lib/chain-time.mjs';

function fakeClock() {
  let t = 0;
  return { now: () => t, sleep: async (ms) => { t += ms; } };
}

test('the target is the commit block time plus 61 seconds (MIN_COMMITMENT_AGE 60, plus 1)', () => {
  assert.equal(COMMIT_AGE_TARGET_OFFSET, 61n);
});

test('returns at once when the head is already at the target', async () => {
  const clock = fakeClock();
  let reads = 0;
  const got = await waitForChainTime({ getHeadTimestamp: async () => { reads++; return 1061n; }, target: 1061n, ...clock });
  assert.equal(got, 1061n);
  assert.equal(reads, 1);
});

test('keeps polling until the head timestamp reaches the target, not for a fixed time', async () => {
  const clock = fakeClock();
  const heads = [1000n, 1012n, 1024n, 1036n, 1048n, 1060n, 1072n];
  let i = 0;
  const got = await waitForChainTime({ getHeadTimestamp: async () => heads[Math.min(i++, heads.length - 1)], target: 1061n, intervalMs: 3000, ...clock });
  assert.equal(got, 1072n);
  assert.equal(i, 7);
  assert.equal(clock.now(), 6 * 3000);
});

test('a head one second short of the target is not enough', async () => {
  const clock = fakeClock();
  const heads = [1060n, 1061n];
  let i = 0;
  const got = await waitForChainTime({ getHeadTimestamp: async () => heads[i++], target: 1061n, ...clock });
  assert.equal(got, 1061n);
  assert.equal(i, 2);
});

test('times out with a message that names the head, the target and the wait', async () => {
  const clock = fakeClock();
  await assert.rejects(
    waitForChainTime({ getHeadTimestamp: async () => 1000n, target: 1061n, intervalMs: 3000, timeoutMs: 30_000, ...clock }),
    (e) => {
      assert.match(e.message, /head block time 1000/);
      assert.match(e.message, /1061/);
      assert.match(e.message, /30 s/);
      return true;
    },
  );
});

test('a transient read error is retried, a persistent one surfaces at the timeout', async () => {
  const clock = fakeClock();
  let n = 0;
  const got = await waitForChainTime({
    getHeadTimestamp: async () => { if (n++ < 2) throw new Error('rpc lag'); return 2000n; },
    target: 1500n,
    ...clock,
  });
  assert.equal(got, 2000n);
  await assert.rejects(
    waitForChainTime({ getHeadTimestamp: async () => { throw new Error('rpc down'); }, target: 1n, timeoutMs: 9000, ...fakeClock() }),
    /rpc down/,
  );
});
