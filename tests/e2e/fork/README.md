# Local e2e chains (21-39)

Two local anvil chains for the Phase 21 wallet e2e lane. They let the automated runs that
come before 21-30's Run 2 (ROADMAP Phase 21, "Plan input (Tom, 2026-11-06)"; D-31) deploy
Safes, sign, propose, approve and execute with fake money, then reset.

- **R-A** (2026-11-06 cutover): everything runs on desktop-dev, two chains may run at once,
  and the e2e lane owns its own site (fresh3-e2e, built by 21-40).
- **R-C**: no provider key is needed. Local mode is the default; fork mode is optional.

The chains are plain containers outside DDEV (`ddev start` never starts them). Once the
fresh3-e2e site exists, `fork.sh` attaches each running chain to its DDEV network, where the
site reaches it as `http://anvil-ethereum-sepolia:8545` / `http://anvil-base-sepolia:8545`
(21-40 routes only fresh3.test requests there).

## Local mode (default)

`fork.sh up <id>` starts a plain anvil (no fork) with the chain id from the registry YAML in
localnodes_web3's `config/install` (one source, 21-07):

| id | chain id | host URL |
|----|----------|----------|
| ethereum_sepolia | 11155111 | http://127.0.0.1:18545 |
| base_sepolia | 84532 | http://127.0.0.1:18546 |

Then it sets the chain up the way the real networks are:

1. It replays the safe-singleton-factory's presigned deployment transaction for that chain
   id, so the factory sits at `0x914d7Fec6aaC8cd542e72Bca78B30650d45643d7`.
2. Through the factory it deploys the Safe v1.4.1 set at the canonical addresses:
   safe_singleton, safe_l2_singleton, safe_proxy_factory, fallback_handler, multi_send,
   multi_send_call_only, sign_message_lib, create_call and simulate_tx_accessor. Before each
   send it recomputes the CREATE2 address from the vendored salt and initcode and requires
   the canonical address; after, it requires the vendored runtime code hash.
3. It places a mock USDC (6 decimals, anyone can mint) at the registry's USDC address with
   `anvil_setCode`. The registry YAMLs and CanonicalDeployments are unchanged.
4. It takes an `evm_snapshot` and writes the run state (below).

Every vendored byte and where it came from is in [vendor/SOURCES.md](vendor/SOURCES.md).
Check a running chain with `bash tests/e2e/fork/fork.sh check <id>`: 11 `CODE OK` lines in
local mode (factory, nine Safe contracts, USDC).

Anvil runs chain 84532 as an OP-stack chain, so its transactions also pay a small L1 data
fee, and its base fee is 0 after setup.

## Commands

Run from anywhere; the script finds the repo root from its own path.

```
bash tests/e2e/fork/fork.sh up <id|all>        # start and set up; `all` runs both at once
bash tests/e2e/fork/fork.sh reset <id>         # back to the post-setup state
bash tests/e2e/fork/fork.sh check <id> [name…] # code hashes; exit 1 on a mismatch
bash tests/e2e/fork/fork.sh status             # containers and run state
bash tests/e2e/fork/fork.sh connect [<id|all>] # join ddev-${LN_SITE_PROJECT:-fresh3-e2e}_default
bash tests/e2e/fork/fork.sh mode <id>          # prints local or fork
bash tests/e2e/fork/fork.sh pin <id>           # fork mode only
bash tests/e2e/fork/fork.sh down [<id|all>]    # stop and remove
bash tests/e2e/fork/selftest.sh <id>           # signing, impersonation, time, reset, code
```

Exit codes: 0 done, 1 failed, 2 usage or refused.

**Reset.** Local mode: `evm_revert` to the recorded snapshot (anvil consumes it), then a new
snapshot, recorded with the block number. Anvil's instanceId stays the same across a revert.
Fork mode: `anvil_reset` with `forking.blockNumber` set to the pinned block, never with no
params.

**Run state.** Each `up` and `reset` writes `tests/e2e/.state/forks-run.json` (gitignored):
chainId, mode, block, snapshotId (local), hostUrl, siteUrl, container, remote, instanceId
(from `anvil_metadata`), startedAt and the DDEV network it joined. 21-41's test wallet trusts
only loopback chains listed there with a matching instanceId.

## Fork mode (optional)

Fork mode forks the real chain at a pinned block. It runs only when a provider URL is set, and
its trigger is Tom's archive key, whenever it comes. Until then local mode is all there is.

Put the URL in your shell or in the gitignored `tests/e2e/.env.fork`, never in a tracked file:

```
export LN_FORK_URL_ETHEREUM_SEPOLIA='<your provider HTTPS URL>'
export LN_FORK_URL_BASE_SEPOLIA='<your provider HTTPS URL>'
```

(`.env.fork` takes the same lines without `export`.) Then `fork.sh pin <id>` records the
provider's head minus 64 blocks in forks.json; commit that to move the pin. `up` refuses a fork
without a pinned block, checks the chain id and block, and checks the factory and the nine
Safe contracts against the real chain (no USDC placement: the real token is there).

The URL holds a key. The scripts never print it, log it or write it to the run state, and it
reaches the container only as an environment variable that the container's shell expands, so
it isn't in any command line. **Never paste `docker logs` of a fork-mode chain into
.planning:** anvil's banner and errors can show the fork URL.

## Remote use (R4)

Ports publish on 127.0.0.1 only. `LN_FORK_BIND` (publish address) and `LN_FORK_HOST` (the
address the scripts use) default to 127.0.0.1; any value but 127.0.0.1, localhost or ::1 is
refused with exit 2 unless `LN_FORK_ALLOW_REMOTE=1`. Even then 21-41's test wallet refuses a
non-loopback chain: anvil serves unlocked dev accounts and admin methods to anyone who can
reach the port.

## Lane rules (R-D)

- Only anvil's well-known dev keys and mnemonic sign, and only on these local chains.
- No session signs anything on Sepolia or Base Sepolia. The local chains reuse those chain
  ids, so a transaction signed here is valid in form there; it's never sent there.
- A product bug found by the e2e lane is recorded and reported to the orchestrator, never
  fixed in this lane.

## Gnosis

None. D-32 gives Gnosis no registry entry, and local mode has nothing to fork.
