#!/usr/bin/env python3
"""Model quirks (max_completion_tokens, temperature), the provider model list and the new default limits."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import subprocess
import tempfile
from threading import Thread
from support import Client, Site, write_json

bodies = []
paths = []


class Provider(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def reply(self, status, payload):
        data = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        if self.path.startswith("/models"):
            if self.headers.get("Authorization") != "Bearer test-key-123":
                return self.reply(401, {"error": {"message": "Incorrect API key provided"}})
            return self.reply(200, {"data": [{"id": n} for n in ["gpt-b", "text-embedding-3-small", "gpt-a", "whisper-1", "dall-e-3", "gpt-image-1", "gpt-a", "bad id with spaces", "o3-mini", "tts-1"]]})
        self.reply(404, {})

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        bodies.append(body)
        paths.append(self.path)
        model = body.get("model")
        if self.path.endswith("/responses"):
            if model == "reasoner" and "temperature" in body:
                return self.reply(400, {"error": {"message": "Unsupported parameter: 'temperature' is not supported with this model."}})
            items = body["input"]
            if any(i.get("type") == "function_call_output" for i in items if isinstance(i, dict)):
                return self.reply(200, {"status": "completed", "output": [{"type": "message", "role": "assistant", "content": [{"type": "output_text", "text": "all done"}]}], "usage": {"input_tokens": 50, "output_tokens": 7}})
            return self.reply(200, {"status": "completed", "output": [
                {"type": "reasoning", "id": "rs_1", "summary": [], "encrypted_content": "ENC123"},
                {"type": "function_call", "id": "fc_1", "call_id": "call_abc", "name": "ping", "arguments": "{}"}
            ], "usage": {"input_tokens": 40, "output_tokens": 300}})
        if model == "strict":
            if "max_tokens" in body:
                return self.reply(400, {"error": {"message": "Unsupported parameter: 'max_tokens' is not supported with this model. Use 'max_completion_tokens' instead."}})
            if body.get("temperature") not in (None, 1):
                return self.reply(400, {"error": {"message": "Unsupported value: 'temperature' does not support 0.3 with this model. Only the default (1) value is supported."}})
        if model == "plain" and "max_completion_tokens" in body:
            return self.reply(400, {"error": {"message": "Unrecognized request argument supplied: max_completion_tokens"}})
        if model == "reasoner" and body.get("tools"):
            return self.reply(400, {"error": {"message": "Function tools with reasoning_effort are not supported for reasoner in /v1/chat/completions. To use function tools, use /v1/responses or set reasoning_effort to 'none'."}})
        if model == "boom":
            return self.reply(500, {"error": {"message": "The server had an error"}})
        if model == "denied":
            return self.reply(401, {"error": {"message": "Incorrect API key provided"}})
        if model == "badrequest":
            return self.reply(400, {"error": {"message": "Your prompt violates the policy"}})
        answer = {"choices": [{"message": {"role": "assistant", "content": "hello from " + str(model)}, "finish_reason": "stop"}], "usage": {"prompt_tokens": 5, "completion_tokens": 3}}
        self.reply(200, answer)


server = ThreadingHTTPServer(("127.0.0.1", 0), Provider)
Thread(target=server.serve_forever, daemon=True).start()

with tempfile.TemporaryDirectory(prefix="nibbly-quirks-") as folder:
    site = Site(folder)
    root = site.root
    write_json(root / "content/settings.json", {"modules": {"ai": True}, "privacy": {"analyticsEnabled": False}})

    def php(code):
        result = subprocess.run(["php", "-d", "display_errors=0", "-r", "require 'includes/ai/ai-helper.php'; " + code], cwd=root, text=True, capture_output=True)
        assert result.returncode == 0, result.stdout + result.stderr
        return result.stdout

    defaults = json.loads(php("echo json_encode(nibblyAiDefaults());"))
    limits = defaults["limits"]
    assert limits["maxInputTokens"] == 64000 and limits["maxOutputTokens"] == 16000 and limits["dailyRequests"] == 300 and limits["dailyTextRequests"] == 250, limits
    settings = json.loads(json.dumps(defaults))
    settings.update(enabled=True, provider="openai-compatible", allowLocalProvider=True, chatModel="plain", baseUrl=f"http://127.0.0.1:{server.server_port}", apiKey="test-key-123")
    settings["providerCredentials"]["openai-compatible"].update(baseUrl=settings["baseUrl"], apiKey="test-key-123")
    settings["limits"].update(dailyRequests=1000, dailyTextRequests=1000, monthlyBudgetCents=100000)
    write_json(root / "content/ai-settings.json", settings)

    def chat(model):
        bodies.clear()
        out = php("try { $r = nibblyAiChat([['role' => 'user', 'content' => 'hi']], ['model' => '" + model + "', 'temperature' => 0.3]); echo json_encode(['ok' => $r['text']]); } catch (Throwable $e) { echo json_encode(['error' => $e->getMessage()]); }")
        return json.loads(out), list(bodies)

    try:
        # A model that wants max_completion_tokens and the default temperature: two corrections, then it works
        result, sent = chat("strict")
        assert result == {"ok": "hello from strict"}, result
        assert len(sent) == 3 and "max_tokens" in sent[0] and "max_completion_tokens" in sent[1] and "temperature" in sent[1] and "temperature" not in sent[2] and "max_completion_tokens" in sent[2], [sorted(b) for b in sent]
        assert sent[2]["max_completion_tokens"] == 16000, "the limit is passed on unchanged"
        # The next call already knows
        result, sent = chat("strict")
        assert result == {"ok": "hello from strict"} and len(sent) == 1 and "max_completion_tokens" in sent[0] and "max_tokens" not in sent[0] and "temperature" not in sent[0], [sorted(b) for b in sent]
        quirks = json.loads((root / "content/ai-model-quirks.json").read_text())
        assert quirks[f"127.0.0.1|strict"] == {"tokenParam": "max_completion_tokens", "noTemperature": True}, quirks
        # An ordinary model keeps max_tokens and the temperature, in one request
        result, sent = chat("plain")
        assert result == {"ok": "hello from plain"} and len(sent) == 1 and sent[0]["max_tokens"] == 16000 and sent[0]["temperature"] == 0.3, sent
        # Real errors are not retried
        for model, expected in [("boom", "The server had an error"), ("denied", "Incorrect API key"), ("badrequest", "violates the policy")]:
            result, sent = chat(model)
            assert result["error"] == expected or expected in result["error"], (model, result)
            assert len(sent) == 1, (model, "only parameter problems are corrected, not other errors", len(sent))
        # The tool gateway uses the same path
        bodies.clear()
        out = php("$r = nibblyAiChatWithTools([['role' => 'user', 'content' => 'hi']], [['name' => 'ping', 'description' => 'x', 'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => new stdClass()]]], ['model' => 'strict', 'temperature' => 0.2]); echo json_encode(['text' => $r['text']]);")
        assert json.loads(out) == {"text": "hello from strict"} and len(bodies) == 1 and "max_completion_tokens" in bodies[0], bodies
        # A reasoning model that refuses tools on chat/completions: asked again on /responses, remembered, reasoning handed on
        tool = "[['name' => 'ping', 'description' => 'x', 'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => new stdClass()]]]"
        bodies.clear(); paths.clear()
        out = php("$r = nibblyAiChatWithTools([['role' => 'user', 'content' => 'hi']], " + tool + ", ['model' => 'reasoner']); echo json_encode($r);")
        first = json.loads(out)
        assert [p.rsplit("/", 1)[1] for p in paths] == ["completions", "responses", "responses"], paths
        assert "temperature" in bodies[1] and "temperature" not in bodies[2], "the temperature is dropped after the provider refused it"
        sent = bodies[2]
        assert sent["store"] is False and sent["include"] == ["reasoning.encrypted_content"] and sent["max_output_tokens"] == 16000 and sent["tool_choice"] == "auto", sent
        assert sent["tools"][0]["type"] == "function" and sent["tools"][0]["name"] == "ping" and "function" not in sent["tools"][0], sent["tools"]
        assert sent["input"] == [{"role": "user", "content": "hi"}], sent["input"]
        assert first["kind"] == "tool_calls" and first["toolCalls"][0]["id"] == "call_abc" and first["message"]["reasoningItems"][0]["encrypted_content"] == "ENC123", first
        assert first["usage"]["outputTokens"] == 300
        quirks = json.loads((root / "content/ai-model-quirks.json").read_text())
        assert quirks["127.0.0.1|reasoner"] == {"api": "responses", "noTemperature": True}, quirks
        # Second turn: the reasoning, the call and its result go back as items; the model answers
        history = [{"role": "system", "content": "be brief"}, {"role": "user", "content": "hi"}, first["message"], {"role": "tool", "toolCallId": "call_abc", "content": "pong"}]
        (root / "history.json").write_text(json.dumps(history))
        bodies.clear(); paths.clear()
        out = php("$r = nibblyAiChatWithTools(json_decode(file_get_contents('history.json'), true), " + tool + ", ['model' => 'reasoner']); echo json_encode(['text' => $r['text'], 'kind' => $r['kind']]);")
        assert json.loads(out) == {"text": "all done", "kind": "final"} and [p.rsplit("/", 1)[1] for p in paths] == ["responses"], (out, paths)
        sent = bodies[0]
        assert sent["instructions"] == "be brief", sent.get("instructions")
        assert sent["input"][0] == {"role": "user", "content": "hi"}, sent["input"]
        assert sent["input"][1] == {"type": "reasoning", "encrypted_content": "ENC123", "summary": [], "id": "rs_1"}, sent["input"][1]
        assert sent["input"][2] == {"type": "function_call", "call_id": "call_abc", "name": "ping", "arguments": "{}"}, sent["input"][2]
        assert sent["input"][3] == {"type": "function_call_output", "call_id": "call_abc", "output": "pong"} and len(sent["input"]) == 4, sent["input"]
        # Plain chat without tools stays on chat/completions
        bodies.clear(); paths.clear()
        result, sent = chat("reasoner")
        assert result == {"ok": "hello from reasoner"} and paths[0].endswith("/chat/completions"), (result, paths)
        # api.openai.com itself always uses the Responses API; other hosts chat/completions
        out = json.loads(php("$s = nibblyAiDefaults(); $s['provider'] = 'openai-compatible'; $s['baseUrl'] = 'https://api.openai.com/v1'; $t = $s; $t['baseUrl'] = 'http://localhost:11434/v1'; echo json_encode([nibblyAgentUsesResponsesApi($s, 'gpt-x'), nibblyAgentUsesResponsesApi($t, 'llama')]);"))
        assert out == [True, False], out
        # api.openai.com gets max_completion_tokens straight away, other hosts keep max_tokens
        out = json.loads(php("echo json_encode([nibblyAiApplyModelQuirks(['baseUrl' => 'https://api.openai.com/v1'], ['model' => 'gpt-x', 'max_tokens' => 10, 'temperature' => 0.3]), nibblyAiApplyModelQuirks(['baseUrl' => 'http://localhost:11434/v1'], ['model' => 'llama', 'max_tokens' => 10])]);"))
        assert out[0] == {"model": "gpt-x", "temperature": 0.3, "max_completion_tokens": 10} and out[1] == {"model": "llama", "max_tokens": 10}, out
        assert not (root / "content/ai-model-quirks.json").read_text().count("test-key"), "no key in the quirks file"

        # ---- Model list for the dropdowns ------------------------------------------------------------------------------
        site.start()
        admin = Client(site).login("admin")
        editor = Client(site).login("editor")
        status, payload = admin.api("ai-list-models")
        assert status == 200 and payload["success"], payload
        assert payload["data"]["models"] == ["gpt-a", "gpt-b", "o3-mini"], "chat models only, sorted, no duplicates or odd names: " + json.dumps(payload["data"])
        assert "test-key" not in json.dumps(payload), "no key in the answer"
        assert editor.api("ai-list-models")[0] == 403, "admins only"
        assert admin.request("/admin/api.php", {"action": "ai-list-models"})[0] == 403, "not without the CSRF token"
        assert admin.request("/admin/api.php?action=ai-list-models")[0] == 405, "not over GET"
        write_json(root / "content/ai-settings.json", {**settings, "apiKey": "wrong", "providerCredentials": {**settings["providerCredentials"], "openai-compatible": {**settings["providerCredentials"]["openai-compatible"], "apiKey": "wrong"}}})
        status, payload = admin.api("ai-list-models")
        assert payload["success"] is False and "Incorrect API key" in payload["message"] and "wrong" not in payload["message"].replace("Incorrect", ""), payload
        print("PASS model quirks: max_completion_tokens and temperature learned per model, real errors not retried, tool gateway, model list for the settings, new default limits")
    finally:
        site.close()
        server.shutdown()
