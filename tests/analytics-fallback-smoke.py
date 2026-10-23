#!/usr/bin/env python3
"""Visits count exactly once per request, also when header.php lacks the tracking call; 404 and admins are not visits."""
import datetime
import json
import tempfile
from support import Site, Client, write_json

BROWSER = {"User-Agent": "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36",
           "Accept": "text/html,application/xhtml+xml"}


def views(site, key=None):
    path = site.root / "content/analytics/days" / (str(datetime.date.today()) + ".json")
    if not path.is_file():
        return 0
    bucket = json.loads(path.read_text())["days"][str(datetime.date.today())]
    if key is None:
        return bucket.get("views", 0)
    return (bucket.get("pages", {}).get(key) or {}).get("views", 0)


with tempfile.TemporaryDirectory(prefix="nibbly-analytics-fallback-") as folder:
    site = Site(folder)
    write_json(site.root / "content/settings.json", {"modules": {"ai": False}, "privacy": {"analyticsEnabled": True}})
    site.start()
    try:
        visitor = Client(site)

        # 1. The regular header counts once per request, not once per call site.
        status, _, body = visitor.request("/services/test/", headers=BROWSER)
        assert status == 200 and b"Public fixture" in body, status
        assert views(site) == 1, f"one request counts one view, got {views(site)}"
        assert views(site, "en_services__test") == 1, "the page key is the content key"
        visitor.request("/", headers=BROWSER)
        assert views(site) == 2 and views(site, "en_home") == 1, "the home page counts under its own key"

        # 2. A header.php without the tracking call: the router's fallback counts the visit.
        header = site.root / "includes/header.php"
        original = header.read_text()
        stripped = "\n".join(line for line in original.splitlines() if "nibblyAnalyticsTrack(" not in line) + "\n"
        assert stripped != original
        header.write_text(stripped)
        status, _, body = visitor.request("/services/test/", headers=BROWSER)
        assert status == 200 and b"Public fixture" in body, status
        assert views(site) == 3, f"a header without the call still counts via the router, got {views(site)}"
        assert views(site, "en_services__test") == 2, "same page key from the router fallback"
        header.write_text(original)

        # 3. Not visits: a 404, a non-HTML request and a logged-in administrator.
        status, _, _ = visitor.request("/does-not-exist/", headers=BROWSER)
        assert status == 404
        visitor.request("/services/test/", headers={"User-Agent": BROWSER["User-Agent"], "Accept": "application/json"})
        admin = Client(site).login()
        admin.request("/services/test/", headers=BROWSER)
        assert views(site) == 3, f"404, JSON requests and admins do not count, got {views(site)}"
    finally:
        site.close()

print("PASS analytics fallback: one view per request, router counts when header.php lacks the call, 404/JSON/admin excluded")
