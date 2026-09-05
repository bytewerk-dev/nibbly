#!/usr/bin/env python3
"""Parallel budget reservations, crash persistence, settlement and explicit resolution."""
from concurrent.futures import ThreadPoolExecutor
import json
import subprocess
import tempfile
from support import copy_core

with tempfile.TemporaryDirectory(prefix='nibbly-reservations-') as folder:
    root = copy_core(folder)
    def php(code):
        return subprocess.check_output(['php', '-r', "require 'includes/ai/ai-helper.php'; " + code], cwd=root, text=True)
    assert php("echo nibblyAiNormalizeOpenRouterImageModel('vendor/future-image-model');") == 'vendor/future-image-model'
    def reserve(index):
        return php("$s=nibblyAiDefaults(); $s['limits']['monthlyBudgetCents']=3; try { nibblyAiReserve($s,'text',1,'req_"+str(index)+"'); echo 'yes'; } catch(Throwable $e) { echo 'no'; }")
    with ThreadPoolExecutor(max_workers=12) as workers:
        results = list(workers.map(reserve, range(24)))
    assert results.count('yes') == 3, results
    ledger = root / 'content/ai-usage.json'
    usage = json.loads(ledger.read_text())
    ids = list(usage['reservations'])
    assert len(ids) == 3
    # Reserved budget survives a process exiting without settlement.
    assert reserve(99) == 'no'
    php("$GLOBALS['nibblyAiRequestId']='"+ids[0]+"'; nibblyAiRecordUsage('text',12,4,1); nibblyAiRecordUsage('text',12,4,1);")
    usage = json.loads(ledger.read_text())
    assert next(iter(usage['days'].values()))['requests'] == 1, 'Settlement counted twice'
    assert reserve(99) == 'no', 'Settlement temporarily released the spent budget'
    # An explicit no-charge resolution frees only that reservation.
    php("nibblyAiRequestPatch('"+ids[1]+"', ['status'=>'uncertain','updatedAt'=>gmdate('c',time()-1000)]); nibblyAiResolveReservation('"+ids[1]+"','released');")
    assert reserve(99) == 'yes'
    php("nibblyAiRequestPatch('"+ids[2]+"', ['status'=>'uncertain','updatedAt'=>gmdate('c',time()-1000)]); nibblyAiResolveReservation('"+ids[2]+"','charged');")
    usage = json.loads(ledger.read_text())
    assert next(iter(usage['days'].values()))['requests'] == 2
    assert usage['reservations'][ids[2]]['status'] == 'settled'
    print('PASS 24 concurrent reservations: budget admits exactly three, crash persistence, idempotent settlement, charged/released resolution')
