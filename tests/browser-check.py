#!/usr/bin/env python3
"""Optional UI checks. Requires a local Playwright/Chromium installation."""
import os
from pathlib import Path
import subprocess
import tempfile
from support import ROOT, Site

with tempfile.TemporaryDirectory(prefix="nibbly-browser-review-") as folder:
    site = Site(folder).start()
    screenshots = Path(tempfile.mkdtemp(prefix="nibbly-browser-evidence-"))
    try:
        environment = dict(os.environ, REVIEW_PASSWORD=site.password,
                           REVIEW_URL=f"http://127.0.0.1:{site.port}", REVIEW_SCREENSHOTS=str(screenshots))
        subprocess.run(["node", str(ROOT / "tests/browser-check.js")], env=environment, cwd=ROOT, check=True, timeout=60)
        print("Screenshots:", screenshots)
    finally:
        site.close()
