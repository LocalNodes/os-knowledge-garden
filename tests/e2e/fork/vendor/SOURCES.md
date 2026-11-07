# Vendored bytes for the local e2e chains (21-39)

Every file here was fetched once, with keyless network reads only, and is checked again by
`fork.sh` on every `up` (CREATE2 address before sending, runtime code hash after) and by
`fork.sh check <id>`.

## Sources

- **safe-singleton-factory**: github.com/safe-global/safe-singleton-factory at commit
  `319ff7e6fed0249d6ceadee7b764ae19c08f2171` (main on 2026-11-06, committed 2026-10-30).
  File: `artifacts/<chainId>/deployment.json`, fetched from
  `https://raw.githubusercontent.com/safe-global/safe-singleton-factory/319ff7e6fed0249d6ceadee7b764ae19c08f2171/artifacts/<chainId>/deployment.json`.
  The factory lands at `0x914d7Fec6aaC8cd542e72Bca78B30650d45643d7` on every chain id.
  Its runtime code hash, read with `eth_getCode` on Ethereum Sepolia and Base Sepolia
  (identical), is `0x2fa86add0aed31f33a762c9d88e807c475bd51d0f52bd0955754b2608f7e4989`
  (`FACTORY_CODE_HASH` in fork.sh).
- **Safe v1.4.1 contracts**: canonical addresses from github.com/safe-global/safe-deployments
  at commit `ca9cb0d2d5f5175fc8e41d579f356748d2bc1c62`, `src/assets/v1.4.1/<file>.json`,
  `deployments.canonical.address` (each also lists 11155111 as `canonical`). Each contract's
  creation transaction on Ethereum Sepolia comes from Blockscout's keyless API
  (`https://eth-sepolia.blockscout.com/api/v2/addresses/<address>`, field
  `creation_transaction_hash`; the plan's `creation_tx_hash` name is out of date). The
  transaction itself and the runtime code come from the registry's first keyless RPC,
  `https://eth-sepolia-testnet.api.pocket.network` (`eth_getTransactionByHash`,
  `eth_getCode`). Every creation transaction was checked to have `to` = the singleton
  factory; its input splits into salt (first 32 bytes) and initcode (the rest). The runtime
  code hash is `cast keccak` of the code at the canonical address on Ethereum Sepolia, with
  the pinned Foundry image (v1.8.6, sha256:4a16a86f…2464).
  The vendoring was done by a one-shot script; it isn't kept, because each vendor JSON
  records everything needed to repeat it.

## Files

| File | Source | sha256 of file | Code hash |
|------|--------|----------------|-----------|
| safe-singleton-factory/11155111.json | safe-singleton-factory@319ff7e6 artifacts/11155111/deployment.json | c061dff556a2ab7700ff60e44aea9d1f3f0f9040277599f672a4b4ee0b5be06c | 0x2fa86add0aed31f33a762c9d88e807c475bd51d0f52bd0955754b2608f7e4989 (factory runtime) |
| safe-1.4.1/safe_proxy_factory.json | Sepolia tx 0x60f6ba17399ebc1441f0a0cd47ead622f0e0c64ad636c093b9d75a938d90c409 → 0x4e1DCf7AD4e460CfD30791CCC4F9c8a4f820ec67 | 471c0123abd720c772118f3c6431c3aa8fc8ae03604d1f028b11944297246a1f | 0x50c3cdc4074750a7a974204a716c999edd37482f907608d960b2b025ee0b3317 |
| safe-1.4.1/create_call.json | Sepolia tx 0x407ce0c521d2cc7f254b877538de41c395ec21c7b86b073c1cfd089fc23f7464 → 0x9b35Af71d77eaf8d7e40252370304687390A1A52 | ae78c3f58009ab3761944866cdd7b54ccb0976e02d15a6e39a1f0b490c072275 | 0x2b3060c55fcb8275653e99ad511a71f67ba76934ed66a7d74d6e68b52afff889 |
| safe-1.4.1/fallback_handler.json | Sepolia tx 0x5cefc1ac644325c9ddc4402b2fdf13f20a2e2170adbee59d8eec38372977b321 → 0xfd0732Dc9E303f09fCEf3a7388Ad10A83459Ec99 | 2621509b58686a7214073b1dcf4e6c32cd37d556f5d290f9192b4d42d726c837 | 0x7c6007a5d711cea8dfd5d91f5940ec29c7f200fe511eb1fc1397b367af3c42f9 |
| safe-1.4.1/multi_send_call_only.json | Sepolia tx 0xdb1016cff05d68f606074cc756b3f400c97bdee1da1ac52901e0fa0156cae3cf → 0x9641d764fc13c8B624c04430C7356C1C7C8102e2 | 5432315de1366ef9b7ccb296762dd14c7edb012c7689caaae5e295a353068fad | 0xecd5bd14a08c5d2122379900b2f272bdf107a7e92423c10dd5fe3254386c9939 |
| safe-1.4.1/multi_send.json | Sepolia tx 0x8a14f832ae966e20a31211e4b719efae46601fdc0bc688137cfea990e5ec2a8a → 0x38869bf66a61cF6bDB996A6aE40D5853Fd43B526 | 044d7e2dec0acf7f3f965d5b317808c5d1b180253ab826cf30beaf50097cc472 | 0x0e4f7fc66550a322d1e7688e181b75e217e662a4f3f4d6a29b22bc61217c4b77 |
| safe-1.4.1/safe_l2_singleton.json | Sepolia tx 0x1ebc78b845a38e7301cc271e06e371640d2e8ed0fdf5c13b8ea62dbcd095e0b3 → 0x29fcB43b46531BcA003ddC8FCB67FFE91900C762 | d52b7abc6b99d1ee5799f61492c2d3c71bb3a15b9a6241205e3619624da5df99 | 0xb1f926978a0f44a2c0ec8fe822418ae969bd8c3f18d61e5103100339894f81ff |
| safe-1.4.1/safe_singleton.json | Sepolia tx 0x6bce5ca720a6c4af97e0742229670575ac6433b43d6ea6642e78c539a7653aeb → 0x41675C099F32341bf84BFc5382aF534df5C7461a | f983327fd47bae789feb3f32a7ded275ecf0b45b1421bce7cfd96a0b101d38a7 | 0x1fe2df852ba3299d6534ef416eefa406e56ced995bca886ab7a553e6d0c5e1c4 |
| safe-1.4.1/sign_message_lib.json | Sepolia tx 0x4faf22eb2ef17bdf20df39adc3e64cb548f15fe4e99fe854057c867c584a33e2 → 0xd53cd0aB83D845Ac265BE939c57F53AD838012c9 | afbfc60b0eaf77f73991a87548b230e4f3152eb3e9df7a505a187dac23c4e8b2 | 0x525c754a46b79e05543a59bb61e8de3c9eee0d955a59352409cbe67ea1077528 |
| safe-1.4.1/simulate_tx_accessor.json | Sepolia tx 0xa48678baa73fdaabbdec330a14783e3b77f6127ea6ebb6afe98f3ea19a65a9f7 → 0x3d4BA2E0884aa488718476ca2FB8Efc291A46199 | af9f8810ed03a1e4cab09c24b04d8856a799d8d5bf3d3ccc895cc598e6da33b0 | 0x91f82615581fc73b190b83d72e883608b25e392f72322035df1b13d51766cf8d |
| safe-singleton-factory/84532.json | safe-singleton-factory@319ff7e6 artifacts/84532/deployment.json | a7e961ad87af8beba22914b3b5c537f85623d2ab52fa226e110743f6aeaaf9a1 | 0x2fa86add0aed31f33a762c9d88e807c475bd51d0f52bd0955754b2608f7e4989 (factory runtime) |
| MockUSDC.sol | written for this lane (test-only ERC-20, 6 decimals, open mint, no constructor state) | 6935213a72367140e5f46864757c267a6ebce99c65a8933b6b99e9d67853f2b1 | n/a |
| MockUSDC.json | compiled from MockUSDC.sol with forge in the pinned image, solc 0.8.24+commit.e11b9ed9, evm cancun, optimizer 200 runs (exact command in the file's forgeCommand) | 3a2c0a907462d2a550f21c99b828c7b7f83506e7892a2d4df2d31630fbc1a27a | 0x63cd4857b6f8c3e5f538f5fb5d1c45b4e7c888b1db029d93e5703276648f7323 (runtime) |

The same salt and initcode deploy every Safe contract to the same address on both chain ids
(the factory address and the initcode don't depend on the chain), so the Ethereum Sepolia
creation transactions serve Base Sepolia's local chain too. Base Sepolia's factory
artifact has a presigned gas price (1000254 wei) below anvil's default 1 gwei base fee, and
anvil runs chain 84532 as an OP-stack chain (L1 data fee), so fork.sh lowers the next
block's base fee to 0 when the presigned price is below it and funds the signer with
gasPrice x gasLimit plus 1 ETH.
