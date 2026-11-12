# Export this site

The recorded site owner opens **Settings → Export this site**, confirms their current password, and requests a copy. Each download requires another password check. An owner who signs in without a password uses **Ask for export help**. Exporting does not close the site, cancel hosting or delete records.

Only the active account recorded in `localnodes.organizer_uid`, with the `sitemanager` role and `export localnodes site` permission, can request or download. Other managers receive notices but cannot export. If no active owner is recorded, site managers see an explanation with no export button.

The existing provisioning commands `localnodes:organizer-setup` and `localnodes:organizer-welcome` record the organizer during initial setup. They are not ownership-transfer commands. A server operator can use the existing Drush command `drush state:set localnodes.organizer_uid UID --input-format=integer` to record the intended account after checking its identity, active status, role and permission. There is no new owner command or web transfer control.

The image worker runs `drush localnodes:site-export-process` once per pass, outside its ordinary worker lock. This dedicated CLI command prepares copies, sends notices, retries unsent notices and removes expired artifacts. Ordinary HTTP cron never prepares an export. A self-hosted installation must keep this worker running; with a custom scheduler, run the command at least every 15 minutes and after startup. Use the same private mount and operating-system account as the web process. A worker restart marks interrupted preparations failed rather than resuming them.

Artifacts live in `private://site-exports/`, outside the resolved web root, with folders 0700 and archives 0600. This unmanaged tree is denied through ordinary private-file delivery, omitted from every site export/backup and refused as library attachments. The export request table is omitted from copied SQL. A restored site does not resume old requests.

A download expires 24 hours after it becomes ready. New downloads are refused at that time; the next worker pass removes the server copy. An already-open download can finish. Failed cleanup remains inaccessible and is retried. Last-success attribution remains after expiry or deletion. Downloaded copies are outside the site's control.

The first version estimates at most 5 GiB and requires three times the estimate plus 1 GiB free on the site's own volume before preparation and before publication. The estimate includes the database, uploaded files once, and one additional allowance of those file bytes for library attachments. Larger sites need export help. Space is not reserved across independently hosted sites.

Copies reuse the backup pause and locks. Members may briefly see a maintenance page. Arrange a final copy after agreed edits stop when moving servers. The included restore guide and readable-library guide explain the two leaving paths and their limits. The three preparation rows report completed archive parts, not a predicted finish time.

Export processing logs fixed outcomes only. Passwords, archive paths, links, account addresses and document words are not included. Apache access logging and Drupal's database logger redact export endpoint/private-artifact URLs. Notices link only to the settings page.
