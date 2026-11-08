# Vendored bytes for the governance vectors (25-06)

Every file here was fetched once with a keyless read and is hashed below. `vectors.mjs` reads
`reality/Arbitrator.bin` to build `vectors/setup-batch.json`, and `node vectors.mjs --check`
fails if the file is missing or is not 0x-prefixed lowercase hex.

## reality/Arbitrator.bin

- Source: github.com/RealityETH/reality-eth-monorepo at commit
  `40b9886afdf7c0a3d755c6ed2738ce3f1c5bc632` (committed 2026-10-20), path
  `packages/contracts/bytecode/Arbitrator.bin`. Fetched from
  `https://raw.githubusercontent.com/RealityETH/reality-eth-monorepo/40b9886afdf7c0a3d755c6ed2738ce3f1c5bc632/packages/contracts/bytecode/Arbitrator.bin`.
- What it is: the creation code of Reality.eth's reference `Arbitrator`, as hex text with a `0x`
  prefix and a trailing newline (7173 bytes of text, 3585 bytes of code). Its constructor takes no
  argument and stores `msg.sender` as the owner. Its selectors include `owner()`, `setRealitio(address)`,
  `realitio()` and `transferOwnership(address)`.
- sha256 of the file as stored: `e160a66b6cbf70833733297c03474921c82bf2033bc50be6526a861f217024dd`
  (check with `sha256sum tests/governance/vendor/reality/Arbitrator.bin`).
- sha256 of the decoded init code (the `arbitratorInitCodeSha256` field in `vectors/setup-batch.json`):
  `0x022c7c772f86b46876212d1c48b1190a4a86d723a90b09fc5ae19c55ef96aa0b`.
- Use: a Group Safe delegatecalls Safe CreateCall 1.4.1 `performCreate2` with this init code, so the
  constructor runs with the Safe as `msg.sender` and the Safe owns the Arbitrator from the first
  block (D-07). The fork test in the relayer repo checks the deployed contract's `owner()` and `realitio()`.

## SafeProxy creation code (a constant inside lib/governance.mjs)

`SAFE_PROXY_CREATION_CODE` is what SafeProxyFactory 1.4.1 (`0x4e1DCf7AD4e460CfD30791CCC4F9c8a4f820ec67`)
returns from `proxyCreationCode()` on Ethereum Sepolia, read at fork block 12087317 on 2026-11-08
(974 bytes, keccak256 `0x1856e0ee08399d74e0ea0b03adca210aeade6f748969ac023cdcb4dd62dcaf5f`).
`vectors.mjs` checks the hash on every run, and the relayer's setup fork test deploys the Safe through
the real factory and requires it to land on the predicted address.
