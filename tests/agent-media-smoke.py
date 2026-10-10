#!/usr/bin/env python3
"""Images in agent runs over HTTP: upload, stock photos (Pexels mock), generated images, review, preview, apply, undo."""
import base64
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
from threading import Thread
import time
import urllib.parse
from support import Client, Site, write_json

PEXELS_KEY = "pexels-test-key"
requests = []        # chat requests
image_requests = []  # image generation requests
pexels_requests = []
queue = []
pexels_mode = {"mode": "ok"}


def php_bytes(code):
    return subprocess.check_output(["php", "-r", code])


JPEG = php_bytes("$i=imagecreatetruecolor(400,300); imagefill($i,0,0,imagecolorallocate($i,40,130,200)); imagefilledrectangle($i,0,0,150,100,imagecolorallocate($i,250,240,30)); ob_start(); imagejpeg($i,null,90); echo ob_get_clean();")
PNG = php_bytes("$i=imagecreatetruecolor(512,512); imagefill($i,0,0,imagecolorallocate($i,200,60,60)); ob_start(); imagepng($i); echo ob_get_clean();")


def with_segments(jpeg, segments):
    out = b""
    for marker, payload in segments:
        out += b"\xff" + bytes([marker]) + (len(payload) + 2).to_bytes(2, "big") + payload
    return jpeg[:2] + out + jpeg[2:]


AI_PHOTO = with_segments(JPEG, [(0xE1, b"http://ns.adobe.com/xap/1.0/\0<x:xmpmeta Iptc4xmpExt:DigitalSourceType=\"http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia\"/>"),
                                 (0xFE, b"SECRET-GPS-48.2N <?php evil(); ?>")])


class Provider(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        if self.path == "/images/generations":
            image_requests.append(body)
            payload = {"data": [{"b64_json": base64.b64encode(PNG).decode()}]}
        else:
            requests.append(body)
            answer = queue.pop(0) if queue else {"choices": [{"message": {"role": "assistant", "content": "unscripted"}, "finish_reason": "stop"}], "usage": {"prompt_tokens": 1, "completion_tokens": 1}}
            payload = answer(body) if callable(answer) else answer
        data = json.dumps(payload).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)


