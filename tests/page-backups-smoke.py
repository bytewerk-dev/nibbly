#!/usr/bin/env python3
"""Page-scoped, revision-aware restore through the real API in disposable storage."""
import json
import tempfile
from support import Client, Site, write_json

with tempfile.TemporaryDirectory(prefix='nibbly-page-backups-') as folder:
    site = Site(folder).start()
    try:
        client = Client(site).login('editor')
        page = site.root / 'content/pages/en_home.json'
        initial = client.api('load', page='en_home')[1]
        original = initial['data']
        original['image'] = {'src': 'assets/images/old.webp', 'alt': 'Original portrait'}
        backup = 'en_home_2026-09-16_100000_abcdef.json'
        write_json(site.root / 'backups' / backup, original)
        write_json(site.root / 'backups/en_home_child_2026-09-16_100001.json', {'title': 'Another page'})
        write_json(site.root / 'backups/en_home__child_2026-09-16_100001.json', {'title': 'Nested page'})
        status, _, body = client.request('/admin/api.php?action=backups&page=en_home')
        assert status == 200
        listed = json.loads(body)['data']
        assert [item['filename'] for item in listed] == [backup], listed
        assert listed[0]['timestamp'] > 0
        empty = json.loads(client.request('/admin/api.php?action=backups&page=en_services__test')[2])
        assert empty['success'] and empty['data'] == []

        current = {**original, 'title': 'Current saved page', 'image': {'src': 'assets/images/new.webp'}}
        saved = client.api('save', page='en_home', revision=initial['revision'], content=json.dumps(current))[1]
        current_bytes = page.read_bytes()
        count = len(list((site.root / 'backups').glob('*.json')))
        status, conflict = client.api('restore', page='en_home', backup=backup, revision=initial['revision'])
        assert status == 409 and conflict['data']['conflict']
        assert page.read_bytes() == current_bytes
        assert len(list((site.root / 'backups').glob('*.json'))) == count
        assert client.api('restore', page='en_home', backup=backup, revision='')[0] == 428
        assert client.api('restore', page='en_other', backup=backup, revision=saved['revision'])[0] == 400
        assert client.api('restore', page='en_home', backup=backup, revision=saved['revision'], csrf_token='invalid')[0] == 403
        assert page.read_bytes() == current_bytes

        # Never replace the current page if its safety backup cannot be written.
        backups = site.root / 'backups'
        backups.chmod(0o500)
        try:
            failed = client.api('restore', page='en_home', backup=backup, revision=saved['revision'])[1]
            assert not failed['success'] and page.read_bytes() == current_bytes
        finally:
            backups.chmod(0o700)

        status, restored = client.api('restore', page='en_home', backup=backup, revision=saved['revision'])
        assert status == 200 and restored['success'], restored
        assert json.loads(page.read_text()) == original, 'Restore includes image references'
        assert any(path.read_bytes() == current_bytes for path in backups.glob('en_home_*.json'))
        assert client.api('restore', backup=backup)[1]['success'], 'Legacy dashboard remains compatible'
        status, _, _ = Client(site).request('/admin/api.php?action=backups&page=en_home')
        assert status == 401, status
        print('PASS page backups: exact page filter, empty list, images, CSRF/auth, stale revision, failed safety backup, current-state backup and legacy restore')
    finally:
        site.close()
