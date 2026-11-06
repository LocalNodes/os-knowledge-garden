# Phase 19: siwe_login 1.0.x release gate (D-18)

Recorded 2026-11-04 by plan 19-08, for 19-11 (tag) to read.

Pushed SHA: 9a53d51b60116840243014a0a5a2c425e784cfea
Dependency-absent suite: green (231 tests, 1 skipped) at 9a53d51b60116840243014a0a5a2c425e784cfea
Dependency-present CI: unavailable (pipeline 1031542 failed in its composer job before any test ran: "simplito/elliptic-php[1.0.0, ..., 1.0.12] require ext-gmp * -> it is missing from your system"; https://git.drupalcode.org/project/siwe_login/-/pipelines/1031542)
CI evidence: command=n/a; web3p=none (composer resolved no packages); tests=n/a skipped=n/a failed=n/a; case-e=not-run; jobs=composer:failed, composer-lint:skipped, phpcs:skipped, phpstan:skipped, stylelint:skipped, eslint:skipped, cspell:skipped, phpunit:skipped
Dependency-present uncovered: real Web3 construction from the module's own composer.json and the real-provider RPC call path (EnsResolverTest case e); covered by EnsResolverTest simulated-present cases (b)-(d) through the spy factory, the dependency-absent cases (a) and (f), LoginHappyPathTest::testLoginWithUpstreamEnsDefaults and the ENS-on MessagePolicyTest cases with a stubbed resolver
Tag: 1.0.0-beta5 at 460f5b831f84004fdb2b57d834cf25416b00dcbb (annotated 26e65396b1973610386f1b90c1021a85b1aa0527; cut after 19-10)
Tag CI: fixed-and-passed at 460f5b831f84004fdb2b57d834cf25416b00dcbb (pipeline 1032009: composer job fixed by 460f5b8 itself; phpunit 240 pass, 0 fail, 1 skip)
Tagged suite: green (241 tests, 1 skipped) at 460f5b831f84004fdb2b57d834cf25416b00dcbb (drupal.org CI phpunit job, pipeline 1032009; the last local full suite before the tag ran on the 19-FOLLOWUPS commits up to 261dc7251279d6566a2f77793ef5bed316b82173 (241 tests, 1345 assertions, 1 skipped), and 460f5b8 differs from 261dc72 only in composer.json)
WaaP fixture re-run: done on the released source (2026-11-05). waap-connector rebased onto 1.0.x ce007ff (beta6 0b19b67 + changelog) as a742b77; it differs from 1.0.x only under modules/siwe_login_waap/ (17 files). WaapSignatureFixtureTest: OK (6 tests, 82 assertions). Full siwe_login_waap suite: first run reported 1 error with its detail lost (run started right after enabling the module during a mutagen sync); clean re-run OK (34 tests, 334 assertions). Pre-rebase branch kept as waap-connector-pre-rebase.
Later release: 1.0.0-beta6 at 0b19b679cd8a20870490870f665f9257ed66160e (annotated 5825b3afe874f24e07ef0c5c4aa01f5174e6e553; pipeline 1032106 green; local suite 241 tests, 1345 assertions, 1 skipped on that head)

## What was pushed

- One ref, `refs/heads/1.0.x`, fast-forward `39d0642..9a53d51` to `git@git.drupal.org:project/siwe_login.git`. No tag, no other branch, no force.
- `9a53d51` differs from the browser-verified clone commit `c1b1dfa` (19-BROWSER-VERIFY.md) only in `.gitlab-ci.yml`, `CHANGELOG.md` and `README.md` (`git diff --name-only c1b1dfa 9a53d51`).
- 1.0.x contains nothing under `modules/siwe_login_waap/` (count 0). No `waap-connector` branch exists on the remote.
- Remote tags before and after the push are identical (`git ls-remote --tags origin`, diffed): `1.0.0` -> cc9de18, `1.0.0-beta1` -> f5b140a, `1.0.0-beta2`, `1.0.0-beta3`, `1.0.0-beta4` -> 21df6eb. No `1.0.0-beta5`.
- Remote heads after the push: `1.0.x` -> 9a53d51, `main` -> de820e0 (unchanged).
- GitLab's push reply pointed at an existing open merge request, project/siwe_login!2 ("chore: Prepare 1.0.0-beta1 release", 1.0.x -> main, opened 2025-12-09). Nothing was done with it.

## Local gate (fresh3, dependency-absent)

fresh3 replaces `web3p/web3.php`, so the full suite runs with web3p absent. The dependency-present logic is tested by simulation in the same run: EnsResolverTest cases (b)-(d) inject a spy factory that stands in for an installed web3p. No second Composer project was built, and fresh3 composer.json and composer.lock were not touched.

- Full suite at 9a53d51: `OK, but incomplete, skipped, or risky tests! Tests: 231, Assertions: 1263, Skipped: 1.` (8 min 52 s). The one skip is EnsResolverTest case (e), the real-web3p case.
- Filtered dependency-mode run (`--filter "EnsResolverTest|testLoginWithUpstreamEnsDefaults|Ens"`): `Tests: 19, Assertions: 115, Skipped: 1.` It includes all six EnsResolverTest cases (a)-(f), with (e) skipped, `testLoginWithUpstreamEnsDefaults`, and five MessagePolicyTest ENS-claim cases with a stubbed resolver (claim resolving to the signer kept; resolving elsewhere rejected; lookup returning null, resolver unavailable, or ENS validation off discards the claim). The filter also matched two EnsHandoffTest, two EndpointContractTest, one VerificationMailTest and two other MessagePolicyTest cases.
- Runner: `ddev exec 'cd /var/www/html && SIMPLETEST_DB=mysql://db:db@db:3306/db SIMPLETEST_BASE_URL=http://web vendor/bin/phpunit -c /var/www/html/html/core/phpunit.xml repos/siwe_login/tests'`.

## Dependency-present CI (drupal.org GitLab)

The push started pipeline 1031542 (source `push`, ref `1.0.x`, sha 9a53d51). It ended `failed`.

- The `composer` build job expanded the module's composer.json against Drupal core 11.4.9 on PHP 8.3.36 (Composer 2.10.3), then failed at dependency resolution:

  ```
  Your requirements could not be resolved to an installable set of packages.
    Problem 1
      - Root composer.json requires simplito/elliptic-php ^1.0 -> satisfiable by simplito/elliptic-php[1.0.0, ..., 1.0.12].
      - simplito/elliptic-php[1.0.0, ..., 1.0.12] require ext-gmp * -> it is missing from your system. Install or enable PHP's gmp extension.
  ERROR: Job failed: command terminated with exit code 2
  ```

- Every later job was skipped, including `phpunit`. So no siwe_login test ran on drupal.org, and nothing failed there.
- The planned risk (web3p/web3.php ^0.3.2 not resolving against current Drupal core) was never reached. Whether web3p resolves against Drupal 11.4.9 is still unknown.
- The job trace is public at https://git.drupalcode.org/project/siwe_login/-/jobs/13371156/raw (the API trace endpoint returns 401 anonymously).

Not fixed here, by design: the clean fix is a module composer.json change (`config.platform.ext-gmp`, as Phase 18 did for fresh3, or moving the ext-gmp question upstream to elliptic-php). That is a module file, so it needs re-verification before any push. A `.gitlab-ci.yml` `--ignore-platform-req` workaround was not used, because the project dropped that flag on purpose.

## Open items for 19-12

1. ext-gmp in drupal.org CI: give the module's composer.json a way past the over-strict `ext-gmp` requirement of simplito/elliptic-php (elliptic-php runs on bcmath), so the `composer` job can resolve.
2. DEPS-02: move `web3p/web3.php` from `require` to `suggest`. Then check whether the dependency-present mode (and case e) can still be run in CI at all.
3. After both, re-run drupal.org CI on 1.0.x and record the phpunit job's outcome here before 19-11 relies on it.

## CI re-run after the composer fix (2026-11-05)

- Pushed `1.0.x` `261dc72..460f5b8` (Tom's choice A, relayed by the orchestrator). `460f5b8` moves `web3p/web3.php` to `suggest` (DEPS-02), makes `ext-gmp` a suggestion and declares `config.platform.ext-gmp` for CI. drupal.org's `expand_composer_json.php` deep-merges the project composer.json into the CI root, so the declaration applies there only.
- Pipeline 1032009 (source push, sha 460f5b8): **success**, 210 s. https://git.drupalcode.org/project/siwe_login/-/pipelines/1032009
  - composer: passed. composer-lint: passed. phpunit: **passed**, PHP 8.3.36 with no GMP (BCMath path): 240 pass, 0 fail, 1 skip (EnsResolverTest `testRealWeb3PresentConstructsLazily`, web3p absent) = 241, the same as the local suite.
  - Allowed to fail and failing: cspell, eslint, phpcs, phpstan, stylelint. These are first runs on this module; triage is follow-up work and does not block the tag.
- The MR !2 merge-result pipelines (1031943, 1032010) belong to the old 2025 "Prepare 1.0.0-beta1" merge request and are not this gate.
- Tag target for 1.0.0-beta5: `460f5b8`. Tag not created; waiting for Tom's go.

## beta5 tag (2026-11-05)

- Tom: "yes, push the beta5 tag" (relayed by the orchestrator). Annotated tag `1.0.0-beta5` = `26e6539`, peels to `460f5b8`. Pushed alone; `git ls-remote --tags origin` shows `refs/tags/1.0.0-beta5^{}` = `460f5b831f84`. The stray `refs/tags/1.0.0` -> `cc9de18` is still there for Tom to delete by hand (D-21), then he creates the release node.

## phpstan triage, pipeline 1032009 (14 errors, job 13379969)

No real bug found. One item is a deprecation worth fixing before Drupal 13.

| Where | Finding | Verdict |
|---|---|---|
| `siwe_login.install:155, 179` | `$role->trustData()->save()` is deprecated in drupal:11.4.0, removed in 13.0.0, no replacement | Works today; fix in beta6 by dropping `trustData()` (a plain `save()` validates the role, and the permission exists) |
| `EnsResolver.php:196, 485, 512, 539` | `Web3\Web3` / `Web3\Contract` not found | False positive: web3p is now optional and every use sits behind `class_exists(Web3::class)` (`isWeb3Available()`); add a phpstan ignore for these |
| `InvalidSiweMessageException.php:15-16`, `WalletAuthenticationException.php:12`, `SiweSettingsForm.php:230` | `\Drupal` calls in classes | Style (dependency injection advice), not a bug |
| `SiweKernelTestBase.php:32`, `SiweFunctionalTestBase.php:30` | "Annotation @group). is invalid" | False positive: prose in the docblock ("must carry their own @group siwe_login (PHPUnit 9 …)") parses as a tag; reword |
| `ContractWalletTest.php:96`, `NonceStoreTest.php:218` | use `assertCount()` | Style |

The other allowed-to-fail jobs (cspell, eslint, phpcs, stylelint) are still to triage; phpcs hits on untouched lines were already listed in [followups.md](followups.md).

## beta6 (2026-11-05)

- Tom: "yes, push and tag beta6" (relayed by the orchestrator). Local 1.0.x fast-forwarded to `0b19b67` (b970831 phpstan cleanup, b18ca36 beta5 changelog date, 0b19b67 version metadata left to packaging).
- Local suite on that exact head: `Tests: 241, Assertions: 1345, Skipped: 1`.
- Pushed `1.0.x` `460f5b8..0b19b67`. Pipeline 1032106: **success**.
  - Passed: composer, composer-lint, **phpstan (now clean)**, phpunit (240 pass, 0 fail, 1 skip).
  - Allowed to fail and failing:
    - phpcs: 40 errors, 7 warnings in 66 files.
    - cspell: 140 issues in 32 of 80 files.
    - eslint: 126 errors.
    - stylelint: 2 errors, both `order/properties-order` in `css/siwe-login.css`.
- Annotated tag `1.0.0-beta6` = `5825b3a`, peeling to `0b19b67` (ls-remote). Pushed alone. The remote `1.0.x` is `0b19b67`. The orchestrator creates the release node.
- Gap: CHANGELOG has no 1.0.0-beta6 section. Add it on 1.0.x afterwards.

## beta7 (2026-11-05)

- Tom: "release away!" (relayed by the orchestrator). Local 1.0.x was fast-forwarded to the beta7 branch, plus `004eeb9` dating the changelog entry. That adds TM-24 flood control on `/siwe/nonce` and `/siwe/verify` (core flood service, 429 plus Retry-After, configurable limits that fall back to defaults) and moves the README threat-model link to `docs/security/THREAT-MODEL.md`.
- Local suite on the beta7 branch: 257 tests, 1 skipped (241 plus 16 flood tests). phpstan was clean.
- Pushed `1.0.x` from `ce007ff` to `004eeb9`. Pipeline 1032257 succeeded: composer, composer-lint, phpstan and phpunit passed. cspell, eslint, phpcs and stylelint are allowed to fail, as before.
- Tag: the agent's tag-and-push command was refused by the Claude Code auto-mode classifier and was not retried. Tom ran it himself. `git ls-remote --tags origin` shows `refs/tags/1.0.0-beta7` = `59e272c` (annotated), peeling to `004eeb9`.
- Release node, created by the orchestrator: https://www.drupal.org/project/siwe_login/releases/1.0.0-beta7. Its notes include a line on `reverse_proxy_addresses`.
- Deployment caveat: per-IP limits are only as good as the client IP Drupal sees. fresh3 PR #40 replaces `reverse_proxy_addresses = ['0.0.0.0/0']` with Cloudflare's ranges plus the instance's Traefik network. The fresh3 pin to beta7 waits for #40 and the Traefik subnet.

## beta8 (2026-11-06)

- Tom: "go" (relayed by the orchestrator, about 02:50 UTC). beta8 carries the Phase 19 code-review fixes: WR-01 to WR-06 and IN-01 to IN-06 (see `.planning/phases/19-siwe-hardening-waap-login-threat-model/19-REVIEW-DISPOSITION.md`).
- Local suite on the beta8 branch: 271 tests, 1 skipped (257 plus 14 new). siwe_login_waap: 36 tests passed. phpstan was clean.
- The orchestrator dated the changelog (`a461e5d`), fast-forwarded `1.0.x` and pushed it, then created and pushed the annotated tag with plain `git push` from this Mac. Nothing was refused this time. `git ls-remote --tags origin` shows `refs/tags/1.0.0-beta8` = `5f21667` (annotated), peeling to `a461e5d`.
- Release node, created by the orchestrator: https://www.drupal.org/project/siwe_login/releases/1.0.0-beta8. Release type: Bug fixes, not Security update, because no security advisory is involved.
- fresh3 pin: PR #48 (`138b317`), lock only. The lock shasum `d759568e…` matches the drupal.org zip, checked by both sessions. Apart from the packaging script's version lines, the zip matches the tag.
