// D-08 smoke run: Safe-signed Snapshot space settings accepted by the Safe's isValidSignature.
//
//   node smoke.mjs --fork   anvil fork of Ethereum Sepolia (tests/e2e/fork/fork.sh up ethereum_sepolia)
//   node smoke.mjs --live   the same path with account 0 paying, accounts 0 and 1 owning the Safe,
//                           and the Snapshot sequencer and hub as the last two steps (25-03)
//
// --fork keys are anvil's public dev keys (the well-known "test test ... junk" mnemonic) and only
// ever sign on the local fork. --live reads its mnemonic from SMOKE_MNEMONIC only (a CI secret on
// the real run, anvil's public mnemonic when it is pointed at the fork) and never prints it.
//
// --live environment (nothing else is read):
//   SMOKE_MNEMONIC       account 0 pays and owns; account 1 only signs
//   SMOKE_RPC_URL        Ethereum Sepolia JSON-RPC (a fork URL is fine)
//   SMOKE_EXPLORER_TX    transaction URL pattern with {hash} (optional; adds a link to STEP lines)
//   SMOKE_HUB            "off" skips the sequencer POST and the hub read; anything else is on
//   SMOKE_HAT_ID         dotted Hats id used by the member and proposer strategies (default 3758.1.1)
// Output lines: STEP <name> <value>, SMOKE PASS, SMOKE FAIL <step>, REFUSED <status> <body>.
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
  nonceManager,
  parseAbi,
  parseEventLogs,
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
  predictModuleProxy,
  realityEthAbi,
  realityInitializer,
  safeMessageHash,
  safeMessageTypedData,
  snapshotDomain,
  snapshotHash,
  snapshotTypes,
  sortAndConcat,
  spaceSettings,
} from './lib/governance.mjs';
import { COMMIT_AGE_TARGET_OFFSET, waitForChainTime } from './lib/chain-time.mjs';
import { hubSpaceVerdict } from './lib/hub-space.mjs';

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

  // nonceManager: back-to-back sends take their nonces from a local counter seeded from the pending count.
  const acct = (i) => mnemonicToAccount(ANVIL_MNEMONIC, { addressIndex: i, nonceManager });
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
  const commitReceipt = await send(payer, { address: net.ensRegistrar, abi: registrarAbi, functionName: 'commit', args: [commitment] });
  await waitForCommitAge(pub, commitReceipt, true);
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

// --- live mode ------------------------------------------------------------------------------------

const SEQUENCER = 'https://testnet.seq.snapshot.org/';
const HUB_GRAPHQL = 'https://testnet.hub.snapshot.org/graphql';
const DEFAULT_HAT_ID = '3758.1.1'; // the hat score-api's examples.json and 25-RESEARCH verified live
// zodiac-module-reality src/tasks/defaultTemplate.json (25-RESEARCH Pattern 6, "Template").
const ZODIAC_DEFAULT_TEMPLATE =
  '{"title": "Did the Snapshot proposal with the id %s pass the execution of the array of Module transactions with the hash 0x%s? The hash is the keccak of the concatenation of the individual EIP-712 hashes of the Module transactions. If this question was asked before the Snapshot proposal was resolved it should ALWAYS be resolved to INVALID!", "lang": "en", "type": "bool", "category": "DAO proposal"}';
const templateEventAbi = parseAbi(['event LogNewTemplate(uint256 indexed template_id, address indexed user, string question_text)']);

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let LIVE_REDACT = [];
const redact = (text) => LIVE_REDACT.reduce((t, v) => (v ? t.split(v).join('<redacted>') : t), String(text));
const line = (text) => console.log(redact(text));

/** Retry a read for a few seconds: a load-balanced public RPC can lag a block behind the receipt. */
async function settle(read, ok = (v) => v !== undefined && v !== null, tries = 8) {
  let last;
  for (let i = 0; i < tries; i++) {
    try {
      last = await read();
      if (ok(last)) return last;
    } catch (e) {
      last = undefined;
      if (i === tries - 1) throw e;
    }
    await sleep(3000);
  }
  return last;
}

/**
 * Hold register() until the chain's own clock is at least 61 s past the commit block's timestamp
 * (MIN_COMMITMENT_AGE is 60 s, plus 1). A wall-clock sleep is not enough: blocks can be late and a
 * public RPC can lag. On a fork the clock is moved with evm_increaseTime and evm_mine first, but the
 * poll of the head block's timestamp is still what decides.
 */
