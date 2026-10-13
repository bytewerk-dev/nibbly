#!/usr/bin/env python3
"""Dashboard page list: language versions with translated URL paths belong together ($PAGE_MAPPING)."""
import json
import re
import tempfile
from support import Client, Site, write_json


def write_page(site, lang, slug, title):
    key = lang + "_" + slug.replace("/", "__")
    write_json(site.root / "content/pages" / (key + ".json"), {
        "page": key, "path": slug, "lang": lang, "title": title, "lastModified": "2026-10-01T10:00:00+00:00",
        "sections": [{"id": "intro", "type": "text", "title": title, "content": "<p>" + title + "</p>"}]})


def write_mapping(site, entries):
    lines = ["<?php", "$PAGE_MAPPING = ["]
    for key, paths in entries.items():
        if not isinstance(paths, dict):
            lines.append("    " + json.dumps(key) + " => " + json.dumps(paths) + ",")  # a broken entry
            continue
        lines.append("    " + json.dumps(key) + " => [" + ", ".join(f"{json.dumps(lang)} => {json.dumps(path)}" for lang, path in paths.items()) + "],")
    lines.append("];")
    (site.root / "includes/nav-config.php").write_text("\n".join(lines) + "\n")


def set_default_language(site, lang):
    config = site.root / "admin/config.php"
    config.write_text(re.sub(r"define\('SITE_LANG_DEFAULT', '[a-z]{2}'\)", f"define('SITE_LANG_DEFAULT', '{lang}')", config.read_text()))


def page_list(admin):
    status, payload = admin.api("list-pages")
    assert status == 200 and payload["success"], payload
    return payload["data"]


def by_slug(data):
    return {page["slug"]: page for page in data["pages"]}


def slugs(page):
    return {lang: info["slug"] for lang, info in page["languages"].items()}


def clear_pages(site):
    for file in (site.root / "content/pages").glob("*.json"):
        file.unlink()


def check_direction(site, admin, default, other, label):
    """Default language without URL prefix, other language under /{code}/: the case of a German site with English URLs."""
    set_default_language(site, default)
    clear_pages(site)
    prefix = lambda lang, path: path if lang == default else lang + "/" + path
    mapping = {
        "home": {default: ".", other: other + "/"},
        "services": {default: "leistungen", other: other + "/services"},
        "leistungen": {default: "leistungen", other: other + "/services"},  # alias of the same page
        "team": {default: "team", other: other + "/team"},
        "kontakt": {default: "kontakt", other: other + "/contact"},
        "contact": {default: "kontakt", other: other + "/contact"},
        "about": {default: "ueber-uns", other: other + "/about-us"},
        "pricing": {default: "preise", other: other + "/pricing"},
        "docs": {default: "doku/start", other: other + "/docs/start"},  # nested paths
    }
    write_mapping(site, mapping)
    for lang, slug, title in [
        (default, "home", "Start"), (other, "home", "Home"),
        (default, "leistungen", "Leistungen"), (other, "services", "Services"),
        (default, "team", "Team"), (other, "team", "Team EN"),
        (default, "kontakt", "Kontakt"), (other, "contact", "Contact"),
        (default, "ueber-uns", "Ueber uns"),            # translation missing, mapping names the path
        (other, "pricing", "Pricing"),                  # default version missing, mapping names the path
        (default, "nur-hier", "Nur hier"),              # no mapping at all
        (default, "doku/start", "Doku"), (other, "docs/start", "Docs"),
    ]:
        write_page(site, lang, slug, title)
    data = page_list(admin)
    pages = by_slug(data)
    assert len(data["pages"]) == len(pages) == 8, f"{label}: aliases and translations make one entry each: " + json.dumps(sorted(pages))
    # Different slugs in the two languages: one group, each version with its own slug
    assert slugs(pages["leistungen"]) == {default: "leistungen", other: "services"}, (label, pages["leistungen"])
    assert "targetSlugs" not in pages["leistungen"], "nothing is missing there"
    assert "services" not in pages, "the English slug is not a group of its own"
    # Same slug in both languages, as before
    assert slugs(pages["home"]) == {default: "home", other: "home"} and slugs(pages["team"]) == {default: "team", other: "team"}, label
    # Two mapping keys for one page (alias) still give one group
    assert slugs(pages["kontakt"]) == {default: "kontakt", other: "contact"} and "contact" not in pages, (label, sorted(pages))
    # Nested paths
    assert slugs(pages["doku/start"]) == {default: "doku/start", other: "docs/start"}, (label, pages["doku/start"])
    # A missing translation: "create" with the path the mapping intends
    assert slugs(pages["ueber-uns"]) == {default: "ueber-uns"} and pages["ueber-uns"]["targetSlugs"] == {other: "about-us"}, (label, pages["ueber-uns"])
    # Missing default-language version: the group is named after the version that exists
    assert slugs(pages["pricing"]) == {other: "pricing"} and pages["pricing"]["targetSlugs"] == {default: "preise"}, (label, pages["pricing"])
    # No mapping: nothing is guessed on the server (the dashboard then reuses the slug)
    assert slugs(pages["nur-hier"]) == {default: "nur-hier"} and "targetSlugs" not in pages["nur-hier"], (label, pages["nur-hier"])
    # Titles and the other fields are the ones of the language versions
    assert pages["leistungen"]["languages"][other]["title"] == "Services" and pages["leistungen"]["title"] == "Leistungen", pages["leistungen"]
    assert pages["leistungen"]["languages"][other]["seoHealth"] is not None

    # Creating the missing translation with the intended path (what the dashboard sends) joins the group
    status, payload = admin.api("copy-page", source=default + "_ueber-uns", targetLang=other, slug="about-us")
    assert status == 200 and payload["success"], payload
    pages = by_slug(payload["data"]["pageList"])
    assert slugs(pages["ueber-uns"]) == {default: "ueber-uns", other: "about-us"} and "targetSlugs" not in pages["ueber-uns"], (label, pages["ueber-uns"])
    assert (site.root / "content/pages" / (other + "_about-us.json")).is_file() and (site.root / "content/pages" / (default + "_ueber-uns.json")).is_file(), "files keep their names"
    # Without a mapping the same slug is used and joins by identical name, as before
    status, payload = admin.api("copy-page", source=default + "_nur-hier", targetLang=other, slug="nur-hier")
    assert payload["success"], payload
    assert slugs(by_slug(payload["data"]["pageList"])["nur-hier"]) == {default: "nur-hier", other: "nur-hier"}, label
    # Trashing one version leaves the other and offers to create it again where the mapping wants it
    status, payload = admin.api("delete-page", page=other + "_services")
    assert payload["success"], payload
    pages = by_slug(payload["data"]["pageList"])
    assert slugs(pages["leistungen"]) == {default: "leistungen"} and pages["leistungen"]["targetSlugs"] == {other: "services"}, (label, pages["leistungen"])
    # Duplicating works on the version's own key
    status, payload = admin.api("duplicate-page", source=default + "_leistungen")
    assert payload["success"], payload
    # The direct editor link names the language version's key
    for key in (default + "_leistungen", other + "_about-us", other + "_pricing", default + "_doku__start"):
        status, loaded = admin.request("/admin/api.php?action=load&page=" + key)[0::2]
        assert status == 200 and json.loads(loaded)["success"], (label, key, loaded[:200])
    return True


