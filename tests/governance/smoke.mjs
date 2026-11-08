// D-08 smoke run: Safe-signed Snapshot space settings accepted by the Safe's isValidSignature.
//
//   node smoke.mjs --fork   anvil fork of Ethereum Sepolia (tests/e2e/fork/fork.sh up ethereum_sepolia)
//   node smoke.mjs --live   added in 25-03 (live Sepolia, mnemonic from CI secrets only)
//
// Keys here are anvil's public dev keys (the well-known "test test ... junk" mnemonic) and only
// ever sign on the local fork. Nothing in this file touches a real network.
import { randomBytes } from 'node:crypto';
import {
  createPublicClient,
  createWalletClient,
  encodeFunctionData,
  getAddress,
  hashDomain,
  hexToBigInt,
  http,
  keccak256,
  parseAbi,
  toHex,
  zeroAddress,
  zeroHash,
} from 'viem';
import { mnemonicToAccount } from 'viem/accounts';
import { sepolia } from 'viem/chains';
import { packetToBytes } from 'viem/ens';
import {
  ERC1271_MAGIC,
  NETWORKS,
  hatIdToUint,
  isValidLabel,
  safeMessageHash,
  safeMessageTypedData,
  snapshotHash,
  sortAndConcat,
  spaceSettings,
} from './lib/governance.mjs';

const ANVIL_MNEMONIC = 'test test test test test test test test test test test junk';
const FORK_RPC = process.env.GOV_FORK_RPC ?? 'http://127.0.0.1:18545';
const CHAIN_ID = 11155111;

const safeAbi = parseAbi([
  'function setup(address[] _owners, uint256 _threshold, address to, bytes data, address fallbackHandler, address paymentToken, uint256 payment, address paymentReceiver)',
  'function getOwners() view returns (address[])',
  'function getThreshold() view returns (uint256)',
  'function domainSeparator() view returns (bytes32)',
  'function isValidSignature(bytes32 _dataHash, bytes _signature) view returns (bytes4)',
]);
const factoryAbi = parseAbi([
  'function createProxyWithNonce(address _singleton, bytes initializer, uint256 saltNonce) returns (address proxy)',
]);
const usdcAbi = parseAbi([
  'function mint(address to, uint256 amount)',
  'function approve(address spender, uint256 amount) returns (bool)',
  'function balanceOf(address) view returns (uint256)',
]);
const registrarAbi = parseAbi([
  'function commit(bytes32 commitment)',
  'function makeCommitment(string label, address owner, bytes32 secret, address subregistry, address resolver, uint64 duration, bytes32 referrer) pure returns (bytes32)',
  'function register(string label, address owner, bytes32 secret, address subregistry, address resolver, uint64 duration, address paymentToken, bytes32 referrer) returns (uint256)',
]);
const helperAbi = parseAbi(['function findExactOwner(bytes name) view returns (address owner)']);

function step(msg) {
  console.log(`step: ${msg}`);
}

