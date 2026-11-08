// One pinned-viem generator for every expected hash the PHP and relayer tests compare against.
//
//   node vectors.mjs            write vectors/*.json
//   node vectors.mjs --check    regenerate in memory; exit 1 on any byte difference from the committed files
//
// All inputs below are fixed and committed. The only keys are anvil's public dev keys.
import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  concat,
  createPublicClient,
  createWalletClient,
  decodeEventLog,
  encodeAbiParameters,
  encodeFunctionData,
  getAddress,
  hashDomain,
  hashTypedData,
  hexToBigInt,
  http,
  keccak256,
  parseAbi,
  toFunctionSelector,
  toHex,
  zeroAddress,
  zeroHash,
} from 'viem';
import { normalize } from 'viem/ens';
import { mnemonicToAccount } from 'viem/accounts';
import { sepolia } from 'viem/chains';
import {
  ERC1271_MAGIC,
  NETWORKS,
  REALITY_DOMAIN_TYPEHASH,
  REALITY_TRANSACTION_TYPEHASH,
  SAFE_MSG_TYPEHASH,
  encodeSnapshotHash,
  ensCommitment,
  ensRegistrarAbi,
  hatIdToUint,
  hatsAbi,
  isValidLabel,
  moduleProxyFactoryAbi,
  moduleTransactionHash,
  multiSendCalldata,
  multiSendPack,
  packedTxHashesKeccak,
  passRule,
  predictModuleProxy,
  realityEthAbi,
  realityInitializer,
  realityModuleAbi,
  realityQuestion,
  realityQuestionId,
  registrationTypedData,
  SAFE_PROXY_CREATION_CODE,
  SAFE_PROXY_CREATION_CODE_KECCAK,
  setupBatch,
  uintToHatId,
  safeMessageHash,
  safeMessageTypedData,
  snapshotDomain,
  snapshotHash,
  snapshotTypesJson,
  sortAndConcat,
  spaceSettings,
} from './lib/governance.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const OUT = join(HERE, 'vectors');
const ANVIL_MNEMONIC = 'test test test test test test test test test test test junk';
const SEPOLIA = 11155111;
const NET = NETWORKS[String(SEPOLIA)];

// Fixed inputs shared by several vectors.
export const FIXED = {
  safe: getAddress('0x' + '5afe'.repeat(10)),
  module: getAddress('0x' + '4d4f'.repeat(10)),
  timestamp: 1790000000,
  space: 'gov-vectors.eth',
};

const sha256 = (s) => '0x' + createHash('sha256').update(s).digest('hex');
const anvil = (i) => mnemonicToAccount(ANVIL_MNEMONIC, { addressIndex: i });

// --- settings.json -------------------------------------------------------------------------

function settingsInput() {
  return {
    name: 'Gov Vectors',
    network: SEPOLIA,
    hats: NET.hats,
    memberHatId: '3758.1',
    proposerHatId: '3758.2',
    period: 86400,
    quorum: 3,
    safe: FIXED.safe,
    realityAddress: FIXED.module,
  };
}

function settingsVector() {
  const input = settingsInput();
  const settings = spaceSettings(input);
  return { input, settings, serialized: JSON.stringify(settings) };
}

// --- snapshot.json -------------------------------------------------------------------------

function snapshotVector() {
  const settings = settingsVector().serialized;
  const space = { from: FIXED.safe, space: FIXED.space, timestamp: FIXED.timestamp, settings };
  const proposer = anvil(3).address;
  const proposal = {
    from: proposer,
    space: FIXED.space,
    timestamp: FIXED.timestamp,
    type: 'basic',
    title: 'Fund the seed library',
    body: 'Pay 50 USDC to the seed library.',
    discussion: '',
    choices: ['For', 'Against', 'Abstain'],
    labels: [],
    start: FIXED.timestamp,
    end: FIXED.timestamp + 86400,
    snapshot: 12087057,
    plugins: '{}',
    privacy: '',
    app: 'localnodes',
  };
  const vote = {
    from: proposer,
    space: FIXED.space,
    timestamp: FIXED.timestamp + 60,
    proposal: '0x' + 'ab'.repeat(32),
    choice: 1,
    reason: '',
    app: 'localnodes',
    metadata: '{}',
  };
  const entry = (primaryType, message) => ({
    types: snapshotTypesJson(primaryType),
    typesSha256: sha256(snapshotTypesJson(primaryType)),
    message,
    digest: snapshotHash(primaryType, message),
  });
  return {
    domain: snapshotDomain,
    space: entry('Space', space),
    proposal: entry('Proposal', proposal),
    vote: entry('Vote', vote),
  };
}

