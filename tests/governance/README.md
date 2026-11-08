# Governance test package (Phase 25)

This package holds the one pinned dependency (viem, exactly 2.57.10) that Phase 25 uses to produce and check every expected hash. It is test tooling only. Nothing in it ships in the Drupal modules or the relayer image.

## Scripts

- `node vectors.mjs` writes `vectors/*.json` from committed inputs. `node vectors.mjs --check` regenerates them in memory and exits 1 on any byte difference. PHP and relayer tests keep copies of these files and record the generator commit in their fixtures README. `node vectors.mjs --fork-check` (added with the Reality vectors) deploys a module proxy on a fork and requires its answers to equal the vectors.
- `node smoke.mjs --fork` runs the D-08 path on an anvil fork of Ethereum Sepolia: a 2-of-2 Safe 1.4.1, a Safe-owned ENSv2 name registered by another account, Snapshot space settings signed by both owners as `SafeMessage`, and the Safe's `isValidSignature` returning `0x1626ba7e`. It also proves that signatures in descending owner order, a single signature, and a different hash are all refused. 
- `node smoke.mjs --live` is the same path on a real chain (plan 25-03, the D-24 hard stop): account 0 pays for the Safe, the ENSv2 name, a Reality 3.0 template (the Zodiac default question template, whose id the localnodes_web3 registry needs) and the test-token mint; accounts 0 and 1 own the 2-of-2 Safe; account 1 only signs. It builds the settings (hats strategy, quorum 1, period 600, safeSnap with the module address predicted from the factory, no module deployed), has both owners sign the `SafeMessage`, requires `isValidSignature` to return `0x1626ba7e`, then POSTs the envelope to `https://testnet.seq.snapshot.org/` and reads the space back from `https://testnet.hub.snapshot.org/graphql` (network `11155111`, admins `[]`). It reads only these environment variables: `SMOKE_MNEMONIC`, `SMOKE_RPC_URL`, `SMOKE_EXPLORER_TX` (a URL pattern with `{hash}`), `SMOKE_HUB` (`off` skips the sequencer and the hub) and `SMOKE_HAT_ID` (default `3758.1.1`). Output is `STEP <name> <value>` lines, then `SMOKE PASS`, `SMOKE FAIL <step>`, or `REFUSED <status> <body>` (exit 3). The mnemonic and the RPC URL are redacted from every line it prints. A refusal from the hub is never worked around by adding an admin, a moderator or a controller to the space (D-08).

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

Every key signing here is anvil's public dev key (the well-known "test test ... junk" mnemonic), and it only ever signs on the local fork. `--live` reads its mnemonic from `SMOKE_MNEMONIC` only: on the real run that is the CI secret `SYNPRESS_SEED_PHRASE` (a throwaway test wallet Tom created, never seen by a Claude session); against the fork it is anvil's public mnemonic with `SMOKE_HUB=off`.

Dry run of the live path against the fork:

```
bash tests/e2e/fork/fork.sh pin ethereum_sepolia
bash tests/e2e/fork/fork.sh up ethereum_sepolia
SMOKE_MNEMONIC='test test test test test test test test test test test junk' \
  SMOKE_RPC_URL=http://127.0.0.1:18545 SMOKE_EXPLORER_TX='http://fork/tx/{hash}' SMOKE_HUB=off \
  node tests/governance/smoke.mjs --live
bash tests/e2e/fork/fork.sh down all
```

## The live workflow and its pinned actions

`.github/workflows/governance-smoke.yml` runs `smoke.mjs --live` on GitHub Actions. It is `workflow_dispatch` only (optional input `hat_id`), has `permissions: contents: read`, a 30 minute timeout, no artifacts, and passes `secrets.SYNPRESS_SEED_PHRASE` only in the env of the one smoke step (`secrets.SEPOLIA_RPC_URL` is optional there; the registry's public RPC and explorer pattern are read from the `localnodes_web3` Ethereum Sepolia YAML in an earlier step otherwise). Dispatch it with `gh workflow run governance-smoke.yml -R LocalNodes/os-knowledge-garden --ref main`.

Every action is pinned to a full commit SHA that was at least 14 days old when it was chosen (checked with `gh api repos/<owner>/<repo>/commits/<sha>`, newest release tag first). A newer tag waits until its commit is 14 days old.

| Action | Tag | Commit SHA | Commit date (UTC) |
|--------|-----|------------|-------------------|
| actions/checkout | v7.0.1 | 3d3c42e5aac5ba805825da76410c181273ba90b1 | 2026-07-17T18:45:11Z |
| actions/setup-node | v7.1.0 | 949feb2413d6458794dcd2491c4babbbce0c15c1 | 2026-10-08T02:43:26Z |

Checked on 2026-11-08: the newest release tag of each action was already at least 14 days old, so no older tag was needed.
