// Pure builders for Phase 25 group governance (no network access). Pinned viem only.
//
// Every address lives in the NETWORKS constant below and is cited to its source. The builder
// functions take addresses as arguments; none of them reads NETWORKS.
import {
  concat,
  encodeAbiParameters,
  encodeFunctionData,
  encodePacked,
  getAddress,
  getContractAddress,
  hashTypedData,
  hexToBigInt,
  keccak256,
  numberToHex,
  parseAbi,
  size,
  zeroAddress,
} from 'viem';

// Source of each row is 25-RESEARCH.md "Standard Stack" (2026-11-06) unless noted.
// Safe 1.4.1 rows also appear in tests/e2e/fork/vendor/safe-1.4.1/*.json (safe-deployments@ca9cb0d2d5).
export const NETWORKS = {
  // Ethereum Sepolia
  '11155111': {
    name: 'Ethereum Sepolia',
    safeSingletonL2: '0x29fcB43b46531BcA003ddC8FCB67FFE91900C762', // safe-deployments v1.4.1 safe_l2_singleton
    safeProxyFactory: '0x4e1DCf7AD4e460CfD30791CCC4F9c8a4f820ec67', // safe-deployments v1.4.1 safe_proxy_factory
    safeFallbackHandler: '0xfd0732Dc9E303f09fCEf3a7388Ad10A83459Ec99', // v1.4.1 compatibility_fallback_handler
    multiSend: '0x38869bf66a61cF6bDB996A6aE40D5853Fd43B526', // v1.4.1 multi_send
    multiSendCallOnly: '0x9641d764fc13c8B624c04430C7356C1C7C8102e2', // v1.4.1 multi_send_call_only
    createCall: '0x9b35Af71d77eaf8d7e40252370304687390A1A52', // v1.4.1 create_call
    signMessageLib: '0xd53cd0aB83D845Ac265BE939c57F53AD838012c9', // v1.4.1 sign_message_lib
    moduleProxyFactory: '0x000000000000aDdB49795b0f9bA5BC298cDda236', // gnosisguild/zodiac src/contracts.ts @b7988c0e lines 88-91
    realityModuleMastercopy: '0x4e35DA39Fa5893a70A40Ce964F993d891E607cC0', // same file, lines 73-75
    hats: '0x3bc1A0Ad72417f2d411118085256fC53CBdDd137', // score-api hats-protocol-hat-id/examples.json
    reality: '0xaf33DcB6E8c5c4D9dDF579f53031b514d19449CA', // reality-eth-monorepo chains/deployments/11155111/ETH/RealityETH-3.0.json
    arbitrator: '0x50A7485f583273A4e5D341a25E4555D3C5A810dE', // same repo, .../ETH/Arbitrator.json @b2fe200b3e (not ours)
    multicall3: '0xcA11bde05977b3631167028862bE2a173976CA11', // snapshot.js src/networks.json
    ensRegistrar: '0xf633e7fc17e2bbe0d0965d18ec1821dcb754a3d3', // bigname docs/sepolia-deployment.md line 32 (ens_v2_sepolia_20261001 @07e55a05)
    ensUniversalHelper: '0xd453e5Bdb62CC3beA84341B1e306319C8Ffd7DFe', // snapshot.js src/networks.json 11155111.ensUniversalHelper
    ensPaymentToken: '0x240b0316df57887dbbe58b586508b19e633a14aa', // bigname docs/sepolia-deployment.md line 43 (MockUSDC, open mint)
  },
  // Base Sepolia (no ENS there; Reality 3.0 differs)
  '84532': {
    name: 'Base Sepolia',
    safeSingletonL2: '0x29fcB43b46531BcA003ddC8FCB67FFE91900C762',
    safeProxyFactory: '0x4e1DCf7AD4e460CfD30791CCC4F9c8a4f820ec67',
    safeFallbackHandler: '0xfd0732Dc9E303f09fCEf3a7388Ad10A83459Ec99',
    multiSend: '0x38869bf66a61cF6bDB996A6aE40D5853Fd43B526',
    multiSendCallOnly: '0x9641d764fc13c8B624c04430C7356C1C7C8102e2',
    createCall: '0x9b35Af71d77eaf8d7e40252370304687390A1A52',
    signMessageLib: '0xd53cd0aB83D845Ac265BE939c57F53AD838012c9',
    moduleProxyFactory: '0x000000000000aDdB49795b0f9bA5BC298cDda236', // RESEARCH: "same" on Base Sepolia
    realityModuleMastercopy: '0x4e35DA39Fa5893a70A40Ce964F993d891E607cC0',
    hats: '0x3bc1A0Ad72417f2d411118085256fC53CBdDd137',
    reality: '0x86b653Dc9aa6d7311109276925927087573f91CB', // reality-eth-monorepo .../84532/ETH/RealityETH-3.0.json
    multicall3: '0xcA11bde05977b3631167028862bE2a173976CA11',
  },
};

