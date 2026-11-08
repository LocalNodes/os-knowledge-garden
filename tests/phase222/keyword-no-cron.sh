#!/usr/bin/env bash
# Lane check (22.2 D-05): a member's newly saved public Topic shows up in
# keyword search on the lane's real Solr without cron running.
#
# Usage (from the lane's repo root, DDEV running, site installed):
#   bash tests/phase222/keyword-no-cron.sh <host>
#   bash tests/phase222/keyword-no-cron.sh fresh3-p222.ddev.site
#
# Steps, each printing one "PASS <step>" or "FAIL <step>: <why>" line:
#   1. tokens       make a query word W and a title-only token T. T is never
#                   sent as a query, so the search page echoing the query back
#                   can't make the check pass.
#   2. control      GET https://<host>/search/content/W (anonymous) must not
#                   contain T yet. This proves the final check can fail.
#   3. cron_last    record `drush state:get system.cron_last`.
#   4. save         `drush php:eval` creates a verified member and a published,
#                   public Topic it owns, titled "W T", with W in the body too.
#                   The save runs as that member. Drush ends its request the
#                   way a web request does, so search_api's post-request
#                   indexing runs when the command exits.
#   5. search hit   without running cron, the same search URL now contains T.
#                   Polled for up to 10 seconds to allow for Solr's commit.
#   6. cron_last    unchanged.
#   7. cleanup      delete the Topic, then the member.
#
# Exit status: 0 when every step passed, 1 when any step failed, 2 on bad
# usage. Cleanup also runs on early exit.
# Used by plans 22.2-04, 22.2-05 and 22.2-14.
set -uo pipefail

HOST="${1:-}"
if [[ -z "$HOST" ]]; then
  echo "Usage: $0 <host>" >&2
  exit 2
fi
cd "$(git rev-parse --show-toplevel)" || { echo "FAIL setup: not inside a git checkout"; exit 1; }

FAIL=0
pass() { echo "PASS $*"; }
fail() { echo "FAIL $*"; FAIL=1; }

# drush output without carriage returns or trailing blank lines.
drush_out() { ddev drush "$@" 2>/dev/null | tr -d '\r'; }

UID_NEW=""
NID_NEW=""
cleanup() {
  if [[ -n "$NID_NEW$UID_NEW" ]]; then
    local php='$s = \Drupal::entityTypeManager();'
    [[ -n "$NID_NEW" ]] && php+=' if ($n = $s->getStorage("node")->load('"$NID_NEW"')) { $n->delete(); }'
    [[ -n "$UID_NEW" ]] && php+=' if ($u = $s->getStorage("user")->load('"$UID_NEW"')) { $u->delete(); }'
    php+=' echo "deleted";'
    if [[ "$(drush_out php:eval "$php" | tail -n1)" == "deleted" ]]; then
      pass "cleanup (topic ${NID_NEW:-none}, member ${UID_NEW:-none} deleted)"
    else
      fail "cleanup: could not delete topic ${NID_NEW:-none} / member ${UID_NEW:-none}"
    fi
    NID_NEW=""
    UID_NEW=""
  fi
}
trap 'cleanup' EXIT

# 1. Tokens: lower-case letters and digits only, so tokenizers keep them whole.
W="p222w${RANDOM}${RANDOM}"
T="p222t${RANDOM}${RANDOM}"
URL="https://${HOST}/search/content/${W}"
pass "tokens (query word $W, title-only token $T)"

# 2. Control: T is not on the page before the save.
body="$(curl -sk --max-time 60 "$URL")"
if [[ -z "$body" ]]; then
  fail "control: empty response from $URL"
  exit 1
elif grep -qF "$T" <<<"$body"; then
  fail "control: $T is already on $URL before the save, so the check can't prove anything"
  exit 1
else
  pass "control ($T absent from /search/content/$W before the save)"
fi

# 3. cron_last before.
cron_before="$(drush_out state:get system.cron_last | tail -n1)"
pass "cron_last recorded ($cron_before)"

# 4. A member saves a published, public Topic titled "W T".
php='use Drupal\node\Entity\Node; use Drupal\user\Entity\User;
$u = User::create(["name" => "p222m_'"$T"'", "mail" => "p222m_'"$T"'@example.com", "status" => 1, "roles" => ["verified"]]);
$u->save();
\Drupal::service("account_switcher")->switchTo($u);
$n = Node::create(["type" => "topic", "title" => "'"$W $T"'", "body" => ["value" => "<p>'"$W"' keyword check</p>", "format" => "basic_html"], "field_content_visibility" => "public", "uid" => $u->id(), "status" => 1]);
$n->save();
echo "ids ", $u->id(), " ", $n->id(), PHP_EOL;'
ids="$(drush_out php:eval "$php" | grep '^ids ' | tail -n1)"
read -r _ UID_NEW NID_NEW <<<"$ids"
if [[ -n "${UID_NEW:-}" && -n "${NID_NEW:-}" ]]; then
  pass "save (member $UID_NEW saved topic $NID_NEW \"$W $T\")"
else
  fail "save: drush did not report the new member and topic ids"
  exit 1
fi

# 5. The same search URL now finds the Topic, with no cron run.
found=0
for i in $(seq 1 10); do
  body="$(curl -sk --max-time 60 "$URL")"
  if grep -qF "$T" <<<"$body"; then
    found=$i
    break
  fi
  sleep 1
done
if [[ "$found" -gt 0 ]]; then
  pass "search hit ($T on /search/content/$W, attempt $found)"
else
  fail "search hit: $T not on /search/content/$W after 10 attempts"
fi

# 6. Cron did not run.
cron_after="$(drush_out state:get system.cron_last | tail -n1)"
if [[ "$cron_after" == "$cron_before" ]]; then
  pass "cron_last unchanged ($cron_after)"
else
  fail "cron_last: changed from $cron_before to $cron_after, so cron ran"
fi

# 7. Cleanup.
cleanup

exit "$FAIL"
