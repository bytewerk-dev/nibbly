#!/usr/bin/env python3
"""Image services apart from the chat provider: fal.ai queue, Comet (Gemini route), a custom OpenAI-compatible service,
keys, prices, resume, SSRF, and the agent making an image with an Anthropic or OpenAI chat. All against loopback mocks."""
import base64
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
import subprocess
import tempfile
from threading import Thread
from support import Client, Site, write_json

KEY_FAL = "fal-secret-key-123"
KEY_COMET = "comet-secret-key-456"
KEY_CUSTOM = "custom-secret-key-789"
KEY_CHAT = "sk-ant-chat-secret-000"

PNG = subprocess.check_output(["php", "-r", "$i=imagecreatetruecolor(64,48); imagefill($i,0,0,imagecolorallocate($i,30,120,200)); ob_start(); imagepng($i); echo ob_get_clean();"])
state = {"list_fail": False, "list_reject_key": False, "status": "ok", "foreign": False, "private_result": False, "num": 1, "submits": 0, "gemini_thought": True, "custom_url": False}
log = []        # (method, path, headers, body)
internal_hits = []
chat_queue = []
chat_requests = []


def reply(handler, status, body, content_type="application/json"):
    data = body if isinstance(body, bytes) else json.dumps(body).encode()
    handler.send_response(status)
    handler.send_header("Content-Type", content_type)
    handler.send_header("Content-Length", str(len(data)))
    handler.end_headers()
    handler.wfile.write(data)


