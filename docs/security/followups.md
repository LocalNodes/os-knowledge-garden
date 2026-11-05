# Phase 19 follow-ups (orchestrator-ruled, 2026-11-05)

Three small fixes ruled in 19-WAITING-FOR-TOM.md ("Smaller calls"), landed on the
siwe_login clone's `1.0.x` for 1.0.0-beta5. Nothing pushed, nothing tagged.

## Commits (repos/siwe_login, branch 1.0.x, on top of 9a53d51)

| Commit | What |
| --- | --- |
| 73f0490 | TM-55: recheck the email under a lock at first-time confirmation |
| 946a6b3 | The link-to-existing-account email gets its own text (`account_link`) |
| 261dc72 | CHANGELOG beta5: `drupal:field`, `/siwe/nonce` and `/siwe/verify` changes, the fixes |

## 1. TM-55 email-in-use race

- **Where:** `EthereumUserManager::findOrCreateUserWithEmail()`, the only writer of a
  user-chosen email (called by `EmailVerificationController::confirm()` for a new
  account). Inside the existing address lock it takes
  `siwe_login_email:` + sha256(lowercased, trimmed email), rechecks that no other
  account holds the email (entity query, case-insensitive), then saves. It covers
  both the create branch and the "address holder gets a new email" branch.
- **Refusal:** a taken email or an unavailable lock throws `EmailInUseException`;
  nothing is saved. The confirmation page shows "This email address is already in
  use. Please enter a different email address." and goes back to the email form.
  Naming the clash here leaks nothing: the visitor just proved the inbox.
- **Limit:** Drupal's own user forms don't take this lock, so a core form save can
  still interleave between our recheck and our save. The window is now only the
  recheck-to-save step under the lock.
- **Tests:** RED first (7 of 8 failed before the fix). `EmailClaimLockTest` (8 kernel
  tests): claim lands before the lock is granted -> rejected, one holder; same in
  another case; address holder can't take a claimed email; holder keeps its own
  email; lock order (address, email, release email, release address); normalised
  lock name; lock refused -> rejected, nothing saved, unacquired lock not released.
  `EmailClashLinkTest::testEmailClaimedElsewhereBeforeConfirmation` (functional)
  covers the confirmation page end to end.

## 2. Link-email wording

- New mail key `account_link` with `siwe_login.mail:account_link.subject/body`
  (config/install and schema). `EmailVerificationForm::requestAccountLink()` sends
  it; the new-account path still sends `email_verification`.
- Text: "Link a wallet to your [site:name] account". It says a wallet asked to be
  linked to the existing account, shows the wallet address (`[siwe:wallet-address]`),
  and says nothing changes unless the link is opened.
- `siwe_login_mail()` keeps code defaults for both keys, so sites whose
  `siwe_login.mail` predates the key (the dev DB, any beta4 upgrade; there is no
  update hook by design) still get the link text.
- Tests: `VerificationMailTest::testAccountLinkMailHasItsOwnText` (RED first: empty
  subject). The registration test now also asserts its "complete your registration"
  text, so the two are proven distinct. `EmailClashLinkTest` asserts the existing
  account gets `account_link` and no `email_verification`.

## 3. CHANGELOG

Beta5 entry now lists `drupal:field`, the exact `/siwe/nonce` change (required
checksummed `address`, 400 body, session- and address-bound single-use nonce, new
response fields, `no-store`) and `/siwe/verify` change (JSON only or 415, `message` +
`signature` required, other keys ignored, fixed 400 body, 403/401 contract-wallet
notices, unchanged 401 `Authentication failed`, `ens_warning`), plus a Fixed section
for TM-55, the link mail, the mail tokens (c1b1dfa) and the refusal display.

## Verification

