#!/usr/bin/env python3
"""Fake `curl` for teardown-workflow.test.sh: an in-memory Coolify application list plus the
sign-up app's teardown-check and teardown-result routes. Nothing leaves this process.

It understands only the curl flags the workflow uses (-X, -o, -w, -H, --data-binary @-,
-s/-S/-f, --fail-with-body, --connect-timeout, --max-time). It is separate from fake-curl.py (provision-workflow.test.sh), so
the two tests never collide.

State lives in $FAKE_STATE:
  apps.json     the application list: [{uuid, name, description, docker_compose_domains}]
  requests.log  one line per request, "METHOD URL" (the full URL, query string included)
  auth.log      "METHOD URL VALUE" for every request that sent an Authorization header
                (kept apart from requests.log, which tests grep to prove no secret sits on a
                command line)
  bodies.log    every request body, one per line
  flags.log     "METHOD URL connect=<--connect-timeout or none> max=<--max-time or none>" for
                every request, so the test can hold every call to its time bounds (23-43)
  leak.log      "METHOD URL" of any request to a host other than coolify.test or cb.test;
                such a request also fails like curl does on an unknown host (exit 6)

$FAKE_SCENARIO picks the behaviour (the test writes apps.json to match):
  ok                  normal Coolify 4.1: DELETE answers 200 and removes the app
  delete_late         DELETE answers 200; the app is still listed for 2 more reads, then gone
  delete_slow         DELETE answers 200 but the app never goes away
  delete500           DELETE answers 500 and removes nothing
  reread_mismatch     GET /applications/{uuid} shows a different description than the list
  list503             GET /applications answers 503
  list_garbage        GET /applications answers 200 with a body that is not JSON
  list_object         GET /applications answers 200 with a JSON object, not a list
  check_refused       teardown-check answers 409 {"teardown":false}
  check_true_not_200  teardown-check answers 500 with {"teardown":true}
  check_garbage       teardown-check answers 200 with a body that is not JSON
  check_false         teardown-check answers 200 {"teardown":false}
  check_string        teardown-check answers 200 {"teardown":"true"} (a string, not true)
  check_down          teardown-check: curl exits 7 (cannot connect), no output
  check_flips         teardown-check answers 200 {"teardown":true} the first time and
                      409 {"teardown":false} every time after (the sign-up changed meanwhile)
  result_down         teardown-result: curl exits 7 (cannot connect)
"""
import json
import os
import sys
from urllib.parse import urlsplit

state_dir = os.environ["FAKE_STATE"]
scenario = os.environ.get("FAKE_SCENARIO", "ok")
apps_path = os.path.join(state_dir, "apps.json")

COOLIFY = "coolify.test"
CALLBACK = "cb.test"
API_PREFIX = "/api/v1"

args = sys.argv[1:]
method, out_file, write_fmt, url, body, auth = "GET", None, None, None, None, None
connect_timeout, max_time = "none", "none"
fail = False
i = 0
while i < len(args):
    a = args[i]
    if a == "-X":
        method = args[i + 1]; i += 2; continue
    if a == "-o" or (len(a) > 2 and a[0] == "-" and a[1] != "-" and a.endswith("o")):  # -o, -so
        out_file = args[i + 1]; i += 2; continue
    if a == "-w":
        write_fmt = args[i + 1]; i += 2; continue
    if a == "--connect-timeout":
        connect_timeout = args[i + 1]; i += 2; continue
    if a == "--max-time":
        max_time = args[i + 1]; i += 2; continue
    if a == "-H":
        if args[i + 1].lower().startswith("authorization:"):
            auth = args[i + 1].split(":", 1)[1].strip()
        i += 2; continue
    if a in ("--data-binary", "-d"):
        v = args[i + 1]
        body = sys.stdin.read() if v == "@-" else v
        i += 2; continue
    if a == "--fail-with-body" or (a.startswith("-") and not a.startswith("--") and "f" in a):
        fail = True
    if a.startswith("http"):
        url = a
    i += 1


def log(name, line):
    with open(os.path.join(state_dir, name), "a") as f:
        f.write(line + "\n")


def load():
    with open(apps_path) as f:
        return json.load(f)


