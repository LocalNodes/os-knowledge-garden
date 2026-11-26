#!/usr/bin/env bash
# One table of answer shapes against the key rule (check_secret), for BOTH workflows that carry it:
# provision-instance.yml ("Configure environment variables") and funds-on.yml ("Turn group funds on
# (practice)").
#
# The rule: a secret name is written only by the run that creates its row; an
# existing row is never replaced or deleted by a workflow, whatever it reads as. The rows of a new site are
# created with the site, so this means the run that creates the site. check_secret KEY DEFAULT reads the
# app's settings once, prints nothing on stdout, never prints a value, and:
#   on the run that created this app (CREATED_APP_UUID equal to APP_UUID, REUSE not true; only the Create
#   step's own output can give it):
#     new     when every production row of KEY holds "" or the compose default (no row included):
#             what a new app is known to show; the caller then makes the secret and writes it;
#     refuse  everything else (a real-looking value already there, or an answer it cannot read);
#   on every other run (a retry that reuses the app, funds-on):
#     keep    when the name is listed and every production row of it holds one same value, a non-empty
#             string other than the compose default; nothing is written, the value never reaches the shell;
#     refuse  everything else: an empty row, the default, no row, rows that disagree, an unreadable answer.
# A refusal is one fixed line on stderr naming the key and no value, status 1, nothing on stdout.
#
# Each row runs the workflow's own check_secret text (cut from the step) the way the step calls it, a
# plain statement under bash -e, followed by a marker line that only runs when the check passed. The
# compose defaults are read from docker-compose.yaml, not typed here.
#
# No network: `curl` is a stand-in that prints the row's body and exits with the row's code.
#
# Usage: bash scripts/ops/tests/pick-secret-table.test.sh
#        PROVISION_WF=... FUNDS_WF=... bash scripts/ops/tests/pick-secret-table.test.sh   (to test changed copies)
set -u

HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$HERE/../../.." && pwd)"
PROVISION_WF="${PROVISION_WF:-$REPO/.github/workflows/provision-instance.yml}"
FUNDS_WF="${FUNDS_WF:-$REPO/.github/workflows/funds-on.yml}"
COMPOSE="${COMPOSE:-$REPO/docker-compose.yaml}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

PASS=0
FAIL=0
ok()   { PASS=$((PASS + 1)); echo "ok   - $1"; }
nok()  { FAIL=$((FAIL + 1)); echo "FAIL - $1"; }
check() { if eval "$2"; then ok "$1"; else nok "$1"; fi; }

# The three refusal lines, by key
line_missing() { printf '%s' "::error::This app's settings do not show $1 as held. Only the run that creates a site writes its keys and passwords: create the site again, or the site's owner must place the key. Nothing was stored or sent."; }
line_taken()   { printf '%s' "::error::The app this run just created already shows a value for $1, so this run does not write it. Nothing was stored or sent."; }
line_unclear() { printf '%s' "::error::This app's settings did not clearly show $1. Nothing was stored or sent."; }

yaml_json() {
  if command -v ruby >/dev/null 2>&1; then
    ruby -ryaml -rjson -e 'puts JSON.generate(YAML.load_file(ARGV[0]))' "$1"
  else
    python3 -c 'import json, sys, yaml; print(json.dumps(yaml.safe_load(open(sys.argv[1]))))' "$1"
  fi
}
run_text() { jq -j --arg j "$2" --arg n "$3" '[.jobs[$j].steps[] | select(.name == $n)][0].run // ""' "$1"; }
extract_fn() { awk -v n="$2" '$0 == n "() {" { p = 1 } p { print } p && $0 == "}" { exit }' "$1"; }

