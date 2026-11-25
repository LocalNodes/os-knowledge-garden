# Keeping your site up to date

Keeping a LocalNodes site current takes four things: a checked release, a copy of the site you can restore from, an update of one site at a time, and a check afterwards. For a site run for you by a host, these steps belong to the host. For a site you run yourself (see [Run LocalNodes on your own server](../SELF-HOST.md)), they belong to you or your operator.

The weekly security check reports problems. It does not update anything: someone still has to make an update and apply it to each site.

## 1. Read the security check

The **Security advisories** workflow runs on Thursdays at 02:00 UTC and can also be started by hand. It checks the package versions in `composer.lock`, which include Drupal, Open Social and the contributed modules. The workflow is `.github/workflows/security-advisories.yml`; the check itself is `scripts/ci/advisory-check.sh`.

The result is about the lock file in the repository. It does not look at your running site. Find out which release your site runs (step 3 shows how) and compare it with the release the check was run on.

- **Red** means an advisory affects a locked package, or the check could not complete its lookup. Read the output to tell which.
- **Green** means no advisory outside the accepted list. Abandoned packages are reported but do not turn the check red.
- Advisories accepted on purpose are listed under `config.audit.ignore` in `composer.json`, each with a reason and the condition that ends it. Green allows them, so read that list too.

## 2. Prepare a release

This step is for whoever builds releases.

In a development checkout, update the affected dependencies and review the changes to `composer.json` and `composer.lock`, including any patches. Keep the lock file with the release so the build installs the versions you reviewed. Do not update dependencies inside a running site's container.

Run the advisory check against that lock and read the result. The release commit's tests must pass: the image workflow, `.github/workflows/build-image.yml`, waits for both of its test sets, which cover access and citation checks using recorded responses and mocks. The advisory check is a separate workflow and does not hold back publication, so look at both.

Pushes to the main branch publish an image with two tags: the short commit tag (seven characters) and `latest`, which moves. The image installs its packages from the lock file, and its labels carry the commit it was built from. Write down the commit tag you mean to deploy and the tag the site runs now.

## 3. Update one site first

Pick one site, update it, and check it before you update any other. For each site:

**a. Record what it runs now.** Use the name `docker compose ps` shows for the `opensocial` container:

```sh
docker inspect --format '{{.Config.Image}} {{.Image}}' "<container name>"
docker inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "<container name>"
```

The first line prints the image name and the image id. The name alone does not identify the release: with the default `latest` tag it stays `…:latest` after `latest` has moved on. The second line prints the commit the image was built from. Its first seven characters are the commit tag of the release the site runs now: write that tag down as the way back to this code. The same commit is in the file `/var/www/html/BUILD_COMMIT` inside the container.

**b. Take a fresh copy.** Do not rely on last night's.

- On a server that has the nightly backup installed (a host that has run `scripts/backup/install-backup-host.sh` and has a configuration file for the site), run the existing command, with the site's own configuration name:

  ```sh
  localnodes-backup "/etc/localnodes-backup/<name>.conf"
  ```

  The site is closed (members see a maintenance page) while the database and files are copied. The copy is encrypted and a separate record holds the site's secrets. Require a successful exit and an `ok` status line for the right site and capture time. The `ok` line says the copy was stored and read back; it does not show that a restore works. Try a restore on a separate test site before you depend on it, and keep the private decryption key off the site's server.