// --- Snapshot (snapshot.js src/sign/index.ts lines 40-51, src/sign/types.ts @6ebeb614) --------

export const snapshotDomain = Object.freeze({ name: 'snapshot', version: '0.1.4' });

// Copied key by key from snapshot.js src/sign/types.ts (spaceTypes, proposalTypes, voteTypes).
// The sequencer matches sha256(JSON.stringify(types)), so key order is part of the contract.
export const snapshotTypes = Object.freeze({
  Space: Object.freeze([
    { name: 'from', type: 'address' },
    { name: 'space', type: 'string' },
    { name: 'timestamp', type: 'uint64' },
    { name: 'settings', type: 'string' },
  ]),
  Proposal: Object.freeze([
    { name: 'from', type: 'string' },
    { name: 'space', type: 'string' },
    { name: 'timestamp', type: 'uint64' },
    { name: 'type', type: 'string' },
    { name: 'title', type: 'string' },
    { name: 'body', type: 'string' },
    { name: 'discussion', type: 'string' },
    { name: 'choices', type: 'string[]' },
    { name: 'labels', type: 'string[]' },
    { name: 'start', type: 'uint64' },
    { name: 'end', type: 'uint64' },
    { name: 'snapshot', type: 'uint64' },
    { name: 'plugins', type: 'string' },
    { name: 'privacy', type: 'string' },
    { name: 'app', type: 'string' },
  ]),
  Vote: Object.freeze([
    { name: 'from', type: 'string' },
    { name: 'space', type: 'string' },
    { name: 'timestamp', type: 'uint64' },
    { name: 'proposal', type: 'string' },
    { name: 'choice', type: 'uint32' },
    { name: 'reason', type: 'string' },
    { name: 'app', type: 'string' },
    { name: 'metadata', type: 'string' },
  ]),
});

/** The types JSON exactly as the sequencer hashes it: { <PrimaryType>: [...] } stringified. */
export function snapshotTypesJson(primaryType) {
  if (!snapshotTypes[primaryType]) throw new Error(`unknown Snapshot type: ${primaryType}`);
  return JSON.stringify({ [primaryType]: snapshotTypes[primaryType] });
}

/** EIP-712 digest of a Snapshot message under the Snapshot domain (no chainId). */
export function snapshotHash(primaryType, message) {
  return hashTypedData({
    domain: snapshotDomain,
    types: { [primaryType]: snapshotTypes[primaryType] },
    primaryType,
    message,
  });
}

// --- Space labels (ENSIP-15 CheckHyphens subset the plan allows) --------------------------

/**
 * Lowercase a-z, 0-9 and hyphen, 3 to 40 characters, never a hyphen in both the third and
 * fourth position. That set is ASCII, so byte length equals character length and the label is
 * already normalized.
 */
export function isValidLabel(label) {
  if (typeof label !== 'string') return false;
  if (!/^[a-z0-9-]{3,40}$/.test(label)) return false;
  if (label[2] === '-' && label[3] === '-') return false;
  return true;
}

// --- Hats ids (score-api HatIpToHex, Hats.sol level packing) ----------------------------

