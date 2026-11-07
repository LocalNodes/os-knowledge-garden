#!/usr/bin/env bash
# Self-test for one local e2e chain (21-39). The chain must be up: `fork.sh up <id>`.
# Usage: selftest.sh <id>
# Checks, over JSON-RPC to the chain's recorded hostUrl:
#   (a) anvil exposes at least 10 dev accounts
#   (b) personal_sign and eth_signTypedData_v4 by account 0 return 65-byte signatures, and a
#       1 wei eth_sendTransaction from account 0 to account 1 is mined with status 0x1
#   (c) impersonation of a key-less address works, and stops when asked
#   (d) evm_increaseTime + evm_mine move the latest block's timestamp at least an hour
#   (e) fork.sh reset brings the block number back and drops (b)'s receipt
#   (f) fork.sh check passes for every contract, MockUSDC has 6 decimals, and a mint shows
#       in balanceOf
# Then it resets the chain once more, so the chain is left at its post-setup state.
# Prints "SELFTEST OK <id>" as its last line only when every step passed; otherwise the
# failed step, and exits 1.
#
# Lane rules (R-D): only anvil's well-known dev accounts sign, and only on this local chain.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../../.." && pwd)"
STATE="$ROOT/tests/e2e/.state/forks-run.json"
FORK="$HERE/fork.sh"

