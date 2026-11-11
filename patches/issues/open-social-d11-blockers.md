Status: draft, not posted; waits for Tom (D-09)

# drupal.org comment draft: Drupal 11 blockers for Open Social 13.1 (issue #3570907)

## Where to post

Comment on drupal.org issue #3570907, "Drupal 11 compatible Open Social" (project Open Social, goalgorilla/open_social). Nothing here has been posted. Phase 22.2 produced it from `.planning/phases/22.2-open-social-13-1-upgrade/D11-BLOCKERS.md`, which has the full evidence and our own items. Only the items that Open Social or a contrib maintainer owns are below.

## Comment text

Hi all. We run Open Social 13.1.0 on Drupal 10.6.21 and want to be ready for Drupal 11 before Drupal 10.6 security support ends in December 2026. We scanned 13.1.0 with Upgrade Status 4.3.10 and ran `composer why-not drupal/core 11.4.12` against its lock. The issue says there is no list of blockers yet, so here is one. We have not tried to install Open Social on Drupal 11, because the first item stops composer before that.

I also looked at MR !102. On 2026-11-09 it has 0 commits and 0 changed files, the head is `2ed29db0` (an old `main` commit), and it has conflicts with `main`. There was nothing to test.

**1. Core requirement.** `composer.json` on `main` still requires `"drupal/core": "^10.5.6"`. In 13.1.0, 127 `*.info.yml` files also say `core_version_requirement: ^9 || ^10` (124 files), `^8.8 || ^9 || ^10` (the `socialbase` and `socialblue` themes), or `^10` (`social_analytics`).

**2. Packages Open Social pins that have no Drupal 11 release under that pin** (core requirement from `composer why-not`):

| Package (Open Social pin) | Locked | Core requirement | Newer release that allows Drupal 11 |
|---|---|---|---|
| `drupal/ckeditor` (`1.0.2`) | 1.0.2 | `^9.4 \|\| ^10` | none; CKEditor 4 is gone from Drupal 11 core |
| `drupal/gin` (`^3.0`) | 3.1.0 | `^9 \|\| ^10 \|\| ^11 <11.2` | Gin 5.x (`^11.2`) |
| `drupal/gin_toolbar` (through `gin ^3`) | 1.1.0 | `^9 \|\| ^10 \|\| ^11 <11.2` | not checked beyond the 1.x line |
| `drupal/inline_entity_form` (`^1.0@RC`) | 1.0.0 | `^8.8 \|\| ^9 \|\| ^10` | 3.0.0 (`^8.8 \|\| ^9 \|\| ^10 \|\| ^11`) |
| `drupal/login_tracker` (`^2.0`) | 2.0.0 | `^8.9 \|\| ^9 \|\| ^10` | none yet |
| `drupal/variationcache` (`^1.4`) | 1.5.0 | `^9.5 \|\| ^10` | `1.x-dev` only, no tagged release |
| `drupal/video_embed_field` (`2.5.0`) | 2.5.0 | `^9.2 \|\| ^10` | 3.1.1 (`^10.3 \|\| ^11`) |
| `drupal/votingapi` (`3.0.0-beta5`) | 3.0.0-beta5 | `^9.2 \|\| ^10` | 3.0.0-beta6 (`^9.2 \|\| ^10 \|\| ^11`) |

**3. Deprecated API use that Drupal 11 removes** (Upgrade Status, "removed from drupal:11.0.0"):

- `profiles/contrib/social/modules/features/social_course/social_course.module` lines 398 and 420 call `Drupal\Core\Url::toRenderArray()`, deprecated in drupal:10.1.0, no replacement.
- 37 views config files set `default_argument_skip_url` on contextual filters (support removed in drupal:11.0.0), for example `social_private_message/config/install/views.view.inbox.yml:184`.

**4. Symfony.** Drupal 11.4.12 requires Symfony 7.4. Drupal 10.6.21 requires Symfony 6.4, and our lock resolves 6.4.x. This moves with core, so it only matters if a package adds its own Symfony 6 pin.

We have not tried any fixes. We can test patches or a branch against our stack (Open Social 13.1.0 on Drupal 10.6.21, PHP 8.3) if that is useful. Is there a preferred order for tackling these? The core requirement and the pins in item 2 look like they need to move together.

## Notes for Tom before posting

- Posting needs your go-ahead (D-09). Nothing was posted.
- The table in item 2 names contrib projects. The maintainers do not need separate issues from us; this list is for Open Social's maintainers.
- The `gin_toolbar` newer-release cell is "not checked": I only confirmed that 1.x tops out at `<11.2`. Check that before posting, or drop the cell.
- Our own items (our modules' `^10`, `group_treasury`, our root Symfony pin) are in `D11-BLOCKERS.md` and are left out on purpose.
