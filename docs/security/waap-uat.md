# 19-10 WaaP UAT on the Drupal page (WAAP-01, D-09)

Run 2026-11-04 by the 19-10 executor on the DDEV site `https://fresh3.ddev.site`, `/user/login`, with the siwe_login clone on its local branch `waap-connector` at `8b450b1` (siwe_login_waap enabled, `WAAP_ENVIRONMENT=staging`). This is the rendered Drupal page, not the standalone spike in `spike/`.

## Summary

The Drupal login page loads the WaaP module graph under integrity. During the run, the page requested six modules from jsDelivr, and all six are in `siwe_login_waap.settings sdk_integrity`. Changing the hash of one relative chunk (`evm-dDrPUbNZ.mjs`, not the entry) made Chrome block that chunk. The connector module then never ran: no "Sign in" button appeared and no WaaP iframe was created. With the saved value restored, the button and the `staging.waap.xyz` iframe came back.

Tom signed in through WaaP staging with Google and with the email magic link. Both passed, and so did the username step. Both signed with the same WaaP EOA, and the account is keyed by that address. Six findings are recorded at the end.

## Browser

- **Executor runs (steps a and b):** HeadlessChrome 154.0.0.0 (`Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/154.0.0.0 Safari/537.36`), agent-browser 0.38.1, session `waap-uat-probe`.
- **Tom's run:** Tom's own Brave (UA `Chrome/155.0.0.0`), Shields down, wallet extensions off. A headed agent-browser session (`waap-uat`, Chrome 154.0.0.0) was prepared first, with a page init script (`browser-verify/waap-uat-capture-init.js.txt`) that stores the page's own `POST /siwe/verify` body and status in `localStorage`. It wasn't used, because Google refuses sign-in in automation-controlled Chrome (finding (a)). The capture came from Brave instead.
- **How the evidence was captured:** Chrome reports SRI failures as browser log entries, which `agent-browser console` doesn't show. So a CDP monitor recorded the page (`Log.entryAdded`, `Runtime.consoleAPICalled`, `Network.*`, cache disabled) and navigated it, while clicks were made with agent-browser. Query strings are left off every URL below.

## Step a: module graph on the Drupal page

Page loaded, then "Sign in" clicked. The WaaP "Sign On" chooser opened inside `https://staging.waap.xyz/iframe` with an email field, Continue with Google, Browser Wallet, WalletConnect and "Sign in with a passkey" (`browser-verify/waap-uat-a-drupal-modal.png`). Every module was requested at page load, before the click. The click only added the iframe document and its assets.

Module URLs the page requested, compared with `ddev drush cget siwe_login_waap.settings sdk_integrity`:

| # | Requested module URL | In sdk_integrity |
|---|----------------------|------------------|
| 1 | `https://cdn.jsdelivr.net/npm/@human.tech/waap-sdk@2.4.2/dist/evm.mjs` (entry) | yes |
| 2 | `https://cdn.jsdelivr.net/npm/ethers@6.16.0/dist/ethers.min.js` | yes |
| 3 | `https://cdn.jsdelivr.net/npm/@human.tech/waap-sdk@2.4.2/dist/UIMessageManager-BSJdvCEF.mjs` | yes |
| 4 | `https://cdn.jsdelivr.net/npm/@human.tech/waap-sdk@2.4.2/dist/evm-dDrPUbNZ.mjs` | yes |
| 5 | `https://cdn.jsdelivr.net/npm/@human.tech/waap-sdk@2.4.2/dist/WalletConnect-DXiTxC-F.mjs` | yes |
| 6 | `https://cdn.jsdelivr.net/npm/eventemitter3@5.0.1/dist/eventemitter3.esm.min.js` | yes |

Result: 6 of 6 requested CDN modules are in sdk_integrity. None are missing.

Two same-origin scripts are not part of the CDN graph, so they aren't sdk_integrity entries:

- `https://fresh3.ddev.site/modules/contrib/siwe_login/modules/siwe_login_waap/js/siwe-login-waap.js` is the connector itself. It's a same-origin Drupal library file (`type="module"`, `preprocess: false`) that imports the pinned graph. It's served by the site, like every other Drupal JS file.
- `https://fresh3.ddev.site/modules/contrib/siwe_login/modules/siwe_login_waap/js/walletconnect-unavailable.js` (the WalletConnect stub) was **not requested** in this run, because it loads only when WalletConnect is clicked. It's pinned in the import map's `integrity` block via `local_modules` (`sha384-G4iNSrTP…`), not via `sdk_integrity`.