class Mock(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def body(self):
        length = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(length) if length else b""
        try:
            return json.loads(raw)
        except ValueError:
            return raw

    def do_POST(self):
        body = self.body()
        log.append(("POST", self.path, dict(self.headers), body))
        port = self.server.server_port
        if self.path.startswith("/fal-ai/") and "/requests/" not in self.path:
            state["submits"] += 1
            state["num"] = int(body.get("num_images", 1)) if isinstance(body, dict) else 1
            status_url = f"http://127.0.0.1:{self.server.internal_port}/stolen/status" if state["foreign"] else f"http://127.0.0.1:{port}{self.path}/requests/req1/status"
            return reply(self, 200, {"request_id": "req1", "status_url": status_url, "response_url": f"http://127.0.0.1:{port}{self.path}/requests/req1"})
        if ":generateContent" in self.path:
            parts = []
            if state["gemini_thought"]:
                parts.append({"thought": True, "inlineData": {"mimeType": "image/png", "data": base64.b64encode(b"not an image").decode()}})
            parts.append({"text": "Here is your image."})
            parts.append({"inlineData": {"mimeType": "image/png", "data": base64.b64encode(PNG).decode()}})
            return reply(self, 200, {"candidates": [{"content": {"parts": parts}}]})
        if self.path == "/v1/images/generations":
            if state["custom_url"]:
                return reply(self, 200, {"data": [{"url": f"http://127.0.0.1:{port}/img.png"}]})
            return reply(self, 200, {"data": [{"b64_json": base64.b64encode(PNG).decode(), "revised_prompt": "a revised prompt"}]})
        if self.path == "/chat/completions":
            chat_requests.append(body)
            answer = chat_queue.pop(0) if chat_queue else {"choices": [{"message": {"role": "assistant", "content": "unscripted"}, "finish_reason": "stop"}], "usage": {"prompt_tokens": 1, "completion_tokens": 1}}
            return reply(self, 200, answer(body) if callable(answer) else answer)
        reply(self, 404, {"error": {"message": "unknown route"}})

    def do_GET(self):
        log.append(("GET", self.path, dict(self.headers), None))
        port = self.server.server_port
        if self.path.startswith("/v1/models") or self.path == "/api/models":
            headers = {k.lower(): v for k, v in self.headers.items()}
            if state["list_fail"] or (state["list_reject_key"] and "authorization" in headers):
                return reply(self, 500 if state["list_fail"] else 401, {"error": "no"})
            if self.path == "/api/models":
                return reply(self, 200, {"success": True, "total": 3, "data": [{"id": "gpt-image-2", "model_type": "image"}, {"id": "gpt-5", "model_type": "chat"}, {"id": "bad id!", "model_type": "image"}]})
            if "cursor=c2" in self.path:
                return reply(self, 200, {"models": [{"endpoint_id": "fal-ai/second", "metadata": {"display_name": "Second <b>model</b>"}}, {"endpoint_id": "bad id!!"}], "has_more": False, "next_cursor": None})
            return reply(self, 200, {"models": [{"endpoint_id": "fal-ai/nano-banana-2", "metadata": {"display_name": "Nano Banana 2"}}, {"endpoint_id": "fal-ai/flux/dev", "metadata": {"display_name": "FLUX.1 [dev]"}}], "has_more": True, "next_cursor": "c2"})
        if self.path.endswith("/status"):
            mode = state["status"]
            if mode == "progress":
                return reply(self, 200, {"status": "IN_PROGRESS"})
            if mode == "error":
                return reply(self, 200, {"status": "COMPLETED", "error": "model refused the prompt", "error_type": "content_policy"})
            return reply(self, 200, {"status": "COMPLETED"})
        if "/requests/" in self.path:
            host = f"cdn.fal.test:{port}"
            if state["private_result"]:
                host = f"127.0.0.1:{self.server.internal_port}"
            return reply(self, 200, {"images": [{"url": f"http://{host}/img.png", "content_type": "image/png"} for _ in range(state["num"])]})
        if self.path == "/img.png":
            return reply(self, 200, PNG, "image/png")
        reply(self, 404, {})


class Internal(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def do_GET(self):
        internal_hits.append(self.path)
        reply(self, 200, PNG, "image/png")


def tool_calls(name, arguments, call_id):
    return {"choices": [{"message": {"role": "assistant", "content": None, "tool_calls": [{"id": call_id, "type": "function", "function": {"name": name, "arguments": json.dumps(arguments)}}]}, "finish_reason": "tool_calls"}], "usage": {"prompt_tokens": 100, "completion_tokens": 20}}


def say(text):
    return {"choices": [{"message": {"role": "assistant", "content": text}, "finish_reason": "stop"}], "usage": {"prompt_tokens": 100, "completion_tokens": 20}}


with tempfile.TemporaryDirectory(prefix="nibbly-image-service-") as folder:
    mock = ThreadingHTTPServer(("127.0.0.1", 0), Mock)
    internal = ThreadingHTTPServer(("127.0.0.1", 0), Internal)
    mock.internal_port = internal.server_port
    Thread(target=mock.serve_forever, daemon=True).start()
    Thread(target=internal.serve_forever, daemon=True).start()
    base = f"http://127.0.0.1:{mock.server_port}"
    os.environ["PHP_CLI_SERVER_WORKERS"] = "8"
    os.environ["NIBBLY_AGENT_TEST_NET"] = json.dumps({
        "imageBases": {"fal": base, "comet": base},
        "imageListBases": {"fal": f"http://api.fal.test:{mock.server_port}", "comet": f"http://api.comet.test:{mock.server_port}"}, "imagePollMs": 10, "ports": [mock.server_port, internal.server_port],
        "resolve": {"cdn.fal.test": ["93.184.216.34"], "api.fal.test": ["93.184.216.34"], "api.comet.test": ["93.184.216.34"]}, "connectMap": {"93.184.216.34": "127.0.0.1"}})
    site = Site(folder)
    root = site.root
    write_json(root / "content/settings.json", {"modules": {"ai": True}, "privacy": {"analyticsEnabled": False}})

    def php(code, check=True):
        result = subprocess.run(["php", "-d", "display_errors=0", "-r", "require 'includes/ai/ai-helper.php'; " + code], cwd=root, text=True, capture_output=True)
        if check:
            assert result.returncode == 0, result.stdout + result.stderr
        return result.stdout

    defaults = json.loads(php("echo json_encode(nibblyAiDefaults());"))

    def configure(chat="anthropic", image=None, keys=None, extra=None, limits=None, chat_base=None):
        settings = json.loads(json.dumps(defaults))
        settings.update(enabled=True, allowLocalProvider=True)
        if chat == "anthropic":
            settings.update(provider="anthropic", baseUrl="https://api.anthropic.com/v1", chatModel="claude-sonnet-5-5", textModel="claude-haiku-5-5")
            settings["providerCredentials"]["anthropic"].update(baseUrl="https://api.anthropic.com/v1", apiKey=KEY_CHAT)
        else:
            settings.update(provider="openai-compatible", baseUrl=chat_base or base, chatModel="gpt-4.1-mini", apiKey="chat-key")
            settings["providerCredentials"]["openai-compatible"].update(baseUrl=chat_base or base, apiKey="chat-key")
        settings["limits"].update({"dailyRequests": 1000, "dailyTextRequests": 1000, "dailyImageRequests": 100, "monthlyBudgetCents": 100000, "requestTimeoutSeconds": 30, **(limits or {})})
        settings["imageService"] = image or {"provider": "fal", "model": "", "models": {}, "custom": {"baseUrl": "", "sizeMode": "size", "authStyle": "bearer", "extraParams": {}}}
        settings["imageServices"] = {k: {"apiKey": v} for k, v in (keys if keys is not None else {"fal": KEY_FAL}).items()}
        settings["agent"] = {"enabled": True, "limits": {"runsPerHour": 100, "stepsPerMinute": 600}}
        settings.update(extra or {})
        write_json(root / "content/ai-settings.json", settings)
        for name in ("content/ai-usage.json", "content/ai-secrets.json"):
            (root / name).unlink(missing_ok=True)

    def reservations():
        return json.loads((root / "content/ai-usage.json").read_text())["reservations"]

    def generate(prompt="A friendly robot", **options):
        code = "$r = nibblyAiGenerateImage(" + json.dumps(prompt) + ", json_decode(" + json.dumps(json.dumps(options)) + ", true)); echo json_encode($r);"
        return json.loads(php(code))

    def last(kind="POST", contains=""):
        return [entry for entry in log if entry[0] == kind and contains in entry[1]][-1]

    def fails(code):
        result = subprocess.run(["php", "-d", "display_errors=0", "-r", "require 'includes/ai/ai-helper.php'; try { " + code + " echo 'NO-ERROR'; } catch (Throwable $e) { echo 'ERROR:' . $e->getMessage(); }"], cwd=root, text=True, capture_output=True)
        assert result.stdout.startswith("ERROR:"), result.stdout + result.stderr
        return result.stdout[6:]

    try:
        # ---- fal.ai with an Anthropic chat: the case that was impossible before -------------------------------------------------
        configure()
        status = json.loads(php("echo json_encode(nibblyAiImageServicePublic(nibblyAiLoadSettings(false))['active']);"))
        assert status["usable"] and status["id"] == "fal" and status["keySource"] == "own" and status["model"] == "fal-ai/nano-banana-2", status
        result = generate(aspectRatio="16:9", size="1536x1024", outputFormat="webp", imageScale=2048)
        assert len(result["paths"]) == 1 and (root / result["paths"][0].lstrip("/")).is_file(), result
        head = (root / result["paths"][0].lstrip("/")).read_bytes()
        assert head[8:12] == b"WEBP", "stored in the requested format"
        submit = last("POST", "/fal-ai/nano-banana-2")
        assert submit[2].get("Authorization") == "Key " + KEY_FAL, "fal takes 'Key <key>'"
        body = submit[3]
        assert body["aspect_ratio"] == "16:9" and body["output_format"] == "webp" and body["resolution"] == "2K" and "num_images" not in body and body["prompt"] == "A friendly robot", body
        assert last("GET", "/status")[2].get("Authorization") == "Key " + KEY_FAL
        meta = json.loads((root / "content/media-meta.json").read_text())["image"]
        assert any(v.get("ai") == "generated" for v in meta.values()), "the AI label is stored with the image: %s" % meta
        reservation = list(reservations().values())[-1]
        assert reservation["model"] == "fal-ai/nano-banana-2" and reservation["service"] == "fal" and reservation["provider"] == "fal" and reservation["status"] == "settled", reservation
        assert reservation["reservedCents"] == 5.0 and reservation["priced"] is True, "the default price per image applies when nothing else is on file: %s" % reservation

        # two images in one task; aspect ratios the model does not know go to the nearest one
        before = state["submits"]
        result = generate(count=2, aspectRatio="4:3", outputFormat="png")
        assert len(result["paths"]) == 2 and state["submits"] == before + 1, "one request for two images (num_images)"
        body = last("POST", "/fal-ai/nano-banana-2")[3]
        assert body["num_images"] == 2 and body["aspect_ratio"] == "4:3" and body["output_format"] == "png", body
        assert list(reservations().values())[-1]["reservedCents"] == 10.0
        generate(aspectRatio="auto", size="1024x1536")
        assert last("POST", "/fal-ai/nano-banana-2")[3]["aspect_ratio"] == "2:3", "a pixel size becomes its ratio"

        # a model with image_size presets (not verified in the registry) and custom parameters
        configure(image={"provider": "fal", "model": "fal-ai/flux/schnell", "models": {}, "custom": {"baseUrl": "", "sizeMode": "size", "authStyle": "bearer", "extraParams": {"enable_safety_checker": False}}})
        generate(aspectRatio="16:9", outputFormat="webp")
        body = last("POST", "/fal-ai/flux/schnell")[3]
        assert body["image_size"] == "landscape_16_9" and body["output_format"] == "png", body  # webp is not offered by the model: png
        assert body["enable_safety_checker"] is False, "custom parameters are added to the request"
        info = json.loads(php("echo json_encode(nibblyAiImageServicePublic(nibblyAiLoadSettings(false))['active']);"))
        assert info["verified"] is False and info["note"], "unverified parameters are marked"
        # an unknown model gets the defaults of the service, also marked
        configure(image={"provider": "fal", "model": "fal-ai/brand-new-model", "models": {}, "custom": {"baseUrl": "", "sizeMode": "size", "authStyle": "bearer", "extraParams": {}}})
        generate(aspectRatio="3:2")
        body = last("POST", "/fal-ai/brand-new-model")[3]
        assert body["aspect_ratio"] == "3:2" and set(body) == {"prompt", "aspect_ratio"}, body

        # reference images need an edit endpoint
        configure()
        ref = root / "assets/images/placeholder-image.webp"
        result = generate(referenceMediaPaths=["/assets/images/placeholder-image.webp"])
        edit = last("POST", "/fal-ai/nano-banana-2/edit")[3]
        assert edit["image_urls"][0].startswith("data:image/") and result["paths"], "reference image sent to the edit endpoint"
        configure(image={"provider": "fal", "model": "fal-ai/flux/schnell", "models": {}, "custom": {"baseUrl": "", "sizeMode": "size", "authStyle": "bearer", "extraParams": {}}})
        assert "reference" in fails("nibblyAiGenerateImage('x', ['referenceMediaPaths' => ['/assets/images/placeholder-image.webp']]);").lower()

        # ---- failures -----------------------------------------------------------------------------------------------------------
        configure()
        state["status"] = "error"
        message = fails("nibblyAiGenerateImage('x');")
        assert "model refused the prompt" in message and KEY_FAL not in message, message
        state["status"] = "ok"
        state["private_result"] = True
        internal_before = len(internal_hits)
        message = fails("nibblyAiGenerateImage('x');")
        assert "not allowed" in message and len(internal_hits) == internal_before, "a result address on a private host is never fetched: " + message
        state["private_result"] = False
        state["foreign"] = True
        generate()
        assert not internal_hits, "a status address on another host is not followed (the key would go with it): %s" % internal_hits
        state["foreign"] = False

        # ---- resume: the task stays in the ledger and is not submitted again ---------------------------------------------------------
        configure(limits={"requestTimeoutSeconds": 5})
        state["status"] = "progress"
        before = state["submits"]
        message = fails("nibblyAiGenerateImage('x', ['_requestId' => 'image_job_0123456789abcdef']);")
        assert "did not finish" in message, message
        task = reservations()["image_job_0123456789abcdef"]["tasks"][0]
        assert task["id"] == "req1" and task["state"] == "running" and state["submits"] == before + 1, task
        state["status"] = "ok"
        result = json.loads(php("echo json_encode(nibblyAiGenerateImage('x', ['_requestId' => 'image_job_0123456789abcdef']));"))
        assert state["submits"] == before + 1 and len(result["paths"]) == 1, "resumed without a second submission"
        assert reservations()["image_job_0123456789abcdef"]["status"] == "settled"
        assert "urls" not in reservations()["image_job_0123456789abcdef"]["tasks"][0] and reservations()["image_job_0123456789abcdef"]["outputs"], "result addresses are dropped from the ledger once the images are stored"

        # ---- Comet: Gemini route ------------------------------------------------------------------------------------------------------
        configure(image={"provider": "comet", "model": "", "models": {}, "custom": {"baseUrl": "", "sizeMode": "size", "authStyle": "bearer", "extraParams": {"safetySettings": [{"category": "x"}]}}}, keys={"comet": KEY_COMET})
        result = generate(aspectRatio="16:9", imageScale=3840, outputFormat="webp")
        entry = last("POST", ":generateContent")
        assert entry[1] == "/v1beta/models/gemini-3.1-flash-image-preview:generateContent" and entry[2].get("Authorization") == "Bearer " + KEY_COMET, entry[:2]
        gen = entry[3]["generationConfig"]
        assert gen["imageConfig"] == {"aspectRatio": "16:9", "imageSize": "4K"} and gen["responseModalities"] == ["TEXT", "IMAGE"] and entry[3]["safetySettings"], entry[3]
        assert (root / result["paths"][0].lstrip("/")).read_bytes()[8:12] == b"WEBP", "the thought part is skipped, the real image stored"
        result = generate(referenceMediaPaths=["/assets/images/placeholder-image.webp"])
        parts = last("POST", ":generateContent")[3]["contents"][0]["parts"]
        assert parts[1]["inline_data"]["mime_type"] == "image/webp" and parts[1]["inline_data"]["data"] and not parts[1]["inline_data"]["data"].startswith("data:"), "raw base64, no data: prefix"

        # ---- custom OpenAI-compatible service ----------------------------------------------------------------------------------------------
        custom = {"provider": "custom", "model": "my-image-model", "models": {}, "custom": {"baseUrl": base + "/v1", "sizeMode": "size", "authStyle": "bearer", "extraParams": {"style": "natural"}}}
        configure(image=custom, keys={"custom": KEY_CUSTOM})
        result = generate(aspectRatio="16:9")
        entry = last("POST", "/v1/images/generations")
        assert entry[2].get("Authorization") == "Bearer " + KEY_CUSTOM and entry[3]["model"] == "my-image-model" and entry[3]["size"] == "1536x1024" and entry[3]["style"] == "natural" and "output_format" not in entry[3], entry[3]
        custom["custom"].update(authStyle="x-api-key", sizeMode="aspect_ratio")
        configure(image=custom, keys={"custom": KEY_CUSTOM})
        state["custom_url"] = True
        generate(aspectRatio="16:9")
        entry = last("POST", "/v1/images/generations")
        headers = {k.lower(): v for k, v in entry[2].items()}
        assert headers.get("x-api-key") == KEY_CUSTOM and "authorization" not in headers and entry[3]["aspect_ratio"] == "16:9" and "size" not in entry[3], entry
        state["custom_url"] = False

        # ---- keys: stored apart, masked, removed ---------------------------------------------------------------------------------------------
        configure(keys={})
        save = {"enabled": True, "provider": "anthropic", "allowLocalProvider": True, "apiKey": KEY_CHAT, "imageService": {"provider": "fal", "model": "fal-ai/nano-banana-2"},
                "imageServices": {"fal": {"apiKey": KEY_FAL}}, "features": {"imageGeneration": True}}
        php("nibblyAiSaveSettings(json_decode(" + json.dumps(json.dumps(save)) + ", true));")
        secrets = json.loads((root / "content/ai-secrets.json").read_text())
        assert secrets["imageServices"]["fal"]["apiKey"] == KEY_FAL and KEY_FAL not in (root / "content/ai-settings.json").read_text(), "the key is in the secrets file only"
        masked = dict(save, imageServices={"fal": {"apiKey": "••••••••"}})
        php("nibblyAiSaveSettings(json_decode(" + json.dumps(json.dumps(masked)) + ", true));")
        assert json.loads((root / "content/ai-secrets.json").read_text())["imageServices"]["fal"]["apiKey"] == KEY_FAL, "a mask never replaces the key"
        blank = dict(save, imageServices={})
        public = php("echo json_encode(nibblyAiSaveSettings(json_decode(" + json.dumps(json.dumps(blank)) + ", true)));")
        assert KEY_FAL not in public and KEY_CHAT not in public, "no key in the settings that go to the browser"
        assert json.loads(public)["imageServiceStatus"]["services"]["fal"] == {"hasApiKey": True, "chatKeyAvailable": False, "keySource": "own"}
        # prices per model
        priced = dict(save, pricing={"imageModels": {"fal|fal-ai/nano-banana-2": {"perImage": 0.04, "currency": "USD"}, "bad key": {"perImage": 1}}})
        php("nibblyAiSaveSettings(json_decode(" + json.dumps(json.dumps(priced)) + ", true));")
        stored = json.loads((root / "content/ai-settings.json").read_text())["pricing"]["imageModels"]
        assert stored == {"fal|fal-ai/nano-banana-2": {"perImage": 0.04, "currency": "USD"}}, stored
        generate()
        reservation = list(reservations().values())[-1]
        assert 3 < reservation["reservedCents"] < 4 and reservation["priced"] is True, "USD price converted to euro cents: %s" % reservation
        assert json.loads((root / "content/ai-usage.json").read_text())["reservations"][reservation["id"]]["priceBasis"]["source"] == "override"
        # no price at all: shown as unknown, not as free
        none = dict(save, pricing={"imageCentsPerRequest": 0, "imageModels": {"fal|fal-ai/nano-banana-2": None}})
        php("nibblyAiSaveSettings(json_decode(" + json.dumps(json.dumps(none)) + ", true));")
        generate()
        reservation = list(reservations().values())[-1]
        month = list(json.loads((root / "content/ai-usage.json").read_text())["months"].values())[-1]
        assert reservation["priced"] is False and reservation["reservedCents"] == 0 and month["unpricedImageRequests"] >= 1, (reservation, month)
        # every file the run wrote, except the secrets file, is free of keys
        leaks = [str(p.relative_to(root)) for p in root.rglob("*") if p.is_file() and p.suffix in (".json", ".jsonl") and p.name != "ai-secrets.json" and any(k.encode() in p.read_bytes() for k in (KEY_FAL, KEY_COMET, KEY_CUSTOM, KEY_CHAT))]
        assert not leaks, leaks
        removed = php("echo json_encode(nibblyAiClearSecret('image:fal'));")
        assert removed.strip() == "true" and "imageServices" not in json.loads((root / "content/ai-secrets.json").read_text()), "the key is removed, the chat key stays"
        # (the mock address is local, which would work without a key: switch that permission off for this check)
        stored_settings = json.loads((root / "content/ai-settings.json").read_text())
        write_json(root / "content/ai-settings.json", dict(stored_settings, allowLocalProvider=False))
        reason = php("echo nibblyAiImageUnavailableReason(nibblyAiLoadSettings(false));")
        assert reason == "key_missing", reason
        write_json(root / "content/ai-settings.json", stored_settings)
        assert "Unknown image service" in fails("nibblyAiClearSecret('image:nope');"), "unknown targets are refused"

        # ---- a stored address of an unused custom service does not block saving --------------------------------------------------------------
        configure(image={"provider": "fal", "model": "", "models": {}, "custom": {"baseUrl": "http://127.0.0.1:9/v1", "sizeMode": "size", "authStyle": "bearer", "extraParams": {}}})
        stale = {"enabled": True, "provider": "anthropic", "allowLocalProvider": False, "imageService": {"provider": "fal"}, "features": {"imageGeneration": True}}
        php("nibblyAiSaveSettings(json_decode(" + json.dumps(json.dumps(stale)) + ", true));")
        refused = fails("nibblyAiSaveSettings(json_decode(" + json.dumps(json.dumps(dict(stale, imageService={"provider": "custom"}))) + ", true));")
        assert "explicitly allowed" in refused, "choosing the custom service checks its address: " + refused

        # ---- key of the chat provider of the same name -------------------------------------------------------------------------------------
        configure(chat="openai", image={"provider": "openai-compatible", "model": "", "models": {}, "custom": {"baseUrl": "", "sizeMode": "size", "authStyle": "bearer", "extraParams": {}}}, keys={})
        status = json.loads(php("echo json_encode(nibblyAiImageServicePublic(nibblyAiLoadSettings(false)));"))
        assert status["services"]["openai-compatible"] == {"hasApiKey": False, "chatKeyAvailable": False, "keySource": "none"} and status["active"]["usable"] is False, "a chat key for another host is never used for OpenAI: %s" % status["active"]
        configure(chat="openai", chat_base="https://api.openai.com/v1", image={"provider": "openai-compatible", "model": "", "models": {}, "custom": {"baseUrl": "", "sizeMode": "size", "authStyle": "bearer", "extraParams": {}}}, keys={})
        status = json.loads(php("echo json_encode(nibblyAiImageServicePublic(nibblyAiLoadSettings(false)));"))
        assert status["services"]["openai-compatible"] == {"hasApiKey": False, "chatKeyAvailable": True, "keySource": "chat"} and status["active"]["usable"] is True and status["active"]["keySource"] == "chat", "the key of an OpenAI chat is used for OpenAI images: %s" % status["active"]
        # the chat provider's own images (service "inherit") behave as before; Anthropic has none
        configure(chat="openai", image={"provider": "inherit", "model": "", "models": {}, "custom": {"baseUrl": "", "sizeMode": "size", "authStyle": "bearer", "extraParams": {}}}, keys={})
        assert php("echo json_encode(nibblyAiImageServiceUsable(nibblyAiLoadSettings(false)));") == "true"
        configure(chat="anthropic", image={"provider": "inherit", "model": "", "models": {}, "custom": {"baseUrl": "", "sizeMode": "size", "authStyle": "bearer", "extraParams": {}}}, keys={})
        assert php("echo json_encode(nibblyAiImageServiceUsable(nibblyAiLoadSettings(false)));") == "false"
        assert "no image API" in fails("nibblyAiGenerateImage('x');")
        # Anthropic chat, no image service set up: the agent has no image tool; with fal it does
        tools = "echo json_encode(array_column(nibblyAgentToolsForUser(), 'name'));"
        prefix = "define('SITE_LANG_DEFAULT','en'); $_SESSION=['admin_role'=>'admin','admin_username'=>'admin']; require 'includes/ai/agent/run.php'; "
        names = json.loads(subprocess.check_output(["php", "-d", "display_errors=0", "-r", prefix + tools], cwd=root, text=True))
        assert "generate_image" not in names, names
        configure()
        names = json.loads(subprocess.check_output(["php", "-d", "display_errors=0", "-r", prefix + tools], cwd=root, text=True))
        assert "generate_image" in names, "an Anthropic chat with a fal.ai image service gives the agent its image tool"

        # ---- price by resolution and quality --------------------------------------------------------------------------------------------
        configure(extra={"pricing": {"imageCentsPerRequest": 5, "usdPerEur": 0, "imageModels": {"fal|fal-ai/nano-banana-2": {"currency": "EUR", "byResolution": {"1K": 0.02, "4K": 0.2}}}, "models": {}}})
        generate(imageScale=3840)
        reservation = list(reservations().values())[-1]
        assert reservation["reservedCents"] == 20.0 and reservation["priceBasis"]["basis"] == "resolution:4K" and reservation["priceBasis"]["source"] == "override", reservation
        generate(imageScale=1024, count=2)
        reservation = list(reservations().values())[-1]
        assert reservation["reservedCents"] == 4.0 and reservation["priceBasis"]["count"] == 2, reservation
        generate(imageScale=2048)
        reservation = list(reservations().values())[-1]
        assert reservation["reservedCents"] == 5.0 and reservation["priceBasis"]["source"] == "default", "2K has no price: the default price per image applies: %s" % reservation

        # ---- live model lists ----------------------------------------------------------------------------------------------------------
        configure()
        list_log = lambda: [e for e in log if e[0] == "GET" and (e[1].startswith("/v1/models") or e[1] == "/api/models")]
        (root / "content/ai-image-models-cache.json").unlink(missing_ok=True)
        listed = json.loads(php("echo json_encode(nibblyAiImageLiveModels(nibblyAiLoadSettings(false), 'fal'));"))
        assert [m["id"] for m in listed["models"]] == ["fal-ai/flux/dev", "fal-ai/nano-banana-2", "fal-ai/second"] and not listed["cached"], listed
        assert listed["models"][2]["label"] == "fal.ai: Second model", "tags are stripped from names"
        requests_made = list_log()
        assert len(requests_made) == 2 and "category=text-to-image" in requests_made[0][1] and "status=active" in requests_made[0][1] and "cursor=c2" in requests_made[1][1], [e[1] for e in requests_made]
        assert {k.lower(): v for k, v in requests_made[0][2].items()}.get("authorization") == "Key " + KEY_FAL, "the saved key raises the rate limit"
        again = json.loads(php("echo json_encode(nibblyAiImageLiveModels(nibblyAiLoadSettings(false), 'fal'));"))
        assert again["cached"] and len(list_log()) == 2, "a day of cache: no new request"
        assert KEY_FAL not in (root / "content/ai-image-models-cache.json").read_text(), "the cache holds no key"
        state["list_fail"] = True
        stale = json.loads(php("echo json_encode(nibblyAiImageLiveModels(nibblyAiLoadSettings(false), 'fal', true));"))
        assert stale["cached"] and len(stale["models"]) == 3, "a service that does not answer: the cached list is served"
        (root / "content/ai-image-models-cache.json").unlink()
        assert "could not be loaded" in fails("nibblyAiImageLiveModels(nibblyAiLoadSettings(false), 'fal');"), "no cache, no answer: an error"
        state["list_fail"] = False
        state["list_reject_key"] = True
        before = len(list_log())
        listed = json.loads(php("echo json_encode(nibblyAiImageLiveModels(nibblyAiLoadSettings(false), 'fal', true));"))
        assert len(listed["models"]) == 3 and len(list_log()) > before + 1, "a refused key does not take the list away (retried without it)"
        state["list_reject_key"] = False
        comet = json.loads(php("echo json_encode(nibblyAiImageLiveModels(nibblyAiLoadSettings(false), 'comet'));"))
        assert [m["id"] for m in comet["models"]] == ["gpt-image-2"], "only image models with a valid id: %s" % comet
        assert "no model list" in fails("nibblyAiImageLiveModels(nibblyAiLoadSettings(false), 'kie');")

        # ---- the agent over HTTP: OpenAI chat, fal.ai images ------------------------------------------------------------------------------------
        configure(chat="openai")
        site.start()
        owner = Client(site).login("admin")

        def api(action, **fields):
            return owner.api(action, **fields)

        chat_queue[:] = [tool_calls("generate_image", {"prompt": "A friendly robot waving", "aspect": "16:9"}, "c1")]

        def stage(body):
            refs = [json.loads(m["content"]).get("mediaRef") for m in body["messages"] if m["role"] == "tool" and m["content"].startswith("{")]
            ref = next(r for r in refs if r)
            return tool_calls("stage_create_page", {"lang": "en", "slug": "robot-page", "title": "Robots", "sections": [{"type": "image", "fields": {"src": ref, "alt": "A friendly robot waving"}}]}, "c2")

        chat_queue.extend([stage, tool_calls("validate_changeset", {}, "c3"), tool_calls("present_changeset", {"summary": "A robot page."}, "c4")])
        submits = state["submits"]
        status, payload = api("ai-agent-start", goal="Make a robot page with a generated image")
        assert payload["success"], payload
        run_id = payload["data"]["runId"]
        states = []
        for _ in range(60):
            status, payload = api("ai-agent-step", runId=run_id)
            assert payload["success"], payload
            states.append(payload["data"]["status"])
            if payload["data"]["status"] not in ("running", "busy", "waiting_jobs"):
                break
        assert payload["data"]["status"] == "awaiting_review", states
        assert state["submits"] == submits + 1 and last("POST", "/fal-ai/nano-banana-2")[3]["aspect_ratio"] == "16:9", "the agent's image came from the image service"
        changeset = next((root / "content/ai-agent/changesets").glob("*.json"))
        media = json.loads(changeset.read_text())["media"]
        assert [m["source"] for m in media.values()] == ["generated"] and next(iter(media.values()))["ai"] == "generated", media
        assert list((root / "content/ai-agent/staging").rglob("*.webp")), "the generated image waits in the staging folder"
        assert KEY_FAL.encode() not in b"".join(p.read_bytes() for p in (root / "content/ai-image-jobs").glob("*.json")), "no key in the job files"
        assert KEY_FAL not in json.dumps(chat_requests), "no key reaches the model"
        assert "references" in [t["function"]["parameters"]["properties"] for t in chat_requests[0]["tools"] if t["function"]["name"] == "generate_image"][0], "the tool offers references"

        # the model list over HTTP: admins only
        status, payload = api("ai-image-models", service="comet")
        assert payload["success"] and [m["id"] for m in payload["data"]["models"]] == ["gpt-image-2"], payload
        assert api("ai-image-models", service="kie")[1]["success"] is False
        editor = Client(site).login("editor")
        assert editor.api("ai-image-models", service="comet")[1]["success"] is False, "editors cannot reach it"

        # reference images: a mediaRef of this run and a library path go to the edit endpoint; the result is "AI-modified"
        def first_ref(body):
            return next(json.loads(m["content"])["mediaRef"] for m in body["messages"] if m["role"] == "tool" and m["content"].startswith("{") and "mediaRef" in m["content"])

        edits_before = len([e for e in log if e[0] == "POST" and e[1].endswith("/edit")])
        chat_requests.clear()
        chat_queue[:] = [
            tool_calls("generate_image", {"prompt": "A robot", "aspect": "1:1"}, "g1"),
            lambda body: tool_calls("generate_image", {"prompt": "The same robot in watercolor", "aspect": "4:3", "references": [first_ref(body), "/assets/images/placeholder-image.webp"]}, "g2"),
            tool_calls("present_changeset", {"summary": "Two images."}, "g3"),
        ]
        status, payload = api("ai-agent-start", goal="Make a robot and then a watercolor version of it")
        assert payload["success"], payload
        run_two = payload["data"]["runId"]
        for _ in range(80):
            status, payload = api("ai-agent-step", runId=run_two)
            assert payload["success"], payload
            if payload["data"]["status"] not in ("running", "busy", "waiting_jobs"):
                break
        edit_calls = [e for e in log if e[0] == "POST" and e[1].endswith("/edit")]
        assert len(edit_calls) == edits_before + 1, "the second image went to the edit endpoint"
        urls = edit_calls[-1][3]["image_urls"]
        assert len(urls) == 2 and all(u.startswith("data:image/") for u in urls), "the staged image of the run and the library image are both sent"
        assert edit_calls[-1][3]["aspect_ratio"] == "4:3", edit_calls[-1][3]
        changeset = sorted((root / "content/ai-agent/changesets").glob("*.json"), key=lambda f: f.stat().st_mtime)[-1]
        media = json.loads(changeset.read_text())["media"]
        assert sorted(m["ai"] for m in media.values()) == ["generated", "modified"], media

        # wrong references fail the call, not the job; a service without an edit endpoint says so
        chat_queue[:] = [tool_calls("generate_image", {"prompt": "x", "references": ["m_00000000"]}, "e1"), say("ok")]
        status, payload = api("ai-agent-start", goal="Reference that does not exist")
        run_three = payload["data"]["runId"]
        for _ in range(20):
            status, payload = api("ai-agent-step", runId=run_three)
            if payload["data"]["status"] not in ("running", "busy", "waiting_jobs"):
                break
        assert "Unknown reference image" in json.dumps(chat_requests[-1]["messages"]), "the tool error reaches the model"
        configure(chat="openai", image={"provider": "fal", "model": "fal-ai/flux/schnell", "models": {}, "custom": {"baseUrl": "", "sizeMode": "size", "authStyle": "bearer", "extraParams": {}}})
        chat_queue[:] = [tool_calls("generate_image", {"prompt": "x", "references": ["/assets/images/placeholder-image.webp"]}, "e2"), say("ok")]
        status, payload = api("ai-agent-start", goal="Reference with a service that cannot")
        run_four = payload["data"]["runId"]
        for _ in range(20):
            status, payload = api("ai-agent-step", runId=run_four)
            if payload["data"]["status"] not in ("running", "busy", "waiting_jobs"):
                break
        assert "cannot use reference images" in json.dumps(chat_requests[-1]["messages"]), "an image service without references says so"
    finally:
        mock.shutdown()
        internal.shutdown()
        if site.process:
            site.close()
print("PASS image services: fal.ai queue (aspect, count, presets, references, resume, errors), Comet Gemini route, custom OpenAI-compatible, keys, prices, SSRF, Anthropic chat with an image service, agent image")
