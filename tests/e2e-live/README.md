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
browser profile holds the imported wallet. Only `wallet-setup/live/live.setup.ts` reads the seed
for this suite, and only inside its setup function (the governance judge's own wallet setup is
`wallet-setup/live/governance.setup.ts`, below).

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

**Rule for `SEPOLIA_RPC_URL`:** never set it to a URL whose key isn't the whole secret value. GitHub
masks only the exact secret value in logs, so the key must not be a part that could be printed on
its own. If a provider gives a URL plus a separate key, store the whole URL with the key in it as
the one secret.

### What the public log may contain

The repository and its Actions logs are public. The suite's own output is an allowlist: fixed
fields, known keys and counts, never free text from MetaMask, the browser console or Drupal.
- **The failure dump** (`failure-dump.ts`) prints:
  - a route key (`group_treasury_create`, `group_treasury`, `group_treasury_payment`, `user_login`
    or `other`);
  - the receipt panel's state, flags, known-string keys and a count of other characters;
  - message, console and failed-request counts;
  - update-deployment answers as HTTP status and enum values;
  - block numbers as integers, or a category.
- **MetaMask lines** print the step, the button's test id and a count of warning lines. The prompt
  text stays in memory for the local recording.
- **The errors the suite wraps** print as `<category> (<error name>)`, without the original
  message, which can carry an RPC URL. The categories are `rpc_error`, `timeout`, `user_rejected`,
  `wallet_closed`, `playwright_timeout` and `other`. The wrapped errors are:
  - the page's wallet calls (`eth_accounts`, `eth_chainId`, the network switch, the funding send);
  - the non-close errors in `confirmPrompts`.
- **The Node-side RPC** (`rpc()`): its errors name the method, `rpc_error` and an HTTP status or
  JSON-RPC code at most. Its results are checked before use: a balance must be a hex quantity, a
  present receipt status `0x0` or `0x1` (a missing or null receipt or status means "still pending"
  and the wait continues), and a printed hash 32 bytes of hex. Any other value is
  `rpc_error (bad_result)` or `bad_hash`.
- **Not wrapped:** Playwright's own failures in the helpers and the steps (a locator timeout, an
  assertion) print as Playwright writes them, with the selector and on-page text. They are covered
  by the accepted items below.
- **The watchdog step** prints only the entry id, type, severity, timestamp and a 12-hex hash of the
  untranslated message template.

These may also appear, accepted by the orchestrator (2026-11-08, after Codex round 2):
- Playwright's own assertion messages, which quote on-page text the assertion expected or found
  (for example the receipt panel or the payment panel);
- the logged on-page balance, a public chain amount;
- addresses and transaction hashes, which are public chain data;
- the raw container log tail ("Site log tail" in the workflow).

Why this is accepted:
- the CI site is a throwaway, destroyed after each run, holding only test content and public chain
  data;
- the seed reaches only the wallet cache-build step, which runs no site and no test;
- GitHub masks exact secret values in every log.

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
- MetaMask warnings are not printed: the log shows only the prompt kind, the button test id and a count
  of warning lines.

### Pinned actions

Every action is pinned to a full commit SHA. Each commit was at least 14 days old when it was
pinned, checked with `gh api repos/<owner>/<repo>/commits/<sha>`. `scripts/workflow-check.sh`
checks the age again and checks that each SHA is in this table. The governance judge
(`governance-judge.yml`, below) uses the same four pins (rechecked 2026-11-09: every
commit at least 14 days old).

| Action | Tag | Commit SHA | Commit date (UTC) |
|--------|-----|------------|-------------------|
| actions/checkout | v7.0.1 | 3d3c42e5aac5ba805825da76410c181273ba90b1 | 2026-07-17T18:45:11Z |
| docker/setup-buildx-action | v4.4.1 | f87e5991a6d7451dcb8d9637bfbc97413f497069 | 2026-09-16T07:47:45Z |
| docker/build-push-action | v7.4.0 | c3c9e263c25d99ce0380d002d59b67737d91b0dc | 2026-09-15T07:29:04Z |
| actions/setup-node | v7.1.0 | 949feb2413d6458794dcd2491c4babbbce0c15c1 | 2026-10-08T02:43:26Z |

## The governance judge (Phase 25, 25-27)

`.github/workflows/governance-judge.yml` runs `governance-judge.e2e.ts` on demand only (no
schedule), from `main` only, in one job of at most 90 minutes. It proves Phase 25's criteria 4 to 7
on live Ethereum Sepolia with the CI test-operator wallet (D-02: never a personal wallet or Safe):

- **Criterion 4, unaided voting:** the judge drives list, proposal, vote and carried out through
  one page object, `governance/pages.ts`, whose locators are only getByRole, getByLabel and
  getByText with the Phase 25 Copywriting Contract strings. `governance/labels.spec.ts` proves
  on the lane's fixture site that each one resolves exactly once in each state the judge passes
  through, that pages.ts holds no CSS locator, test id or query selector, and that its copy is in
  the contract strings (`governance/labels.fixture.ts`). An independent human organizer repeats the walk at the Phase 35 gate.
- **Criterion 5, zero ETH for members:** account 2 votes. Its balance is asserted exactly 0 before
  it votes and after the payment goes out. Every Phase 25 step on the public record is sent and
  paid by the CI relayer; account 1 pays only the Phase 21 fund deploy and the 0.0002 ETH funding.
