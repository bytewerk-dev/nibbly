#!/usr/bin/env python3
"""Guide at /admin/handbook: session, login return, switch, modules, roles, site hook, images, theme default."""
import json
import re
import tempfile
from support import Client, Site, write_json


def settings(site, **groups):
    write_json(site.root / "content/settings.json", {"modules": {"ai": False}, "privacy": {"analyticsEnabled": False}, **groups})


def chapter_ids(body):
    return re.findall(rb'<section class="hb-chapter[^"]*" id="([a-z0-9-]+)"', body)


with tempfile.TemporaryDirectory(prefix="nibbly-handbook-") as folder:
    site = Site(folder).start()
    try:
        guest = Client(site)
        status, headers, _ = guest.request("/admin/handbook")
        assert status == 302 and headers["Location"] == "index.php", (status, headers)
        status, headers, _ = guest.request("/admin/index.php", {"username": "admin", "password": site.password})
        assert status == 302 and headers["Location"] == "handbook", "login returns to the guide"

        admin = Client(site).login()
        status, headers, body = admin.request("/admin/handbook")
        assert status == 200, status
        assert "noindex" in headers.get("X-Robots-Tag", ""), headers
        assert b'<meta name="robots" content="noindex, nofollow">' in body
        assert b'rel="icon" href="/assets/images/favicon.svg"' in body
        assert b'data-theme-preset="pop"' in body, "a fresh install shows the guide in Pop"
        ids = chapter_ids(body)
        for expected in (b"welcome", b"pages", b"news", b"menus", b"users", b"security"):
            assert expected in ids, (expected, ids)
        assert b"ai" not in ids, "the AI chapter follows the AI module"
        status, headers, _ = admin.request("/admin/handbook.php?x=1")
        assert status == 308 and headers["Location"] == "handbook?x=1", (status, headers)

        status, headers, image = admin.request("/admin/handbook?img=login.webp")
        assert status == 200 and headers["Content-Type"] == "image/webp" and image[:4] == b"RIFF", (status, headers)
        for name in ("../config.php", "login.php", "site/../../admin/config.php", "nothing.webp"):
            assert admin.request("/admin/handbook?img=" + name)[0] == 404, name

        _, _, dashboard = admin.request("/admin/dashboard.php")
        assert b'class="topbar-handbook"' in dashboard and b'id="moduleHandbook"' in dashboard

        # Modules hide their chapters; English through the admin language
        settings(site, modules={"ai": True, "news": False, "events": False}, general={"adminLanguage": "en"})
        _, _, body = admin.request("/admin/handbook")
        ids = chapter_ids(body)
        assert b"news" not in ids and b"events" not in ids and b"ai" in ids, ids
        assert b'<html lang="en"' in body and b"Contents" in body, "English guide"

        # Editors see only what their account can reach
        editor = Client(site).login("editor")
        _, _, body = editor.request("/admin/handbook")
        ids = chapter_ids(body)
        assert b"menus" not in ids and b"users" not in ids and b"icons" not in ids and b"pages" in ids, ids

        # Site chapters: placed after the named chapter, core chapters hidden
        hook = site.root / "includes/site-handbook"
        (hook / "img").mkdir(parents=True)
        (hook / "chapters.php").write_text("<?php return ['chapters' => [['id' => 'speaker', 'after' => 'people', "
                                           "'title' => ['de' => 'Speaker', 'en' => 'Speakers'], 'file' => 'speaker.{lang}.php']], "
                                           "'hide' => ['icons'], 'contact' => ['en' => 'Write to <b>us</b>.']];")
        (hook / "speaker.de.php").write_text("<p>Speaker-Kapitel</p>")
        settings(site)
        _, _, body = admin.request("/admin/handbook")
        ids = chapter_ids(body)
        assert ids[ids.index(b"people") + 1] == b"speaker" and b"icons" not in ids, ids
        assert b"Speaker-Kapitel" in body and b"Write to <b>us</b>." in body

        # Switched off: 404 and no links
        settings(site, modules={"ai": False, "handbook": False})
        assert admin.request("/admin/handbook")[0] == 404
        assert guest.request("/admin/handbook")[0] == 404
        _, _, dashboard = admin.request("/admin/dashboard.php")
        assert b'class="topbar-handbook"' not in dashboard
        status, saved = admin.api("save-settings", settings=json.dumps({"modules": {"handbook": True}}),
                                  revision=admin.api("load-settings")[1]["revision"])
        assert status == 200 and saved["success"] and saved["data"]["modules"]["handbook"] is True, saved

        # A theme saved without a preset (installs up to 2.5) keeps Soft
        settings(site, theme={"adminTheme": "light", "primaryColor": "#3858e9", "accentColor": "#3858e9"})
        _, _, dashboard = admin.request("/admin/dashboard.php")
        assert b'data-theme-preset="soft"' in dashboard and b"--nb-base: #3858e9" in dashboard
        _, loaded = admin.api("load-settings")
        assert loaded["data"]["theme"]["preset"] == "soft" and loaded["data"]["theme"]["buttonRadius"] == 6, loaded["data"]["theme"]
        settings(site)
        _, loaded = admin.api("load-settings")
        assert loaded["data"]["theme"]["preset"] == "pop" and loaded["data"]["theme"]["primaryColor"] == "#3d8ee9", loaded["data"]["theme"]
    finally:
        site.close()

print("PASS guide: login return, noindex, favicon, modules, roles, site chapters, images, switch, English, Pop default and legacy Soft")
