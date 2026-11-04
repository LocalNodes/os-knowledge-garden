#!/usr/bin/env python3
"""Fake `curl` for provision-workflow.test.sh: a tiny in-memory Coolify env API.

It understands only the curl flags the workflow uses (-X, -o, -w, -H, --data-binary @-,
-s/-S/-f, --fail-with-body). State lives in $FAKE_STATE/envs.json; every request is
appended to $FAKE_STATE/requests.log as "METHOD URL KEY" (values are never logged there;
bodies go to $FAKE_STATE/bodies.log so tests can check literal values).

$FAKE_SCENARIO picks the failure mode:
  ok              normal Coolify 4.1 behaviour (PATCH existing=201, PATCH missing=404, POST=201)
  all503          every env PATCH/POST answers 503 (Codex's reproduction)
  post503         PATCH missing=404, then POST answers 503
  app404          PATCH answers 404 "Application not found" (not a missing variable)
  post409_once    first POST per key answers 409 and the key appears (concurrent create)
  post409_always  POST always answers 409 and the key never appears
  readback_wrong  GET returns a different IMAGE_TAG than was saved
  hidden          GET omits `value` (token without read:sensitive)
  delete503       DELETE answers 503 and removes nothing (Codex re-check reproduction)
  delete_noop     DELETE answers 200 but removes nothing (only the read-back can tell)
  delete_curl23   DELETE answers 200 but curl exits 23
  curl23          env PATCH is applied and reports 200, but curl exits 23 (Codex re-check)
  delete_late     the first 2 DELETEs of each row remove nothing, the 3rd works (only a final read-back sees it)
  deploy_stuck    GET /deployments/{uuid} keeps answering "in_progress"
  deploy_garbage  GET /deployments/{uuid} answers non-JSON
  site503         the site's /user/login keeps answering 503
  dupes_after     a stale DRUPAL_ADMIN_PASS row appears ahead of ours on the first list after
                  the password is written (i.e. before the second dedup),
                  so dedup keeps the stale one (the read-back must catch it)
"""
import json
import os
import sys

state_dir = os.environ["FAKE_STATE"]
scenario = os.environ.get("FAKE_SCENARIO", "ok")
envs_path = os.path.join(state_dir, "envs.json")

args = sys.argv[1:]
method, out_file, write_fmt, url, body = "GET", None, None, None, None
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
    if a == "-H":
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


def load():
    with open(envs_path) as f:
        return json.load(f)


def save(envs):
    with open(envs_path, "w") as f:
        json.dump(envs, f)


def counter(name):
    p = os.path.join(state_dir, "count-" + name)
    n = int(open(p).read()) if os.path.exists(p) else 0
    open(p, "w").write(str(n + 1))
    return n


key = None
if body:
    try:
        key = json.loads(body).get("key")
    except ValueError:
        pass
with open(os.path.join(state_dir, "requests.log"), "a") as f:
    f.write("%s %s %s\n" % (method, url, key or "-"))
if body:
    with open(os.path.join(state_dir, "bodies.log"), "a") as f:
        f.write(body.replace("\n", " ") + "\n")

status, resp = 200, {}
prod = lambda e: not e.get("is_preview")

if url and url.endswith("/envs") and method == "GET":
    envs = load()
    if scenario == "readback_wrong":
        envs = [dict(e, value="0000000") if e["key"] == "IMAGE_TAG" else e for e in envs]
    if scenario == "hidden":
        envs = [{k: v for k, v in e.items() if k not in ("value", "real_value")} for e in envs]
    written = os.path.exists(os.path.join(state_dir, "pass-written"))
    if scenario == "dupes_after" and written and counter("stale") == 0:
        envs.insert(0, {"uuid": "stale1", "key": "DRUPAL_ADMIN_PASS", "value": "compose-default", "is_preview": False})
        save(envs)
    resp = envs
elif url and url.endswith("/envs") and method == "PATCH":
    data = json.loads(body)
    envs = load()
    if scenario == "all503":
        status, resp = 503, {"message": "Service Unavailable"}
    elif scenario == "app404":
        status, resp = 404, {"message": "Application not found"}
    else:
        hit = [e for e in envs if e["key"] == data["key"] and prod(e)]
        if data["key"] == "DRUPAL_ADMIN_PASS":
            open(os.path.join(state_dir, "pass-written"), "w").close()
        if hit:
            hit[0]["value"] = data["value"]
            save(envs)
            status, resp = 201, {"key": data["key"]}
        else:
            status, resp = 404, {"message": "Environment variable not found."}
elif url and url.endswith("/envs") and method == "POST":
    data = json.loads(body)
    envs = load()
    if scenario in ("all503", "post503"):
        status, resp = 503, {"message": "Service Unavailable"}
    elif scenario == "post409_always":
        status, resp = 409, {"message": "Environment variable already exists. Use PATCH request to update it."}
    elif scenario == "post409_once" and counter("post-" + data["key"]) == 0:
        envs.append({"uuid": "race-" + data["key"], "key": data["key"], "value": "from-compose", "is_preview": False})
        save(envs)
        status, resp = 409, {"message": "Environment variable already exists. Use PATCH request to update it."}
    else:
        envs.append({"uuid": "new-" + data["key"], "key": data["key"], "value": data["value"], "is_preview": False})
        save(envs)
        status, resp = 201, {"uuid": "new-" + data["key"]}
elif url and "/envs/" in url and method == "DELETE":
    uuid = url.rsplit("/", 1)[1]
    if scenario == "delete503":
        status, resp = 503, {"message": "Service Unavailable"}
    else:
        if scenario != "delete_noop" and not (scenario == "delete_late" and counter("del-" + uuid) < 2):
            save([e for e in load() if e["uuid"] != uuid])
        status, resp = 200, {"message": "Environment variable deleted."}
elif url and "/deployments/" in url:
    status = 200
    resp = {"status": "in_progress"} if scenario == "deploy_stuck" else {"status": "finished"}
    if scenario == "deploy_garbage":
        resp = None
elif url and url.endswith("/user/login"):
    status, resp = (503, {}) if scenario == "site503" else (200, {})
elif url and url.endswith("/applications/public") and method == "POST":
    # Coolify 4.1 ValidationPatterns::FILE_PATH_PATTERN for docker_compose_location
    import re
    loc = json.loads(body).get("docker_compose_location", "")
    if re.match(r"^/[a-zA-Z0-9._\-/~@+]+$", loc):
        status, resp = 201, {"uuid": "app123"}
    else:
        status, resp = 422, {"message": "Validation failed.",
                             "errors": {"docker_compose_location": ["The docker compose location field format is invalid."]}}
elif url and "/deploy?" in url:
    status, resp = 200, {"deployments": [{"deployment_uuid": "dep123"}]}
else:
    status, resp = 200, {}

text = "<html>502 Bad Gateway</html>" if resp is None else json.dumps(resp)
if out_file:
    with open(out_file, "w") as f:
        f.write(text)
else:
    sys.stdout.write(text)
if write_fmt:
    sys.stdout.write(write_fmt.replace("%{http_code}", str(status)))
if (scenario == "curl23" and method == "PATCH" and url and url.endswith("/envs")) or \
        (scenario == "delete_curl23" and method == "DELETE"):
    sys.exit(23)  # curl "write error" after reporting the HTTP status
sys.exit(22 if fail and status >= 400 else 0)
