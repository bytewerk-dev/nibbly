#!/usr/bin/env python3
"""HTTP regression tests against a disposable local Nibbly installation."""
import json
import re
import subprocess
import tempfile
import time
import traceback
import urllib.parse
import zipfile
from support import Client, Site, write_json


def test_auth(site):
    guest = Client(site)
    assert guest.api("list-users")[0] == 401
    admin = Client(site).login()
    editor = Client(site).login("editor")
    assert not editor.api("list-users")[1]["success"]
    assert not admin.api("save", page="en_home", content="{}", csrf_token="wrong")[1]["success"]
    assert not admin.api("save", page="../users", content="{}")[1]["success"]
    assert not admin.api("save", page="en_home", content="42")[1]["success"]
    # Account changes must take effect in the already authenticated session.
    users = json.loads((site.root / "content/users.json").read_text())
    users["users"][1]["role"] = "admin"
    write_json(site.root / "content/users.json", users)
    assert editor.api("list-users")[1]["success"], "Role promotion was not refreshed"
    users["users"][1]["role"] = "editor"
    write_json(site.root / "content/users.json", users)
    assert not editor.api("list-users")[1]["success"], "Demoted user retained admin access"
    users["users"][1]["passwordHash"] = "changed-password-hash"
    write_json(site.root / "content/users.json", users)
    assert editor.api("list-pages")[0] == 401, "Password change did not revoke the session"
    write_json(site.root / "content/users.json", {"users": site.users})
    editor = Client(site).login("editor")
    write_json(site.root / "content/users.json", {"users": [site.users[0]]})
    assert editor.api("list-pages")[0] == 401, "Deleted user retained access"
    write_json(site.root / "content/users.json", {"users": site.users})
    for target in ("javascript:alert(1)", "data:text/html,test", "//example.invalid/", "/\\example.invalid/", "/admin/dashboard.php"):
        status, headers, _ = Client(site).request("/admin/index.php?logout=1&redirect=" + urllib.parse.quote(target, safe=""))
        assert status == 302 and headers["Location"] == "/", f"Unsafe redirect: {target}"


def test_news(site):
    editor = Client(site).login("editor")
    users_before = (site.root / "content/users.json").read_bytes()
    post = {"title": "News fixture", "date": "2026-09-04", "slug": "audit-news", "lang": "en",
            "content": '<p>Safe <strong>text</strong><a href="java&#x73;cript:alert(1)">link</a></p>'}
    result = editor.api("save-news", post=json.dumps({**post, "id": "../users"}))[1]
    assert not result["success"], "News ID traversal was accepted"
    assert (site.root / "content/users.json").read_bytes() == users_before
    result = editor.api("save-news", post=json.dumps(post))[1]
    assert result["success"], result
    news_id = result["data"]["id"]
    path = site.root / "content/news" / (news_id + ".json")
    assert 'javascript:' not in path.read_text().lower()
    saved = path.read_bytes()
    assert not editor.api("save-news", post=json.dumps(post))[1]["success"], "New post overwrote an existing slug"
    assert path.read_bytes() == saved
    # A failed rename/save must retain the original record.
    (site.root / "content/news/2026-09-04-unwritable.json").mkdir()
    result = editor.api("save-news", post=json.dumps({**post, "id": news_id, "slug": "unwritable"}))[1]
    assert not result["success"] and path.read_bytes() == saved, "Failed save removed the original news post"


def test_routing(site):
    guest = Client(site)
    for path in ("/", "/en", "/en/", "/services/test", "/services/test/", "/en/services/test", "/en/services/test/"):
        status, _, html = guest.request(path)
        assert status == 200, f"Page failed: {path}: {status}"
        css = re.search(rb'href="([^\"]*css/style\.css[^\"]*)"', html)
        assert css, "No core stylesheet"
        css_path = urllib.parse.urlsplit(urllib.parse.urljoin("http://localhost" + path, css[1].decode())).path
        assert guest.request(css_path)[0] == 200, f"Broken relative asset: {path} -> {css_path}"
    for name in ("backups/test.zip", "assets/images-trash/secret.png", ".review-secret", "assets/.review-secret"):
        (site.root / name).write_text("private-fixture")
    for path in ("/content/users.json", "/%63ontent/users.json", "/backups/test.zip", "/assets/images-trash/secret.png", "/.review-secret", "/assets/.review-secret", "/tests/system-smoke.py"):
        assert guest.request(path)[0] in (403, 404), f"Sensitive path exposed: {path}"
    (site.root / "assets/videos/range.mp4").write_bytes(b"0123456789")
    status, headers, body = guest.request("/assets/videos/range.mp4", headers={"Range": "bytes=3-5"})
    assert status == 206 and body == b"345" and headers["Content-Range"] == "bytes 3-5/10"
    assert guest.request("/assets/videos/range.mp4", headers={"Range": "bytes=-"})[0] == 416
    assert guest.request("/missing-page")[0] == 404
    password_hash = subprocess.check_output(["php", "-r", "echo password_hash('Private-A7!', PASSWORD_DEFAULT);"], text=True)
    private = {"page": "en_private", "lang": "en", "title": "Private", "sections": [],
               "visibility": {"status": "private", "passwordHash": password_hash}}
    write_json(site.root / "content/pages/en_private.json", private)
    assert guest.request("/private")[0] == 403
    assert guest.request("/private", {"nibbly_page_password": "Private-A7!"})[0] == 302
    assert guest.request("/private")[0] == 200
    private["visibility"]["passwordHash"] = "changed-private-password"
    write_json(site.root / "content/pages/en_private.json", private)
    assert guest.request("/private")[0] == 403, "Changed private password did not revoke the old grant"
    sitemap = guest.request("/sitemap.xml")[2]
    assert b"/private/" not in sitemap
    write_json(site.root / "content/settings.json", {"modules": {"ai": False}, "access": {"maintenance": {"enabled": True}}})
    assert Client(site).request("/services/test")[0] == 503
    assert Client(site).request("/admin/index.php")[0] == 200
    write_json(site.root / "content/settings.json", {"modules": {"ai": False}})