Hosts contacted by the page during the run: `fresh3.ddev.site`, `cdn.jsdelivr.net` and `staging.waap.xyz`. There were no failed loads, no browser log entries and no exceptions.

## Step b: Drupal-page SRI negative control

1. Saved `sdk_integrity` (`drush cget … --format=json`, sha256 `a73f72d4…c3ce8e`).
2. Changed one entry through `ddev drush php:eval` on the editable config, as a one-off on the dev site: the relative chunk `…/waap-sdk@2.4.2/dist/evm-dDrPUbNZ.mjs` got the unrelated hash `sha384-NL6ihMP7F1O2gPSt84Pn5z+yL4xKVEFMBejsvWVh5jF63fIhOT3tCcVskOUQzCTb` (the sha384 of a fixed text string). The entry module was left alone. Then ran `ddev drush cr`.
3. Reloaded `/user/login`. The rendered import map carried the bad hash.
4. **Browser log (verbatim, CDP):**
   ```
   [error/security] Failed to find a valid digest in the 'integrity' attribute for resource 'https://cdn.jsdelivr.net/npm/@human.tech/waap-sdk@2.4.2/dist/evm-dDrPUbNZ.mjs' with computed SHA-384 integrity 'ezp0qySbXuWF3n8YWfw9Xglj7QM834eit2vYYKll+MegI3ILC6A6xqOb4HWWquyB'. The resource has been blocked. @ https://fresh3.ddev.site/user/login
   ```
   The computed hash Chrome reports is the real one in the saved config.
5. **Result: blocked.** The mount element was present (1), but there were 0 "Sign in" buttons, so the click found nothing to press. `WalletConnect-DXiTxC-F.mjs` was never fetched, and **no waap.xyz iframe appeared** (0 iframes). The username/password form still worked (`browser-verify/waap-uat-b-badsri-no-button.png`).
6. **Restore:** wrote the saved list back through `php:eval` and ran `ddev drush cr`. `drush cget … --format=json` afterwards was byte-identical to the saved file (`cmp` exit 0, same sha256). The bad hash was gone from the rendered import map. On reload, "Sign in" was back, all six modules answered 200, and the click opened `https://staging.waap.xyz/iframe` again (`browser-verify/waap-uat-b-restored-modal.png`). The one integrity line in that run's log is the step-4 line replayed by `Log.enable` (same text, from the previous page). The chunk it names answered 200 and the iframe appeared, so nothing was blocked after the restore.

Drupal-page SRI: enforced (HeadlessChrome 154.0.0.0)

## Path outcomes (Tom, staging)

Tom ran the sign-in in his own **Brave** browser (UA `Chrome/155.0.0.0`) with Shields down and wallet extensions off, not in the agent-browser window. The reasons are findings (a) and (b) below. The capture was taken from Brave and saved to a scratch file, which held only the `/siwe/verify` body and response: status 200, `success: true`, `redirect: /siwe/create-username`, captured 2026-11-05T06:35:57Z.

Dev-site settings for the run: `siwe_login.settings require_email_verification` FALSE. `require_ens_or_username` was switched to TRUE on the dev DB by the orchestrator before the run (it was FALSE), so that the username step would run.

