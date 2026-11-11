# Wallet e2e suite (Phase 21)

Real browser runs of the Safe and group fund screens against two local anvil chains. The
site is the e2e lane's own fresh3-e2e, reached as **https://fresh3.test**. Signing is done by
an injected test wallet backed by anvil's well-known dev accounts, so no key exists in this
repo and nothing ever touches a real chain.

## What it covers

The automated half of 21-30's Run 2, on both chain ids (Ethereum Sepolia 11155111 and Base
Sepolia 84532, run as local chains):

| Run 2 step | Spec › test |
|---|---|
| sign in with the wallet (SIWE) | siwe.e2e.ts › the organizer signs in |
| the wallet's safety refusals | wallet-guard.e2e.ts › cases (1) to (11) |
| set up a group fund from the group tab | group-fund.e2e.ts › set up the group fund from the group tab |
| put money in it | group-fund.e2e.ts › fund the group fund |
| balances come from the chain | group-fund.e2e.ts › balances come from the chain |
| link a Safe made outside the app, unlink it | link-safe.e2e.ts › link (a) a Safe made outside the app |
| link the real pre-existing Safe (fork mode only) | link-safe.e2e.ts › link (b) the real pre-existing Safe |
| propose a payment | payments.e2e.ts › propose |
| approve as the organizer | payments.e2e.ts › approve (organizer) |
| approve as the second approver | payments.e2e.ts › approve (approver) |
| send, and the chain and balance agree | payments.e2e.ts › send |
| reload while the send waits for its block (step f) | payments.e2e.ts › reload while waiting |

Each step checks three things: what the page says, what the chain says (read with `cast`
inside the anvil container), and what the site's database holds.

## How to run it

From the fresh3-work root:

```
bash tests/e2e/site/build.sh            # after new product commits; --reinstall for a fresh site
npm run test:e2e                        # = bash tests/e2e/run.sh all
bash tests/e2e/run.sh ethereum_sepolia  # one network
```

`run.sh`:

- refuses under 10 GB free disk, or while `safe_smart_accounts_rpc_fixture` is enabled on
  fresh3-e2e (the suite never runs against canned reads);
