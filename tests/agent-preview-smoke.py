#!/usr/bin/env python3
"""Preview of an AI change set on the real page: only for its owner, read-only, without the editor."""
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
from support import Client, Site

with tempfile.TemporaryDirectory(prefix="nibbly-preview-") as folder:
    site = Site(folder).start()
    try:
        root = site.root

        def php(code):
            result = subprocess.run(["php", "-d", "display_errors=0", "-r",
                                     "define('SITE_LANG_DEFAULT','en'); require 'includes/ai/agent/changeset-apply.php'; " + code],
                                    cwd=root, text=True, capture_output=True)
            assert result.returncode == 0, result.stdout + result.stderr
            return result.stdout

        def content_hashes():
            files = list((root / "content/pages").glob("*.json")) + list((root / "content/news").glob("*.json")) + [root / "content/events.json"]
            return {f.name: hashlib.sha256(f.read_bytes()).hexdigest() for f in files if f.exists()}

        owner = Client(site).login("editor")
        other = Client(site).login("admin")
        anonymous = Client(site)

        def get(client, path):
            status, headers, body = client.request(path)
            return status, {k.lower(): v for k, v in headers.items()}, body.decode("utf-8", "replace")

        # Opening a page as an editor completes missing fields in its JSON (auto-write). That is
        # normal; do it first, so the change set below starts from a settled file.
        status, headers, live = get(owner, "/services/test")
        assert status == 200 and "Public fixture" in live and "inline-editor.js" in live and "nb-agent-preview-banner" not in live, "live page for the editor"
        assert "noindex" not in headers.get("x-robots-tag", ""), headers

        staged = json.loads(php("""
            $cs = nibblyAgentChangesetCreate(['owner' => 'u_editor', 'runId' => 'preview']);
            $id = $cs['id'];
            $r = [];
            $r[] = nibblyAgentChangesetStage($id, ['type' => 'set_field', 'target' => 'page:en_services__test', 'path' => 'sections.0.content', 'value' => '<p>Preview content from the agent</p>']);
            $r[] = nibblyAgentChangesetStage($id, ['type' => 'create_news', 'lang' => 'en', 'title' => 'Agent preview news', 'slug' => 'agent-preview-news', 'date' => '2026-10-10', 'excerpt' => 'Preview excerpt', 'contentHtml' => '<p>News body from the agent</p>', 'imagePath' => '/assets/images/placeholder-image.webp']);
            $r[] = nibblyAgentChangesetStage($id, ['type' => 'create_page', 'lang' => 'en', 'slug' => 'agent-new', 'title' => 'Agent new page', 'sections' => [['type' => 'text', 'fields' => ['title' => 'New page headline', 'content' => '<p>Private draft body</p>']]]]);
            echo json_encode(['id' => $id, 'results' => $r, 'finalize' => nibblyAgentChangesetFinalize($id)]);
        """))
        cs = staged["id"]
        assert all(r.get("ok") for r in staged["results"]), staged["results"]
        assert staged["finalize"]["status"] == "ready", staged["finalize"]
        before = content_hashes()
        url = f"/services/test?nb_agent_preview={cs}"


        # The owner sees the package on the real page
        status, headers, preview = get(owner, url)
        assert status == 200 and "Preview content from the agent" in preview and "Public fixture" not in preview, "overlay content: %s %s" % (status, (root / "server.log").read_text()[-1500:])
        assert "nb-agent-preview-banner" in preview and "have not been applied yet" in preview, "banner"
        assert "inline-editor.js" not in preview and 'name="csrf-token"' not in preview and "ai-copilot.js" not in preview, "the inline editor must not load in a preview"
        assert headers.get("x-robots-tag") == "noindex, nofollow" and "no-store" in headers.get("cache-control", ""), headers

        # Hidden news and a private, not yet existing page are visible in the preview only
        status, _, body = get(owner, f"/news/agent-preview-news?nb_agent_preview={cs}")
        assert status == 200 and "Agent preview news" in body and "News body from the agent" in body, "preview of a hidden news post (status %s)" % status
        status, _, body = get(owner, "/news/agent-preview-news")
        assert status == 404, "the hidden post does not exist without the preview"
        status, _, body = get(owner, f"/agent-new?nb_agent_preview={cs}")
        assert status == 200 and "Private draft body" in body and "New page headline" in body, "preview of a new page (status %s)" % status
        assert get(owner, "/agent-new")[0] == 404, "the new page does not exist without the preview"

        # Everyone else gets the live content: other users, visitors, malformed or unknown ids
        for label, client, path in [("another user", other, url), ("a visitor", anonymous, url),
                                    ("a malformed id", owner, "/services/test?nb_agent_preview=../../x"),
                                    ("an unknown id", owner, "/services/test?nb_agent_preview=cs_0123456789abcdef")]:
            status, headers, body = get(client, path)
            assert status == 200 and "Public fixture" in body and "Preview content" not in body and "nb-agent-preview-banner" not in body, f"{label} must see the live page"
            assert "noindex" not in headers.get("x-robots-tag", ""), label
        assert get(anonymous, f"/news/agent-preview-news?nb_agent_preview={cs}")[0] == 404, "a visitor cannot see the hidden post through the preview"
        assert get(anonymous, f"/agent-new?nb_agent_preview={cs}")[0] == 404, "a visitor cannot see the new page through the preview"

        # A package that is no longer ready, or whose base changed, is not previewed
        page_file = root / "content/pages/en_services__test.json"
        original = page_file.read_bytes()
        page_file.write_text(page_file.read_text().replace("Public fixture", "Edited elsewhere"))
        status, _, body = get(owner, url)
        assert status == 200 and "Edited elsewhere" in body and "Preview content" not in body and "nb-agent-preview-banner" not in body, "a changed base falls back to the live page"
        page_file.write_bytes(original)
        assert "Preview content" in get(owner, url)[2], "preview works again once the base matches"
        php(f"nibblyAgentChangesetDiscard('{cs}', ['userKey' => 'u_editor', 'isAdmin' => false]);")
        assert "Preview content" not in get(owner, url)[2], "a discarded package is not previewed"

        # Nothing was written by any of this
        assert content_hashes() == before, "previews must not change content"
        assert not (root / "content/analytics").exists() or not list((root / "content/analytics").rglob("*.json")), "previews are not visits"
        print("PASS preview: owner-only overlay on pages, hidden news and new pages, no editor, noindex/no-store, banner; others and stale packages see live content; nothing written")
    finally:
        site.close()
