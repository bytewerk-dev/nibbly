#!/usr/bin/env python3
"""Search page and RSS feed over HTTP: routing, site override, head links, index refresh after dashboard saves."""
import json
import tempfile
import urllib.parse
from support import Client, Site, write_json


def post(date, slug, **extra):
    return {"id": f"{date}-{slug}", "slug": slug, "lang": "en", "title": f"Post {slug}", "date": date,
            "author": "Fixture", "excerpt": f"Excerpt {slug}", "image": "", "content": f"<p>Body {slug}</p>",
            "hidden": False, **extra}


def seed(site, search=None, news=None):
    settings = {"modules": {"ai": False}, "privacy": {"analyticsEnabled": False},
                "news": {"urlPattern": "/{yyyy}/{mm}/{dd}/{slug}", "perPage": 5, "pageParam": "page",
                         "archive": {"base": "/news", "tag": "topic", "year": "year", "decade": "decade"}, **(news or {})}}
    if search is not None:
        settings["search"] = search
    write_json(site.root / "content/settings.json", settings)
    for file in (site.root / "content/news").glob("*.json"):
        file.unlink()
    for item in [post("2026-08-17", "zebra-stripes", categories=["blog"], tags=["Savanna"]),
                 post("2019-03-04", "zebra-crossing", content="<p>A zebra crossing is a street marking.</p>"),
                 post("2018-01-01", "hidden-zebra", hidden=True)]:
        write_json(site.root / "content/news" / (item["id"] + ".json"), item)
    write_json(site.root / "content/news-categories.json", {"categories": [{"slug": "blog", "name": "Blog"}]})
    write_json(site.root / "content/pages/en_zoo.json", {"page": "en_zoo", "lang": "en", "title": "Zoo guide",
                                                        "sections": [{"id": "s", "type": "text", "content": "<p>Zebra enclosure near the entrance.</p>"}]})


def check_defaults(site):
    guest = Client(site)
    status, headers, body = guest.request("/search?q=zebra")
    assert status == 200, status
    assert b'name="robots" content="noindex' in body, "search pages are not indexed"
    assert body.count(b'class="search-result ') == 3, body.count(b'class="search-result ')
    assert b"3 results" in body and b"<mark>Zebra</mark>" in body and b"<mark>zebra</mark>" in body, "count and highlighting"
    results = body[body.find(b'class="search-results"'):]
    assert results.find(b"zebra-stripes") < results.find(b"zebra-crossing") < results.find(b'href="zoo"'), "equal title hits by date, body-only page last"
    assert b"hidden-zebra" not in body
    assert b'rel="alternate" type="application/rss+xml"' in body and b'href="/feed.xml"' in body, "feed link in the head"
    assert b'search-form--header' in body and b'action="/search"' in body, "header search form"
    status, _, body = guest.request("/search")
    assert status == 200 and b"search-result " not in body and b'id="search-page-input"' in body, "empty search shows the form"
    status, _, body = guest.request("/search?q=z")
    assert status == 200 and b"at least 2 characters" in body, "short query hint"
    status, _, body = guest.request("/search?q=nothing-here")
    assert status == 200 and b"Nothing found" in body
    status, headers, _ = guest.request("/search/?q=zebra")
    assert status == 301 and headers["Location"] == "/search?q=zebra", (status, headers.get("Location"))
    # Feed
    status, headers, body = guest.request("/feed.xml")
    assert status == 200 and headers["Content-Type"].startswith("application/rss+xml"), (status, headers.get("Content-Type"))
    assert body.count(b"<item>") == 2 and b"/2026/08/17/zebra-stripes</link>" in body and b"<category>Blog</category>" in body, body[:800]
    assert b"hidden-zebra" not in body
    assert guest.request("/en/feed.xml")[0] == 404, "no language prefix for the primary language"


def check_index_refresh(site):
    admin = Client(site).login()
    status, result = admin.api("save-news", post=json.dumps(post("2026-09-01", "giraffe-news", content="<p>A giraffe and a zebra.</p>")))
    assert status == 200 and result["success"], result
    index = json.loads((site.root / "content/search-index.json").read_text())
    assert any(d["id"] == "2026-09-01-giraffe-news" for d in index["documents"]), "index rebuilt after a post save"
    guest = Client(site)
    status, _, body = guest.request("/search?q=giraffe")
    assert status == 200 and b"giraffe-news" in body, "new post is searchable"
    status, loaded = admin.api("load", page="en_zoo")
    assert status == 200 and loaded["success"], loaded
    status, result = admin.api("save", page="en_zoo", content=json.dumps({"page": "en_zoo", "lang": "en", "title": "Zoo guide", "sections": [{"id": "s", "type": "text", "content": "<p>Okapi enclosure.</p>"}]}), revision=loaded["revision"])
    assert status == 200 and result["success"], result
    status, _, body = guest.request("/search?q=okapi")
    assert status == 200 and b'href="zoo"' in body, "edited page is searchable"
    status, _, body = guest.request("/search?q=zebra")
    assert b'href="zoo"' not in body[body.find(b"search-results"):], "old page text is gone from the index"


def check_custom_base_and_override(site):
    seed(site, search={"base": "/suche", "param": "s", "perPage": 1})
    guest = Client(site)
    assert guest.request("/search?q=zebra")[0] == 404, "default path is free once the base changes"
    status, _, body = guest.request("/suche?s=zebra")
    assert status == 200 and body.count(b'class="search-result ') == 1 and b'class="pagination"' in body, "custom base, parameter and page size"
    assert b'href="/suche?s=zebra&amp;page=2"' in body, body[body.find(b'class="pagination"'):body.find(b'class="pagination"') + 600]
    status, _, body = guest.request("/suche?s=zebra&page=3")
    assert status == 200 and b"zebra" in body and b'aria-current="page" aria-label="Page 3"' in body, "page 3 of 3"
    # Site override template
    (site.root / "en").mkdir(exist_ok=True)
    (site.root / "en/search.php").write_text("<?php require_once dirname(__DIR__) . '/includes/search-helper.php'; $r = nibblySearchQuery(nibblySearchRequestQuery(), ['lang' => $currentLang]); echo 'OVERRIDE ' . $r['total'] . ' ' . $basePath;")
    status, _, body = guest.request("/suche?s=zebra")
    assert status == 200 and body.strip() == b"OVERRIDE 3", body[:200]
    (site.root / "en/search.php").unlink()
    # A JSON page at the base path wins
    write_json(site.root / "content/pages/en_suche.json", {"page": "en_suche", "lang": "en", "title": "Own page", "sections": [{"id": "s", "type": "text", "content": "<p>Own search page</p>"}]})
    status, _, body = guest.request("/suche?s=zebra")
    assert status == 200 and b"Own search page" in body
    (site.root / "content/pages/en_suche.json").unlink()
    # Switched off: no page, no header form
    seed(site, search={"enabled": False}, news={"feed": False})
    assert guest.request("/search?q=zebra")[0] == 404
    status, _, body = guest.request("/zoo")
    assert status == 200 and b"search-form--header" not in body and b"application/rss+xml" not in body, "no search form or feed link when switched off"
    assert guest.request("/feed.xml")[0] == 404, "feed off"


with tempfile.TemporaryDirectory(prefix="nibbly-search-") as folder:
    site = Site(folder)
    seed(site)
    try:
        site.start()
        check_defaults(site)
        check_index_refresh(site)
        check_custom_base_and_override(site)
    finally:
        site.close()
print("PASS search-feed-smoke")
