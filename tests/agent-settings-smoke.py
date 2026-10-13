#!/usr/bin/env python3
"""Agent settings through the dashboard API: save and load, masked keys, rights, cache, system status, form markup."""
import json
import tempfile
from support import Client, Site, write_json

BRAVE = "BSA-brave-http-secret-9876543210"
PEXELS = "pexels-http-secret-ZYXWVUTS987654"

with tempfile.TemporaryDirectory(prefix="nibbly-agent-settings-") as folder:
    site = Site(folder)
    root = site.root
    write_json(root / "content/settings.json", {"modules": {"ai": True}, "privacy": {"analyticsEnabled": False}})
    site.start()
    admin = Client(site).login("admin")
    editor = Client(site).login("editor")
    visitor = Client(site)

    def load(client):
        status, _, body = client.request("/admin/api.php?action=load-ai-settings")
        return status, json.loads(body)

    def save(client, settings):
        return client.api("save-ai-settings", settings=json.dumps(settings))

    try:
        # ---- Load ----------------------------------------------------------------------------------------------------------------------------
        status, payload = load(admin)
        settings = payload["data"]["settings"]
        assert status == 200 and settings["agent"]["enabled"] is False and settings["research"]["hasBraveApiKey"] is False and settings["stock"]["hasPexelsApiKey"] is False, settings
        assert settings["agentStatus"]["gd"] in (True, False) and settings["agentStatus"]["toolCalling"] in (True, False), "admins see what the agent needs"
        status, payload = load(editor)
        assert status == 200 and "agentStatus" not in payload["data"]["settings"], "editors do not get the server status"

        # ---- Save ----------------------------------------------------------------------------------------------------------------------------
        form = {"enabled": True, "provider": "openai-compatible", "baseUrl": "http://127.0.0.1:9", "allowLocalProvider": True, "apiKey": "provider-http-secret-0001", "chatModel": "gpt-4.1-mini",
                "features": {"backendAssistant": True, "seoTextGeneration": True, "imageGeneration": True},
                "agent": {"enabled": True, "limits": {"runsPerHour": 7, "maxRuntimeSeconds": 600}, "roles": {"editor": {"publish": False, "uploadImages": True}}, "mediaFolder": "agent-images", "creditInNews": True, "sourcesInNews": False},
                "research": {"braveApiKey": BRAVE, "allowDomains": "example.org\nexample.net", "denyDomains": "", "respectRobots": True},
                "stock": {"pexelsApiKey": PEXELS}}
        status, payload = save(admin, form)
        assert status == 200 and payload["success"], payload
        saved = payload["data"]["settings"]
        assert saved["agent"]["limits"]["runsPerHour"] == 7 and saved["agent"]["mediaFolder"] == "agent-images" and saved["agent"]["sourcesInNews"] is False, saved["agent"]
        assert saved["research"]["hasBraveApiKey"] and saved["stock"]["hasPexelsApiKey"] and saved["research"]["allowDomains"] == ["example.org", "example.net"], saved
        assert "agentStatus" in saved
        stored = (root / "content/ai-settings.json").read_text()
        assert BRAVE in stored and PEXELS in stored, "the server keeps the keys"
        for text in [json.dumps(payload), json.dumps(load(admin)[1]), json.dumps(load(editor)[1])]:
            assert BRAVE not in text and PEXELS not in text and "provider-http-secret" not in text, "no key in any response"
        assert visitor.request("/content/ai-settings.json")[0] != 200, "the settings file is not downloadable"

        # keys survive a save without keys, and are cleared on request
        status, payload = save(admin, {**form, "apiKey": "", "research": {"braveApiKey": "", "clearBraveApiKey": False}, "stock": {"pexelsApiKey": ""}})
        assert payload["success"] and BRAVE in (root / "content/ai-settings.json").read_text() and PEXELS in (root / "content/ai-settings.json").read_text()
        status, payload = save(admin, {**form, "apiKey": "", "research": {"clearBraveApiKey": True}, "stock": {"clearPexelsApiKey": True}})
        assert payload["success"] and BRAVE not in (root / "content/ai-settings.json").read_text() and not payload["data"]["settings"]["research"]["hasBraveApiKey"]
        # refusals
        for bad in [{"research": {"braveApiKey": "short"}}, {"stock": {"pexelsApiKey": "a key with spaces 12345"}}, {"agent": {"mediaFolder": "../up"}}]:
            status, payload = save(admin, {**form, "apiKey": "", **bad})
            assert payload["success"] is False and status != 200 or payload["success"] is False, (bad, payload)

        # ---- Warnings travel with the usage numbers ---------------------------------------------------------------------------------
        import time
        usage = {"days": {time.strftime("%Y-%m-%d", time.gmtime()): {"requests": 290, "textRequests": 10, "imageRequests": 0, "inputTokens": 0, "outputTokens": 0, "estimatedCostCents": 0}},
                 "months": {time.strftime("%Y-%m", time.gmtime()): {"requests": 290, "textRequests": 10, "imageRequests": 0, "inputTokens": 0, "outputTokens": 0, "estimatedCostCents": 1000}}}
        write_json(root / "content/ai-usage.json", usage)
        save(admin, {**form, "apiKey": "", "limits": {"monthlyBudgetCents": 1000, "dailyRequests": 300, "dailyTextRequests": 250, "dailyImageRequests": 10, "maxInputTokens": 64000, "maxOutputTokens": 16000, "requestTimeoutSeconds": 300}})
        for client in (admin, editor):
            kinds = {w["kind"]: w["level"] for w in load(client)[1]["data"]["usage"]["warnings"]}
            assert kinds == {"month": "reached", "requests": "warning"}, kinds
        status, _, body = admin.request("/admin/api.php", {"action": "ai-copilot-context", "csrf_token": admin.csrf, "contentPage": "en_home"})
        assert {w["kind"] for w in json.loads(body)["data"]["limitWarnings"]} == {"month", "requests"}, "the assistant gets them with its context"
        write_json(root / "content/ai-usage.json", {"days": {}, "months": {}})

        # ---- Who may change them ---------------------------------------------------------------------------------------------------------
        status, payload = save(editor, form)
        assert status == 403 and payload["success"] is False, (status, payload)
        status, _, body = admin.request("/admin/api.php", {"action": "save-ai-settings", "settings": json.dumps(form)})
        assert status == 403, "no CSRF token, no change"
        write_json(root / "content/ai-settings.json", {**json.loads((root / "content/ai-settings.json").read_text()), "agent": {"enabled": True}})

        # ---- Clear the page scan cache ---------------------------------------------------------------------------------------------------
        cache = root / "content/ai-agent/cache"
        cache.mkdir(parents=True, exist_ok=True)
        for name in ["cap-aaaa.json", "cap-bbbb.json"]:
            (cache / name).write_text("{}")
        (cache / "keep.txt").write_text("not a scan")
        assert editor.api("ai-agent-clear-cache")[0] == 403, "editors cannot clear it"
        assert admin.request("/admin/api.php?action=ai-agent-clear-cache")[0] == 405, "not over GET"
        assert admin.request("/admin/api.php", {"action": "ai-agent-clear-cache"})[0] == 403, "not without the CSRF token"
        assert len(list(cache.glob("cap-*.json"))) == 2
        status, payload = admin.api("ai-agent-clear-cache")
        assert status == 200 and payload["data"]["removed"] == 2 and not list(cache.glob("cap-*.json")) and (cache / "keep.txt").exists(), payload

        # ---- System status ---------------------------------------------------------------------------------------------------------------
        save(admin, {**form, "apiKey": ""})
        status, _, body = admin.request("/admin/api.php?action=system-status&scheme=http")
        data = json.loads(body)["data"]
        assert set(data["agent"]) == {"enabled", "toolCalling", "gd", "webp", "research", "stock", "storageWritable"} and data["agent"]["enabled"] is True and data["agent"]["research"] is True and data["agent"]["stock"] is True, data["agent"]
        assert BRAVE not in body.decode() and PEXELS not in body.decode(), "the status never contains a key"
        assert editor.request("/admin/api.php?action=system-status&scheme=http")[0] in (403, 200) and BRAVE not in editor.request("/admin/api.php?action=system-status&scheme=http")[2].decode()

        # ---- The form -----------------------------------------------------------------------------------------------------------------------
        status, _, body = admin.request("/admin/dashboard.php")
        html = body.decode()
        for needle in ["id=\"aiAgentSection\"", "id=\"aiAgentEnabled\"", "id=\"aiBraveApiKey\"", "id=\"aiPexelsApiKey\"", "id=\"aiAgentClearCache\"", "data-agent-right=\"publish\"", "data-agent-right=\"editSharedContent\"", "id=\"aiAgentMediaFolder\""]:
            assert needle in html, needle
        assert BRAVE not in html and PEXELS not in html and "provider-http-secret" not in html, "keys are never rendered into the dashboard"
        assert "aiAgentSection" not in editor.request("/admin/dashboard.php")[2].decode(), "editors do not get the section"
        print("PASS agent settings over HTTP: load and save, keys never returned and kept, clear flags, refusals, admin only, CSRF, cache, system status, form markup")
    finally:
        site.close()
