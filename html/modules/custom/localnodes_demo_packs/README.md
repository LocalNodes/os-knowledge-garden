# LocalNodes demo packs

## What this module does

It loads one fictional community pack onto a fresh LocalNodes site. `DEMO_PACK` chooses the pack. Each pack is a directory under `packs/` holding the same YAML files social_demo reads (users, groups, pages, events, posts, polls). The module's plugins are empty subclasses of social_demo's loaders, so a pack is data only.

- Everything in the packs is made up: no real person, event, organisation, address or place.
- Seeded accounts use `.example` addresses, so no mail reaches anyone. The platform's reserved-recipient filter drops any mail to `.example`, `.test`, `.invalid` and `.localhost` addresses.
- The member how-to pages are not pack content. localnodes_platform creates them on every site at install (`content/site_guide.yml` and `content/site_guide_tour.yml` in that module).
- Once the pack is loaded, the entrypoint uninstalls this module and social_demo. The content stays. social_demo's messenger hides every status message ("saved", join confirmations) while it is enabled, and a demo site must show them. A redeploy does not enable the module again.

## Who sets DEMO_MODULE

A site that sets nothing, an empty value or `none` is a blank site: no demo module is enabled and no demo content loads, on the first install and on every redeploy. A pack loads only when a caller names it, with two values: `DEMO_MODULE=localnodes_demo_packs` and `DEMO_PACK=<pack>`.

| Caller | What it sets |
|--------|--------------|
| The provision workflow (`provision-instance.yml`) | Its `demo_module` input defaults to `none`. Choose `pack:<pack>` for a pack: the workflow writes `DEMO_MODULE=localnodes_demo_packs` and `DEMO_PACK=<pack>` on the new app, and refuses a name that is not lower-case letters, digits and underscores. There is one `pack:<pack>` choice per shipped pack: a pack added under `packs/` needs its choice in the workflow, and the workflow's shell test fails until it has one. |
| `scripts/install.sh --demo=<pack>` | The developer install on DDEV. It stops before any DDEV call if the pack is not shipped, then enables this module, runs `drush localnodes-demo:install localnodes_demo_packs --pack=<pack>`, and uninstalls this module and social_demo as the entrypoint does. |
| `drush localnodes-demo:install localnodes_demo_packs --pack=<pack>` | Loads the same plugins in the same order as the entrypoint, with the profile `packs/<pack>/`. Without `--pack`, the pack module is refused; with `--pack` on any other module, the command is refused. |
| Each Coolify demo app | `DEMO_MODULE=localnodes_demo_packs` and `DEMO_PACK=<pack>`, set on the app in Coolify (see the table below). |
| Staging | A test site, not a demo site. This list gives it no demo value. |
| A self-hoster | Leaves `DEMO_MODULE` unset or `none`. |
| The older demo modules | They stay in the repository and still load when `DEMO_MODULE` names one. They take no `DEMO_PACK`. |

## Settings for a demo site

Set these in Coolify. The names are the ones in `docker-compose.yaml`.

| Setting | Value on a demo site | Why |
|---------|----------------------|-----|
| `DEMO_MODULE` | `localnodes_demo_packs` | Selects this loader. |
| `DEMO_PACK` | `lanternrow`, `maplestreet`, `mallowbend` or `thawfest` | The pack directory under `packs/`. It must be lower-case letters, digits and underscores, and the directory must exist. Otherwise the install stops before any module is enabled. |
| `DRUPAL_SITE_NAME`, `SITE_NAME` | the site's name (table below) | Shown in the header and in mail. |
| `SERVICE_FQDN_OPENSOCIAL` | the site's address (table below) | Coolify's routing. |
| `RESEND_API_KEY` | leave unset | A second barrier to mail, behind the reserved-recipient filter. |
| `ORGANIZER_EMAIL`, `ORGANIZER_NAME` | leave unset | When set, the install sends a real welcome mail to the organizer. |
| `DRUPAL_ADMIN_EMAIL` | an `.example` address | Account 1 is not a demo member. |
| `DRUPAL_ADMIN_USER`, `DRUPAL_ADMIN_PASS` | set in Coolify only, or leave the password unset | Never in the repository. With no password set, account 1 gets a random one and is blocked after install. |
| `AI_PROVIDER` | leave unset | Unset keeps Gemini. Only `openrouter` changes the provider. |
| `GEMINI_API_KEY` | the demo key, set in Coolify only | The assistant and the tier 2 checks need it. |
| `GEMINI_KEY_TIER` | `paid` when the key's Cloud project has billing on | Declares the key's data tier. A demo site is exempt from the tier check, but declaring it keeps the status report clean. |
| `WEB3_ENABLED` | `false` on all four sites | The default. A pack loads no fund, and the loader makes no network call. |
| `WORKER_ENABLED`, `WORKER_INTERVAL` | on, default interval | Cron indexes the content and sends queued mail through the filter. |
| `LOCALNODES_DEFER_INDEXING` | leave unset | The entrypoint sets it for the load only. |
| `PROVISION_*` | leave unset | These belong to sign-up runs, not to demos. |

Qdrant and Solr hosts are fixed to the compose services.

## The four sites

