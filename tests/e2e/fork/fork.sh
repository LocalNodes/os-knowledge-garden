#!/usr/bin/env bash
# Local anvil chains for the Phase 21 wallet e2e lane (21-39). See README.md.
#
# Usage: fork.sh up <id|all> | down [<id>|all] | reset <id> | status | check <id> [<name>...]
#        | connect [<id>|all] | mode <id> | pin <id>
# Ids are the registry network ids in forks.json (ethereum_sepolia, base_sepolia).
# Exit codes: 0 done, 1 failed, 2 usage or refused.
#
# Lane rules (R-D): only anvil's well-known dev keys and mnemonic sign, and only on these
# local chains. Nothing here signs on a real network.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../../.." && pwd)"
FORKS="$HERE/forks.json"
COMPOSE="$HERE/compose.yaml"
VENDOR="$HERE/vendor"
STATE_DIR="$ROOT/tests/e2e/.state"
STATE="$STATE_DIR/forks-run.json"

# Fork mode (optional): LN_FORK_URL_<ID> comes from the environment or, failing that, from the
# gitignored tests/e2e/.env.fork (KEY=VALUE lines; only LN_FORK_URL_* names are read, nothing
# is evaluated). The URL holds a provider key: it is never printed, logged or written to the
# run state, and reaches the container only as an environment variable.
ENV_FORK="$ROOT/tests/e2e/.env.fork"
if [ -f "$ENV_FORK" ]; then
  while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in
      LN_FORK_URL_*=*)
        k=${line%%=*}; v=${line#*=}
        v=${v#\"}; v=${v%\"}; v=${v#\'}; v=${v%\'}
        [[ "$k" =~ ^LN_FORK_URL_[A-Z_]+$ ]] || continue
        [ -n "${!k:-}" ] || export "$k=$v"
        ;;
    esac
  done < "$ENV_FORK"
  unset line k v
fi

# safe-singleton-factory (vendor/SOURCES.md): same address and runtime on every chain id.
FACTORY=0x914d7Fec6aaC8cd542e72Bca78B30650d45643d7
FACTORY_CODE_HASH=0x2fa86add0aed31f33a762c9d88e807c475bd51d0f52bd0955754b2608f7e4989
# Gas for one CREATE2 deployment through the factory (the Safe singleton is the largest).
DEPLOY_GAS=0x989680

die() { echo "fork.sh: $*" >&2; exit 1; }
usage() { echo "usage: fork.sh up <id|all> | down [<id>|all] | reset <id> | status | check <id> [<name>...] | connect [<id>|all] | mode <id> | pin <id>" >&2; exit 2; }

ids_all() { jq -r 'keys_unsorted[]' "$FORKS"; }
known_id() { jq -e --arg i "$1" 'has($i)' "$FORKS" >/dev/null; }
dash() { printf '%s' "$1" | tr '_' '-'; }
container_of() { printf 'fresh3-e2e-anvil-%s' "$(dash "$1")"; }
service_of() { printf 'anvil-%s' "$(dash "$1")"; }
upper() { printf '%s' "$1" | tr '[:lower:]' '[:upper:]'; }
lower() { printf '%s' "$1" | tr '[:upper:]' '[:lower:]'; }
port_of() { jq -r --arg i "$1" '.[$i].port' "$FORKS"; }
registry_of() { printf '%s/%s' "$ROOT" "$(jq -r --arg i "$1" '.[$i].registry' "$FORKS")"; }
chain_id_of() { awk '/^chain_id:/ {print $2; exit}' "$(registry_of "$1")"; }
token_of() { awk '/^tokens:/ {t=1} t && /address:/ {v=$NF; gsub(/[\047" ]/, "", v); print v; exit}' "$(registry_of "$1")"; }
to_hex() { printf '0x%x' "$1"; }

# Expand an id argument ("all" or one id) into ids; refuse unknown ids.
expand_ids() {
  local a="${1:-all}"
  if [ "$a" = "all" ]; then ids_all; return; fi
  known_id "$a" || { echo "fork.sh: unknown network id: $a" >&2; exit 2; }
  printf '%s\n' "$a"
}

# Loopback gate (R4). LN_FORK_BIND is the host address the ports publish on; LN_FORK_HOST is
# the address the host side uses to reach them.
is_loopback() { case "$1" in 127.0.0.1|localhost|::1) return 0 ;; *) return 1 ;; esac; }
BIND="${LN_FORK_BIND:-127.0.0.1}"
HOST="${LN_FORK_HOST:-127.0.0.1}"
REMOTE=false
gate_remote() {
  if is_loopback "$BIND" && is_loopback "$HOST"; then return 0; fi
  if [ "${LN_FORK_ALLOW_REMOTE:-}" != "1" ]; then
    echo "remote chain refused: set LN_FORK_ALLOW_REMOTE=1 to opt in" >&2
    exit 2
  fi
  echo "fork.sh: WARNING: non-loopback chain (LN_FORK_ALLOW_REMOTE=1); 21-41's test wallet refuses it" >&2
  REMOTE=true
}
host_url_of() {
  local h="$HOST"
  case "$h" in *:*) h="[$h]" ;; esac
  printf 'http://%s:%s' "$h" "$(port_of "$1")"
}

