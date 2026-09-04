#!/usr/bin/env python3
"""Concurrent form writes, single-use tokens and corrupt-store preservation."""
from concurrent.futures import ThreadPoolExecutor
import json
from pathlib import Path
import subprocess
import tempfile
import time
from support import copy_core, write_json


def php(root, code):
    return subprocess.check_output(["php", "-r", code], cwd=root, text=True).strip()


with tempfile.TemporaryDirectory(prefix="nibbly-storage-smoke-") as folder:
    root = copy_core(folder)
    prefix = "require 'includes/forms.php'; "
    with ThreadPoolExecutor(max_workers=12) as workers:
        tokens = list(workers.map(lambda _: php(root, prefix + "echo nibblyCreateFormToken('review');"), range(24)))
    token_path = root / "content/form-tokens.json"
    stored = json.loads(token_path.read_text())
    assert len(stored) == len(tokens), "Concurrent renders lost form tokens"
    for entry in stored.values():
        entry["created"] = int(time.time()) - 5
    write_json(token_path, stored)
    with ThreadPoolExecutor(max_workers=12) as workers:
        results = list(workers.map(lambda _: php(root, prefix + "echo json_encode(nibblyValidateFormToken('" + tokens[0] + "', 'review'));"), range(24)))
    assert sum(json.loads(result)["valid"] for result in results) == 1, "Token accepted more than once"
    with ThreadPoolExecutor(max_workers=12) as workers:
        results = list(workers.map(lambda index: php(root, prefix + f"echo (int)nibblyFormsSaveSubmission(['id'=>'mail-{index}']);"), range(24)))
    assert all(result == "1" for result in results)
    mail_path = root / "content/mails.json"
    mails = json.loads(mail_path.read_text())
    assert len({mail["id"] for mail in mails}) == 24, "Concurrent submissions lost messages"
    with ThreadPoolExecutor(max_workers=12) as workers:
        list(workers.map(lambda _: php(root, prefix + "nibblyFormRecordAttempt('review-client', true);"), range(24)))
    rates = json.loads((root / "content/form-rate-limit.json").read_text())
    assert len(rates["review-client"]["submissions"]) == 24, "Concurrent attempts lost rate-limit entries"
    with ThreadPoolExecutor(max_workers=12) as workers:
        admissions = list(workers.map(lambda _: php(root, prefix + "echo (int)nibblyFormReserveRequest('parallel-client');"), range(24)))
    assert admissions.count("1") == 3, "Parallel requests bypassed the submission limit"
    mail_path.write_text("{damaged-json")
    assert php(root, prefix + "echo (int)nibblyFormsSaveSubmission(['id'=>'must-not-overwrite']);") == "0"
    assert mail_path.read_text() == "{damaged-json", "Damaged inbox overwritten"
    prefix = "require 'includes/ai/ai-helper.php'; "
    with ThreadPoolExecutor(max_workers=12) as workers:
        list(workers.map(lambda _: php(root, prefix + "nibblyAiRecordUsage('text', 10, 2, 1);"), range(24)))
    usage = json.loads((root / "content/ai-usage.json").read_text())
    bucket = next(iter(usage["days"].values()))
    assert bucket["requests"] == 24 and bucket["estimatedCostCents"] == 24, "Lost AI usage"
    php(root, prefix + "nibblyAiSaveImageJob(['id'=>'job_0123456789abcdef','status'=>'queued']);")
    with ThreadPoolExecutor(max_workers=12) as workers:
        claims = list(workers.map(lambda _: php(root, prefix + "$claimed=false; nibblyAiMarkImageJobRunning('job_0123456789abcdef',$claimed); echo (int)$claimed;"), range(24)))
    assert claims.count("1") == 1, "Multiple workers claimed the same paid image job"
    with ThreadPoolExecutor(max_workers=12) as workers:
        list(workers.map(lambda _: php(root, prefix + "nibblyAiRecordImageHistory(['prompt'=>str_repeat('ä',3999).'€','status'=>'error']);"), range(24)))
    history = json.loads((root / "content/ai-image-history.json").read_text())
    assert len(history['items']) == 24 and all(item['prompt'].endswith('€') for item in history['items'])
    prefix = "require 'includes/analytics-helper.php'; $_SERVER['REQUEST_URI']='/test'; $_SERVER['HTTP_USER_AGENT']='Mozilla/5.0'; "
    with ThreadPoolExecutor(max_workers=12) as workers:
        list(workers.map(lambda _: php(root, prefix + "nibblyAnalyticsTrack('en_test','en','test');"), range(24)))
    analytics = json.loads((root / "content/analytics.json").read_text())
    assert next(iter(analytics['days'].values()))['views'] == 24, 'Concurrent page views were lost'
print("PASS concurrent tokens, submissions, rate counters, AI usage/history, single image worker, analytics and corrupt-store preservation")
