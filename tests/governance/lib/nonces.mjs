// A local, monotonic nonce counter for the smoke run (25-03 review round 2, fix 1).
//
// viem 2.57.10's built-in nonceManager only advances its counter once the previous nonce is above
// zero, so a fresh wallet could be handed nonce 0 three times running and the next send failed with
// "nonce too low". Here the pending transaction count is read once per account, and every later
// nonce comes from a local counter: n, n+1, n+2, ... A lagging RPC that keeps reporting the old
// count cannot cause a repeat.
//
// Gap policy: the smoke run aborts on its first failed send, so nothing tries to repair a gap. A
// send that throws may or may not have reached the mempool, and the RPC cannot say which (it may lag),
// so handing the nonce out again could collide and handing out the next one could leave a hole. The
// sender therefore marks the account as failed and next() refuses from then on, which makes "abort
// on the first failure" something the code enforces rather than something a reader has to trust.
// A reverted transaction is not a failed send: it has a receipt and used its nonce.

const keyOf = (address) => String(address).toLowerCase();

/** client needs getTransactionCount({ address, blockTag }); a viem public client has it. */
export function createNonceCounter(client) {
  const accounts = new Map();
  return {
    /** The next nonce for this account (a number). The first call reads the pending count. */
    async next(address) {
      const key = keyOf(address);
      let state = accounts.get(key);
      if (!state) {
        const base = Promise.resolve(client.getTransactionCount({ address, blockTag: 'pending' }));
        state = { base, used: 0, failed: false };
        accounts.set(key, state);
        // A failed read must not stick: the next call reads the count again.
        base.catch(() => {
          if (accounts.get(key) === state) accounts.delete(key);
        });
      }
      if (state.failed) {
        throw new Error(`nonce counter for ${address} is stopped: an earlier send from this account failed, so its next nonce is unknown`);
      }
      const offset = state.used++; // reserved before the first await, so concurrent callers differ
      return Number(await state.base) + offset;
    },
    /** Call when a send threw. Later next() calls for this account throw. */
    fail(address) {
      const state = accounts.get(keyOf(address));
      if (state) state.failed = true;
    },
  };
}

/**
 * Returns send(account, request) that always passes an explicit nonce, whatever the kind of write:
 * writeContract (has functionName), deployContract (has bytecode) or sendTransaction (anything else).
 * walletFor(account) returns a viem wallet client. The request object is copied, never changed, and
 * any `nonce` in it is replaced by the counter's.
 */
export function createSender({ nonces, walletFor }) {
  return async (account, request) => {
    const nonce = await nonces.next(account.address);
    const wallet = walletFor(account);
    const call = { ...request, nonce };
    try {
      if (request.functionName !== undefined) return await wallet.writeContract(call);
      if (request.bytecode !== undefined) return await wallet.deployContract(call);
      return await wallet.sendTransaction(call);
    } catch (e) {
      nonces.fail(account.address);
      throw e;
    }
  };
}
