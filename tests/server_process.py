"""Start/stop owned PHP test servers; reap abandoned servers before each start.

Ownership markers travel with PHP and its workers, so even a killed test that
could not run finally/atexit can be cleaned up on the next start. No daemon,
watchdog or PID file is needed. Active tests and unmarked servers are preserved.
"""
import atexit
import os
from pathlib import Path
import re
import secrets
import signal
import subprocess
import sys
import threading
import time

_active = {}
_signals_installed = False
_marker = re.compile(r"(?:^|\s)-d nibbly_test_owner=(\d+) -d nibbly_test_token=([a-f0-9]{32}) -d nibbly_test_group=([01])(?:\s|$)")


def _servers():
    result = []
    output = subprocess.check_output(
        ["ps", "-axo", "pid=,pgid=,uid=,stat=,command="], text=True)
    for line in output.splitlines():
        parts = line.split(None, 4)
        if len(parts) != 5 or int(parts[2]) != os.getuid() or parts[3].startswith("Z"):
            continue
        command = parts[4]
        if not re.fullmatch(r"php(?:\d+(?:\.\d+)*)?", Path(command.split()[0]).name):
            continue
        match = _marker.search(command)
        if match:
            owner, token, grouped = match.groups()
            result.append((int(parts[0]), int(parts[1]), int(owner), token, grouped == "1"))
    return result


def _owner_alive(pid):
    try:
        os.kill(pid, 0)
        return True
    except ProcessLookupError:
        return False
    except PermissionError:
        return True


def _matches(target, token, grouped):
    return any(row[3] == token and (row[1] if grouped else row[0]) == target
               for row in _servers())


def _stop_owned(target, token, grouped):
    send = os.killpg if grouped else os.kill
    for sig, seconds in ((signal.SIGTERM, 1), (signal.SIGKILL, 2)):
        # Recheck ownership immediately before each signal, including escalation.
        if not _matches(target, token, grouped):
            return
        try:
            send(target, sig)
        except ProcessLookupError:
            return
        deadline = time.monotonic() + seconds
        while time.monotonic() < deadline:
            if not _matches(target, token, grouped):
                return
            time.sleep(.03)
    raise RuntimeError("PHP test server did not stop")


def cleanup_stale_servers():
    for target, owner, token, grouped in {
        (group if grouped else pid, owner, token, grouped)
        for pid, group, owner, token, grouped in _servers()
    }:
        if owner > 1 and not _owner_alive(owner):
            _stop_owned(target, token, grouped)


def _on_signal(signum, _frame):
    # SystemExit unwinds finally blocks and runs atexit. SIGINT already raises
    # KeyboardInterrupt in Python; do not replace custom signal handlers.
    raise SystemExit(128 + signum)


def start_server(command, **kwargs):
    global _signals_installed
    cleanup_stale_servers()
    if not _signals_installed and threading.current_thread() is threading.main_thread():
        for sig in (signal.SIGTERM, signal.SIGHUP):
            if signal.getsignal(sig) == signal.SIG_DFL:
                signal.signal(sig, _on_signal)
        _signals_installed = True
    token = secrets.token_hex(16)
    command = [command[0], "-d", f"nibbly_test_owner={os.getpid()}",
               "-d", f"nibbly_test_token={token}", "-d", "nibbly_test_group=1", *command[1:]]
    process = subprocess.Popen(command, start_new_session=True, **kwargs)
    _active[process.pid] = (process, token)
    return process


def stop_server(process):
    entry = _active.get(process.pid)
    if entry is None:
        return
    # A very early startup failure may occur before exec exposes the PHP marker.
    # This Popen object still owns the child, so its PID cannot be reused here.
    if process.poll() is None:
        try:
            os.killpg(process.pid, signal.SIGTERM)
        except ProcessLookupError:
            pass
    _stop_owned(process.pid, entry[1], True)
    process.wait(timeout=5)
    del _active[process.pid]


def _close_all():
    for process, _ in list(_active.values()):
        try:
            stop_server(process)
        except (OSError, RuntimeError, subprocess.SubprocessError) as error:
            print(f"Test server cleanup failed: {error}", file=sys.stderr)


atexit.register(_close_all)

if __name__ == "__main__":
    if sys.argv[1:] != ["reap"]:
        raise SystemExit("Usage: server_process.py reap")
    cleanup_stale_servers()