// --- safe-message.json ---------------------------------------------------------------------

async function safeMessageVector() {
  const hash = snapshotVector().space.digest;
  const typed = safeMessageTypedData(SEPOLIA, FIXED.safe, hash);
  const accounts = [anvil(0), anvil(1)];
  const owners = accounts.map((a) => a.address);
  const sigs = [];
  for (const a of accounts) sigs.push({ owner: a.address, signature: await a.signTypedData(typed) });
  const ascending = [...owners].sort((x, y) => (hexToBigInt(x) < hexToBigInt(y) ? -1 : 1));
  const byOwner = Object.fromEntries(sigs.map((s) => [s.owner, s]));
  const mkCase = (name, signingOrder) => {
    const entries = signingOrder.map((o) => byOwner[o]);
    return {
      name,
      signingOrder,
      ascendingOwners: ascending,
      signingOrderDiffersFromOwnerOrder: signingOrder.join() !== ascending.join(),
      signatures: entries,
      concatenated: sortAndConcat(entries),
      naiveConcatInSigningOrder: '0x' + entries.map((e) => e.signature.slice(2)).join(''),
    };
  };
  return {
    chainId: SEPOLIA,
    safe: FIXED.safe,
    safeMsgTypehash: SAFE_MSG_TYPEHASH,
    erc1271Magic: ERC1271_MAGIC,
    snapshotHash: hash,
    encodedMessage: encodeSnapshotHash(hash),
    domainSeparator: hashDomain({
      domain: typed.domain,
      types: { EIP712Domain: [{ name: 'chainId', type: 'uint256' }, { name: 'verifyingContract', type: 'address' }] },
    }),
    safeMessageDigest: safeMessageHash(SEPOLIA, FIXED.safe, hash),
    typedData: typed,
    owners,
    note: 'Keys are anvil public dev keys 0 and 1. Case two signs in descending address order; concatenated is always ascending.',
    cases: [
      mkCase('signed in ascending owner order', ascending),
      mkCase('signed in descending owner order (differs from owner order)', [...ascending].reverse()),
    ],
  };
}


// --- registration.json (D-18) --------------------------------------------------------------

function registrationVector() {
  const message = {
    relayer: anvil(5).address,
    chainId: SEPOLIA,
    safe: FIXED.safe,
    space: FIXED.space,
    community: 'Gov Vectors',
  };
  const typed = registrationTypedData(SEPOLIA, message);
  const digest = hashTypedData(typed);
  return {
    domain: typed.domain,
    types: JSON.stringify({ SponsorRegistration: typed.types.SponsorRegistration }),
    message,
    digest,
    safeMessageDigest: safeMessageHash(SEPOLIA, FIXED.safe, digest),
    note: 'The Group Safe owners sign SafeMessage{abi.encode(digest)} under the Safe domain; the relayer checks it with isValidSignature on the Safe.',
  };
}

// --- reality.json --------------------------------------------------------------------------

export const REALITY_SELECTORS_EXPECTED = {
  // 25-RESEARCH.md Pattern 5/6 (computed there with viem, matching the source constants).
  addProposal: '0x8dcd2661',
  executeProposalWithIndex: '0x14604b8c',
  submitAnswer: '0x77f325df',
  claimWinnings: '0x1101a0fd',
  withdraw: '0x3ccfd60b',
  createTemplate: '0x83bf4609',
  getTransactionHash: '0x2b500041',
  buildQuestion: '0x39eafa6f',
  getQuestionId: '0xcc3b43b1',
};
const REALITY_SIGNATURES = {
  addProposal: 'addProposal(string,bytes32[])',
  executeProposalWithIndex: 'executeProposalWithIndex(string,bytes32[],address,uint256,bytes,uint8,uint256)',
  submitAnswer: 'submitAnswer(bytes32,bytes32,uint256)',
  claimWinnings: 'claimWinnings(bytes32,bytes32[],address[],uint256[],bytes32[])',
  withdraw: 'withdraw()',
  createTemplate: 'createTemplate(string)',
  getTransactionHash: 'getTransactionHash(address,uint256,bytes,uint8,uint256)',
  buildQuestion: 'buildQuestion(string,bytes32[])',
  getQuestionId: 'getQuestionId(string,uint256)',
};

