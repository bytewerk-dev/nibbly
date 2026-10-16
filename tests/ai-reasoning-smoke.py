#!/usr/bin/env python3
"""Reasoning effort per purpose and per-model costs, end to end against a scripted provider.

The registry only knows models on their official hosts, so the test adds the mock's host to the registry and the
price catalog of its own disposable copy of the CMS. The code paths are the real ones."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import subprocess
import tempfile
from threading import Thread
from support import Site, write_json

requests = []


class Provider(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def reply(self, payload):
        data = json.dumps(payload).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        requests.append((self.path, body))
        if self.path.endswith("/responses"):
            return self.reply({"status": "completed", "output": [{"type": "message", "role": "assistant", "content": [{"type": "output_text", "text": "tool answer"}]}],
                               "usage": {"input_tokens": 1000, "output_tokens": 400, "input_tokens_details": {"cached_tokens": 200}, "output_tokens_details": {"reasoning_tokens": 300}}})
        if self.path.endswith("/messages"):
            return self.reply({"type": "message", "role": "assistant", "model": body["model"], "stop_reason": "end_turn", "content": [{"type": "text", "text": "claude says hi"}],
                               "usage": {"input_tokens": 100, "cache_read_input_tokens": 900, "cache_creation_input_tokens": 50, "output_tokens": 30}})
        return self.reply({"choices": [{"message": {"role": "assistant", "content": "hello from " + str(body.get("model"))}, "finish_reason": "stop"}],
                           "usage": {"prompt_tokens": 1000, "completion_tokens": 400, "prompt_tokens_details": {"cached_tokens": 600}, "completion_tokens_details": {"reasoning_tokens": 250}}})


server = ThreadingHTTPServer(("127.0.0.1", 0), Provider)
Thread(target=server.serve_forever, daemon=True).start()
HOST = "127.0.0.1"
URL = f"http://{HOST}:{server.server_port}"

with tempfile.TemporaryDirectory(prefix="nibbly-reasoning-") as folder:
    site = Site(folder)
    root = site.root
    write_json(root / "content/settings.json", {"modules": {"ai": True}, "privacy": {"analyticsEnabled": False}})

    caps_path = root / "includes/ai/model-capabilities.json"
    caps = json.loads(caps_path.read_text())
    for entry in list(caps["models"]):
        copy = json.loads(json.dumps(entry))
        copy["host"] = HOST
        caps["models"].append(copy)
    caps_path.write_text(json.dumps(caps))
    price_path = root / "includes/ai/pricing-catalog.json"
    prices = json.loads(price_path.read_text())
    for entry in list(prices["models"]):
        copy = json.loads(json.dumps(entry))
        copy["host"] = HOST
        prices["models"].append(copy)
    price_path.write_text(json.dumps(prices))

    def php(code):
        result = subprocess.run(["php", "-d", "display_errors=0", "-r", "require 'includes/ai/ai-helper.php'; " + code], cwd=root, text=True, capture_output=True)
        assert result.returncode == 0, result.stdout + result.stderr
        return result.stdout

    defaults = json.loads(php("echo json_encode(nibblyAiDefaults());"))

    def configure(provider, chat, text, reasoning, **extra):
        settings = json.loads(json.dumps(defaults))
        settings.update(enabled=True, provider=provider, allowLocalProvider=True, chatModel=chat, textModel=text, baseUrl=URL, apiKey="test-key-123", **extra)
        if reasoning is not None:
            settings["reasoning"] = reasoning
        else:
            del settings["reasoning"]
        settings["providerCredentials"][provider].update(baseUrl=URL, apiKey="test-key-123")
        settings["limits"].update(dailyRequests=1000, dailyTextRequests=1000, monthlyBudgetCents=100000)
        (root / "content/ai-usage.json").unlink(missing_ok=True)
        write_json(root / "content/ai-settings.json", settings)

    def run(code):
        requests.clear()
        return json.loads(php("try { $r = " + code + "; echo json_encode($r); } catch (Throwable $e) { echo json_encode(['error' => $e->getMessage()]); }"))

    def usage():
        return json.loads((root / "content/ai-usage.json").read_text())

    try:
        # --- OpenAI: chat purpose and text purpose with their own levels, also for the same model -------------------------------
        configure("openai-compatible", "gpt-6.1-sol", "gpt-6.1-sol", {"chat": "high", "text": "low"})
        result = run("nibblyAiChat([['role' => 'user', 'content' => 'hi']], ['maxOutputTokens' => 500])")
        path, body = requests[0]
        assert path.endswith("/chat/completions") and body["reasoning_effort"] == "high" and "temperature" not in body, body
        assert body.get("max_completion_tokens", body.get("max_tokens")) == 500 + 8000, "thinking headroom is added to the output limit: " + json.dumps(body)
        result = run("nibblyAiGenerateText('write', ['maxOutputTokens' => 400])")
        path, body = requests[0]
        assert body["reasoning_effort"] == "low" and body.get("max_completion_tokens", body.get("max_tokens")) == 400 + 2000, body
        assert result["text"] == "hello from gpt-6.1-sol", result
        # the usage: cached tokens are part of the prompt, reasoning tokens part of the output (counted once)
        u = result["usage"]
        assert u["inputTokens"] == 1000 and u["outputTokens"] == 400 and u["reasoningTokens"] == 250 and u["priced"] is True and u["usageEstimated"] is False, u
        expected = round(((400 * 2 + 600 * 0.10 + 400 * 10) / 1e6) / 1.1208 * 100, 4)
        assert abs(u["estimatedCostCents"] - expected) < 1e-9, (u, expected)
        # streaming builds the same parameters (the scripted provider answers with plain JSON, which the stream code accepts)
        run("nibblyAiChatStream([['role' => 'user', 'content' => 'hi']], ['purpose' => 'text', 'maxOutputTokens' => 300], static function ($delta) {})")
        path, body = requests[0]
        assert body["stream"] is True and body["reasoning_effort"] == "low" and "temperature" not in body and body.get("max_completion_tokens", body.get("max_tokens")) == 300 + 2000, body
        # a draft is chat work even though it goes through the text function
        run("nibblyAiGenerateText('draft', ['purpose' => 'chat', 'model' => 'gpt-6.1-sol'])")
        assert requests[0][1]["reasoning_effort"] == "high", requests[0][1]

        # --- Responses API (tools) ---------------------------------------------------------------------------------------------
        (root / "content/ai-model-quirks.json").write_text(json.dumps({f"{HOST}|gpt-6.1-sol": {"api": "responses"}}))
        tool = "[['name' => 'ping', 'description' => 'x', 'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => new stdClass()]]]"
        result = run("nibblyAiChatWithTools([['role' => 'user', 'content' => 'hi']], " + tool + ", ['temperature' => 0.2])")
        path, body = requests[0]
        assert path.endswith("/responses") and body["reasoning"] == {"effort": "high"} and "temperature" not in body, body
        assert result["usage"]["priced"] is True and result["usage"]["reasoningTokens"] == 300, result["usage"]

        # --- Anthropic ---------------------------------------------------------------------------------------------------------
        configure("anthropic", "claude-sonnet-5-5", "claude-haiku-5-5", {"chat": "recommended", "text": "recommended"})
        result = run("nibblyAiChat([['role' => 'user', 'content' => 'hi']], ['temperature' => 0.3])")
        path, body = requests[0]
        assert path.endswith("/messages") and body["output_config"] == {"effort": "medium"} and "temperature" not in body and "thinking" not in body, body
        run("nibblyAiChatStream([['role' => 'user', 'content' => 'hi']], ['purpose' => 'chat'], static function ($delta) {})")
        path, body = requests[0]
        assert path.endswith("/messages") and body["stream"] is True and body["output_config"] == {"effort": "medium"} and "temperature" not in body, body
        result = run("nibblyAiGenerateText('seo', ['maxOutputTokens' => 300])")
        path, body = requests[0]
        assert body["model"] == "claude-haiku-5-5" and body["output_config"] == {"effort": "low"}, body
        u = result["usage"]
        assert u["inputTokens"] == 100 + 900 + 50 and u["priced"] is True, u
        expected = round(((100 * 0.10 + 900 * 0.01 + 50 * 0.125 + 30 * 0.5) / 1e6) / 1.1208 * 100, 4)
        assert abs(u["estimatedCostCents"] - expected) < 1e-9, (u, expected)

        # --- Installs from before: the request stays as it was ---------------------------------------------------------------------
        configure("anthropic", "claude-sonnet-5-5", "claude-haiku-5-5", None)
        result = run("nibblyAiChat([['role' => 'user', 'content' => 'hi']], ['temperature' => 0.3])")
        path, body = requests[0]
        assert "output_config" not in body and body["temperature"] == 0.3 and body["max_tokens"] == 16000, body
        configure("openai-compatible", "gpt-6-luna", "gpt-6-luna", None)
        result = run("nibblyAiChat([['role' => 'user', 'content' => 'hi']])")
        body = requests[0][1]
        assert "reasoning_effort" not in body and body["temperature"] == 0.3, body

        # --- Unknown models: nothing on suspicion; unknown price: allowed, counted apart, never 0 -------------------------------------
        configure("openai-compatible", "my-local-model", "my-local-model", {"chat": "recommended", "text": "recommended"})
        result = run("nibblyAiChat([['role' => 'user', 'content' => 'hi']])")
        body = requests[0][1]
        assert "reasoning_effort" not in body and "reasoning" not in body and body["temperature"] == 0.3, body
        assert result["usage"]["priced"] is False, result["usage"]
        month = list(usage()["months"].values())[0]
        assert month["unpricedRequests"] == 1 and month["estimatedCostCents"] == 0 and month["requests"] == 1, month

        # --- Budget with prices per model: reservation uses the model that is really used --------------------------------------------
        configure("openai-compatible", "gpt-6-luna", "gpt-6.1-sol", {"chat": "recommended", "text": "recommended"})
        settings = json.loads((root / "content/ai-settings.json").read_text())
        settings["limits"].update(monthlyBudgetCents=1)
        write_json(root / "content/ai-settings.json", settings)
        cheap = run("nibblyAiChat([['role' => 'user', 'content' => 'hi']], ['maxOutputTokens' => 100])")
        assert "error" not in cheap, cheap
        costly = run("nibblyAiChat([['role' => 'user', 'content' => 'hi']], ['model' => 'gpt-6.1-sol', 'maxOutputTokens' => 16000])")
        assert "budget" in costly.get("error", "").lower(), "a request whose worst case does not fit the budget is refused: " + json.dumps(costly)
        print("PASS reasoning effort per purpose for OpenAI, Responses and Anthropic; temperature left out; headroom; cache and reasoning usage; legacy and unknown models unchanged; unknown prices; model-dependent budget")
    finally:
        site.close() if hasattr(site, "close") else None
        server.shutdown()