- Full suite in DDEV (`ddev exec … phpunit … repos/siwe_login/tests`): 241 tests, 1345 assertions, 1 skipped, exit 0 (baseline 231 + 10 new: 8 EmailClaimLockTest, 1 VerificationMailTest, 1 EmailClashLinkTest).
- phpcs (Drupal, DrupalPractice) clean on every new or changed line. Pre-existing
  hits remain on untouched lines of `EthereumUserManager.php` (3 comment full stops,
  2 long lines) and `EmailVerificationForm.php` (one inline doc block).

### Live check on https://fresh3.ddev.site (2026-11-05)

- `require_email_verification` was `false`; set to `true` at 07:19:58Z, restored to
  `false` at 07:20:48Z (checked after). `require_ens_or_username` stayed `true`.
- With curl and one cookie jar: `GET /siwe/nonce?address=` for a throwaway key's
  address, signed in the web container (same code as the test trait), `POST
  /siwe/verify` -> 200 redirect to `/siwe/email-verification`. Submitted the form
  twice: a fresh `followup-new-…@example.com` and `admin@example.com` (uid 1, no
  wallet). Neither link was opened, so no account was created or linked.
- Mailpit: `admin@example.com` got "Link a wallet to your Drush Site-Install account"
  with the link wording and the wallet address; the fresh address got "Verify your
  email address for Drush Site-Install" with the registration text. No raw `[site:`,
  `[user:` or `[siwe:` tokens in either. The dev DB has no `account_link` config, so
  this run exercised the code-default text; the config text is covered by the kernel
  test.
- Screenshots: `browser-verify/followup-account-link-mail.png`,
  `browser-verify/followup-verification-mail.png` (agent-browser on Mailpit).

## Left for later

- [THREAT-MODEL.md](THREAT-MODEL.md) TM-55 still says "pending follow-up task". 19-12 replaces pending
  markers; point it at 73f0490 and `EmailClaimLockTest`.

---

# WaaP connector follow-ups: finding (c) and TM-56 / finding (f) (2026-11-05)

Fixed on the clone's LOCAL-ONLY branch `waap-connector` (never pushed). The branch
was not rebased onto 1.0.x: it still sits on 9a53d51, and every test passes there.
`siwe_login_waap` is left ENABLED on the dev site and the clone is left on
`waap-connector`, for the real-login check in Tom's Brave.

Commit: `6bcd841` fix(waap): show failed sign-ins; end the WaaP session after Drupal logout (TM-56).

## (c) A failed WaaP sign-in showed the member nothing

**What the code did.** I reproduced both failure shapes in agent-browser (headless
Chromium) on `/user/login`, before the fix:

- WaaP host blocked (`network route **staging.waap.xyz** --abort`): `waap.login()`
  rejects with "WaaP wallet ready handshake timed out after 10s."
- WaaP iframe navigation hung (iframe `src` pointed at an unroutable address, so its
  `load` never fires; this is Tom's Brave error): `waap.login()` rejects after 30 s
  with "WaaP iframe navigation timed out after 30s."

In both cases the old catch handler did call `showError()`, and the Drupal message
**was rendered and visible** at the top of the content region (socialblue has a
`[data-drupal-messages-fallback]` there), showing the raw SDK text
(`browser-verify/waap-c-handshake-blocked-before-fix.png`,
`browser-verify/waap-c-nav-timeout-before-fix.png`). Neither guess from the UAT was
right. `login()` does not resolve empty on a timeout: the SDK rejects. And the
message area does exist on the socialblue login page.

So on this code, in Chromium, a timeout is not silent. It is silent for **30
seconds**: the button gave no sign that anything was happening and stayed
clickable, and the message, when it came, was SDK jargon in the content area,
while the Sign in button sits in the header. What Tom saw in Brave with Shields up
can't be checked here. The Brave check below tells the possible causes apart: the
message was never rendered, Brave's cosmetic filter hid it, or it was there and
was missed.

Two more problems turned up during the check:

- **Closing the WaaP box rejects; it doesn't resolve empty.** It rejected with
  `{code: 4001, message: "The user rejected the request.", waapCode: "user_rejected"}`,
  so the old code showed "The user rejected the request." as an error when the
  member had simply closed the box.
- **No message area means the message goes below the footer.** On a page without
  `[data-drupal-messages]` or the fallback, `Drupal.Message` appends its wrapper to
  the end of `<body>`.

**Fix (`js/siwe-login-waap.js`).**

- A cancel stays quiet: EIP-1193 code 4001, or the SDK's `normalizeWaaPError()`
  categories `cancelled` and `user_rejected`. This covers closing the box and
  rejecting the signature.
- A timeout (SDK category `timeout`, or a message with "timed out") shows "Sign-in
  with WaaP didn't complete because the WaaP window didn't load. A browser privacy
  shield or ad blocker may be blocking it: allow it for this site and try again, or
  use another sign-in option."
- Any other SDK or network failure shows "Sign-in with WaaP didn't complete. Please
  try again, or use another sign-in option."
- Some messages are still shown as they are: the connector's own notices and the
  server's `/siwe/nonce` and `/siwe/verify` refusal text (marked `siweUserFacing`,
  never treated as a cancel), and the WalletConnect stub's "not available" text,
  as 8b450b1 promised. The raw SDK error goes only to `console.warn`.