const erc20Abi = parseAbi(['function transfer(address to, uint256 value) returns (bool)']);
const PROPOSAL_ID = '0x' + 'cd'.repeat(32);

function realityTxs() {
  const recipient = anvil(4).address;
  const native = { label: 'native payment', to: recipient, value: '10000000000000000', data: '0x', operation: 0 };
  const erc20 = {
    label: 'ERC-20 transfer',
    to: NET.ensPaymentToken,
    value: '0',
    data: encodeFunctionData({ abi: erc20Abi, functionName: 'transfer', args: [recipient, 2500000n] }),
    operation: 0,
  };
  return { native, erc20 };
}

function realityCases() {
  const { native, erc20 } = realityTxs();
  const base = {
    owner: FIXED.safe,
    avatar: FIXED.safe,
    target: FIXED.safe,
    oracle: NET.reality,
    cooldown: 300,
    expiration: 0,
    bond: '1000000000000000',
    templateId: 7,
    arbitrator: NET.arbitrator,
  };
  const variants = [
    { name: 'timeout 3600 s', timeout: 3600, saltNonce: 1 },
    { name: 'timeout 600 s (testnet recommendation)', timeout: 600, saltNonce: 2 },
  ];
  return variants.map((v) => {
    const params = { ...base, timeout: v.timeout };
    const initializer = realityInitializer(params);
    const module = predictModuleProxy(NET.moduleProxyFactory, NET.realityModuleMastercopy, initializer, v.saltNonce);
    const hashFor = (tx, nonce) => moduleTransactionHash(SEPOLIA, module, { ...tx, nonce });
    const txs = [
      { ...native, nonce: 0, txHash: hashFor(native, 0) },
      { ...erc20, nonce: 0, txHash: hashFor(erc20, 0) },
      { ...native, nonce: 1, txHash: hashFor(native, 1) },
      { ...erc20, nonce: 1, txHash: hashFor(erc20, 1) },
    ];
    const proposals = [
      { name: 'single native payment', txHashes: [txs[0].txHash], txs: [txs[0]] },
      { name: 'native payment then ERC-20 transfer', txHashes: [txs[0].txHash, txs[3].txHash], txs: [txs[0], txs[3]] },
    ].map((pr) => {
      const question = realityQuestion(PROPOSAL_ID, pr.txHashes);
      return {
        ...pr,
        proposalId: PROPOSAL_ID,
        txHashesPackedKeccak: packedTxHashesKeccak(pr.txHashes),
        question,
        questionId: realityQuestionId({
          templateId: params.templateId, question, arbitrator: params.arbitrator, timeout: params.timeout,
          bond: params.bond, oracle: params.oracle, module, nonce: 0,
        }),
      };
    });
    return { name: v.name, params, saltNonce: v.saltNonce, initializer, initializerSelector: initializer.slice(0, 10), predictedModule: module, txs, proposals };
  });
}

function realityVector() {
  const selectors = {};
  for (const [name, sig] of Object.entries(REALITY_SIGNATURES)) selectors[name] = { signature: sig, selector: toFunctionSelector(sig) };
  return {
    chainId: SEPOLIA,
    factory: NET.moduleProxyFactory,
    mastercopy: NET.realityModuleMastercopy,
    domainTypehash: REALITY_DOMAIN_TYPEHASH,
    transactionTypehash: REALITY_TRANSACTION_TYPEHASH,
    questionSeparator: 'U+241F (bytes3 0xe2909f)',
    selectors,
    cases: realityCases(),
  };
}

// --- hats.json -----------------------------------------------------------------------------