async function waitForCommitAge(pub, commitReceipt, onFork) {
  const commitBlock = await settle(() => pub.getBlock({ blockNumber: commitReceipt.blockNumber }));
  const target = commitBlock.timestamp + COMMIT_AGE_TARGET_OFFSET;
  if (onFork) {
    await pub.request({ method: 'evm_increaseTime', params: [Number(COMMIT_AGE_TARGET_OFFSET)] });
    await pub.request({ method: 'evm_mine', params: [] });
  }
  return waitForChainTime({
    getHeadTimestamp: async () => (await pub.getBlock({ blockTag: 'latest' })).timestamp,
    target,
    timeoutMs: onFork ? 20_000 : 5 * 60_000,
  });
}

async function liveMode() {
  const mnemonic = process.env.SMOKE_MNEMONIC;
  const rpc = process.env.SMOKE_RPC_URL;
  if (!mnemonic) throw new Error('SMOKE_MNEMONIC is not set');
  if (!rpc) throw new Error('SMOKE_RPC_URL is not set');
  LIVE_REDACT = [mnemonic, rpc];
  const explorerTx = process.env.SMOKE_EXPLORER_TX ?? '';
  const hubOn = (process.env.SMOKE_HUB ?? 'on') !== 'off';
  const hatId = process.env.SMOKE_HAT_ID || DEFAULT_HAT_ID;
  hatIdToUint(hatId); // throws on a malformed dotted id

  const net = NETWORKS[String(CHAIN_ID)];
  const transport = http(rpc);
  const pub = createPublicClient({ chain: sepolia, transport });
  const chainId = await pub.getChainId();
  if (chainId !== CHAIN_ID) throw new Error(`RPC reports chain ${chainId}, expected ${CHAIN_ID}`);
  // On the fork the clock can be moved; on the live chain a real wait is the only way.
  const onFork = await pub
    .request({ method: 'anvil_metadata', params: [] })
    .then((m) => Boolean(m?.forkedNetwork))
    .catch(() => false);

  // nonceManager: after a receipt a lagging RPC can still report the old transaction count, which made
  // the next send fail with "nonce too low". The manager keeps a local counter instead.
  const acct = (i) => mnemonicToAccount(mnemonic, { addressIndex: i, nonceManager });
  const owner0 = acct(0);
  const owner1 = acct(1);
  if (owner0.address === owner1.address) throw new Error('accounts 0 and 1 are the same address');
  const wallet = (account) => createWalletClient({ account, chain: sepolia, transport });
  const link = (hash) => (explorerTx ? ` ${explorerTx.replace('{hash}', hash)}` : '');
  const tx = async (name, request) => {
    const hash = await wallet(owner0).writeContract(request);
    const rc = await pub.waitForTransactionReceipt({ hash });
    if (rc.status !== 'success') throw new Error(`transaction reverted: ${request.functionName}`);
    line(`STEP ${name} ${hash}${link(hash)}`);
    return rc;
  };

  let current = 'start';
  const at = (name) => {
    current = name;
  };
  try {
    at('balances');
    const bal0 = await pub.getBalance({ address: owner0.address });
    const bal1 = await pub.getBalance({ address: owner1.address });
    line(`STEP owner0 ${owner0.address} balance ${bal0} wei`);
    line(`STEP owner1 ${owner1.address} balance ${bal1} wei`);
    if (bal0 === 0n) throw new Error('account 0 holds no ETH; fund it (TA-3) before the live run');

    // 1. Safe 1.4.1, two owners, threshold 2 (account 0 pays).
    at('safe-deploy');
    const initializer = encodeFunctionData({
      abi: safeAbi,
      functionName: 'setup',
      args: [[owner0.address, owner1.address], 2n, zeroAddress, '0x', net.safeFallbackHandler, zeroAddress, 0n, zeroAddress],
    });
    const saltNonce = hexToBigInt(toHex(randomBytes(16)));
    const sim = await pub.simulateContract({
      account: owner0,
      address: net.safeProxyFactory,
      abi: factoryAbi,
      functionName: 'createProxyWithNonce',
      args: [net.safeSingletonL2, initializer, saltNonce],
    });
    const safe = getAddress(sim.result);
    await tx('safe-deploy', sim.request);
    at('safe-readback');
    const owners = await settle(() => pub.readContract({ address: safe, abi: safeAbi, functionName: 'getOwners' }), (v) => v.length > 0);
    const threshold = await settle(() => pub.readContract({ address: safe, abi: safeAbi, functionName: 'getThreshold' }), (v) => v > 0n);
    line(`STEP safe-owners ${safe} owners ${owners.join(',')} threshold ${threshold}`);
    if (threshold !== 2n || owners.length !== 2) throw new Error('Safe set-up wrong: need two owners and threshold 2');
    const want = new Set([owner0.address, owner1.address]);
    if (!owners.every((o) => want.has(getAddress(o)))) throw new Error('Safe owners differ from accounts 0 and 1');

    // 2. A Safe-owned ENSv2 name; account 0 pays, the register call itself sets the owner.
    at('ens-register');
    const label = `ln-smoke-${randomBytes(3).toString('hex')}`;
    if (!isValidLabel(label)) throw new Error(`generated label is invalid: ${label}`);
    const ensName = `${label}.eth`;
    const secret = toHex(randomBytes(32));
    const duration = 365n * 24n * 60n * 60n;
    const funds = 100n * 10n ** 6n;
    await tx('usdc-mint', { address: net.ensPaymentToken, abi: usdcAbi, functionName: 'mint', args: [owner0.address, funds] });
    await tx('usdc-approve', { address: net.ensPaymentToken, abi: usdcAbi, functionName: 'approve', args: [net.ensRegistrar, funds] });
    const commitment = await pub.readContract({
      address: net.ensRegistrar,
      abi: registrarAbi,
      functionName: 'makeCommitment',
      args: [label, safe, secret, zeroAddress, zeroAddress, duration, zeroHash],
    });
    const commitReceipt = await tx('ens-commit', { address: net.ensRegistrar, abi: registrarAbi, functionName: 'commit', args: [commitment] });
    const headTime = await waitForCommitAge(pub, commitReceipt, onFork);
    line(`STEP ens-commit-age head block time ${headTime}`);
    await tx('ens-register', {
      address: net.ensRegistrar,
      abi: registrarAbi,
      functionName: 'register',
      args: [label, safe, secret, zeroAddress, zeroAddress, duration, net.ensPaymentToken, zeroHash],
    });
    at('ens-owner');
    const nameOwner = await settle(
      () => pub.readContract({ address: net.ensUniversalHelper, abi: helperAbi, functionName: 'findExactOwner', args: [toHex(packetToBytes(ensName))] }),
      (v) => getAddress(v) === safe,
    );
    line(`STEP ens-owner ${ensName} owner ${getAddress(nameOwner)}`);
    if (getAddress(nameOwner) !== safe) throw new Error(`ENS owner is ${nameOwner}, expected the Safe ${safe}`);

    // 3. The Zodiac default question template on Reality 3.0.
    at('reality-template');
    const tplSim = await pub.simulateContract({ account: owner0, address: net.reality, abi: realityEthAbi, functionName: 'createTemplate', args: [ZODIAC_DEFAULT_TEMPLATE] });
    const tplReceipt = await tx('reality-template', tplSim.request);
    const logs = parseEventLogs({ abi: templateEventAbi, logs: tplReceipt.logs, eventName: 'LogNewTemplate' });
    if (logs.length !== 1) throw new Error(`expected one LogNewTemplate event, found ${logs.length}`);
    const templateId = logs[0].args.template_id;
    line(`STEP reality-template-id ${templateId.toString(10)}`);

    // 4. Settings: hats strategy, quorum 1, period 600, safeSnap with the predicted module address.
    at('settings');
    const moduleInit = realityInitializer({
      owner: safe, avatar: safe, target: safe, oracle: net.reality,
      timeout: 600, cooldown: 300, expiration: 0, bond: 10n ** 15n, templateId, arbitrator: net.arbitrator,
    });
    const realityAddress = predictModuleProxy(net.moduleProxyFactory, net.realityModuleMastercopy, moduleInit, 0n);
    const settings = spaceSettings({
      name: label,
      network: CHAIN_ID,
      hats: net.hats,
      memberHatId: hatId,
      proposerHatId: hatId,
      period: 600,
      quorum: 1,
      safe,
      realityAddress,
    });
    const settingsJson = JSON.stringify(settings);
    line(`STEP settings ${ensName} hat ${hatId} module ${realityAddress}`);

    // 5. Both owners sign the SafeMessage; the Safe must accept it before anything is posted.
    at('safe-signatures');
    const timestamp = Math.floor(Date.now() / 1000);
    const message = { from: safe, space: ensName, timestamp: BigInt(timestamp), settings: settingsJson };
    const digest = snapshotHash('Space', message);
    const typed = safeMessageTypedData(CHAIN_ID, safe, digest);
    const signed = [];
    for (const o of [owner0, owner1]) signed.push({ owner: o.address, signature: await o.signTypedData(typed) });
    const sig = sortAndConcat(signed);
    at('is-valid-signature');
    const magic = await settle(
      () => pub.readContract({ address: safe, abi: safeAbi, functionName: 'isValidSignature', args: [digest, sig] }),
      (v) => v === ERC1271_MAGIC,
      3,
    );
    line(`STEP is-valid-signature ${magic}`);
    if (magic !== ERC1271_MAGIC) throw new Error(`isValidSignature returned ${magic}, expected ${ERC1271_MAGIC}`);

    // 6. The sequencer and the hub (skipped when SMOKE_HUB=off).
    if (!hubOn) {
      line('STEP hub skipped (SMOKE_HUB=off)');
    } else {
      at('sequencer');
      const envelope = {
        address: safe,
        sig,
        data: {
          domain: { ...snapshotDomain },
          types: { Space: snapshotTypes.Space.map((f) => ({ ...f })) },
          message: { from: safe, space: ensName, timestamp, settings: settingsJson },
        },
      };
      const res = await fetch(SEQUENCER, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify(envelope),
      });
      const body = await res.text();
      if (res.status !== 200) {
        line(`REFUSED ${res.status} ${body.replace(/\s+/g, ' ').slice(0, 2000)}`);
        process.exit(3);
      }
      let receipt = {};
      try {
        receipt = JSON.parse(body);
      } catch {
        // keep the raw text in the STEP line below
      }
      line(`STEP sequencer ${receipt.id ?? body.slice(0, 200)} ipfs ${receipt.ipfs ?? '-'} relayer ${receipt.relayer ?? '-'}`);

      at('hub-space');
      const query = 'query($id:String!){ space(id:$id){ id network admins moderators strategies{ name network params } validation{ name params } voting{ quorum period } plugins } }';
      let space = null;
      const until = Date.now() + 3 * 60_000;
      while (Date.now() < until) {
        const r = await fetch(HUB_GRAPHQL, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ query, variables: { id: ensName } }),
        });
        const j = await r.json().catch(() => ({}));
        if (j?.data?.space) {
          space = j.data.space;
          break;
        }
        await sleep(10_000);
      }
      if (!space) throw new Error('the hub did not serve the space within 3 minutes');
      line(`STEP hub-space ${JSON.stringify(space)}`);
      // Everything that was signed must be what the hub stores (strategies and the hat id, validation,
      // quorum, period, safeSnap, empty admins and moderators), not just the network.
      const verdict = hubSpaceVerdict(settings, space);
      if (!verdict.ok) {
        line(verdict.line);
        process.exit(1);
      }
      line('STEP hub-space-verified the hub serves the signed settings');
    }
    console.log('SMOKE PASS');
  } catch (e) {
    line(`SMOKE FAIL ${current}`);
    line(`${e?.name ?? 'Error'}: ${e?.shortMessage ?? e?.message ?? e}`);
    process.exit(1);
  }
}

const mode = process.argv[2];
if (mode === '--fork') {
  forkMode().catch((e) => {
    console.error(`FAILED: ${e.shortMessage ?? e.message}`);
    process.exit(1);
  });
} else if (mode === '--live') {
  liveMode().catch((e) => {
    // Set-up errors before the step tracker exists (missing env, wrong chain). Never the mnemonic.
    line(`SMOKE FAIL setup`);
    line(`${e?.name ?? 'Error'}: ${e?.shortMessage ?? e?.message ?? e}`);
    process.exit(1);
  });
} else {
  console.error('usage: node smoke.mjs --fork | --live');
  process.exit(2);
}
