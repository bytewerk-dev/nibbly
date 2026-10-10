#!/usr/bin/env python3
"""Event writes share one lock: parallel saves lose nothing, damaged storage survives."""
from concurrent.futures import ThreadPoolExecutor
import json
import os
import tempfile
from support import Client, Site

os.environ['PHP_CLI_SERVER_WORKERS'] = '8'  # real parallel requests instead of one queue

with tempfile.TemporaryDirectory(prefix='nibbly-events-') as folder:
    site = Site(folder).start()
    try:
        events_file = site.root / 'content/events.json'
        clients = [Client(site).login() for _ in range(8)]  # one session each, so requests do not queue on the session lock

        def event(index):
            return json.dumps({'id': f'2026-10-{index + 1:02d}-parallel', 'date': f'2026-10-{index + 1:02d}',
                               'title': {'en': f'Parallel {index}'}})

        def save(index):
            return clients[index % len(clients)].api('save-event', event=event(index))[1]

        with ThreadPoolExecutor(max_workers=8) as workers:
            results = list(workers.map(save, range(24)))
        assert all(result['success'] for result in results), results
        stored = json.loads(events_file.read_text())
        assert sorted(item['id'] for item in stored['events']) == sorted(f'2026-10-{i + 1:02d}-parallel' for i in range(24)), 'parallel saves lost events'
        assert not list(events_file.parent.glob('.nibbly-json-*')), 'temporary files left behind'

        admin = clients[0]
        # Update in place keeps one entry
        changed = json.loads(event(0)); changed['title'] = {'en': 'Changed'}
        assert admin.api('save-event', event=json.dumps(changed))[1]['message'] == 'Event updated'
        assert [e['title']['en'] for e in json.loads(events_file.read_text())['events'] if e['id'] == changed['id']] == ['Changed']

        # Hide, trash, restore, delete for good, empty trash: parallel toggles must all land
        with ThreadPoolExecutor(max_workers=8) as workers:
            toggled = list(workers.map(lambda i: clients[i % 8].api('toggle-event-visibility', id=f'2026-10-{i + 1:02d}-parallel')[1], range(1, 9)))
        assert all(result['success'] and result['data']['hidden'] for result in toggled), toggled
        hidden = {e['id'] for e in json.loads(events_file.read_text())['events'] if e.get('hidden')}
        assert len(hidden) == 8, 'parallel visibility changes lost updates'

        victim = '2026-10-03-parallel'
        assert admin.api('delete-event', id=victim)[1]['success']
        data = json.loads(events_file.read_text())
        assert victim not in [e['id'] for e in data['events']] and data['trash'][0]['event']['id'] == victim
        assert admin.api('delete-event', id=victim)[1]['message'] == 'Event not found'
        assert admin.api('restore-event', id=victim)[1]['success']
        assert victim in [e['id'] for e in json.loads(events_file.read_text())['events']]
        assert admin.api('delete-event', id=victim)[1]['success'] and admin.api('delete-event-permanent', id=victim)[1]['success']
        assert json.loads(events_file.read_text())['trash'] == []
        assert admin.api('delete-event-permanent', id=victim)[1]['message'] == 'Event not found in trash'
        assert admin.api('empty-events-trash')[1]['success']
        assert list((site.root / 'backups').glob('events_*.json')), 'backups are still written'

        # A damaged events file is never replaced by an empty one
        events_file.write_text('{damaged-json')
        assert not admin.api('save-event', event=event(30))[1]['success']
        assert not admin.api('toggle-event-visibility', id='2026-10-04-parallel')[1]['success']
        assert not admin.api('delete-event', id='2026-10-04-parallel')[1]['success']
        assert events_file.read_text() == '{damaged-json', 'damaged events file was overwritten'
        print('PASS events: 24 parallel saves and 8 parallel toggles keep every entry, trash cycle intact, damaged store preserved')
    finally:
        site.close()
