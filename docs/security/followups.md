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

## siwe_login beta8: Phase 19 review fixes

Date: 2026-11-05.

This fixes the siwe_login findings from `.planning/phases/19-siwe-hardening-waap-login-threat-model/19-REVIEW.md` (WR-01 to WR-06 and IN-01 to IN-06). CR-01 was fixed earlier in fresh3 PR #40.

The work is on local branches only: `repos/siwe_login` branch `beta8`, cut from 1.0.x at 004eeb9 (beta7). The WaaP connector part is one commit on `waap-connector`. Nothing is pushed or tagged. THREAT-MODEL.md is unchanged. The clone is left on `beta8`.

### Commits (repos/siwe_login, branch beta8)

- 235d7d6 fix(email): new-account verification link expires after one day (WR-01)
- 133d1bf fix(mail): plain-text token replacement; [siwe:*] tokens in subjects (WR-02)
- 141bdfd fix(locks): the address lock outlives the inner lock wait (WR-03)
- da75f41 fix(email): case-insensitive email recheck on every database (WR-04)
- 0d398ef fix(paths): sign-in URLs and redirects follow the site base path (WR-05)
- 601660f fix(web3onboard): response checks and wallet disconnect on every path (WR-06)
- 9db92a3 fix(flood): post-proof failures count per IP only; 429 no-store and shown (IN-03, IN-05)
- 2fad63a fix(email): every email writer takes the email lock; a busy lock asks to retry (IN-01, IN-04)
- d6743ef fix(js): a sign-in error replaces only the previous sign-in error (IN-06)
- 53bf5f5 docs(composer): ext-gmp suggest text says what Composer actually needs (IN-02)
- ef5b86d docs: 1.0.0-beta8 changelog (Phase 19 review fixes)
- c5d588c style: phpcs in EmailClaimLockTest (array width, method name)

On `waap-connector`: 207714e fix(waap): sign-in fetches follow the base path; errors keep other messages (WR-05, IN-06). It is not merged into beta8. That branch is still based on beta6 (ce007ff).

### Outcome per finding

