#!/usr/bin/env python3
"""Fake `curl` for provision-workflow.test.sh: a tiny in-memory Coolify env API.

It understands only the curl flags the workflow uses (-X, -o, -w, -H, --data-binary @-,
-s/-S/-f, --fail-with-body). State lives in $FAKE_STATE/envs.json; every request is
appended to $FAKE_STATE/requests.log as "METHOD URL KEY" (values are never logged there;
bodies go to $FAKE_STATE/bodies.log so tests can check literal values).

$FAKE_SCENARIO picks the failure mode:
  ok              normal Coolify 4.1 behaviour (PATCH existing=201, PATCH missing=404, POST=201)
  all503          every env PATCH/POST answers 503
  post503         PATCH missing=404, then POST answers 503
  app404          PATCH answers 404 "Application not found" (not a missing variable)
  post409_once    first POST per key answers 409 and the key appears (concurrent create)
  post409_always  POST always answers 409 and the key never appears
  readback_wrong  GET returns a different IMAGE_TAG than was saved
  readback_wrong_backup  GET returns a different AI_BACKUP_MODEL than was saved (23-40)
  hidden          GET omits `value` (token without read:sensitive)
  envs_get503     the first GET /envs answers 503; later reads work
  envs_garbage    the first GET /envs answers 200 with a body that is not JSON; later reads work
  envs_down       the first GET /envs gets no answer (curl exits 7, prints nothing); later reads work
  envs_empty      the first GET /envs answers 200 with an empty body; later reads work
  envs_blank      the first GET /envs answers 200 with a whitespace-only body; later reads work
  delete503       DELETE answers 503 and removes nothing
  delete_noop     DELETE answers 200 but removes nothing (only the read-back can tell)
  delete_curl23   DELETE answers 200 but curl exits 23
  curl23          env PATCH is applied and reports 200, but curl exits 23
  delete_late     the first 2 DELETEs of each row remove nothing, the 3rd works (only a final read-back sees it)
  deploy_stuck    GET /deployments/{uuid} keeps answering "in_progress"
  deploy_garbage  GET /deployments/{uuid} answers non-JSON
  site503         the site's /user/login keeps answering 503
  dupes_after     a stale DRUPAL_ADMIN_PASS row appears ahead of ours on the first list after
                  the password is written (i.e. before the second dedup),
                  so dedup keeps the stale one (the read-back must catch it)
  manifest_401    GET /api/provision-manifest answers 401 with a body that must never be echoed
  manifest_down   GET /api/provision-manifest: curl exits 7 (cannot connect), no output
  list503         GET /applications answers 503
  taken           GET /applications lists the subdomain's FQDN as already routed (an app with no description)
  reuse           ... as routed by app "half123" whose description is ln-provision:<the fixture provision id>
  foreign         ... as routed by an app whose description is ln-provision:<another provision id>
  reuse_dup       ... as routed by two apps, one of them tagged with the fixture provision id
  list_garbage    GET /applications answers 200 with a body that is not JSON
  create_fail     POST /applications/public answers 200 without a uuid
  deploy_fail     POST /deploy answers 200 without a deployment uuid
  deploy_cancelled  GET /deployments/{uuid} answers "cancelled"
  callback_down   POST /api/provision-callback: curl exits 7 (the sign-up app is unreachable)
  ack_down        POST /api/provision-manifest/ack: curl exits 7 (the sign-up app is unreachable)
  ack_401         POST /api/provision-manifest/ack answers 401 with a body that must never be echoed

An env written with is_shown_once true is stored with that flag, and GET /envs then leaves out its
`value` (Coolify hides write-once values on read; research Pitfall 13), so only a presence check
can verify such a key.

The manifest answers from $FAKE_STATE/manifest.json (the test writes it; a fixed fixture is the
fallback). Every request's Authorization header goes to $FAKE_STATE/auth.log as "METHOD URL VALUE"
(kept apart from requests.log, which tests grep to prove secrets never sit on a command line).
"""
import json
import os
import sys

