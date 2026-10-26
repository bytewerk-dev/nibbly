#!/usr/bin/env python3
"""Disposable page-writing fixture: native rich HTML persistence and revision safety.
Use --serve for manual browser coverage of Visual/HTML, undo, blocks and responsive layout.
"""
import json
import sys
import tempfile
import time
from pathlib import Path
from support import Site, Client, write_json

with tempfile.TemporaryDirectory(prefix='nibbly-writing-test-') as folder:
    site = Site(folder)
    html = '<h2>Example heading</h2><p>A <strong>bold</strong> paragraph with <em>emphasis</em> and <a href="https://example.org">a link</a>.</p><ul><li>One</li><li>Two</li></ul>'
    pages = {
        'writing': {'title': 'Writing test', 'sections': [{'id': 'intro', 'type': 'text', 'title': '', 'titleTag': 'h2', 'content': html, 'style': ''}]},
        'multi': {'title': 'Multiple blocks', 'sections': [{'id': 'one', 'type': 'text', 'content': html}, {'id': 'team', 'type': 'team', 'columns': '3', 'group': ''}]},
        'custom': {'title': 'Custom fields', 'hero': {'heading': 'Custom headline', 'description': 'Keep this structure'}},
        'empty': {'title': 'Empty page', 'sections': []},
    }
    for slug, page in pages.items():
        write_json(site.root / ('content/pages/en_' + slug + '.json'), dict(page, page='en_'+slug, lang='en'))
    site.start()
    try:
        admin = Client(site).login()
        other = Client(site).login('editor')
        first = admin.api('load', page='en_writing')[1]
        stale = other.api('load', page='en_writing')[1]
        content = first['data']
        content['title'] = 'Writing test saved'
        code, saved = admin.api('save', page='en_writing', revision=first['revision'], content=json.dumps(content))
        assert code == 200, saved
        after = admin.api('load', page='en_writing')[1]
        assert after['data']['sections'][0]['content'] == html
        assert other.api('save', page='en_writing', revision=stale['revision'], content=json.dumps(stale['data']))[0] == 409
        assert admin.api('save', page='en_writing', content=json.dumps(content))[0] == 428
        for slug in ('multi', 'custom', 'empty'):
            loaded = admin.api('load', page='en_'+slug)[1]
            assert admin.api('save', page='en_'+slug, revision=loaded['revision'], content=json.dumps(loaded['data']))[0] == 200
        assert '<strong>bold</strong>' in Client(site).request('/writing')[2].decode()
        print('PASS: formatted HTML round-trip, custom/empty/multi pages and native revision conflicts.', flush=True)
        if '--serve' in sys.argv:
            Path('/tmp/nibbly-writing-browser.json').write_text(json.dumps({'root': str(site.root), 'port': site.port, 'password': site.password}))
            print('Browser fixture on port '+str(site.port), flush=True)
            try:
                while True: time.sleep(1)
            except KeyboardInterrupt: pass
    finally:
        site.close()
        Path('/tmp/nibbly-writing-browser.json').unlink(missing_ok=True)
