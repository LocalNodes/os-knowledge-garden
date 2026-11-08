// Unit tests for the local nonce counter and the sender that always passes it (25-03 review round 2,
// fix 1). Run with `node --test`. A fake client stands in for the RPC: no network, no key.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createNonceCounter, createSender } from '../lib/nonces.mjs';

const A = '0xAAAAaaaaAAAAaaaaAAAAaaaaAAAAaaaaAAAAaaaa';
const B = '0xBBBBbbbbBBBBbbbbBBBBbbbbBBBBbbbbBBBBbbbb';

/** A client whose pending transaction count is fixed per account and never advances: a lagging RPC. */
function laggingClient(counts) {
  const calls = [];
  return {
    calls,
    getTransactionCount: async ({ address, blockTag }) => {
      calls.push({ address, blockTag });
      return counts[address.toLowerCase()] ?? 0;
    },
  };
}

test('a lagging RPC that keeps reporting 0 still yields 0, 1, 2 (the nonceManager 0,0,0 bug)', async () => {
  const client = laggingClient({ [A.toLowerCase()]: 0 });
  const nonces = createNonceCounter(client);
  assert.deepEqual([await nonces.next(A), await nonces.next(A), await nonces.next(A)], [0, 1, 2]);
});

test('an account that already has 5 transactions yields 5, 6, 7', async () => {
  const nonces = createNonceCounter(laggingClient({ [A.toLowerCase()]: 5 }));
  assert.deepEqual([await nonces.next(A), await nonces.next(A), await nonces.next(A)], [5, 6, 7]);
});

test('two accounts count independently, interleaved', async () => {
  const nonces = createNonceCounter(laggingClient({ [A.toLowerCase()]: 0, [B.toLowerCase()]: 9 }));
  assert.equal(await nonces.next(A), 0);
  assert.equal(await nonces.next(B), 9);
  assert.equal(await nonces.next(A), 1);
  assert.equal(await nonces.next(B), 10);
  assert.equal(await nonces.next(A), 2);
});

test('the pending count is read once per account, with blockTag pending, and the address case does not matter', async () => {
  const client = laggingClient({ [A.toLowerCase()]: 3 });
  const nonces = createNonceCounter(client);
  await nonces.next(A);
  await nonces.next(A.toLowerCase());
  await nonces.next(A.toUpperCase().replace('0X', '0x'));
  assert.equal(client.calls.length, 1);
  assert.equal(client.calls[0].blockTag, 'pending');
  await nonces.next(B);
  assert.equal(client.calls.length, 2);
});

test('calls made at the same moment get distinct, consecutive nonces', async () => {
  const nonces = createNonceCounter(laggingClient({ [A.toLowerCase()]: 4 }));
  const got = await Promise.all([nonces.next(A), nonces.next(A), nonces.next(A)]);
  assert.deepEqual([...got].sort((x, y) => x - y), [4, 5, 6]);
});

test('a failed read of the count is not cached: the next call reads it again', async () => {
  let n = 0;
  const nonces = createNonceCounter({
    getTransactionCount: async () => {
      if (n++ === 0) throw new Error('rpc down');
      return 7;
    },
  });
  await assert.rejects(nonces.next(A), /rpc down/);
  assert.equal(await nonces.next(A), 7);
});

test('after fail(address) that account hands out no more nonces, and another account is unaffected', async () => {
  const nonces = createNonceCounter(laggingClient({ [A.toLowerCase()]: 0, [B.toLowerCase()]: 0 }));
  assert.equal(await nonces.next(A), 0);
  nonces.fail(A);
  await assert.rejects(nonces.next(A), /earlier send/);
  assert.equal(await nonces.next(B), 0);
});

// --- the sender: every kind of write carries an explicit nonce --------------------------------------

function fakeWallet(log, { failWith } = {}) {
  const record = (kind) => async (args) => {
    if (failWith) throw failWith;
    log.push({ kind, args });
    return `0x${String(log.length).padStart(64, '0')}`;
  };
  return { writeContract: record('writeContract'), sendTransaction: record('sendTransaction'), deployContract: record('deployContract') };
}

test('writeContract, sendTransaction and deployContract all get an explicit, increasing nonce', async () => {
  const log = [];
  const account = { address: A };
  const send = createSender({ nonces: createNonceCounter(laggingClient({ [A.toLowerCase()]: 0 })), walletFor: () => fakeWallet(log) });
  await send(account, { address: B, abi: [], functionName: 'mint', args: [] });
  await send(account, { to: B, value: 1n });
  await send(account, { abi: [], bytecode: '0x00', args: [] });
  assert.deepEqual(log.map((l) => l.kind), ['writeContract', 'sendTransaction', 'deployContract']);
  assert.deepEqual(log.map((l) => l.args.nonce), [0, 1, 2]);
});

test('the sender never lets a request override the counter, and does not change the request object', async () => {
  const log = [];
  const send = createSender({ nonces: createNonceCounter(laggingClient({ [A.toLowerCase()]: 2 })), walletFor: () => fakeWallet(log) });
  const request = { address: B, abi: [], functionName: 'mint', nonce: 99 };
  await send({ address: A }, request);
  assert.equal(log[0].args.nonce, 2);
  assert.equal(request.nonce, 99);
});

test('a send that throws stops later sends from that account (the run aborts, no gap is papered over)', async () => {
  const boom = new Error('insufficient funds');
  let failing = false;
  const log = [];
  const send = createSender({
    nonces: createNonceCounter(laggingClient({ [A.toLowerCase()]: 0 })),
    walletFor: () => (failing ? fakeWallet(log, { failWith: boom }) : fakeWallet(log)),
  });
  await send({ address: A }, { address: B, abi: [], functionName: 'mint' });
  failing = true;
  await assert.rejects(send({ address: A }, { address: B, abi: [], functionName: 'mint' }), /insufficient funds/);
  failing = false;
  await assert.rejects(send({ address: A }, { address: B, abi: [], functionName: 'mint' }), /earlier send/);
  assert.equal(log.length, 1);
});