async function forkMode() {
  const net = NETWORKS[String(CHAIN_ID)];
  const transport = http(FORK_RPC);
  const pub = createPublicClient({ chain: sepolia, transport });
  const chainId = await pub.getChainId();
  if (chainId !== CHAIN_ID) throw new Error(`fork reports chain ${chainId}, expected ${CHAIN_ID}`);
  const meta = await pub.request({ method: 'anvil_metadata', params: [] });
  if (!meta?.clientVersion?.startsWith('anvil')) throw new Error('not an anvil node');
  if (!meta.forkedNetwork) throw new Error('anvil is not forking; the ENSv2 and Safe contracts must be real');
  const registrarCode = await pub.getCode({ address: net.ensRegistrar });
  if (!registrarCode || registrarCode === '0x') throw new Error('ENSv2 registrar has no code on this chain; not a Sepolia fork');

  const acct = (i) => mnemonicToAccount(ANVIL_MNEMONIC, { addressIndex: i });
  const owner0 = acct(0);
  const owner1 = acct(1);
  const payer = acct(2);
  const wallet = (account) => createWalletClient({ account, chain: sepolia, transport });
  const send = async (account, request) => {
    const hash = await wallet(account).writeContract(request);
    const rc = await pub.waitForTransactionReceipt({ hash });
    if (rc.status !== 'success') throw new Error(`transaction reverted: ${request.functionName}`);
    return rc;
  };

  // 1. Safe 1.4.1, two owners, threshold 2, canonical proxy factory and fallback handler.
  step('deploy a 2-of-2 Safe 1.4.1 through the canonical proxy factory');
  const initializer = encodeFunctionData({
    abi: safeAbi,
    functionName: 'setup',
    args: [[owner0.address, owner1.address], 2n, zeroAddress, '0x', net.safeFallbackHandler, zeroAddress, 0n, zeroAddress],
  });
  const saltNonce = hexToBigInt(toHex(randomBytes(16)));
  const sim = await pub.simulateContract({
    account: payer,
    address: net.safeProxyFactory,
    abi: factoryAbi,
    functionName: 'createProxyWithNonce',
    args: [net.safeSingletonL2, initializer, saltNonce],
  });
  const safe = getAddress(sim.result);
  await send(payer, sim.request);
  const owners = await pub.readContract({ address: safe, abi: safeAbi, functionName: 'getOwners' });
  const threshold = await pub.readContract({ address: safe, abi: safeAbi, functionName: 'getThreshold' });
  if (threshold !== 2n || owners.length !== 2) throw new Error('Safe set-up wrong');

  // 2. A Safe-owned ENSv2 name, paid for by another account (the register call itself sets the owner).
  const label = `gov-smoke-${randomBytes(4).toString('hex')}`;
  if (!isValidLabel(label)) throw new Error(`generated label is invalid: ${label}`);
  step(`register ${label}.eth with owner = the Safe, payer = another account`);
  const secret = toHex(randomBytes(32));
  const duration = 365n * 24n * 60n * 60n;
  const funds = 1000n * 10n ** 6n;
  await send(payer, { address: net.ensPaymentToken, abi: usdcAbi, functionName: 'mint', args: [payer.address, funds] });
  await send(payer, { address: net.ensPaymentToken, abi: usdcAbi, functionName: 'approve', args: [net.ensRegistrar, funds] });
  const commitment = await pub.readContract({
    address: net.ensRegistrar,
    abi: registrarAbi,
    functionName: 'makeCommitment',
    args: [label, safe, secret, zeroAddress, zeroAddress, duration, zeroHash],
  });
  await send(payer, { address: net.ensRegistrar, abi: registrarAbi, functionName: 'commit', args: [commitment] });
  await pub.request({ method: 'evm_increaseTime', params: [61] });
  await pub.request({ method: 'evm_mine', params: [] });
  await send(payer, {
    address: net.ensRegistrar,
    abi: registrarAbi,
    functionName: 'register',
    args: [label, safe, secret, zeroAddress, zeroAddress, duration, net.ensPaymentToken, zeroHash],
  });
  const ensName = `${label}.eth`;
  const nameOwner = await pub.readContract({
    address: net.ensUniversalHelper,
    abi: helperAbi,
    functionName: 'findExactOwner',
    args: [toHex(packetToBytes(ensName))],
  });
  if (getAddress(nameOwner) !== safe) throw new Error(`ENS owner is ${nameOwner}, expected the Safe ${safe}`);

  // 3. The settings, the Snapshot Space digest, and the Safe-wrapped message both owners sign.
  step('build the settings and sign SafeMessage with both owners, out of address order');
  const settings = spaceSettings({
    name: label,
    network: CHAIN_ID,
    hats: net.hats,
    memberHatId: '3758.1',
    proposerHatId: '3758.2',
    period: 3600,
    quorum: 2,
    safe,
    realityAddress: net.realityModuleMastercopy, // placeholder module for the dry run
  });
  const message = { from: safe, space: ensName, timestamp: BigInt(Math.floor(Date.now() / 1000)), settings: JSON.stringify(settings) };
  const digest = snapshotHash('Space', message);
  const typed = safeMessageTypedData(CHAIN_ID, safe, digest);
  const onchainDomain = await pub.readContract({ address: safe, abi: safeAbi, functionName: 'domainSeparator' });
  // Rebuild the domain separator from the typed data and compare with the Safe's own.
  if (hashDomain({ domain: typed.domain, types: { EIP712Domain: [{ name: 'chainId', type: 'uint256' }, { name: 'verifyingContract', type: 'address' }] } }) !== onchainDomain) {
    throw new Error('Safe domain separator differs from the typed data domain');
  }
  // hexToBigInt(owner0) > hexToBigInt(owner1): the descending order is signing owner 0 first.
  const signingOrder = [owner0, owner1].sort((a, b) => (hexToBigInt(a.address) > hexToBigInt(b.address) ? -1 : 1));
  const signed = [];
  for (const o of signingOrder) signed.push({ owner: o.address, signature: await o.signTypedData(typed) });
  const naive = signed.map((s) => s.signature).reduce((a, b) => a + b.slice(2));

  // 4. The hub's check: isValidSignature(snapshotHash, sigs) on the Safe returns 0x1626ba7e.
  step('isValidSignature on the Safe');
  const check = async (sigs) => {
    try {
      return await pub.readContract({ address: safe, abi: safeAbi, functionName: 'isValidSignature', args: [digest, sigs] });
    } catch {
      return 'reverted';
    }
  };
  const sorted = sortAndConcat(signed);
  const got = await check(sorted);
  if (got !== ERC1271_MAGIC) throw new Error(`isValidSignature returned ${got}, expected ${ERC1271_MAGIC}`);
  const wrongOrder = await check(naive);
  if (wrongOrder === ERC1271_MAGIC) throw new Error('signatures in signing (descending) order were accepted; the ordering case proves nothing');
  const oneSig = await check(signed[0].signature);
  if (oneSig === ERC1271_MAGIC) throw new Error('a single signature was accepted by a threshold-2 Safe');
  const wrongHash = await pub.readContract({ address: safe, abi: safeAbi, functionName: 'isValidSignature', args: [keccak256(digest), sorted] }).catch(() => 'reverted');
  if (wrongHash === ERC1271_MAGIC) throw new Error('signatures were accepted for a different hash');

  console.log(`FORK DRY RUN OK ${safe} ${ensName}`);
}

const mode = process.argv[2];
if (mode === '--fork') {
  forkMode().catch((e) => {
    console.error(`FAILED: ${e.shortMessage ?? e.message}`);
    process.exit(1);
  });
} else if (mode === '--live') {
  console.error('live mode is added in 25-03');
  process.exit(2);
} else {
  console.error('usage: node smoke.mjs --fork | --live');
  process.exit(2);
}
