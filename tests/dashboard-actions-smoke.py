#!/usr/bin/env python3
"""AI assistant in the dashboard: cards for opening a section or changing the dashboard look, validated per role."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import tempfile
from threading import Thread
from support import Client, Site, write_json

requests = []


def call(name, **arguments):
    return {"id": "call_" + name + str(len(requests)), "type": "function", "function": {"name": name, "arguments": json.dumps(arguments)}}


# What the "model" answers to which user message (the last one).
SCRIPT = {
    "dark": [call("change_setting", setting="adminTheme", value="dark")],
    "light": [call("change_setting", setting="adminTheme", value="light")],
    "blue": [call("change_setting", setting="accentColor", value="#1E40AF")],
    "german": [call("change_setting", setting="adminLanguage", value="de")],
    "settings": [call("open_section", section="settings", settingsTab="language")],
    "media": [call("open_section", section="media")],
    "mine": [call("open_section", section="settings", settingsTab="my-account")],
    "bad": [call("change_setting", setting="adminTheme", value="purple"), call("change_setting", setting="accentColor", value="red"),
            call("change_setting", setting="adminLanguage", value="xx"), call("change_setting", setting="apiKey", value="x"),
            call("open_section", section="danger"), call("delete_everything")],
    "two": [call("change_setting", setting="adminTheme", value="dark"), call("change_setting", setting="adminTheme", value="dark"), call("open_section", section="settings", settingsTab="theme")],
}


class Provider(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        requests.append(body)
        last = [m for m in body["messages"] if m["role"] == "user"][-1]["content"]
        calls = SCRIPT.get(last) if body.get("tools") else None
        message = {"role": "assistant", "content": None if calls else "plain answer"}
        if calls:
            message["tool_calls"] = calls
        data = json.dumps({"choices": [{"message": message, "finish_reason": "tool_calls" if calls else "stop"}], "usage": {"prompt_tokens": 20, "completion_tokens": 5}}).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)


server = ThreadingHTTPServer(("127.0.0.1", 0), Provider)
Thread(target=server.serve_forever, daemon=True).start()

with tempfile.TemporaryDirectory(prefix="nibbly-dash-") as folder:
    site = Site(folder)
    root = site.root
    write_json(root / "content/settings.json", {"modules": {"ai": True}, "privacy": {"analyticsEnabled": False}, "theme": {"adminTheme": "dark", "accentColor": "#3858e9"}})
    import subprocess
    defaults = json.loads(subprocess.run(["php", "-r", "require 'includes/ai/ai-helper.php'; echo json_encode(nibblyAiDefaults());"], cwd=root, text=True, capture_output=True).stdout)
    settings = {**defaults, "enabled": True, "provider": "openai-compatible", "allowLocalProvider": True, "chatModel": "test", "baseUrl": f"http://127.0.0.1:{server.server_port}", "apiKey": "k"}
    settings["providerCredentials"]["openai-compatible"].update(baseUrl=settings["baseUrl"], apiKey="k")
    settings["limits"].update(dailyRequests=1000, dailyTextRequests=1000, monthlyBudgetCents=100000)
    write_json(root / "content/ai-settings.json", settings)
    site.start()
    try:
        admin = Client(site).login("admin")
        editor = Client(site).login("editor")

        def chat(client, text, mode="dashboard"):
            requests.clear()
            fields = {"messages": json.dumps([{"role": "user", "content": text}]), "contentPage": ""}
            if mode:
                fields["mode"] = mode
            status, payload = client.api("ai-copilot-chat", **fields)
            assert status == 200 and payload["success"], payload
            return payload["data"]

        # The context tells the page whether actions are on
        assert admin.api("ai-copilot-context", mode="dashboard")[1]["data"]["dashboardActions"] is True
        assert admin.api("ai-copilot-context")[1]["data"]["dashboardActions"] is False, "only in the dashboard assistant"

        # A change of the look becomes a card, nothing is changed
        data = chat(admin, "dark")
        assert data["actions"] == [{"type": "noop", "title": "Dashboard theme is already Dark."}], data["actions"]
        data = chat(admin, "light")
        assert data["actions"] == [{"type": "setting", "setting": "adminTheme", "value": "light", "title": "Change dashboard theme", "detail": "Dark → Light"}], data["actions"]
        assert data["reply"], "a short sentence goes with the card"
        data = chat(admin, "blue")
        assert data["actions"][0]["setting"] == "accentColor" and data["actions"][0]["value"] == "#1e40af" and data["actions"][0]["detail"] == "#3858e9 → #1e40af", data["actions"]
        data = chat(admin, "german")
        assert data["actions"][0]["setting"] == "adminLanguage" and data["actions"][0]["value"] == "de" and "Deutsch" in data["actions"][0]["detail"], data["actions"]
        settings_after = json.loads((root / "content/settings.json").read_text())
        assert settings_after["theme"] == {"adminTheme": "dark", "accentColor": "#3858e9"} and "general" not in settings_after, "the chat itself changes no setting"

        # Opening areas
        data = chat(admin, "settings")
        assert data["actions"] == [{"type": "open", "section": "settings", "settingsTab": "language", "title": "Open Settings › Language"}], data["actions"]
        assert chat(admin, "media")["actions"][0]["section"] == "media"

        # Anything outside the catalogue is dropped
        data = chat(admin, "bad")
        assert data["actions"] == [] and data["reply"], data
        data = chat(admin, "two")
        assert [a["type"] for a in data["actions"]] == ["noop", "open"], "duplicates are removed: " + json.dumps(data["actions"])

        # Ordinary questions are answered as before; the provider saw the tools and the instructions
        data = chat(admin, "How do I add a page?")
        assert data["actions"] == [] and data["reply"] == "plain answer"
        sent = requests[0]
        assert sorted(t["function"]["name"] for t in sent["tools"]) == ["change_setting", "open_section"], sent["tools"]
        assert "Dashboard actions" in sent["messages"][0]["content"]

        # Editors: no settings changes, only the areas they have
        data = chat(editor, "dark")
        assert data["actions"] == [] and data["reply"], data
        sent = requests[0]
        assert [t["function"]["name"] for t in sent["tools"]] == ["open_section"], "editors are not even offered the settings tool"
        sections = sent["tools"][0]["function"]["parameters"]["properties"]["section"]["enum"]
        assert "media" not in sections and "backup" not in sections and "settings" in sections, sections
        assert editor.api("ai-copilot-context", mode="dashboard")[1]["data"]["dashboardActions"] is True
        assert chat(editor, "settings")["actions"] == [], "a tab or area the editor does not have is refused by the tool schema already"
        assert chat(editor, "media")["actions"] == []
        assert chat(editor, "mine")["actions"] == [{"type": "open", "section": "settings", "settingsTab": "my-account", "title": "Open Settings › My Account"}], "their own account tab is fine"

        # The frontend assistant is untouched: no tools, no actions
        data = chat(admin, "dark", mode="")
        assert data["actions"] == [] and "tools" not in requests[0], data
        print("PASS dashboard actions: cards for open/change, role-aware tools, validation, no-ops, nothing changed by the chat, frontend assistant untouched")
    finally:
        site.close()
        server.shutdown()