class Pexels(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def reply(self, status, body, content_type, extra=None):
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        for k, v in (extra or {}).items():
            self.send_header(k, v)
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        url = urllib.parse.urlparse(self.path)
        pexels_requests.append((url.path, urllib.parse.parse_qs(url.query), self.headers.get("Authorization"), self.headers.get("Host")))
        port = self.server.server_port
        if url.path == "/v1/search":
            if self.headers.get("Authorization") != PEXELS_KEY or pexels_mode["mode"] == "denied":
                return self.reply(401, b'{"error":"Invalid key"}', "application/json")
            photos = [{"id": 111, "width": 4000, "height": 3000, "url": "https://www.pexels.com/photo/111/", "photographer": "Jane <b>Doe</b>", "photographer_url": "https://www.pexels.com/@jane",
                       "alt": "A mountain lake at sunrise", "src": {"large2x": f"http://images.pexels.com:{port}/img/{pexels_mode['image']}", "medium": f"http://images.pexels.com:{port}/img/medium.jpg"}}]
            return self.reply(200, json.dumps({"photos": photos}).encode(), "application/json")
        if url.path == "/img/photo.jpg":
            return self.reply(200, with_segments(JPEG, [(0xFE, b"PHOTOGRAPHER-GPS")]), "image/jpeg")
        if url.path == "/img/redirect.jpg":
            return self.reply(302, b"", "text/plain", {"Location": f"http://evil.test:{port}/img/photo.jpg"})
        if url.path == "/img/notimage.jpg":
            return self.reply(200, b"%PDF-1.4", "application/pdf")
        self.reply(404, b"nope", "text/plain")


def multipart(fields, files):
    boundary = "----nibblybound" + str(int(time.time() * 1000))
    body = b""
    for name, value in fields.items():
        body += f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode()
    for name, (filename, content, mime) in files.items():
        body += f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"; filename="{filename}"\r\nContent-Type: {mime}\r\n\r\n'.encode() + content + b"\r\n"
    body += f"--{boundary}--\r\n".encode()
    return body, {"Content-Type": f"multipart/form-data; boundary={boundary}"}


def call(name, arguments, call_id):
    return {"id": call_id, "type": "function", "function": {"name": name, "arguments": json.dumps(arguments)}}


def turn(*calls):
    return {"choices": [{"message": {"role": "assistant", "content": None, "tool_calls": list(calls)}, "finish_reason": "tool_calls"}], "usage": {"prompt_tokens": 120, "completion_tokens": 30}}


def say(text):
    return {"choices": [{"message": {"role": "assistant", "content": text}, "finish_reason": "stop"}], "usage": {"prompt_tokens": 120, "completion_tokens": 30}}


def tool_results(body):
    """JSON payloads of all tool messages the model has seen so far."""
    out = []
    for message in body["messages"]:
        if message["role"] == "tool":
            try:
                out.append(json.loads(message["content"]))
            except ValueError:
                pass
    return out


with tempfile.TemporaryDirectory(prefix="nibbly-agent-media-") as folder:
    chat = ThreadingHTTPServer(("127.0.0.1", 0), Provider)
    pex = ThreadingHTTPServer(("127.0.0.1", 0), Pexels)
    Thread(target=chat.serve_forever, daemon=True).start()
    Thread(target=pex.serve_forever, daemon=True).start()
    pexels_mode["image"] = "photo.jpg"
    os.environ["PHP_CLI_SERVER_WORKERS"] = "8"
    os.environ["NIBBLY_AGENT_TEST_NET"] = json.dumps({"apiBase": f"http://api.pexels.com:{pex.server_port}", "ports": [pex.server_port],
                                                      "resolve": {"api.pexels.com": ["93.184.216.34"], "images.pexels.com": ["93.184.216.34"], "evil.test": ["93.184.216.35"]},
                                                      "connectMap": {"93.184.216.34": "127.0.0.1", "93.184.216.35": "127.0.0.1"}})
    site = Site(folder)
    root = site.root
    editor = next(u for u in site.users if u["role"] == "editor")
    site.users.append({**editor, "id": "u_editor2", "username": "editor2", "email": "editor2@example.invalid"})
    write_json(root / "content/users.json", {"users": site.users})
    write_json(root / "content/settings.json", {"modules": {"ai": True}, "privacy": {"analyticsEnabled": False}})
    site.start()

    def php(code):
        result = subprocess.run(["php", "-d", "display_errors=0", "-r", "require 'includes/ai/ai-helper.php'; " + code], cwd=root, text=True, capture_output=True)
        assert result.returncode == 0, result.stdout + result.stderr
        return result.stdout

    base_settings = json.loads(php("echo json_encode(nibblyAiDefaults());"))

    def configure(agent=None, stock=True):
        settings = json.loads(json.dumps(base_settings))
        settings.update(enabled=True, provider="openai-compatible", allowLocalProvider=True, chatModel="gpt-4.1-mini", baseUrl=f"http://127.0.0.1:{chat.server_port}", apiKey="")
        settings["providerCredentials"]["openai-compatible"]["baseUrl"] = settings["baseUrl"]
        settings["limits"].update(dailyRequests=1000, dailyTextRequests=1000, dailyImageRequests=100, monthlyBudgetCents=100000, maxInputTokens=60000)
        agent = agent or {}
        settings["agent"] = {"enabled": True, **agent, "limits": {"runsPerHour": 100, "stepsPerMinute": 600, **agent.get("limits", {})}}
        if stock:
            settings["stock"] = {"pexels": {"apiKey": PEXELS_KEY}}
        write_json(root / "content/ai-settings.json", settings)

    configure()
    owner = Client(site).login("editor")
    other = Client(site).login("editor2")
    admin = Client(site).login("admin")
    visitor = Client(site)

    def api(client, action, **fields):
        return client.api(action, **fields)

    def upload(client, name, content, mime="image/jpeg", **fields):
        body, headers = multipart({"csrf_token": client.csrf, **fields}, {"image": (name, content, mime)})
        status, _, raw = client.request("/admin/api.php?action=ai-agent-upload", body, headers=headers)
        return status, json.loads(raw)

    def run_to_end(client, run_id, max_steps=60):
        states = []
        for _ in range(max_steps):
            status, payload = api(client, "ai-agent-step", runId=run_id)
            assert status == 200 and payload["success"], payload
            state = payload["data"]
            states.append(state["status"])
            if state["status"] not in ("running", "busy", "waiting_jobs"):
                return state, states
        raise AssertionError("run did not finish: " + json.dumps(states))

    try:
        # ---- Upload ------------------------------------------------------------------------------------------------
        status, payload = upload(owner, "Office Photo.jpg", AI_PHOTO)
        assert status == 200 and payload["success"], payload
        cs_id = payload["data"]["changesetId"]
        up = payload["data"]["media"]
        assert up["source"] == "upload" and up["ai"] == "generated" and up["width"] == 400 and up["name"] == "Office Photo.jpg", up
        assert "stagingFile" not in json.dumps(payload) and "finalPath" not in json.dumps(payload), "no storage paths for the browser"
        staging = root / "content/ai-agent/staging" / cs_id
        files = sorted(p.name for p in staging.iterdir())
        assert len(files) == 2 and files[1].endswith(".webp") or files[0].endswith(".webp"), files
        webp = next(staging.glob("*.webp")).read_bytes()
        assert webp[8:12] == b"WEBP" and b"SECRET-GPS" not in webp and b"<?php" not in webp and b"xmpmeta" not in webp, "re-encoded without metadata or hidden code"
        assert not list((root / "assets/images").glob("**/office-photo*")), "nothing reaches the library before the apply"
        assert upload(owner, "x.jpg", b"<?php echo 1;", "image/jpeg", changesetId=cs_id)[1]["success"] is False, "PHP code named .jpg"
        assert upload(owner, "x.svg", b"<svg xmlns='http://www.w3.org/2000/svg'/>", "image/svg+xml")[1]["success"] is False, "SVG"
        assert upload(owner, "x.jpg", b"\xff\xd8" + b"0" * (6 * 1024 * 1024), "image/jpeg")[1]["success"] is False, "above the media size limit"
        assert upload(other, "x.jpg", JPEG, changesetId=cs_id)[1]["message"] == "Unknown change set", "another user cannot add to it"
        # thumbnails: owner and admins only; the staging folder itself is not public
        url = f"/admin/api.php?action=ai-agent-media&id={cs_id}&ref={up['mediaRef']}"
        status, headers, body = owner.request(url)
        assert status == 200 and headers.get("Content-Type") == "image/webp" and headers.get("X-Content-Type-Options") == "nosniff" and body[8:12] == b"WEBP", (status, headers)
        assert other.request(url)[0] == 404 and visitor.request(url)[0] == 401 and admin.request(url)[0] == 200
        assert owner.request(f"/admin/api.php?action=ai-agent-media&id={cs_id}&ref=m_00000000")[0] == 404
        for path in [f"/content/ai-agent/staging/{cs_id}/{next(staging.glob('*.webp')).name}", f"/content/ai-agent/changesets/{cs_id}.json", "/content/ai-agent/.secret"]:
            assert owner.request(path)[0] != 200 and visitor.request(path)[0] != 200, "content/ai-agent is not reachable over HTTP: " + path

        # ---- A run with stock photo, generated image and the upload -------------------------------------------------------
        def second_turn(body):  # import the first search result
            sid = tool_results(body)[-1]["results"][0]["sid"]
            return turn(call("import_stock_image", {"sid": sid}, "c2"))

        def stage_turn(body):
            refs = [r["mediaRef"] for r in tool_results(body) if isinstance(r, dict) and "mediaRef" in r]
            assert len(refs) == 2, refs
            stock_ref, generated_ref = refs
            return turn(call("stage_create_news", {"lang": "en", "title": "Lake news", "date": "2026-10-10", "excerpt": "Short", "contentHtml": "<p>News body</p>", "imageRef": stock_ref}, "c4"),
                        call("stage_create_page", {"lang": "en", "slug": "robot-page", "title": "Robots", "sections": [
                            {"type": "image", "fields": {"src": generated_ref, "alt": "A friendly robot waving"}},
                            {"type": "card", "fields": {"title": "Team", "image": up["mediaRef"]}}]}, "c5"))

        queue[:] = [turn(call("list_run_media", {}, "c0")),
                    turn(call("search_stock_images", {"query": "mountain lake", "orientation": "landscape"}, "c1")), second_turn,
                    turn(call("generate_image", {"prompt": "A friendly robot waving", "aspect": "3:2"}, "c3")),
                    stage_turn, turn(call("validate_changeset", {}, "c6")), turn(call("present_changeset", {"summary": "News and a robot page."}, "c7"))]
        status, payload = api(owner, "ai-agent-start", goal="Write news about the lake with a stock photo and a robot page. Use my office photo too.", changesetId=cs_id)
        assert payload["success"], payload
        run = payload["data"]["runId"]
        assert payload["data"]["changesetId"] == cs_id, "the run takes over the change set with the uploaded image"
        state, states = run_to_end(owner, run)
        assert state["status"] == "awaiting_review", state
        assert "waiting_jobs" in states, "the image job is waited for: " + json.dumps(states)
        first_message = requests[0]["messages"][-1]["content"]
        assert "[Attached images: " + up["mediaRef"] in first_message and "Office Photo.jpg" in first_message, first_message
        assert "SECRET" not in json.dumps(requests), "no secret from image metadata reaches the model"
        # Pexels was called properly
        search = [r for r in pexels_requests if r[0] == "/v1/search"][0]
        assert search[1]["query"] == ["mountain lake"] and search[1]["orientation"] == ["landscape"] and search[2] == PEXELS_KEY and search[3].startswith("api.pexels.com"), search
        assert [r for r in pexels_requests if r[0] == "/img/photo.jpg"][0][3].startswith("images.pexels.com"), "the image comes from the image host"
        # The model never got URLs, only result ids
        assert "pexels.com" not in json.dumps(requests) and "image.pexels" not in json.dumps(requests), "no stock URLs reach the model"
        assert "Jane" in json.dumps(requests[2]["messages"]) and "<b>" not in json.dumps(requests[2]["messages"]), "photographer names are shown, tags stripped"
        # The image request went to the provider, the file to the staging folder, nothing into history or library
        assert len(image_requests) == 1 and "friendly robot" in image_requests[0]["prompt"], image_requests
        history = root / "content/ai-image-history.json"
        assert not history.exists() or "ai-agent" not in history.read_text(), "history stays free of staged images"
        assert not list((root / "assets/images/generated").glob("*")), "generated images wait in the staging folder"
        stored = json.loads((root / "content/ai-agent/changesets" / f"{cs_id}.json").read_text())
        assert sorted(m["source"] for m in stored["media"].values()) == ["generated", "stock", "upload"], stored["media"]
        stock = next(m for m in stored["media"].values() if m["source"] == "stock")
        assert stock["credit"]["author"] == "Jane Doe" and stock["credit"]["license"] == "Pexels License" and stock["credit"]["attribution"] == "Photo by Jane Doe on Pexels", stock["credit"]
        assert len(list(staging.glob("*.webp"))) == 3, sorted(p.name for p in staging.iterdir())

        # ---- Review ----------------------------------------------------------------------------------------------------
        status, payload = owner.request(f"/admin/api.php?action=ai-agent-changeset&id={cs_id}")[0], json.loads(owner.request(f"/admin/api.php?action=ai-agent-changeset&id={cs_id}")[2])
        review = payload["data"]
        assert len(review["media"]) == 3 and "stagingFile" not in json.dumps(review) and "content/ai-agent" not in json.dumps(review), "review lists the images without staging paths"
        for ref in review["media"]:
            assert owner.request(f"/admin/api.php?action=ai-agent-media&id={cs_id}&ref={ref}")[0] == 200, "every staged image has a thumbnail"
        # Preview of the real page shows the staged images through the review endpoint
        status, headers, body = owner.request(f"/robot-page?nb_agent_preview={cs_id}")
        text = body.decode("utf-8", "replace")
        assert status == 200 and "ai-agent-media&amp;id=" + cs_id in text and "/assets/images/generated/" not in text and "/assets/images/agent/" not in text, "preview serves staged images: %s" % status
        assert visitor.request(f"/robot-page?nb_agent_preview={cs_id}")[0] == 404

        # ---- Apply: used images move into the library ---------------------------------------------------------------------------
        status, payload = api(owner, "ai-agent-apply", id=cs_id, previewHash=review["previewHash"], confirmed="1")
        assert status == 200 and payload["success"], payload
        meta = json.loads((root / "content/media-meta.json").read_text())["image"]
        by_folder = {}
        for name, entry in meta.items():
            by_folder.setdefault(name.split("/")[0], {})[name] = entry
        stock_names = by_folder.get("agent", {})
        credit_entry = next(e for e in stock_names.values() if "credit" in e)
        assert credit_entry["credit"]["author"] == "Jane Doe" and credit_entry["credit"]["source"] == "pexels" and credit_entry["credit"]["license"] == "Pexels License" and "importedAt" in credit_entry["credit"], credit_entry
        generated = by_folder.get("generated", {})
        assert len(generated) == 1 and next(iter(generated.values()))["ai"] == "generated", "the generated image gets the AI label when the package is applied"
        assert any(e.get("ai") == "generated" for n, e in stock_names.items() if "credit" not in e), "the upload carried its AI provenance into the library label"
        for name in list(stock_names) + list(generated):
            assert (root / "assets/images" / name).is_file(), name
        assert not (root / "content/ai-agent/staging" / cs_id).exists(), "the staging folder is gone"
        news = json.loads(next((root / "content/news").glob("*lake-news*.json")).read_text())
        assert news["hidden"] is True and news["content"].endswith("<p><em>Photo: Jane Doe / Pexels</em></p>") and news["image"].startswith("/assets/images/agent/"), news
        page = json.loads((root / "content/pages/en_robot-page.json").read_text())
        assert page["sections"][0]["src"].startswith("/assets/images/generated/") and page["sections"][1]["image"].startswith("/assets/images/agent/") and page["visibility"]["status"] == "private", page
        undo = json.loads(owner.request("/admin/api.php", {"action": "ai-agent-undo", "csrf_token": owner.csrf, "id": cs_id, "token": payload["data"]["undoToken"]})[2])
        assert undo["success"] and not list((root / "assets/images/generated").glob("*")) and list((root / "assets/images-trash").rglob("*.webp")), "undo takes the images out of the library again"

        # ---- Stock photo failures ----------------------------------------------------------------------------------------------
        def stock_run(image):
            pexels_mode["image"] = image
            queue[:] = [turn(call("search_stock_images", {"query": "x"}, "f1")),
                        lambda body: turn(call("import_stock_image", {"sid": tool_results(body)[-1]["results"][0]["sid"]}, "f2")), say("done")]
            r = api(owner, "ai-agent-start", goal="Fetch a stock photo")[1]["data"]["runId"]
            run_to_end(owner, r)
            return json.dumps(requests[-1]["messages"])

        assert "could not be downloaded" in stock_run("redirect.jpg"), "a redirect to another host is refused"
        assert "could not be downloaded" in stock_run("notimage.jpg"), "a download that is not an image is refused"
        pexels_mode["mode"] = "denied"
        queue[:] = [turn(call("search_stock_images", {"query": "x"}, "f3")), say("done")]
        run_to_end(owner, api(owner, "ai-agent-start", goal="Denied")[1]["data"]["runId"])
        assert "refused the API key" in json.dumps(requests[-1]["messages"]) and PEXELS_KEY not in json.dumps(requests), "the key error is explained; the key itself never reaches the model"
        pexels_mode["mode"] = "ok"
        pexels_mode["image"] = "photo.jpg"

        # ---- Limits and rights ---------------------------------------------------------------------------------------------------
        queue[:] = [turn(call("generate_image", {"prompt": f"robot {i}"}, f"g{i}")) for i in range(3)] + [say("done")]
        r = api(owner, "ai-agent-start", goal="Three images")[1]["data"]["runId"]
        run_to_end(owner, r)
        assert "image generation limit of this run" in json.dumps(requests[-1]["messages"]), "at most two generated images per run"
        configure(agent={"roles": {"editor": {"generateImages": False, "stockImages": False, "uploadImages": False}}})
        queue[:] = [say("ok")]
        run_to_end(owner, api(owner, "ai-agent-start", goal="Rights")[1]["data"]["runId"])
        names = [t["function"]["name"] for t in requests[-1]["tools"]]
        assert not [n for n in names if n in ("generate_image", "search_stock_images", "import_stock_image")], names
        status, payload = upload(owner, "a.jpg", JPEG)
        assert status == 403 and not payload["success"], "without the right there is no upload"
        queue[:] = [say("ok")]
        run_to_end(admin, api(admin, "ai-agent-start", goal="Admin")[1]["data"]["runId"])
        assert "generate_image" in [t["function"]["name"] for t in requests[-1]["tools"]], "admins keep every tool"
        configure(stock=False)
        queue[:] = [say("ok")]
        run_to_end(owner, api(owner, "ai-agent-start", goal="No stock key")[1]["data"]["runId"])
        assert "search_stock_images" not in [t["function"]["name"] for t in requests[-1]["tools"]], "no key, no stock tools"
        configure(agent={"limits": {"uploadsPer10Minutes": 2}})
        results = [upload(admin, "r.jpg", JPEG)[0] for _ in range(3)]
        assert results == [200, 200, 429], results
        print("PASS agent media: upload and thumbnails, metadata removed, staging not public, stock photos (key, host allowlist, redirects), generated images, review and preview, credits and AI labels on apply, undo, limits and rights")
    finally:
        site.close()
        chat.shutdown()
        pex.shutdown()