yaml_json "$PROVISION_WF" > "$WORK/prov.json" || { echo "FAIL - could not parse $PROVISION_WF"; exit 1; }
yaml_json "$FUNDS_WF" > "$WORK/funds.json" || { echo "FAIL - could not parse $FUNDS_WF"; exit 1; }
run_text "$WORK/prov.json" provision "Configure environment variables" > "$WORK/prov-run.sh"
run_text "$WORK/funds.json" funds_on "Turn group funds on (practice)" > "$WORK/funds-run.sh"
for fn in secret_names created_here check_secret; do
  extract_fn "$WORK/prov-run.sh" "$fn" >> "$WORK/prov.fn"
  extract_fn "$WORK/funds-run.sh" "$fn" >> "$WORK/funds.fn"
done

echo "# the two copies of the key rule"
for fn in secret_names created_here check_secret; do
  check "$fn is non-empty in both workflows and the two copies are byte-identical (sha256)" \
    '[ -n "$(extract_fn "$WORK/prov-run.sh" "$fn")" ] && [ "$(extract_fn "$WORK/prov-run.sh" "$fn" | sha256sum)" = "$(extract_fn "$WORK/funds-run.sh" "$fn" | sha256sum)" ]'
done
check "no pick_secret, prove_reads, remove_read_check, READ_PROVEN or LOCALNODES_READ_CHECK is left in either workflow" \
  '! grep -qE "pick_secret|prove_reads|remove_read_check|READ_PROVEN|READ_CHECK" "$PROVISION_WF" "$FUNDS_WF"'
check "the six secret names, in this order" \
  '[ "$(bash -c "$(cat "$WORK/prov.fn"); secret_names" | jq -r "join(\" \")")" = "DRUPAL_ADMIN_PASS DB_PASSWORD DB_ROOT_PASSWORD DRUPAL_HASH_SALT SPONSOR_RELAYER_PRIVATE_KEY SPONSOR_RELAYER_TOKEN" ]'

# compose_default KEY -> the default docker-compose.yaml gives ${KEY:-default}; nonzero when the file names none
compose_default() {
  local line
  line="$(grep -m1 -oE "\\\$\\{$1:-[^}]*\\}" "$COMPOSE")" || return 1
  line="${line#\$\{$1:-}"
  printf '%s' "${line%\}}"
}

echo "# every check_secret call passes the compose default of its key (read from docker-compose.yaml), and every secret name is checked"
for f in "$WORK/prov-run.sh" "$WORK/funds-run.sh"; do
  grep -oE '^[[:space:]]*check_secret [A-Z_]+ ("[^"]*"|[^ "]+)$' "$f" | sed -E 's/^[[:space:]]+//' > "$WORK/calls" || true
  wf_name="$(basename "$([ "$f" = "$WORK/prov-run.sh" ] && echo "$PROVISION_WF" || echo "$FUNDS_WF")")"
  check "$wf_name: the step has check_secret calls to check" '[ -s "$WORK/calls" ]'
  while IFS= read -r call; do
    key="$(sed -E 's/^check_secret ([A-Z_]+) .*/\1/' <<<"$call")"
    def="$(sed -E 's/^check_secret [A-Z_]+ (.*)$/\1/; s/^"(.*)"$/\1/' <<<"$call")"
    want="$(compose_default "$key")"; have=$?
    check "$wf_name: check_secret $key passes '$def', the compose default" '[ "$have" -eq 0 ] && [ "$def" = "$want" ]'
  done < "$WORK/calls"
done
check "provision-instance.yml checks all six names" \
  '[ "$(grep -oE "^[[:space:]]*check_secret [A-Z_]+" "$WORK/prov-run.sh" | awk "{print \$2}" | sort -u | tr "\n" " ")" = "DB_PASSWORD DB_ROOT_PASSWORD DRUPAL_ADMIN_PASS DRUPAL_HASH_SALT SPONSOR_RELAYER_PRIVATE_KEY SPONSOR_RELAYER_TOKEN " ]'
check "funds-on.yml checks the key and the token" \
  '[ "$(grep -oE "^[[:space:]]*check_secret [A-Z_]+" "$WORK/funds-run.sh" | awk "{print \$2}" | sort -u | tr "\n" " ")" = "SPONSOR_RELAYER_PRIVATE_KEY SPONSOR_RELAYER_TOKEN " ]'