# JSON-RPC over curl. Prints the full response; fails on transport errors or an RPC error.
rpc_raw() {
  local url=$1 method=$2 params=$3
  jq -cn --arg m "$method" --argjson p "$params" '{jsonrpc:"2.0",id:1,method:$m,params:$p}' \
    | curl -sf --max-time 30 -H 'content-type: application/json' --data @- "$url"
}
rpc() {
  local out
  out=$(rpc_raw "$@") || return 1
  if jq -e 'has("error")' <<<"$out" >/dev/null; then
    echo "fork.sh: $2 failed: $(jq -c .error <<<"$out")" >&2
    return 1
  fi
  jq -c '.result' <<<"$out"
}
rpc_str() { rpc "$@" | jq -r '.'; }

# cast lives only in the chain image: run it in the chain's own container.
keccak() { docker exec "$CONTAINER" cast keccak "$1"; }
code_hash_at() {
  local code
  code=$(rpc_str "$URL" eth_getCode "[\"$1\",\"latest\"]") || return 1
  [ "$code" != "0x" ] || { echo 0x; return 0; }
  keccak "$code"
}

# Run-state file (gitignored); writes are serialised with flock.
state_update() {
  mkdir -p "$STATE_DIR"
  (
    flock 9
    [ -s "$STATE" ] || echo '{}' > "$STATE"
    local tmp
    tmp=$(mktemp "$STATE_DIR/.forks-run.XXXXXX")
    jq "$@" "$STATE" > "$tmp" && mv "$tmp" "$STATE"
  ) 9>"$STATE_DIR/.forks-run.lock"
}

compose() { docker compose -f "$COMPOSE" --profile ethereum_sepolia --profile base_sepolia "$@"; }

is_running() { [ "$(docker inspect -f '{{.State.Running}}' "$(container_of "$1")" 2>/dev/null || true)" = "true" ]; }

wait_chain() {
  local i
  for i in $(seq 1 60); do
    if rpc_raw "$URL" eth_chainId '[]' >/dev/null 2>&1; then return 0; fi
    # A chain that exited (a fork URL that doesn't answer, say) won't come back. Its logs can
    # hold the fork URL, so they are never printed here.
    is_running "$ID" || return 1
    sleep 1
  done
  return 1
}

# Replay the safe-singleton-factory's presigned EIP-155 transaction for this chain id.
deploy_factory() {
  local art="$VENDOR/safe-singleton-factory/$CHAIN_ID.json" gp gl signer tx base fund code
  [ -f "$art" ] || { echo "fork.sh: no vendored singleton factory for chain $CHAIN_ID" >&2; return 1; }
  gp=$(jq -r .gasPrice "$art"); gl=$(jq -r .gasLimit "$art"); signer=$(jq -r .signerAddress "$art"); tx=$(jq -r .transaction "$art")
  [ "$(lower "$(jq -r .address "$art")")" = "$(lower "$FACTORY")" ] || { echo "fork.sh: factory artifact names another address" >&2; return 1; }
  # The presigned gas price can sit below anvil's default base fee (Base Sepolia's does);
  # in that case the next block's base fee is lowered so the replay is accepted.
  base=$(rpc "$URL" eth_getBlockByNumber '["latest",false]' | jq -r '.baseFeePerGas // "0x0"') || return 1
  if [ "$((base))" -gt "$gp" ]; then rpc "$URL" anvil_setNextBlockBaseFeePerGas '["0x0"]' >/dev/null || return 1; fi
  # anvil runs chain 84532 as an OP-stack chain, so a transaction also pays an L1 data fee:
  # funding exactly gasPrice x gasLimit gets the replay dropped. Add 1 ETH (fake money).
  fund=$(to_hex $((gp * gl + 1000000000000000000)))
  rpc "$URL" anvil_setBalance "[\"$signer\",\"$fund\"]" >/dev/null || return 1
  rpc "$URL" eth_sendRawTransaction "[\"$tx\"]" >/dev/null || return 1
  local i
  for i in $(seq 1 20); do
    code=$(code_hash_at "$FACTORY") || return 1
    [ "$code" != "0x" ] && break
    sleep 0.5
  done
  [ "$code" = "$FACTORY_CODE_HASH" ] || { echo "fork.sh: singleton factory code hash mismatch: $code" >&2; return 1; }
}

