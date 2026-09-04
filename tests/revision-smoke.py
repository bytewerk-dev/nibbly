#!/usr/bin/env python3
"""Two independently loaded editors must never overwrite newer revisions."""
import json
import tempfile
from support import Client, Site

with tempfile.TemporaryDirectory(prefix='nibbly-revision-') as folder:
    site = Site(folder).start()
    try:
        first, second = Client(site).login(), Client(site).login()
        for load, save, field, extra in [('load', 'save', 'content', {'page': 'en_home'}), ('load-settings', 'save-settings', 'settings', {})]:
            one = first.api(load, **extra)[1]
            two = second.api(load, **extra)[1]
            assert one['revision'] == two['revision'] and one['revision']
            patch = {'title': 'First edit', 'sections': []} if save == 'save' else {'branding': {'name': 'First edit'}}
            status, saved = first.api(save, **extra, revision=one['revision'], **{field: json.dumps(patch)})
            assert status == 200 and saved['success'] and saved['revision'] != one['revision'], saved
            status, conflict = second.api(save, **extra, revision=two['revision'], **{field: json.dumps(patch)})
            assert status == 409 and conflict['data']['conflict'], conflict
            assert second.api(save, **extra, **{field: '{}'})[0] == 428
            latest = second.api(load, **extra)[1]
            assert latest['revision'] == saved['revision']
            assert second.api(save, **extra, revision=latest['revision'], **{field: json.dumps(patch)})[1]['success']
        # A partial settings patch must preserve unrelated feature/privacy/access values.
        stored = json.loads((site.root / 'content/settings.json').read_text())
        assert stored['modules']['ai'] is False and stored['privacy']['analyticsEnabled'] is False
        # Every newly created backup remains listable, previewable and restorable.
        status, _, raw = first.request('/admin/api.php?action=backups&page=en_home')
        backups = json.loads(raw)['data']
        assert len(backups) >= 2, backups
        assert first.api('restore', backup=backups[-1]['filename'])[1]['success']
        # Invalid storage is preserved, never reset to an empty document.
        target = site.root / 'content/pages/en_home.json'
        target.write_text('{broken')
        assert first.api('load', page='en_home')[0] == 503
        assert target.read_text() == '{broken'
        # Credentials do not belong in the editor's settings response.
        settings = site.root / 'content/settings.json'
        settings.write_text(json.dumps({'email': {'smtpPassword': 'fixture-secret'}, 'backup': {'token': 'fixture'}, 'ai': {'apiKey': 'fixture'}}))
        visible = Client(site).login('editor').api('load-settings')[1]['data']
        assert not any(key in visible for key in ('email', 'backup', 'ai'))
        print('PASS revisions: two editors, two settings forms, required preconditions, corrupt-store preservation and credential filtering')
    finally:
        site.close()