- Every message goes through `Drupal.checkPlain` and `Drupal.Message`. When the page
  has no message area, one is created at the top of `<main>`. The message is
  scrolled into view.
- The button is disabled, with `aria-busy`, while a sign-in runs, so that a slow
  WaaP window doesn't start a second sign-in. It comes back when the sign-in fails
  or is cancelled.
- `ConnectorLibraryTest` (kernel) pins `core/drupal.message` and the other
  dependencies of the `connector` library, and checks that the library holds only
  the module script.

**Browser check after the fix (agent-browser, https://fresh3.ddev.site/user/login).**

| Case | Result | Evidence |
| --- | --- | --- |
| WaaP host blocked (10 s handshake) | Shield message shown, button re-enabled | `browser-verify/waap-c-handshake-blocked-after-fix.png` |
| Iframe navigation hung (30 s, Tom's error), button clicked twice | Shield message shown once; the second click hit a disabled button | `browser-verify/waap-c-nav-timeout-after-fix.png` |
| Page with no message area (fallback removed by hand) | Area created as the first child of `main`, message visible at y=50 | `browser-verify/waap-c-no-message-area-after-fix.png` |
| Real WaaP box opened, then closed with its X | No message, button re-enabled (console: code 4001 `user_rejected`) | `browser-verify/waap-c-user-closed-quiet.png` |

There is no automated layer for the JS: the dev site has no chromedriver (only Solr
and Qdrant compose files) and no JS unit runner. The cases above are the check.

## (f) / TM-56: Drupal logout left the WaaP session alive

**How it works.**

1. `siwe_login_waap_user_logout()` sets the cookie `siwe_waap_logout=1`. It is not
   HttpOnly, has `SameSite=Lax`, Path = base path, `Secure` on https, and lasts 30
   days. The cookie holds no identity. The name, lifetime and path live in
   `src/WaapLogoutCookie.php`. The cookie is set with `setcookie()` on the logout
   response, and nothing is set under the CLI.
2. The anonymous `/user/login` page comes from the page cache, which ignores
   cookies, so PHP can't change the page for this flag. The connector, already an
   ES module that imports `initWaaP`, reads the cookie in the browser. Nothing new
   is loaded: no new library or import-map entry, no SRI change, and `ImportMapTest`
   is untouched. `drupalSettings.siweLoginWaap.logoutCookiePath` gives the path to
   clear the cookie with.
3. The connector ends the WaaP session at one of two points:
   - **When the page loads,** if the cookie is set and the SDK's
     `${origin}-connected` marker shows this browser signed in with WaaP here. The
     connector calls `initWaaP(...).logout()` at once. A member who only uses a
     password doesn't get a WaaP iframe on the login page.
   - **When Sign in is clicked,** if the cookie is still there. `signIn()` awaits
     the same logout **before** `login()`. If the logout fails, the sign-in stops
     with the error message, so `login()` can't resume the old session.
4. When `logout()` succeeds, the cookie is cleared. The SDK's own `logout()` removes
   the marker. When `logout()` fails (for example, WaaP is blocked), the cookie
   stays and the next try ends the session. Nothing is shown on page load, because
   the member hasn't asked for anything.

`LogoutCookieTest` (functional) covers this:

- After a confirm-form logout, the cookie jar holds `siwe_waap_logout=1`: not
  HttpOnly, path `base_path()`, SameSite Lax, expiring in about 30 days. A visit
  doesn't set it, and neither does a login.
- After logout, the login page is still a page-cache HIT, carries the connector
  module script and `logoutCookiePath`, and the script it attaches handles the
  flag.
- Mutation check: with the hook renamed, both tests fail ("Logout set the
  siwe_waap_logout cookie": null).

**Browser check (agent-browser).** A real WaaP login can't be done here (findings
(a) and (e)). The browser was logged in as uid 1 with `drush uli`, then logged out
through `/user/logout/confirm`.

| Step | Result | Evidence |
| --- | --- | --- |
| After the logout redirect to `/` | `document.cookie` = `siwe_waap_logout=1` | eval |
| `/user/login` with the WaaP marker set | On load: cookie set, marker `waap`, WaaP iframe mounted. 8 s later: cookie gone, marker `null` (removed by the SDK's `logout()`), no error message | `browser-verify/waap-f-login-after-logout-cleared.png` |
| Second logout, `/user/login` with no marker | No iframe on load, cookie kept. On the Sign in click the cookie is cleared, then WaaP's "Sign On" chooser opens | `browser-verify/waap-f-click-after-logout-chooser.png` |
| WaaP blocked, cookie and marker set | Cookie kept, no message on load, console "WaaP session not ended yet" | eval |

## Verification

- Parent suite (`ddev exec … phpunit … repos/siwe_login/tests`): 231 tests, 1263
  assertions, 1 skipped, exit 0. That is the expected count on this branch's base
  9a53d51; the 241 baseline is on 1.0.x, which has 10 more tests.
- WaaP module (`… repos/siwe_login/modules`): 33 tests, 332 assertions, exit 0. That
  is 30 before, plus 2 in `LogoutCookieTest` and 1 in `ConnectorLibraryTest`.
- phpcs (Drupal, DrupalPractice): the changed and new files are clean
  (`siwe_login_waap.module`, `WaapLogoutCookie.php`, both new tests). Hits remain
  only in files this change doesn't touch: `ImportMapTest.php` (3 method names, 1
  array) and `SiweLoginBlockPreRender.php:52` (doc comment capital).
- `node --check js/siwe-login-waap.js` passes.

## Brave check for the orchestrator (real WaaP login)

TM-56, Shields down:

1. On https://fresh3.ddev.site/user/login, click Sign in and finish a WaaP Google or
   email login.
2. Log out of Drupal (`/user/logout`, then confirm). In the console on `/`, check
   that `document.cookie` includes `siwe_waap_logout=1`.
3. Open `/user/login`, wait about 5 s, then run `document.cookie` and
   `localStorage.getItem(location.origin + '-connected')`. Expect no
   `siwe_waap_logout` and `null`.
4. Click Sign in. Expect WaaP's **Sign On chooser** (email, Google, wallets), not
   "Message signing request" as the previous identity. Close it with the X and
   check that no Drupal message appears.

(c), Shields up:

5. On `/user/login`, click Sign in. The button greys out. Wait up to 30 s. Expect a
   red Drupal message under "Log in": "Sign-in with WaaP didn't complete because
   the WaaP window didn't load. A browser privacy shield or ad blocker may be
   blocking it…".
6. If no message is visible, run in the console:
   `[...document.querySelectorAll('[data-drupal-message-type]')].map(e => [e.textContent.trim(), getComputedStyle(e).display, getComputedStyle(e.parentElement).display])`
   - An empty list means the message was never rendered (new information).
   - An entry showing `none` means Brave's cosmetic filter hid the alert.
   - A visible entry means the message was there but was missed.

## siwe_login beta7: TM-24 flood control

Date: 2026-11-05.

TM-24 said the public `/siwe/nonce` and `/siwe/verify` routes had no flood control.
beta7 adds it with core's `flood` service, following core's user login flood control
(`user.flood`). The work is on a local branch only: `repos/siwe_login`, branch `beta7`,
cut from 1.0.x at ce007ff. Nothing is pushed or tagged. THREAT-MODEL.md is unchanged;
TM-24 moves there after the release.

### Commits (repos/siwe_login, branch beta7)

- ecbdfd7 test: flood control on /siwe/nonce and /siwe/verify (TM-24, failing)
- 2bfb112 feat: flood control on /siwe/nonce and /siwe/verify (TM-24)
- df91350 test: flood settings on the SIWE settings form (failing)
- dfcc695 feat: flood control settings on the SIWE settings form
- 1468e4c docs: beta7 changelog, README flood control and threat model link
- 1c95091 style: phpcs line length in the flood settings and test base

### What it does

- New service `siwe_login.flood_control` (`SiweFloodControl`) on `@flood`.
- `GET /siwe/nonce`: every request from an IP counts, malformed ones included.
  Default: 30 per IP per 300 s.
- `POST /siwe/verify`: only failures count (400, 401 and 403), as in core. Defaults are
  core's `user.flood` values: 50 failures per IP per hour, and 5 per address per 6 hours.
  The per-address identifier is the lowercased address plus the IP, like core's default
  `uid_only: false`. With the address alone, anyone could lock a wallet out by
  posting 5 bad messages for it. A verified signature clears that address's count,
  including on the email and username hand-offs. It never clears the IP count.
- Both checks run before the nonce store is touched, so a blocked request doesn't
  spend the session's nonce. A 415 (wrong Content-Type) is not counted.
- Past a limit: HTTP 429, body `{"error": "Too many sign-in attempts. Please wait and try again."}`
  (same `{"error": …}` shape as the other errors). `Retry-After` is set to the window,
  because the flood API can't say when the oldest event expires. The nonce 429 also
  sends `Cache-Control: no-store`. Each block logs a notice on the `siwe_login` channel
  with the route name only.
- Config: `siwe_login.settings:flood.{nonce_ip_limit, nonce_ip_window, verify_ip_limit,
  verify_ip_window, verify_address_limit, verify_address_window}`, with schema and
  config/install defaults. A missing or non-positive value falls back to
  `SiweFloodControl::DEFAULTS` in code, so beta6 sites need no update hook. The
  fresh3 site has no `flood` key, so the live check below ran on that fallback.
- The settings form has a collapsed **Flood control** section with the six fields
  (number, min 1). It shows the defaults when the keys are missing.
- README: threat model link is now `docs/security/THREAT-MODEL.md`. TM-24 moved from
  the accepted risks to its own mitigated bullet, and there's a new Flood Control
  config section. CHANGELOG has `## [1.0.0-beta7] - Unreleased` below `## [Unreleased]`.

### Verification

- New Kernel tests: `FloodControlTest` (13) and `FloodSettingsFormTest` (3). They cover
  the nonce 429 at request limit+1 with another IP unaffected, malformed nonce
  requests counting, the default fallback for missing and for zero or negative values,
  the install defaults, per-IP verify failures giving 429 without spending the nonce,
  per-address failures giving 429 for that address and IP only, case-insensitive
  address keys, success and hand-off clearing the address count, success not clearing
  the IP count, unchanged behaviour under the limits, and 415 not being counted. A
  mutation check (removing the clear call) fails both clear tests.
- Full suite (`ddev exec … phpunit … repos/siwe_login/tests`): 257 tests, 1554
  assertions, 1 skipped, exit 0. That is the 241 baseline plus the 16 new tests. The
  run started before 1c95091 (formatting only), so `FloodControlTest`,
  `FloodSettingsFormTest` and `EndpointContractTest` were rerun on the final tree:
  27 tests, 283 assertions, OK.
- phpstan (level 0, drupal.org CI setup, on a copy under `repos/beta7check`, since
  deleted): `[OK] No errors`.
- phpcs (Drupal, DrupalPractice) on the changed files: clean apart from two hits that
  were already on 1.0.x: `SiweSettingsForm.php` (blank line after the comment in
  `validateForm()`) and `SiweKernelTestBase.php:195` (the `resetSiweServices()`
  array line).

### Live check on https://fresh3.ddev.site (curl, 2026-11-05)

- `drush cr` first, since the module is symlinked and the new service has to be in the
  container.
- Throwaway address `0x343bE945F4BFd32F95aFa526de7614b91B51A462`. 32 GET
  `/siwe/nonce?address=…` at 11:06:36Z: requests 1 to 30 got 200 with a nonce body,
  31 and 32 got 429. Headers on the 429:
  `HTTP/2 429`, `cache-control: no-store, private`, `content-type: application/json`,
  `retry-after: 300`. Body: `{"error":"Too many sign-in attempts. Please wait and try again."}`.
- Flood table: `siwe_login.nonce_ip | 192.168.97.7 | 30`. That IP is the DDEV router,
  because fresh3 runs without `DRUPAL_REVERSE_PROXY`. So every client of the local site
  shares one counter, and the block hit everyone for about 30 s.
- Recovery: a fresh IP isn't possible from the host, so I cleared the entry with
  `drush php:eval '\Drupal::flood()->clear("siwe_login.nonce_ip", "192.168.97.7");'`
  at 11:07:06Z. After that, `/siwe/nonce` gave 200 with a nonce, and `/user/login` gave
  200 with the SIWE login block. The one entry from that nonce request was cleared
  too. The final count of `siwe_login.%` rows in `flood` is 0.
- Not cleaned up: the nonce requests left anonymous tempstore records and sessions.
  They expire on their own (nonce TTL and session GC).

### For the orchestrator

- **Production client IP (needs a decision, not in this branch).** In
  `html/sites/default/settings.php`, `DRUPAL_REVERSE_PROXY=true` sets
  `reverse_proxy_addresses = ['0.0.0.0/0']` and trusts `X-Forwarded-For`. Every peer
  counts as a trusted proxy, so a client can send its own `X-Forwarded-For` and get a
  fresh flood identifier on each request. The per-IP limits in beta7 (and core's own
  login flood) are then easy to bypass on Coolify instances. The fix is to list only
  Traefik's real network or address in `reverse_proxy_addresses`. Without
  `DRUPAL_REVERSE_PROXY` (local DDEV), the problem is the other way round: one shared
  counter for all clients behind the router.
- TM-24 can move to mitigated in THREAT-MODEL.md once beta7 is released.

### Post-deploy client-IP check (2026-11-05, run by the orchestrator)

Boulder (deployed 11:49Z) and Cascadia (11:53Z) both run image `0282227`, which includes fresh3 PR #40 (trusted proxies) and PR #41 (siwe_login 1.0.0-beta7). On both sites:
- Requests from a real client over IPv4 and IPv6 were logged by watchdog with the client's real public IP, not a Cloudflare edge.
- The state value `localnodes_platform.cf_connecting_ip_fallback` is unset. So Traefik's `forwardedHeaders.trustedIPs` is in effect and the CF-Connecting-IP fallback is idle.

TM-24 flood control therefore keys on real client IPs in production.
