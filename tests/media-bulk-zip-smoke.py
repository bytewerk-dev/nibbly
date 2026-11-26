#!/usr/bin/env python3
"""Media library batch actions through the real API: bulk move/trash/restore/purge,
ZIP download, and the checks of ZIP import (paths, links, types, content, bombs)."""
import io
import json
import shutil
import tempfile
import uuid
import zipfile

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


def unzip(client, name, data, **fields):
    body, ctype = multipart({"action": "unzip-media", "csrf_token": client.csrf, **fields},
                            {"file": (name, data, "application/zip")})
    status, _, raw = client.request("/admin/api.php", body, "POST", {"Content-Type": ctype})
    return status, json.loads(raw)


def items(*pairs):
    return json.dumps([{"type": t, "name": n} for t, n in pairs])


def build_zip(entries):
    """entries: (name, bytes) or (name, bytes, unix_mode)."""
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, "w", zipfile.ZIP_DEFLATED) as archive:
        for entry in entries:
            info = zipfile.ZipInfo(entry[0], date_time=(2026, 11, 26, 12, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            if len(entry) > 2:
                info.create_system = 3
                info.external_attr = entry[2] << 16
            archive.writestr(info, entry[1])
    return buffer.getvalue()


SVG_XSS = b'<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script><rect width="4" height="4"/></svg>'

with tempfile.TemporaryDirectory(prefix="nibbly-media-bulk-") as folder:
    site = Site(folder).start()
    try:
        root = site.root
        images = root / "assets/images"
        svg = (images / "favicon.svg").read_bytes()
        webp = (images / "placeholder-image.webp").read_bytes()
        (images / "a.svg").write_bytes(svg)
        (images / "b.svg").write_bytes(svg)
        (images / "sub").mkdir()
        (images / "sub/c.svg").write_bytes(svg)
        (root / "assets/documents/readme.txt").write_text("Hello media library\n")
        admin = Client(site).login("admin")
        listed = json.loads(admin.request("/admin/api.php?action=list-media&type=image")[2])["data"]
        assert listed["zip"] is True, "list-media reports the zip extension"

        # --- refactored single-file actions behave as before
        status, moved = admin.api("move-media", type="image", filename="sub/c.svg", folder="")
        assert status == 200 and moved["success"] and moved["data"]["name"] == "c.svg", moved
        status, moved = admin.api("move-media", type="image", filename="c.svg", folder="sub")
        assert moved["success"] and (images / "sub/c.svg").is_file(), moved
        assert admin.api("move-media", type="image", filename="c.svg", folder="sub")[1]["message"] == "File not found"
        assert admin.api("move-media", type="image", filename="sub/c.svg", folder="../x")[1]["message"] == "Invalid folder name"

        # --- bulk move into a new nested folder
        status, result = admin.api("bulk-media", op="move", folder="neu/unter", items=items(("image", "a.svg"), ("image", "b.svg")))
        assert status == 200 and result["success"] and len(result["data"]["done"]) == 2 and not result["data"]["failed"], result
        assert (images / "neu/unter/a.svg").is_file() and (images / "neu/unter/b.svg").is_file()
        assert not (images / "a.svg").exists()
        # an unknown op, a bad item or a bad folder rejects the whole request
        assert admin.api("bulk-media", op="chmod", items=items(("image", "neu/unter/a.svg")))[0] == 400
        assert not admin.api("bulk-media", op="move", folder="", items=items(("image", "../content/users.json")))[1]["success"]
        assert not admin.api("bulk-media", op="move", folder="x/../y", items=items(("image", "neu/unter/a.svg")))[1]["success"]
        assert not admin.api("bulk-media", op="trash", items="[]")[1]["success"]
        assert not admin.api("bulk-media", op="trash", items=json.dumps([{"type": "image", "name": f"x{i}.svg"} for i in range(501)]))[1]["success"]
        # without CSRF nothing happens
        status, _, raw = admin.request("/admin/api.php", {"action": "bulk-media", "op": "trash", "items": items(("image", "neu/unter/a.svg"))})
        assert status == 403 and (images / "neu/unter/a.svg").is_file(), raw

        # --- bulk trash: per-file results, partial success
        status, result = admin.api("bulk-media", op="trash", items=items(("image", "neu/unter/a.svg"), ("image", "missing.svg")))
        assert result["success"] and [d["name"] for d in result["data"]["done"]] == ["neu/unter/a.svg"], result
        assert result["data"]["failed"][0]["name"] == "missing.svg" and result["data"]["failed"][0]["message"] == "File not found"
        assert (root / "assets/images-trash/neu/unter/a.svg").is_file()
        status, result = admin.api("bulk-media", op="restore", items=items(("image", "neu/unter/a.svg")))
        assert result["success"] and (images / "neu/unter/a.svg").is_file(), result
        admin.api("bulk-media", op="trash", items=items(("image", "neu/unter/a.svg")))
        status, result = admin.api("bulk-media", op="purge", items=items(("image", "neu/unter/a.svg")))
        assert result["success"] and not (root / "assets/images-trash/neu/unter/a.svg").exists(), result

        # --- ZIP download: shared folder left out, one top folder per media type
        status, headers, body = admin.request("/admin/api.php", {"action": "zip-media", "csrf_token": admin.csrf,
                                                                 "items": items(("image", "neu/unter/b.svg"), ("image", "sub/c.svg"))})
        assert status == 200 and headers.get("Content-Type", "").startswith("application/zip"), (status, headers, body[:200])
        with zipfile.ZipFile(io.BytesIO(body)) as archive:
            assert sorted(archive.namelist()) == ["neu/unter/b.svg", "sub/c.svg"], archive.namelist()
            assert archive.read("sub/c.svg") == svg
        status, headers, body = admin.request("/admin/api.php", {"action": "zip-media", "csrf_token": admin.csrf,
                                                                 "items": items(("image", "neu/unter/b.svg"))})
        with zipfile.ZipFile(io.BytesIO(body)) as archive:
            assert archive.namelist() == ["b.svg"], archive.namelist()
        status, headers, body = admin.request("/admin/api.php", {"action": "zip-media", "csrf_token": admin.csrf,
                                                                 "items": items(("image", "sub/c.svg"), ("document", "readme.txt"))})
        with zipfile.ZipFile(io.BytesIO(body)) as archive:
            assert sorted(archive.namelist()) == ["documents/readme.txt", "images/sub/c.svg"], archive.namelist()
        status, headers, body = admin.request("/admin/api.php", {"action": "zip-media", "csrf_token": admin.csrf,
                                                                 "items": items(("image", "nope.svg"))})
        assert status == 400 and not json.loads(body)["success"]

        # --- ZIP import: every entry is checked on its own
        archive = build_zip([
            ("Fotos/Bild Ä 1.webp", webp),
            ("Fotos/logo.SVG", SVG_XSS),
            ("evil.php", b"<?php echo 1;"),
            ("Fotos/shell.php.svg.php", b"<?php echo 1;"),
            ("../../escape.svg", svg),
            ("/abs.svg", svg),
            ("fake.jpg", b"just text, not a jpeg"),
            ("__MACOSX/Fotos/._logo.SVG", b"junk"),
            (".htaccess", b"Options +ExecCGI"),
            ("link.svg", b"/etc/passwd", 0o120777),
            ("a/b/c/d/e/deep.svg", svg),
            ("Docs/notes.txt", b"plain notes\n"),
        ])
        status, result = unzip(admin, "Upload Ä.zip", archive, folder="import")
        assert status == 200 and result["success"], result
        names = sorted(i["name"] for i in result["data"]["imported"])
        assert names == ["import/Docs/notes.txt", "import/Fotos/Bild-1.webp", "import/Fotos/logo.svg"], names
        reasons = {s["entry"]: s["reason"] for s in result["data"]["skipped"]}
        assert reasons == {
            "evil.php": "type_not_allowed", "Fotos/shell.php.svg.php": "type_not_allowed",
            "../../escape.svg": "unsafe_path", "/abs.svg": "unsafe_path", "fake.jpg": "mime_mismatch",
            "link.svg": "not_regular", "a/b/c/d/e/deep.svg": "too_deep"}, reasons
        assert result["data"]["ignored"] == 2, result["data"]
        cleaned = (images / "import/Fotos/logo.svg").read_text()
        assert "<script" not in cleaned and "onload" not in cleaned, cleaned
        assert (root / "assets/documents/import/Docs/notes.txt").read_text() == "plain notes\n"
        for leaked in ("escape.svg", "abs.svg", "evil.php", "fake.jpg", "link.svg", ".htaccess"):
            assert not list(root.rglob(leaked)) or leaked == ".htaccess" and not (images / "import/.htaccess").exists(), leaked
        assert not list(root.rglob("*.php.svg*")) and not list((root / "assets").rglob("*.php"))
        assert not [p for p in (root / "assets").rglob("*") if p.is_symlink()]

        # the same archive again: nothing is overwritten, flat import into a folder named after the archive
        status, result = unzip(admin, "Upload Ä.zip", archive, folder="import", structure="0", subfolder="1")
        names = sorted(i["name"] for i in result["data"]["imported"])
        assert names == ["import/Upload/Bild-1.webp", "import/Upload/deep.svg", "import/Upload/logo.svg", "import/Upload/notes.txt"], names
        status, result = unzip(admin, "again.zip", build_zip([("Fotos/logo.svg", svg)]), folder="import")
        assert [i["name"] for i in result["data"]["imported"]] == ["import/Fotos/logo-1.svg"], result

        # rejected archives import nothing
        before = sorted(p for p in (root / "assets").rglob("*") if p.is_file())
        bomb = build_zip([("ok.svg", svg), ("zeros.txt", b"\0" * (8 * 1024 * 1024))])
        status, result = unzip(admin, "bomb.zip", bomb)
        assert status == 400 and "ZIP bomb" in result["message"], result
        status, result = unzip(admin, "fake.zip", b"PK\x03\x04 not really a zip archive")
        assert status == 400 and not result["success"], result
        status, result = unzip(admin, "photo.jpg", archive)
        assert status == 400 and result["message"] == "Invalid file extension", result
        status, result = unzip(admin, "only-bad.zip", build_zip([("x.php", b"<?php"), ("y.exe", b"MZ")]))
        assert status == 400 and not result["success"] and result["data"]["skippedCount"] == 2, result
        status, result = unzip(admin, "badfolder.zip", archive, folder="../content")
        assert status == 400 and result["message"] == "Invalid folder name", result
        assert sorted(p for p in (root / "assets").rglob("*") if p.is_file()) == before

        # editors may import like they may upload; signed-out requests are refused
        editor = Client(site).login("editor")
        status, result = unzip(editor, "editor.zip", build_zip([("e.svg", svg)]))
        assert result["success"] and result["data"]["imported"][0]["name"] == "e.svg", result
        status, _, _ = Client(site).request("/admin/api.php", {"action": "bulk-media", "op": "trash", "items": items(("image", "e.svg"))})
        assert status in (401, 403) and (images / "e.svg").is_file()

        # temporary working folders are cleaned up
        assert not list((root / "backups").glob(".nibbly-media-*"))
        print("PASS media batch actions: bulk move into new folder, trash/restore/purge with per-file results, "
              "ZIP download layout, ZIP import with path/link/type/content/depth checks, SVG sanitizing, "
              "no overwrite, ZIP bomb and fake archive rejection, CSRF and login required")
    finally:
        site.close()