function hatsVector() {
  const ids = [];
  for (const top of [1, 3758, 65535]) {
    for (const dotted of [`${top}`, `${top}.1`, `${top}.2`]) {
      const value = hatIdToUint(dotted);
      if (uintToHatId(value) !== dotted) throw new Error(`hat id does not round trip: ${dotted}`);
      ids.push({ dotted, uint256: value.toString(), hex: toHex(value, { size: 32 }) });
    }
  }
  const [w0, w1, w2] = [anvil(6).address, anvil(7).address, anvil(8).address];
  const member = hatIdToUint('3758.1');
  const call = (functionName, args) => ({ functionName, args: args.map(jsonArg), calldata: encodeFunctionData({ abi: hatsAbi, functionName, args }) });
  const cases = [
    { name: 'mintTopHat', ...call('mintTopHat', [FIXED.safe, 'Group top hat', 'ipfs://']) },
    { name: 'createHat member', ...call('createHat', [hatIdToUint('3758'), 'Member', 4294967295, FIXED.safe, FIXED.safe, true, '']) },
    { name: 'createHat proposer', ...call('createHat', [hatIdToUint('3758'), 'Proposer', 4294967295, FIXED.safe, FIXED.safe, true, '']) },
    { name: 'mintHat', ...call('mintHat', [member, w0]) },
    { name: 'batchMintHats with 0 wearers', ...call('batchMintHats', [[], []]) },
    { name: 'batchMintHats with 1 wearer', ...call('batchMintHats', [[member], [w0]]) },
    { name: 'batchMintHats with 3 wearers', ...call('batchMintHats', [[member, member, member], [w0, w1, w2]]) },
    { name: 'setHatWearerStatus (burn without bad standing)', ...call('setHatWearerStatus', [member, w0, false, true]) },
    { name: 'isWearerOfHat', ...call('isWearerOfHat', [w0, member]) },
  ].map((c) => ({ ...c, selector: c.calldata.slice(0, 10) }));
  return { hats: NET.hats, ids, cases };
}

function jsonArg(v) {
  if (typeof v === 'bigint') return v.toString();
  if (Array.isArray(v)) return v.map(jsonArg);
  return v;
}

// --- multisend.json ------------------------------------------------------------------------

function multisendVector() {
  const safeAbi = parseAbi(['function enableModule(address module)']);
  const createCallAbi = parseAbi(['function performCreate2(uint256 value, bytes deploymentData, bytes32 salt) returns (address newContract)']);
  const calls = [
    {
      label: 'delegatecall CreateCall.performCreate2',
      operation: 1,
      to: NET.createCall,
      value: '0',
      data: encodeFunctionData({ abi: createCallAbi, functionName: 'performCreate2', args: [0n, '0x6080604052', '0x' + '07'.repeat(32)] }),
    },
    { label: 'call Safe.enableModule', operation: 0, to: FIXED.safe, value: '0', data: encodeFunctionData({ abi: safeAbi, functionName: 'enableModule', args: [FIXED.module] }) },
    { label: 'call with value and empty data', operation: 0, to: anvil(4).address, value: '1000000000', data: '0x' },
  ];
  const wire = calls.map((c) => ({ ...c, value: BigInt(c.value) }));
  return { multiSend: NET.multiSend, calls, packed: multiSendPack(wire), calldata: multiSendCalldata(wire), selector: '0x8d80ff0a' };
}

// --- ens.json ------------------------------------------------------------------------------

function ensVector() {
  const c = {
    label: 'gov-vectors',
    owner: FIXED.safe,
    secret: '0x' + '11'.repeat(32),
    subregistry: zeroAddress,
    resolver: zeroAddress,
    duration: 365 * 24 * 60 * 60,
    referrer: zeroHash,
  };
  const commitment = ensCommitment(c);
  const args = [c.label, c.owner, c.secret, c.subregistry, c.resolver, BigInt(c.duration), c.referrer];
  return {
    registrar: NET.ensRegistrar,
    paymentToken: NET.ensPaymentToken,
    input: c,
    commitment,
    makeCommitmentCalldata: encodeFunctionData({ abi: ensRegistrarAbi, functionName: 'makeCommitment', args }),
    commitCalldata: encodeFunctionData({ abi: ensRegistrarAbi, functionName: 'commit', args: [commitment] }),
    registerCalldata: encodeFunctionData({
      abi: ensRegistrarAbi,
      functionName: 'register',
      args: [c.label, c.owner, c.secret, c.subregistry, c.resolver, BigInt(c.duration), NET.ensPaymentToken, c.referrer],
    }),
  };
}

// --- setup-batch.json (25-06: the one Safe transaction that builds a Group's governance) -------

const ARBITRATOR_BIN = join(HERE, 'vendor', 'reality', 'Arbitrator.bin');

