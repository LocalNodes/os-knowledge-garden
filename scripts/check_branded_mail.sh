#!/usr/bin/env bash
# Live check of the LocalNodes mail template, header and footer (22.1-07).
#
# What it proves on the running DDEV site, with the real socialblue theme and
# social_swiftmail, which a Kernel test can't boot (socialbase's bootstrap base
# theme registers a shutdown function that needs the container):
# - every account mail (all nine user.mail keys), an activity notification, a
#   bulk (broadcast) email and the mailer test mail render in the LocalNodes
#   wrapper, name the community, and carry no Open Social link, slogan,
#   "#MadeToShare" or image;
# - the reset and both welcome subjects read the way Tom approved;
# - the organizer-created welcome mail has the wallet line only when the
#   localnodes_web3 module is enabled;
# - the front page footer carries Tom's wording and no "MadeToShare", and the
#   header shows the community's name as text.
#
# No real mail is sent. DDEV routes mail to Mailpit, and the script refuses to
# run if RESEND_API_KEY is set in the web container. Recipients are on
# mail.localnodes.xyz (a name no real member has; reserved domains such as
# example.com are dropped by localnodes_platform and would never arrive).
#
# Output is PASS or FAIL lines and a final RESULT line. It never prints a mail
# body or a one-time login link: every check reads the message in memory and
# prints only its own name. Exit status is 0 only when every check passed.
#
# Not covered here, and why (each needs seed content a fresh site lacks): the
# group invite, event invite and anonymous-event-enroll mails. They go through
# the same `email` theme hook and wrapper as the mails checked here.
#
# Usage, from the repo root of a running DDEV project:
#   bash scripts/check_branded_mail.sh
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

fail=0
pass_line() { echo "PASS $*"; }
fail_line() { echo "FAIL $*"; fail=1; }

if ! command -v ddev >/dev/null 2>&1; then
  echo "FAIL ddev is not installed"
  exit 1
fi

# Never send real mail: RESEND_API_KEY must be unset in the web container.
if ddev exec 'test -z "${RESEND_API_KEY:-}"' >/dev/null 2>&1; then
  pass_line "RESEND_API_KEY is unset in the web container"
else
  echo "FAIL RESEND_API_KEY is set in the web container; refusing to send mail"
  exit 1
fi

SEED_NAME="ln-brand-check"
SEED_MAIL="ln-brand-check@mail.localnodes.xyz"

cleanup() {
  ddev drush php:eval '
    $u = user_load_by_name("'"$SEED_NAME"'");
    if ($u) { $u->delete(); }
  ' >/dev/null 2>&1 || true
}
trap cleanup EXIT

# Apply the install-time helpers (idempotent), then rebuild caches so the
# theme registry sees the template and the footer block.
ddev drush php:eval '
  \Drupal::moduleHandler()->loadInclude("localnodes_platform", "install");
  \Drupal::service("config.installer")->installOptionalConfig();
  _localnodes_platform_configure_email();
  _localnodes_platform_configure_branding();
' >/dev/null
ddev drush cr >/dev/null 2>&1

SITE_NAME=$(ddev drush config:get system.site name --format=string)
WEB3=$(ddev drush php:eval 'echo \Drupal::moduleHandler()->moduleExists("localnodes_web3") ? "on" : "off";')

# One mail per family, each to its own recipient so Mailpit can tell them apart. Every recipient carries a nonce that is
# new on each run, and the checks below select the message by it: a message left by an earlier run (a crash, an interrupt)
# has another nonce and can never stand in for this run's (22.1 review, WR-08).
NONCE="$(date +%s)-${RANDOM}${RANDOM}"
export NONCE
# Remove what earlier runs left behind (best effort; the nonce is what makes the checks safe).
ddev exec "curl -s -X DELETE 'localhost:8025/api/v1/search?query=to%3Aln-brand-'" >/dev/null 2>&1 || true

ddev drush php:eval '
  $u = user_load_by_name("'"$SEED_NAME"'");
  if (!$u) {
    $u = \Drupal\user\Entity\User::create(["name" => "'"$SEED_NAME"'", "mail" => "'"$SEED_MAIL"'", "status" => 1]);
    $u->save();
  }
  $mailer = \Drupal::service("plugin.manager.mail");
  $from = "noreply@localnodes.xyz";
  $keys = ["cancel_confirm", "password_reset", "register_admin_created", "register_no_approval_required", "register_pending_approval", "register_pending_approval_admin", "status_activated", "status_blocked", "status_canceled"];
  foreach ($keys as $key) {
    $mailer->mail("user", $key, "ln-brand-'"$NONCE"'-" . $key . "@mail.localnodes.xyz", "en", ["account" => $u], $from);
  }
  $body = ["#markup" => "<div class=\"post\"><p>Someone commented on a topic.</p></div>"];
  $mailer->mail("activity_send_email", "activity_send_email", "ln-brand-'"$NONCE"'-activity@mail.localnodes.xyz", "en", ["subject" => "A new comment", "body" => $body], $from);
  $mailer->mail("social_email_broadcast", "social_email_broadcast", "ln-brand-'"$NONCE"'-broadcast@mail.localnodes.xyz", "en", ["subject" => "News from the group", "body" => $body], $from);
  $mailer->mail("social_swiftmail", "test", "ln-brand-'"$NONCE"'-test@mail.localnodes.xyz", "en", ["site_name" => \Drupal::config("system.site")->get("name")], $from);
