#!/usr/bin/env python3
"""Security self-check: probe files through an nginx-like proxy, cleanup, cache, findings and API access."""
import argparse
import fcntl
import functools
import http.client
import http.server
import json
import os
from pathlib import Path
import re
import shutil
import socket
import ssl
import subprocess
import tempfile
import threading
import time
import traceback
import urllib.parse
from support import Client, Site

# Extensions that HestiaCP's nginx serves from disk without asking Apache.
STATIC = {".json", ".txt", ".zip", ".jpg", ".mp3", ".mp4", ".pdf", ".css", ".js", ".svg", ".webp", ".png"}


def front_proxy(root, backend_port, deny=(), tls=None):
    """Serve existing static files itself, deny prefixes like `location ^~`, proxy the rest to PHP."""
    class Handler(http.server.BaseHTTPRequestHandler):
        def log_message(self, *args):
            pass

        def do_GET(self):
            path = urllib.parse.unquote(urllib.parse.urlsplit(self.path).path)
            if any(path.startswith(prefix) for prefix in deny):
                return self.reply(403, b"denied by front proxy")
            file = Path(root) / path.lstrip("/")
            if Path(path).suffix.lower() in STATIC and file.is_file():
                return self.reply(200, file.read_bytes())
            backend = http.client.HTTPConnection("127.0.0.1", backend_port, timeout=10)
            backend.request("GET", self.path, headers={"Host": self.headers.get("Host", "")})
            response = backend.getresponse()
            self.reply(response.status, response.read(), response.getheader("Location"))

        def reply(self, status, body, location=None):
            self.send_response(status)
            if location:
                self.send_header("Location", location)
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

    server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
    if tls:
        server.socket = tls.wrap_socket(server.socket, server_side=True)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    return server


def redirect_server(target):
    class Handler(http.server.BaseHTTPRequestHandler):
        def log_message(self, *args):
            pass

        def do_GET(self):
            self.send_response(301)
            self.send_header("Location", target + self.path)
            self.send_header("Content-Length", "0")
            self.end_headers()

    server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    return server


class QuietStaticHandler(http.server.SimpleHTTPRequestHandler):
    def log_message(self, *args):
        pass


def php(root, code, server=None, constants=None):
    """Run the helper in a fresh PHP process; any notice or warning fails the test."""
    prelude = "$_SERVER = array_merge($_SERVER, json_decode(getenv('NB_SERVER'), true));"
    for name, value in (constants or {}).items():
        prelude += f"define({name!r}, {json.dumps(value)});"
    prelude += "require 'includes/security-check.php';"
    result = subprocess.run(["php", "-d", "display_errors=stderr", "-d", "error_reporting=-1", "-r", prelude + code],
                            cwd=root, capture_output=True, text=True,
                            env=dict(os.environ, NB_SERVER=json.dumps({"SCRIPT_NAME": "/admin/api.php", **(server or {})})))
    assert result.returncode == 0 and not result.stderr, result.stderr + result.stdout
    return json.loads(result.stdout)


def probe(root, base):
    return php(root, f"echo json_encode(nibblySecurityRunProbe(getcwd(), {json.dumps(base)}));")


def leftovers(root):
    return [str(path) for path in Path(root).rglob("nibbly-access-check-*")]


def items(status):
    return {item["id"]: item for item in status["items"]}


def free_port():
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