- **Criterion 6, a passed payment carried out:** 0.0001 ETH back to account 1, shown as
  "Carried out" only after the server proves the fund's carry-out event; the run also checks the
  receipt's ExecutionFromModuleSuccess names the group's module.
- **Criterion 7, no host power:** it records the fund's owners, the top hat's wearer, the voting
  page name's owner, the carry-out step's owner and the space's admins and moderators, and fails if
  any is the CI relayer, if the fund doesn't wear the top hat or own the name and the step, or if
  the space has admins.

**No test modules.** The judge runs the real Reality Arbitrator code. `seed-governance-live.php` never
enables `safe_governance_arbitrator_fixture` (the stand-in the a11y and e2e seeds turn on) and refuses to run
(exit 1, a message on stderr) while it is enabled. CI starts fresh volumes, so it is never on there.

**The flow.** Accounts: 1 is ci-organizer, 2 is ci-member, 3 is ci-approver
(`seed-governance-live.php`; the group is invitation-only, so D-33's box starts checked).
1. Account 1 signs in and sets up the group fund, 2 of 2 with accounts 1 and 3, then funds it.
2. "Set up group voting": 2 votes, 1 day, "Any member who can vote", a 1-hour safety check, the
   new-members box left checked, and the admin-only overrides 10 minutes, 600 s and 300 s, which
   make it a test group (D-16, D-21, D-32).
3. Accounts 1 and 3 approve the setup, each reading "Confirmation 1 of 3" to "3 of 3" (D-35); the
   run waits for "Group voting is ready."
4. Account 2 connects its wallet on siwe_login's Wallet tab (signed in through a one-time login
   link the seed prints). Its "Let them vote" row is queued already selected ("Selected because
   new members are added automatically."). A member whose wallet was linked before voting was
   ready gets no row, so the seed links none for ci-member.
5. Accounts 1 and 3 approve the change; the run waits for "3 members can vote."
6. Account 3 proposes paying 0.0001 ETH to account 1 through "Confirm in your wallet".
7. Accounts 2 and 1 vote For; the run waits up to 45 minutes for "Carried out", whose "Pay to"
   reads "Nightly Organizer" (D-29).

**Timing.** About 40 minutes: setup about 5, member approval about 2, voting 10, the final count
about 1, reporting the result about 2, the safety check 10, the waiting time 5, the send about 1.

**Funding per run.** About 0.05 Sepolia ETH on account 1 (the fund deploy, its funding and the
Phase 21 nightly) and about 0.05 Sepolia ETH on the CI relayer (setup, member batch, reporting the
result with its small bond, the payment). Account 2 must stay at exactly 0: never send it anything.

**Input.** `relayer_sha` (a full 40-character lowercase SHA; anything else is refused): the relayer
is built from that commit of `LocalNodes/gas-sponsor-relayer` (`compose.governance.yaml`,
`pull_policy: build`), never pulled. The repository is fixed in the workflow, never an input,
because the job holds the wallet key.

**Secrets and variables (names only).** Besides the nightly's `SYNPRESS_SEED_PHRASE`,
`SYNPRESS_WALLET_PASSWORD`, `SEPOLIA_RPC_URL` and `SYNPRESS_WALLET_ADDRESS`:
- secret `SPONSOR_RELAYER_PRIVATE_KEY_CI`: the CI relayer's key, in the compose-up step's env only.

The seed reaches the cache-build step only (25-27 Q1 (b), as the nightly; only
`wallet-setup/live/governance.setup.ts` reads it, inside functions, and the judge never calls them). The relayer's bearer token is
made by the compose-up step for that run and masked. The member's one-time login link is masked
and never printed.

**Output.** The judge prints only `HASH <step> <hash>`, `RECORD <fact> <address>` and
`CAPTURE <name> <value>` lines besides Playwright's own; the last step copies those lines and the
relayer's public GET /status fields to the job summary. No artifact is uploaded. 25-28 copies them
into the phase's judge evidence file with explorer links.

**Captures for the 25-27 addendum** (CAPTURE lines; then update the pins in safe_governance's
VoteForwarder and ProposalPublisher to match):
- `sequencer_no_power`, `sequencer_not_in_window`, `sequencer_validation`: the testnet
  sequencer's exact refusal words, from envelopes a throwaway key made for the run signs and posts
  straight to the sequencer (it holds nothing and wears no hat);
- `sequencer_age_<seconds>`: the same key's votes stamped 120 to 1200 s in the past; where the
  answer changes is the oldest message age the sequencer accepts (ProposalPublisher assumes 900 s);
- `vote_domain_chainid_<n>`, `propose_domain_chainid`, `vote_wallet_prompts_<n>`,
  `propose_wallet_prompts`: whether the signed domain needed the chainId retry with MetaMask;
- `propose_prompt_before_click`: no wallet prompt opens before "Confirm in your wallet" is clicked;
- `vote_public_record_url`, `voting_page_url`: the Snapshot app links, to open in a real browser;
- `execution_from_module_success_topic1`: the carry-out event's module word.
WaaP can't be driven here (Synpress drives MetaMask only): its chainId retry and the "Confirm in
your wallet" route with WaaP stay on the manual walkthrough.

**Run it locally?** Never. Locally, check it with:
- `npm --prefix tests/e2e-live run judge -- --list` (it lists the judge);
- `env -u GITHUB_ACTIONS npm --prefix tests/e2e-live run judge` (refused before a browser starts);
- the labels check: `LN_BROWSER_CHANNEL=chromium LN_BASE_URL=<lane site> npx --no-install
  playwright test -c tests/e2e-live/governance/labels.config.ts`.
