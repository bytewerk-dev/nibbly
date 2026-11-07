#!/usr/bin/env python3
"""Browser check of the visual editor's block tools on pages and posts. Requires Playwright/Chromium."""
import base64
import json
import os
from pathlib import Path
import subprocess
import tempfile
from support import ROOT, Site, write_json

PNG = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M/wHwAEAQH/7yMK/gAAAABJRU5ErkJggg==")

with tempfile.TemporaryDirectory(prefix="nibbly-visual-editor-") as folder:
    site = Site(folder).start()
    images = site.root / "assets/images"
    images.mkdir(parents=True, exist_ok=True)
    for name in ("one.png", "two.png"):
        (images / name).write_bytes(PNG)
    long_text = "".join(f"<p>Paragraph number {n} with enough words to fill a line of text.</p>" for n in range(1, 4))
    write_json(site.root / "content/pages/en_services__test.json", {
        "page": "en_services__test", "lang": "en", "title": "Blocks", "sections": [
            {"id": "t1", "type": "text", "title": "", "content": long_text},
            {"id": "t2", "type": "text", "title": "Second", "content": "<p>Second block</p>"},
            {"id": "i1", "type": "image", "src": "/assets/images/one.png", "alt": "One", "caption": "First image"},
            {"id": "g1", "type": "gallery", "layout": "grid", "images": [
                {"src": "/assets/images/one.png", "alt": "", "caption": "Tile"},
                {"src": "/assets/images/two.png", "alt": "", "caption": ""}]},
            {"id": "t3", "type": "text", "title": "", "content": "<p>Last block</p>"}]})
    (site.root / "content/news").mkdir(parents=True, exist_ok=True)
    write_json(site.root / "content/news/2026-01-02-block-post.json", {
        "id": "2026-01-02-block-post", "slug": "block-post", "lang": "en", "date": "2026-01-02", "title": "Block post",
        "excerpt": "", "hidden": False, "sidebar": True,
        "sections": [
            {"id": "a", "type": "text", "content": "<p>Alpha block</p>"},
            {"id": "b", "type": "text", "content": "<p>Beta block</p>"},
            {"id": "c", "type": "image", "src": "/assets/images/one.png", "alt": "", "caption": "Post image"},
            {"id": "d", "type": "text", "content": "<p>Delta block</p>"}],
        "aside": [{"id": "s", "type": "text", "content": "<p>Aside block</p>"}]})
    # A page with its own template and free fields, for the content editor
    write_json(site.root / "content/pages/en_custom.json", {
        "page": "en_custom", "lang": "en", "title": "Custom",
        "intro": {"title": "Plain title", "text": "<p>Rich <b>text</b></p>"}})
    (site.root / "en").mkdir(exist_ok=True)
    (site.root / "en/custom.php").write_text(
        "<?php $contentPage = 'en_custom'; $currentLang = 'en'; $currentPage = 'custom'; $basePath = '../';\n"
        "include __DIR__ . '/../includes/header.php'; require_once __DIR__ . '/../includes/content-loader.php';\n"
        "$c = loadContent($contentPage); ?>\n"
        "<main><h1><?php echo editableText($contentPage, 'intro.title', $c['intro']['title']); ?></h1>"
        "<?php echo editableHtml($contentPage, 'intro.text', $c['intro']['text']); ?></main>\n"
        "<?php include __DIR__ . '/../includes/footer.php';\n")
    settings = json.loads((site.root / "content/settings.json").read_text())
    settings["modules"]["comments"] = True
    write_json(site.root / "content/settings.json", settings)
    (site.root / "content/comments").mkdir(exist_ok=True)
    long_comment = "".join(f"<p>Long comment paragraph {n}.</p>" for n in range(1, 12))
    write_json(site.root / "content/comments/2026-01-02-block-post.json", {
        "target": "news:2026-01-02-block-post", "comments": [
            {"id": "c1", "parent": "", "author": "Anna", "email": "anna@example.invalid", "content": long_comment,
             "status": "pending", "date": "2026-02-01T10:00:00+00:00"},
            {"id": "c2", "parent": "c1", "author": "Ben", "email": "", "content": "<p>Short</p>",
             "status": "approved", "date": "2026-02-02T10:00:00+00:00"}]})
    screenshots = Path(tempfile.mkdtemp(prefix="nibbly-browser-evidence-visual-editor-")  # the CI uploads nibbly-browser-evidence-*)
    try:
        environment = dict(os.environ, REVIEW_PASSWORD=site.password, REVIEW_ROOT=str(site.root),
                           REVIEW_URL=f"http://127.0.0.1:{site.port}", REVIEW_SCREENSHOTS=str(screenshots))
        subprocess.run(["node", str(ROOT / "tests/visual-editor-check.js")], env=environment, cwd=ROOT, check=True, timeout=240)
    finally:
        print("Screenshots:", screenshots)
        site.close()