/** "3758.1.2" -> uint256: first part 8 hex digits, each later part 4, right-padded to 64. */
export function hatIdToUint(dotted) {
  const parts = String(dotted).split('.');
  if (parts.length < 1 || parts.length > 15) throw new Error(`bad hat id: ${dotted}`);
  for (const [i, p] of parts.entries()) {
    if (!/^[0-9]+$/.test(p)) throw new Error(`bad hat id part: ${p}`);
    const n = BigInt(p);
    const max = i === 0 ? 0xffffffffn : 0xffffn;
    if (n > max) throw new Error(`hat id part too large: ${p}`);
    if (i > 0 && n === 0n) throw new Error(`hat id level cannot be zero: ${dotted}`);
  }
  let hex = BigInt(parts[0]).toString(16).padStart(8, '0');
  for (const p of parts.slice(1)) hex += BigInt(p).toString(16).padStart(4, '0');
  return BigInt('0x' + hex.padEnd(64, '0'));
}

/** Inverse of hatIdToUint. */
export function uintToHatId(value) {
  const hex = BigInt(value).toString(16).padStart(64, '0');
  const parts = [BigInt('0x' + hex.slice(0, 8)).toString()];
  for (let i = 8; i < 64; i += 4) {
    const chunk = hex.slice(i, i + 4);
    if (/^0{4}$/.test(chunk)) break;
    parts.push(BigInt('0x' + chunk).toString());
  }
  return parts.join('.');
}

// --- Space settings (RESEARCH Pattern 3) -------------------------------------------------

/**
 * The Snapshot settings object for a Group space. Key order is the serialization order.
 * quorum is absolute (a vote total), never a percentage (D-15).
 */
export function spaceSettings(input) {
  const required = ['name', 'network', 'hats', 'memberHatId', 'period', 'quorum', 'safe', 'realityAddress'];
  for (const k of required) if (input[k] === undefined || input[k] === null) throw new Error(`settings input missing: ${k}`);
  if (!Number.isInteger(input.quorum) || input.quorum < 1) throw new Error('quorum must be a positive integer (absolute votes)');
  if (!Number.isInteger(input.period) || input.period < 1) throw new Error('period must be a positive integer (seconds)');
  const hats = getAddress(input.hats);
  const network = String(input.network);
  const hatStrategy = (hatId) => ({ name: 'hats-protocol-hat-id', network, params: { address: hats, hatId } });
  const proposerHatId = input.proposerHatId ?? input.memberHatId;
  return {
    name: input.name,
    network,
    symbol: input.symbol ?? 'VOTE',
    admins: [],
    moderators: [],
    members: [],
    strategies: [hatStrategy(input.memberHatId)],
    validation: {
      name: 'basic',
      params: { minScore: 1, strategies: [hatStrategy(proposerHatId)] },
    },
    voting: { type: 'basic', period: input.period, quorum: input.quorum, delay: 0, privacy: '' },
    filters: { onlyMembers: false },
    plugins: { safeSnap: { safes: [{ network, realityAddress: getAddress(input.realityAddress) }] } },
    treasuries: [{ name: input.treasuryName ?? 'Group fund', address: getAddress(input.safe), network }],
  };
}

// --- Safe message signing (CompatibilityFallbackHandler 1.4.1, RESEARCH Q1 VERDICT) -----------

export const SAFE_MSG_TYPEHASH = '0x60b3cbf8b4a223d68d641b3b6ddf9a298e7f33710cf3d3a9d1146b5a6150fbca';
export const ERC1271_MAGIC = '0x1626ba7e';

/** abi.encode(bytes32 hash): the 32 bytes themselves. */
export function encodeSnapshotHash(hash) {
  return encodeAbiParameters([{ type: 'bytes32' }], [hash]);
}

/** Typed data an owner signs for a Safe-wrapped message (SafeMessage{bytes message}). */
export function safeMessageTypedData(chainId, safe, hash) {
  return {
    domain: { chainId: Number(chainId), verifyingContract: getAddress(safe) },
    types: { SafeMessage: [{ name: 'message', type: 'bytes' }] },
    primaryType: 'SafeMessage',
    message: { message: encodeSnapshotHash(hash) },
  };
}

