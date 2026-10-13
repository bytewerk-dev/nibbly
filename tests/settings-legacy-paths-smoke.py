#!/usr/bin/env python3
"""An old invalid path in the settings must not block saving unrelated settings (colours, radius); new invalid ones are still refused."""
import json
import tempfile
from support import Client, Site, write_json

with tempfile.TemporaryDirectory(prefix="nibbly-legacy-paths-") as folder:
    site = Site(folder)
    path = site.root / "content/settings.json"
    # What a hand-edited or imported settings file can contain
    write_json(path, {"modules": {"ai": False}, "privacy": {"analyticsEnabled": False},
                      "favicon": "favicon.svg", "favicon_png": "https://example.org/icon.png",
                      "branding": {"logo": "logo.png", "name": "Legacy"},
                      "seo": {"defaultOgImage": "not-an-image", "siteUrl": "example.org"}})
    site.start()
    try:
        admin = Client(site).login("admin")

        revision = {"value": ""}

        def load():
            status, _, body = admin.request("/admin/api.php?action=load-settings")
            assert status == 200, status
            payload = json.loads(body)
            revision["value"] = payload["revision"]  # saves carry the revision they were loaded at
            return payload["data"]

        def save(settings):
            status, payload = admin.api("save-settings", settings=json.dumps(settings), revision=revision["value"])
            assert status in (200, 400), status  # a refused value answers 400
            revision["value"] = payload.get("revision") or revision["value"]
            return payload

        # The dashboard sends every group along with the change of one tab
        settings = load()
        assert settings["favicon"] == "favicon.svg", "the stored value is what the dashboard gets back"
        settings["theme"]["accentColor"] = "#0b5cad"
        settings["theme"]["buttonRadius"] = 12
        result = save(settings)
        assert result["success"], "colours and radius save although the favicon path is old and invalid: " + json.dumps(result)
        stored = json.loads(path.read_text())
        assert stored["theme"]["accentColor"] == "#0b5cad" and stored["theme"]["buttonRadius"] == 12, stored["theme"]
        assert stored["favicon"] == "favicon.svg" and stored["favicon_png"] == "https://example.org/icon.png" and stored["branding"]["logo"] == "logo.png", "the old values are left as they were"
        assert stored["seo"]["defaultOgImage"] == "not-an-image" and stored["seo"]["siteUrl"] == "example.org", stored["seo"]

        # A changed value has to be valid, whatever else is saved
        for group, key, value, message in [
            (None, "favicon", "other.svg", "Invalid favicon path"),
            (None, "favicon", "/assets/images/../../admin/config.php", "Invalid favicon path"),
            (None, "favicon_png", "javascript:alert(1)", "Invalid favicon path"),
            ("branding", "logo", "http://evil.example/logo.png", "Invalid logo path"),
            ("branding", "adminLogo", "/etc/passwd", "Invalid logo path"),
            ("seo", "defaultOgImage", "/assets/images/x.svg", "Open Graph"),
            ("seo", "siteUrl", "not a url", "Invalid SEO site URL"),
        ]:
            attempt = load()
            if group is None:
                attempt[key] = value
            else:
                attempt[group][key] = value
            result = save(attempt)
            assert not result["success"] and message in result["message"], (key, value, result)
            assert json.loads(path.read_text())["favicon"] == "favicon.svg", "a refused save changes nothing"

        # A valid change works, and so does fixing the old value
        fixed = load()
        fixed["favicon"] = "/assets/images/favicon.svg"
        fixed["favicon_png"] = ""
        fixed["branding"]["logo"] = ""
        assert save(fixed)["success"]
        stored = json.loads(path.read_text())
        assert stored["favicon"] == "/assets/images/favicon.svg" and stored["branding"]["logo"] == "", stored
        # ... after which the strict rule applies to that field again
        again = load()
        again["favicon"] = "favicon.svg"
        assert not save(again)["success"], "an invalid value is refused again once the stored one is valid"
        print("PASS settings with old invalid paths: unrelated saves go through, changed values are still validated")
    finally:
        site.close()