function setupBatchInput() {
  const initCode = readFileSync(ARBITRATOR_BIN, 'utf8').trim();
  if (!/^0x([0-9a-f]{2})+$/.test(initCode)) throw new Error('vendor/reality/Arbitrator.bin is not 0x-prefixed lowercase hex');
  return {
    chainId: SEPOLIA,
    owners: [anvil(0).address, anvil(1).address],
    threshold: 2,
    safeSaltNonce: 250601,
    // Hats top hat number. The fork test sets lastTopHatId to topHatNumber - 1 so the next top hat id is this one.
    topHatNumber: 5000,
    arbitratorSalt: keccak256(toHex('gov-vectors.eth/arbitrator')),
    arbitratorInitCode: initCode,
    moduleSaltNonce: 6,
    timeout: 600,
    cooldown: 300,
    expiration: 0,
    bond: '1000000000000000',
    // RealityModule.setUp does not check that the template exists; the template itself is created per chain (25-07).
    templateId: 7,
    groupName: 'Gov Vectors',
    a: {
      safeSingletonL2: NET.safeSingletonL2,
      safeProxyFactory: NET.safeProxyFactory,
      safeFallbackHandler: NET.safeFallbackHandler,
      multiSend: NET.multiSend,
      createCall: NET.createCall,
      moduleProxyFactory: NET.moduleProxyFactory,
      realityModuleMastercopy: NET.realityModuleMastercopy,
      hats: NET.hats,
      reality: NET.reality,
    },
  };
}

async function setupBatchVector() {
  const input = setupBatchInput();
  const batch = setupBatch(input);
  const signers = [anvil(0), anvil(1)];
  const entries = [];
  for (const acct of signers) entries.push({ owner: acct.address, signature: await acct.sign({ hash: batch.safeTxHash }) });
  const { arbitratorInitCode, ...rest } = input;
  return {
    note: 'Keys are anvil public dev keys 0 and 1. Every inner call runs inside the Safe (MultiSend delegatecall), so no address but the Safe ever owns, wears or administers anything. The signatures are plain ecrecover signatures over safeTxHash (v 27 or 28), concatenated in ascending owner order.',
    inputs: {
      ...rest,
      lastTopHatIdBefore: input.topHatNumber - 1,
      arbitratorInitCodeSha256: sha256(Buffer.from(arbitratorInitCode.slice(2), 'hex')),
      arbitratorInitCodeBytes: (arbitratorInitCode.length - 2) / 2,
      arbitratorInitCodeSource: 'tests/governance/vendor/reality/Arbitrator.bin (see vendor/SOURCES.md)',
    },
    safeInitializer: batch.safeInitializer,
    moduleInitializer: batch.moduleInitializer,
    calls: batch.calls,
    multiSendPacked: batch.packed,
    safeTx: batch.safeTx,
    safeTxHash: batch.safeTxHash,
    typedData: batch.typedData,
    signatures: entries,
    signaturesConcatenated: sortAndConcat(entries),
    predicted: batch.predicted,
  };
}

// --- abi.json (request R1: what SSA's AbiEncoder must learn) --------------------------------

function abiVector() {
  const a = (i) => anvil(i).address;
  const cases = [
    ['string, empty', 'string', ['']],
    ['string, ASCII', 'string', ['hello governance']],
    ['string, multi-byte UTF-8', 'string', ['café → コミュニティ 🌱']],
    ['bool, true', 'bool', [true]],
    ['bool, false', 'bool', [false]],
    ['uint32, max', 'uint32', [4294967295n]],
    ['uint32, zero', 'uint32', [0n]],
    ['uint64, max', 'uint64', [18446744073709551615n]],
    ['uint64, one', 'uint64', [1n]],
    ['bytes32[], empty', 'bytes32[]', [[]]],
    ['bytes32[], one', 'bytes32[]', [['0x' + 'aa'.repeat(32)]]],
    ['bytes32[], three', 'bytes32[]', [['0x' + '01'.repeat(32), '0x' + '02'.repeat(32), '0x' + 'ff'.repeat(32)]]],
    ['uint256[], empty', 'uint256[]', [[]]],
    ['uint256[], one', 'uint256[]', [[1n]]],
    ['uint256[], three', 'uint256[]', [[0n, 2n ** 256n - 1n, 12345678901234567890n]]],
    ['address[], empty', 'address[]', [[]]],
    ['address[], one', 'address[]', [[a(0)]]],
    ['address[], three', 'address[]', [[a(0), a(1), a(2)]]],
    ['mixed static and dynamic', 'address,string,uint256[],bool,bytes32,uint64,address[]', [a(0), 'mixed', [1n, 2n], true, '0x' + 'ab'.repeat(32), 42n, [a(1), a(2)]]],
  ];
  return {
    note: 'Each case is a call to a function named f with the given parameter types; calldata is selector plus abi.encode(args).',
    cases: cases.map(([name, types, args]) => {
      const signature = `f(${types})`;
      const abi = parseAbi([`function f(${types})`]);
      const calldata = encodeFunctionData({ abi, functionName: 'f', args });
      return { name, signature, selector: toFunctionSelector(signature), args: args.map(jsonArg), calldata };
    }),
  };
}