/** The digest the Safe's checkSignatures verifies for that typed data. */
export function safeMessageHash(chainId, safe, hash) {
  return hashTypedData(safeMessageTypedData(chainId, safe, hash));
}

/**
 * Concatenate owner signatures in ascending owner-address order (Safe checkSignatures).
 * entries: [{ owner, signature }]. Order of the input does not matter.
 */
export function sortAndConcat(entries) {
  if (!entries.length) throw new Error('no signatures');
  const sorted = [...entries].sort((a, b) => {
    const x = hexToBigInt(a.owner);
    const y = hexToBigInt(b.owner);
    return x < y ? -1 : x > y ? 1 : 0;
  });
  for (let i = 1; i < sorted.length; i++) {
    if (hexToBigInt(sorted[i].owner) === hexToBigInt(sorted[i - 1].owner)) throw new Error('duplicate owner signature');
  }
  for (const e of sorted) if (e.signature.length !== 132) throw new Error('signature must be 65 bytes');
  return concat(sorted.map((e) => e.signature));
}


// --- Registration (D-18): the Safe-signed relayer registration ---------------------------------

export const registrationTypes = Object.freeze({
  SponsorRegistration: Object.freeze([
    { name: 'relayer', type: 'address' },
    { name: 'chainId', type: 'uint256' },
    { name: 'safe', type: 'address' },
    { name: 'space', type: 'string' },
    { name: 'community', type: 'string' },
  ]),
});

export function registrationDomain(chainId) {
  return { name: 'gas-sponsor-relayer', version: '1', chainId: Number(chainId) };
}

export function registrationTypedData(chainId, message) {
  return {
    domain: registrationDomain(chainId),
    types: { SponsorRegistration: registrationTypes.SponsorRegistration },
    primaryType: 'SponsorRegistration',
    message,
  };
}

// --- Zodiac Reality module v2.0.0 (RealityModule.sol, RESEARCH Pattern 6) ---------------------------

export const REALITY_DOMAIN_TYPEHASH = '0x47e79534a245952e8b16893a336b85a3d9ea9fa8c573f3d803afb92a79469218';
export const REALITY_TRANSACTION_TYPEHASH = '0x72e9670a7ee00f5fbf1049b8c38e3f22fab7e9b85029e85cf9412f17fdd5c2ad';
export const QUESTION_SEPARATOR = '\u241f'; // bytes3(0xe2909f) in UTF-8

export const realityModuleAbi = parseAbi([
  'function setUp(bytes initParams)',
  'function addProposal(string proposalId, bytes32[] txHashes)',
  'function executeProposalWithIndex(string proposalId, bytes32[] txHashes, address to, uint256 value, bytes data, uint8 operation, uint256 txIndex)',
  'function getTransactionHash(address to, uint256 value, bytes data, uint8 operation, uint256 nonce) view returns (bytes32)',
  'function buildQuestion(string proposalId, bytes32[] txHashes) pure returns (string)',
  'function getQuestionId(string question, uint256 nonce) view returns (bytes32)',
]);
export const moduleProxyFactoryAbi = parseAbi([
  'function deployModule(address masterCopy, bytes initializer, uint256 saltNonce) returns (address proxy)',
]);
export const realityEthAbi = parseAbi([
  'function createTemplate(string content) returns (uint256)',
  'function submitAnswer(bytes32 question_id, bytes32 answer, uint256 max_previous) payable',
  'function claimWinnings(bytes32 question_id, bytes32[] history_hashes, address[] addrs, uint256[] bonds, bytes32[] answers)',
  'function withdraw()',
]);

/** RealityModule.setUp(bytes) initializer (selector 0xa4f9edbf) over the ten-field init params. */
export function realityInitializer(p) {
  const params = encodeAbiParameters(
    [
      { type: 'address' }, { type: 'address' }, { type: 'address' }, { type: 'address' },
      { type: 'uint32' }, { type: 'uint32' }, { type: 'uint32' },
      { type: 'uint256' }, { type: 'uint256' }, { type: 'address' },
    ],
    [p.owner, p.avatar, p.target, p.oracle, p.timeout, p.cooldown, p.expiration, BigInt(p.bond), BigInt(p.templateId), p.arbitrator],
  );
  return encodeFunctionData({ abi: realityModuleAbi, functionName: 'setUp', args: [params] });
}

