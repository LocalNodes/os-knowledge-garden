# The a11y browser suite: how to run it, and why it makes no AI calls

Playwright specs for every Phase 22 screen state (S1 to S8): axe, keyboard, reflow, screen-reader proxy, page titles and the recorded import run. Results go to `.planning/phases/22-document-importer-cited-group-answers/a11y/`; its `README.md` indexes the axe files.

## Where it runs

The suite runs against a throwaway DDEV site, never a real one. On desktop-dev that's the project `fresh3-a11y` (`http://fresh3-a11y.ddev.site:33000`), a fresh install with no demo content:

```
ddev drush si social --account-pass=admin -y
ddev drush en localnodes_platform -y
ddev drush cr
```

`scripts/install.sh` isn't used, because it asks for a Gemini key. **The lane installs with no AI key:** `ddev exec printenv GEMINI_API_KEY` is empty, and the Gemini key entity is empty too.

Run it from the repo root of that site's checkout:

```
LN_BASE_URL=http://fresh3-a11y.ddev.site:33000 LN_BROWSER_CHANNEL=chromium \
  npx --no-install playwright test -c tests/a11y/playwright.config.ts
```

- `LN_RECORD=1` records a trace and a video of every test.
- `global-setup.ts` seeds the personas and groups (`seed.php`; the specs run `seed-status.php`, `seed-source-box.php`, `seed-refresh.php` and `seed-walkthrough.php` themselves) and saves the personas' sessions under `.auth/`, which is gitignored.

## No AI calls, in the browser or on the server

There are two guards, because AI can be called from two places.

**In the browser:** `helpers/ai-guard.ts` (22-28).
- The two AI endpoints a page calls, `POST /api/deepchat` and `GET /api/ai/overview`, are answered with canned bodies (`fixtures/canned-ai.ts`).
- Any request the route didn't answer fails the test.

**On the server:** the test-only module `localnodes_a11y_ai_guard` (22-30, Codex merge review R1 item 5).
- Drupal makes its own AI calls while it renders a page. A Topic's Related Topics block embeds a search query, so do background indexing (cron, `search-api:index`) and anything else that asks the AI module for a chat or an embedding.
- Every one of those calls goes through the AI module's `ProviderProxy`, which dispatches `PreGenerateResponseEvent` before it invokes the provider. If a listener has set a forced output by then, the proxy returns that output instead.
- The module listens twice:
  1. **First (priority 1000), it counts the call and denies it.** It answers with a canned output: a short fixed chat reply, or a 3072-number embedding, so the provider is never invoked and no request leaves the site. Any other kind of operation is stopped with an exception.
  2. **Last (priority -1000), it checks whether the call still has no forced output.** Such a call would reach its provider. It's counted as "reached", logged with its provider, operation and path, and stopped. So even with the deny off, nothing is sent.
- The counts (attempted, denied, reached) are kept in state under `localnodes_a11y_ai_guard.counts`, so the suite can read them from Drupal after a page loads.

**How the suite enforces it:**
- `global-setup.ts` refuses to run unless the site's settings switch the deny on. It then enables the module and resets the counts.
- `server-ai.spec.ts` clears the query-embedding cache, renders a Topic as a member, then runs cron and indexes 5 items. It requires at least one call to have been attempted (so the path really runs), every call denied, and 0 reached.
- `global-teardown.ts` fails the whole run if any call reached a provider at any point.
- `22-30-SERVER-AI-PROOF.txt` records the RED, the GREEN and the proof: with the deny switched off, the spec and the teardown both fail.

**To set a site up for it**, put these lines in its `html/sites/default/settings.local.php`. That file is gitignored, and the tracked `settings.php` includes it last. `tests/e2e/site/site.sh code` writes it for the lane test sites. Never put the lines in the tracked `settings.php`. The module lives in `html/modules/custom/localnodes_platform/tests/modules/`, which Drupal only scans when told to, so a real site never finds it:

```php
$settings['extension_discovery_scan_tests'] = TRUE;
$settings['localnodes_a11y_ai_deny'] = TRUE;
```

On a real site there's no deny and no counter. Never enable the module there.

## The governance specs' test modules

`governance.spec.ts` and its keyboard and screen-reader runs read a site that runs these test-only modules, all hidden and under a module's `tests/modules/`, so Drupal finds them only where `extension_discovery_scan_tests` is on (the lane's gitignored `settings.local.php`; DDEV sets it too):

- `safe_smart_accounts_rpc_fixture`: every Safe read answers from canned data.
- `safe_governance_chain_fixture`: the governance reads answer from the synthetic facts `seed-governance.php` writes.
- `safe_governance_hub_fixture`: the voting service.
- `gas_sponsor_fixture`: the community's network fee account.
- `safe_governance_arbitrator_fixture` (25-29): a stand-in for the Reality Arbitrator code, so setup "prepare" never downloads the real GPL-3.0 bytecode from GitHub. **`seed-governance.php` enables this one itself** (and fails if it isn't enabled when it finishes), so a run never makes that GET. `arbitrator-fixture.test.ts` (part of `npm test`) guards the wiring.

The live judge is the opposite case: `tests/e2e-live/seed-governance-live.php` never enables the Arbitrator fixture and refuses to run (exit 1) while it is on, because a live run must use the real code. A site that ran the a11y suite is therefore not a site for the judge: use a fresh one, or `drush pm:uninstall safe_governance_arbitrator_fixture`.
