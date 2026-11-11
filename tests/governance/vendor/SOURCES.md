# Pinned bytes for the governance vectors (25-06)

Nothing third-party is vendored here any more. This file records where the vectors' outside bytes
come from and how each one is pinned.

## Reality.eth's Arbitrator: pinned by hash, not vendored

- Source: github.com/RealityETH/reality-eth-monorepo at commit
  `40b9886afdf7c0a3d755c6ed2738ce3f1c5bc632` (committed 2026-10-20), path
  `packages/contracts/bytecode/Arbitrator.bin`, at
  `https://raw.githubusercontent.com/RealityETH/reality-eth-monorepo/40b9886afdf7c0a3d755c6ed2738ce3f1c5bc632/packages/contracts/bytecode/Arbitrator.bin`.
- Licence: **GPL-3.0-only**. This repository is GPL-2.0-or-later, so the code is not included here,
  in any form.
- What it is: the creation code of Reality.eth's reference `Arbitrator`, as hex text with a `0x`
  prefix and a trailing newline (7173 bytes of text, 3585 bytes of code). Its constructor takes no
  argument and stores `msg.sender` as the owner. Its selectors include `owner()`, `setRealitio(address)`,
  `realitio()` and `transferOwnership(address)`.
- Pins (constants in `lib/governance.mjs`, and fields of `vectors/setup-batch.json` `inputs`):
  - sha256 of the file as published: `e160a66b6cbf70833733297c03474921c82bf2033bc50be6526a861f217024dd`;
  - sha256 of the decoded init code (`ARBITRATOR_INIT_CODE_SHA256`, `arbitratorInitCodeSha256`):
    `0x022c7c772f86b46876212d1c48b1190a4a86d723a90b09fc5ae19c55ef96aa0b`;
  - keccak256 of the decoded init code (`ARBITRATOR_INIT_CODE_KECCAK256`, `arbitratorInitCodeKeccak256`):
    `0x421c90ec59f15f919d228393747b45b195e12a6c54287f360c8c7e94f7f369c6`;
  - length: 3585 bytes (`ARBITRATOR_INIT_CODE_BYTES`, `arbitratorInitCodeBytes`).
- Use: a Group Safe delegatecalls Safe CreateCall 1.4.1 `performCreate2` with this init code, so the
  constructor runs with the Safe as `msg.sender` and the Safe owns the Arbitrator from the first
  block (D-07). On a real site, safe_governance fetches the code once at setup from the URL above and
  checks the sha256 on every use (its `resources/SOURCES.md`). The fork test in the relayer repo checks
  the deployed contract's `owner()` and `realitio()`.

### The stand-in in the vectors

`ARBITRATOR_STAND_IN` in `lib/governance.mjs` is 3585 bytes of `0xfe` (INVALID), the real code's
length. `vectors.mjs` builds `vectors/setup-batch.json` with it, so the file holds the stand-in in
every place the init code appears (7: `calls[0].data`, `multiSendPacked`, `safeTx.data`,
`typedData.message.data`, and `variants.any_member` `calls[0].data`, `multiSendPacked` and
`safeTxData`). Because the length is the same, every ABI length word and all padding are the real
batch's. `setupBatch` predicts the Arbitrator from the pinned keccak256, not from the bytes, and
refuses without it, so every predicted address is the one 25-06 deployed to
(`0x5Fc5C0a135cda8894610aceBD81bc50e2CFab6b7` for the Arbitrator).

`safeTxHash` in `setup-batch.json` is the one 25-06 recorded over the **real** code
(`0x82e7337c41600e9e87bc3bab0b0330e55c16dc628595b5af040df33adb53f556`). It is pinned in `vectors.mjs`
because it can't be recomputed without the code, so it is not the hash of that file's `typedData`.
The owners' signatures are made over it again with the anvil dev keys, so they are the recorded ones.

The file equals safe_governance's copy (`tests/fixtures/vectors/setup-batch.json` there) except the
two strings `arbitratorInitCodeSource` and `arbitratorInitCodeStandIn`, which point at this file.
`test/setup-batch.test.mjs` checks that `vendor/reality/Arbitrator.bin` does not exist, that the
stand-in appears exactly 7 times, that the predicted addresses are unchanged, and that both
signatures recover over the recorded `safeTxHash`.

### History

- Arbitrator.bin (GPL-3.0-only, RealityETH reality-eth-monorepo @40b9886, packages/contracts/bytecode)
  was vendored here as a test fixture from 9fa8ee5f until fe8ad618683cd495b58b8a64fbc8dbc9f39bca61;
  it is no longer included. (Lane origin: 5a055c13, 25-06.)
- `vectors/setup-batch.json` held the same real code bytes (7 copies, as hex) over the same span,
  from 9fa8ee5f until fe8ad618683cd495b58b8a64fbc8dbc9f39bca61; it now holds the 0xfe stand-in.

## SafeProxy creation code (a constant inside lib/governance.mjs)

`SAFE_PROXY_CREATION_CODE` is what SafeProxyFactory 1.4.1 (`0x4e1DCf7AD4e460CfD30791CCC4F9c8a4f820ec67`)
returns from `proxyCreationCode()` on Ethereum Sepolia, read at fork block 12087317 on 2026-11-08
(974 bytes, keccak256 `0x1856e0ee08399d74e0ea0b03adca210aeade6f748969ac023cdcb4dd62dcaf5f`).
`vectors.mjs` checks the hash on every run, and the relayer's setup fork test deploys the Safe through
the real factory and requires it to land on the predicted address.