def test_forms(site):
    guest = Client(site)
    form = {"id": "review", "label": "Review", "enabled": True,
            "submit": {"store": False, "email": True, "successText": "Delivered"},
            "fields": [{"key": "name", "type": "text", "label": "Name", "required": True, "width": 12}]}
    write_json(site.root / "content/forms/review.json", form)
    status, _, body = guest.request("/api/form.php?form=review")
    assert status == 200
    token = re.search(rb'name="form_token" value="([^\"]+)"', body)[1].decode()
    tokens = json.loads((site.root / "content/form-tokens.json").read_text())
    for entry in tokens.values():
        entry["created"] = int(time.time()) - 5
    write_json(site.root / "content/form-tokens.json", tokens)
    status, _, body = guest.request("/api/form-submit.php", {"form_id": "review", "form_token": token, "name": "Test"})
    assert status == 503 and not json.loads(body)["success"], "Failed delivery reported success"
    form["submit"]["store"] = True
    form["submit"]["email"] = False
    write_json(site.root / "content/forms/review.json", form)
    token = json.loads(body)["formToken"]
    tokens = json.loads((site.root / "content/form-tokens.json").read_text())
    for entry in tokens.values():
        entry["created"] = int(time.time()) - 5
    write_json(site.root / "content/form-tokens.json", tokens)
    status, _, body = guest.request("/api/form-submit.php", {"form_id": "review", "form_token": token, "name": "Test"})
    assert json.loads(body)["success"], body
    assert len(json.loads((site.root / "content/mails.json").read_text())) == 1
    assert guest.request("/api/form-submit.php", {"form_id": "review", "form_token": token, "name": "Replay"})[0] == 400


def test_backups(site):
    admin = Client(site).login()
    media = {"assets/videos/clip.mp4": b"video-fixture", "assets/documents/guide.pdf": b"pdf-fixture",
             "assets/documents/sheet.xlsx": b"xlsx-fixture", "assets/audio/recording.flac": b"flac-fixture"}
    for name, content in media.items():
        (site.root / name).write_bytes(content)
    status, result = admin.api("create-site-backup")
    assert result["success"], result
    filename = result["data"]["filename"]
    with zipfile.ZipFile(site.root / "backups" / filename) as archive:
        assert all(name in archive.namelist() for name in media)
        assert not any(name.startswith("tests/") for name in archive.namelist())
    for name in media:
        (site.root / name).unlink()
    result = admin.api("restore-site-backup", pool_file=filename, restore_mode="content")[1]
    assert result["success"], result
    for name, content in media.items():
        assert (site.root / name).is_file() and (site.root / name).read_bytes() == content, f"Restore lost {name}"
    # Bad targets must be detected before content-only restore removes pages.
    page = site.root / "content/pages/en_home.json"
    page.write_text('{"title":"Keep newer content after failed restore"}')
    marker = page.read_bytes()
    video = site.root / "assets/videos/clip.mp4"
    video.unlink()
    video.mkdir()
    result = admin.api("restore-site-backup", pool_file=filename, restore_mode="content")[1]
    assert not result["success"] and page.read_bytes() == marker, "Failed restore lost existing pages"
    video.rmdir()
    original_zip = (site.root / "backups" / filename).read_bytes()
    result = admin.api("restore-site-backup", pool_file=filename, restore_mode="full")[1]
    assert result["success"], result
    assert (site.root / "backups" / filename).read_bytes() == original_zip, "Safety backup overwrote restore source"


def test_deployment_cli(site):
    # Production backups omit the development router; CLI tools need route.php.
    router = site.root / "router.php"
    router.rename(site.root / "router.php.disabled")
    try:
        for name in ("make", "convert", "backup"):
            result = subprocess.run(["php", f"cli/{name}.php", "--help"], cwd=site.root, capture_output=True, text=True)
            assert result.returncode == 0, result.stdout + result.stderr
    finally:
        (site.root / "router.php.disabled").rename(router)


if __name__ == "__main__":
    failures = []
    with tempfile.TemporaryDirectory(prefix="nibbly-system-smoke-") as folder:
        site = Site(folder).start()
        try:
            for test in (test_auth, test_news, test_routing, test_forms, test_backups, test_deployment_cli):
                try:
                    test(site)
                    print("PASS", test.__name__)
                except Exception:
                    failures.append(test.__name__)
                    traceback.print_exc()
        finally:
            site.close()
    if failures:
        raise SystemExit("Failed: " + ", ".join(failures))