# Deploy one vendored Safe contract through the factory, checking the CREATE2 address first
# and the runtime code hash after.
deploy_vendored() {
  local f=$1 name addr salt init want ih pre computed acct hash rcpt
  name=$(jq -r .name "$f"); addr=$(jq -r .address "$f"); salt=$(jq -r .salt "$f"); init=$(jq -r .initcode "$f"); want=$(jq -r .runtimeCodeHash "$f")
  ih=$(keccak "$init") || return 1
  pre="0xff${FACTORY#0x}${salt#0x}${ih#0x}"
  computed="0x$(keccak "$pre" | tail -c 41)" || return 1
  [ "$(lower "$computed")" = "$(lower "$addr")" ] || { echo "fork.sh: CREATE2 address for $name is $computed, not $addr" >&2; return 1; }
  acct=$(rpc "$URL" eth_accounts '[]' | jq -r '.[0]') || return 1
  hash=$(jq -cn --arg from "$acct" --arg to "$FACTORY" --arg data "$salt${init#0x}" --arg gas "$DEPLOY_GAS" \
    '[{from:$from,to:$to,data:$data,gas:$gas}]' | { read -r p; rpc_str "$URL" eth_sendTransaction "$p"; }) || return 1
  # anvil's automine can lag the RPC answer: poll the receipt for up to 10 s.
  local i
  for i in $(seq 1 20); do
    rcpt=$(rpc "$URL" eth_getTransactionReceipt "[\"$hash\"]" | jq -r 'if . == null then "null" else .status end') || return 1
    [ "$rcpt" != "null" ] && break
    sleep 0.5
  done
  [ "$rcpt" = "0x1" ] || { echo "fork.sh: deployment of $name failed (status $rcpt)" >&2; return 1; }
  [ "$(code_hash_at "$addr")" = "$want" ] || { echo "fork.sh: code hash mismatch for $name after deployment" >&2; return 1; }
}

# Local mode: place MockUSDC's runtime at the registry's USDC address (the registry is unchanged).
place_usdc() {
  local token rt want got
  token=$(token_of "$ID")
  [ -n "$token" ] || { echo "fork.sh: no tokens[0].address in $(registry_of "$ID")" >&2; return 1; }
  rt=$(jq -r .runtime "$VENDOR/MockUSDC.json"); want=$(jq -r .runtimeCodeHash "$VENDOR/MockUSDC.json")
  rpc "$URL" anvil_setCode "[\"$token\",\"$rt\"]" >/dev/null || return 1
  got=$(code_hash_at "$token") || return 1
  [ "$got" = "$want" ] || { echo "fork.sh: MockUSDC code hash mismatch at $token" >&2; return 1; }
}

# Bind CONTAINER, URL and CHAIN_ID for one id.
bind_chain() {
  ID=$1
  CONTAINER=$(container_of "$ID")
  URL=$(host_url_of "$ID")
  CHAIN_ID=$(chain_id_of "$ID")
  [ -n "$CHAIN_ID" ] || die "no chain_id in $(registry_of "$ID")"
}

pinned_block_of() { jq -r --arg i "$1" '.[$i].block // empty' "$FORKS"; }

