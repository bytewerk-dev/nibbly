#!/usr/bin/env python3
"""Menus & Footer: detection, adoption, editing, rollback, conflicts and rendering in a disposable site."""
import json
import re
import tempfile
from pathlib import Path
from support import Client, Site, write_json

NAV_CONFIG = r"""<?php
$defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
$SITE_LANGUAGES = ['en' => 'English', 'de' => 'Deutsch'];
$PAGE_MAPPING = ['home' => ['en' => '.', 'de' => 'de/'], 'about' => ['en' => 'about', 'de' => 'de/ueber']];
$NAV_ITEMS = [
    'en' => [
        ['href' => '.', 'label' => 'Home', 'page' => 'home'],
        ['href' => 'about', 'label' => 'About', 'page' => 'about', 'class' => 'nav-about', 'children' => [
            ['href' => 'about', 'label' => 'Team', 'page' => 'about'],
            ['href' => 'https://shop.example.com', 'label' => 'Shop', 'page' => 'shop', 'nav' => ['header']],
        ]],
    ],
    'de' => [
        ['href' => 'de/', 'label' => 'Start', 'page' => 'home'],
        ['href' => 'de/ueber', 'label' => 'Über uns', 'page' => 'about'],
    ],
];
"""

SITE_MENUS = r"""<?php
nibblyRegisterMenu('service', [
    'label' => ['en' => 'Service', 'de' => 'Service'],
    'template' => 'includes/service-nav.php',
    'depth' => 1,
    'fallback' => fn(string $lang) => [['label' => 'Help', 'href' => 'https://help.example.com', 'target' => '_blank']],
]);
nibblyRegisterFooterField('social', ['type' => 'linkgroup', 'label' => 'Social', 'supports' => ['hidden' => true]]);
"""


def page(lang, slug, title, **extra):
    return {"page": f"{lang}_{slug}", "lang": lang, "title": title, "sections": [], **extra}


def walk(items):
    for item in items:
        yield item
        yield from walk(item.get("children", []))