// --- pass-rule.json (GOV-04) ---------------------------------------------------------------

function passRuleVector() {
  const c = (name, input) => ({ name, input, expected: passRule(input) });
  const closedFinal = { state: 'closed', scores_state: 'final' };
  return {
    rule: "passed <=> state == 'closed' and scores_state == 'final' and scores_total >= quorum and scores[0] > scores[1]; fewer than two scores never passes; Abstain is scores[2] and counts only toward scores_total",
    cases: [
      c('total equal to quorum passes', { ...closedFinal, scores: [3, 1, 0], scores_total: 4, quorum: 4 }),
      c('total one below quorum fails', { ...closedFinal, scores: [3, 0, 0], scores_total: 3, quorum: 4 }),
      c('For equal to Against fails', { ...closedFinal, scores: [2, 2, 0], scores_total: 4, quorum: 1 }),
      c('Against above For fails', { ...closedFinal, scores: [1, 2, 0], scores_total: 3, quorum: 1 }),
      c('Abstain counts toward quorum', { ...closedFinal, scores: [2, 1, 3], scores_total: 6, quorum: 6 }),
      c('without the Abstain the same scores miss quorum', { ...closedFinal, scores: [2, 1, 0], scores_total: 3, quorum: 6 }),
      c('scores_state pending never passes', { state: 'closed', scores_state: 'pending', scores: [5, 0, 0], scores_total: 5, quorum: 1 }),
      c('closed with empty scores never passes', { ...closedFinal, scores: [], scores_total: 0, quorum: 0 }),
      c('state active never passes', { state: 'active', scores_state: 'final', scores: [5, 0, 0], scores_total: 5, quorum: 1 }),
      c('scores array of two entries passes', { ...closedFinal, scores: [3, 1], scores_total: 4, quorum: 4 }),
      c('scores array of one entry never passes', { ...closedFinal, scores: [3], scores_total: 3, quorum: 1 }),
      c('zero quorum with For above Against passes', { ...closedFinal, scores: [1, 0, 0], scores_total: 1, quorum: 0 }),
    ],
  };
}

// --- self-checks that guard the generator itself --------------------------------------------

function selfChecks() {
  for (const [name, sig] of Object.entries(REALITY_SIGNATURES)) {
    if (toFunctionSelector(sig) !== REALITY_SELECTORS_EXPECTED[name]) throw new Error(`selector for ${name} differs from the RESEARCH value`);
  }
  const hatsSel = { batchMintHats: '0x52a6b265', setHatWearerStatus: '0xafaae55a' };
  for (const [n, sel] of Object.entries(hatsSel)) {
    const item = hatsAbi.find((x) => x.name === n);
    if (toFunctionSelector(item) !== sel) throw new Error(`selector for ${n} differs from the RESEARCH value`);
  }
  if (toFunctionSelector('deployModule(address,bytes,uint256)') !== '0xf1ab873c') throw new Error('deployModule selector differs');
  if (keccak256(SAFE_PROXY_CREATION_CODE) !== SAFE_PROXY_CREATION_CODE_KECCAK) throw new Error('SAFE_PROXY_CREATION_CODE does not hash to the value read from the factory');
  if (toFunctionSelector('commit(bytes32)') !== '0xf14fcbc8') throw new Error('commit selector differs');
  if (keccak256(toHex('SafeMessage(bytes message)')) !== SAFE_MSG_TYPEHASH) throw new Error('SAFE_MSG_TYPEHASH is not keccak(SafeMessage(bytes message))');
  // Every label the rule accepts is already ENS-normalized (same string back).
  for (const l of ['abc', 'a-b-c', 'gov-vectors', 'x'.repeat(40), 'ab-cd', '1-2-3', 'abc--d']) {
    if (!isValidLabel(l)) throw new Error(`label should be valid: ${l}`);
    if (normalize(l) !== l) throw new Error(`label changes under normalize: ${l}`);
  }
  for (const l of ['ab', 'x'.repeat(41), 'Abc', 'ab--cd', 'a_b', 'a b', 'é-é-é', '']) {
    if (isValidLabel(l)) throw new Error(`label should be invalid: ${l}`);
  }
}


