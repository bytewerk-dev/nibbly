#!/usr/bin/env python3
"""Browser check of the AI agent panel (Chromium via Playwright, scripted provider, no network)."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import base64
import shutil
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
from threading import Thread
import time
from support import ROOT, Site, write_json

PNG = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==")
folder = tempfile.mkdtemp(prefix="nibbly-agent-browser-")
log_file = Path(folder) / "provider-requests.jsonl"


def call(name, arguments, call_id):
    return {"id": call_id, "type": "function", "function": {"name": name, "arguments": json.dumps(arguments)}}


def turn(*calls):
    return {"choices": [{"message": {"role": "assistant", "content": None, "tool_calls": list(calls)}, "finish_reason": "tool_calls"}], "usage": {"prompt_tokens": 120, "completion_tokens": 30}}


def say(text):
    return {"choices": [{"message": {"role": "assistant", "content": text}, "finish_reason": "stop"}], "usage": {"prompt_tokens": 120, "completion_tokens": 30}}


NEWS = {"lang": "en", "title": "Agent news", "date": "2026-10-10", "excerpt": "Short", "contentHtml": "<p>News body written by the agent.</p>", "imagePath": "/assets/images/placeholder-image.webp"}


def scripted_turn(goal, messages):
    """The scripted agent: its answer after the last user message of the conversation."""
    last_user = max(i for i, m in enumerate(messages) if m["role"] == "user")
    since = sum(1 for m in messages[last_user:] if m["role"] == "assistant")
    text = messages[last_user]["content"]
    if "[flow:slow]" in goal:
        time.sleep(4)
        return turn(call("get_site_overview", {}, f"s{since}"))
    if "[flow:upload]" in goal:
        return turn(call("list_run_media", {}, "u1")) if since == 0 else say("I can see your image. Where should it go?")
    first = [
        turn(call("get_page_capabilities", {"target": "page:en_services__test"}, "c1")),
        turn(call("stage_set_field", {"target": "page:en_services__test", "path": "sections.0.content", "value": "<p>Updated by the agent</p>"}, "c2"), call("stage_create_news", NEWS, "c3")),
        turn(call("validate_changeset", {}, "c4")),
        turn(call("present_changeset", {"summary": "Updated the services page and drafted a news post. <img src=x onerror=\"window.__xss=1\">"}, "c5")),
    ]
    revise = [
        turn(call("stage_set_field", {"target": "page:en_services__test", "path": "sections.0.content", "value": "<p>Shorter text</p>"}, "c6")),
        turn(call("validate_changeset", {}, "c7")),
        turn(call("present_changeset", {"summary": "Made the services text shorter."}, "c8")),
    ]
    if text.strip().lower() == "yes":
        return turn(call("present_changeset", {"summary": "Nothing is applied by a chat reply."}, "y1")) if since == 0 else say("Done.")
    steps = revise if "shorter" in text.lower() else first
    return steps[since] if since < len(steps) else say("Done.")


def dashboard_turn(messages):
    """The dashboard assistant: cards instead of answers."""
    text = messages[-1]["content"].lower() if messages[-1]["role"] == "user" else ""
    if "dark" in text:
        return turn(call("change_setting", {"setting": "adminTheme", "value": "dark"}, "d1"))
    if "language settings" in text:
        return turn(call("open_section", {"section": "settings", "settingsTab": "language"}, "d2"))
    if "german" in text:
        return turn(call("change_setting", {"setting": "adminLanguage", "value": "de"}, "d3"))
    if "blue" in text:
        return turn(call("change_setting", {"setting": "accentColor", "value": "#0b5cad"}, "d4"))
    return say("Plain dashboard answer.")


class Provider(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        with log_file.open("a") as handle:
            handle.write(json.dumps(body) + "\n")
        messages = body["messages"]
        goal = next((m["content"] for m in messages if m["role"] == "user"), "")
        names = {tool["function"]["name"] for tool in body.get("tools", [])}
        payload = dashboard_turn(messages) if "open_section" in names else scripted_turn(goal, messages)
        data = json.dumps(payload).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)


provider = ThreadingHTTPServer(("127.0.0.1", 0), Provider)
Thread(target=provider.serve_forever, daemon=True).start()
os.environ["PHP_CLI_SERVER_WORKERS"] = "8"
site = Site(folder + "/site")
root = site.root
write_json(root / "content/settings.json", {"modules": {"ai": True}, "privacy": {"analyticsEnabled": False}})
defaults = json.loads(subprocess.run(["php", "-r", "require 'includes/ai/ai-helper.php'; echo json_encode(nibblyAiDefaults());"], cwd=root, text=True, capture_output=True, check=True).stdout)
defaults.update(enabled=True, provider="openai-compatible", allowLocalProvider=True, chatModel="gpt-4.1-mini", baseUrl=f"http://127.0.0.1:{provider.server_port}", apiKey="test-key")
defaults["providerCredentials"]["openai-compatible"]["baseUrl"] = defaults["baseUrl"]
defaults["limits"].update(dailyRequests=1000, dailyTextRequests=1000, monthlyBudgetCents=100000, maxInputTokens=60000)
defaults["features"]["backendAssistant"] = True
defaults["agent"] = {"enabled": True, "limits": {"runsPerHour": 100, "stepsPerMinute": 600}}
write_json(root / "content/ai-settings.json", defaults)
(Path(folder) / "upload.png").write_bytes(PNG)
available = subprocess.run(["php", "-r", "$_SESSION = ['admin_role' => 'editor']; require 'includes/ai/agent/agent-config.php'; $reason = ''; echo nibblyAgentAvailable($reason) ? 'yes' : 'no: ' . $reason;"], cwd=root, text=True, capture_output=True)
assert available.stdout == "yes", "the agent should be available in this fixture: " + available.stdout + available.stderr
site.start()
screenshots = Path(tempfile.mkdtemp(prefix="nibbly-browser-evidence-"))
try:
    environment = dict(os.environ, REVIEW_PASSWORD=site.password, REVIEW_URL=f"http://127.0.0.1:{site.port}", REVIEW_SCREENSHOTS=str(screenshots),
                       REVIEW_IMAGE=str(Path(folder) / "upload.png"), REVIEW_SITE=str(root))
    script = sys.argv[1] if len(sys.argv) > 1 else str(ROOT / "tests" / "agent-check.js")
    subprocess.run(["node", script], env=environment, cwd=ROOT, check=True, timeout=240)
    # The AI settings form: legacy installs, effort per purpose, the agent question, own prices, key removal
    subprocess.run(["node", str(ROOT / "tests" / "ai-settings-check.js")], env=environment, cwd=ROOT, check=True, timeout=240)
    requests = [json.loads(line) for line in log_file.read_text().splitlines()]
    uploads = [r for r in requests if "[flow:upload]" in json.dumps(r["messages"])]
    assert uploads and "[Attached images:" in uploads[0]["messages"][-1]["content"], "the attached image is announced to the agent"
    assert all("<script" not in json.dumps(r) for r in requests), "no markup from the UI reaches the provider"
    print("PASS agent browser: panel, mode switch, run, review card, preview, apply, publish rights, undo, cancel, request changes, upload chips, resume after reload, mobile layout, dashboard settings, AI settings form")
finally:
    print("Screenshots:", screenshots)
    site.close()
    provider.shutdown()
    shutil.rmtree(folder, ignore_errors=True)
