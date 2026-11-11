# Run LocalNodes on your own server

This guide starts a new, empty LocalNodes community site on a server you control, with Docker Compose. It is written for someone who has run a Linux server before but has never used Drupal (the software LocalNodes is built on). You do not need anything from LocalNodes to follow it.

If you are moving an existing LocalNodes site to your server, follow this guide up to the end of step 4, then go on with the `RESTORE.md` that came inside your export.

## What you need

- A Linux server with an **x86-64 processor (Intel or AMD), not ARM**, 4 GB of memory or more, and 10 GB of free disk. The published site image does not support ARM yet.
- [Docker Engine](https://docs.docker.com/engine/install/) and Docker Compose v2 (`docker compose version` works). Docker must work for your account without `sudo`; follow Docker's [account setup instructions](https://docs.docker.com/engine/install/linux-postinstall/) if needed. Check with:

  ```sh
  docker info
  ```

- `git` and `openssl`.
- An SSH client on your own computer, for the first look through a secure connection to the server.
- A domain name pointed at the server, once people will use the site. You can try it first through the SSH tunnel in step 3.
- Later, not for the first start:
  - **Mail**: an account with [Resend](https://resend.com), its API key, and your domain verified there. Without it the site runs, but sends no mail (no sign-up confirmations, password resets or notifications).
  - **The assistant**: a Google Gemini API key from [Google AI Studio](https://aistudio.google.com/apikey), or an [OpenRouter](https://openrouter.ai) key. Without one, everything works except the assistant and its search.

Run the commands below on the server, except for the SSH tunnel command marked in step 3, which runs on your own computer.

## 1. Get the software

```sh
git clone https://github.com/localnodes/os-knowledge-garden.git localnodes
cd localnodes
```

From here on, run every command in this `localnodes` folder.

## 2. Write your settings

The settings live in a file named `.env`. Start from the example, and let `openssl` make the database passwords and the hash salt:

```sh
(
  umask 077
  set -C
  cat .env.example > .env
) &&
chmod 600 .env &&
sed -i \
  -e "s/^DB_PASSWORD=$/DB_PASSWORD=$(openssl rand -hex 16)/" \
  -e "s/^DB_ROOT_PASSWORD=$/DB_ROOT_PASSWORD=$(openssl rand -hex 16)/" \
  -e "s/^DRUPAL_HASH_SALT=$/DRUPAL_HASH_SALT=$(openssl rand -hex 32)/" \
  .env
```

If `.env` already exists, the block refuses to replace it and leaves it unchanged. Stop and use your existing settings; do not delete them to repeat this step.

Check the three generated values without displaying them. This prints `ok`, or the name of each value that is still blank:

```sh
awk -F= '
  $1 == "DB_PASSWORD" || $1 == "DB_ROOT_PASSWORD" || $1 == "DRUPAL_HASH_SALT" {
    values[$1] = substr($0, index($0, "=") + 1)
  }
  END {
    split("DB_PASSWORD DB_ROOT_PASSWORD DRUPAL_HASH_SALT", names, " ")
    for (i = 1; i <= 3; i++) {
      value = values[names[i]]
      gsub(/^[[:space:]]+|[[:space:]]+$/, "", value)
      if (value == "" || value == "\"\"" || value == "\047\047") {
        print names[i] " is blank"
        missing = 1
      }
    }
    if (!missing) print "ok"
    exit missing
  }
' .env
```

Do not continue if a value is blank. The hash salt is a permanent random value that protects sign-in; if it is left blank, sign-in links stop working after every restart.

Then open `.env` in an editor (`nano .env`) and fill in the rest of the first block:

| Setting | What to put there |
|---|---|
| `SERVICE_FQDN_OPENSOCIAL` | Put `localhost` for the first look through the SSH tunnel. In step 7, change it to your domain, without `https://`. The site refuses other host names. |
| `SERVICE_URL_OPENSOCIAL` | Put `http://localhost:8080` for the SSH tunnel. This is the full address your browser opens; sign-in and mail links use it. In step 7, change it to your full HTTPS address, such as `https://community.example.org`. |
| `DRUPAL_SITE_NAME` | Your community's name. |
| `DRUPAL_ADMIN_EMAIL` | Your own email address, for the first administrator account. |
| `DEMO_MODULE` | Leave it as `none` for an empty site. Any other value fills the site with made-up demo people and pages, which is only for looking around. |

You can leave the mail and AI keys empty for now (step 6). Leave every setting that is not in `.env.example` unset. In particular, never set `ORGANIZER_EMAIL` or any `PROVISION_*` variable: they belong to LocalNodes' own sign-up service.

Do not change `DB_PASSWORD`, `DB_ROOT_PASSWORD` or `DRUPAL_HASH_SALT` after the first start. The database keeps the first passwords, and a different salt can break sign-in links.

## 3. Make the site reachable

The stack does not publish a port by itself. This file makes the site available only on the server's own local address, port 8080:

```sh
cat > docker-compose.override.yml <<'EOF'
services:
  opensocial:
    ports:
      - "127.0.0.1:8080:80"
EOF
```

On **your own computer**, open a second terminal and run this SSH tunnel. Replace `user@server-address` with the same login you use to reach the server:

```sh
ssh -N -L 127.0.0.1:8080:127.0.0.1:8080 user@server-address
```

Leave that terminal open. After the site starts, open `http://localhost:8080` on your own computer. The browser's local connection travels through encrypted SSH to the server; your first password does not cross the open internet as plain HTTP.

**Temporary alternative: open port 8080.** For a throwaway first look without a tunnel, change the port line to `"8080:80"` and set the two address values to the server's address and its full HTTP URL. This exposes plain HTTP: do not sign in, enter a password or add private records this way. Use the tunnel or HTTPS before signing in, and restore the local-only port line before using HTTPS in step 7.

## 4. Start it

For a library-only site use this four-service command; starting everything also starts an unused payments helper.

```sh
docker compose up -d mariadb solr qdrant opensocial
docker compose logs -f opensocial
```

The first start downloads the images, builds the search server's image, and installs the site. Allow about ten minutes. The messages show it waiting for the database and search, then installing the community site. `=== FRESH INSTALL ===` means installation has begun. When you see `Starting Apache...`, press Ctrl+C to leave the logs; the site keeps running.

With the tunnel still open, open `http://localhost:8080` on your own computer. Check that the page loads: one log line does not prove the site is ready. If it is not available yet, wait a little and try again.

If the site exits with an error, it does not restart by itself. Read the messages to find the cause. After correcting it, start the site again with:

```sh
docker compose up -d opensocial
```

## 5. Sign in for the first time

There is no default password. The installer gave the administrator account (named `admin`) a random password that is never shown. Ask the site for a one-time sign-in link instead:

```sh
drush() { docker compose exec -T opensocial /var/www/html/vendor/bin/drush -r /var/www/html/html "$@"; }
docker compose exec -T opensocial sh -c \
  'exec /var/www/html/vendor/bin/drush -r /var/www/html/html user:login --uri="$SERVICE_URL_OPENSOCIAL"'
```

The command uses the address from your saved settings, as read when the site container started. You do not need to edit an address inside the command. Keep the tunnel open while using its `localhost` address.

Open the printed link on your own computer. **It works once.** It signs you in as the administrator and opens the account form at `/user/1/edit`, with the new-password fields. Now:

1. Set and save a password you will remember on that form.
2. Under *Administration › Configuration › System › Basic site settings*, check the site's name. Its email address is LocalNodes' own `noreply@localnodes.xyz` unless you set `MAIL_FROM` before the first start; change it to your own sender address when you set up mail (step 6).

The `drush` line defines a shortcut for this terminal session. In a new terminal, `cd` into the `localnodes` folder and define it again. Use it whenever this guide or a support answer says `drush`.

Never run `drush config:import` or `drush deploy` on this site.

### Create your first group

Your new site has no groups yet. With the tunnel open and while signed in, open [Create a group](http://localhost:8080/group/add). If you already use HTTPS, open `/group/add` on your site's address instead. Create a group for your community, then open its Import tab to add documents. The in-site guide's references to a welcome email and an existing group do not apply to this manual setup.

## 6. Add mail and the assistant

Put your keys in `.env`:

| Setting | What it is |
|---|---|
| `RESEND_API_KEY` | Your Resend API key. |
| `MAIL_FROM` | The sender address, at a domain verified in Resend (for example `noreply@community.example.org`). |
| `GEMINI_API_KEY` | Your Gemini key. |
| `GEMINI_KEY_TIER` | `paid` if the key belongs to a Google Cloud project with billing turned on. With a free key, the status report shows an error for the Gemini data tier: Google's free tier has its own data terms. |

For OpenRouter instead of Gemini, set `AI_PROVIDER=openrouter` and `OPENROUTER_API_KEY`, and leave `GEMINI_API_KEY` empty.

Then restart the site so it reads them:

```sh
docker compose up -d opensocial
```

The site's sender address was set when it was installed. If you set `MAIL_FROM` only now, also change the email address under *Basic site settings* to the same address.

The assistant's search index fills in by itself: a background worker in the site container runs every minute and works through the pages a few at a time.

## 7. Put it on your domain, with HTTPS

This page does not install a web server or proxy for you. You need one to provide HTTPS, or a helper who can set it up. An automatic setup command is being built to start the site and configure HTTPS for you; that part is not available yet.

1. Point your domain at the server.
2. Set `SERVICE_FQDN_OPENSOCIAL` in `.env` to the domain and `SERVICE_URL_OPENSOCIAL` to `https://` and the domain, and run `docker compose up -d opensocial`.
3. Put HTTPS in front of port 8080 with the web server or proxy you already use. With [Caddy](https://caddyserver.com), which gets the certificate by itself, the whole `Caddyfile` is:

   ```
   community.example.org {
       reverse_proxy 127.0.0.1:8080
   }
   ```

4. Keep the port line in `docker-compose.override.yml` as `"127.0.0.1:8080:80"` (step 3). If you used the temporary open-port alternative, change it back and run `docker compose up -d opensocial`. Open your HTTPS address and check the page loads before entering a password or inviting members.

## Running it

| Task | Command |
|---|---|
| See what the site did at start | `docker compose logs opensocial` |
| See the site's own recent errors | `drush watchdog:show` |
| Sign in without a password (for example before mail works) | Repeat the sign-in command in [step 5](#5-sign-in-for-the-first-time); it uses the address in your settings |
| Update to a newer LocalNodes release | `docker compose pull opensocial && docker compose up -d opensocial` (the site updates its database as it starts) |
| Stop everything | `docker compose stop` |
| Start the site again after it exits | `docker compose up -d opensocial` |
| Make a full copy of the site | `drush localnodes:export` prints the path of an archive inside the container. Copy it out with `docker compose cp opensocial:<that path> .`, then delete it inside with `docker compose exec opensocial rm <that path>` (the container's copy is lost whenever the container is recreated). Its `RESTORE.md` says how to bring the site up again |

Everything the site keeps is in this stack's Docker volumes (`docker volume ls`): the database, uploaded files, and the search indexes. Back them up the way you back up the rest of the server.

## Start again from nothing (deletes everything)

Only use this for a throwaway setup, before anyone adds records they want to keep. It deletes this stack's database, uploaded files and search indexes. It does not delete your `.env` settings file. Do not use it to fix a site people already use.

```sh
docker compose down -v
```

To start the empty site again with the same settings, return to step 4.

## Turning the demo content off

If you started with a demo (`DEMO_MODULE` other than `none`), changing the setting later does not remove the demo people and pages. For a throwaway setup only, follow [Start again from nothing](#start-again-from-nothing-deletes-everything), set `DEMO_MODULE=none` in `.env`, and return to step 4.