// --- --fork-check: the deployed contracts' own answers must equal the vectors ----------------------

async function forkCheck() {
  const rpc = process.env.GOV_FORK_RPC ?? 'http://127.0.0.1:18545';
  const transport = http(rpc);
  const pub = createPublicClient({ chain: sepolia, transport });
  if ((await pub.getChainId()) !== SEPOLIA) throw new Error('fork-check needs the Ethereum Sepolia fork');
  const meta = await pub.request({ method: 'anvil_metadata', params: [] });
  if (!meta?.forkedNetwork) throw new Error('fork-check needs an anvil fork of Ethereum Sepolia');
  const payer = anvil(2);
  const wallet = createWalletClient({ account: payer, chain: sepolia, transport });
  const send = async (request) => {
    const hash = await wallet.writeContract(request);
    const rc = await pub.waitForTransactionReceipt({ hash });
    if (rc.status !== 'success') throw new Error(`transaction reverted: ${request.functionName}`);
    return rc;
  };
  const eq = (what, got, want) => {
    if (typeof got === 'string' && typeof want === 'string' ? got.toLowerCase() !== want.toLowerCase() : got !== want) {
      throw new Error(`${what}: contract says ${got}, vector says ${want}`);
    }
  };
  const vec = realityVector();
  let checked = 0;

  for (const c of vec.cases) {
    const sim = await pub.simulateContract({
      account: payer.address,
      address: vec.factory,
      abi: moduleProxyFactoryAbi,
      functionName: 'deployModule',
      args: [vec.mastercopy, `0x${c.initializer.slice(2)}`, BigInt(c.saltNonce)],
    });
    // The factory takes the initializer as bytes: the setUp(bytes) call data itself.
    eq(`${c.name}: predicted proxy address`, sim.result, c.predictedModule);
    await send(sim.request);
    const code = await pub.getCode({ address: c.predictedModule });
    if (!code || code === '0x') throw new Error(`${c.name}: no code at the predicted module address`);
    for (const t of c.txs) {
      const got = await pub.readContract({
        address: c.predictedModule, abi: realityModuleAbi, functionName: 'getTransactionHash',
        args: [t.to, BigInt(t.value), t.data, t.operation, BigInt(t.nonce)],
      });
      eq(`${c.name}: getTransactionHash ${t.label} nonce ${t.nonce}`, got, t.txHash);
      checked++;
    }
    for (const pr of c.proposals) {
      const q = await pub.readContract({
        address: c.predictedModule, abi: realityModuleAbi, functionName: 'buildQuestion', args: [pr.proposalId, pr.txHashes],
      });
      eq(`${c.name}: buildQuestion (${pr.name})`, q, pr.question);
      const qid = await pub.readContract({
        address: c.predictedModule, abi: realityModuleAbi, functionName: 'getQuestionId', args: [pr.question, 0n],
      });
      eq(`${c.name}: getQuestionId (${pr.name})`, qid, pr.questionId);
      checked += 2;
    }
  }

  // End to end: a real template on Reality 3.0, a module that uses it, and addProposal, which makes
  // Reality compute the question id and requires it to equal the module's own getQuestionId.
  const template = '{"title": "Did the Snapshot proposal with the id %s pass the execution of the array of Module transactions with the hash 0x%s?", "lang": "en", "type": "bool", "category": "DAO proposal"}';
  const tplSim = await pub.simulateContract({ account: payer.address, address: NET.reality, abi: realityEthAbi, functionName: 'createTemplate', args: [template] });
  await send(tplSim.request);
  const templateId = tplSim.result;
  const first = vec.cases[0];
  const params = { ...first.params, templateId: Number(templateId) };
  const initializer = realityInitializer(params);
  const saltNonce = 99;
  const module = predictModuleProxy(NET.moduleProxyFactory, NET.realityModuleMastercopy, initializer, saltNonce);
  await send((await pub.simulateContract({
    account: payer.address, address: NET.moduleProxyFactory, abi: moduleProxyFactoryAbi, functionName: 'deployModule',
    args: [NET.realityModuleMastercopy, initializer, BigInt(saltNonce)],
  })).request);
  const { native } = realityTxs();
  const txHash = moduleTransactionHash(SEPOLIA, module, { ...native, nonce: 0 });
  const rc = await send({ address: module, abi: realityModuleAbi, functionName: 'addProposal', args: [PROPOSAL_ID, [txHash]] });
  const topic0 = keccak256(toHex('ProposalQuestionCreated(bytes32,string)'));
  const log = rc.logs.find((l) => l.address.toLowerCase() === module.toLowerCase() && l.topics[0] === topic0);
  if (!log) throw new Error('addProposal emitted no ProposalQuestionCreated');
  const expected = realityQuestionId({
    templateId, question: realityQuestion(PROPOSAL_ID, [txHash]), arbitrator: params.arbitrator, timeout: params.timeout,
    bond: params.bond, oracle: params.oracle, module, nonce: 0,
  });
  eq('addProposal question id vs the formula', log.topics[1], expected);
  checked++;

  // ENS: the registrar's own makeCommitment.
  const ens = JSON.parse(readFileSync(join(OUT, 'ens.json'), 'utf8'));
  const i = ens.input;
  const commitment = await pub.readContract({
    address: NET.ensRegistrar, abi: ensRegistrarAbi, functionName: 'makeCommitment',
    args: [i.label, i.owner, i.secret, i.subregistry, i.resolver, BigInt(i.duration), i.referrer],
  });
  eq('ENS makeCommitment', commitment, ens.commitment);
  checked++;

  // Hats: the dotted-id conversion against a live wearer (score-api example: 3758.1.1 and 0xc027...).
  const wearer = '0xc027e08266Ed3B9d8baC131b49b50a626a34Fbf9';
  const isWearer = await pub.readContract({ address: NET.hats, abi: hatsAbi, functionName: 'isWearerOfHat', args: [wearer, hatIdToUint('3758.1.1')] });
  eq('Hats isWearerOfHat(3758.1.1) for the score-api example wearer', isWearer, true);
  checked++;

  console.log(`FORK CHECK OK (${checked} comparisons; template id ${templateId}, module ${module})`);
}