def test_probe_through_proxy(site):
    root = site.root
    folders = ["content/", "backups/", "assets/images-trash/", "assets/audio-trash/", "assets/videos-trash/", "assets/documents-trash/"]
    exposed_proxy = front_proxy(root, site.port)
    try:
        result = probe(root, f"http://127.0.0.1:{exposed_proxy.server_port}")
    finally:
        exposed_proxy.shutdown()
    assert result["state"] == "exposed", result
    assert [p["path"] for p in result["probes"]] == folders, result["probes"]
    assert all(p["status"] == 200 and p["state"] == "exposed" for p in result["probes"]), result["probes"]
    assert result["http"] == "skipped", "Plain http:// base must not run the redirect check"
    assert not leftovers(root), leftovers(root)

    # The generated nginx rules must cover every probed folder.
    rules = php(root, "echo json_encode(nibblySecurityNginxRules(''));")
    prefixes = re.findall(r"^location \^~ (\S+) \{ deny all; \}$", rules, re.M)
    assert len(prefixes) == 8 and all(("/" + folder) in prefixes for folder in folders), rules
    assert "location ^~ /sub/content/ { deny all; }" in php(root, "echo json_encode(nibblySecurityNginxRules('/sub'));")
    fixed_proxy = front_proxy(root, site.port, deny=prefixes)
    try:
        result = probe(root, f"http://127.0.0.1:{fixed_proxy.server_port}")
    finally:
        fixed_proxy.shutdown()
    assert result["state"] == "protected" and all(p["status"] == 403 for p in result["probes"]), result
    # A proxy that still serves media itself (e.g. a Hestia template limited to assets) exposes only the trash folders.
    media_proxy = front_proxy(root, site.port, deny=["/content/", "/backups/"])
    try:
        result = probe(root, f"http://127.0.0.1:{media_proxy.server_port}")
    finally:
        media_proxy.shutdown()
    assert [p["path"] for p in result["probes"] if p["state"] == "exposed"] == folders[2:], result
    # Without a proxy, .htaccess (Apache) or router.php (development server) blocks the folders.
    result = probe(root, f"http://127.0.0.1:{site.port}")
    assert result["state"] == "protected", result
    assert not leftovers(root), leftovers(root)


def test_server_ignoring_htaccess(site):
    # A bare PHP server resembles nginx + PHP-FPM without rules: files and CLI scripts are reachable.
    port = free_port()
    bare = subprocess.Popen(["php", "-d", "display_errors=0", "-S", f"127.0.0.1:{port}", "-t", str(site.root)],
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        for _ in range(100):
            try:
                with socket.create_connection(("127.0.0.1", port), timeout=.1):
                    break
            except OSError:
                time.sleep(.05)
        result = probe(site.root, f"http://127.0.0.1:{port}")
        assert result["state"] == "exposed" and "content/" in [p["path"] for p in result["probes"] if p["state"] == "exposed"], result
        for name in ("backup", "make", "convert"):
            connection = http.client.HTTPConnection("127.0.0.1", port, timeout=10)
            connection.request("GET", f"/cli/{name}.php?x+--action=run")
            response = connection.getresponse()
            body = response.read()
            # Web SAPIs echo a shebang line as text; nothing else may run or be printed.
            assert response.status == 404 and body.strip() in (b"", b"#!/usr/bin/env php"), \
                f"cli/{name}.php ran as a web request: {response.status} {body[:200]!r}"
        assert not list((site.root / "backups").glob("*-backup-*.zip")), "Web request created a backup"
    finally:
        bare.terminate()
        bare.wait(timeout=10)
    assert not leftovers(site.root), leftovers(site.root)


def test_https_redirect_and_self_signed(site):
    if not shutil.which("openssl"):
        print("SKIP test_https_redirect_and_self_signed: openssl is not installed")
        return
    root = site.root
    prefixes = re.findall(r"^location \^~ (\S+) \{ deny all; \}$", php(root, "echo json_encode(nibblySecurityNginxRules(''));"), re.M)
    with tempfile.TemporaryDirectory(prefix="nibbly-tls-") as folder:
        cert, key = Path(folder) / "cert.pem", Path(folder) / "key.pem"
        subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=127.0.0.1",
                        "-keyout", str(key), "-out", str(cert)], check=True, capture_output=True)
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(cert, key)
        # Staging servers often use self-signed certificates: the status-only probe still works.
        secure = front_proxy(root, site.port, deny=prefixes, tls=context)
        base = f"https://127.0.0.1:{secure.server_port}"
        redirect = redirect_server(base)
        plain = front_proxy(root, site.port, deny=prefixes)
        try:
            def run(plain_base):
                return php(root, f"echo json_encode(nibblySecurityRunProbe(getcwd(), {json.dumps(base)}, {json.dumps(plain_base)}));")
            result = run(f"http://127.0.0.1:{redirect.server_port}")
            assert result["state"] == "protected" and result["http"] == "redirect", result
            assert run(f"http://127.0.0.1:{plain.server_port}")["http"] == "open"
            assert run(f"http://127.0.0.1:{free_port()}")["http"] == "closed"
            # An https:// address with its own port has no known http:// counterpart.
            assert probe(root, base)["http"] == "skipped"
        finally:
            for server in (secure, redirect, plain):
                server.shutdown()
    assert not leftovers(root), leftovers(root)


