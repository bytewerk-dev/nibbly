#!/usr/bin/env python3
"""Post URL pattern (news.urlPattern), slug routing with 301s, aliases, archive pages, pagination and sitemap."""
import argparse
import json
import re
import tempfile
import urllib.parse
from support import Client, Site, write_json


def post(date, slug, **extra):
    return {"id": f"{date}-{slug}", "slug": slug, "lang": "en", "title": f"Post {slug}", "date": date,
            "author": "Fixture", "excerpt": f"Excerpt {slug}", "image": "", "content": f"<p>Body {slug}</p>",
            "hidden": False, **extra}


def seed(site, pattern, archive_base="/news", page_param="page", per_page=5):
    write_json(site.root / "content/settings.json", {
        "modules": {"ai": False}, "privacy": {"analyticsEnabled": False},
        "news": {"urlPattern": pattern, "perPage": per_page, "pageParam": page_param,
                 "archive": {"base": archive_base, "tag": "topic", "year": "year", "decade": "decade"}}})
    for file in (site.root / "content/news").glob("*.json"):
        file.unlink()
    posts = [post("2026-08-17", "target-post", categories=["blog"], tags=["HOSI Wien", "oevp"], flags=["column"]),
             post("2026-08-16", "hidden-post", hidden=True, categories=["blog"]),
             post("1999-03-04", "old-article", categories=["articles"], tags=["oevp"])]
    for index in range(12):
        posts.append(post(f"2001-01-{index + 1:02d}", f"ln-{index + 1:02d}", categories=["ln"], flags=["column"] if index % 2 else []))
    for item in posts:
        write_json(site.root / "content/news" / (item["id"] + ".json"), item)
    write_json(site.root / "content/news-categories.json", {"categories": [
        {"slug": "blog", "name": "Blog", "order": 2}, {"slug": "articles", "name": "Articles", "description": "Printed", "order": 1},
        {"slug": "ln", "name": "LN", "order": 3}, {"slug": "empty", "name": "Empty", "order": 4}]})
    write_json(site.root / "content/news-aliases.json", {"target-post-old": "target-post", "older-alias": "target-post-old"})
    write_json(site.root / "content/news-tags.json", {"tags": {"hosi-wien": "HOSI Wien"}})


def location(headers):
    return urllib.parse.urlsplit(headers.get("Location", "")).path + (("?" + urllib.parse.urlsplit(headers["Location"]).query) if "?" in headers.get("Location", "") else "")


def check_date_pattern(site):
    guest = Client(site)
    status, _, body = guest.request("/2026/08/17/target-post")
    assert status == 200 and b"Body target-post" in body, status
    assert b'href="../../../news/blog"' in body, "category chip links to the archive page relative to the post"
    assert b'href="../../../news/topic/hosi-wien"' in body and b">HOSI Wien<" in body, "tag chip with its stored name"
    assert re.search(rb'<link rel="canonical" href="[^"]*/2026/08/17/target-post"', body), "canonical URL follows the pattern"
    # Wrong date, old slug (also chained), trailing slash: 301 to the canonical address, query kept
    for source in ("/2020/01/01/target-post", "/2026/08/17/target-post-old", "/2026/08/17/older-alias", "/1999/12/31/older-alias", "/2026/08/17/target-post/"):
        status, headers, _ = guest.request(source + "?utm=1")
        assert status == 301 and location(headers) == "/2026/08/17/target-post?utm=1", (source, status, headers.get("Location"))
    for source in ("/2026/08/17/no-such-post", "/2026/08/16/hidden-post", "/news/target-post", "/2026/8/17/target-post"):
        assert guest.request(source)[0] == 404, source
    # A physical folder named like the date (mirrored uploads, exports) must not capture post URLs
    (site.root / "2026/08/17").mkdir(parents=True, exist_ok=True)
    (site.root / "2026/08/17/index.html").write_text("folder listing")
    status, _, body = guest.request("/2026/08/17/target-post")
    assert status == 200 and b"Body target-post" in body, ("post beside a physical date folder", status)
    status, headers, _ = guest.request("/2026/08/17/target-post/")
    assert status == 301 and location(headers) == "/2026/08/17/target-post", (status, headers.get("Location"))
    # A JSON page at a date-like path still wins when no post matches
    write_json(site.root / "content/pages/en_2026__08__17__page-here.json", {"page": "en_2026__08__17__page-here", "lang": "en", "title": "Page", "sections": [{"id": "s", "type": "text", "content": "<p>Page wins</p>"}]})
    status, _, body = guest.request("/2026/08/17/page-here")
    assert status == 200 and b"Page wins" in body
    sitemap = guest.request("/sitemap.xml")[2]
    assert b"/2026/08/17/target-post</loc>" in sitemap and b"/1999/03/04/old-article</loc>" in sitemap
    assert b"hidden-post" not in sitemap and b"/news/target-post" not in sitemap
    assert b"/news/blog</loc>" in sitemap and b"/news/articles</loc>" in sitemap and b"/news/empty" not in sitemap, "used categories are in the sitemap"


