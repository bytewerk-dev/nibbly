#!/usr/bin/env python3
"""The dashboard's inline scripts, as PHP renders them for a logged-in admin, must parse (node --check)."""
import re
import subprocess
import tempfile
from pathlib import Path
from support import Client, Site

with tempfile.TemporaryDirectory(prefix='nibbly-dashboard-scripts-') as folder:
    site = Site(folder).start()
    try:
        client = Client(site).login('admin')
        status, _, body = client.request('/admin/dashboard.php')
        assert status == 200, status
        html = body.decode('utf-8')
        scripts = re.findall(r'<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)</script>', html)
        assert len(scripts) >= 2, f'expected inline scripts, found {len(scripts)}'
        for index, source in enumerate(scripts):
            path = Path(folder) / f'dashboard-inline-{index}.js'
            path.write_text(source, encoding='utf-8')
            result = subprocess.run(['node', '--check', str(path)], capture_output=True, text=True)
            assert result.returncode == 0, f'inline script {index} does not parse:\n{result.stderr[:1200]}'
        print(f'PASS dashboard scripts: {len(scripts)} rendered inline scripts parse ({sum(len(s) for s in scripts) // 1024} KB)')
    finally:
        site.close()
