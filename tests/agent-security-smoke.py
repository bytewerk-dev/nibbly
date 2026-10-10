#!/usr/bin/env python3
"""Security of the agent API over HTTP: auth, CSRF, methods, rights, ownership, traversal, secrets, sanitising, limits, audit."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import subprocess
import tempfile
from threading import Thread
from support import Client, Site, write_json

os.environ["PHP_CLI_SERVER_WORKERS"] = "8"
requests = []
queue = []
SECRET_HASH = "$2y$10$AGENTSECRETHASHVALUEfortestsonlyDoNotLeak0123456789abcdefgh"
GOAL_MARKER = "SECRET-GOAL-TEXT-4711"


class Provider(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        requests.append(body)
        answer = queue.pop(0) if queue else {"choices": [{"message": {"role": "assistant", "content": "unscripted"}, "finish_reason": "stop"}], "usage": {"prompt_tokens": 1, "completion_tokens": 1}}
        data = json.dumps(answer).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)


def call(name, arguments, call_id):
    return {"id": call_id, "type": "function", "function": {"name": name, "arguments": json.dumps(arguments)}}


def turn(*calls):
    return {"choices": [{"message": {"role": "assistant", "content": None, "tool_calls": list(calls)}, "finish_reason": "tool_calls"}], "usage": {"prompt_tokens": 120, "completion_tokens": 30}}


def say(text):
    return {"choices": [{"message": {"role": "assistant", "content": text}, "finish_reason": "stop"}], "usage": {"prompt_tokens": 120, "completion_tokens": 30}}


def tree(path):
    return sorted(str(p.relative_to(path)) for p in path.rglob("*") if p.is_file() and "ai-agent" not in p.parts and "ai-audit" not in p.parts and "ai-usage" not in p.name and "rate" not in p.parts)


with tempfile.TemporaryDirectory(prefix="nibbly-agent-security-") as folder:
    site = Site(folder)
    root = site.root
    editor = next(u for u in site.users if u["role"] == "editor")
    site.users.append({**editor, "id": "u_editor2", "username": "editor2", "email": "editor2@example.invalid"})
    write_json(root / "content/users.json", {"users": site.users})
    write_json(root / "content/settings.json", {"modules": {"ai": True}, "privacy": {"analyticsEnabled": False}})
    write_json(root / "content/pages/en_secret.json", {"page": "en_secret", "lang": "en", "title": "Board minutes", "visibility": {"status": "private", "passwordHash": SECRET_HASH},
                                                       "sections": [{"id": "s", "type": "text", "title": "Minutes", "content": "<p>Confidential</p>"}]})
    server = ThreadingHTTPServer(("127.0.0.1", 0), Provider)
    Thread(target=server.serve_forever, daemon=True).start()
    site.start()

    defaults = json.loads(subprocess.run(["php", "-d", "display_errors=0", "-r", "require 'includes/ai/ai-helper.php'; echo json_encode(nibblyAiDefaults());"], cwd=root, text=True, capture_output=True, check=True).stdout)

    def configure(agent=None, modules=True):
        settings = json.loads(json.dumps(defaults))
        settings.update(enabled=True, provider="openai-compatible", allowLocalProvider=True, chatModel="gpt-4.1-mini", baseUrl=f"http://127.0.0.1:{server.server_port}", apiKey="test-key")
        settings["features"]["backendAssistant"] = True
        settings["providerCredentials"]["openai-compatible"]["baseUrl"] = settings["baseUrl"]
        settings["limits"].update(dailyRequests=1000, dailyTextRequests=1000, monthlyBudgetCents=100000, maxInputTokens=60000)
        agent = agent or {}
        settings["agent"] = {"enabled": True, **agent, "limits": {"runsPerHour": 100, "stepsPerMinute": 600, **agent.get("limits", {})}}
        write_json(root / "content/ai-settings.json", settings)
        write_json(root / "content/settings.json", {"modules": {"ai": modules}, "privacy": {"analyticsEnabled": False}})

    configure()
    visitor = Client(site)
    owner = Client(site).login("editor")
    other = Client(site).login("editor2")
    admin = Client(site).login("admin")

    READS = ["ai-agent-status", "ai-agent-changeset", "ai-agent-media"]
    WRITES = ["ai-agent-start", "ai-agent-step", "ai-agent-reply", "ai-agent-cancel", "ai-agent-revalidate", "ai-agent-apply", "ai-agent-discard", "ai-agent-undo", "ai-agent-publish", "ai-agent-upload"]

    def post(client, action, **fields):
        return client.api(action, **fields)

    def get(client, action, **query):
        qs = "&".join(f"{k}={v}" for k, v in query.items())
        status, headers, body = client.request(f"/admin/api.php?action={action}&{qs}")
        try:
            return status, json.loads(body)
        except ValueError:
            return status, {"raw": body[:100]}

    def run_to_end(client, run_id, max_steps=40):
        for _ in range(max_steps):
            status, payload = post(client, "ai-agent-step", runId=run_id)
            assert status == 200 and payload["success"], payload
            if payload["data"]["status"] not in ("running", "busy"):
                return payload["data"]
        raise AssertionError("run did not finish")

    def package(client, goal="Add a news post"):
        queue[:] = [turn(call("stage_create_news", {"lang": "en", "title": "Security news", "date": "2026-10-10", "contentHtml": "<p>Body</p>"}, "n1")),
                    turn(call("validate_changeset", {}, "v1")), turn(call("present_changeset", {"summary": "Done"}, "p1"))]
        status, payload = post(client, "ai-agent-start", goal=goal)
        assert payload["success"], payload
        state = run_to_end(client, payload["data"]["runId"])
        assert state["status"] == "awaiting_review", state
        return state["runId"], state["changesetId"]

    try:
        # ---- Not signed in -----------------------------------------------------------------------------------------------------------------
        for action in WRITES:
            status, payload = post(visitor, action)
            assert status in (401, 403) and payload["success"] is False, (action, status, payload)
        for action in READS:
            status, payload = get(visitor, action, runId="run_00000000", id="cs_0000000000000000", ref="m_00000000")
            assert status in (401, 403) and payload.get("success") is not True, (action, status, payload)
        status, _, body = visitor.request("/services/test/")
        assert status == 200 and b"NB_AI_AGENT" not in body and b"ai-agent.js" not in body, "guests get no agent"

        # ---- CSRF and methods -------------------------------------------------------------------------------------------------------------------
        for action in WRITES:
            status, _, body = owner.request("/admin/api.php", {"action": action})
            assert status == 403 and json.loads(body)["success"] is False, (action, "missing CSRF token", status)
            status, _, body = owner.request("/admin/api.php", {"action": action, "csrf_token": "forged"})
            assert status == 403, (action, "forged CSRF token", status)
            status, _, body = owner.request(f"/admin/api.php?action={action}")
            assert status == 405, (action, "GET on a write action", status)
        status, _, _ = owner.request("/admin/api.php?action=ai-agent-start", method="PUT")
        assert status == 405, "other methods"

        # ---- Switched off ----------------------------------------------------------------------------------------------------------------------------
        configure(agent={"enabled": False})
        status, payload = post(owner, "ai-agent-start", goal="Hello")
        assert payload["success"] is False and "switched off" in payload["message"], payload
        status, _, body = owner.request("/services/test/")
        assert b"NB_AI_AGENT" not in body and b"ai-agent.js" not in body, "no agent on the page when it is switched off"
        configure(modules=False)
        status, payload = post(owner, "ai-agent-start", goal="Hello")
        assert payload["success"] is False and "disabled" in payload["message"], payload
        configure()
        status, _, body = owner.request("/services/test/")
        text = body.decode()
        assert "window.NB_AI_AGENT" in text and "ai-agent.js" in text, "editors get the agent"
        assert '"publish":false' in text.replace(" ", "") and '"uploadImages":true' in text.replace(" ", ""), "the page tells the editor's rights"
        assert '"publish":true' in admin.request("/services/test/")[2].decode().replace(" ", ""), "admins may publish"

        # ---- Ownership: nobody else sees or touches a run, a package or its images --------------------------------------------------
        run_id, cs_id = package(owner, GOAL_MARKER)
        review = get(owner, "ai-agent-changeset", id=cs_id)[1]["data"]
        for action, fields in [("ai-agent-step", {"runId": run_id}), ("ai-agent-reply", {"runId": run_id, "message": "hi"}), ("ai-agent-cancel", {"runId": run_id}),
                               ("ai-agent-revalidate", {"id": cs_id}), ("ai-agent-apply", {"id": cs_id, "previewHash": review["previewHash"], "confirmed": "1"}), ("ai-agent-discard", {"id": cs_id}),
                               ("ai-agent-undo", {"id": cs_id, "token": cs_id + ".x"}), ("ai-agent-publish", {"id": cs_id, "item": json.dumps({"kind": "news", "target": "news:2026-10-10-security-news"})})]:
            status, payload = post(other, action, **fields)
            assert payload["success"] is False and status != 200 or payload["success"] is False, (action, status, payload)
            assert (payload.get("data") or {}).get("status", "missing") in ("missing", None), ("another user learns nothing about the package", action, payload)
        assert get(other, "ai-agent-status", runId=run_id)[0] == 404 and get(other, "ai-agent-changeset", id=cs_id)[0] == 404, "no peeking"
        assert get(other, "ai-agent-media", id=cs_id, ref="m_00000000")[0] == 404
        assert not list((root / "content/news").glob("*security*")), "nothing was applied by someone else"
        assert get(admin, "ai-agent-changeset", id=cs_id)[0] == 200, "admins may look"
        # Invalid ids never reach the file system
        for bad in ["../x", "run_../../etc", "..%2f..%2fadmin", "run_" + "a" * 200, ""]:
            assert get(owner, "ai-agent-status", runId=bad)[1].get("success") is not True, bad
        for bad in ["../content/settings", "cs_../../x", "cs_" + "f" * 200]:
            assert get(owner, "ai-agent-changeset", id=bad)[1].get("success") is not True, bad

        # ---- Publishing needs the right ---------------------------------------------------------------------------------------------------------
        status, payload = post(owner, "ai-agent-apply", id=cs_id, previewHash=review["previewHash"], confirmed="1")
        assert status == 200 and payload["success"], payload
        item = payload["data"]["items"][0]
        status, payload = post(owner, "ai-agent-publish", id=cs_id, item=json.dumps(item))
        assert status == 403 and payload["success"] is False, (status, payload)
        news_file = next((root / "content/news").glob("*security*.json"))
        assert json.loads(news_file.read_text())["hidden"] is True, "still hidden"
        forged = dict(item, target="news:2026-10-10-other-post")
        assert post(admin, "ai-agent-publish", id=cs_id, item=json.dumps(forged))[1]["success"] is False, "only items of the package can be published"
        assert post(admin, "ai-agent-publish", id=cs_id, item=json.dumps(item))[1]["success"] is True and json.loads(news_file.read_text()).get("hidden") is not True, "admins may publish"

        # ---- What the agent may touch ---------------------------------------------------------------------------------------------------------------
        before = tree(root)
        attempts = [
            ("read_content", {"target": "page:../../admin/config"}), ("read_content", {"target": "news:../a"}), ("read_content", {"target": "event:..%2f..%2fx"}),
            ("get_page_capabilities", {"target": "page:../../etc/passwd"}), ("read_content", {"target": "page:en_secret"}),
            ("read_content", {"target": "page:en_services__test", "path": "visibility.passwordHash"}),
            ("stage_set_field", {"target": "page:en_services__test", "path": "visibility.passwordHash", "value": "x"}),
            ("stage_set_field", {"target": "page:en_services__test", "path": "__proto__.polluted", "value": "x"}),
            ("stage_set_field", {"target": "page:en_services__test", "path": "sections.0.__proto__", "value": "x"}),
            ("stage_set_field", {"target": "page:en_services__test", "path": "constructor.prototype.x", "value": "x"}),
            ("stage_set_field", {"target": "page:en_secret", "path": "sections.0.content", "value": "<p>x</p>"}),
            ("stage_create_page", {"lang": "en", "slug": "../evil", "title": "Evil", "sections": [{"type": "text", "fields": {"content": "<p>x</p>"}}]}),
            ("stage_create_page", {"lang": "en", "slug": "a/../../evil", "title": "Evil", "sections": [{"type": "text", "fields": {"content": "<p>x</p>"}}]}),
            ("stage_create_news", {"lang": "en", "title": "Evil", "slug": "../x", "date": "2026-10-10", "contentHtml": "<p>x</p>"}),
            ("stage_create_news", {"lang": "en", "title": "Evil", "date": "2026-10-10", "contentHtml": "<p>x</p>", "imagePath": "/content/ai-settings.json"}),
            ("stage_create_news", {"lang": "en", "title": "Evil", "date": "2026-10-10", "contentHtml": "<p>x</p>", "imagePath": "/assets/images/../../admin/config.php"}),
            ("stage_set_field", {"target": "page:en_services__test", "path": "sections.0.content", "value": "<p>See <a href=\"https://evil.example/x\">this</a></p>"}),
            ("stage_set_field", {"target": "page:en_services__test", "path": "sections.0.content", "value": "<p>Hi</p><script>alert(1)</script><img src=x onerror=alert(1)><a href=\"javascript:alert(1)\">x</a>"}),
            ("list_media", {"folder": "../../content"}),
            ("import_stock_image", {"sid": "s_000000"}), ("generate_image", {"prompt": "x" * 2000}),
        ]
        configure(agent={"limits": {"maxToolFailures": 10}})  # many refusals in a row are the point of this run
        queue[:] = [turn(*[call(name, args, f"t{i}") for i, (name, args) in enumerate(attempts)]), say("done")]
        requests.clear()
        status, payload = post(owner, "ai-agent-start", goal="Poke around")
        state = run_to_end(owner, payload["data"]["runId"])
        results = {}
        for message in requests[-1]["messages"]:
            if message["role"] == "tool":
                results[message["tool_call_id"]] = json.loads(message["content"])
        for i, (name, args) in enumerate(attempts):
            result = results[f"t{i}"]
            if name == "stage_set_field" and "script" in json.dumps(args):
                assert result.get("ok") is True, ("the sanitised value is staged", result)
                after = result["preview"]["after"]
                assert "<script" not in after and "onerror" not in after and "javascript:" not in after, "markup is sanitised: " + after
                continue
            if name == "stage_create_news" and args.get("slug") == "../x":
                assert result.get("ok") is True and __import__("re").fullmatch(r"news:\d{4}-\d{2}-\d{2}-[a-z0-9-]+", result["preview"]["target"]), ("a path-like slug is turned into a harmless one", result)
                continue
            assert "error" in result, (name, args, result)
        assert "External link not allowed" in results["t16"]["error"], "external links in rich text need the user's word: " + json.dumps(results["t16"])
        assert SECRET_HASH not in json.dumps(requests) and "AGENTSECRETHASH" not in json.dumps(requests), "the password hash never reaches the model"
        assert "Confidential" not in json.dumps(requests), "private pages stay out of the model's context unless the user names them"
        assert tree(root) == before, "nothing was written outside the agent's own folder: " + json.dumps(sorted(set(tree(root)) ^ set(before)))
        assert json.loads((root / "content/pages/en_secret.json").read_text())["visibility"]["passwordHash"] == SECRET_HASH
        configure()
        # The user may name a private page; the hash still never shows up
        queue[:] = [turn(call("read_content", {"target": "page:en_secret"}, "r1")), say("ok")]
        requests.clear()
        state = run_to_end(owner, post(owner, "ai-agent-start", goal="Please read page:en_secret")[1]["data"]["runId"])
        reply = json.dumps(requests[-1]["messages"])
        assert "Confidential" in reply and SECRET_HASH not in reply and "passwordHash" not in reply, "a named private page is readable, its hash never"

        # ---- Rights ------------------------------------------------------------------------------------------------------------------------------------------------
        configure(agent={"roles": {"editor": {"uploadImages": False, "createNews": False}}})
        status, payload = post(owner, "ai-agent-upload")
        assert status == 403 and payload["success"] is False, ("no upload without the right", status, payload)
        queue[:] = [turn(call("stage_create_news", {"lang": "en", "title": "No", "date": "2026-10-10", "contentHtml": "<p>x</p>"}, "n1")), say("ok")]
        requests.clear()
        run_to_end(owner, post(owner, "ai-agent-start", goal="Create news")[1]["data"]["runId"])
        assert "stage_create_news" not in [t["function"]["name"] for t in requests[-1]["tools"]], "tools without the right are not offered"
        assert "error" in json.loads([m for m in requests[-1]["messages"] if m["role"] == "tool"][0]["content"]), "and calling them anyway is refused"
        configure()

        # ---- Limits ------------------------------------------------------------------------------------------------------------------------------------------------
        configure(agent={"limits": {"runsPerHour": 2}})
        limited = Client(site).login("admin")
        statuses = []
        for _ in range(3):
            queue[:] = [say("ok")]
            statuses.append(post(limited, "ai-agent-start", goal="Limit")[0])
        assert statuses == [200, 200, 429], statuses
        configure(agent={"limits": {"stepsPerMinute": 2}})
        queue[:] = [turn(call("get_site_overview", {}, "a")), turn(call("get_site_overview", {}, "b")), turn(call("get_site_overview", {}, "c")), say("ok")]
        stepper = Client(site).login("admin")  # fresh counters: the earlier checks used up editor2's steps
        run = post(stepper, "ai-agent-start", goal="Steps")[1]["data"]["runId"]
        codes = [post(stepper, "ai-agent-step", runId=run)[0] for _ in range(3)]
        assert codes[:2] == [200, 200] and codes[2] == 429, codes
        status, headers, body = stepper.request("/admin/api.php", {"action": "ai-agent-step", "csrf_token": stepper.csrf, "runId": run})
        assert status == 429 and int(headers.get("Retry-After", 0)) > 0, "the client is told when to come back"
        configure()

        # ---- Audit: what happened, never what was written --------------------------------------------------------------------------------------
        audit = "".join(path.read_text() for path in sorted((root / "content/ai-audit").glob("*.jsonl")))
        for action in ["agent-run-start", "agent-tool", "agent-apply", "agent-publish"]:
            assert f'"action":"{action}"' in audit, action
        assert GOAL_MARKER not in audit and SECRET_HASH not in audit and "Confidential" not in audit and "Security news" not in audit, "no goal, content or secret in the audit log"
        assert "AGENTSECRETHASH" not in "".join(p.read_text() for p in (root / "content/ai-agent").rglob("*.json") if "runs" in p.parts), "runs never store the hash"
        print("PASS agent security: auth, CSRF, methods, switches, ownership, publish right, traversal, prototype and secret paths, sanitising, link rule, rights, rate limits, audit")
    finally:
        site.close()
        server.shutdown()