def check_archives(site):
    guest = Client(site)
    status, _, body = guest.request("/news")
    assert status == 200 and body.count(b'class="news-card"') == 5 and b'class="pagination"' in body, "list page 1"
    assert b'href="/news?page=2"' in body and b'rel="next"' in body and b"Page 1 of 3" in body, "page links"
    assert b'<link rel="next" href="/news?page=2">' in body and b'<link rel="prev"' not in body
    status, _, body = guest.request("/news?page=3")
    assert status == 200 and body.count(b'class="news-card"') == 4, "last page holds the rest"
    assert b'<link rel="prev" href="/news?page=2">' in body and b'<link rel="next"' not in body
    assert b"Body ln-01" not in body and b"Excerpt old-article" in body, "oldest post is on the last page"
    assert b"Page 3 of 3" in guest.request("/news?page=99")[2], "a page beyond the end shows the last page"
    assert guest.request("/news/")[0] == 301
    # Category, tag, year, decade and flag-independent lists
    status, _, body = guest.request("/news/ln")
    assert status == 200 and body.count(b'class="news-card"') == 5 and b"Page 1 of 3" in body
    assert b'aria-current="page"' in body and b">LN<" in body
    assert guest.request("/news/ln?page=2")[2].count(b'class="news-card"') == 5
    status, _, body = guest.request("/news/articles")
    assert status == 200 and body.count(b'class="news-card"') == 1 and b"Printed" in body
    assert guest.request("/news/empty")[0] == 200, "a defined category without posts is still a page"
    assert guest.request("/news/unknown-category")[0] == 404
    status, _, body = guest.request("/news/topic/oevp")
    assert status == 200 and body.count(b'class="news-card"') == 2
    assert guest.request("/news/topic/hosi-wien")[2].count(b'class="news-card"') == 1
    assert guest.request("/news/topic/nothing")[0] == 404
    status, _, body = guest.request("/news/year/2001")
    assert status == 200 and b"Page 1 of 3" in body
    assert guest.request("/news/year/1999")[2].count(b'class="news-card"') == 1
    assert guest.request("/news/year/1980")[0] == 404
    assert guest.request("/news/decade/1990")[2].count(b'class="news-card"') == 1
    assert guest.request("/news/decade/2020")[2].count(b'class="news-card"') == 1, "hidden posts never count"
    assert guest.request("/news/decade/1995")[0] == 404, "a decade ends with 0"
    # Hidden posts are not listed anywhere
    for path in ("/news", "/news?page=2", "/news?page=3", "/news/blog"):
        assert b"hidden-post" not in guest.request(path)[2], path


def check_default_pattern(site):
    """The default /news/{slug} keeps its behaviour, with /news/{category} archives beside it."""
    guest = Client(site)
    status, _, body = guest.request("/news/target-post")
    assert status == 200 and b"Body target-post" in body
    assert guest.request("/2026/08/17/target-post")[0] == 404
    status, headers, _ = guest.request("/news/target-post-old")
    assert status == 301 and location(headers) == "/news/target-post"
    status, headers, _ = guest.request("/news/target-post/")
    assert status == 301 and location(headers) == "/news/target-post"
    assert guest.request("/news/blog")[2].count(b'class="news-card"') == 1, "category archive beside the post route"
    assert b"/news/target-post</loc>" in guest.request("/sitemap.xml")[2]
    # The physical listing template en/news.php, when present, serves /news itself
    (site.root / "en").mkdir(exist_ok=True)
    (site.root / "en/news.php").write_text("<?php $currentLang='en'; $basePath='../'; include dirname(__DIR__).'/includes/header.php'; echo '<main>Template list</main>'; include dirname(__DIR__).'/includes/footer.php';")
    status, _, body = guest.request("/news")
    assert status == 200 and b"Template list" in body
    (site.root / "en/news.php").unlink()


def check_archive_off(site):
    guest = Client(site)
    assert guest.request("/news/blog")[0] == 404 and guest.request("/news/topic/oevp")[0] == 404
    assert guest.request("/news/target-post")[0] == 200