# The stand-in curl: prints the row's body (as is, even empty) and exits with the row's code.
mkdir -p "$WORK/bin"
cat > "$WORK/bin/curl" <<'SH'
#!/bin/sh
cat "$ROW_BODY"
exit "$ROW_RC"
SH
chmod +x "$WORK/bin/curl"

# The caller, as the step calls it: a plain statement under bash -e, then a line that runs only when it passed.
caller() {
  cat "$1"
  printf '%s\n' 'check_secret "$ROW_KEY" "$ROW_DEF"' 'echo "PASSED"'
}
caller "$WORK/prov.fn" > "$WORK/prov-caller.sh"
caller "$WORK/funds.fn" > "$WORK/funds-caller.sh"
# The function called on its own, stdout and stderr kept apart: it prints nothing on stdout at all.
direct() {
  cat "$1"
  printf '%s\n' 'RC=0' 'check_secret "$ROW_KEY" "$ROW_DEF" > "$ROW_STDOUT" 2> "$ROW_STDERR" || RC=$?' 'exit "$RC"'
}
direct "$WORK/prov.fn" > "$WORK/prov-direct.sh"
direct "$WORK/funds.fn" > "$WORK/funds-direct.sh"

HELD="held-secret-the-app-holds-0002"
OTHER="other-secret-another-row-0003"

# run_mode MODE -> the env of that run. own: the run that created app123 (the Create step's output); later: a retry
# or funds-on (REUSE=true, no output). Two forgeries of the flag run as later: an output naming another app, and
# REUSE=true next to a matching output.
mode_env() {
  case "$1" in
    own)        printf '%s\n' APP_UUID=app123 CREATED_APP_UUID=app123 ;;
    later)      printf '%s\n' APP_UUID=app123 REUSE=true ;;
    other-app)  printf '%s\n' APP_UUID=app123 CREATED_APP_UUID=app999 ;;
    reuse-flag) printf '%s\n' APP_UUID=app123 CREATED_APP_UUID=app123 REUSE=true ;;
    no-flag)    printf '%s\n' APP_UUID=app123 ;;
  esac
}

# row LABEL OWN_EXPECT LATER_EXPECT CURLRC JQ_PROGRAM -> the body is that jq program's output (-n, compact; $k the key,
# $d its compose default, $h a held value, $o another value). "RAW:text" instead of a program sends text as is.
# An expectation is new, keep, or the refusal kind: missing, taken, unclear.
row() {
  local label="$1" own="$2" later="$3" rc="$4" prog="$5" key def wf caller mode expect out wrc drc want
  for key in DB_PASSWORD SPONSOR_RELAYER_PRIVATE_KEY; do
    def="$(compose_default "$key")" || { nok "$label: no compose default for $key"; continue; }
    if [[ "$prog" == RAW:* ]]; then
      printf '%b' "${prog#RAW:}" > "$WORK/body"
    else
      jq -nc --arg k "$key" --arg d "$def" --arg h "$HELD" --arg o "$OTHER" "$prog" > "$WORK/body" \
        || { nok "$label: the row's body could not be built"; continue; }
    fi
    for mode in own later other-app reuse-flag no-flag; do
      expect="$later"; [ "$mode" = own ] && expect="$own"
      for wf in provision funds-on; do
        caller="$WORK/prov-caller.sh"; [ "$wf" = funds-on ] && caller="$WORK/funds-caller.sh"
        mapfile -t MENV < <(mode_env "$mode")
        out="$(env -i PATH="$WORK/bin:/usr/bin:/bin" HOME="$WORK" "${MENV[@]}" ENVS_URL="http://coolify.test/envs" \
          COOLIFY_TOKEN=test-token ROW_BODY="$WORK/body" ROW_RC="$rc" ROW_KEY="$key" ROW_DEF="$def" \
          bash --noprofile --norc -e "$caller" 2>&1)"
        wrc=$?
        env -i PATH="$WORK/bin:/usr/bin:/bin" HOME="$WORK" "${MENV[@]}" ENVS_URL="http://coolify.test/envs" \
          COOLIFY_TOKEN=test-token ROW_BODY="$WORK/body" ROW_RC="$rc" ROW_KEY="$key" ROW_DEF="$def" \
          ROW_STDOUT="$WORK/d.out" ROW_STDERR="$WORK/d.err" bash --noprofile --norc -e "${caller%-caller.sh}-direct.sh"
        drc=$?
        case "$expect" in
          new|keep)
            check "$wf | $mode | $key | $label | $expect: passes, nothing printed at all" \
              '[ "$wrc" -eq 0 ] && [ "$out" = PASSED ] && [ "$drc" -eq 0 ] && [ ! -s "$WORK/d.out" ] && [ ! -s "$WORK/d.err" ]' ;;
          *)
            want="$(line_"$expect" "$key")"
            check "$wf | $mode | $key | $label | refuse ($expect): the step stops there with the one fixed line, no value printed" \
              '[ "$wrc" -ne 0 ] && [ "$out" = "$want" ] && ! grep -qF -e "$HELD" -e "$OTHER" <<<"$out"'
            check "$wf | $mode | $key | $label | refuse ($expect), called on its own: status 1, nothing on stdout, the line alone on stderr" \
              '[ "$drc" -eq 1 ] && [ ! -s "$WORK/d.out" ] && [ "$(cat "$WORK/d.err")" = "$want" ]' ;;
        esac
      done
    done
  done
}