state_dir = os.environ["FAKE_STATE"]
scenario = os.environ.get("FAKE_SCENARIO", "ok")
envs_path = os.path.join(state_dir, "envs.json")

args = sys.argv[1:]
method, out_file, write_fmt, url, body, auth = "GET", None, None, None, None, None
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
if auth is not None:
    with open(os.path.join(state_dir, "auth.log"), "a") as f:
        f.write("%s %s %s\n" % (method, url, auth))
if body:
    with open(os.path.join(state_dir, "bodies.log"), "a") as f:
        f.write(body.replace("\n", " ") + "\n")

status, resp = 200, {}
raw = None  # a body sent as is, not as JSON
prod = lambda e: not e.get("is_preview")

MANIFEST_FALLBACK = {
    "provision_id": "k3Jx9_Qm-2LpWv7RtYd4Nz", "subdomain": "demo", "site_name": "Fern Hollow Commons",
    "organizer_email": "organizer@fernhollow.example", "topic_types_b64": "WyJHYXJkZW5zIl0",
    "community_seed": "eyJhYm91dCI6IkZlcm4gSG9sbG93IENvbW1vbnMgaXMgYSBuZWlnaGJvcmhvb2QgZ3JvdXAgdGhhdCBzaGFyZXMgdG9vbHMgYW5kIG1lYWxzLiIsImdyb3VwX2Rlc2NyaXB0aW9uIjoiTmVpZ2hib3JzIG9mIEZlcm4gSG9sbG93IHNoYXJpbmcgdG9vbHMsIG1lYWxzIGFuZCByZXBhaXIgZGF5cy4iLCJhc3Npc3RhbnRfY29udGV4dCI6IkZlcm4gSG9sbG93IENvbW1vbnMgaXMgYSBuZWlnaGJvcmhvb2QgZ3JvdXAgdGhhdCBzaGFyZXMgdG9vbHMgYW5kIHBsYW5zIHJlcGFpciBkYXlzLiIsIndlbGNvbWVfcG9zdCI6IldlbGNvbWUgdG8gRmVybiBIb2xsb3cgQ29tbW9ucy4gU2F5IGhlbGxvIGFuZCB0ZWxsIHVzIHdoYXQgeW91IGNhbiBsZW5kLiJ9",
    "ai_provider": "gemini", "ai_key_source": "platform",
}

if url and "/api/provision-manifest" in url and method == "GET":
    if scenario == "manifest_401":
        status, resp = 401, {"error": "Unauthorized", "detail": "SHOULD-NOT-LEAK-401-BODY"}
    elif scenario == "manifest_down":
        sys.exit(7)
    else:
        mpath = os.path.join(state_dir, "manifest.json")
        status, resp = 200, (json.load(open(mpath)) if os.path.exists(mpath) else MANIFEST_FALLBACK)
elif url and url.endswith("/api/provision-manifest/ack") and method == "POST":
    if scenario == "ack_down":
        sys.exit(7)
    elif scenario == "ack_401":
        status, resp = 401, {"error": "Unauthorized", "detail": "SHOULD-NOT-LEAK-ACK-BODY"}
    else:
        status, resp = 200, {"ok": True}
elif url and url.endswith("/api/provision-callback") and method == "POST":
    if scenario == "callback_down":
        sys.exit(7)
    status, resp = 200, {"ok": True}