def test_wrong_address_and_cleanup(site):
    root = site.root
    stale = root / "content/nibbly-access-check-0123456789abcdef.json"
    unrelated = root / "content/nibbly-access-check-notes.json"
    stale.write_text("{}")
    unrelated.write_text("{}")
    # Another installation answers the address: its 404 for the control file must not count as protected.
    with tempfile.TemporaryDirectory(prefix="nibbly-other-") as other:
        (Path(other) / "content").mkdir()
        handler = functools.partial(QuietStaticHandler, directory=other)
        other_server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), handler)
        threading.Thread(target=other_server.serve_forever, daemon=True).start()
        try:
            result = probe(root, f"http://127.0.0.1:{other_server.server_port}")
        finally:
            other_server.shutdown()
    assert result["state"] == "unknown" and result["reason"] == "unreachable" and result["detail"] == "HTTP 404", result
    assert not stale.exists(), "Stale probe file was not swept"
    assert unrelated.exists(), "Sweep removed a file that is not a probe"
    unrelated.unlink()
    started = time.monotonic()
    result = probe(root, f"http://127.0.0.1:{free_port()}")
    assert result["state"] == "unknown" and result["reason"] == "unreachable" and result["detail"], result
    assert time.monotonic() - started < 10, "Unreachable address was not answered quickly"
    assert not leftovers(root), leftovers(root)


