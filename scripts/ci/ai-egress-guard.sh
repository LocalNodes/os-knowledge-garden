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
DIAG="$STATE_DIR/diagnostics.log"
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
  : > "$DIAG"
  for host in "${HOSTS[@]}"; do
    echo "$SINK_IP $host" | $SUDO tee -a /etc/hosts > /dev/null
  done
  check_mapping

  # The recorder: accept, log one line, close. Never answers, never forwards.
  # The accept line is written before anything else, and check decides from
  # those lines alone. Then, best effort, a second file names the host the
  # client asked for (the TLS server name, or the HTTP Host header) and the
  # process that owns the connection (pid and name only: a command line can
  # hold a key), so a failure says what made it.
  $SUDO nohup python3 - "$SINK_IP" "$LOG" "$DIAG" > "$STATE_DIR/recorder.out" 2>&1 <<'PY' &
import os, re, socket, sys, threading, time
ip, log, diag = sys.argv[1], sys.argv[2], sys.argv[3]
lock = threading.Lock()
NAME = re.compile(rb"^[A-Za-z0-9.-]{1,253}$")

def write(path, line):
    with lock, open(path, "a") as f:
        f.write(line + "\n")

def u16(data, k):
    if k + 2 > len(data):
        raise ValueError
    return int.from_bytes(data[k:k + 2], "big")

def server_name(data):
    # A ClientHello's server_name, every length checked; None when malformed.
    if len(data) < 9 or data[0] != 0x16 or data[5] != 0x01:
        return None
    rec_end = 5 + u16(data, 3)
    hs_end = 9 + int.from_bytes(data[6:9], "big")
    end = min(rec_end, hs_end, len(data))
    k = 9 + 2 + 32
    if k + 1 > end:
        return None
    k += 1 + data[k]
    k += 2 + u16(data, k)
    if k + 1 > end:
        return None
    k += 1 + data[k]
    ext_end = k + 2 + u16(data, k)
    if ext_end > end:
        return None
    k += 2
    while k + 4 <= ext_end:
        kind, size = u16(data, k), u16(data, k + 2)
        body, k = k + 4, k + 4 + size
        if k > ext_end:
            return None
        if kind != 0:
            continue
        if size < 5 or u16(data, body) != size - 2 or data[body + 2] != 0:
            return None
        n = u16(data, body + 3)
        if body + 5 + n > k:
            return None
        name = data[body + 5:body + 5 + n]
        return name.decode("ascii") if NAME.fullmatch(name) else None
    return None

def asked_host(conn, port):
    # The first bytes only, within two seconds in all.
    deadline = time.monotonic() + 2
    data = b""
    try:
        while len(data) < 4096:
            left = deadline - time.monotonic()
            if left <= 0:
                break
            conn.settimeout(left)
            chunk = conn.recv(4096 - len(data))
            if not chunk:
                break
            data += chunk
            if port == 80 and b"\r\n\r\n" in data:
                break
            if port == 443 and len(data) >= 5 and len(data) >= 5 + int.from_bytes(data[3:5], "big"):
                break
    except OSError:
        pass
    if port == 80:
        head = data.split(b"\r\n\r\n", 1)[0]
        for line in head.split(b"\r\n")[1:]:
            key, _, value = line.partition(b":")
            if key.strip().lower() == b"host":
                value = value.strip().split(b":", 1)[0]
                return value.decode("ascii") if NAME.fullmatch(value) else "malformed"
        return "none"
    try:
        return server_name(data) or "none"
    except (IndexError, ValueError):
        return "none"

def owner(peer, port):
    # The client's socket in /proc/net/tcp, then the process holding it.
    want_local = "%s:%04X" % (socket.inet_aton(peer[0])[::-1].hex().upper(), peer[1])
    want_remote = "%s:%04X" % (socket.inet_aton(ip)[::-1].hex().upper(), port)
    inode = None
    with open("/proc/net/tcp") as f:
        for row in f.readlines()[1:]:
            cols = row.split()
            if cols[1] == want_local and cols[2] == want_remote:
                inode = cols[9]
                break
    if not inode or inode == "0":
        return "unknown"
    target = "socket:[%s]" % inode
    for pid in filter(str.isdigit, os.listdir("/proc")):
        try:
            for fd in os.listdir("/proc/%s/fd" % pid):
                if os.readlink("/proc/%s/fd/%s" % (pid, fd)) == target:
                    with open("/proc/%s/comm" % pid, "rb") as c:
                        comm = re.sub(rb"[^A-Za-z0-9._-]", b"?", c.read().strip()).decode("ascii")
                    return "pid=%s comm=%s" % (pid, comm)
        except OSError:
            continue
    return "unknown"

def handle(conn, peer, port, at):
    who, host = "unknown", "none"
    try:
        try:
            who = owner(peer, port)
        except Exception:
            who = "unknown"
        try:
            host = asked_host(conn, port)
        except Exception:
            host = "none"
    finally:
        try:
            conn.close()
        finally:
            write(diag, f"{at} port={port} from={peer[0]}:{peer[1]} host={host} process={who}")

def serve(port):
    s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    s.bind((ip, port))
    s.listen(64)
    while True:
        conn, peer = s.accept()
        at = time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())
        write(log, f"{at} port={port} from={peer[0]}:{peer[1]}")
        try:
            threading.Thread(target=handle, args=(conn, peer, port, at), daemon=True).start()
        except Exception:
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
  for i in $(seq 1 30); do
    [ -s "$DIAG" ] && break
    sleep 0.1
  done
  $SUDO sh -c ": > '$LOG'; : > '$DIAG'"
  echo "ai-egress-guard: armed (${#HOSTS[@]} provider hosts -> $SINK_IP, recorder pid $(cat "$PIDFILE"))"
}

check() {
  [ -f "$PIDFILE" ] || fail "the guard was never armed"
  $SUDO kill -0 "$(cat "$PIDFILE")" 2>/dev/null || fail "the recorder is not running, so attempts would go unseen"
  check_mapping
  if [ -s "$LOG" ]; then
    echo "ai-egress-guard: connections to an AI provider host were attempted:" >&2
    cat "$LOG" >&2
    # The diagnostics finish within two seconds of each connection.
    sleep 3
    echo "ai-egress-guard: by host and process (best effort):" >&2
    sed -E 's/^.* (host=.*)$/\1/' "$DIAG" | sort | uniq -c | sort -rn >&2
    fail "$(wc -l < "$LOG" | tr -d ' ') attempted AI request(s); the CI tests must make none"
  fi
  echo "ai-egress-guard: OK, no AI request was attempted"
}

case "${1:-}" in
  arm) arm ;;
  check) check ;;
  *) echo "usage: $0 arm|check" >&2; exit 2 ;;
esac