- On a plain Compose installation from `docs/SELF-HOST.md`, the `localnodes-backup` command does not exist. Take the same kind of copy by hand, with the site closed while it is taken. Do not use `drush localnodes:export` for this copy: it leaves out direct messages and pending work, so it is not a full copy. From the site's Compose directory:

  ```sh
  uuid="$(docker compose exec -T opensocial /var/www/html/vendor/bin/drush -r /var/www/html/html config:get system.site uuid --format=string)"
  dest="/tmp/localnodes-preupdate-$(date -u +%Y%m%d-%H%M%S)"
  echo "backup folder in the container: $dest"
  docker compose exec -T -u www-data -e HOME=/tmp opensocial /var/www/html/vendor/bin/drush -r /var/www/html/html \
    localnodes:backup --destination="$dest" --expect-site-uuid="$uuid"
  ```

  Each run writes to a new folder in the container, named by the time it started, and the command refuses a folder that is not empty. Write down the folder name the second line prints.

  The site is closed (members see a maintenance page, and the background worker skips its work) from the start of the copy until it is complete, then opens again. Require a successful exit and a last line that names the site's id, the archive and its `archive_sha256`; if the command fails, it deletes what it wrote and says whether the site is open again.

  The copy is still inside the container, where it is lost whenever the container is recreated. Copy it to a new folder on the server, check it, and only then delete the container's copy. Put the `archive` and `archive_sha256` values from that last line into the first two lines, and the backup's folder name into the third (it is already set if you are in the same terminal), then run the rest as one command from the site's Compose directory:

  ```sh
  archive='<archive from the last line>'
  expected='<archive_sha256 from the last line>'
  dest='<the backup folder in the container>'
  dir="$(mktemp -d ./localnodes-preupdate-XXXXXX)" &&
  docker compose cp "opensocial:$dest/." "$dir" &&
  echo "$expected  $dir/$archive" | sha256sum -c - &&
  docker compose exec -T -u 0 opensocial rm -rf "$dest" &&
  echo "copy kept in $dir"
  ```

  Each run makes a new folder that only you can read, so an earlier copy is never overwritten or mixed in. Each step runs only if the one before it worked. The checksum line must print the archive's name followed by `OK`, and the last line names the folder the copy is in. If the copy fails (for example because the server's disk is full) or the checksum does not match, the command stops before deleting anything, and the container's copy is still there: delete the new folder, fix the cause, and run the block again.

  Keep the site's `.env` file and any Compose override files with the copy, stored where only you can read them: a restore needs the same hash salt and passwords, and `.env` holds them. The archive's own `RESTORE.md` says how to bring the copy up on a new site. Before you update, check that the copy restores: follow that `RESTORE.md` on a separate test machine, not on the site you are about to update.

  **If the backup was interrupted** (the terminal closed, the connection dropped or the command was stopped), you cannot tell from your side how it ended. It may still be running inside the container, it may have stopped and left part of a copy, or it may have finished and left a complete one. First see whether the site is closed:

  ```sh
  docker compose exec -T -u www-data opensocial localnodes-pause status
  ```

  It prints `open`, or `paused` with a reason and an end time in UTC.

  - **`paused reason=backup`:** a backup's pause ends by itself at its end time, at most 90 minutes after the backup started, even when the backup was stopped before it could open the site. Wait for that time to pass. Do not lift the pause yourself: the backup may still be running.
  - **Any other reason** (for example `export` or `restore`): the pause belongs to another operation. Leave it alone. An operation that succeeds lifts its own pause. A restore that failed leaves the site closed with no end time on purpose; see [Recover if the update fails](#5-recover-if-the-update-fails).
  - **`open`:** go on to the next step.

  Once the status is `open`, check that no backup is still running: `docker compose top opensocial` lists the container's processes, and a running backup shows `localnodes:backup` in its line. When none does, do not delete anything yet: the earlier folder may hold the only complete copy. Run the backup again from the beginning. It writes to a new folder, so the earlier one is kept. Copy and check the new one as above.

  Only after the new copy is on the server and its checksum matched, list the folders the earlier runs left behind and delete them by name:

  ```sh
  docker compose exec -T -u 0 opensocial sh -c 'ls -d /tmp/localnodes-preupdate-*'
  ```

  For each folder in the list, put its full path between the quotes and run:

  ```sh
  earlier='/tmp/localnodes-preupdate-00000000-000000'
  docker compose exec -T -u 0 opensocial rm -rf "$earlier"
  ```

  If the list says `No such file or directory`, no earlier folder is left.

  If you saw the earlier run's last line (the site's id, the archive and its `archive_sha256`), its folder holds a complete copy. You may copy and check that one with the block above, using its folder name, instead of running the backup again.

**c. Set the new image and redeploy.** Set `IMAGE_TAG` in the site's `.env` to the commit tag you chose (unset means `latest`). From the site's Compose directory:

```sh
docker compose pull opensocial && docker compose up -d opensocial
docker compose logs opensocial
```

`docker compose up -d` reports success as soon as the container is created. A site that then stops still shows success there, so read the logs.

On a site whose installation finished, the log shows `=== EXISTING INSTALL ===`, then `Running database updates...` (with a table of any updates and a notice for each one it ran), a cache rebuild, `=== EXISTING INSTALL READY ===` and `Starting Apache...`. Startup applies the database updates and rebuilds caches before the web server starts. In one test it ran several updates from a few modules. The container is replaced by the update, so its log holds this start only. If the container was only restarted, older starts are in the log too, so read from the last `=== EXISTING INSTALL ===`.

An installation that never finished may show `=== RESUMING INSTALL (no completion marker) ===` and finish its setup. If the log says the database is not empty and that it will not install over it, investigate. That message suggests deleting volumes for a throwaway site. Never do that to a site with members' records in it.

Your checkout of the repository and its `docker-compose.yaml` are not part of the image, and `docker compose pull` does not update them. A release may come with changes to the compose file (for example new settings passed to the site). When you update your checkout with `git pull`, read what changed in `docker-compose.yaml` and `.env.example` before you restart.

A redeploy asks the registry for the image every time (the compose file sets `pull_policy: always`). A tag that is no longer in the registry cannot be redeployed, so keeping an old image on the server is not enough on its own.

## 4. Check the site after the update

