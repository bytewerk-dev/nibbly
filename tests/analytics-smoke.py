#!/usr/bin/env python3
"""Migration, historical aggregates, disabled tracking and hot-file size regression."""
import datetime
import json
import subprocess
import tempfile
import time
from support import copy_core, write_json

with tempfile.TemporaryDirectory(prefix='nibbly-analytics-') as folder:
    root = copy_core(folder)
    today = datetime.date.today()
    days = {}
    for index in range(730):
        day = str(today - datetime.timedelta(days=index))
        days[day] = {'views': 7, 'visits': 3, 'visitors': {'fixture': True}, 'sessions': {'fixture': True},
                     'pages': {'en_home': {'views': 7, 'visits': 3, 'visitors': {'fixture': True}, 'title': 'Home', 'path': '/'}},
                     'hours': {}, 'referrers': {'Direct': 7}}
    legacy = root / 'content/analytics.json'
    write_json(legacy, {'days': days})
    initial_bytes = legacy.stat().st_size
    def php(code):
        return subprocess.check_output(['php', '-r', "require 'includes/analytics-helper.php'; " + code], cwd=root, text=True)
    started = time.perf_counter()
    summary = json.loads(php("echo json_encode(nibblyAnalyticsSummary('days', 30));"))
    migration_ms = (time.perf_counter() - started) * 1000
    assert summary['periodViews'] == 210 and len(summary['series']) == 30
    complete = json.loads(php('echo json_encode(nibblyAnalyticsLoad());'))
    assert len(complete['days']) == 730 and sum(day['views'] for day in complete['days'].values()) == 5110
    old = complete['days'][str(today - datetime.timedelta(days=400))]
    assert 'visitors' not in old and old['visitorCount'] == 1
    assert (root / 'content/analytics/archive').is_dir()
    track = "$_SERVER['HTTP_USER_AGENT']='Mozilla/5.0'; $_SERVER['REQUEST_URI']='/'; nibblyAnalyticsTrack('en_home','en','home');"
    php(track)
    summary = json.loads(php("echo json_encode(nibblyAnalyticsSummary('days', 30));"))
    assert summary['periodViews'] == 211, 'Current-day write did not invalidate summary cache'
    hot = root / 'content/analytics/days' / (str(today) + '.json')
    assert hot.stat().st_size < initial_bytes / 10
    write_json(root / 'content/settings.json', {'privacy': {'analyticsEnabled': False}})
    before = hot.read_bytes()
    php(track)
    assert hot.read_bytes() == before, 'Disabled analytics still tracks visits'
    print(f'PASS analytics: 730-day import in {migration_ms:.0f} ms; legacy {initial_bytes} bytes, hot day {hot.stat().st_size} bytes; preserved totals, archive and cache invalidation')
