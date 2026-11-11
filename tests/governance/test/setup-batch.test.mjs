// The setup batch generator takes the organizer's proposer rule and never defaults it (D-28, 25-16).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  setupBatch,
  hatsAbi,
  createCallAbi,
  ARBITRATOR_STAND_IN,
  ARBITRATOR_INIT_CODE_BYTES,
  ARBITRATOR_INIT_CODE_KECCAK256,
  ARBITRATOR_INIT_CODE_SHA256,
} from '../lib/governance.mjs';
import { decodeFunctionData, recoverAddress } from 'viem';

const HERE = dirname(fileURLToPath(import.meta.url));
const vectorText = readFileSync(join(HERE, '..', 'vectors', 'setup-batch.json'), 'utf8');
const vector = JSON.parse(vectorText);
// Reality.eth's Arbitrator code is GPL-3.0-only and is not in this repository (vendor/SOURCES.md). The batch is
// built with a same-length stand-in, and the Arbitrator's address is predicted from the pinned keccak256.
const input = { ...vector.inputs, arbitratorInitCode: ARBITRATOR_STAND_IN };

const mints = (calls) =>
  calls
    .filter((c) => c.to === input.a.hats && c.data.startsWith('0x641f776e'))
    .map((c) => decodeFunctionData({ abi: hatsAbi, data: c.data }).args);

test('setupBatch refuses without a proposer rule', () => {
  const { proposerRule, ...noRule } = input;
  assert.throws(() => setupBatch(noRule), /proposerRule/);
  assert.throws(() => setupBatch({ ...input, proposerRule: 'owners' }), /proposerRule/);
  assert.throws(() => setupBatch({ ...input, proposerRule: '' }), /proposerRule/);
});

test('proposer_hat: the 11 calls 25-06 executed, both hats to every owner', () => {
  const batch = setupBatch({ ...input, proposerRule: 'proposer_hat' });
  assert.equal(batch.calls.length, 11);
  assert.deepEqual(batch.calls, vector.calls);
  const m = mints(batch.calls);
  assert.deepEqual(
    m.map(([hat, wearer]) => [hat.toString(), wearer]),
    [
      [vector.predicted.memberHat, input.owners[0]],
      [vector.predicted.memberHat, input.owners[1]],
      [vector.predicted.proposerHat, input.owners[0]],
      [vector.predicted.proposerHat, input.owners[1]],
    ],
  );
});

test('any_member: 9 calls, member mints only, the proposer hat is still created', () => {
  const batch = setupBatch({ ...input, proposerRule: 'any_member' });
  assert.equal(batch.calls.length, 9);
  assert.deepEqual(batch.calls, vector.variants.any_member.calls);
  assert.equal(batch.packed, vector.variants.any_member.multiSendPacked);
  assert.ok(mints(batch.calls).every(([hat]) => hat.toString() === vector.predicted.memberHat));
  assert.deepEqual(batch.calls, vector.calls.slice(0, 9));
  // The predictions do not depend on the rule.
  assert.deepEqual(batch.predicted, vector.predicted);
});

test('the Arbitrator code is not vendored: Arbitrator.bin is gone (GPL-3.0-only)', () => {
  assert.equal(existsSync(join(HERE, '..', 'vendor', 'reality', 'Arbitrator.bin')), false);
});

test('the stand-in has the real code\'s length and is all 0xfe (INVALID)', () => {
  assert.equal(ARBITRATOR_INIT_CODE_BYTES, 3585);
  assert.match(ARBITRATOR_STAND_IN, /^0x(fe)+$/);
  assert.equal((ARBITRATOR_STAND_IN.length - 2) / 2, ARBITRATOR_INIT_CODE_BYTES);
  assert.equal(vector.inputs.arbitratorInitCodeBytes, ARBITRATOR_INIT_CODE_BYTES);
  assert.equal(vector.inputs.arbitratorInitCodeKeccak256, ARBITRATOR_INIT_CODE_KECCAK256);
  assert.equal(vector.inputs.arbitratorInitCodeSha256, ARBITRATOR_INIT_CODE_SHA256);
});

test('setup-batch.json holds the stand-in in all 7 places the init code appears, and nothing else that long', () => {
  const standIn = ARBITRATOR_STAND_IN.slice(2);
  assert.equal(vectorText.split(standIn).length - 1, 7);
  const deploy = decodeFunctionData({ abi: createCallAbi, data: vector.calls[0].data });
  assert.equal(deploy.args[1], ARBITRATOR_STAND_IN);
  const anyMember = decodeFunctionData({ abi: createCallAbi, data: vector.variants.any_member.calls[0].data });
  assert.equal(anyMember.args[1], ARBITRATOR_STAND_IN);
  // Every other hex string in the file is far shorter than the init code (the longest is a payload that embeds it).
  const withoutStandIn = vectorText.split(standIn).join('');
  for (const hex of withoutStandIn.match(/[0-9a-f]{64,}/gi) ?? []) assert.ok(hex.length < 2 * ARBITRATOR_INIT_CODE_BYTES, `a ${hex.length}-char hex run`);
});

test('the Arbitrator is predicted from the pinned keccak256, so every address is the one 25-06 deployed to', () => {
  assert.equal(vector.predicted.arbitrator, '0x5Fc5C0a135cda8894610aceBD81bc50e2CFab6b7');
  const batch = setupBatch({ ...input, proposerRule: 'proposer_hat' });
  assert.deepEqual(batch.predicted, vector.predicted);
  const { arbitratorInitCodeKeccak256, ...noPin } = input;
  assert.throws(() => setupBatch({ ...noPin, proposerRule: 'proposer_hat' }), /arbitratorInitCodeKeccak256/);
  assert.throws(() => setupBatch({ ...input, arbitratorInitCodeKeccak256: '0x1234', proposerRule: 'proposer_hat' }), /arbitratorInitCodeKeccak256/);
});

test('safeTxHash is the one recorded over the real code, and both owners\' signatures recover over it', async () => {
  assert.equal(vector.safeTxHash, '0x82e7337c41600e9e87bc3bab0b0330e55c16dc628595b5af040df33adb53f556');
  assert.equal(vector.signatures.length, 2);
  for (const { owner, signature } of vector.signatures) {
    assert.equal(await recoverAddress({ hash: vector.safeTxHash, signature }), owner);
  }
});