def check_dashboard(site):
    admin = Client(site).login()
    posts = json.loads(admin.request("/admin/api.php?action=load-news")[2])["data"]
    target = next(item for item in posts if item["slug"] == "target-post")
    assert target["url"] == "/2026/08/17/target-post" and target["categories"] == ["blog"] and target["tags"] == ["hosi-wien", "oevp"], target
    # The list carries metadata only; the editor loads the complete record per post
    assert "content" not in target and "hidden" in target and "excerpt" in target, target
    full = json.loads(admin.request("/admin/api.php?action=load-news-post&id=" + target["id"])[2])
    assert full["success"] and full["data"]["content"] and full["data"]["url"] == target["url"], full
    assert admin.request("/admin/api.php?action=load-news-post&id=../users")[0] == 400
    assert admin.request("/admin/api.php?action=load-news-post&id=2000-01-01-missing")[0] == 404
    config = json.loads(admin.request("/admin/api.php?action=load-news-config")[2])["data"]
    assert config["settings"]["urlPattern"] == "/{yyyy}/{mm}/{dd}/{slug}" and [c["slug"] for c in config["categories"]] == ["articles", "blog", "ln", "empty"]
    assert config["tagNames"].get("hosi-wien") == "HOSI Wien" and config["tagCounts"] == {"oevp": 2, "hosi-wien": 1}
    saved = admin.api("save-news", post=json.dumps({"id": "", "lang": "en", "title": "Editor post", "slug": "editor-post", "date": "2026-09-01",
                                                      "content": "<p>x</p>", "categories": ["blog", "Not A Slug!"], "tags": "Jörg Haider, ÖVP", "flags": ["Column"],
                                                      "seo": {"title": "Kept"}}))[1]
    assert saved["success"], saved
    assert saved["data"]["url"] == "/2026/09/01/editor-post" and saved["data"]["categories"] == ["blog", "not-a-slug"]
    assert saved["data"]["tags"] == ["joerg-haider", "oevp"] and saved["data"]["flags"] == ["column"] and saved["data"]["seo"] == {"title": "Kept"}
    names = json.loads((site.root / "content/news-tags.json").read_text())["tags"]
    assert names["joerg-haider"] == "Jörg Haider" and names["oevp"] == "ÖVP", names
    assert Client(site).request("/2026/09/01/editor-post")[0] == 200
    # Categories and settings are saved through the API; invalid values fall back
    assert admin.api("save-news-categories", categories=json.dumps([{"slug": "Blog", "name": "Weblog", "order": 1}, {"slug": "", "name": ""}, {"slug": "ln", "name": "LN"}]))[1]["data"] == [
        {"slug": "blog", "name": "Weblog", "description": "", "order": 1}, {"slug": "ln", "name": "LN", "description": "", "order": 2}]
    editor = Client(site).login("editor")
    assert not editor.api("save-news-categories", categories="[]")[1]["success"], "editors cannot change categories"
    revision = json.loads(admin.request("/admin/api.php?action=load-settings")[2])["revision"]
    result = admin.api("save-settings", revision=revision, settings=json.dumps({"news": {"urlPattern": "/blog/{yyyy}/{slug}", "perPage": 999, "pageParam": "Seite!", "archive": {"base": "/blog/", "tag": "thema"}}}))[1]
    assert result["success"] and result["data"]["news"] == {"urlPattern": "/blog/{yyyy}/{slug}", "perPage": 200, "pageParam": "page",
                                                           "archive": {"base": "/blog", "tag": "thema", "year": "year", "decade": "decade"}}, result["data"].get("news")
    assert Client(site).request("/blog/2026/target-post")[0] == 200 and Client(site).request("/blog/blog")[0] == 200
    status, headers, _ = Client(site).request("/2026/08/17/target-post")
    assert status == 404, "the old pattern is not a route any more"


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--apache", action="store_true", help="run against Apache + PHP-FPM with the candidate .htaccess")
    args = parser.parse_args()
    implementation = Site
    if args.apache:
        from apache_support import ApacheSite
        implementation = ApacheSite
    with tempfile.TemporaryDirectory(prefix="nibbly-news-") as folder:
        site = implementation(folder)
        try:
            seed(site, "/{yyyy}/{mm}/{dd}/{slug}")
            site.start()
            check_date_pattern(site)
            check_archives(site)
            seed(site, "/news/{slug}")
            check_default_pattern(site)
            seed(site, "/news/{slug}", archive_base="")
            check_archive_off(site)
            seed(site, "/{yyyy}/{mm}/{dd}/{slug}")
            check_dashboard(site)
        finally:
            site.close()
    if args.apache:
        print(site.server_version + "; " + site.php_version)
    print("PASS news routing: date pattern, wrong date/alias/slash 301s, pages beside posts, archives (category/tag/year/decade), pagination, sitemap, default pattern, archives off, editor API and settings")