/** ModuleProxyFactory v1.2.0 CREATE2 address (zodiac-core ModuleProxyFactory.sol lines 19-50). */
export function predictModuleProxy(factory, mastercopy, initializer, saltNonce) {
  const salt = keccak256(encodePacked(['bytes32', 'uint256'], [keccak256(initializer), BigInt(saltNonce)]));
  const code = `0x602d8060093d393df3363d3d373d3d3d363d73${getAddress(mastercopy).slice(2).toLowerCase()}5af43d82803e903d91602b57fd5bf3`;
  return getContractAddress({ opcode: 'CREATE2', from: factory, salt, bytecode: code });
}

/** Module transaction hash (RealityModule.getTransactionHash). nonce is the index in txHashes. */
export function moduleTransactionHash(chainId, module, tx) {
  const domainSeparator = keccak256(
    encodeAbiParameters([{ type: 'bytes32' }, { type: 'uint256' }, { type: 'address' }], [REALITY_DOMAIN_TYPEHASH, BigInt(chainId), module]),
  );
  const transactionHash = keccak256(
    encodeAbiParameters(
      [{ type: 'bytes32' }, { type: 'address' }, { type: 'uint256' }, { type: 'bytes32' }, { type: 'uint8' }, { type: 'uint256' }],
      [REALITY_TRANSACTION_TYPEHASH, tx.to, BigInt(tx.value), keccak256(tx.data), tx.operation, BigInt(tx.nonce)],
    ),
  );
  return keccak256(concat(['0x1901', domainSeparator, transactionHash]));
}

/** question = proposalId + U+241F + lowercase hex (no 0x) of keccak256(abi.encodePacked(txHashes)). */
export function packedTxHashesKeccak(txHashes) {
  return keccak256(concat(txHashes));
}
export function realityQuestion(proposalId, txHashes) {
  return proposalId + QUESTION_SEPARATOR + packedTxHashesKeccak(txHashes).slice(2);
}

/** questionId = keccak256(packed(contentHash, arbitrator, timeout, minimumBond, oracle, module, nonce)). */
export function realityQuestionId({ templateId, question, arbitrator, timeout, bond, oracle, module, nonce }) {
  const contentHash = keccak256(encodePacked(['uint256', 'uint32', 'string'], [BigInt(templateId), 0, question]));
  return keccak256(
    encodePacked(
      ['bytes32', 'address', 'uint32', 'uint256', 'address', 'address', 'uint256'],
      [contentHash, arbitrator, timeout, BigInt(bond), oracle, module, BigInt(nonce)],
    ),
  );
}

// --- MultiSend packing (Safe MultiSend 1.4.1) --------------------------------------------------

const multiSendAbi = parseAbi(['function multiSend(bytes transactions)']);

/** op(1) ‖ to(20) ‖ value(32) ‖ dataLength(32) ‖ data, concatenated. */
export function multiSendPack(calls) {
  return concat(
    calls.map((c) =>
      concat([
        numberToHex(c.operation, { size: 1 }),
        c.to,
        numberToHex(BigInt(c.value), { size: 32 }),
        numberToHex(size(c.data), { size: 32 }),
        c.data,
      ]),
    ),
  );
}
export function multiSendCalldata(calls) {
  return encodeFunctionData({ abi: multiSendAbi, functionName: 'multiSend', args: [multiSendPack(calls)] });
}

// --- Hats Protocol v1 calldata -----------------------------------------------------------------

export const hatsAbi = parseAbi([
  'function mintTopHat(address _target, string _details, string _imageURI) returns (uint256 topHatId)',
  'function createHat(uint256 _admin, string _details, uint32 _maxSupply, address _eligibility, address _toggle, bool _mutable, string _imageURI) returns (uint256 newHatId)',
  'function mintHat(uint256 _hatId, address _wearer) returns (bool success)',
  'function batchMintHats(uint256[] _hatIds, address[] _wearers) returns (bool success)',
  'function setHatWearerStatus(uint256 _hatId, address _wearer, bool _eligible, bool _standing) returns (bool updated)',
  'function isWearerOfHat(address _user, uint256 _hatId) view returns (bool isWearer)',
]);