cmd_up_one() {
  bind_chain "$1"
  local var="LN_ANVIL_ARGS_$(upper "$ID")" meta client got snap block mode pinned=""
  mode=$(chain_mode "$ID")
  if is_running "$ID"; then echo "fork.sh: $ID already running; down it first" >&2; return 1; fi
  if [ "$mode" = "fork" ]; then
    pinned=$(pinned_block_of "$ID")
    # The container's shell expands $LN_FORK_URL (set from LN_FORK_URL_<ID> in compose.yaml),
    # so the URL is in no command line.
    export "$var=--fork-url \"\$LN_FORK_URL\" --fork-block-number $pinned"
  else
    export "$var=--chain-id $CHAIN_ID"
  fi
  export LN_FORK_BIND="$BIND"
  [ "$BIND" = "localhost" ] && export LN_FORK_BIND=127.0.0.1
  compose up -d --no-deps "$(service_of "$ID")" >/dev/null 2>&1 || { echo "fork.sh: could not start $ID" >&2; return 1; }
  wait_chain || { echo "fork.sh: $ID did not come up (it exited, or gave no answer within 60 s)" >&2; return 1; }
  got=$(rpc_str "$URL" eth_chainId '[]') || return 1
  [ "$got" = "$(to_hex "$CHAIN_ID")" ] || { echo "fork.sh: $ID reports chain id $got, expected $(to_hex "$CHAIN_ID")" >&2; return 1; }
  meta=$(rpc "$URL" anvil_metadata '[]') || return 1
  client=$(jq -r .clientVersion <<<"$meta")
  case "$client" in anvil*) ;; *) echo "fork.sh: $ID is not anvil ($client)" >&2; return 1 ;; esac
  if [ "$mode" = "fork" ]; then
    block=$(rpc_str "$URL" eth_blockNumber '[]') || return 1
    [ "$((block))" -eq "$pinned" ] || { echo "fork.sh: $ID forked at block $((block)), not the pinned $pinned" >&2; return 1; }
    # The real chain already has the factory and the Safe contracts; the real token is there.
    cmd_check_names "$(check_names)" >/dev/null || { echo "fork.sh: $ID: a Safe contract's code differs on the fork" >&2; return 1; }
    meta=$(rpc "$URL" anvil_metadata '[]') || return 1
    state_update --arg i "$ID" --argjson chainId "$CHAIN_ID" --arg mode fork --argjson block "$pinned" \
      --arg host "$URL" --arg site "http://anvil-$(dash "$ID"):8545" --arg c "$CONTAINER" \
      --argjson remote "$REMOTE" --arg inst "$(jq -r .instanceId <<<"$meta")" --arg at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
      '.[$i] = {chainId:$chainId, mode:$mode, block:$block, snapshotId:null, hostUrl:$host, siteUrl:$site, container:$c, remote:$remote, instanceId:$inst, startedAt:$at, network:null}'
    echo "fork.sh: $ID up (fork, chain $CHAIN_ID, block $pinned) at $URL"
    return 0
  fi
  deploy_factory || return 1
  local f
  for f in "$VENDOR"/safe-1.4.1/*.json; do
    [ -e "$f" ] || continue
    deploy_vendored "$f" || return 1
  done
  place_usdc || return 1
  snap=$(rpc_str "$URL" evm_snapshot '[]') || return 1
  block=$(rpc_str "$URL" eth_blockNumber '[]') || return 1
  block=$((block))
  meta=$(rpc "$URL" anvil_metadata '[]') || return 1
  state_update --arg i "$ID" --argjson chainId "$CHAIN_ID" --arg mode local --argjson block "$block" \
    --arg snap "$snap" --arg host "$URL" --arg site "http://anvil-$(dash "$ID"):8545" --arg c "$CONTAINER" \
    --argjson remote "$REMOTE" --arg inst "$(jq -r .instanceId <<<"$meta")" --arg at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    '.[$i] = {chainId:$chainId, mode:$mode, block:$block, snapshotId:$snap, hostUrl:$host, siteUrl:$site, container:$c, remote:$remote, instanceId:$inst, startedAt:$at, network:null}'
  echo "fork.sh: $ID up (local, chain $CHAIN_ID, block $block) at $URL"
}

cmd_up() {
  [ $# -eq 1 ] || usage
  gate_remote
  local ids id
  ids=$(expand_ids "$1")
  for id in $ids; do
    if [ "$(chain_mode "$id")" = "fork" ] && [ -z "$(pinned_block_of "$id")" ]; then
      echo "fork.sh: $id is in fork mode but has no pinned block; run fork.sh pin $id and commit forks.json" >&2
      exit 2
    fi
  done
  for id in $ids; do
    if ! cmd_up_one "$id"; then
      down_one "$id"
      exit 1
    fi
  done
  cmd_connect "$1"
}

down_one() {
  local id=$1
  compose rm -s -f "$(service_of "$id")" >/dev/null 2>&1 || true
  docker rm -f "$(container_of "$id")" >/dev/null 2>&1 || true
  [ -s "$STATE" ] && state_update --arg i "$id" 'del(.[$i])' || true
}

cmd_down() {
  [ $# -le 1 ] || usage
  local ids id
  ids=$(expand_ids "${1:-all}")
  for id in $ids; do down_one "$id"; done
  if [ -z "$(docker ps -aq --filter name=fresh3-e2e-anvil)" ]; then compose down --remove-orphans >/dev/null 2>&1 || true; fi
  echo "fork.sh: down ${1:-all}"
}

cmd_status() {
  [ $# -eq 0 ] || usage
  echo "containers:"
  docker ps -a --filter name=fresh3-e2e-anvil --format '  {{.Names}} {{.Status}}'
  echo "run state:"
  if [ -s "$STATE" ]; then
    jq -r 'to_entries[] | "  \(.key): mode=\(.value.mode) chain=\(.value.chainId) block=\(.value.block) host=\(.value.hostUrl) network=\(.value.network)"' "$STATE"
  else
    echo "  (none)"
  fi
}

# Every name `check` knows for this chain: the factory, each vendored Safe contract and, in
# local mode, the mock USDC.
check_names() {
  echo singleton_factory
  local f
  for f in "$VENDOR"/safe-1.4.1/*.json; do [ -e "$f" ] && jq -r .name "$f"; done
  [ "$(chain_mode "$ID")" = "local" ] && echo usdc
  return 0
}

cmd_check() {
  [ $# -ge 1 ] || usage
  known_id "$1" || { echo "fork.sh: unknown network id: $1" >&2; exit 2; }
  gate_remote
  bind_chain "$1"; shift
  is_running "$ID" || die "$ID is not running"
  local names
  if [ $# -gt 0 ]; then names="$*"; else names=$(check_names); fi
  cmd_check_names "$names" || exit $?
}

# Compare the code hash at each named contract's address with the vendored hash (ID, URL and
# CONTAINER bound). Prints CODE OK / CODE MISMATCH per name; 1 on any mismatch, 2 on a bad name.
cmd_check_names() {
  local names=$1 name want addr got bad=0
  for name in $names; do
    case "$name" in
      singleton_factory) addr=$FACTORY; want=$FACTORY_CODE_HASH ;;
      usdc) addr=$(token_of "$ID"); want=$(jq -r .runtimeCodeHash "$VENDOR/MockUSDC.json") ;;
      *)
        [ -f "$VENDOR/safe-1.4.1/$name.json" ] || { echo "fork.sh: unknown contract: $name" >&2; return 2; }
        addr=$(jq -r .address "$VENDOR/safe-1.4.1/$name.json"); want=$(jq -r .runtimeCodeHash "$VENDOR/safe-1.4.1/$name.json") ;;
    esac
    got=$(code_hash_at "$addr") || got=error
    if [ "$got" = "$want" ]; then echo "CODE OK $name"; else echo "CODE MISMATCH $name"; bad=1; fi
  done
  return $bad
}

# local, or fork when LN_FORK_URL_<ID> is set (Task 3). Never prints the URL.
chain_mode() {
  local var="LN_FORK_URL_$(upper "$1")"
  if [ -n "${!var:-}" ]; then echo fork; else echo local; fi
}

cmd_mode() {
  [ $# -eq 1 ] || usage
  known_id "$1" || { echo "fork.sh: unknown network id: $1" >&2; exit 2; }
  chain_mode "$1"
}

# Local mode: evm_revert to the recorded post-setup snapshot (anvil consumes it), then take a
# new snapshot and record it with the block number and anvil_metadata's instanceId.
cmd_reset() {
  [ $# -eq 1 ] || usage
  known_id "$1" || { echo "fork.sh: unknown network id: $1" >&2; exit 2; }
  gate_remote
  bind_chain "$1"
  is_running "$ID" || die "$ID is not running"
  local snap ok block meta pinned
  if [ "$(jq -r --arg i "$ID" '.[$i].mode // empty' "$STATE" 2>/dev/null || true)" = "fork" ]; then
    # Fork mode: back to the pinned block. Never anvil_reset with no params (that drops the
    # fork) and never with a jsonRpcUrl (the URL stays out of every request body we build).
    pinned=$(pinned_block_of "$ID")
    [ -n "$pinned" ] || die "no pinned block for $ID"
    rpc "$URL" anvil_reset "$(jq -cn --argjson b "$pinned" '[{"forking": {"blockNumber": $b}}]')" >/dev/null || die "anvil_reset failed for $ID"
    block=$(rpc_str "$URL" eth_blockNumber '[]') || die "eth_blockNumber failed for $ID"
    [ "$((block))" -eq "$pinned" ] || die "$ID reset to block $((block)), not $pinned"
    meta=$(rpc "$URL" anvil_metadata '[]') || die "anvil_metadata failed for $ID"
    state_update --arg i "$ID" --argjson block "$pinned" --arg inst "$(jq -r .instanceId <<<"$meta")" \
      '.[$i].block = $block | .[$i].instanceId = $inst'
    echo "fork.sh: $ID reset to pinned block $pinned (fork)"
    return 0
  fi
  snap=$(jq -r --arg i "$ID" '.[$i].snapshotId // empty' "$STATE" 2>/dev/null || true)
  [ -n "$snap" ] || die "no recorded snapshot for $ID; run fork.sh up $ID"
  ok=$(rpc_str "$URL" evm_revert "[\"$snap\"]") || die "evm_revert failed for $ID"
  [ "$ok" = "true" ] || die "evm_revert to $snap returned $ok"
  snap=$(rpc_str "$URL" evm_snapshot '[]') || die "evm_snapshot failed for $ID"
  block=$(rpc_str "$URL" eth_blockNumber '[]') || die "eth_blockNumber failed for $ID"
  meta=$(rpc "$URL" anvil_metadata '[]') || die "anvil_metadata failed for $ID"
  state_update --arg i "$ID" --arg snap "$snap" --argjson block "$((block))" --arg inst "$(jq -r .instanceId <<<"$meta")" \
    '.[$i].snapshotId = $snap | .[$i].block = $block | .[$i].instanceId = $inst'
  echo "fork.sh: $ID reset to block $((block)) (snapshot $snap)"
}

# Fork mode only: pin the fork to the provider's head minus 64 blocks and record it in
# forks.json. The block moves only by a deliberate commit of forks.json.
cmd_pin() {
  [ $# -eq 1 ] || usage
  known_id "$1" || { echo "fork.sh: unknown network id: $1" >&2; exit 2; }
  [ "$(chain_mode "$1")" = "fork" ] || { echo "fork.sh: pin needs fork mode (LN_FORK_URL_$(upper "$1") unset)" >&2; exit 2; }
  local var="LN_FORK_URL_$(upper "$1")" head block tmp
  head=$(rpc_str "${!var}" eth_blockNumber '[]' 2>/dev/null) || die "the provider didn't answer eth_blockNumber for $1"
  block=$(( head - 64 ))
  tmp=$(mktemp "$HERE/.forks.XXXXXX")
  jq --arg i "$1" --argjson b "$block" --arg d "$(date -u +%Y-%m-%d)" '.[$i].block = $b | .[$i].pinned_on = $d' "$FORKS" > "$tmp" && mv "$tmp" "$FORKS"
  echo "fork.sh: $1 pinned at block $block; commit forks.json to keep it"
}

cmd_connect() {
  [ $# -le 1 ] || usage
  local net="ddev-${LN_SITE_PROJECT:-fresh3-e2e}_default" ids id c alias
  if ! docker network inspect "$net" >/dev/null 2>&1; then
    echo "fork.sh: no DDEV network yet; skipped"
    return 0
  fi
  ids=$(expand_ids "${1:-all}")
  for id in $ids; do
    is_running "$id" || continue
    c=$(container_of "$id"); alias="anvil-$(dash "$id")"
    if docker inspect -f '{{json .NetworkSettings.Networks}}' "$c" | jq -e --arg n "$net" 'has($n)' >/dev/null; then
      echo "fork.sh: $id already on $net"
    else
      docker network connect --alias "$alias" "$net" "$c"
      echo "fork.sh: $id joined $net as $alias"
    fi
    state_update --arg i "$id" --arg n "$net" 'if has($i) then .[$i].network = $n else . end'
  done
}

[ $# -ge 1 ] || usage
cmd=$1; shift
case "$cmd" in
  up) cmd_up "$@" ;;
  down) cmd_down "$@" ;;
  status) cmd_status "$@" ;;
  check) cmd_check "$@" ;;
  connect) cmd_connect "$@" ;;
  reset) cmd_reset "$@" ;;
  mode) cmd_mode "$@" ;;
  pin) cmd_pin "$@" ;;
  *) usage ;;
esac
