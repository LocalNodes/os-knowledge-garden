# Export this site

The recorded site owner opens **Settings → Export this site** and confirms each request, download and deletion of a prepared download. An owner with a password uses that current password. With no password and a recorded sign-in address, the owner signs a fresh message for that action. The existing sign-in panel can open in place to reconnect the same account; it does not change the site session. An emailed sign-in link or other session alone never confirms an export. If signing is unavailable, refused, or unsupported, or the account has neither credential, use **Ask for export help**. Exporting does not close the site, cancel hosting or delete records.

Only the active account recorded in `localnodes.organizer_uid`, with the `sitemanager` role and `export localnodes site` permission, can request or download. Other managers receive notices but cannot export. If no active owner is recorded, site managers see an explanation with no export button.

The existing provisioning commands `localnodes:organizer-setup` and `localnodes:organizer-welcome` record the organizer during initial setup. They are not ownership-transfer commands. A server operator can use the existing Drush command `drush state:set localnodes.organizer_uid UID --input-format=integer` to record the intended account after checking its identity, active status, role and permission. There is no new owner command or web transfer control.

The image worker runs `drush localnodes:site-export-process` once per pass, outside its ordinary worker lock. This dedicated CLI command prepares copies, sends notices, retries unsent notices and removes expired artifacts. Ordinary HTTP cron never prepares an export. A self-hosted installation must keep this worker running; with a custom scheduler, run the command at least every 15 minutes and after startup. Use the same private mount and operating-system account as the web process. A worker restart marks interrupted preparations failed rather than resuming them.

Artifacts live in `private://site-exports/`, outside the resolved web root, with folders 0700 and archives 0600. This unmanaged tree is denied through ordinary private-file delivery, omitted from every site export/backup and refused as library attachments. The export request table is omitted from copied SQL. A restored site does not resume old requests.

A download expires 24 hours after it becomes ready. New downloads are refused at that time; the next worker pass removes the server copy. An already-open download can finish. Failed cleanup remains inaccessible and is retried. Last-success attribution remains after expiry or deletion. Downloaded copies are outside the site's control.

The first version estimates at most 5 GiB and requires three times the estimate plus 1 GiB free on the site's own volume before preparation and before publication. The estimate includes the database, uploaded files once, and one additional allowance of those file bytes for library attachments. Larger sites need export help. Space is not reserved across independently hosted sites.

Copies reuse the backup pause and locks. Members may briefly see a maintenance page. Arrange a final copy after agreed edits stop when moving servers. The included restore guide and readable-library guide explain the two leaving paths and their limits. The three preparation rows report completed archive parts, not a predicted finish time.

Export processing logs fixed outcomes only. Passwords, archive paths, links, account addresses and document words are not included. Apache access logging and Drupal's database logger redact export endpoint/private-artifact URLs. Notices link only to the settings page.

Signature confirmation needs the siwe_login public signer API (`SignerAttachments`, `Drupal.siwe.wallet` and `Drupal.siwe.signMessage`) and its separately released browser connector. Until that API is installed, the confirmation page shows export help. While an opening or signature is pending, Cancel stops that attempt in place so the owner can retry or ask for help; no short timer mistakes a slow first load for a failure. Otherwise Cancel returns to export settings. Password confirmation also works with the optional sign-in module switched off.

Each signature challenge expires after at most five minutes. It is bound to this browser session, current account and recorded address, site, action and prepared-copy ID where applicable. A verification attempt consumes it; a later action needs a new message. Acknowledgement remains required, and password/signature failures share the existing five-failures-per-hour account limit. Challenge responses are private POSTs with no-store headers. Messages and signatures are erased from submitted form state and are not logged; export verification also suppresses the sign-in library’s global exception diagnostics for that synchronous call only; pending context expires in five minutes and is excluded from copied SQL with the other temporary records.

A sign-in added to an existing account must wait 24 hours before confirming an
export request, download or deletion. Its recorded attachment date persists
across sessions. The export screen and server-side proof checks enforce this
wait through the sign-in module's attachment-history service. Existing password
confirmation remains available. An address without a new attachment record
keeps its existing confirmation path.

Later: contract-account verification. The current verifier accepts ordinary account signatures; it does not verify contract accounts.
