#!/usr/bin/env python3
"""The agent loop over HTTP, against a scripted loopback provider (no paid calls)."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import subprocess
import tempfile
from threading import Thread
import time
from support import Client, Site, write_json

os.environ['PHP_CLI_SERVER_WORKERS'] = '8'  # parallel requests: a second step must find the first one busy

requests = []   # request bodies the provider received
queue = []      # scripted answers: dict payload, or (delay seconds, payload)


class Provider(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        requests.append(body)
        answer = queue.pop(0) if queue else {"choices": [{"message": {"role": "assistant", "content": "unscripted"}, "finish_reason": "stop"}], "usage": {"prompt_tokens": 1, "completion_tokens": 1}}
        delay = 0
        if isinstance(answer, tuple):
            delay, answer = answer
        time.sleep(delay)
        data = json.dumps(answer).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)


def call(name, arguments, call_id):
    return {"id": call_id, "type": "function", "function": {"name": name, "arguments": json.dumps(arguments)}}


def turn(*calls, usage=(120, 30)):
    return {"choices": [{"message": {"role": "assistant", "content": None, "tool_calls": list(calls)}, "finish_reason": "tool_calls"}],
            "usage": {"prompt_tokens": usage[0], "completion_tokens": usage[1]}}


def say(text, usage=(120, 30)):
    return {"choices": [{"message": {"role": "assistant", "content": text}, "finish_reason": "stop"}], "usage": {"prompt_tokens": usage[0], "completion_tokens": usage[1]}}


with tempfile.TemporaryDirectory(prefix="nibbly-agent-loop-") as folder:
    site = Site(folder)
    root = site.root
    # a second editor, to prove runs belong to their owner
    editor = next(u for u in site.users if u["role"] == "editor")
    site.users.append({**editor, "id": "u_editor2", "username": "editor2", "email": "editor2@example.invalid"})
    write_json(root / "content/users.json", {"users": site.users})
    write_json(root / "content/settings.json", {"modules": {"ai": True}, "privacy": {"analyticsEnabled": False}})
    write_json(root / "content/pages/en_secret.json", {"page": "en_secret", "lang": "en", "title": "Board minutes", "visibility": {"status": "private", "passwordHash": "x"},
                                                       "sections": [{"id": "s", "type": "text", "title": "Minutes", "content": "<p>Confidential</p>"}]})
    server = ThreadingHTTPServer(("127.0.0.1", 0), Provider)
    Thread(target=server.serve_forever, daemon=True).start()
    site.start()

    def php(code):
        result = subprocess.run(["php", "-d", "display_errors=0", "-r", "require 'includes/ai/ai-helper.php'; " + code], cwd=root, text=True, capture_output=True)
        assert result.returncode == 0, result.stdout + result.stderr
        return result.stdout

    base_settings = json.loads(php("echo json_encode(nibblyAiDefaults());"))

    def configure(agent=None, ai=None):
        settings = json.loads(json.dumps(base_settings))
        settings.update(enabled=True, provider="openai-compatible", allowLocalProvider=True, chatModel="gpt-4.1-mini",
                        baseUrl=f"http://127.0.0.1:{server.server_port}", apiKey="")
        settings["providerCredentials"]["openai-compatible"]["baseUrl"] = settings["baseUrl"]
        settings["limits"].update(dailyRequests=1000, dailyTextRequests=1000, monthlyBudgetCents=100000, maxInputTokens=60000)
        settings.update(ai or {})
        agent = agent or {}
        # Tests start many runs; keep the hourly cap out of the way unless a test sets it.
        settings["agent"] = {"enabled": True, **agent, "limits": {"runsPerHour": 100, "stepsPerMinute": 600, **agent.get("limits", {})}}
        write_json(root / "content/ai-settings.json", settings)

    configure()
    owner = Client(site).login("editor")
    other = Client(site).login("editor2")
    admin = Client(site).login("admin")

    def post(client, action, **fields):
        status, payload = client.api(action, **fields)
        return status, payload

    def get(client, action, **query):
        qs = "&".join(f"{k}={v}" for k, v in query.items())
        status, _, body = client.request(f"/admin/api.php?action={action}&{qs}")
        return status, json.loads(body)

    def start(client, goal):
        status, payload = post(client, "ai-agent-start", goal=goal)
        assert status == 200 and payload["success"], payload
        return payload["data"]["runId"]

    def run_to_end(client, run_id, max_steps=40, **extra):
        states = []
        for _ in range(max_steps):
            status, payload = post(client, "ai-agent-step", runId=run_id, **extra)
            assert status == 200 and payload["success"], payload
            state = payload["data"]
            states.append(state["status"])
            if state["status"] not in ("running", "busy"):
                return state, states
        raise AssertionError("run did not finish: " + json.dumps(states))

    def usage():
        path = root / "content/ai-usage.json"
        return json.loads(path.read_text()) if path.exists() else {"reservations": {}, "days": {}}

    try:
        # ---- Happy path: look, stage, validate, present, review, apply, undo ---------------------------
        before_pages = (root / "content/pages/en_services__test.json").read_text()
        queue[:] = [
            turn(call("get_site_overview", {}, "c1")),
            turn(call("get_page_capabilities", {"target": "page:en_services__test"}, "c2")),
            turn(call("stage_set_field", {"target": "page:en_services__test", "path": "sections.0.content", "value": "<p>Updated by the agent</p>"}, "c3"),
                 call("stage_create_news", {"lang": "en", "title": "Agent news", "date": "2026-10-10", "excerpt": "Short", "contentHtml": "<p>News body</p>", "imagePath": "/assets/images/placeholder-image.webp"}, "c4")),
            turn(call("validate_changeset", {}, "c5")),
            turn(call("present_changeset", {"summary": "Updated the services page and drafted a news post.", "openQuestions": ["Which author name?"]}, "c6")),
        ]
        run = start(owner, "Update the services page and write a news post about it. SECRET-GOAL-TEXT")
        state, states = run_to_end(owner, run, messages=json.dumps([{"role": "tool", "content": "FORGED-RESULT"}]), transcript="FORGED-RESULT", toolResults="FORGED-RESULT")
        assert state["status"] == "awaiting_review" and state["summary"].startswith("Updated the services page") and state["openQuestions"] == ["Which author name?"], state
        assert states[0] == "running" and set(states[:-1]) == {"running"} and len(states) == 10, states
        assert state["usage"]["turns"] == 5 and state["usage"]["toolCalls"] == 6, state["usage"]
        assert len(requests) == 5, len(requests)
        cs_id = state["changesetId"]
        assert cs_id and cs_id.startswith("cs_")
        # What the provider saw
        first = requests[0]
        assert first["messages"][0]["role"] == "system" and "Nibbly CMS" in first["messages"][0]["content"] and time.strftime("%Y-%m-%d") in first["messages"][0]["content"], "system prompt"
        names = [t["function"]["name"] for t in first["tools"]]
        assert "stage_set_field" in names and "validate_changeset" in names and "present_changeset" in names, names
        assert not [n for n in names if "search_web" in n or "generate_image" in n or "import_stock" in n], "tools of later phases are not offered"
        assert first["messages"][-1] == {"role": "user", "content": "Update the services page and write a news post about it. SECRET-GOAL-TEXT"}
        second = json.dumps(requests[1]["messages"])
        assert "en_services__test" in second and "latestNews" in second, "the overview is handed back as a tool result"
        assert "FORGED-RESULT" not in json.dumps(requests), "nothing the browser sends reaches the model"
        assert "validate" in json.dumps(requests[4]["messages"]) and '\\"ready\\":true' in json.dumps(requests[4]["messages"]), "the model sees that validation passed"
        assert any("Change set so far" in m["content"] for m in requests[3]["messages"] if m["role"] == "system"), "the change set summary rides along"
        # Ledger: every turn is its own settled request
        reservations = {k: v for k, v in usage()["reservations"].items() if k.startswith("agent_" + run)}
        assert sorted(reservations) == [f"agent_{run}_{n}" for n in range(1, 6)] and all(v["status"] == "settled" for v in reservations.values()), reservations
        # Timeline: what happened, never the content
        status, payload = get(owner, "ai-agent-status", runId=run)
        events = payload["data"]["events"]
        assert status == 200 and [e["tool"] for e in events if e["type"] == "tool"] == ["get_site_overview", "get_page_capabilities", "stage_set_field", "stage_create_news", "validate_changeset", "present_changeset"], events
        assert all(e.get("ok") for e in events if e["type"] == "tool") and "Updated by the agent" not in json.dumps(events) and "News body" not in json.dumps(events)
        later = get(owner, "ai-agent-status", runId=run, after=payload["data"]["cursor"] - 2)[1]["data"]["events"]
        assert len(later) == 2, "status returns only new events"
        audit = "\n".join(p.read_text() for p in (root / "content/ai-audit").glob("*.jsonl"))
        assert "agent-run-start" in audit and "agent-tool" in audit and "SECRET-GOAL-TEXT" not in audit and "Updated by the agent" not in audit, "audit has metadata, not content"
        # The package is still only a package
        assert (root / "content/pages/en_services__test.json").read_text() == before_pages and not list((root / "content/news").glob("*agent*")), "nothing is live before the review"

        # ---- Ownership -------------------------------------------------------------------------------------
        for action, fields in [("ai-agent-step", {"runId": run}), ("ai-agent-reply", {"runId": run, "message": "hi"}), ("ai-agent-cancel", {"runId": run}),
                               ("ai-agent-apply", {"id": cs_id, "previewHash": "x", "confirmed": "1"}), ("ai-agent-discard", {"id": cs_id}), ("ai-agent-undo", {"id": cs_id, "token": "x"}),
                               ("ai-agent-revalidate", {"id": cs_id})]:
            status, payload = post(other, action, **fields)
            assert not payload["success"] and status in (400, 404), (action, status, payload)
        assert get(other, "ai-agent-status", runId=run)[0] == 404 and get(other, "ai-agent-changeset", id=cs_id)[0] == 404, "another editor cannot even look"
        assert get(admin, "ai-agent-status", runId=run)[0] == 200, "an admin can"
        assert post(owner, "ai-agent-step", runId="../../x")[1]["message"] == "Invalid run", "malformed run ids are refused"

        # ---- Review and apply over the API ---------------------------------------------------------------------
        status, payload = get(owner, "ai-agent-changeset", id=cs_id)
        review = payload["data"]
        assert status == 200 and review["status"] == "ready" and review["canPublish"] is False and len(review["previewHash"]) == 64, review
        assert any(op["type"] == "set_field" for ops in review["targets"].values() for op in ops) and review["renderCheck"]["status"] == "passed"
        assert post(owner, "ai-agent-apply", id=cs_id, previewHash=review["previewHash"])[1]["message"] == "Confirmation required", "applying needs the explicit confirmation"
        status, payload = post(owner, "ai-agent-apply", id=cs_id, previewHash="0" * 64, confirmed="1")
        assert not payload["success"] and "changed after the preview" in payload["message"]
        status, payload = post(owner, "ai-agent-apply", id=cs_id, previewHash=review["previewHash"], confirmed="1")
        assert status == 200 and payload["data"]["status"] == "applied" and payload["data"]["undoToken"], payload
        assert "Updated by the agent" in (root / "content/pages/en_services__test.json").read_text()
        news = json.loads(next((root / "content/news").glob("*agent-news*.json")).read_text())
        assert news["hidden"] is True and news["title"] == "Agent news", "the news post is created hidden"
        status, payload = post(owner, "ai-agent-publish", id=cs_id, item=json.dumps({"kind": "news", "target": "news:" + news["id"]}))
        assert status == 403 and not payload["success"], "editors cannot publish by default"
        status, payload = post(owner, "ai-agent-undo", id=cs_id, token="wrong")
        assert not payload["success"], "a forged undo token is refused"

        # ---- Limits ---------------------------------------------------------------------------------------------
        configure(agent={"limits": {"maxTurns": 2}})
        queue[:] = [turn(call("get_site_overview", {}, f"t{i}")) for i in range(5)]
        state, states = run_to_end(owner, start(owner, "Loop forever"))
        assert state["status"] == "failed" and state["errorCode"] == "maxTurns" and state["usage"]["turns"] == 2, state

        configure(agent={"limits": {"maxToolFailures": 2}})
        bad = lambda i: turn(call("stage_set_field", {"target": "page:en_services__test", "path": "sections.0.nope", "value": "x"}, f"b{i}"))
        queue[:] = [bad(1), bad(2), bad(3)]
        state, states = run_to_end(owner, start(owner, "Keep failing"))
        assert state["status"] == "failed" and state["errorCode"] == "toolFailures" and "stage_set_field" in state["error"], state
        assert len(requests) and "not an editable field" in json.dumps(requests[-1]["messages"]), "the model was told why it failed"

        configure(agent={"limits": {"maxToolCalls": 2}})
        queue[:] = [turn(call("get_site_overview", {}, "a"), call("get_site_overview", {}, "b"), call("get_site_overview", {}, "c"))]
        state, _ = run_to_end(owner, start(owner, "Too many calls"))
        assert state["status"] == "failed" and state["errorCode"] == "maxToolCalls", state

        configure(agent={"limits": {"maxCostCentsPerRun": 1}}, ai={"pricing": {"inputCentsPerMillion": 1000000, "outputCentsPerMillion": 1000000, "imageCentsPerRequest": 5}})
        queue[:] = [turn(call("get_site_overview", {}, "x1")), turn(call("get_site_overview", {}, "x2"))]
        state, _ = run_to_end(owner, start(owner, "Costly"))
        assert state["status"] == "failed" and state["errorCode"] == "maxCost" and state["usage"]["turns"] == 1, state
        configure()

        run = start(owner, "Slow")
        record = root / "content/ai-agent/runs" / f"{run}.json"
        data = json.loads(record.read_text())
        data["roundStartedAt"] = int(time.time()) - 3600
        record.write_text(json.dumps(data))
        state, _ = run_to_end(owner, run)
        assert state["status"] == "failed" and state["errorCode"] == "timeout", state

        # ---- Questions, plain answers, replies --------------------------------------------------------------------
        queue[:] = [turn(call("ask_user", {"question": "Which date should the event have?"}, "q1"), call("get_site_overview", {}, "q2")),
                    say("Understood, I will use that date.")]
        run = start(owner, "Create an event")
        state, _ = run_to_end(owner, run)
        assert state["status"] == "needs_input" and state["question"] == "Which date should the event have?", state
        status, payload = post(owner, "ai-agent-reply", runId=run, message="March 3rd, 18:00")
        assert payload["success"] and payload["data"]["status"] == "running", payload
        state, _ = run_to_end(owner, run)
        assert state["status"] == "needs_input" and state["question"] == "Understood, I will use that date.", "a plain answer waits for the user"
        sent = json.dumps(requests[-1]["messages"])
        assert "Skipped: the run is waiting for the user" in sent and "March 3rd, 18:00" in sent, "the skipped call and the reply are in the conversation"
        assert post(owner, "ai-agent-reply", runId=start(owner, "x" * 5), message="too early")[1]["success"] is False, "a running run takes no reply"

        # ---- Cancel, busy -------------------------------------------------------------------------------------------
        run = start(owner, "Cancel me")
        status, payload = post(owner, "ai-agent-cancel", runId=run)
        assert payload["success"] and payload["data"]["status"] == "cancelled"
        seen = len(requests)
        state = post(owner, "ai-agent-step", runId=run)[1]["data"]
        assert state["status"] == "cancelled" and len(requests) == seen, "a cancelled run does no more work"

        queue[:] = [(2.0, turn(call("get_site_overview", {}, "s1"))), turn(call("get_site_overview", {}, "s2"))]
        run = start(owner, "Busy test")
        results = []
        runner = Thread(target=lambda: results.append(("first", post(owner, "ai-agent-step", runId=run))))
        runner.start()
        time.sleep(0.6)
        second = post(admin, "ai-agent-step", runId=run)[1]["data"]
        runner.join()
        assert second["status"] == "busy", second
        assert results[0][1][1]["data"]["status"] == "running", "the first step finished normally"
        status, payload = post(owner, "ai-agent-cancel", runId=run)
        assert payload["success"]

        # ---- Rights ---------------------------------------------------------------------------------------------------
        configure(agent={"roles": {"editor": {"structural": False, "createNews": False}}})
        queue[:] = [say("ok")]
        run = start(owner, "Rights")
        run_to_end(owner, run)
        names = [t["function"]["name"] for t in requests[-1]["tools"]]
        assert "stage_add_section" not in names and "stage_add_list_item" not in names and "stage_create_news" not in names and "stage_set_field" in names, "tools without the right are not offered: " + ", ".join(names)
        assert "may not" in requests[-1]["messages"][0]["content"] and "create news posts" in requests[-1]["messages"][0]["content"], "the prompt says what the user may not do"
        queue[:] = [turn(call("stage_add_section", {"target": "page:en_services__test", "position": 0, "sectionType": "text"}, "r1")), say("done")]
        run = start(owner, "Try anyway")
        run_to_end(owner, run)
        assert "Unknown tool" in json.dumps(requests[-1]["messages"]), "a tool the user may not use is refused even if the model asks"
        queue[:] = [say("admin ok")]
        run = start(admin, "Admin")
        run_to_end(admin, run)
        assert "stage_add_section" in [t["function"]["name"] for t in requests[-1]["tools"]], "admins get every tool"
        configure()

        # ---- Private pages --------------------------------------------------------------------------------------------------
        queue[:] = [turn(call("read_content", {"target": "page:en_secret"}, "p1")), say("done")]
        run_to_end(owner, start(owner, "Summarise the pages"))
        assert "This page is private" in json.dumps(requests[-1]["messages"]) and "Confidential" not in json.dumps(requests[-1]["messages"]), "private pages stay out of the conversation"
        queue[:] = [turn(call("read_content", {"target": "page:en_secret"}, "p2")), say("done")]
        run_to_end(owner, start(owner, "Please check the secret page"))
        assert "Confidential" in json.dumps(requests[-1]["messages"]), "when the user names the page, it can be read"
        queue[:] = [turn(call("get_site_overview", {}, "p3")), say("done")]
        run_to_end(owner, start(owner, "List pages"))
        assert "Board minutes" not in json.dumps(requests[-1]["messages"]) and "en_secret" in json.dumps(requests[-1]["messages"]), "private page titles are not listed"

        # ---- Switches and limits at the door ---------------------------------------------------------------------------------
        configure(agent={"limits": {"runsPerHour": 2}})
        for _ in range(2):
            queue[:] = [say("x")]
            start(other, "Rate limit " + str(time.time()))
        status, payload = post(other, "ai-agent-start", goal="One too many")
        assert status == 429 and not payload["success"], (status, payload)
        configure()
        write_json(root / "content/ai-settings.json", {**json.loads((root / "content/ai-settings.json").read_text()), "agent": {"enabled": False}})
        status, payload = post(owner, "ai-agent-start", goal="Switched off")
        assert not payload["success"] and "switched off" in payload["message"], payload
        configure(ai={"provider": "kie", "baseUrl": "https://api.kie.ai", "apiKey": "k"})
        status, payload = post(owner, "ai-agent-start", goal="Wrong provider")
        assert not payload["success"] and "tool calling" in payload["message"], payload
        configure()
        write_json(root / "content/settings.json", {"modules": {"ai": False}})
        assert not post(owner, "ai-agent-start", goal="Module off")[1]["success"], "the AI module switch also switches the agent off"
        write_json(root / "content/settings.json", {"modules": {"ai": True}})
        status, _, _ = owner.request("/admin/api.php?action=ai-agent-start&goal=hello")
        assert status == 405, "writes are POST only"
        status, _, _ = owner.request("/admin/api.php", {"action": "ai-agent-start", "goal": "no token"})
        assert status == 403, "a missing CSRF token is refused"
        print("PASS agent loop: status flow, one ledger request per turn, browser input ignored, limits, questions and replies, cancel, busy, rights, private pages, ownership, review/apply over the API")
    finally:
        site.close()
        server.shutdown()
        server.server_close()
