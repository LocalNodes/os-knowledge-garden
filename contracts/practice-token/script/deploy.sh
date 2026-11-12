#!/bin/sh
# Deploys Practice Money through the standard CREATE2 deployer with a fixed salt, so the
# address is known before the deploy and a second run changes nothing. Runs inside the pinned
# Foundry image (forge, cast). Usage:
#   script/deploy.sh address           print the address only (no network, no key)
#   script/deploy.sh deploy            deploy if missing, then check it and print the address
# deploy reads RPC_URL, EXPECT_CHAIN_ID and DEPLOYER_KEY from the environment. The key is never
# printed and never put on a command line: script/Deploy.s.sol reads it from the environment.
# Only the test networks in TEST_CHAINS are accepted, and the RPC must be on EXPECT_CHAIN_ID;
# adding a network to TEST_CHAINS is a reviewed edit.
set -eu
CREATE2_DEPLOYER=0x4e59b44847b379578588920ca78fbf26c0b4956c
SALT=$(cast keccak "localnodes.practice-money.v1")
# Ethereum Sepolia, Base Sepolia, local anvil.
TEST_CHAINS="11155111 84532 31337"

forge build --quiet
INIT=$(forge inspect PracticeMoney bytecode)
# keccak256(0xff ++ deployer ++ salt ++ keccak256(init code)), last 20 bytes.
INIT_HASH=$(cast keccak "$INIT")
HASH=$(cast keccak "0xff${CREATE2_DEPLOYER#0x}${SALT#0x}${INIT_HASH#0x}")
ADDR=$(cast to-check-sum-address "0x$(printf '%s' "$HASH" | tail -c 40)")
[ -n "$ADDR" ] || { echo "could not work out the address" >&2; exit 1; }

if [ "${1:-}" = "address" ]; then
  echo "PRACTICE_TOKEN_ADDRESS=$ADDR"
  exit 0
fi
[ "${1:-}" = "deploy" ] || { echo "usage: deploy.sh address|deploy" >&2; exit 2; }

: "${RPC_URL:?RPC_URL is not set}"
: "${EXPECT_CHAIN_ID:?EXPECT_CHAIN_ID is not set}"
case " $TEST_CHAINS " in *" $EXPECT_CHAIN_ID "*) ;; *) echo "refused: chain $EXPECT_CHAIN_ID is not a listed test network" >&2; exit 1 ;; esac
CHAIN=$(cast chain-id --rpc-url "$RPC_URL")
[ "$CHAIN" = "$EXPECT_CHAIN_ID" ] || { echo "refused: the RPC is on chain $CHAIN, not $EXPECT_CHAIN_ID" >&2; exit 1; }
[ "$(cast code "$CREATE2_DEPLOYER" --rpc-url "$RPC_URL")" != "0x" ] || { echo "the CREATE2 deployer is missing on chain $CHAIN" >&2; exit 1; }

if [ "$(cast code "$ADDR" --rpc-url "$RPC_URL")" = "0x" ]; then
  : "${DEPLOYER_KEY:?DEPLOYER_KEY is not set}"
  echo "deploying to $ADDR on chain $CHAIN"
  forge script script/Deploy.s.sol:Deploy --rpc-url "$RPC_URL" --chain "$CHAIN" --broadcast --slow --quiet >/dev/null
  [ -f "broadcast/Deploy.s.sol/$CHAIN/run-latest.json" ] || { echo "no broadcast record for chain $CHAIN" >&2; exit 1; }
else
  echo "already deployed at $ADDR on chain $CHAIN; nothing sent"
fi

NAME=$(cast call "$ADDR" "name()(string)" --rpc-url "$RPC_URL")
SYMBOL=$(cast call "$ADDR" "symbol()(string)" --rpc-url "$RPC_URL")
AMOUNT=$(cast call "$ADDR" "PRACTICE_AMOUNT()(uint256)" --rpc-url "$RPC_URL" | cut -d' ' -f1)
[ "$NAME" = '"Practice Money"' ] && [ "$SYMBOL" = '"PRACTICE"' ] && [ "$AMOUNT" = "1000000000000000000000" ] \
  || { echo "the contract at $ADDR is not Practice Money ($NAME, $SYMBOL, $AMOUNT)" >&2; exit 1; }
echo "PRACTICE_TOKEN_ADDRESS=$ADDR"