// --- ENSv2 registrar (ETHRegistrar @07e55a05) --------------------------------------------------

export const ensRegistrarAbi = parseAbi([
  'function commit(bytes32 commitment)',
  'function makeCommitment(string label, address owner, bytes32 secret, address subregistry, address resolver, uint64 duration, bytes32 referrer) pure returns (bytes32)',
  'function register(string label, address owner, bytes32 secret, address subregistry, address resolver, uint64 duration, address paymentToken, bytes32 referrer) returns (uint256)',
]);

/** keccak256(abi.encode(label, owner, secret, subregistry, resolver, duration, referrer)). */
export function ensCommitment(c) {
  return keccak256(
    encodeAbiParameters(
      [{ type: 'string' }, { type: 'address' }, { type: 'bytes32' }, { type: 'address' }, { type: 'address' }, { type: 'uint64' }, { type: 'bytes32' }],
      [c.label, c.owner, c.secret, c.subregistry, c.resolver, BigInt(c.duration), c.referrer],
    ),
  );
}

// --- Pass rule (single source of truth for the fixtures; PassRule in PHP and the relayer match) ----

/**
 * passed <=> state == 'closed' and scores_state == 'final' and scores_total >= quorum
 *            and scores[0] (For) > scores[1] (Against).
 * Fewer than two scores never passes. Abstain (scores[2]) only counts toward scores_total.
 */
export function passRule({ state, scores_state, scores, scores_total, quorum }) {
  if (state !== 'closed') return { passed: false, reason: 'not-closed' };
  if (scores_state !== 'final') return { passed: false, reason: 'scores-not-final' };
  if (!Array.isArray(scores) || scores.length < 2) return { passed: false, reason: 'scores-missing' };
  if (scores_total < quorum) return { passed: false, reason: 'quorum-not-met' };
  if (!(scores[0] > scores[1])) return { passed: false, reason: 'for-not-above-against' };
  return { passed: true, reason: 'passed' };
}


// --- Group setup batch (RESEARCH Pattern 1, plan 25-06) --------------------------------------------------

// SafeProxy creation code, exactly what SafeProxyFactory 1.4.1 (0x4e1DCf7A...ec67) returns from
// proxyCreationCode() on Ethereum Sepolia (read at fork block 12087317, 2026-11-08). The constant is
// the same on every chain. A fork run proves it: the Safe deployed there lands on predictSafeProxy's address.
export const SAFE_PROXY_CREATION_CODE =
  '0x608060405234801561001057600080fd5b506040516101e63803806101e68339818101604052602081101561003357600080fd5b8101908080519060200190929190505050600073ffffffffffffffffffffffffffffffffffffffff168173ffffffffffffffffffffffffffffffffffffffff1614156100ca576040517f08c379a00000000000000000000000000000000000000000000000000000000081526004018080602001828103825260228152602001806101c46022913960400191505060405180910390fd5b806000806101000a81548173ffffffffffffffffffffffffffffffffffffffff021916908373ffffffffffffffffffffffffffffffffffffffff1602179055505060ab806101196000396000f3fe608060405273ffffffffffffffffffffffffffffffffffffffff600054167fa619486e0000000000000000000000000000000000000000000000000000000060003514156050578060005260206000f35b3660008037600080366000845af43d6000803e60008114156070573d6000fd5b3d6000f3fea264697066735822122003d1488ee65e08fa41e58e888a9865554c535f2c77126a82cb4c0f917f31441364736f6c63430007060033496e76616c69642073696e676c65746f6e20616464726573732070726f7669646564';
export const SAFE_PROXY_CREATION_CODE_KECCAK = '0x1856e0ee08399d74e0ea0b03adca210aeade6f748969ac023cdcb4dd62dcaf5f';