// --- registry ------------------------------------------------------------------------------

/** Each file name maps to a function returning the JSON value to write. Task 3 adds the rest. */
export const GENERATORS = {
  'snapshot.json': async () => snapshotVector(),
  'safe-message.json': safeMessageVector,
  'settings.json': async () => settingsVector(),
  'registration.json': async () => registrationVector(),
  'reality.json': async () => realityVector(),
  'hats.json': async () => hatsVector(),
  'multisend.json': async () => multisendVector(),
  'ens.json': async () => ensVector(),
  'setup-batch.json': setupBatchVector,
  'abi.json': async () => abiVector(),
  'pass-rule.json': async () => passRuleVector(),
};

const text = (value) => JSON.stringify(value, (_k, v) => (typeof v === 'bigint' ? v.toString() : v), 2) + '\n';

export async function generateAll() {
  selfChecks();
  const out = {};
  for (const [file, gen] of Object.entries(GENERATORS)) out[file] = text(await gen());
  return out;
}

async function main() {
  if (process.argv.includes('--fork-check')) return forkCheck();
  const check = process.argv.includes('--check');
  const files = await generateAll();
  if (check) {
    let bad = 0;
    for (const [file, content] of Object.entries(files)) {
      const path = join(OUT, file);
      const have = existsSync(path) ? readFileSync(path, 'utf8') : null;
      if (have !== content) {
        bad++;
        console.error(`DIFF ${file}${have === null ? ' (missing)' : ''}`);
      }
    }
    if (bad) process.exit(1);
    console.log(`vectors OK (${Object.keys(files).length} files)`);
    return;
  }
  mkdirSync(OUT, { recursive: true });
  for (const [file, content] of Object.entries(files)) writeFileSync(join(OUT, file), content);
  console.log(`wrote ${Object.keys(files).length} files to ${OUT}`);
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  main().catch((e) => {
    console.error(e);
    process.exit(1);
  });
}