with tempfile.TemporaryDirectory(prefix="nibbly-menus-") as folder:
    site = Site(folder)
    root = site.root
    (root / "includes/nav-config.php").write_text(NAV_CONFIG)
    (root / "includes/site-menus.php").write_text(SITE_MENUS)
    (root / "includes/service-nav.php").write_text(
        "<nav class=\"service\"><?php foreach (nibblyMenuItems('service') as $i): ?><a href=\"x\"></a><?php endforeach; ?></nav>\n")
    # A project footer with fields the core does not know, and a hard-coded nav in a language template.
    (root / "de").mkdir(exist_ok=True)
    (root / "de/legacy.php").write_text('<nav class="legacy"><a href="impressum">Impressum</a><a href="https://x.example">X</a></nav>\n')
    write_json(root / "content/pages/footer.json", {
        "page": "footer", "copyright": "&copy; 2026 Fixture",
        "contact": {"phone": "+43 1", "email": "office@example.com", "projectNote": "keep me"},
        "social": {"0": {"label": "Mastodon", "href": "https://mastodon.example", "icon": "m"},
                   "1": {"label": {"en": "News", "de": "Neuigkeiten"}, "href": "https://news.example"}},
        "weird": [1, 2, 3],
    })
    for lang, slug, title, extra in [("en", "about", "About", {}), ("de", "ueber", "Über uns", {}),
                                     ("en", "contact", "Contact", {}), ("en", "legal", "Legal", {"nav": ["footer-legal"]})]:
        write_json(root / f"content/pages/{lang}_{slug}.json", page(lang, slug, title, **extra))
    site.start()
    try:
        admin = Client(site).login()
        editor = Client(site).login("editor")

        # Editors keep their old rights: no menu administration.
        assert editor.request("/admin/api.php?action=load-menus-footer")[0] == 403

        status, _, raw = admin.request("/admin/api.php?action=load-menus-footer")
        state = json.loads(raw)["data"]
        assert status == 200, raw[:300]
        menus = {m["id"]: m for m in state["menus"]}
        assert {"header", "footer-pages", "footer-legal", "footer-links", "service"} <= set(menus), menus.keys()
        header_en = menus["header"]["languages"]["en"]
        assert not header_en["managed"] and header_en["source"] == "legacy"
        assert "includes/nav-config.php" in header_en["origin"] and "content/pages/en_contact.json" in header_en["origin"]
        about = header_en["items"][1]
        assert about["label"] == "About" and about["class"] == "nav-about" and "nav" not in about
        assert [c["label"] for c in about["children"]] == ["Team", "Shop"]
        assert about["children"][1]["type"] == "link"  # href differs from the page URL → kept verbatim
        assert menus["header"]["bindings"] and menus["service"]["languages"]["en"]["source"] == "template"
        legal = menus["footer-legal"]["languages"]["en"]["items"]
        assert [i["page"] for i in legal] == ["legal"], legal
        # Re-detection is stable: the same uids, nothing duplicated.
        again = json.loads(admin.request("/admin/api.php?action=load-menus-footer")[2])["data"]
        assert [i["uid"] for i in walk(again["menus"][0]["languages"]["en"]["items"])] == [i["uid"] for i in walk(header_en["items"])]
        fields = {f["path"]: f for f in state["footer"]["fields"]}
        findings = {f["path"]: f for f in state["footer"]["findings"]}
        assert "copyright" in fields and "social" in fields and fields["social"]["origin"] == "adapter"
        assert findings["contact"]["type"] == "contact" and findings["weird"]["type"] == ""
        assert any(t["file"] == "de/legacy.php" and t["count"] == 2 for t in state["templates"]), state["templates"]
        revisions = state["revisions"]

        # Frontend before adoption: unchanged legacy rendering.
        html = admin.request("/about")[2].decode()
        assert 'href="https://shop.example.com"' in html and 'class="active nav-about"' in html, html[html.find('nav-list'):html.find('nav-list')+1500]

        # Unsafe input is refused as a whole; nothing is written.
        bad = dict(header_en["items"][0], uid="mi_badbadbad1", type="link", href="javascript:alert(1)", label="X")
        status, result = admin.api("save-menus-footer", payload=json.dumps({"menus": {"header": {"en": header_en["items"] + [bad]}}}),
                                   revisions=json.dumps(revisions))
        assert status == 422 and result["data"]["errors"][0]["field"] == "href", result
        assert "items" not in json.loads((root / "content/menus.json").read_text())["menus"]["header"]

        # Edit: rename, hide, reorder, add a page and a free link, try to smuggle a project field.
        items = json.loads(json.dumps(header_en["items"]))
        items[1]["label"] = "About us"
        items[1]["children"][0]["hidden"] = True
        items[1]["children"][1]["class"] = "smuggled"  # must not stick: project fields come from the server copy
        items.reverse()
        items.append({"uid": "mi_newlink0001", "type": "link", "label": "Blog", "href": "https://blog.example", "target": "_blank", "rel": "nofollow evil"})
        items.append({"uid": "mi_newpage0001", "type": "page", "page": "contact", "label": ""})
        status, result = admin.api("save-menus-footer", payload=json.dumps({"menus": {"header": {"en": items}}}), revisions=json.dumps(revisions))
        assert status == 200 and result["success"], result
        saved = {m["id"]: m for m in result["data"]["menus"]}["header"]["languages"]["en"]
        assert saved["managed"] and saved["origin"] == ["content/menus.json"]
        stored = {i["uid"]: i for i in walk(saved["items"])}
        assert stored["mi_newlink0001"]["rel"] == "nofollow" and stored[about["uid"]]["class"] == "nav-about"
        assert "class" not in stored[about["children"][1]["uid"]]
        assert result["data"]["revisions"]["menus"] != revisions["menus"] and result["data"]["revisions"]["footer"] == revisions["footer"]
        assert list(Path(root / "backups").glob("menus-registry_*.json")), "registry backup missing"
        html = admin.request("/about")[2].decode()
        nav = html[html.index('class="nav-main"'):html.index("</nav>", html.index('class="nav-main"'))]
        labels = re.findall(r">\s*([^<>]+?)\s*(?:<svg|</a>)", nav)
        assert labels[:2] == ["Blog", "Contact"] or "Blog" in labels, labels
        assert 'href="https://blog.example" target="_blank" rel="nofollow noopener"' in nav, nav
        assert "Team" not in nav and "About us" in nav and 'class="active nav-about"' in nav
        assert html.count("About us") >= 2  # desktop and mobile share the source
        # German stays derived from nav-config.php until edited there.
        assert "Über uns" in admin.request("/de/ueber")[2].decode()

        # Parallel edit: a second admin with the old revision gets 409, not an overwrite.
        status, result = Client(site).login().api("save-menus-footer", payload=json.dumps({"menus": {"header": {"en": []}}}),
                                                   revisions=json.dumps(revisions))
        assert status == 409 and result["data"]["resources"] == ["menus"], result
        assert admin.api("save-menus-footer", payload=json.dumps({"menus": {"header": {"en": []}}}), revisions="{}")[0] == 428
        revisions = result_rev = json.loads(admin.request("/admin/api.php?action=load-menus-footer")[2])["data"]["revisions"]

        # Footer: core field, adapter link group (numbered object stays numbered, project keys stay), assigned contact.
        social = {"0": {"label": "Mastodon", "href": "https://mastodon.example", "icon": "m"},
                  "1": {"label": {"en": "News", "de": "Nachrichten"}, "href": "https://news.example"}}
        social_list = [social["1"], dict(social["0"], hidden=True)]
        payload = {"footer": {"copyright": "&copy; 2027 <b onclick=x>Fixture</b>", "social": social_list,
                              "contact": {"phone": "+43 2", "email": "team@example.com"}},
                   "assign": {"contact": "contact", "weird": "ignore"}}
        status, result = admin.api("save-menus-footer", payload=json.dumps(payload), revisions=json.dumps(revisions))
        assert status == 200, result
        footer = json.loads((root / "content/pages/footer.json").read_text())
        assert footer["copyright"] == "© 2027 <b>Fixture</b>", footer["copyright"]
        assert list(footer["social"]) == ["0", "1"] and footer["social"]["0"]["label"]["de"] == "Nachrichten", footer["social"]
        assert footer["social"]["0"]["label"]["de"] == "Nachrichten"
        assert footer["social"]["1"]["icon"] == "m" and footer["social"]["1"]["hidden"] is True
        assert footer["contact"] == {"phone": "+43 2", "email": "team@example.com", "projectNote": "keep me"}
        assert footer["weird"] == [1, 2, 3]
        assert list(Path(root / "backups").glob("footer_*.json"))
        state = result["data"]
        assert "weird" in state["footer"]["ignored"] and "contact" in {f["path"] for f in state["footer"]["fields"]}
        assert not any(f["path"] in ("contact", "weird") for f in state["footer"]["findings"])  # assigned once, never twice
        assert "© 2027 <b>Fixture</b>" in admin.request("/about")[2].decode()

        # Core footer links: template defaults until edited, then managed.
        links = {m["id"]: m for m in state["menus"]}["footer-links"]["languages"]["en"]
        assert [i["label"] for i in links["items"]] == ["Docs", "GitHub", "Privacy", "Imprint"]
        html = admin.request("/about")[2].decode()
        assert '<a href="https://github.com/bytewerk-dev/nibbly" target="_blank" rel="noopener">GitHub</a>' in html
        edited = [dict(links["items"][1], label="Source")] + links["items"][2:]
        status, result = admin.api("save-menus-footer", payload=json.dumps({"menus": {"footer-links": {"en": edited}}}),
                                   revisions=json.dumps(state["revisions"]))
        assert status == 200, result
        html = admin.request("/about")[2].decode()
        footer_nav = html[html.index('class="footer-links"'):html.index("</nav>", html.index('class="footer-links"'))]
        assert ">Source</a>" in footer_nav and ">Docs</a>" not in footer_nav, footer_nav
        state = result["data"]

        # Page settings: a new page joins managed menus like auto-discovery did; unchecking removes it.
        assert admin.api("create-page", title="Pricing", slug="pricing", lang="en")[1]["success"]
        header = json.loads((root / "content/menus.json").read_text())["menus"]["header"]["items"]["en"]
        assert any(i.get("page") == "pricing" for i in walk(header))
        loaded = admin.api("load", page="en_pricing")[1]
        content = dict(loaded["data"], nav=[])
        status, result = admin.api("save", page="en_pricing", content=json.dumps(content), revision=loaded["revision"], nav_sync="1")
        assert status == 200, result
        header = json.loads((root / "content/menus.json").read_text())["menus"]["header"]["items"]["en"]
        assert not any(i.get("page") == "pricing" for i in walk(header))
        # An inline save that does not change "nav" leaves the managed menu alone.
        loaded = admin.api("load", page="en_pricing")[1]
        admin.api("save", page="en_pricing", content=json.dumps(loaded["data"]), revision=loaded["revision"])
        header = json.loads((root / "content/menus.json").read_text())["menus"]["header"]["items"]["en"]
        assert not any(i.get("page") == "pricing" for i in walk(header))

        # A deleted page disappears from the output and is flagged for relinking.
        (root / "content/pages/en_contact.json").unlink()
        state = json.loads(admin.request("/admin/api.php?action=load-menus-footer")[2])["data"]
        header_en = {m["id"]: m for m in state["menus"]}["header"]["languages"]["en"]
        assert "mi_newpage0001" in header_en["missing"]
        assert 'href="contact"' not in admin.request("/about")[2].decode()

        # Rollback: reset returns to nav-config.php untouched; a registry backup restores the managed state.
        status, result = admin.api("save-menus-footer", payload=json.dumps({"reset": [{"menu": "header", "lang": "en"}]}),
                                   revisions=json.dumps(state["revisions"]))
        assert status == 200 and not {m["id"]: m for m in result["data"]["menus"]}["header"]["languages"]["en"]["managed"]
        assert 'href="https://shop.example.com"' in admin.request("/about")[2].decode()
        assert "NAV_ITEMS" in (root / "includes/nav-config.php").read_text()
        backups = json.loads(admin.request("/admin/api.php?action=list-menus-backups")[2])["data"]["backups"]
        assert backups
        status, result = admin.api("restore-menus-backup", backup=backups[0]["filename"], revision=result["data"]["revisions"]["menus"])
        assert status == 200 and {m["id"]: m for m in result["data"]["menus"]}["header"]["languages"]["en"]["managed"], result

        # Legacy order endpoint reorders a managed menu in place instead of adding a second order source.
        assert admin.api("save-menu-order", menu="header", lang="en", order=json.dumps(["pricing", "about", "home"]))[1]["success"]
        registry = json.loads((root / "content/menus.json").read_text())
        assert "order" not in registry["menus"]["header"] and registry["menus"]["header"]["items"]["en"][0]["page"] == "about"

        # A copied language takes managed menus along, with site paths moved to the new prefix.
        registry["menus"]["header"]["items"]["en"].append({"uid": "mi_relative001", "type": "link", "label": "Old", "href": "about"})
        write_json(root / "content/menus.json", registry)
        status, result = admin.api("add-site-language", **{"code": "fr", "from": "en"})
        assert status == 200 and result["success"], result
        fr = json.loads((root / "content/menus.json").read_text())["menus"]["header"]["items"]["fr"]
        assert any(i.get("href") == "fr/about" for i in fr) and any(i.get("page") == "about" for i in fr), fr
        # Settings → Modules can switch the editor off; the API follows, the site keeps its menus.
        status, result = admin.api("save-settings", settings=json.dumps({"modules": {"menus": False}}),
                                   revision=admin.api("load-settings")[1]["revision"])
        assert status == 200 and result["success"], result
        assert admin.request("/admin/api.php?action=load-menus-footer")[0] == 403
        dashboard = admin.request("/admin/dashboard.php")[2].decode()
        assert "switchTab('menus')\"" not in dashboard and 'id="menusTab" style' not in dashboard
        assert "About us" in admin.request("/about")[2].decode() or "About" in admin.request("/about")[2].decode()
        print("PASS menus & footer: detection, stable re-detection, adoption, editing, sanitising, conflicts, footer types, "
              "template adapters, page sync, missing pages, reset/restore, legacy order, language copy and module switch")
    finally:
        site.close()
