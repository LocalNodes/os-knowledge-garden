# tests/e2e-live: real MetaMask suites (Phase 21)

Two suites drive a real MetaMask 13.13.1 extension, downloaded and loaded through Synpress 4.1.2,
through the group fund screens. Both use the shared page helpers in `tests/e2e/helpers/treasury.ts`
through their `WalletDriver` seam, so they click the same screens as the injected-wallet suites.
MetaMask's own prompts use `metamask-steps.ts`, because Synpress 4.1.2's selectors don't match
MetaMask 13.13.1's screens.

| Suite | Where it runs | Chain | Key |
|-------|---------------|-------|-----|
| `local-anvil.e2e.ts` (21-45 Task A) | desktop-dev, by hand, recorded | local anvil, chain id 84532, behind a host block | anvil's public dev mnemonic |
| `live-sepolia.e2e.ts` (21-45 Task B) | CI only, once a night | live Ethereum Sepolia | a throwaway wallet, in CI secrets only |

The packages are pinned exactly in `package-lock.json` and installed with scripts off. Playwright is
the repo root's single 1.63.0 copy, called by its path.

## The nightly live-Sepolia run

`.github/workflows/nightly-wallet-e2e.yml` runs once a night (03:41 UTC) and on demand. It:

1. builds the site image from the checked-out commit (`docker/Dockerfile`; not pushed);
2. starts it with `docker-compose.yaml` plus `compose.ci.yaml` on `http://localhost:8080`, with
   `WEB3_ENABLED=true` and `DEMO_MODULE=none`. It starts only `opensocial`, `mariadb`, `solr` and
   `qdrant`, by name, so no other service in the compose file is pulled or started;
3. waits for the install, then runs `seed-live.php`: user `ci-organizer`, linked to the wallet's
   address, and the group "CI Nightly Fund <date>", with `ci-organizer` as its only organizer;
4. builds the wallet's MetaMask profile (`cache-live.ts`, which imports the seed and checks the
   MetaMask zip's sha256);
5. runs `live-sepolia.e2e.ts`. It signs in with SIWE, puts the site on Ethereum Sepolia, sets up
   the group fund with threshold 1, and funds it with 0.0002 ETH from the wallet. It then proposes,
   approves and sends 0.0001 ETH back to the wallet, waits for "Sent", and checks that "Refresh
   balances" shows what a direct `eth_getBalance` says;
6. always shows the site's log tail and removes the stack and its volumes.

It uploads no artifact: no MetaMask profile, no trace, no screenshot, no build record.

### Why it is CI only

The run needs a real key. The key is a throwaway wallet's seed phrase, and it exists only as a
GitHub Actions secret, so a run anywhere else has no key. As a second guard, the live config's
global setup refuses unless `GITHUB_ACTIONS` is `true` and the site is on `localhost` or a `.test`
host. The cache build refuses outside CI too. Trace, video and screenshots are off, because the
browser profile holds the imported wallet. Only `wallet-setup/live/live.setup.ts` reads the seed,
and only inside its setup function.

What can be checked on a machine without the key: `bash tests/e2e-live/scripts/live-suite-check.sh`
(the list, the refusals and where the seed variable is named) and
`bash tests/e2e-live/scripts/workflow-check.sh` (the workflow's structure, the compose override,
the seed's PHP syntax and the action pins). Never run the live suite locally.

### Secrets and variable (names only)

On LocalNodes/os-knowledge-garden, under Settings, then Secrets and variables, then Actions:

- secret `SYNPRESS_SEED_PHRASE`: the throwaway wallet's seed phrase;
- secret `SYNPRESS_WALLET_PASSWORD`: MetaMask's local password in the CI browser;
- secret `SEPOLIA_RPC_URL` (optional): a provider URL for Ethereum Sepolia. Without it, the site
  and the suite use the registry's first public URL;
- variable `SYNPRESS_WALLET_ADDRESS`: the wallet's public address.

The seed reaches only the wallet cache-build step. The password reaches the cache-build and test
steps (the test unlocks MetaMask with it). The RPC secret reaches only the
compose-up step (as the site's `SAFE_RPC_URL_ETHEREUM_SEPOLIA`) and the test step.

### Running it on demand

`gh workflow run nightly-wallet-e2e.yml -R LocalNodes/os-knowledge-garden --ref main`, or the
Actions tab, then "Nightly wallet e2e (live Sepolia)", then "Run workflow". It runs from `main` only.

### What it needs in the image

The run tests the module code in the image it builds. Until the Phase 21 module releases are in the
image (21-31), it tests the older module code, and its results say little about Phase 21.

### Keeping the wallet funded

One night spends roughly 0.002 Sepolia ETH in gas, at most. The fund keeps 0.0001 ETH a night,
and the rest of the payment comes back to the wallet. The run fails early with "the throwaway
wallet has Sepolia ETH" when the wallet holds 0.005 ETH or less. Top it up from a Sepolia faucet
(see 21-LIVE-CHECK, "Getting test ETH").

The same wallet pays for the manual governance smoke (`governance-smoke.yml`). Two jobs sending
from one wallet at once could collide on its nonce, so both workflows share the concurrency group
`sepolia-test-wallet` (cancel-in-progress false): a run started while the other is running waits
in the queue. GitHub keeps one running and one queued run per group; if a third run is queued,
the older queued one is cancelled.

### Scheduled runs pause after 60 days

GitHub turns off scheduled workflows on a public repository after 60 days without repository
activity. When that happens, turn it back on from the Actions tab.

### Expected findings

These are logged as findings, not failures:

- The suite signs in before it puts the site on Ethereum Sepolia, so the SIWE message names chain
  1 (the site's default), which is also the wallet's chain for the site at that moment. Phase 25
  owns the SIWE chain line, and the suite doesn't assert it.
- Putting the site on Sepolia is a `wallet_switchEthereumChain` request from the site's page.
  MetaMask may first ask to add Sepolia to the networks the site may use; the suite confirms that
  prompt like any other.
- The site gives SIWE the URI `https://localhost:8080` (derived from `SERVICE_FQDN_OPENSOCIAL`),
  while the CI site is served over plain http. MetaMask may warn about the domain or the URI.
- Any other MetaMask warning text is printed in the job log and added to the test's annotations.

### Pinned actions

Every action is pinned to a full commit SHA. Each commit was at least 14 days old when it was
pinned, checked with `gh api repos/<owner>/<repo>/commits/<sha>`. `scripts/workflow-check.sh`
checks the age again and checks that each SHA is in this table.

| Action | Tag | Commit SHA | Commit date (UTC) |
|--------|-----|------------|-------------------|
| actions/checkout | v7.0.1 | 3d3c42e5aac5ba805825da76410c181273ba90b1 | 2026-07-17T18:45:11Z |
| docker/setup-buildx-action | v4.4.1 | f87e5991a6d7451dcb8d9637bfbc97413f497069 | 2026-09-16T07:47:45Z |
| docker/build-push-action | v7.4.0 | c3c9e263c25d99ce0380d002d59b67737d91b0dc | 2026-09-15T07:29:04Z |
| actions/setup-node | v7.1.0 | 949feb2413d6458794dcd2491c4babbbce0c15c1 | 2026-10-08T02:43:26Z |
