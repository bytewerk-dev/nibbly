#!/usr/bin/env python3
"""Exercise Kie adapters against a loopback mock, without paid provider calls."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import subprocess
import tempfile
from threading import Thread
from support import copy_core, write_json

requests = []


class Provider(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def send_json(self, value):
        body = json.dumps(value).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        requests.append((self.path, body))
        if self.path == "/codex/v1/responses":
            self.send_json({"output": [{"type": "message", "content": [{"type": "output_text", "text": "Grüße"}]}],
                            "usage": {"input_tokens": 10, "output_tokens": 3}})
        elif self.path == "/claude/v1/messages":
            self.send_json({"content": [{"type": "text", "text": "Grüße"}],
                            "usage": {"input_tokens": 10, "output_tokens": 3}})
        elif self.path == "/gemini-3-5-flash-openai/v1/chat/completions":
            self.send_json({"choices": [{"message": {"content": "Grüße"}}],
                            "usage": {"prompt_tokens": 10, "completion_tokens": 3}})
        elif self.path == "/api/v1/jobs/createTask":
            self.send_json({"code": 200, "data": {"taskId": "fixture"}})
        else:
            self.send_error(404)

    def do_GET(self):
        # Deliberately stop after task creation: this test checks the request
        # contract and failure propagation, not external image downloads.
        self.send_json({"code": 200, "data": {"state": "fail", "failMsg": "fixture-stop"}})


with tempfile.TemporaryDirectory(prefix="nibbly-provider-smoke-") as folder:
    root = copy_core(folder)
    server = ThreadingHTTPServer(("127.0.0.1", 0), Provider)
    thread = Thread(target=server.serve_forever, daemon=True)
    thread.start()
    prefix = "require 'includes/ai/ai-helper.php'; "

    def php(code):
        return subprocess.check_output(["php", "-r", prefix + code], cwd=root, text=True)

    try:
        settings = json.loads(php("echo json_encode(nibblyAiDefaults());"))
        settings.update(enabled=True, provider="kie", allowLocalProvider=True,
                        baseUrl=f"http://127.0.0.1:{server.server_port}", apiKey="fixture-key")
        settings["providerCredentials"]["kie"]["baseUrl"] = settings["baseUrl"]
        settings["providerCredentials"]["kie"]["apiKey"] = "fixture-key"
        for model, endpoint, cap in [
            ("gpt-5-6-luna", "/codex/v1/responses", "max_output_tokens"),
            ("claude-sonnet-5", "/claude/v1/messages", "max_tokens"),
            ("gemini-3-5-flash", "/gemini-3-5-flash-openai/v1/chat/completions", "max_tokens"),
        ]:
            settings["chatModel"] = model
            write_json(root / "content/ai-settings.json", settings)
            before = len(requests)
            result = json.loads(php("$d=[]; $r=nibblyAiChatStream([['role'=>'user','content'=>'Hallo']], ['maxOutputTokens'=>80], function($v)use(&$d){$d[]=$v;}); echo json_encode([$r,$d]);"))
            assert result[0]["text"] == "Grüße" and result[1] == ["Grüße"]
            assert len(requests) == before + 1, "Streaming fallback made duplicate requests"
            assert requests[-1][0] == endpoint, requests[-1]
            assert requests[-1][1][cap] == 80, "Output cap missing"
        for size, ratio in [("1536x1024", "3:2"), ("1024x1536", "2:3")]:
            result = php("try { nibblyAiGenerateKieImages(nibblyAiLoadSettings(), ['model'=>'gpt-image-2', 'prompt'=>str_repeat('ä',3999).'€'.str_repeat('x',5000), 'size'=>'" + size + "','aspectRatio'=>'auto']); } catch(Throwable $e) { echo $e->getMessage(); }")
            assert result == "fixture-stop", result
            payload = requests[-1][1]["input"]
            assert payload["aspect_ratio"] == ratio, payload
            assert len(payload["prompt"]) == 8000 and payload["prompt"][3999] == "€"
        before = len(requests)
        result = php("try { nibblyAiGenerateKieImages(nibblyAiLoadSettings(), ['prompt'=>\"\\xff\"]); } catch(Throwable $e) { echo $e->getMessage(); }")
        assert "UTF-8" in result and len(requests) == before, "Invalid UTF-8 reached provider"
        result = json.loads(php("echo json_encode(nibblyAiCleanMessages([['role'=>'user','content'=>str_repeat('ä',11999).'€end']]));"))
        assert result[0]["content"].endswith("€end"), "Chat truncated a Unicode character"
    finally:
        server.shutdown()
        server.server_close()
        thread.join()
print("PASS Kie routes, single-request streaming fallback, output caps, aspect ratios, UTF-8 and provider errors")
