#!/usr/bin/env python3
"""Post backups and nested media folders through the real API in disposable storage."""
import json
import shutil
import tempfile
from support import Client, Site

with tempfile.TemporaryDirectory(prefix='nibbly-news-backups-') as folder:
    site = Site(folder).start()
    try:
        client = Client(site).login('admin')
        backups = site.root / 'backups'

        # --- posts built from blocks: first save keeps no backup, every further save one
        post = {'title': 'Block post', 'date': '2026-10-31', 'slug': 'block-post', 'subtitle': '<b>Kicker</b>',
                'cover': {'src': 'assets/images/favicon.svg', 'alt': 'Cover', 'label': 'Issue 2/2026'},
                'sidebar': True, 'comments': False, 'imageLayout': 'full',
                'sections': [{'type': 'text', 'content': '<p onclick="x()">One</p>', 'style': 'highlight'},
                             {'type': 'gallery', 'layout': 'grid', 'images': [{'src': '/assets/images/a.webp'}]},
                             {'type': 'bogus'}],
                'aside': [{'type': 'text', 'content': '<p>Aside</p>'}]}
        status, saved = client.api('save-news', post=json.dumps(post))
        assert status == 200 and saved['success'], saved
        data = saved['data']
        assert data['id'] == '2026-10-31-block-post' and data['subtitle'] == 'Kicker', data
        assert data['cover'] == {'src': '/assets/images/favicon.svg', 'alt': 'Cover', 'label': 'Issue 2/2026'}, data['cover']
        assert data['sidebar'] is True and data['comments'] is False and data['imageLayout'] == 'full'
        assert 'content' not in data and [b['type'] for b in data['sections']] == ['text', 'gallery'], data
        assert 'onclick' not in data['sections'][0]['content'] and data['sections'][0]['style'] == 'highlight'
        assert data['aside'][0]['content'] == '<p>Aside</p>'
        assert list(backups.glob('news__*.json')) == [], 'no backup before a previous state exists'
        listed = json.loads(client.request('/admin/api.php?action=list-news-backups&id=2026-10-31-block-post')[2])
        assert listed['success'] and listed['data'] == [], listed

        # second save: previous JSON lands in backups/
        second = {**data, 'title': 'Block post v2', 'sections': data['sections'][:1]}
        status, saved2 = client.api('save-news', post=json.dumps(second))
        assert status == 200 and saved2['success'], saved2
        files = sorted(backups.glob('news__2026-10-31-block-post_*.json'))
        assert len(files) == 1, files
        first_state = json.loads(files[0].read_text())
        assert first_state['title'] == 'Block post' and len(first_state['sections']) == 2
        listed = json.loads(client.request('/admin/api.php?action=list-news-backups&id=2026-10-31-block-post')[2])['data']
        assert [item['filename'] for item in listed] == [files[0].name] and listed[0]['date'] == files[0].name.split('_')[3], listed
        assert json.loads(client.request('/admin/api.php?action=list-news-backups&id=2026-10-31-block-pos')[2])['data'] == [], 'exact id only'
        assert not json.loads(client.request('/admin/api.php?action=list-news-backups&id=../x')[2])['success']
        loaded = json.loads(client.request('/admin/api.php?action=load-news-backup&backup=' + files[0].name)[2])
        assert loaded['success'] and loaded['data']['title'] == 'Block post', loaded
        assert not json.loads(client.request('/admin/api.php?action=load-news-backup&backup=../content/users.json')[2])['success']

        # restore: the current state is backed up first, the backup must belong to the post
        status, wrong = client.api('restore-news-backup', backup=files[0].name, post_id='2026-10-31-other')
        assert status == 400 and not wrong['success'], wrong
        status, restored = client.api('restore-news-backup', backup=files[0].name, post_id='2026-10-31-block-post')
        assert status == 200 and restored['success'] and restored['data']['title'] == 'Block post', restored
        current = json.loads((site.root / 'content/news/2026-10-31-block-post.json').read_text())
        assert current['title'] == 'Block post' and len(current['sections']) == 2 and current['id'] == '2026-10-31-block-post'
        all_backups = sorted(backups.glob('news__2026-10-31-block-post_*.json'))
        assert len(all_backups) == 2 and any(json.loads(p.read_text())['title'] == 'Block post v2' for p in all_backups), 'restore keeps the replaced state'
        status, deleted = client.api('delete-news-backup', backup=files[0].name)
        assert status == 200 and deleted['success'] and not files[0].exists()
        assert not client.api('delete-news-backup', backup='en_home_2026-09-16_100000_abcdef.json')[1]['success'], 'page backups are out of reach'
        assert Client(site).request('/admin/api.php?action=list-news-backups&id=2026-10-31-block-post')[0] == 401

        # --- nested media folders
        status, created = client.api('create-media-folder', type='image', folder='2026/10')
        assert status == 200 and created['success'] and (site.root / 'assets/images/2026/10').is_dir(), created
        assert client.api('create-media-folder', type='image', folder='2026/10/a/b/c')[1]['success']
        assert not client.api('create-media-folder', type='image', folder='2026/10/a/b/c/d')[1]['success'], 'depth limit'
        assert not client.api('create-media-folder', type='image', folder='../x')[1]['success']
        assert not client.api('create-media-folder', type='image', folder='2026//10')[1]['success']
        listed = json.loads(client.request('/admin/api.php?action=list-media&type=image')[2])['data']['folders']
        assert listed.index('2026') < listed.index('2026/10') < listed.index('2026/10/a'), listed
        assert '2026/10/a/b/c' in listed and 'generated' in listed, listed

        shutil.copyfile(site.root / 'assets/images/favicon.svg', site.root / 'assets/images/move-me.svg')
        status, moved = client.api('move-media', type='image', filename='move-me.svg', folder='2026/10')
        assert status == 200 and moved['success'] and moved['data']['name'] == '2026/10/move-me.svg', moved
        assert (site.root / 'assets/images/2026/10/move-me.svg').is_file()
        items = json.loads(client.request('/admin/api.php?action=list-media&type=image')[2])['data']['items']
        assert any(item['name'] == '2026/10/move-me.svg' for item in items), [i['name'] for i in items]
        status, renamed = client.api('rename-media', type='image', filename='2026/10/move-me.svg', newName='moved.svg')
        assert status == 200 and renamed['success'], renamed
        assert (site.root / 'assets/images/2026/10/moved.svg').is_file()
        assert not client.api('delete-media-folder', type='image', folder='2026/10')[1]['success'], 'folder with content stays'
        assert client.api('delete-media-folder', type='image', folder='2026/10/a/b/c')[1]['success']
        assert not (site.root / 'assets/images/2026/10/a/b/c').exists()
        print('PASS news backups and media folders: block post save, backup per save, list/load/restore/delete, auth, nested folders with depth limit, move and rename into subfolders')
    finally:
        site.close()
