#!/usr/bin/env python3
"""Optional UI checks. Requires a local Playwright/Chromium installation."""
import os
from pathlib import Path
import subprocess
import tempfile
import sys
import json
from support import ROOT, Site

with tempfile.TemporaryDirectory(prefix="nibbly-browser-review-") as folder:
    site = Site(folder).start()
    script = sys.argv[1] if len(sys.argv) > 1 else 'browser-check.js'
    if script == 'responsive-check.js':
        menu_page = site.root / 'content/pages/en_services__test.json'
        menu_content = json.loads(menu_page.read_text())
        menu_content['title'] = 'Leistungen für internationale Geschäftskunden'
        menu_page.write_text(json.dumps(menu_content))
        settings = json.loads((site.root / 'content/settings.json').read_text())
        settings['modules']['ai'] = True
        settings['email'] = {'method': 'smtp', 'smtpHost': 'smtp.example.invalid', 'recipientEmail': 'test@example.invalid', 'fromEmail': 'site@example.invalid', 'smtpPort': 587, 'smtpEncryption': 'tls'}
        (site.root / 'content/ai-settings.json').write_text(json.dumps({'enabled': True, 'provider': 'kie', 'baseUrl': 'https://api.kie.ai', 'apiKey': 'fixture-only', 'features': {'backendAssistant': False, 'seoTextGeneration': True, 'imageGeneration': True}}))
        settings['backup'] = {'remote_targets': [{'id': 'review-s3', 'type': 's3',
            'name': 'Website backup with a longer descriptive name', 'enabled': False,
            'settings': {'endpoint': 'https://storage.example.invalid', 'region': 'eu-west-1',
                         'bucket': 'website-archive', 'prefix': 'nibbly/production', 'access_key': 'fixture'}}]}
        (site.root / 'content/settings.json').write_text(json.dumps(settings))
    screenshots = Path(tempfile.mkdtemp(prefix="nibbly-browser-evidence-"))
    try:
        environment = dict(os.environ, REVIEW_PASSWORD=site.password,
                           REVIEW_URL=f"http://127.0.0.1:{site.port}", REVIEW_SCREENSHOTS=str(screenshots))
        subprocess.run(["node", str(ROOT / "tests" / script)], env=environment, cwd=ROOT, check=True, timeout=120)
    finally:
        print("Screenshots:", screenshots)
        site.close()