Check the updated site by hand before you update another one. The commands below use the `drush` shortcut from `docs/SELF-HOST.md` (step 5 of that guide defines it); a host runs the same commands in the site's container. None of them changes the site.

**On the server:**

1. **The image.** The two commands from step 3a now show the tag you set in `IMAGE_TAG`, a new image id, and a commit whose first seven characters are that tag.
2. **Drupal starts.** `drush status --field=bootstrap` prints `Successful`.
3. **No database update is waiting.** `drush updatedb:status` says that no database updates are required. It only lists updates; it does not run them.
4. **The background worker runs.** Wait a few minutes after the start, then read `docker compose logs opensocial` from the newest `=== EXISTING INSTALL ===` and look for repeated worker failures.
5. **Search is caught up.** `drush search-api:status` lists each search index. The ordinary search indexes (`social_all`, `social_content`, `social_groups`, `social_users`) should reach 100% once the worker has run. The assistant's indexes (`social_posts`, `social_comments`) are filled only on a site with an AI key set: there, run the command again after a while and check that the number of items left goes down. How long they take depends on how much the site holds. On a site with no AI key they do not fill; that alone is not a failed update.
6. **Recent errors.** `drush watchdog:show` lists the site's own recent errors. Read anything logged since the update.

**In a browser:**

7. The home page and the sign-in page open at the site's address.
8. Sign in as an ordinary member (not the recovery administrator). Open a group page and download an attachment.
9. Check that private content stays closed: open a private group's page as a member who is not in that group, or signed out.
10. Search for a page you know is there.
11. Where the assistant is on, ask it a question whose answer you know and open the source it cites. A site with no AI key set has an assistant that does not answer: a new site set up from `docs/SELF-HOST.md` is in that state until you add a key (step 6 of the guide).

Write down the result before updating another site.

## 5. Recover if the update fails

Keep members out while you decide how to recover, for example with `docker compose stop opensocial`.

**Going back to an earlier release needs the copy of the database and files taken before the update (step 3b). An image tag on its own is not a way back.** The rest of this section says why, and what a restore loses.

**Setting `IMAGE_TAG` back to the earlier tag restores earlier code only. It does not undo the database updates**, and an earlier image may refuse to start on an updated database. In one test, a site was updated across several releases and then returned to the earlier tag. The database kept its new update levels. The earlier image stopped on startup with a message that Drupal did not bootstrap and that the database was not empty, and the container stayed stopped, although `docker compose up -d` itself reported success. Whether an earlier image starts depends on what the update changed (in that test, a module had moved to a new location). Do not delete volumes to make it start.

Setting `IMAGE_TAG` to the newer tag again and redeploying brought the same site back: it started, no database update was waiting, and its pages answered. The failed start had not touched the database. If the new release is the problem and the earlier image will not start, recovery needs the copy from step 3b.

Restoring replaces the database and files with the copy. Posts, edits, uploads, memberships, settings and other changes saved after the copy was taken are lost, including changes between the copy and the redeploy. An earlier image on its own does not discard those records, but may not be able to read them.

Two ways to restore. The descriptions below come from the tools' own code and documentation; neither was run when this page was written.

- **A copy taken by hand in step 3b** (or one from `drush localnodes:export`). Follow the `RESTORE.md` inside the archive. It starts a new empty site, at the image tag the copy was made from, and loads the copy into it. Do not use an image older than the copy.
- **A nightly backup set.** `scripts/backup/restore-site.sh` runs on the operator's own machine, with the registered target, the storage configuration, the chosen backup set and the private decryption key (`restore-site.sh` has the full list of arguments at the top of the file). It checks the set's checksums and the site identities before it changes anything, then closes the site, replaces the database and files, runs `updatedb` and a cache rebuild, empties the search indexes (the worker fills them again), and opens the site. If it fails after replacement has begun, the site stays closed, and the tool prints how to lift that pause. Follow that instruction and do not lift the pause on a half-loaded site.

  The tool works through the site's running container: it reads the site's identity with `drush` there and closes the site with the `localnodes-pause` command from the image. So the container it targets must be running, and its image must have that command. Older images may not have it: an image from the week before the one in the test above did not. Because the tool runs `updatedb` after loading, a copy taken before an update, restored under the newer image, comes back with the newer image's database updates applied again. This page does not give a tested order for returning to old code with old data on such a host. Work out that order on a test site first.

## 6. A current limit

Changes to the settings defaults that ship with LocalNodes do not reach a site that is already installed. Those defaults are applied when a module is installed. On every later start, the site updates its database and caches without importing the shipped configuration. A release that needs a changed setting on existing sites will say so in its release instructions, naming each setting and the value it should have.

What you do as the operator: read the instructions that come with the release, and change those settings yourself, on a test site first. Do not run `drush config:import` or `drush deploy` on a site: the site's settings live in its own database, and the shipped export does not match it (`docs/SELF-HOST.md` says the same).
