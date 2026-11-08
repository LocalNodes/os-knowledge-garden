# Governance test package (Phase 25)

This package holds the one pinned dependency (viem, exactly 2.57.10) that Phase 25 uses to produce and check every expected hash. It is test tooling only. Nothing in it ships in the Drupal modules or the relayer image.

## Scripts

- `node vectors.mjs` writes `vectors/*.json` from committed inputs. `node vectors.mjs --check` regenerates them in memory and exits 1 on any byte difference. PHP and relayer tests keep copies of these files and record the generator commit in their fixtures README. `node vectors.mjs --fork-check` (added with the Reality vectors) deploys a module proxy on a fork and requires its answers to equal the vectors.
- `node smoke.mjs --fork` runs the D-08 path on an anvil fork of Ethereum Sepolia: a 2-of-2 Safe 1.4.1, a Safe-owned ENSv2 name registered by another account, Snapshot space settings signed by both owners as `SafeMessage`, and the Safe's `isValidSignature` returning `0x1626ba7e`. It also proves that signatures in descending owner order, a single signature, and a different hash are all refused. `node smoke.mjs --live` is a stub until plan 25-03.

Install with `npm ci --ignore-scripts` (lockfile only; the package gate is recorded in `.planning/phases/25-group-governance-snapshot-zodiac-hats/25-PACKAGE-CHECK.md`).

## Running the fork

```
bash tests/e2e/fork/fork.sh mode ethereum_sepolia   # must print: fork
bash tests/e2e/fork/fork.sh up ethereum_sepolia
node tests/governance/smoke.mjs --fork
bash tests/e2e/fork/fork.sh down all                # always
```

Fork mode needs `LN_FORK_URL_ETHEREUM_SEPOLIA` in the gitignored `tests/e2e/.env.fork`. Use a public URL from the network registry's `rpc_urls`, never a keyed one. It also needs a pinned block: `bash tests/e2e/fork/fork.sh pin ethereum_sepolia` writes one into `tests/e2e/fork/forks.json`. A public RPC only serves recent state, so re-pin before a run if the pin is more than a few hours old, and do not commit the pin from this package's work.

## Keys

Every key signing here is anvil's public dev key (the well-known "test test ... junk" mnemonic), and it only ever signs on the local fork. Live mode, when added, reads its mnemonic from CI secrets only.