elif url and url.endswith("/applications") and method == "GET":
    if scenario == "list503":
        status, resp = 503, {"message": "Service Unavailable"}
    elif scenario == "taken":
        status, resp = 200, [{"uuid": "other1", "docker_compose_domains": json.dumps(
            [{"name": "opensocial", "domain": "https://demo.localnodes.xyz"}])}]
    elif scenario in ("reuse", "foreign", "reuse_dup"):
        domains = json.dumps([{"name": "opensocial", "domain": "https://demo.localnodes.xyz"}])
        tag = "ln-provision:AAAAAAAAAAAAAAAAAAAAAA" if scenario == "foreign" else "ln-provision:k3Jx9_Qm-2LpWv7RtYd4Nz"
        status, resp = 200, [
            {"uuid": "noise1", "description": None, "docker_compose_domains": json.dumps(
                [{"name": "opensocial", "domain": "https://elsewhere.localnodes.xyz"}])},
            {"uuid": "half123", "description": tag, "docker_compose_domains": domains}]
        if scenario == "reuse_dup":
            resp.append({"uuid": "other2", "description": "someone else", "docker_compose_domains": domains})
    elif scenario == "list_garbage":
        status, resp = 200, None
    else:
        status, resp = 200, []
elif url and url.endswith("/envs") and method == "GET" and scenario == "envs_down" and counter("envs_get") == 0:
    sys.stderr.write("curl: (7) Failed to connect\n")
    sys.exit(7)
elif url and url.endswith("/envs") and method == "GET" and scenario == "envs_get503" and counter("envs_get") == 0:
    status, resp = 503, {"message": "Service Unavailable"}
elif url and url.endswith("/envs") and method == "GET" and scenario == "envs_garbage" and counter("envs_get") == 0:
    status, resp = 200, None
elif url and url.endswith("/envs") and method == "GET" and scenario in ("envs_empty", "envs_blank") and counter("envs_get") == 0:
    status, resp, raw = 200, None, ("" if scenario == "envs_empty" else " \n\t \n")
elif url and url.endswith("/envs") and method == "GET":
    envs = load()
    if scenario == "readback_wrong":
        envs = [dict(e, value="0000000") if e["key"] == "IMAGE_TAG" else e for e in envs]
    if scenario == "readback_wrong_backup":
        envs = [dict(e, value="fixture/other-model") if e["key"] == "AI_BACKUP_MODEL" else e for e in envs]
    if scenario == "hidden":
        envs = [{k: v for k, v in e.items() if k not in ("value", "real_value")} for e in envs]
    if scenario == "drop_relayer_key":
        envs = [e for e in envs if e["key"] != "SPONSOR_RELAYER_PRIVATE_KEY"]
    envs = [{k: v for k, v in e.items() if k not in ("value", "real_value")} if e.get("is_shown_once") else e for e in envs]
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
            for flag in ("is_shown_once", "is_literal"):
                if flag in data:
                    hit[0][flag] = data[flag]
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
        envs.append({"uuid": "new-" + data["key"], "key": data["key"], "value": data["value"], "is_preview": False,
                     "is_shown_once": bool(data.get("is_shown_once")), "is_literal": bool(data.get("is_literal"))})
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
    if scenario == "deploy_cancelled":
        resp = {"status": "cancelled"}
    if scenario == "deploy_garbage":
        resp = None
elif url and url.endswith("/user/login"):
    status, resp = (503, {}) if scenario == "site503" else (200, {})
elif url and url.endswith("/applications/public") and method == "POST":
    # Coolify 4.1 ValidationPatterns::FILE_PATH_PATTERN for docker_compose_location
    import re
    loc = json.loads(body).get("docker_compose_location", "")
    if scenario == "create_fail":
        status, resp = 200, {"message": "Something broke SHOULD-NOT-LEAK-CREATE"}
    elif re.match(r"^/[a-zA-Z0-9._\-/~@+]+$", loc):
        status, resp = 201, {"uuid": "app123"}
    else:
        status, resp = 422, {"message": "Validation failed.",
                             "errors": {"docker_compose_location": ["The docker compose location field format is invalid."]}}
elif url and "/deploy?" in url:
    status, resp = (200, {"message": "no deployment SHOULD-NOT-LEAK-DEPLOY"}) if scenario == "deploy_fail" else (200, {"deployments": [{"deployment_uuid": "dep123"}]})
else:
    status, resp = 200, {}

if raw is not None:
    text = raw
else:
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