| Site | `DEMO_PACK` | Address | Walk-through user | Export owner |
|------|-------------|---------|-------------------|--------------|
| Lantern Row Housing Co-op | `lanternrow` | `lanternrow.localnodes.xyz` | Ines Duarte | Sam Okafor |
| Maple Street Neighbors | `maplestreet` | `maplestreet.localnodes.xyz` | Zora Bennett | Dalia Reed |
| Mallow Bend Farmers' Market | `mallowbend` | `mallowbend.localnodes.xyz` | Carys Bellamy | Maren Pike |
| Thawfest | `thawfest` | not chosen yet | Suri | Mara, only if the export step is shown |

All names are fictional.

## Walk status

| Site | Walk steps checked by the tests and scripts/check_demo_pack.php | Held back |
|------|------------------------------------------------------------------|-----------|
| Lantern Row Housing Co-op | Steps 1, 2, 3, 5 (the screen) and 8 are checked by the tests, as Ines Duarte. | The assistant rows run only on a site with an AI key, through scripts/check_demo_pack.php. Step 5's assistant row (lr-12) passes there. Step 4's row (lr-01) does not pass yet: the live answer differs from what the row requires. Step 6 waits for the decisions work. Step 7 waits for the practice-money work. |

## Presenter sign-in

No demo account has a stored or guessable password: each one gets a random password that is never shown.

1. The site operator runs `drush user:login --name=<account name of the walk-through user>` on the site and hands the one-time link to the presenter.
2. To confirm an export, the export owner signs in the same way, sets a throwaway password on the account edit page, and types it on the export form.

Nothing is written down.

## Left out, with owners

These parts of the packs cannot be loaded as written. Each is left out or changed as described.

| Item | What happens instead | Owner |
|------|----------------------|-------|
| Agree, stand aside and block decision records | Left out. Pages that describe the rule (bylaws, handbook) still load as text. | The decisions work |
| Fund and pot history | Left out. The loader makes no chain call. | The funds work, plus a Safe on a test chain |
| Pinned stream posts | Pinning is not built. The post is dated now, so it shows first. | no owner |
| Polls, decisions and pots labelled "community" | Loaded in their group. Quick polls are group-only. | no owner |
| "Request an invitation" on an invitation-only group | No such button exists. The pack uses "Request to join" or rewords the text. | no owner |
| "Going" lists that include non-members | Seeded only. A presenter cannot reproduce them live. | none |
| Fund and vote controls for a visitor who joins | Not shown while web3 is off. | The funds work and web3 |

Two member how-to pages are held back:

| Page | Why | Owner |
|------|-----|-------|
| P11 "Respond to a decision" | It needs today's voting feature and voting access set up for the group. Rewrite it with the house screens. | The decisions work |
| P12 "See what the fund holds" | The fund is off at load. | open: whether Lantern Row gets a hand-made practice fund |

## How-to pages

Every site, with or without a pack, gets the same ten member how-to pages (P01 to P10) and a page on taking the group tour. localnodes_platform creates them when it is installed, from `content/site_guide.yml` and `content/site_guide_tour.yml` in that module, so removing a pack keeps them. Each is a Topic with the "Site guide" topic type, ending with links to the screens it describes. The site guide account owns them: a blocked account with no role and no password, with an address on a reserved domain, so nobody can sign in as it, reset its password or receive its mail. Each page is visible to signed-in members, in no group. The assistant answers "how do I" questions from these pages.

P11 "Respond to a decision" and P12 "See what the fund holds" are withheld, with the owners named in the table above, so no page describes a feature the site does not have.

On who edits: site managers and content managers can edit a guide page when they can already see it. The grant lives in localnodes_platform (a node-access rule on topics marked Site guide), not in this module, because the entrypoint uninstalls this module after the load. No role gains a permission. A member cannot edit a guide page, and cannot mark a topic of their own as a guide page: the form offers the Site guide type only to those two roles. On a site with no Site guide topic type the rules do nothing.

The rules are strict about who changes a guide page. A member who owns a topic loses edit and delete once it is marked Site guide. The importer offers the Site guide type only to the two editor roles. A group organizer without an editor role cannot take a guide page down from a problem report.

No writable API (REST, JSON:API or GraphQL) is enabled on a fresh install, so none offers a way around these rules. Whoever enables one later must give its write access the same Site guide check.

The edit form of a guide page starts with a line for the editor: "The assistant answers 'how do I' questions from this page. If you change it, its answers change."

## Updates

Guide pages are content loaded once, on a fresh install. A redeploy runs only `updatedb` and `cr`, and the loader skips every uuid the site already has, so new wording in a later image does not reach an installed site, edited or not: the site keeps its own copy. The Site guide topic type is also install-only. The edit line and the edit rules are code in localnodes_platform and change with the image.

## Before the public image can show everything

Answers that cite pages from more than one group need the shared-pages work. That work is not in the public image yet, and one small open item remains on it.

## Tests

- Tier 1 needs no AI key: `PackLoadTest` and `PackEntrypointTest` in this module, and the shell smoke `tests/phase222/demo-pack-arm.sh`.
- Tier 2 (`scripts/check_demo_pack.php`) asks the assistant the pack's questions, so it needs the site's AI key.