# A production row of another key that holds a value: on any real app the compose parse leaves several.
A='{uuid: "a1", key: "DEMO_MODULE", value: "localnodes_demo", is_preview: false}'
r() { printf '{uuid: "%s", key: $k, value: %s, is_preview: %s}' "$1" "$2" "${3:-false}"; }

echo "# the table: answer shape -> (the run that created the app, every later run)"
# what a new app shows: the creating run makes the secret; a later run refuses (the name is not shown as held)
row "no row for the key"                                          new missing 0 "[$A]"
row "the key's row is empty"                                      new missing 0 "[$A, $(r k1 '""')]"
row "the key's row holds the compose default"                     new missing 0 "[$A, $(r k1 '$d')]"
row "two rows for the key, one empty and one the compose default" new missing 0 "[$A, $(r k1 '""'), $(r k2 '$d')]"
row "two empty rows for the key (the compose parse's duplicate)"  new missing 0 "[$A, $(r k1 '""'), $(r k2 '""')]"
row "an empty preview row only (production holds none)"           new missing 0 "[$A, $(r p1 '""' true)]"
row "a preview row holding a value only"                          new missing 0 "[$A, $(r p1 '$h' true)]"
# a value is held: a later run keeps it (writes nothing); the creating run refuses (its new app shows no such value)
row "one row holds a value"                                       taken keep 0 "[$A, $(r k1 '$h')]"
row "two rows hold the same value"                                taken keep 0 "[$A, $(r k1 '$h'), $(r k2 '$h')]"
row "a held row and a preview row with another value"             taken keep 0 "[$A, $(r k1 '$h'), $(r p1 '$o' true)]"
row "the host masks the value (********)"                         taken keep 0 "[$A, $(r k1 '"********"')]"
row "a held value ending in a newline (kept: it never passes through the shell)" taken keep 0 "[$A, $(r k1 '($h + "\n")')]"
row "two rows hold different values"                              taken unclear 0 "[$A, $(r k1 '$h'), $(r k2 '$o')]"
row "an empty row ahead of a held row"                            taken unclear 0 "[$A, $(r k1 '""'), $(r k2 '$h')]"
row "a compose-default row next to a held row"                    taken unclear 0 "[$A, $(r k1 '$h'), $(r k2 '$d')]"
# unclear answers: refused on every run
row "the key's row has no value field (hidden from this token)"   unclear unclear 0 "[$A, {uuid: \"k1\", key: \$k, is_preview: false}]"
row "every row has no value field (hidden scenario)"              unclear unclear 0 "[{uuid: \"a1\", key: \"DEMO_MODULE\", is_preview: false}, {uuid: \"k1\", key: \$k, is_preview: false}]"
row "the key's row holds null"                                    unclear unclear 0 "[$A, $(r k1 null)]"
row "the key's row holds a number"                                unclear unclear 0 "[$A, $(r k1 123)]"
row "a held row and a row with no value field"                    unclear unclear 0 "[$A, $(r k1 '$h'), {uuid: \"k2\", key: \$k, is_preview: false}]"
row "guard: every value empty (hidden values sent as empty strings)" unclear unclear 0 "[{uuid: \"a1\", key: \"DEMO_MODULE\", value: \"\", is_preview: false}, $(r k1 '""')]"
row "guard: no production row holds a value, only a preview row does" unclear unclear 0 "[$(r k1 '""'), $(r p1 '$o' true)]"
row "guard: the empty list"                                       unclear unclear 0 "[]"
row "not a list (an object)"                                      unclear unclear 0 '{data: []}'
row "an error body that is not a list"                            unclear unclear 0 '{message: "Unauthenticated."}'
row "a list of strings, not objects"                              unclear unclear 0 '["x"]'
row "a list holding null"                                         unclear unclear 0 '[null]'
row "a list of objects and one null"                              unclear unclear 0 "[$A, null]"
row "a row whose key is not a string"                             unclear unclear 0 "[$A, {uuid: \"k1\", key: 7, value: \"\", is_preview: false}]"
row "an error status (curl fails on it)"                          unclear unclear 22 '{message: "Service Unavailable"}'
row "no answer at all (curl cannot connect)"                      unclear unclear 7 'RAW:'
row "an empty body"                                               unclear unclear 0 'RAW:'
row "a whitespace-only body"                                      unclear unclear 0 'RAW: \n\t \n'
row "a body that is not JSON"                                     unclear unclear 0 'RAW:<html>502 Bad Gateway</html>'
row "two documents: a list, then the empty list"                  unclear unclear 0 "RAW:[{\"uuid\":\"a1\",\"key\":\"DEMO_MODULE\",\"value\":\"x\",\"is_preview\":false}] []"
row "two documents, each a list holding a different value"        unclear unclear 0 "RAW:[{\"uuid\":\"a1\",\"key\":\"DEMO_MODULE\",\"value\":\"x\"},{\"uuid\":\"k1\",\"key\":\"DB_PASSWORD\",\"value\":\"$HELD\"},{\"uuid\":\"k2\",\"key\":\"SPONSOR_RELAYER_PRIVATE_KEY\",\"value\":\"$HELD\"}] [{\"uuid\":\"a1\",\"key\":\"DEMO_MODULE\",\"value\":\"x\"},{\"uuid\":\"k1\",\"key\":\"DB_PASSWORD\",\"value\":\"$OTHER\"},{\"uuid\":\"k2\",\"key\":\"SPONSOR_RELAYER_PRIVATE_KEY\",\"value\":\"$OTHER\"}]"

echo "# created_here: only the Create step's output naming this very app, with no REUSE, counts"
ch() { env -i PATH=/usr/bin:/bin "$@" bash --noprofile --norc -c "$(cat "$WORK/prov.fn")"$'\n''created_here'; }
check "created_here: CREATED_APP_UUID equal to APP_UUID, no REUSE -> yes" 'ch APP_UUID=app123 CREATED_APP_UUID=app123'
check "created_here: no CREATED_APP_UUID -> no" '! ch APP_UUID=app123'
check "created_here: an empty CREATED_APP_UUID and an empty APP_UUID -> no" '! ch APP_UUID= CREATED_APP_UUID='
check "created_here: CREATED_APP_UUID naming another app -> no" '! ch APP_UUID=app123 CREATED_APP_UUID=app999'
check "created_here: REUSE=true next to a matching CREATED_APP_UUID -> no" '! ch APP_UUID=app123 CREATED_APP_UUID=app123 REUSE=true'

echo
echo "passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ]