def test_findings():
    with tempfile.TemporaryDirectory(prefix="nibbly-security-findings-") as folder:
        root = Site(folder).root
        public = {"HTTP_HOST": "www.example.com", "HTTPS": "on", "REMOTE_ADDR": "203.0.113.9", "SERVER_ADDR": "198.51.100.1"}
        status = php(root, "echo json_encode(nibblySecurityStatus('https'));", public,
                     {"NIBBLY_INITIAL_DISPLAY_ERRORS": "0", "NIBBLY_DEV_LOGIN": False})
        found = items(status)
        assert not status["local"] and not status["devServer"]
        assert found["folders"]["state"] == "pending" and found["folders"]["stale"], found["folders"]
        assert found["http"]["state"] == "pending", found["http"]
        assert all(found[key]["state"] == "ok" for key in ("https", "cookie", "client_ip", "errors", "dev_login")), found
        assert found["php"]["version"] and found["php"]["state"] in ("ok", "warning")

        # Proxy without visitor IPs, unrecognised HTTPS, visible errors and the default development login.
        found = items(php(root, "echo json_encode(nibblySecurityStatus('https'));",
                          {**public, "HTTPS": "", "REMOTE_ADDR": "127.0.0.1"}, {"NIBBLY_INITIAL_DISPLAY_ERRORS": "On"}))
        assert found["cookie"]["state"] == "warning" and found["errors"]["state"] == "warning", found
        assert found["client_ip"] == {"id": "client_ip", "state": "warning", "ip": "127.0.0.1"}, found["client_ip"]
        assert found["dev_login"]["state"] == "warning", found["dev_login"]
        found = items(php(root, "echo json_encode(nibblySecurityStatus('https'));",
                          {**public, "REMOTE_ADDR": "198.51.100.1"}, {"NIBBLY_INITIAL_DISPLAY_ERRORS": "stderr"}))
        assert found["client_ip"]["state"] == "warning" and found["dev_login"]["state"] == "note", found
        assert found["errors"]["state"] == "ok", "display_errors=stderr does not reach visitors"

        found = items(php(root, "echo json_encode(nibblySecurityStatus('http'));", {**public, "HTTPS": ""},
                          {"NIBBLY_INITIAL_DISPLAY_ERRORS": "stdout"}))
        assert found["https"]["state"] == "warning" and found["errors"]["state"] == "warning", found
        assert found["cookie"] == {"id": "cookie", "state": "skipped", "reason": "scheme"}, found["cookie"]
        assert found["http"] == {"id": "http", "state": "skipped", "reason": "scheme"}, found["http"]

        status = php(root, "echo json_encode(nibblySecurityStatus('http'));", {"HTTP_HOST": "localhost:3000", "REMOTE_ADDR": "127.0.0.1"})
        found = items(status)
        assert status["local"] and found["folders"]["state"] == "skipped" and found["folders"]["reason"] == "local", found
        assert all(found[key]["state"] == "skipped" for key in ("https", "cookie", "client_ip", "errors", "dev_login")), found

        found = items(php(root, "echo json_encode(nibblySecurityStatus('https'));", {**public, "HTTP_HOST": "evil.example/x"}))
        assert found["folders"]["state"] == "unknown" and found["folders"]["reason"] == "host", found["folders"]

        hosts = php(root, "echo json_encode(array_map('nibblySecurityIsLocalHost', ['localhost:3000', '127.0.0.1', '[::1]:8080', "
                          "'site.test', 'nibbly.localhost', '192.168.1.20', 'intranet', 'www.example.com', '93.184.215.14:8443', "
                          "'beruehrungwirkt.mybyte.at']));")
        assert hosts == [True] * 7 + [False] * 3, hosts


def test_cache_and_lock():
    with tempfile.TemporaryDirectory(prefix="nibbly-security-cache-") as folder:
        root = Site(folder).root
        cache = root / "content/security-check.json"
        public = {"HTTP_HOST": "www.example.com", "HTTPS": "on", "REMOTE_ADDR": "203.0.113.9"}
        entry = {"version": 1, "baseUrl": "https://www.example.com", "checkedAt": "2026-09-26T10:00:00+02:00",
                 "checkedTs": int(time.time()) - 60, "state": "exposed", "reason": "", "detail": "",
                 "probes": [{"path": "content/", "status": 200, "state": "exposed"}, {"path": "backups/", "status": 403, "state": "protected"}],
                 "http": "open"}
        cache.write_text(json.dumps(entry))
        # A recent result is reused without any network request, even when running is allowed.
        found = items(php(root, "echo json_encode(nibblySecurityStatus('https', true));", public))
        assert found["folders"]["state"] == "critical" and found["folders"]["exposed"] == ["content/"], found["folders"]
        assert not found["folders"]["stale"] and "location ^~ /content/ { deny all; }" in found["folders"]["rules"]
        assert found["http"] == {"id": "http", "state": "warning", "mode": "open"}, found["http"]
        assert json.loads(cache.read_text())["checkedTs"] == entry["checkedTs"], "Fresh cache was re-run"
        # Deleted media alone is a warning (no dashboard banner), still with the nginx rules.
        trash = {**entry, "probes": [{"path": "content/", "status": 403, "state": "protected"},
                                     {"path": "assets/images-trash/", "status": 200, "state": "exposed"}]}
        cache.write_text(json.dumps(trash))
        found = items(php(root, "echo json_encode(nibblySecurityStatus('https'));", public))["folders"]
        assert found["state"] == "warning" and found["exposed"] == ["assets/images-trash/"] and found["rules"], found
        cache.write_text(json.dumps(entry))
        # Exposed results expire after ten minutes; protected ones after twelve hours.
        cache.write_text(json.dumps({**entry, "checkedTs": int(time.time()) - 601}))
        assert items(php(root, "echo json_encode(nibblySecurityStatus('https'));", public))["folders"]["stale"]
        cache.write_text(json.dumps({**entry, "state": "protected", "checkedTs": int(time.time()) - 601}))
        assert not items(php(root, "echo json_encode(nibblySecurityStatus('https'));", public))["folders"]["stale"]
        # Another address (moved site, restored backup) must not inherit the result.
        assert items(php(root, "echo json_encode(nibblySecurityStatus('https'));", {**public, "HTTP_HOST": "staging.example.com"}))["folders"]["state"] == "pending"
        # A running check holds the lock: report it instead of starting a second probe.
        cache.write_text(json.dumps(entry))
        with open(str(cache) + ".lock", "a") as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            found = items(php(root, "echo json_encode(nibblySecurityStatus('https', true, true));", public))
            fcntl.flock(lock, fcntl.LOCK_UN)
        assert found["folders"]["running"] and found["folders"]["state"] == "critical", found["folders"]


