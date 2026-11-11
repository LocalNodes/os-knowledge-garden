# Run LocalNodes on your own server

This guide starts a new, empty LocalNodes community site on a server you control, with Docker Compose. It is written for someone who has run a Linux server before but has never used Drupal (the software LocalNodes is built on). You do not need anything from LocalNodes to follow it.

If you are moving an existing LocalNodes site to your server, follow this guide up to the end of step 4, then go on with the `RESTORE.md` that came inside your export.

## What you need

- A Linux server with Docker and Docker Compose v2 (`docker compose version` works), 4 GB of memory or more, and 10 GB of free disk.
- `git` and `openssl`.
- A domain name pointed at the server, once people will use the site. You can try it first on the server's address.
- Later, not for the first start:
  - **Mail**: an account with [Resend](https://resend.com), its API key, and your domain verified there. Without it the site runs, but sends no mail (no sign-up confirmations, password resets or notifications).
  - **The assistant**: a Google Gemini API key from [Google AI Studio](https://aistudio.google.com/apikey), or an [OpenRouter](https://openrouter.ai) key. Without one, everything works except the assistant and its search.

Every command below is run on the server.

## 1. Get the software

```sh
git clone https://github.com/localnodes/os-knowledge-garden.git localnodes
cd localnodes
```

From here on, run every command in this `localnodes` folder.

## 2. Write your settings

The settings live in a file named `.env`. Start from the example, and let `openssl` make the database passwords and the hash salt:

```sh
cp .env.example .env
chmod 600 .env
sed -i \
  -e "s/^DB_PASSWORD=$/DB_PASSWORD=$(openssl rand -hex 16)/" \
  -e "s/^DB_ROOT_PASSWORD=$/DB_ROOT_PASSWORD=$(openssl rand -hex 16)/" \
  -e "s/^DRUPAL_HASH_SALT=$/DRUPAL_HASH_SALT=$(openssl rand -hex 32)/" \
  .env
```

Then open `.env` in an editor (`nano .env`) and fill in the rest of the first block:

| Setting | What to put there |
|---|---|
| `SERVICE_FQDN_OPENSOCIAL` | Your domain, without `https://`. While you try the site on the server's address, put that address here (for example `203.0.113.7`). The site refuses requests for any other name. |
| `SERVICE_URL_OPENSOCIAL` | Required. The full address people open, with `https://` (for example `https://community.example.org`). Links in the mail the site sends use it; without it the site guesses `https://` and the domain above. While you try the site on the server's address, put `http://` and that address with its port here (for example `http://203.0.113.7:8080`). |
| `DRUPAL_SITE_NAME` | Your community's name. |
| `DRUPAL_ADMIN_EMAIL` | Your own email address, for the first administrator account. |
| `DEMO_MODULE` | Leave it as `none` for an empty site. Any other value fills the site with made-up demo people and pages, which is only for looking around. |

You can leave the mail and AI keys empty for now (step 6). Leave every setting that is not in `.env.example` unset. In particular, never set `ORGANIZER_EMAIL` or any `PROVISION_*` variable: they belong to LocalNodes' own sign-up service.

Do not change `DB_PASSWORD`, `DB_ROOT_PASSWORD` or `DRUPAL_HASH_SALT` after the first start. The database keeps the first passwords, and a new hash salt signs everyone out.

## 3. Make the site reachable

The stack does not publish a port by itself. This file publishes the site on port 8080:

```sh
cat > docker-compose.override.yml <<'EOF'
services:
  opensocial:
    ports:
      - "8080:80"
EOF
```

When you put HTTPS in front of it (step 7), change `"8080:80"` to `"127.0.0.1:8080:80"` so that only your proxy can reach port 8080.

## 4. Start it

```sh
docker compose up -d mariadb solr qdrant opensocial
docker compose logs -f opensocial
```

The first start downloads the images, builds the search server's image, and installs the site. It takes about ten minutes. Wait for the line `Starting Apache...`, then press Ctrl+C (the site keeps running). Above it, the log says `=== FRESH INSTALL ===`.

The stack also lists a `gas-sponsor-relayer` service. It is only for the optional web3 features, and the command above leaves it out on purpose.

Open `http://<your server's address>:8080`. You see the site's front page.

## 5. Sign in for the first time

There is no default password. The installer gave the administrator account (named `admin`) a random password that is never shown. Ask the site for a one-time sign-in link instead:

```sh
drush() { docker compose exec -T opensocial /var/www/html/vendor/bin/drush -r /var/www/html/html "$@"; }
drush user:login --uri=http://<your server's address>:8080
```

Open the link it prints (it works once, for a short time). You are signed in as the administrator. Now:

1. Click your avatar, then *Settings*, and set a password you will remember.
2. Under *Administration › Configuration › System › Basic site settings*, check the site's name. Its email address is LocalNodes' own `noreply@localnodes.xyz` unless you set `MAIL_FROM` before the first start; change it to your own sender address when you set up mail (step 6).

The `drush` line defines a shortcut for this terminal session. In a new terminal, `cd` into the `localnodes` folder and define it again. Use it whenever this guide or a support answer says `drush`.

Never run `drush config:import` or `drush deploy` on this site.

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

1. Point your domain at the server.
2. Set `SERVICE_FQDN_OPENSOCIAL` in `.env` to the domain and `SERVICE_URL_OPENSOCIAL` to `https://` and the domain, and run `docker compose up -d opensocial`.
3. Put HTTPS in front of port 8080 with the web server or proxy you already use. With [Caddy](https://caddyserver.com), which gets the certificate by itself, the whole `Caddyfile` is:

   ```
   community.example.org {
       reverse_proxy 127.0.0.1:8080
   }
   ```

4. Change the port line in `docker-compose.override.yml` to `"127.0.0.1:8080:80"` (step 3) and run `docker compose up -d opensocial`.

## Running it

| Task | Command |
|---|---|
| See what the site did at start | `docker compose logs opensocial` |
| See the site's own recent errors | `drush watchdog:show` |
| Sign in without a password (for example before mail works) | `drush user:login --uri=https://<your domain>` |
| Update to a newer LocalNodes release | `docker compose pull opensocial && docker compose up -d opensocial` (the site updates its database as it starts) |
| Stop everything | `docker compose stop` |
| Make a full copy of the site | `drush localnodes:export` prints the path of an archive inside the container. Copy it out with `docker compose cp opensocial:<that path> .`, then delete it inside with `docker compose exec opensocial rm <that path>` (the container's copy is lost whenever the container is recreated). Its `RESTORE.md` says how to bring the site up again |

Everything the site keeps is in this stack's Docker volumes (`docker volume ls`): the database, uploaded files, and the search indexes. Back them up the way you back up the rest of the server.

While you are still setting up, starting over is safe: `docker compose down -v` deletes this stack's database, files and indexes, and you begin again at step 4. Once people use the site, never run it.

## Turning the demo content off

If you started with a demo (`DEMO_MODULE` other than `none`), changing the setting later does not remove the demo people and pages. Start over instead: `docker compose down -v`, set `DEMO_MODULE=none` in `.env`, and run step 4 again.
