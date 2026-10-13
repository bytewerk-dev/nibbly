#!/usr/bin/env python3
"""Browser check of the page list and the language buttons with translated URL paths (Chromium via Playwright)."""
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
from support import ROOT, Site, write_json

with tempfile.TemporaryDirectory(prefix="nibbly-page-list-browser-") as folder:
    site = Site(folder)
    config = site.root / "admin/config.php"
    config.write_text(re.sub(r"define\('SITE_LANG_DEFAULT', '[a-z]{2}'\)", "define('SITE_LANG_DEFAULT', 'de')", config.read_text()))
    for file in (site.root / "content/pages").glob("*.json"):
        file.unlink()
    for lang, slug, title in [
        ("de", "home", "Startseite"), ("en", "home", "Home"),
        ("de", "leistungen", "Leistungen"), ("en", "services", "Services"),
        ("de", "team", "Unser Team"), ("en", "team", "Our team"),
        ("de", "ueber-uns", "Über uns"),
    ]:
        key = lang + "_" + slug
        write_json(site.root / "content/pages" / (key + ".json"), {"page": key, "path": slug, "lang": lang, "title": title,
                   "sections": [{"id": "intro", "type": "text", "title": title, "content": "<p>" + title + "</p>"}]})
    (site.root / "includes/nav-config.php").write_text("""<?php
$PAGE_MAPPING = [
    'home' => ['de' => '.', 'en' => 'en/'],
    'leistungen' => ['de' => 'leistungen', 'en' => 'en/services'],
    'services' => ['de' => 'leistungen', 'en' => 'en/services'],
    'team' => ['de' => 'team', 'en' => 'en/team'],
    'ueber-uns' => ['de' => 'ueber-uns', 'en' => 'en/about-us'],
];
""")
    site.start()
    screenshots = Path(tempfile.mkdtemp(prefix="nibbly-browser-evidence-"))
    try:
        environment = dict(os.environ, REVIEW_PASSWORD=site.password, REVIEW_URL=f"http://127.0.0.1:{site.port}",
                           REVIEW_SCREENSHOTS=str(screenshots), REVIEW_SITE=str(site.root))
        subprocess.run(["node", str(ROOT / "tests" / "page-list-check.js")], env=environment, cwd=ROOT, check=True, timeout=120)
        print("PASS page list browser: one row per page, language buttons open the right version, create uses the mapped path, direct editor links, language select follows the page")
    finally:
        print("Screenshots:", screenshots)
        site.close()