def test_api(site):
    guest = Client(site)
    assert guest.api("security-check")[0] == 401
    admin = Client(site).login()
    editor = Client(site).login("editor")
    assert editor.api("security-check")[0] == 403
    # Only administrators get the warning banner and the system status badge.
    for client, visible in ((admin, True), (editor, False)):
        body = client.request("/admin/dashboard.php")[2]
        assert (b'id="securityExposureWarning"' in body and b'id="systemBadge"' in body) == visible, client
    status, _, _ = admin.request("/admin/api.php?action=security-check")
    assert status == 405, "Probe run must require POST"
    assert admin.api("security-check", csrf_token="wrong")[0] == 403
    apache = hasattr(site, "fpm_process")
    if apache:
        # A local address is only probed on request, then through Apache and the real .htaccess.
        status, result = admin.api("security-check", scheme="http")
        assert status == 200 and items(result["data"])["folders"]["state"] == "skipped", result
    status, result = admin.api("security-check", scheme="http", force="1")
    assert status == 200 and result["success"], result
    found = items(result["data"])
    if apache:
        assert found["folders"]["state"] == "ok" and all(p["status"] == 403 for p in found["folders"]["probes"]), found["folders"]
        assert json.loads((site.root / "content/security-check.json").read_text())["state"] == "protected"
    else:
        # The development server is single-threaded and cannot request itself.
        assert found["folders"] == {"id": "folders", "canRun": False, "url": f"http://127.0.0.1:{site.port}",
                                    "state": "skipped", "reason": "dev_server"}, found["folders"]
    assert found["http"]["state"] == "skipped" and found["php"]["version"], found
    status, _, body = admin.request("/admin/api.php?action=system-status&scheme=http")
    security = json.loads(body)["data"]["security"]
    assert status == 200 and security["devServer"] != apache, security
    assert items(security)["folders"]["state"] == ("ok" if apache else "skipped"), security
    assert not leftovers(site.root)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--apache", action="store_true", help="use Apache/PHP-FPM with the real .htaccess behind the proxy")
    args = parser.parse_args()
    if args.apache:
        from apache_support import ApacheSite as Backend
    else:
        Backend = Site
    failures = []
    for test in (test_findings, test_cache_and_lock):
        try:
            test()
            print("PASS", test.__name__)
        except Exception:
            failures.append(test.__name__)
            traceback.print_exc()
    with tempfile.TemporaryDirectory(prefix="nibbly-security-smoke-") as folder:
        site = Backend(folder)
        try:
            site.start()
            if args.apache:
                print(site.server_version + "; " + site.php_version)
            for test in (test_probe_through_proxy, test_server_ignoring_htaccess, test_https_redirect_and_self_signed,
                         test_wrong_address_and_cleanup, test_api):
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
