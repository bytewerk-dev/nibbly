#!/usr/bin/env python3
"""Media upload hardening: reject executable extensions, sanitise SVG, keep real media working."""
import json
import tempfile
import uuid

from support import Client, Site


def multipart(fields, files):
    boundary = uuid.uuid4().hex
    body = b""
    for key, value in fields.items():
        body += (f"--{boundary}\r\nContent-Disposition: form-data; name=\"{key}\"\r\n\r\n{value}\r\n").encode()
    for key, (name, data, ctype) in files.items():
        body += (f"--{boundary}\r\nContent-Disposition: form-data; name=\"{key}\"; filename=\"{name}\"\r\n"
                 f"Content-Type: {ctype}\r\n\r\n").encode() + data + b"\r\n"
    body += f"--{boundary}--\r\n".encode()
    return body, f"multipart/form-data; boundary={boundary}"


# Real magic bytes so finfo reports an allowed MIME type; a naive check would pass these.
JPEG = bytes.fromhex("ffd8ffe000104a46494600010100000100010000") + b"<?php echo 'PWNED'; ?>"
MP3 = b"ID3\x03\x00\x00\x00\x00\x00\x00" + b"\xff\xfb\x90\x00" * 40 + b"<?php echo 'PWNED'; ?>"
PNG = bytes.fromhex("89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c489"
                    "0000000a49444154789c6360000000020001") + bytes.fromhex("00000000")
SVG_XSS = b'<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(document.domain)</script><rect width="10" height="10"/></svg>'


def upload(client, action, field, name, data, extra=None):
    fields = {"action": action, "csrf_token": client.csrf, "type": "image"}
    fields.update(extra or {})
    body, ctype = multipart(fields, {field: (name, data, "application/octet-stream")})
    status, _, raw = client.request("/admin/api.php", body, "POST", {"Content-Type": ctype})
    return status, json.loads(raw)


def test_upload_hardening(site):
    editor = Client(site).login("editor")

    # 1. Polyglot .php uploads must be refused, even with valid image/audio magic bytes.
    for action, field, name, data in (("upload-image", "image", "shell.php", JPEG),
                                      ("upload-audio", "audio", "shell.php", MP3),
                                      ("upload-media", "image", "shell.php", JPEG)):
        _, result = upload(editor, action, field, name, data)
        assert not result["success"], f"{action} accepted an executable extension: {result}"

    # No .php file may have reached a web-served directory.
    for folder in ("assets/images", "assets/audio"):
        leftovers = list((site.root / folder).rglob("*.php"))
        assert not leftovers, f"Executable file written to {folder}: {leftovers}"

    # 2. A legitimate PNG still uploads (we did not over-block).
    _, result = upload(editor, "upload-image", "image", "photo.png", PNG)
    assert result["success"], f"Legitimate PNG rejected: {result}"

    # 3. An SVG uploads but is sanitised: no script/onload survive on disk or over HTTP.
    _, result = upload(editor, "upload-media", "image", "logo.svg", SVG_XSS)
    assert result["success"], f"SVG upload failed: {result}"
    stored = (site.root / "assets/images" / result["data"]["name"]).read_text()
    assert "<script" not in stored.lower() and "onload" not in stored.lower(), stored
    assert "<rect" in stored.lower(), "SVG drawing content was lost during sanitisation"
    status, _, body = Client(site).request(result["data"]["path"].replace("../", "/"))
    assert status == 200 and b"<script" not in body.lower() and b"onload" not in body.lower(), body[:200]

    print("PASS upload hardening: .php refused, no code in assets, SVG sanitised, PNG accepted")


if __name__ == "__main__":
    with tempfile.TemporaryDirectory(prefix="nibbly-upload-smoke-") as folder:
        site = Site(folder).start()
        try:
            test_upload_hardening(site)
        finally:
            site.close()
