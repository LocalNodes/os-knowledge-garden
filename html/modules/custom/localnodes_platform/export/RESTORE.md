# Bring {{site_name}} up on your own server

This folder is a full copy of the community site **{{site_name}}**, made on {{created}}. Follow these steps in order to run it on a server you control. You do not need anything from LocalNodes to do it.

## What is in this folder

| File | What it is |
|---|---|
| `database.sql.gz` | The site's database: members (with their passwords, stored as hashes), groups, pages, events, comments, settings. |
| `public-files.tar.gz` | Public files: profile pictures, images in posts. |
| `private-files.tar.gz` | Private files: documents attached to pages, readable only by the people allowed to see them. |
| `manifest.json` | What was exported: versions, file counts, a checksum for each part, and what was left out. |
| `SHA256SUMS` | The checksums, for step 1. |

**Not included:**

- **No keys or passwords from our server.** There is no AI key, mail key, token or database password in this copy. You bring your own (step 2).
- **The search index (Solr) and the assistant's index (vector store).** Both are rebuilt from the database in step 6. The assistant's index needs your own AI key.
- Caches, logs and sign-in sessions. Everyone signs in again with their usual password.

This copy holds every member's account and all private content. Keep it as safe as the site itself, and delete copies you no longer need.

## What you need

The same as for a new LocalNodes site: a Linux server with Docker Compose v2, 4 GB of memory or more and 10 GB of free disk, plus `git`, `openssl`, `tar` and `sha256sum`. Mail and AI keys are your own; the site starts without them.

All commands below are run on the server.

## 1. Check the copy

In the folder that holds the archive:

```sh
tar -xf {{folder}}.tar
cd {{folder}}
sha256sum -c SHA256SUMS
cd ..
```

Every line must end in `OK`. If one says `FAILED`, the copy was damaged in transit: get it again.

## 2. Start a new, empty site

Follow **steps 1 to 4** of the self-hosting guide, [docs/SELF-HOST.md](https://github.com/localnodes/os-knowledge-garden/blob/main/docs/SELF-HOST.md), in the folder that holds `{{folder}}`, so that the two folders sit side by side. Skip its step 5: the administrator and every member come back with the copy.

In step 2 of that guide, make these lines in `.env` read as follows, so the new site matches the old one (change `DEMO_MODULE` where it is, and add the other two):

```sh
# The image this site was exported from. If the first start stops with
# "manifest unknown", that tag is not published: leave IMAGE_TAG unset
# (latest). Never use an image older than the export.
IMAGE_TAG={{image_tag}}
DEMO_MODULE={{demo_module}}
WEB3_ENABLED={{web3_enabled}}
```

`DEMO_MODULE` must be `none`, even if the old site showed demo content: the demo content is in the copy.

This site was exported from the build `{{commit}}`, using the AI provider `{{ai_provider}}`. If that is `openrouter`, set `AI_PROVIDER=openrouter` and `OPENROUTER_API_KEY` instead of `GEMINI_API_KEY` (step 6 of the guide).

When the guide's step 4 shows `Starting Apache...` and the empty site opens in a browser, come back here. From now on, run every command in the `localnodes` folder; the copy is at `../{{folder}}`.

## 3. Stop the site and empty it

```sh
docker compose stop opensocial
docker compose exec -T mariadb sh -c 'exec mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" -e "DROP DATABASE \`$MYSQL_DATABASE\`; CREATE DATABASE \`$MYSQL_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"'
docker compose run --rm --no-deps -T --entrypoint sh opensocial -c \
  'find /var/www/html/html/sites/default/files /var/www/private -mindepth 1 -delete'
```

This deletes the empty site's database and files, not the copy.

## 4. Load the copy

```sh
gunzip -c ../{{folder}}/database.sql.gz \
  | docker compose exec -T mariadb sh -c 'exec mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'
docker compose run --rm --no-deps -T --entrypoint tar opensocial \
  -xzf - -C /var/www/html/html/sites/default/files < ../{{folder}}/public-files.tar.gz
docker compose run --rm --no-deps -T --entrypoint tar opensocial \
  -xzf - -C /var/www/private < ../{{folder}}/private-files.tar.gz
```

No output means it worked. Check that the members are there:

```sh
docker compose exec -T mariadb sh -c 'exec mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e "SELECT COUNT(*) AS accounts FROM users"'
```

## 5. Start the site again

```sh
docker compose up -d opensocial
docker compose logs -f opensocial
```

Wait for the newest `Starting Apache...`, then press Ctrl+C. Above it, the log must now say `=== EXISTING INSTALL ===`. If it says `=== FRESH INSTALL ===` again, the database was not loaded: go back to step 3.

The site brings the database up to date and clears its caches as it starts. Run both once more by hand, to see them finish cleanly:

```sh
drush() { docker compose exec -T opensocial /var/www/html/vendor/bin/drush -r /var/www/html/html "$@"; }
drush updatedb -y
drush cr
```

`drush updatedb` says `No pending updates.` or lists the updates it ran. Neither should end in an error.

Never run `drush config:import` (or `drush deploy`) on this site: the site's settings are in the database you loaded.

## 6. Rebuild the search indexes

The search index still holds the empty site's few entries, and the database remembers the old site's items as indexed. Clear both, then index:

```sh
drush search-api:clear
drush search-api:index social_all
drush search-api:index social_content
drush search-api:index social_groups
drush search-api:index social_users
```

Ordinary search works when these finish.

The assistant's index (`social_posts`, `social_comments`) is built by the site's background worker once an AI key is set in `.env` (step 6 of the guide). It runs every minute and works through the documents a few at a time. Until a key is set, the worker's log shows failed indexing attempts for these two; that is expected.

## 7. Check the site

Open the site in a browser.

- Sign in with your usual account and password. Every member's password still works.
- Open a private group as one of its members: its pages are there. Signed out, or as someone who is not a member, it stays closed.
- Open a page with an attached document and download it.
- Search for the title of a page you know.

Under *Administration › Reports › Status report*, an error for the Gemini data tier is expected until you set an AI key, and `GEMINI_KEY_TIER=paid` for a paid one.

To sign in as the site's administrator without a password (for example before mail works):

```sh
drush user:login --uri=https://<your.domain.org>
```

## 8. Finish moving in

- **Mail.** The site's own sender address is still the old one. Change it under *Administration › Configuration › System › Basic site settings* to the address in `MAIL_FROM`, at a domain verified in your Resend account.
- **Domain and HTTPS.** Step 7 of the self-hosting guide.
- **Backups and updates.** The "Running it" section of the guide.
- **Web3.** If `WEB3_ENABLED` is `true` above, the site also uses the `gas-sponsor-relayer` service in `docker-compose.yaml`. That part is not covered by these steps; ask LocalNodes before you rely on it.

## If something goes wrong

- `docker compose logs opensocial` shows what the site container did at start.
- `drush watchdog:show` shows the site's own recent errors.
- Starting over is safe while you are still setting up: `docker compose down -v` deletes this stack's database, files and indexes (not the copy), and you begin again at step 2.
