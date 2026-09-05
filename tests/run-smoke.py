#!/usr/bin/env python3
"""Run all offline smoke tests inside disposable copies of the CMS."""
from concurrent.futures import ThreadPoolExecutor
import json
import subprocess
import sys
import tempfile
from support import ROOT, copy_core


def run(command, root):
    result = subprocess.run(command, cwd=root, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    if result.returncode:
        raise RuntimeError(f"{' '.join(map(str, command))}\n{result.stdout}")
    return result.stdout


with tempfile.TemporaryDirectory(prefix="nibbly-suite-") as folder:
    root = copy_core(folder)
    php_files = sorted(root.rglob("*.php"))
    js_files = sorted(path for directory in ("js", "admin", "tests") for path in (root / directory).rglob("*.js"))
    # Syntax checks have no side effects; run them concurrently.
    with ThreadPoolExecutor(max_workers=8) as workers:
        list(workers.map(lambda p: run(["php", "-l", str(p)], root), php_files))
        list(workers.map(lambda p: run(["node", "--check", str(p)], root), js_files))
    json_files = list((root / "includes/ai").glob("*.json")) + list((root / "admin/lang").glob("*.json")) + list((root / "content").rglob("*.json"))
    for path in json_files:
        json.loads(path.read_text())
    print(f"PASS syntax: {len(php_files)} PHP, {len(js_files)} JavaScript, {len(json_files)} JSON files", flush=True)
    tests = sorted(path for path in (root / "tests").glob("*-smoke.*") if path.name != "run-smoke.py")
    failures = []
    for path in tests:
        interpreter = {".php": "php", ".js": "node", ".py": sys.executable}.get(path.suffix)
        if interpreter:
            try:
                print(run([interpreter, str(path)], root).strip(), flush=True)
            except RuntimeError as error:
                failures.append(path.name)
                print(error, flush=True)
    if failures:
        raise SystemExit("Failed: " + ", ".join(failures))
    print(f"PASS all {len(tests)} smoke suites", flush=True)
