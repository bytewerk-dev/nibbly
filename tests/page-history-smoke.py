#!/usr/bin/env python3
"""Verify retention against historical files and through ordinary saves in a disposable installation."""
from datetime import datetime, timedelta
import json
import os
import subprocess
import tempfile
from urllib.parse import urlencode

from support import Client, Site, write_json


def read(client, action, **params):
    status, _, body = client.request('/admin/api.php?' + urlencode({'action': action, **params}))
    return status, json.loads(body)


def versions(client, mode, page='en_home'):
    status, result = read(client, 'get-page-history-preview', page=page, mode=mode)
    assert status == 200, result
    return result['data']


def php(site, code):
    return subprocess.check_output(['php', '-r', "require 'admin/config.php'; " + code], cwd=site.root, text=True)


with tempfile.TemporaryDirectory(prefix='nibbly-page-history-') as tmp:
    site = Site(tmp)
    config = site.root / 'admin/config.php'
    config.write_text(config.read_text().replace("define('MAX_BACKUPS', 30)", "define('MAX_BACKUPS', 3)"))
    settings_path = site.root / 'content/settings.json'
    settings = json.loads(settings_path.read_text())
    settings['modules']['ai'] = True
    write_json(settings_path, settings)
    site.start()
    try:
        admin = Client(site).login()
        editor = Client(site).login('editor')
        directory = site.root / 'backups'
        old = datetime(2021, 1, 4, 12)  # Monday after ISO week 2020-W53
        dates = {old - timedelta(minutes=i) for i in range(12)}
        dates.update(old - timedelta(days=i) for i in range(1, 45))
        dates.update(datetime(year, month, 7) for year in (2000, 2015, 2018, 2020) for month in range(1, 13))
        names = {}
        for index, date in enumerate(sorted(dates)):
            suffix = '_abcdef' if index % 2 else ''
            name = date.strftime('en_home_%Y-%m-%d_%H%M%S') + suffix + '.json'
            names[date] = name
            write_json(directory / name, {'title': name})
            os.utime(directory / name, (1000, 1000))  # extracted from a ZIP with identical mtimes

        neighbors = ['en_home__child', 'en_homeish', 'de_home']
        for page in [*neighbors, 'en_home_legacy', 'events']:
            write_json(directory / (page + '_2000-01-04_120000.json'), {'title': page})
        status, result = read(admin, 'get-page-history-policy')
        assert result['data'] == {'mode': 'count', 'limit': 3, 'pages': sorted(['en_home', *neighbors])}
        assert read(editor, 'get-page-history-policy')[0] == 403
        assert read(editor, 'get-page-history-preview', mode='count', page='en_home')[0] == 403
        assert editor.api('set-page-history-policy', mode='tiered')[0] == 403
        assert admin.api('set-page-history-policy', mode='tiered', csrf_token='wrong')[0] == 403
        assert admin.api('set-page-history-policy', mode='unknown')[0] == 400

        pinned = names[min(dates)]
        pin = {'page': 'en_home', 'backup': pinned, 'pin': '1', 'label': 'Vor dem Relaunch'}
        assert editor.api('protect-page-backup', **pin, csrf_token='wrong')[0] == 403
        assert editor.api('protect-page-backup', **{**pin, 'page': neighbors[0]})[0] == 400
        assert editor.api('protect-page-backup', **pin)[1]['success']
        assert editor.api('delete-backup', backup=pinned)[0] == 400

        recent = sorted(dates - {min(dates)}, reverse=True)
        expected = {names[d] for d in recent[:5]} | {pinned}
        for key, limit in ((lambda d: d.date(), 7), (lambda d: d.isocalendar()[:2], 4),
                           (lambda d: (d.year, d.month), 12), (lambda d: d.year, 2)):
            newest = {}
            for date in recent:
                newest.setdefault(key(date), names[date])
            expected.update(list(newest.values())[:limit])
        count_plan = versions(admin, 'count')
        assert {i['filename'] for i in count_plan if i['keep']} == {names[d] for d in recent[:3]} | {pinned}
        plan = versions(admin, 'tiered')
        assert {i['filename'] for i in plan if i['keep']} == expected
        assert next(i for i in plan if i['pinned'])['label'] == pin['label']
        assert len(plan) == len(names)
        before = sorted(directory.glob('*.json'))
        assert admin.api('set-page-history-policy', mode='tiered')[1]['success']
        assert sorted(directory.glob('*.json')) == before
        assert json.loads(settings_path.read_text())['modules'] == settings['modules']
        php(site, """require 'includes/page-history.php'; (new NibblyPageHistory(BACKUP_PATH))->prune('en_home');""")
        assert {i['filename'] for i in versions(admin, 'tiered')} == expected
        _, published = admin.api('ai-copilot-publish-content', confirmed=1, contentType='page', id='en_home')
        assert published['success'], published
        assert (directory / published['data']['backup']).is_file()
        assert (directory / pinned).is_file()
        assert all((directory / (page + '_2000-01-04_120000.json')).is_file() for page in neighbors)

        before_switch = set(directory.glob('*.json'))
        assert admin.api('set-page-history-policy', mode='count')[1]['success']
        assert set(directory.glob('*.json')) == before_switch
        initial = editor.api('load', page='en_home')[1]
        revision = initial['revision']
        for i in range(9):
            saved = editor.api('save', page='en_home', revision=revision, content=json.dumps({'title': str(i)}))[1]
            assert saved['success'], saved
            revision = saved['revision']
        listed = versions(admin, 'count')
        assert len(listed) == 4 and listed[-1]['filename'] == pinned
        assert [json.loads((directory / item['filename']).read_text())['title'] for item in listed[:3]] == ['7', '6', '5']
        assert editor.api('restore', page='en_home', backup=pinned, revision=revision)[1]['success']
        assert editor.api('load', page='en_home')[1]['data']['title'] == pinned

        # The agent shares the exact filter, pins and count with both page editors.
        php(site, """require 'includes/ai/agent/changeset-apply.php'; nibblyAgentBackupFile(CONTENT_PATH . 'en_home.json');""")
        assert len(versions(admin, 'count')) == 4
        assert all((directory / (page + '_2000-01-04_120000.json')).is_file() for page in neighbors)
        assert editor.api('protect-page-backup', **{**pin, 'pin': '0'})[1]['success']
        assert (directory / pinned).is_file()
        php(site, """require 'includes/ai/agent/changeset-apply.php'; nibblyAgentBackupFile(CONTENT_PATH . 'en_home.json');""")
        assert not (directory / pinned).exists()
        assert len(versions(admin, 'count')) == 3

        # A damaged protection record never turns protected snapshots into deletion candidates.
        (directory / 'page-history/en_home.json').write_text('{')
        before = set(directory.glob('*.json'))
        php(site, """require 'includes/ai/agent/changeset-apply.php'; nibblyAgentBackupFile(CONTENT_PATH . 'en_home.json');""")
        assert before < set(directory.glob('*.json'))
        assert editor.api('delete-backup', backup=listed[0]['filename'])[0] == 400
        print('PASS page history: legacy files, sparse calendar tiers, preview/prune parity, per-page isolation, pins, roles, count limit and rapid saves')
    finally:
        site.close()