| Path | Outcome |
|------|---------|
| Google (Tom's Google account, WaaP staging) | **PASS.** WaaP's EOA `0x3cC8…F5e2` signed the standard SIWE message. `/siwe/verify` answered 200 with `success: true` and redirected to `/siwe/create-username`. The address already belonged to uid 12, created 2026-11-04 19:07 (site time) by Tom's first Google sign-in in his own browser, with the generated name `0x3cc8...f5e2`. Checked in the DB: uid 12's `field_ethereum_address` is `0x3cC8…F5e2`, the fixture address in lowercase. |
| Username step (require_ens_or_username on) | **PASS.** The existing account with a generated name was sent to the username form, and Tom chose a username. uid 12 is now `proofoftom`, last login 2026-11-04 23:37:12 (site time). The address on the account is unchanged. |
| email (real inbox, magic link) | **PASS** (run 2026-11-05 at Tom's request, so that WAAP-01 has its email evidence). Tom opened the WaaP magic link in the same Brave window. The same EOA `0x3cC8…F5e2` signed the SIWE message, and `/siwe/verify` answered 200 with `success: true`, captured 2026-11-05T07:02:15Z. Returning user uid 12 `proofoftom` was logged in directly; there was no username step, because the name is no longer a generated one. The response redirected to `/user/12/safe-accounts/create`, set by safe_smart_accounts' response alter. |
| external-wallet listing | **PASS (listing only).** The WaaP chooser on the Drupal page lists Browser Wallet and WalletConnect (step a, `browser-verify/waap-uat-a-drupal-modal.png`). Neither was completed in this UAT. 19-09 already completed Browser Wallet with a test wallet. WalletConnect is the option-2 stub and reports "not available". |

The captured message's statement is `Sign in to Drush Site-Install`, the dev site's name. It is kept verbatim in the fixture because that is the text WaaP signed.

The captured `{message, signature}` pairs are on clone branch `waap-connector`, each with the address, captured_at, environment `staging`, host `fresh3.ddev.site` and auth: `tests/fixtures/waap_eoa_siwe.json` (Google) and `tests/fixtures/waap_eoa_siwe_email.json` (email). `WaapSignatureFixtureTest` runs each of its cases for both fixtures, 6 tests in all, and they pass. No email address and no OTP are recorded anywhere.

## Findings (recorded, not fixed here)

- **(a) Google blocks automation-controlled Chrome.** In the agent-browser window, Google's sign-in refused with "Couldn't sign you in. This browser or app may not be secure." So a Google WaaP login can't be run in agent-browser, and the UAT moved to Tom's Brave.
- **(b) Brave Shields block the WaaP iframe.** With Shields up, the console showed "WaaP iframe navigation timed out after 30s", and the WaaP frame never appeared. With Shields down it worked. Members using Brave (or similar blockers) can't sign in with WaaP unless they allow it.
- **(c) A failed WaaP sign-in shows the member nothing.** When the WaaP step failed before `/siwe/verify` (as in (b)), Tom saw no message on the page, only a console warning. On a rejected promise the connector's catch handler calls `console.warn` and then `showError()`, which uses `Drupal.Message` (`siwe-login-waap.js:157-158`). So the silence must have one of two causes, neither of which has been checked yet: `waap.login()` resolved empty after the timeout, which the connector treats as a cancel and returns from without a message; or `Drupal.Message` has nowhere visible to render on the socialblue login page. Follow-up: make a failed or timed-out WaaP step visible to the member as a Drupal message.
- **(d) Google sign-in with a Google account that has no WaaP account** (a different Gmail) showed "Account not found. Please check your credentials or create a new account." in WaaP's box. Open question for human.tech: does a Google sign-in on staging create a WaaP account, or does the account have to exist first?
- **(e) The email magic link must open in the same browser that started the sign-in.** So agent-browser couldn't be used for the email path either. Tom ran it in his own Brave.
- **(f) Drupal logout does not end the WaaP session.** After `/user/logout`, clicking Sign in went straight to WaaP's "Message signing request" as the previous WaaP identity, without asking who is signing in. That's a risk on a shared computer, although the next person still has to click Sign. The orchestrator cleared the session with `window.waap.logout()`. Follow-up: have the connector call `waap.logout()` when the member logs out of Drupal.

## Re-check of the connector fixes in Tom's Brave (2026-11-05)

Driven through Claude in Chrome; Tom picked the Google account and clicked Sign. Clone on `waap-connector` (6bcd841 plus the wording change below).

- **(f) / TM-56, logout ends the WaaP session: PASS, twice.** First on the WaaP session left over from the email run, then after a fresh Google sign-in (uid 12 `proofoftom`). Each time: Drupal logout set `siwe_waap_logout=1`; `/user/login` cleared the cookie and the SDK's `-connected` marker within 8 s; Sign in then showed the Sign On chooser, not a signing request. Closing it with X showed no message and re-enabled the button.
- **(c), visible failure: accepted as fixed (orchestrator, 2026-11-05).** Brave with Shields UP and wallet extensions off: WaaP opened normally (button greyed while running, iframe mounted, no error over 30 s+). So Shields alone do not block WaaP; the earlier 30 s timeout is attributed to the wallet extensions that were on at the time (they also injected extension frames that blocked Claude in Chrome). Not re-tested with them on. The failure message path was verified in agent-browser by blocking the WaaP host ([followups.md](followups.md)).
- **Wording change:** the timeout message now reads "Sign-in didn't open. A privacy shield or a browser extension (such as a wallet) may be blocking it. Turn it off for this site and try again, or use another sign-in option." `ConnectorLibraryTest::testTimeoutMessageNamesShieldsAndExtensions` pins it.

### Deferred Tom-hands check (before `waap-connector` ever ships)

- In Brave with wallet extensions ON (MetaMask, Rabby, Phantom, …), click Sign in on `/user/login`: confirm whether WaaP fails, and if it does, that the new message appears within 30 s and the button comes back.