' >/dev/null

# Read every message from Mailpit and check it in memory. Prints PASS or FAIL
# lines only; a body never leaves this process.
checks=$(WEB3="$WEB3" SITE_NAME="$SITE_NAME" NONCE="$NONCE" python3 - <<'PY'
import json, os, subprocess, sys, time

web3 = os.environ["WEB3"] == "on"
site = os.environ["SITE_NAME"]
nonce = os.environ["NONCE"]
WAIT_SECONDS = 45
wallet = "You can connect a wallet later from your account page."

def get(path):
    out = subprocess.run(["ddev", "exec", "curl -s 'localhost:8025" + path + "'"],
                         capture_output=True, text=True, check=True).stdout
    return json.loads(out)

families = {
    "cancel_confirm": None, "password_reset": "Reset your password for " + site,
    "register_admin_created": "Welcome to " + site,
    "register_no_approval_required": "Welcome to " + site,
    "register_pending_approval": None, "register_pending_approval_admin": None,
    "status_activated": None, "status_blocked": None, "status_canceled": None,
    "activity": "A new comment", "broadcast": "News from the group", "test": None,
}
gone = ["getopensocial.com", "Open Social", "Create digital spaces", "MadeToShare", "<img"]
footer = "Sent by " + site + " · a community on LocalNodes"
bad = 0
ids = []

for name, subject in families.items():
    addr = "ln-brand-" + nonce + "-" + name + "@mail.localnodes.xyz"
    # Mail is delivered a moment after php:eval returns: poll for this run's message instead of sleeping a fixed time.
    msgs = []
    deadline = time.monotonic() + WAIT_SECONDS
    while True:
        msgs = get("/api/v1/search?query=" + "to%3A" + addr + "&limit=5").get("messages", [])
        if msgs or time.monotonic() >= deadline:
            break
        time.sleep(1)
    if not msgs:
        print("FAIL " + name + ": no message for this run's recipient reached Mailpit within " + str(WAIT_SECONDS) + " s")
        bad = 1
        continue
    msg = get("/api/v1/message/" + msgs[0]["ID"])
    ids.append(msgs[0]["ID"])
    html = msg.get("HTML", "")
    problems = []
    if "<!-- localnodes-email -->" not in html:
        problems.append("not the LocalNodes wrapper")
    if site not in html:
        problems.append("community name missing")
    if footer not in html:
        problems.append("footer wording missing")
    for g in gone:
        if g in html:
            problems.append("has " + g)
    if subject is not None and msg.get("Subject") != subject:
        problems.append("subject is not the approved one")
    if name == "register_admin_created":
        has = wallet in html
        if web3 and not has:
            problems.append("wallet line missing on a Web3 site")
        if not web3 and has:
            problems.append("wallet line on a site with Web3 off")
    if problems:
        print("FAIL " + name + ": " + "; ".join(problems))
        bad = 1
    else:
        extra = ""
        if name == "register_admin_created":
            extra = " (wallet line " + ("present, Web3 on" if web3 else "absent, Web3 off") + ")"
        print("PASS " + name + ": wrapper, community name, footer, no Open Social" + extra)

# Remove only the messages this script made. A failed delete is a failure: stale mail is what the nonce guards against.
if ids:
    done = subprocess.run(["ddev", "exec", "curl -s -o /dev/null -w '%{http_code}' -X DELETE -H 'Content-Type: application/json' -d '" +
                           json.dumps({"IDs": ids}) + "' localhost:8025/api/v1/messages"],
                          capture_output=True, text=True)
    if done.returncode != 0 or done.stdout.strip() != "200":
        print("FAIL cleanup: could not delete this run's messages from Mailpit (HTTP " + done.stdout.strip() + ")")
        bad = 1
sys.exit(bad)
PY
) && mail_ok=1 || mail_ok=0
echo "$checks"
[ "$mail_ok" = "1" ] || fail=1

# The front page: footer wording, no "#MadeToShare", the name as text in the header.
front=$(ddev exec "curl -s http://localhost/")
if grep -q "MadeToShare" <<<"$front"; then
  fail_line "front page: MadeToShare is still on the page"
else
  pass_line "front page: no MadeToShare"
fi
if grep -q 'class="ln-site-footer"' <<<"$front" \
  && grep -q "a community on" <<<"$front" \
  && grep -q "LocalNodes" <<<"$front" \
  && grep -q ">Open source</a>" <<<"$front" \
  && grep -qF "$SITE_NAME" <<<"$front"; then
  pass_line "front page: footer reads the community name, a community on LocalNodes, Open source"
else
  fail_line "front page: footer wording is missing"
fi
if grep -q 'class="ln-site-name"' <<<"$front"; then
  pass_line "front page: header shows the community name as text"
else
  fail_line "front page: header does not show the community name as text"
fi

# The branding flag the plan's key_links name.
if [ "$(ddev drush config:get social_swiftmail.settings remove_open_social_branding --format=string)" = "1" ]; then
  pass_line "social_swiftmail remove_open_social_branding is on"
else
  fail_line "social_swiftmail remove_open_social_branding is off"
fi

if [ "$fail" = "0" ]; then
  echo "RESULT: PASS"
else
  echo "RESULT: FAIL"
  exit 1
fi
