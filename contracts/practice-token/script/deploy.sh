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
# The ceiling is on the gas limit the deploy is sent with: the network's estimate times 1.2,
# worked out here and handed to forge as is. Sepolia estimated 4,098,039 in 2026-11 (a limit of
# about 4,920,000); the same deploy is about 620,000 on a local EVM.
MAX_GAS_LIMIT=7200000

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
  # The gas comes from the network, before anything is signed. A local EVM can charge far less
  # for new code than the network does (Sepolia in 2026-11: about 1,542 gas a byte against 200),
  # so a limit taken from forge's own simulation can run out on chain.
  EST=$(cast estimate "$CREATE2_DEPLOYER" "${SALT}${INIT#0x}" --rpc-url "$RPC_URL" 2>/dev/null) || EST=
  # Leading zeros are dropped, so the shell reads the number in base 10, not as octal.
  EST=${EST#"${EST%%[!0]*}"}
  case "$EST" in ''|*[!0-9]*) echo "refused: no usable gas estimate from the network; nothing sent" >&2; exit 1 ;; esac
  [ "${#EST}" -le 9 ] || { echo "refused: the network's gas estimate is out of range; nothing sent" >&2; exit 1; }
  LIMIT=$((EST * 12 / 10))
  [ "$LIMIT" -le "$MAX_GAS_LIMIT" ] || { echo "refused: the gas limit would be $LIMIT, above the $MAX_GAS_LIMIT ceiling; nothing sent" >&2; exit 1; }
  echo "deploying to $ADDR on chain $CHAIN with a gas limit of $LIMIT (the network estimates $EST)"
  # Deploy.s.sol makes the deployer call with exactly DEPLOY_GAS_LIMIT gas, and forge sends that
  # call with that limit. Whether it worked is decided below from the receipt and the contract,
  # not from forge's exit code, which also covers forge's own receipt wait failing. forge waits
  # at most 120 seconds for the receipt, and the whole forge run is cut off after 300 seconds.
  # The job log is public, so forge's own messages go to a file that is never printed.
  rm -rf "broadcast/Deploy.s.sol/$CHAIN"
  FORGE_ERR=$(mktemp)
  trap 'rm -f "$FORGE_ERR"' EXIT
  FORGE_OK=yes
  DEPLOY_GAS_LIMIT=$LIMIT timeout -k 10 300 forge script script/Deploy.s.sol:Deploy --rpc-url "$RPC_URL" \
    --chain "$CHAIN" --broadcast --slow --quiet --timeout 120 >/dev/null 2>"$FORGE_ERR" || FORGE_OK=no
  if [ "$FORGE_OK" = no ]; then
    if grep -qi "insufficient funds" "$FORGE_ERR"; then
      echo "forge exited with an error: the deployer account has too little test ETH; the checks below decide"
    else
      echo "forge exited with an error; the checks below decide"
    fi
  fi
  RECORD="broadcast/Deploy.s.sol/$CHAIN/run-latest.json"
  TX=
  [ -f "$RECORD" ] && TX=$(grep -o '"hash": *"0x[0-9a-fA-F]*"' "$RECORD" | head -n 1 | grep -o '0x[0-9a-fA-F]*') || true
  if [ -z "$TX" ]; then
    [ "$(timeout -k 5 20 cast code "$ADDR" --rpc-url "$RPC_URL" 2>/dev/null || true)" != "0x" ] \
      || { echo "forge recorded no transaction and there is no code at $ADDR" >&2; exit 1; }
    echo "forge recorded no transaction hash; checking the contract at $ADDR"
  else
    echo "transaction $TX"
    STATUS=
    for _ in 1 2 3 4 5; do
      # --async answers at once when there is no receipt yet, and timeout bounds a lookup the RPC
      # never answers, so five tries end within about two minutes (115 seconds measured).
      STATUS=$(timeout -k 5 20 cast receipt "$TX" status --async --rpc-url "$RPC_URL" 2>/dev/null) && [ -n "$STATUS" ] && break
      STATUS=; sleep 3
    done
    # cast prints the status field as true or false.
    case "$STATUS" in
      true|1*) echo "receipt: success" ;;
      false|0*) echo "the deploy transaction failed on chain (receipt status 0)" >&2; exit 1 ;;
      *) echo "could not read the receipt; checking the contract at $ADDR" ;;
    esac
    SENT=$(timeout -k 5 20 cast tx "$TX" gas --rpc-url "$RPC_URL" 2>/dev/null) || SENT=
    case "$SENT" in
      ''|*[!0-9]*) echo "gas: could not read the sent limit back; $LIMIT was computed" ;;
      "$LIMIT") echo "gas: sent with the computed limit of $LIMIT" ;;
      *) echo "gas: sent with a limit of $SENT, not the computed $LIMIT" ;;
    esac
  fi
else
  echo "already deployed at $ADDR on chain $CHAIN; nothing sent"
fi

# A lookup can fail for a moment just after a deploy, so the contract is read up to five times
# before the run gives up. Each read is cut off after 20 seconds, and what the endpoint answered
# is never printed.
is_practice_money() {
  NAME=$(timeout -k 5 20 cast call "$ADDR" "name()(string)" --rpc-url "$RPC_URL" 2>/dev/null) || return 1
  SYMBOL=$(timeout -k 5 20 cast call "$ADDR" "symbol()(string)" --rpc-url "$RPC_URL" 2>/dev/null) || return 1
  AMOUNT=$(timeout -k 5 20 cast call "$ADDR" "PRACTICE_AMOUNT()(uint256)" --rpc-url "$RPC_URL" 2>/dev/null) || return 1
  AMOUNT=${AMOUNT%% *}
  [ "$NAME" = '"Practice Money"' ] && [ "$SYMBOL" = '"PRACTICE"' ] && [ "$AMOUNT" = "1000000000000000000000" ]
}
NAME= SYMBOL= AMOUNT=
for TRY in 1 2 3 4 5; do
  is_practice_money && break
  [ "$TRY" = 5 ] && { echo "the contract at $ADDR is not Practice Money, or could not be read" >&2; exit 1; }
  sleep 3
done
echo "PRACTICE_TOKEN_ADDRESS=$ADDR"
