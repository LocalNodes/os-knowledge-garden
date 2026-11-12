#!/bin/sh
# Checks deploy.sh against local anvil nodes, with anvil's public test key:
#  1. a main-network chain id that no deny-list names (59144, Linea) is refused and nothing is sent;
#  2. on an allowed test network the token deploys at the predicted address, and a second run sends
#     nothing.
set -eu
KEY=0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80
FROM=0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266
ADDR=$(script/deploy.sh address | sed -n 's/^PRACTICE_TOKEN_ADDRESS=//p')
rm -rf broadcast
fail() { echo "FAIL: $*" >&2; exit 1; }

anvil --chain-id 59144 --port 8601 --silent & A=$!
trap 'kill $A 2>/dev/null || true' EXIT
sleep 2
if out=$(RPC_URL=http://127.0.0.1:8601 EXPECT_CHAIN_ID=59144 DEPLOYER_KEY=$KEY script/deploy.sh deploy 2>&1); then
  fail "chain 59144 was accepted: $out"
fi
case "$out" in *"not a listed test network"*) ;; *) fail "wrong refusal: $out" ;; esac
[ "$(cast nonce "$FROM" --rpc-url http://127.0.0.1:8601)" = 0 ] || fail "a transaction was sent on 59144"
[ "$(cast code "$ADDR" --rpc-url http://127.0.0.1:8601)" = "0x" ] || fail "code appeared on 59144"
echo "ok: chain 59144 refused, nothing sent"
kill $A; wait $A 2>/dev/null || true

anvil --chain-id 31337 --port 8602 --silent & A=$!
sleep 2
RPC_URL=http://127.0.0.1:8602 EXPECT_CHAIN_ID=31337 DEPLOYER_KEY=$KEY script/deploy.sh deploy | grep -qx "PRACTICE_TOKEN_ADDRESS=$ADDR" || fail "deploy on 31337"
N=$(cast nonce "$FROM" --rpc-url http://127.0.0.1:8602)
RPC_URL=http://127.0.0.1:8602 EXPECT_CHAIN_ID=31337 DEPLOYER_KEY=$KEY script/deploy.sh deploy | grep -q "nothing sent" || fail "second run"
[ "$(cast nonce "$FROM" --rpc-url http://127.0.0.1:8602)" = "$N" ] || fail "the second run sent a transaction"
echo "ok: deployed at $ADDR on 31337; a second run sent nothing"