def check_conflicts(site, admin):
    """A mapping never joins two versions of one language; unrelated same-named pages stay as they are."""
    set_default_language(site, "de")
    clear_pages(site)
    write_mapping(site, {"kontakt": {"de": "kontakt", "en": "en/contact"}, "orphan": {"xx": "xx/foo", "de": "nirgends"}, "broken": "not an array"})
    for lang, slug in [("de", "kontakt"), ("en", "contact"), ("en", "kontakt"), ("es", "kontakt")]:
        write_page(site, lang, slug, lang + " " + slug)
    pages = page_list(admin)["pages"]
    sets = sorted(json.dumps(slugs(page), sort_keys=True) for page in pages)
    # de kontakt + en contact (mapping) + es kontakt (same name as de) are one page; en "kontakt" cannot join (en is taken)
    assert sets == sorted([json.dumps({"de": "kontakt", "en": "contact", "es": "kontakt"}, sort_keys=True), json.dumps({"en": "kontakt"}, sort_keys=True)]), sets
    # No mapping file at all: identical slugs only
    (site.root / "includes/nav-config.php").unlink()
    pages = page_list(admin)["pages"]
    assert sorted(json.dumps(slugs(page), sort_keys=True) for page in pages) == sorted([json.dumps({"de": "kontakt", "en": "kontakt", "es": "kontakt"}, sort_keys=True), json.dumps({"en": "contact"}, sort_keys=True)]), "without a mapping the pages join by slug as before"


with tempfile.TemporaryDirectory(prefix="nibbly-page-list-") as folder:
    site = Site(folder)
    site.start()
    try:
        admin = Client(site).login("admin")
        check_direction(site, admin, "de", "en", "German site with English paths")
        check_direction(site, admin, "en", "de", "English site with German paths")
        check_conflicts(site, admin)
        print("PASS page list languages: translated paths grouped via PAGE_MAPPING (both directions), aliases, identical slugs, missing translations with intended slug, conflicts, copy/trash/duplicate/load by version key")
    finally:
        site.close()