| ID | Outcome | What changed |
|---|---|---|
| WR-01 | fixed | The `verification_<hash>` entry now stores the issue time. For uid 0, `confirm()` requires the URL timestamp to equal it and the link to be at most `LINK_TIMEOUT` (86400 s) old. The hash that keys the entry is the HMAC over timestamp, email and address, so the lookup plus this equality bind the timestamp. An expired entry is deleted. A tampered timestamp is refused and the real link still works. |
| WR-02 | fixed | hook_mail replaces `[siwe:*]` in the subject and the body, then core tokens with `Token::replacePlain()`. |
| WR-03 | fixed | The address lock is held for 30 s (`ADDRESS_LOCK_LIFETIME`). That is longer than one 5 s inner wait, plus the 5 s inner hold, plus the save. This covers the email lock and also the account lock in `linkVerifiedAddress()`, which had the same gap. |
| WR-04 | fixed | Both email lookups (`otherAccountHoldsEmail()` and the form's `otherAccountWithEmail()`) use `LIKE` on `Database::getConnection()->escapeLike($email)`. Core maps this to ILIKE on PostgreSQL. |
| WR-05 | fixed | Both legacy clients and the WaaP connector use `Drupal.url('siwe/nonce?…')` and `Drupal.url('siwe/verify')`. The controller redirects use `Url::fromRoute(...)->toString(TRUE)->getGeneratedUrl()`. |
| WR-06 | fixed | `siwe-web3onboard.js` uses `.json().catch(() => ({}))`, success needs `ok && success`, and everything after the wallet connects runs in try/finally with the disconnect in it. |
| IN-01 | fixed | `createUserWithUsername()` rechecks and writes a non-empty email under the email lock. `UsernameCreationForm` fails closed on `EmailInUseException`. |
| IN-02 | fixed | The `ext-gmp` suggest text now says what Composer needs. `config.platform` stays for drupal.org CI. |
| IN-03 | fixed | After `validateMessage()` succeeds, failures register the per-IP event only. |
| IN-04 | fixed | A new `EmailLockUnavailableException`, a subclass of `EmailInUseException`. The confirmation catches it first, keeps the link, and shows "Your email could not be confirmed just now. Please open the link again in a moment." |
| IN-05 | fixed | The verify 429 goes through `noStore()`. Both legacy clients show the server's `error` text when the nonce request fails. |
| IN-06 | fixed | Errors carry the id `siwe-login-error`. Only that message is removed, guarded by `select()`, because `Drupal.Message.remove()` throws on a missing id. Fixed in `siwe-login.js` and `siwe-web3onboard.js` on beta8, and in `siwe-login-waap.js` on waap-connector. |

### Tests (each written first and seen failing, except where noted)

- WR-01: new `VerificationLinkExpiryTest` (Kernel). It runs the real flow: a signed login, the email form, the link taken from the captured mail, then `confirm()`. Only the clock is moved. Results:
  - a link at exactly 86400 s works;
  - a link at 86401 s is refused, and stays refused after moving the clock back;
  - timestamps of +3600 and -1 are refused, and the untampered link then works.
  - Before the fix, the expired and tampered cases created the account.
- WR-02: `VerificationMailTest` has two new tests.
  - With site name "Bob's Garden & Co" and user "o'neil", the subject and body are unescaped. This holds both in the sent mail and in what hook_mail returns.
  - `[siwe:email-verification-url]` and `[siwe:wallet-address]` are replaced in a subject.
  - Note: core's `MailManager` already runs the subject through `PlainTextOutput::renderFromHtml()` before sending, so the sent-mail subject looked right before the fix. The failing assertion was on hook_mail's own output, which is what `hook_mail_alter()`, `SiweMail::format()` and contrib mailers receive.
- WR-03: `EmailClaimLockTest::testAddressLockOutlivesTheEmailLockWait` and `AccountLinkTest::testAddressLockOutlivesTheAccountLockWait`. The lock doubles now record lifetimes and waits. Before the fix: 5.0 is not greater than 10.0.
- WR-04: no failing test was possible here. MariaDB (`_ci`) and SQLite (Drupal creates mail as `COLLATE NOCASE_UTF8`) already compare mail case-insensitively; only PostgreSQL differs, and no PostgreSQL was available. The existing `testConcurrentClaimInOtherCaseIsRejected` stays green. The new `testRecheckEscapesLikeWildcards` pins the escaping: `cl_im@`, `cl%@` and `CLA_M@` do not match `claim@`, while `Cl_Im@` does match `cl_im@`.
- WR-05: `EndpointContractTest::testHandoffRedirectsUseTheBasePath`, with the request context base URL set to `/garden`. Before the fix it got `/siwe/email-verification`. For the JS: `grep -n "siwe/" js/*.js` shows only `Drupal.url(...)` calls. On waap-connector, `ConnectorLibraryTest::testEndpointsUseDrupalUrl` checks the connector source.
- WR-06: JS only. `node --check` passes. There is no JS test harness in the module.
- IN-01: `EmailClaimLockTest`. The username creator refuses an email that another account holds in another case; before the fix it saved it. It saves a free email inside the address lock and then the email lock.
- IN-03: `FloodControlTest::testFailureAfterProofDoesNotCountAgainstTheAddress`. Address limit 1 and the wallet address lock refused: two post-proof 401s, then a normal login works, and the per-IP count still blocks. A mutation check (putting back `$address`) gives 429 on attempt 2.
- IN-04: `EmailClaimLockTest::testBusyEmailLockIsDistinctFromEmailInUse` and `VerificationLinkExpiryTest::testBusyEmailLockAsksToRetry`. With the email lock refused, the member gets the retry message and no account; once the lock is free, the same link works.
- IN-05: `assertTooMany()` now requires `no-store`. Before the fix, three verify-429 tests failed with `'no-cache, private'`.
- IN-06: `ConnectorLibraryTest::testErrorKeepsOtherMessages` (connector). The legacy clients are JS only: `grep -n "clear()" js/*.js` finds nothing.

### Verification

- Full suite (`ddev exec … phpunit … repos/siwe_login/tests`, 12 min 37 s): 271 tests, 1690 assertions, 1 skipped. That is the 257 baseline plus 14 new tests.
  - There was one error: `testUsernameCreatorSavesAFreeEmailUnderTheLock` was renamed (c5d588c, for phpcs) while the run was going, so phpunit called a method that no longer existed.
  - `EmailClaimLockTest` rerun on the final tree: 13 tests, 73 assertions, OK.
  - `VerificationLinkExpiryTest` rerun after switching back from waap-connector: 4 tests, OK.
- waap-connector suite (`repos/siwe_login/modules/siwe_login_waap/tests`): 36 tests, 340 assertions, OK.
- phpstan (level 0, drupal.org CI setup, on a copy under `repos/beta8check`, since deleted): `[OK] No errors`.
- phpcs (Drupal, DrupalPractice) on every changed PHP file: no new findings. The 7 remaining hits are all on lines unchanged from beta7, and running phpcs on a beta7 copy gives the same 7:
  - `EmailVerificationForm` and `UsernameCreationForm`: an inline doc block;
  - `EthereumUserManager`: comment punctuation and line length in `isGeneratedUsername()`, `updateUsernameToEns()` and `isEnsUsername()`.
  - `ConnectorLibraryTest` on waap-connector is clean.

### Live check on https://fresh3.ddev.site (curl, 2026-11-05)

The site loads siwe_login through the symlink `html/modules/contrib/siwe_login -> repos/siwe_login`, so it ran beta8.

Mail goes through symfony_mailer (`social_swiftmail`, `mailer_transport`).

- **Settings.**
  - Before: `require_email_verification: false`, `require_ens_or_username: true`, and `siwe_login.mail:email_verification.subject` = `Verify your email address for [site:name]`.
  - During the check: `require_email_verification: true` and the subject `Verify [user:mail] for [site:name], wallet [siwe:wallet-address]`. The site name has no special characters, so `[user:mail]` carries them instead, and `[siwe:wallet-address]` shows the subject-token fix.
  - After: both values restored to the "before" values. `require_ens_or_username` was not touched.
- **Sign-in.** Hardhat key #0 (`0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266`, no account on fresh3), signed in the container with the test trait.
  - `GET /siwe/nonce` gave 200 with `cache-control: no-store, private`.
  - `POST /siwe/verify` gave 200 `{"success":true,"redirect":"\/siwe\/email-verification"}`. That is the route-built redirect, with the `language` module on and no early-rendering error.
  - The email form, posted with `o'neil&co+beta8@example.com`, redirected to the front page with "Check your inbox and open the link in this same browser to finish signing in."
- **Mailpit.** The subject was `Verify o'neil&co+beta8@example.com for Drush Site-Install, wallet 0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266`. It is unescaped, and `[siwe:wallet-address]` is filled in (before the fix it would have been empty). The body has no `&#039;` or `&amp;`, and still says "will expire after one day".
- **Tampered timestamp.** The mailed link with its timestamp changed to +3600 s and to -86401 s, with the real hash, in the same session: both gave 302 to the front page with "The verification link is invalid or has expired.". No account holds the address or the email. The real link was not opened, so no account was created.
- **Aged link.** Not aged live. The expiry is covered by `VerificationLinkExpiryTest` with a moved clock, as planned.
- **Cleanup.**
  - The Mailpit message was deleted.
  - The curl session's two `tempstore.private.siwe_login` rows (owner `-z1l28BW5…`) were deleted.
  - The one `siwe_login.nonce_ip` flood row from the check was deleted. The count of `siwe_login%` flood rows since the check is now 0.
  - The temporary signer `repos/beta8check/sign.php` was removed.
  - Not cleaned up: watchdog notices and the anonymous session row, which expire with session GC.

### Notes

- WR-03: of the two fixes the reviewer offered, I chose the longer address-lock lifetime. Not waiting for the inner lock would have turned every concurrent confirmation into an immediate retry.
- WR-01 widens the `$time` / `$date_time` constructor type of `EmailVerificationController` and `EmailVerificationForm` from `Time` to `TimeInterface`. This is backward compatible for callers, and the kernel test needs it to replace the clock.
- WR-02's practical effect on a stock site is smaller than the review suggests, because core decodes subjects before sending (see Tests). The `[siwe:*]` subject fix is a real behaviour change.
- The waap-connector branch is still based on beta6. Rebasing it onto beta8 is left for its own release.

## siwe_login beta9: connect a wallet (TM-58) and the chain list (TM-59)

Date: 2026-11-06.

beta9 adds two features to siwe_login and closes two gaps. It is built and reviewed but not released: the work is on the local siwe_login branch `beta9` at `e929122`, on top of `bc76846` (the beta8 test fix). Nothing is pushed or tagged on drupal.org. The release, the tag and the fresh3 pin bump wait for Tom's word.

### Commits (siwe_login, branch beta9)

- 2411f52 feat: allowed chain list (`chain_ids`) (TM-59)
- e3b566d feat: nobody edits `field_ethereum_address` by hand (TM-58 c)
- 71394ff feat: connect a wallet to the signed-in account (TM-58)
- b1c8efd docs: beta9 README, CHANGELOG and CLAUDE.md; cspell words; drop QWEN.md
- 9ec1e29 fix: the "already has a wallet" answer no longer points at admins
- e929122 fix: bring sign-in and connect errors into view

### What members and admins see

- **Wallet tab.** A signed-in member opens the Wallet tab on their own account (`/user/{uid}/wallet`, also linked from the account form) and signs "Connect this wallet to your account on <site name>". The wallet is saved only if the account has none and no other account has it. Nobody else can open the tab, site admins included.
- **No hand edits.** Nobody can type a wallet address into an account, site admins included. Only a verified signature writes it. A member who already has a wallet sees "Your account already has a wallet connected. Changing it isn't available yet." Changing or unlinking a wallet, with re-authentication, is planned.
- **Chain list.** The admin setting is now a list of allowed chains (default: mainnet only). The first is the default. The browser signs with the chain the wallet is on when it is listed, else the default, and never asks the wallet to switch.
- **Errors in view.** A sign-in or connect error now scrolls into view and takes focus. Before, themes such as Olivero could show it above the page title, out of sight.

### Rulings (orchestrator, 2026-11-06)

1. Connect nonces stay in PrivateTempStore. Account B can't use account A's connect nonce, and a refused attempt doesn't burn A's nonce. Both behaviours have tests.
2. The field lock applies to everyone, site admins included, because Phase 21's owner checks rely on it.
3. An error the user can't see is a bug; fixed for both buttons, with a browser test.
4. Codex reviews the branch before any PR; P1 and P2 findings are fixed first.

### Tests (desktop DDEV lane `siwe-beta9`, 2026-11-06, at `e929122`)

| Test class | Result | Covers |
|---|---|---|
| `Kernel/ConnectWalletTest` | OK (14 tests, 126 assertions) | Binding, the connect statement, each nonce refused for the other purpose, a nonce issued to another account, anonymous and non-JSON requests, both 409s, the 503, replay, flood counting |
| `Functional/ConnectWalletPageTest` | OK (4 tests, 76 assertions) | Who can open the tab, the CSRF and login requirements, connect end to end, the hidden field and the form link |
| `Kernel/WalletFieldLockTest` | OK (2 tests, 17 assertions) | Edit access refused to everyone, programmatic writes still work |
| `Kernel/ChainListTest` | OK (9 tests, 96 assertions) | Reader fallback order, list parsing, the validator, sign-in on a second listed chain, `chainIds` in the nonce response, the settings form, the detector's RPC choice per chain |
| `FunctionalJavascript/ErrorMessageVisibilityTest` | OK (2 tests, 10 assertions) | Both buttons with no wallet, from the bottom of a tall page: the error has focus and is in the viewport |

- Full suite at `e929122`: 302 tests, 2020 assertions, 1 skipped (`EnsResolverTest`'s real-web3p case, as before).
- Mutation checks: removing the field lock, the CSRF requirement or the focus fix makes its test fail.
- phpstan: only the 6 old `new static` warnings. phpcs: no new findings. cspell: 0 issues.
- A browser smoke test with no wallet installed: OK.
- Codex (read-only review of `bc76846..9ec1e29`): ship, no P1 or P2. Its two P3 doc points are fixed in `e929122`. PHP couldn't start where it ran, so this was a reading review, not a test run.

### Left for later

- Tom: TM-59 accept or not. Then the beta9 release, which needs his word.
- A connect with a real wallet joins Tom's next hands-on batch, with the WaaP check.
- Once siwe_login requires beta9, safe_smart_accounts drops its copy of the field lock (Phase 21-21).
- The WaaP connector gets the same chain rule (done: siwe_login beta9-waap 94f93d0, 2026-11-08).
- A lint pass (eslint, prettier) was not run; the older phpcs, cspell, eslint and stylelint debt from beta7 is left for its own pass.

## siwe_login beta9-waap: Codex round 1, deferred item (2026-11-08)

- **WaaP on the Wallet tab (Codex round 1, item 2, P2): deferred to beta10.** In 1.0.0-beta9 the WaaP connector only signs people in. Connecting a WaaP wallet to an existing account from the Wallet tab (`/siwe/nonce?purpose=connect`, then `POST /siwe/connect` with the CSRF header) is not wired. The WaaP README says so in one sentence (siwe_login `modules/siwe_login_waap/README.md`, branch beta9-waap). For beta10: offer WaaP as a signer on the Wallet tab through the same connect flow, with the beta9 chain rule and a FunctionalJavascript test on the mocked SDK (`tests/js`).

## siwe_login 1.0.0-beta9 released: follow-ups (2026-11-08)

- **safe_smart_accounts: drop its copy of the wallet-field lock (owner: Phase 25, which owns SSA).** siwe_login 1.0.0-beta9 now forbids editing `user.field_ethereum_address` for everyone (`siwe_login_entity_field_access()`) and hides the widget on every user form (`siwe_login_form_user_form_alter()`). SSA has the same two hooks in `safe_smart_accounts.module` (at `repos/safe_smart_accounts` 4a3ee3f): `safe_smart_accounts_entity_field_access()` and the `field_ethereum_address` part of `safe_smart_accounts_form_user_form_alter()`. To do in the SSA repo, not in fresh3:
  - Raise SSA's `drupal/siwe_login` constraint from `*` to `^1.0.0-beta9`, so the lock is guaranteed to be there.
  - Then remove `safe_smart_accounts_entity_field_access()` and the `field_ethereum_address` lines of the form alter. Keep the rest of that alter, the "Shared accounts" note.
  - Keep, or move into SSA's tests, the assertion that the field can't be edited by hand, because `ReceiptStatusUpdater::ownersMatch()` trusts it.
  fresh3 itself has no copy (its only references are in `tests/a11y/seed-treasury.php` and `tests/e2e/seed-e2e.php`, which seed the field programmatically).
- **siwe_login_waap: collect diagnostics only when debug is on (Codex round 4 nit, next beta).** The connector calls `getWaaPIframeDiagnostics()` (through `iframeDiagnostics()`) when the watchdog stops or fires, even with the debug log off; only the logging is guarded. Guard the collection with `debugEnabled(settings)` too. It is harmless today (a read of SDK state), so it doesn't block anything.
- **siwe_login_waap: prettier on the connector (lint debt).** `js/siwe-login-waap.js` uses single quotes throughout, so core's prettier rule flags the whole file (92 findings at 0f37fc1, 74 at the WaaP merge). Reformat it in one pass of its own, separate from any behaviour change. Its other eslint findings are at or below the merge's counts.
- **WaaP on the Wallet tab (beta10):** see "siwe_login beta9-waap: Codex round 1, deferred item" above.