def save(apps):
    with open(apps_path, "w") as f:
        json.dump(apps, f)


def counter(name):
    p = os.path.join(state_dir, "count-" + name)
    n = int(open(p).read()) if os.path.exists(p) else 0
    open(p, "w").write(str(n + 1))
    return n


log("requests.log", "%s %s" % (method, url))
log("flags.log", "%s %s connect=%s max=%s" % (method, url, connect_timeout, max_time))
if auth is not None:
    log("auth.log", "%s %s %s" % (method, url, auth))
if body:
    log("bodies.log", body.replace("\n", " "))

parts = urlsplit(url or "")
host, path = parts.hostname, parts.path

if host not in (COOLIFY, CALLBACK):
    log("leak.log", "%s %s" % (method, url))
    sys.stderr.write("curl: (6) Could not resolve host: %s\n" % host)
    sys.exit(6)

status, resp = 200, {}
if host == CALLBACK and path == "/api/teardown-check" and method == "GET":
    if scenario == "check_down":
        sys.stderr.write("curl: (7) Failed to connect to cb.test port 443\n")
        sys.exit(7)
    elif scenario == "check_refused":
        status, resp = 409, {"teardown": False}
    elif scenario == "check_true_not_200":
        status, resp = 500, {"teardown": True}
    elif scenario == "check_garbage":
        status, resp = 200, None
    elif scenario == "check_false":
        status, resp = 200, {"teardown": False}
    elif scenario == "check_string":
        status, resp = 200, {"teardown": "true"}
    elif scenario == "check_flips" and counter("check") >= 1:
        status, resp = 409, {"teardown": False}
    else:
        status, resp = 200, {"teardown": True}
elif host == CALLBACK and path == "/api/teardown-result" and method == "POST":
    if scenario == "result_down":
        sys.stderr.write("curl: (7) Failed to connect to cb.test port 443\n")
        sys.exit(7)
    status, resp = 200, {"ok": True, "detail": "SHOULD-NOT-LEAK-RESULT-BODY"}
elif host == COOLIFY and path == API_PREFIX + "/applications" and method == "GET":
    if scenario == "list503":
        status, resp = 503, {"message": "Service Unavailable"}
    elif scenario == "list_garbage":
        status, resp = 200, None
    elif scenario == "list_object":
        status, resp = 200, {"data": load()}
    else:
        status, resp = 200, load()
elif host == COOLIFY and path.startswith(API_PREFIX + "/applications/") and path.count("/") == 4:
    uuid = path.rsplit("/", 1)[1]
    apps = load()
    hit = [a for a in apps if a["uuid"] == uuid]
    if method == "GET":
        if scenario == "delete_late" and os.path.exists(os.path.join(state_dir, "deleted-" + uuid)):
            if counter("late-" + uuid) >= 2:
                save([a for a in apps if a["uuid"] != uuid])
                hit = []
        if not hit:
            status, resp = 404, {"message": "Resource not found."}
        else:
            app = dict(hit[0])
            if scenario == "reread_mismatch":
                app["description"] = "ln-provision:AAAAAAAAAAAAAAAAAAAAAA"
            status, resp = 200, app
    elif method == "DELETE":
        if not hit:
            status, resp = 404, {"message": "Resource not found."}
        elif scenario == "delete500":
            status, resp = 500, {"message": "Server Error", "detail": "SHOULD-NOT-LEAK-DELETE-BODY"}
        else:
            open(os.path.join(state_dir, "deleted-" + uuid), "w").close()
            if scenario not in ("delete_slow", "delete_late"):
                save([a for a in apps if a["uuid"] != uuid])
            status, resp = 200, {"message": "Application deletion request queued."}
    else:
        status, resp = 405, {"message": "Method not allowed in this fake"}
else:
    status, resp = 404, {"message": "No such route in this fake"}

text = "<html>502 Bad Gateway</html>" if resp is None else json.dumps(resp)
if out_file:
    with open(out_file, "w") as f:
        f.write(text)
else:
    sys.stdout.write(text)
if write_fmt:
    sys.stdout.write(write_fmt.replace("%{http_code}", str(status)))
sys.exit(22 if fail and status >= 400 else 0)
