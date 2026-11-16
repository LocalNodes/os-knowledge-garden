// Fixed-message boundaries for steps whose failures could print page text, addresses or a filled
// value into a public CI log.
//
// Playwright's own errors carry a call log and DOM previews: a failing fill() prints the value
// it was filling, a failing goto() prints the URL, and a failing assertion prints what it received.
// These helpers drop all of that and throw only the fixed message: no original text, no stack
// from the original, no cause.

/** Runs `action`; if it fails, throws only `message`. */
export async function fixedStep<T>(action: () => Promise<T>, message: string): Promise<T> {
  let result: { ok: true; value: T } | { ok: false };
  try {
    result = { ok: true, value: await action() };
  } catch {
    result = { ok: false };
  }
  // Thrown outside the catch block, so nothing of the original error is attached.
  if (!result.ok) throw new Error(message);
  return result.value;
}

/**
 * Runs `action`, then returns what `wait` gave. `wait` must have been started just before
 * (a waitForURL or waitForResponse set up before the click it watches). Its failure is handled
 * at once, and both outcomes are collected before anything is thrown, so neither is left
 * running or rejecting unhandled: a failed action still waits for `wait` to settle (at its own
 * timeout at the latest). A failed action throws `actionMessage`; a failed wait throws
 * `waitMessage`.
 */
export async function actThenWait<T>(wait: Promise<T>, action: () => Promise<unknown>, actionMessage: string, waitMessage = actionMessage): Promise<T> {
  const waited = wait.then((value) => ({ ok: true as const, value }), () => ({ ok: false as const }));
  const acted = await Promise.resolve().then(action).then(() => true, () => false);
  const result = await waited;
  if (!acted) throw new Error(actionMessage);
  if (!result.ok) throw new Error(waitMessage);
  return result.value;
}