export const safeSetupAbi = parseAbi([
  'function setup(address[] _owners, uint256 _threshold, address to, bytes data, address fallbackHandler, address paymentToken, uint256 payment, address paymentReceiver)',
  'function enableModule(address module)',
]);
export const createCallAbi = parseAbi([
  'function performCreate2(uint256 value, bytes deploymentData, bytes32 salt) returns (address newContract)',
]);
export const arbitratorAbi = parseAbi([
  'function setRealitio(address addr)',
  'function owner() view returns (address)',
  'function realitio() view returns (address)',
]);

/** Safe.setup() call data for a plain owners-and-threshold Safe with the 1.4.1 fallback handler. */
export function safeSetupInitializer({ owners, threshold, fallbackHandler }) {
  return encodeFunctionData({
    abi: safeSetupAbi,
    functionName: 'setup',
    args: [owners, BigInt(threshold), zeroAddress, '0x', fallbackHandler, zeroAddress, 0n, zeroAddress],
  });
}

/** CREATE2 address of init code deployed by `deployer` with `salt`. */
export function predictCreate2(deployer, salt, initCode) {
  return getContractAddress({ opcode: 'CREATE2', from: deployer, salt, bytecode: initCode });
}

/** SafeProxyFactory.createProxyWithNonce address: salt = keccak(keccak(initializer) ++ saltNonce), code = proxy creation code ++ singleton. */
export function predictSafeProxy({ factory, singleton, initializer, saltNonce }) {
  const salt = keccak256(encodePacked(['bytes32', 'uint256'], [keccak256(initializer), BigInt(saltNonce)]));
  const deploymentData = concat([SAFE_PROXY_CREATION_CODE, encodeAbiParameters([{ type: 'uint256' }], [hexToBigInt(singleton)])]);
  return predictCreate2(factory, salt, deploymentData);
}

export const safeTxTypes = Object.freeze({
  SafeTx: Object.freeze([
    { name: 'to', type: 'address' },
    { name: 'value', type: 'uint256' },
    { name: 'data', type: 'bytes' },
    { name: 'operation', type: 'uint8' },
    { name: 'safeTxGas', type: 'uint256' },
    { name: 'baseGas', type: 'uint256' },
    { name: 'gasPrice', type: 'uint256' },
    { name: 'gasToken', type: 'address' },
    { name: 'refundReceiver', type: 'address' },
    { name: 'nonce', type: 'uint256' },
  ]),
});

/** Typed data the Safe owners sign for execTransaction (Safe 1.4.1: domain is chainId and verifyingContract only). */
export function safeTxTypedData(chainId, safe, tx) {
  return {
    domain: { chainId: Number(chainId), verifyingContract: getAddress(safe) },
    types: { SafeTx: safeTxTypes.SafeTx },
    primaryType: 'SafeTx',
    message: {
      to: tx.to,
      value: BigInt(tx.value),
      data: tx.data,
      operation: tx.operation,
      safeTxGas: BigInt(tx.safeTxGas),
      baseGas: BigInt(tx.baseGas),
      gasPrice: BigInt(tx.gasPrice),
      gasToken: tx.gasToken,
      refundReceiver: tx.refundReceiver,
      nonce: BigInt(tx.nonce),
    },
  };
}

export const HAT_MAX_SUPPLY = 4294967295;

/**
 * The one Safe transaction that builds a Group's governance with the Safe in control of every authority from the
 * first block (RESEARCH Pattern 1, "Key insight"). Inner calls, in order:
 *   1 delegatecall CreateCall.performCreate2: the reference Arbitrator, owner = the Safe (D-07)
 *   2 Arbitrator.setRealitio
 *   3 ModuleProxyFactory.deployModule: the Reality module, owner = avatar = target = the Safe (D-04)
 *   4 Safe.enableModule
 *   5 Hats.mintTopHat to the Safe (D-17)
 *   6 and 7 Hats.createHat for the member and proposer hats, eligibility = toggle = the Safe
 *   8 Hats.mintHat of both hats to every owner
 * `a` holds the contract addresses; this function reads no network table.
 */
