// Wait on the chain's own clock, not the wall clock.
//
// ENSv2's ETHRegistrar refuses register() until the head block is at least MIN_COMMITMENT_AGE (60 s)
// past the commit's block time. A fixed sleep after the commit receipt can fall short when blocks are
// late or a load-balanced RPC lags, so the live run polls the head block's timestamp instead.

/** MIN_COMMITMENT_AGE is 60 s; one more second keeps the register call off the boundary. */
export const COMMIT_AGE_TARGET_OFFSET = 61n;

const defaultSleep = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * Poll until the head block's timestamp is at least `target` (bigint, seconds) and return it.
 * A failed read is retried; if the target is not reached before `timeoutMs` the error names the last
 * head time, the target and the wait (and the last read error, if any).
 */
export async function waitForChainTime({
  getHeadTimestamp,
  target,
  intervalMs = 3000,
  timeoutMs = 5 * 60_000,
  sleep = defaultSleep,
  now = Date.now,
}) {
  const deadline = now() + timeoutMs;
  let head;
  let lastError;
  for (;;) {
    try {
      head = await getHeadTimestamp();
      lastError = undefined;
      if (head >= target) return head;
    } catch (e) {
      lastError = e;
    }
    if (now() + intervalMs > deadline) break;
    await sleep(intervalMs);
  }
  const seen = head === undefined ? 'no head block could be read' : `head block time ${head}`;
  const why = lastError ? ` (last read error: ${lastError?.shortMessage ?? lastError?.message ?? lastError})` : '';
  throw new Error(`${seen} did not reach ${target} within ${Math.round(timeoutMs / 1000)} s${why}`);
}