- holds the run lock `tests/e2e/.state/fresh3-e2e.lock` (the same flock as `build.sh` and
  21-45's recording), waiting up to 30 minutes;
- uses one run id for every network (`LN_E2E_RUN_ID`, or the UTC time);
- starts the chains, resets each one to its post-setup snapshot before its pass, and stops
  them all on exit, whatever happens;
- exits 0 only when every network passed.

To run one spec by hand:

```
bash tests/e2e/fork/fork.sh up all
LN_E2E_NETWORK=base_sepolia npx --no-install playwright test -c tests/e2e/playwright.config.ts payments.e2e.ts
bash tests/e2e/fork/fork.sh down all
```

**Local and fork mode** (21-39). Local mode is the default and needs no key: a plain anvil
with the Safe v1.4.1 contracts at their canonical addresses and a mock USDC. Fork mode forks
the real chain (set `LN_FORK_URL_<NETWORK>` and pin a block, see `fork/README.md`). Only
fork mode runs link (b); local mode records it as "skipped (fork mode only)".

Each run uses fresh per-run groups ("E2E Fund/Link/Pay <runId>-<network>"), so runs don't
meet each other's funds. Nothing is deleted.

## The safety rule

Only anvil's dev keys sign, only on the local anvil chains, and only for the .test host.
No session signs anything on Sepolia or any real network. `wallet/test-wallet.ts` enforces it:

- `window.ethereum` exists only in frames on a .test or loopback host;
- every call checks the page and the frame host;
- every signature and send first checks that the chain is the suite's own loopback anvil
  (client version, the recorded instance id and chain id);
- only the selected dev account signs; chain-control methods are refused.

`wallet-guard.e2e.ts` tests each refusal. Each guard was seen to go red on a planted
mutation: `.planning/phases/21-real-safe-reads-balances/gates/e2e-wallet-guard-proof.txt`
(caught 10 of 10). `playwright.config.ts` refuses any base URL outside .test and loopback.

The site sends .test requests to the local chains through `$settings['safe_rpc_urls']` in
settings.php, which replaces the stored RPC list. With a chain stopped, a .test read fails at
the anvil URL; it never falls back to a public RPC.

Lane rules: product bugs found here are recorded and reported to the orchestrator, never
fixed in this lane.

## Evidence

- Traces and videos: `.planning/phases/21-real-safe-reads-balances/e2e-evidence/runs/<runId>/<network>/`.
  They are big and gitignored. Open one with `npx --no-install playwright show-trace <trace.zip>`.
- `e2e-evidence/INDEX.md` (committed): one block per run and network, one row per test with
  its trace, video and result, and a line naming the site's four commit SHAs.
- Page, chain and database values per step: `tests/e2e/.state/results-<network>.json`
  (local; `run.sh` copies it into the run's evidence folder). Failures carry the on-screen
  text, the error and the trace path.

## Notes

- **SIWE.** The signed message names fresh3.test (21-40). Its chain line isn't asserted:
  siwe_login gets an allowed-chain list in Phase 25.
- **SIWE's nonce limit (fresh3-e2e only).** siwe_login allows 30 sign-in nonces per IP per
  5 minutes. One whole-suite run on both networks asks for more (34 in about 4 minutes, run
  20261107T193200Z). So `site/site.sh code` writes
  `$config['siwe_login.settings']['flood']['nonce_ip_limit'] = 600;` into fresh3-e2e's
  gitignored settings.local.php (orchestrator ruling, 2026-11-07). It's a runtime override:
  siwe_login's config, the tracked settings.php and other sites keep 30. Check it with
  `drush config:get siwe_login.settings flood --include-overridden`. `run.sh all` runs both
  networks back to back.
- **No fixture module.** fresh3-e2e never has `safe_smart_accounts_rpc_fixture` on. The
  copy and accessibility checks (21-28, 21-29) use their own site, fresh3-p21-a11y.
- **Arbitrator stand-in (25-29).** The wallet suite never reaches governance setup. Where
  `safe_governance` is on the site, `seed-e2e.php` still enables the test-only
  `safe_governance_arbitrator_fixture`, so nothing here can download the real Arbitrator code from
  GitHub. It changes no chain read, and is not the RPC fixture above. The live judge
  (`tests/e2e-live/`) must run without it and refuses to start while it is enabled.
- **Shared host.** desktop-dev runs other lanes' containers. Each new container makes
  Chromium abort requests in flight with `net::ERR_NETWORK_CHANGED`. The helpers retry page
  loads, link follows and the SIWE sign-in once, and list each retry in the results file.
  Proposals, approvals and sends are never retried. The SIWE and guard specs don't retry,
  so a whole-suite run can still fail on this once in a while: check the trace for
  ERR_NETWORK_CHANGED before calling it a product failure.

## Base Sepolia (21-RESEARCH assumption A3)

A3: protocol-kit picks the Safe contract addresses by the wallet's chain, so the same JS
deploys and runs payments on chain 84532 with the contracts at their canonical addresses.

**Outcome (21-43, run 20261107T062045Z, local mode): A3 holds.** On chain 84532 the same JS,
with no contract addresses passed in:

- set up a 2-of-2 group fund (the Safe proxy came from the registry's factory, v1.4.1);
- took two eth_signTypedData_v4 approvals, which the server accepted after checking them
  against the chain;
- sent the payment: the Safe's nonce went from 0 to 1, the recipient got exactly
  10^15 wei, and the receipt has status 0x1 and an ExecutionSuccess log from the Safe.

The `send` test still fails on Base Sepolia, as it does on Ethereum Sepolia, for a product
bug that isn't about addresses: the server's receipt check reads the Safe 1.3.0 log layout,
so a v1.4.1 payment stays "Waiting for the public record to confirm this." and never shows
"Sent" (21-43-SUMMARY, finding 1). The per-step values are in
`e2e-evidence/runs/20261107T062045Z/base_sepolia/results-base_sepolia.json`.
