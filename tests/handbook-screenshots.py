#!/usr/bin/env python3
"""Retake the guide's screenshots (includes/handbook/img/{de,en}/).

Builds a disposable installation per language with the setup wizard's starter
content, the default theme (Pop with its own colours) and a fixed site name,
then lets tests/handbook-screenshots.js drive Chromium through the dashboard
and the website. Nothing from a live installation is used.

    cd tests && npm install && npx playwright install chromium
    python3 tests/handbook-screenshots.py            # de and en
    python3 tests/handbook-screenshots.py de         # one language
    python3 tests/handbook-screenshots.py de pages   # only shots whose name contains "pages"

Requires PHP with GD (for WebP), Node and Playwright (tests/package.json).
"""
import os
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import sys
import tempfile
import time

from server_process import start_server, stop_server

ROOT = Path(__file__).resolve().parent.parent
OUTPUT = ROOT / "includes" / "handbook" / "img"
LANGUAGES = ("de", "en")
SKIP = {".git", "node_modules", "backups", "server.log"}
# Site data a checkout may hold; the setup wizard writes fresh copies
SITE_FILES = ("admin/config.php", "admin/smtp-config.php", "includes/nav-config.php",
              "content/settings.json", "content/users.json", "content/events.json",
              "content/people.json", "content/mails.json", "content/media-meta.json")


def copy_site(target: Path) -> None:
    def ignore(folder, names):
        relative = Path(folder).relative_to(ROOT)
        skipped = {name for name in names if name in SKIP}
        skipped |= {name for name in names if (relative / name).as_posix() in SITE_FILES}
        if relative.as_posix() in ("content/pages", "content/news", "content/pages-trash"):
            skipped |= {name for name in names if name.endswith(".json")}
        return skipped
    shutil.copytree(ROOT, target, ignore=ignore, dirs_exist_ok=True)
    (target / "backups").mkdir(exist_ok=True)


def free_port() -> int:
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


def take(lang: str, only: str) -> None:
    with tempfile.TemporaryDirectory(prefix=f"nibbly-handbook-{lang}-") as folder:
        site = Path(folder)
        copy_site(site)
        port = free_port()
        log = open(site / "server.log", "w+")
        server = start_server(["php", "-d", "display_errors=0", "-S", f"127.0.0.1:{port}", "router.php"],
                              cwd=site, stdout=log, stderr=log)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(("127.0.0.1", port), timeout=.1):
                        break
                except OSError:
                    time.sleep(.05)
            shots = Path(folder) / "shots"
            shots.mkdir()
            environment = dict(os.environ,
                               HANDBOOK_URL=f"http://127.0.0.1:{port}",
                               HANDBOOK_LANG=lang,
                               HANDBOOK_PASSWORD="Handbook-" + secrets.token_urlsafe(12) + "-Aa1!",
                               HANDBOOK_SHOTS=str(shots),
                               HANDBOOK_ONLY=only)
            # A failed shot keeps the others; the run still reports failure at the end
            result = subprocess.run(["node", str(ROOT / "tests" / "handbook-screenshots.js")],
                                    env=environment, cwd=ROOT / "tests", timeout=600)
            target = OUTPUT / lang
            target.mkdir(parents=True, exist_ok=True)
            pngs = sorted(shots.glob("*.png"))
            # WebP through PHP's GD: no extra image tooling needed
            convert = ("foreach (array_slice($argv, 1) as $png) {"
                       " $image = imagecreatefrompng($png); imagepalettetotruecolor($image);"
                       " imagewebp($image, $png . '.webp', 82); }")
            subprocess.run(["php", "-r", convert, "--", *map(str, pngs)], check=True)
            for png in pngs:
                shutil.move(str(png) + ".webp", target / (png.stem + ".webp"))
            print(f"{lang}: {len(pngs)} screenshots in {target.relative_to(ROOT)}", flush=True)
            if result.returncode:
                raise SystemExit(f"{lang}: some screenshots failed")
        except BaseException:
            log.seek(0)
            php_errors = [line for line in log.read().splitlines() if "PHP " in line]
            print("\n".join(php_errors[-40:]), file=sys.stderr)
            raise
        finally:
            stop_server(server)
            log.close()


if __name__ == "__main__":
    languages = [sys.argv[1]] if len(sys.argv) > 1 else list(LANGUAGES)
    for language in languages:
        if language not in LANGUAGES:
            raise SystemExit(f"Unknown language {language}; use one of {', '.join(LANGUAGES)}")
        take(language, sys.argv[2] if len(sys.argv) > 2 else "")