[ $# -eq 1 ] || { echo "usage: selftest.sh <id>" >&2; exit 2; }
ID=$1
[ -s "$STATE" ] && jq -e --arg i "$ID" 'has($i)' "$STATE" >/dev/null || { echo "selftest: $ID is not up (run fork.sh up $ID)" >&2; exit 2; }
URL=$(jq -r --arg i "$ID" '.[$i].hostUrl' "$STATE")
CHAIN_ID=$(jq -r --arg i "$ID" '.[$i].chainId' "$STATE")
MODE=$(jq -r --arg i "$ID" '.[$i].mode' "$STATE")
case "$URL" in http://127.0.0.1:*|http://localhost:*|http://\[::1\]:*) ;; *) echo "selftest: $ID is not a loopback chain; refused" >&2; exit 2 ;; esac

fail() { echo "SELFTEST FAILED $ID: $*" >&2; exit 1; }
rpc_raw() {
  jq -cn --arg m "$1" --argjson p "$2" '{jsonrpc:"2.0",id:1,method:$m,params:$p}' \
    | curl -sf --max-time 30 -H 'content-type: application/json' --data @- "$URL"
}
# Result of a call that must succeed.
rpc() {
  local out
  out=$(rpc_raw "$1" "$2") || fail "$1: no response"
  jq -e 'has("error")' <<<"$out" >/dev/null && fail "$1: $(jq -c .error <<<"$out")"
  jq -r '.result | if type == "string" then . else tojson end' <<<"$out"
}
pad32() { local h=${1#0x}; printf '%064s' "$h" | tr ' ' 0; }
receipt_status() { rpc eth_getTransactionReceipt "[\"$1\"]" | jq -r 'if . == null then "null" else .status end'; }
# Status of a receipt that should exist: anvil's automine can lag the RPC answer, so poll
# for up to 10 s before reporting "null".
receipt_wait() {
  local i st
  for i in $(seq 1 20); do
    st=$(receipt_status "$1")
    [ "$st" != "null" ] && { echo "$st"; return 0; }
    sleep 0.5
  done
  echo null
}
is_sig65() { [[ "$1" =~ ^0x[0-9a-fA-F]{130}$ ]]; }

# (a) dev accounts
ACCTS=$(rpc eth_accounts '[]')
[ "$(jq 'length' <<<"$ACCTS")" -ge 10 ] || fail "(a) fewer than 10 dev accounts"
A0=$(jq -r '.[0]' <<<"$ACCTS"); A1=$(jq -r '.[1]' <<<"$ACCTS"); A2=$(jq -r '.[2]' <<<"$ACCTS")
echo "(a) ok: $(jq 'length' <<<"$ACCTS") dev accounts"

# (b) signing and a plain transfer
SIG=$(rpc personal_sign "[\"0x4c6f63616c4e6f646573206532652073656c6674657374\",\"$A0\"]")
is_sig65 "$SIG" || fail "(b) personal_sign did not return 65 bytes"
TYPED=$(jq -cn --argjson c "$CHAIN_ID" '{
  types: {
    EIP712Domain: [{name:"name",type:"string"},{name:"version",type:"string"},{name:"chainId",type:"uint256"}],
    Check: [{name:"note",type:"string"},{name:"n",type:"uint256"}]
  },
  primaryType: "Check",
  domain: {name:"LocalNodes e2e selftest", version:"1", chainId:$c},
  message: {note:"selftest", n:1}
}')
SIG=$(rpc eth_signTypedData_v4 "$(jq -cn --arg a "$A0" --argjson t "$TYPED" '[$a,$t]')")
is_sig65 "$SIG" || fail "(b) eth_signTypedData_v4 did not return 65 bytes"
TX_B=$(rpc eth_sendTransaction "[{\"from\":\"$A0\",\"to\":\"$A1\",\"value\":\"0x1\"}]")
[ "$(receipt_wait "$TX_B")" = "0x1" ] || fail "(b) transfer receipt status is not 0x1"
echo "(b) ok: personal_sign, eth_signTypedData_v4, transfer $TX_B"

# (c) impersonation of a key-less address
IMP=0x000000000000000000000000000000000000e2e1
rpc anvil_setBalance "[\"$IMP\",\"0xde0b6b3a7640000\"]" >/dev/null
rpc anvil_impersonateAccount "[\"$IMP\"]" >/dev/null
SEND="[{\"from\":\"$IMP\",\"to\":\"$A1\",\"value\":\"0x1\"}]"
TX_C=$(rpc eth_sendTransaction "$SEND")
[ "$(receipt_wait "$TX_C")" = "0x1" ] || fail "(c) impersonated send failed"
rpc anvil_stopImpersonatingAccount "[\"$IMP\"]" >/dev/null
OUT=$(rpc_raw eth_sendTransaction "$SEND") || fail "(c) no response after stopping impersonation"
jq -e 'has("error")' <<<"$OUT" >/dev/null || fail "(c) send still accepted after anvil_stopImpersonatingAccount"
echo "(c) ok: impersonated send mined; refused after stop ($(jq -r .error.message <<<"$OUT"))"

# (d) time travel
T0=$(( $(rpc eth_getBlockByNumber '["latest",false]' | jq -r .timestamp) ))
rpc evm_increaseTime '[3600]' >/dev/null
rpc evm_mine '[]' >/dev/null
T1=$(( $(rpc eth_getBlockByNumber '["latest",false]' | jq -r .timestamp) ))
[ $((T1 - T0)) -ge 3600 ] || fail "(d) timestamp moved only $((T1 - T0)) s"
echo "(d) ok: timestamp moved $((T1 - T0)) s"

# (e) reset
POST=$(jq -r --arg i "$ID" '.[$i].block' "$STATE")
INST0=$(jq -r --arg i "$ID" '.[$i].instanceId' "$STATE")
bash "$FORK" reset "$ID" >/dev/null || fail "(e) fork.sh reset failed"
[ $(( $(rpc eth_blockNumber '[]') )) -eq "$POST" ] || fail "(e) block number is not back at $POST"
[ "$(receipt_status "$TX_B")" = "null" ] || fail "(e) the transfer's receipt survived the reset"
INST1=$(jq -r --arg i "$ID" '.[$i].instanceId' "$STATE")
if [ "$INST0" = "$INST1" ]; then KEPT=kept; else KEPT=changed; fi
echo "(e) ok: back at block $POST, transfer receipt gone; instanceId $KEPT by reset"

# (f) code hashes, mock USDC
CHECK=$(bash "$FORK" check "$ID") || fail "(f) fork.sh check reported a mismatch: $(grep MISMATCH <<<"$CHECK" | tr '\n' ' ')"
N=$(grep -c '^CODE OK ' <<<"$CHECK" || true)
if [ "$MODE" = "local" ]; then [ "$N" -eq 11 ] || fail "(f) expected 11 CODE OK lines, got $N"; fi
USDC=$(awk '/^tokens:/ {t=1} t && /address:/ {v=$NF; gsub(/[\047" ]/, "", v); print v; exit}' \
  "$ROOT/$(jq -r --arg i "$ID" '.[$i].registry' "$HERE/forks.json")")
DEC=$(rpc eth_call "[{\"to\":\"$USDC\",\"data\":\"0x313ce567\"},\"latest\"]")
[ $((DEC)) -eq 6 ] || fail "(f) USDC decimals() is $((DEC)), not 6"
if [ "$MODE" = "local" ]; then
  AMT=1250500000
  BAL0=$(( $(rpc eth_call "[{\"to\":\"$USDC\",\"data\":\"0x70a08231$(pad32 "$A2")\"},\"latest\"]") ))
  TX_F=$(rpc eth_sendTransaction "[{\"from\":\"$A0\",\"to\":\"$USDC\",\"data\":\"0x40c10f19$(pad32 "$A2")$(pad32 "$(printf '%x' $AMT)")\"}]")
  [ "$(receipt_wait "$TX_F")" = "0x1" ] || fail "(f) mint failed"
  BAL1=$(( $(rpc eth_call "[{\"to\":\"$USDC\",\"data\":\"0x70a08231$(pad32 "$A2")\"},\"latest\"]") ))
  [ $((BAL1 - BAL0)) -eq $AMT ] || fail "(f) balanceOf moved by $((BAL1 - BAL0)), not $AMT"
fi
echo "(f) ok: $N CODE OK lines, USDC decimals 6, mint shows in balanceOf"

# Leave the chain at its post-setup state for whoever runs next.
bash "$FORK" reset "$ID" >/dev/null || fail "final reset failed"
echo "SELFTEST OK $ID"