export function setupBatch(input) {
  const { a } = input;
  const safeInitializer = safeSetupInitializer({ owners: input.owners, threshold: input.threshold, fallbackHandler: a.safeFallbackHandler });
  const safe = predictSafeProxy({ factory: a.safeProxyFactory, singleton: a.safeSingletonL2, initializer: safeInitializer, saltNonce: input.safeSaltNonce });
  const arbitrator = predictCreate2(safe, input.arbitratorSalt, input.arbitratorInitCode);
  const moduleInit = realityInitializer({
    owner: safe, avatar: safe, target: safe, oracle: a.reality,
    timeout: input.timeout, cooldown: input.cooldown, expiration: input.expiration,
    bond: input.bond, templateId: input.templateId, arbitrator,
  });
  const module = predictModuleProxy(a.moduleProxyFactory, a.realityModuleMastercopy, moduleInit, input.moduleSaltNonce);
  const topHat = hatIdToUint(String(input.topHatNumber));
  const memberHat = hatIdToUint(`${input.topHatNumber}.1`);
  const proposerHat = hatIdToUint(`${input.topHatNumber}.2`);
  const enc = (abi, functionName, args) => encodeFunctionData({ abi, functionName, args });
  const call = (operation, to, data, purpose) => ({ operation, to, value: '0', data, purpose });
  const calls = [
    call(1, a.createCall, enc(createCallAbi, 'performCreate2', [0n, input.arbitratorInitCode, input.arbitratorSalt]), 'deploy the reference Arbitrator from the Safe (CREATE2, owner = the Safe)'),
    call(0, arbitrator, enc(arbitratorAbi, 'setRealitio', [a.reality]), 'point the Arbitrator at Reality.eth'),
    call(0, a.moduleProxyFactory, enc(moduleProxyFactoryAbi, 'deployModule', [a.realityModuleMastercopy, moduleInit, BigInt(input.moduleSaltNonce)]), 'deploy the Reality module (owner, avatar and target = the Safe)'),
    call(0, safe, enc(safeSetupAbi, 'enableModule', [module]), 'enable the Reality module on the Safe'),
    call(0, a.hats, enc(hatsAbi, 'mintTopHat', [safe, input.groupName, '']), 'mint the top hat to the Safe'),
    call(0, a.hats, enc(hatsAbi, 'createHat', [topHat, 'Member', HAT_MAX_SUPPLY, safe, safe, true, '']), 'create the member hat (eligibility and toggle = the Safe)'),
    call(0, a.hats, enc(hatsAbi, 'createHat', [topHat, 'Proposer', HAT_MAX_SUPPLY, safe, safe, true, '']), 'create the proposer hat (eligibility and toggle = the Safe)'),
  ];
  for (const owner of input.owners) calls.push(call(0, a.hats, enc(hatsAbi, 'mintHat', [memberHat, owner]), `mint the member hat to ${owner}`));
  for (const owner of input.owners) calls.push(call(0, a.hats, enc(hatsAbi, 'mintHat', [proposerHat, owner]), `mint the proposer hat to ${owner}`));
  const wire = calls.map((c) => ({ ...c, value: BigInt(c.value) }));
  const safeTx = {
    to: a.multiSend,
    value: '0',
    data: multiSendCalldata(wire),
    operation: 1,
    safeTxGas: '0',
    baseGas: '0',
    gasPrice: '0',
    gasToken: zeroAddress,
    refundReceiver: zeroAddress,
    nonce: '0',
  };
  const typed = safeTxTypedData(input.chainId, safe, safeTx);
  return {
    safeInitializer,
    calls,
    packed: multiSendPack(wire),
    safeTx,
    safeTxHash: hashTypedData(typed),
    typedData: typed,
    moduleInitializer: moduleInit,
    predicted: {
      safe,
      arbitrator,
      module,
      topHat: topHat.toString(),
      memberHat: memberHat.toString(),
      proposerHat: proposerHat.toString(),
      topHatDotted: String(input.topHatNumber),
      memberHatDotted: `${input.topHatNumber}.1`,
      proposerHatDotted: `${input.topHatNumber}.2`,
    },
  };
}
