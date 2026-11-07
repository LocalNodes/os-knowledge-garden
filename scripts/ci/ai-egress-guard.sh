#!/usr/bin/env bash
# AI egress guard for the phase22-evals CI job (Phase 22, plan 22-29).
#
# The CI tests answer every model call from recorded fixtures and mocks
# (ScriptedLlmSubscriber, echoai, Guzzle MockHandler). This guard makes the
# job fail if any request to an AI provider is attempted anyway, even one
# whose error a test swallows.
#
#   arm    Map each provider host to 127.0.0.2 in /etc/hosts, start a
#          recorder on 127.0.0.2:443 and :80 that logs every connection it
#          accepts (and answers none), and check that nothing can reach the
#          real host: each name resolves only to 127.0.0.2, the recorder
#          logs a self-test connection, and no provider key is in the
#          environment. Run before the tests.
#   check  Fail if the recorder logged any connection, if it died (a dead
#          recorder would be blind), or if a mapping was lost. Run after
#          the tests, with `if: always()`.
#
# The guard is network-level, so it sees an attempt whether or not the test
# that made it fails. It doesn't see a client that bypasses the system
# resolver (DNS over HTTPS, a hard-coded IP); nothing in the tree does that.
#
# Needs root or passwordless sudo (GitHub's ubuntu runners have it).
# Proof that it fires: 22-29-GUARD-PROOF.txt in the Phase 22 folder.
set -euo pipefail

HOSTS=(
  generativelanguage.googleapis.com
  aiplatform.googleapis.com
  api.openai.com
  api.anthropic.com
  api.mistral.ai
  api.groq.com
  api.deepseek.com
)
SINK_IP=127.0.0.2
STATE_DIR="${AI_GUARD_DIR:-/tmp/ai-egress-guard}"
LOG="$STATE_DIR/connections.log"
PIDFILE="$STATE_DIR/recorder.pid"
KEY_VARS=(GEMINI_API_KEY GOOGLE_API_KEY OPENAI_API_KEY ANTHROPIC_API_KEY MISTRAL_API_KEY GROQ_API_KEY DEEPSEEK_API_KEY)

SUDO=""
if [ "$(id -u)" != "0" ]; then
  SUDO="sudo"
fi

fail() {
  echo "ai-egress-guard: FAIL: $*" >&2
  exit 1
}

check_mapping() {
  local host addrs
  for host in "${HOSTS[@]}"; do
    addrs="$(getent ahosts "$host" | awk '{print $1}' | sort -u | tr '\n' ' ')"
    [ "$addrs" = "$SINK_IP " ] || fail "$host resolves to '${addrs}', not only $SINK_IP"
  done
}

arm() {
  local var host
  for var in "${KEY_VARS[@]}"; do
    [ -z "${!var:-}" ] || fail "$var is set; the CI tests must run without provider keys"
  done

  mkdir -p "$STATE_DIR"
  : > "$LOG"
  for host in "${HOSTS[@]}"; do
    echo "$SINK_IP $host" | $SUDO tee -a /etc/hosts > /dev/null
  done
  check_mapping

  # The recorder: accept, log one line, close. Never answers, never forwards.
  $SUDO nohup python3 - "$SINK_IP" "$LOG" > "$STATE_DIR/recorder.out" 2>&1 <<'PY' &
import socket, sys, threading, time
ip, log = sys.argv[1], sys.argv[2]
def serve(port):
    s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    s.bind((ip, port))
    s.listen(64)
    while True:
        conn, peer = s.accept()
        with open(log, "a") as f:
            f.write(f"{time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())} port={port} from={peer[0]}:{peer[1]}\n")
        conn.close()
for port in (443, 80):
    threading.Thread(target=serve, args=(port,), daemon=True).start()
while True:
    time.sleep(3600)
PY
  echo $! > "$PIDFILE"

  # Self-test: a connection the recorder must log. Then start from empty.
  local i
  for i in $(seq 1 50); do
    if (exec 3<>"/dev/tcp/$SINK_IP/443") 2>/dev/null; then
      break
    fi
    sleep 0.1
  done
  for i in $(seq 1 50); do
    [ -s "$LOG" ] && break
    sleep 0.1
  done
  [ -s "$LOG" ] || fail "the recorder did not log its self-test connection: $(cat "$STATE_DIR/recorder.out" 2>/dev/null)"
  $SUDO sh -c ": > '$LOG'"
  echo "ai-egress-guard: armed (${#HOSTS[@]} provider hosts -> $SINK_IP, recorder pid $(cat "$PIDFILE"))"
}

check() {
  [ -f "$PIDFILE" ] || fail "the guard was never armed"
  $SUDO kill -0 "$(cat "$PIDFILE")" 2>/dev/null || fail "the recorder is not running, so attempts would go unseen"
  check_mapping
  if [ -s "$LOG" ]; then
    echo "ai-egress-guard: connections to an AI provider host were attempted:" >&2
    cat "$LOG" >&2
    fail "$(wc -l < "$LOG" | tr -d ' ') attempted AI request(s); the CI tests must make none"
  fi
  echo "ai-egress-guard: OK, no AI request was attempted"
}

case "${1:-}" in
  arm) arm ;;
  check) check ;;
  *) echo "usage: $0 arm|check" >&2; exit 2 ;;
esac
