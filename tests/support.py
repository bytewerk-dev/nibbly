"""Disposable, offline Nibbly fixtures; never copy live config or content."""
import http.client
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import time
import urllib.parse
from http.cookies import SimpleCookie

ROOT = Path(__file__).resolve().parent.parent


def copy_core(target):
    target = Path(target)
    for folder in ("admin", "api", "includes", "cli", "css", "js", "templates", "examples", "tests"):
        if (ROOT / folder).is_dir():
            shutil.copytree(ROOT / folder, target / folder,
                            ignore=shutil.ignore_patterns("config.php", "smtp-config.php", "nav-config.php", "__pycache__", "*.pyc"))
    for source in ROOT.iterdir():
        if source.is_file() and (source.suffix in (".php", ".md") or source.name in (".htaccess", "VERSION")):
            shutil.copyfile(source, target / source.name)
    for folder in ("content/pages", "content/news", "content/forms", "content/pages-trash", "backups",
                   "assets/images/generated", "assets/images-trash", "assets/audio", "assets/audio-trash",
                   "assets/videos", "assets/videos-trash", "assets/documents", "assets/documents-trash"):
        (target / folder).mkdir(parents=True, exist_ok=True)
    for name in ("content/menus.json", "content/schema.json", "content/forms/contact.json", "content/.htaccess",
                 "assets/images/favicon.svg", "assets/images/placeholder-image.webp"):
        shutil.copyfile(ROOT / name, target / name)
    return target


def write_json(path, value):
    Path(path).write_text(json.dumps(value), encoding="utf-8")


class Site:
    def __init__(self, root):
        self.root = copy_core(root)
        self.password = "Review-Aa7!" + secrets.token_urlsafe(18)
        password_hash = subprocess.check_output(
            ["php", "-r", "echo password_hash(getenv('REVIEW_PASSWORD'), PASSWORD_DEFAULT);"],
            env=dict(os.environ, REVIEW_PASSWORD=self.password), text=True)
        self.users = [{"id": "u_" + role, "username": role, "email": role + "@example.invalid",
                       "role": role, "passwordHash": password_hash, "createdAt": "2026-09-04T00:00:00Z",
                       "lastLogin": None, "resetToken": None, "resetTokenExpiry": None}
                      for role in ("admin", "editor")]
        config = (self.root / "admin/config.example.php").read_text().replace(
            "define('NIBBLY_DEV_LOGIN', true)", "define('NIBBLY_DEV_LOGIN', false)")
        (self.root / "admin/config.php").write_text(config)
        write_json(self.root / "content/users.json", {"users": self.users})
        write_json(self.root / "content/settings.json", {"modules": {"ai": False}, "privacy": {"analyticsEnabled": False}})
        for name in ("home", "services__test"):
            write_json(self.root / ("content/pages/en_" + name + ".json"), {
                "page": "en_" + name, "lang": "en", "title": name, "sections": [
                    {"id": "intro", "type": "text", "content": "<p>Public fixture</p>"}]})
        self.port = 0
        self.process = None

    def start(self, router="router.php"):
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            self.port = sock.getsockname()[1]
        self.log = open(self.root / "server.log", "w+")
        self.process = subprocess.Popen(
            ["php", "-d", "display_errors=0", "-S", f"127.0.0.1:{self.port}", router],
            cwd=self.root, stdout=self.log, stderr=self.log)
        for _ in range(100):
            if self.process.poll() is not None:
                self.log.seek(0)
                raise RuntimeError(self.log.read())
            try:
                with socket.create_connection(("127.0.0.1", self.port), timeout=.1):
                    return self
            except OSError:
                time.sleep(.05)
        raise RuntimeError("Test server did not start")

    def close(self):
        if self.process:
            self.process.terminate()
            self.process.wait(timeout=10)
            self.log.close()


class Client:
    def __init__(self, site):
        self.site = site
        self.cookies = SimpleCookie()
        self.csrf = ""

    def request(self, path, data=None, method=None, headers=None):
        headers = dict(headers or {})
        if self.cookies:
            headers["Cookie"] = "; ".join(f"{k}={v.value}" for k, v in self.cookies.items())
        if isinstance(data, dict):
            data = urllib.parse.urlencode(data)
            headers["Content-Type"] = "application/x-www-form-urlencoded"
        connection = http.client.HTTPConnection("127.0.0.1", self.site.port, timeout=15)
        connection.request(method or ("POST" if data is not None else "GET"), path, body=data, headers=headers)
        response = connection.getresponse()
        body = response.read()
        result_headers = dict(response.getheaders())
        for key, value in response.getheaders():
            if key.lower() == "set-cookie":
                self.cookies.load(value)
        status = response.status
        connection.close()
        return status, result_headers, body

    def login(self, username="admin", password=None):
        import re
        self.request("/admin/index.php")
        old_id = self.cookies["PHPSESSID"].value
        status, _, _ = self.request("/admin/index.php", {"username": username, "password": password or self.site.password})
        assert status == 302, f"Login failed: {status}"
        assert self.cookies["PHPSESSID"].value != old_id, "Login did not rotate the session ID"
        status, _, body = self.request("/admin/dashboard.php")
        assert status == 200, f"Dashboard failed: {status}"
        self.csrf = re.search(rb"const CSRF_TOKEN = ['\"]([^'\"]+)", body)[1].decode()
        return self

    def api(self, action, **fields):
        status, _, body = self.request("/admin/api.php", {"action": action, "csrf_token": self.csrf, **fields})
        try:
            return status, json.loads(body)
        except ValueError:
            raise AssertionError(f"{action}: invalid JSON ({status}): {body[:250]!r}")
