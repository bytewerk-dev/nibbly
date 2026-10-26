#!/usr/bin/env python3
"""Real PHP worker cleanup, restart, failed startup and interrupted test owners."""
import json
import os
from pathlib import Path
import re
import signal
import socket
import subprocess
import sys
import tempfile
import time
from unittest.mock import patch

from support import Site
from server_process import start_server, stop_server

TESTS = Path(__file__).resolve().parent


def eventually(check, message, timeout=6):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        value = check()
        if value:
            return value
        time.sleep(.03)
    raise AssertionError(message)


def php_processes(root):
    # Observe the real process table independently of the cleanup helper's markers.
    result = []
    output = subprocess.check_output(['ps', '-axo', 'pid=,pgid=,stat=,command='], text=True)
    for line in output.splitlines():
        fields = line.split(None, 3)
        if len(fields) == 4 and not fields[2].startswith('Z'):
            # Versioned binaries count too (/usr/bin/php8.1 on CI runners), as in server_process.py
            if re.fullmatch(r'php(?:\d+(?:\.\d+)*)?', Path(fields[3].split()[0]).name) and ' -S ' in fields[3] and str(root) in fields[3]:
                result.append((int(fields[0]), int(fields[1])))
    return result


def free_port():
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        return sock.getsockname()[1]


def reachable(port):
    try:
        with socket.create_connection(('127.0.0.1', port), timeout=.1):
            return True
    except OSError:
        return False


def fixture(root):
    root.mkdir()
    (root / 'router.php').write_text("<?php echo 'fixture';\n")
    # Exercise the real Site lifecycle without copying the CMS for every case.
    site = Site.__new__(Site)
    site.root, site.process, site.port = root, None, 0
    return site


def assert_gone(root, port):
    eventually(lambda: not php_processes(root) and not reachable(port),
               f'PHP processes or listener remain for {root.name}')


PYTHON_OWNER = r'''
import json, os, sys
from pathlib import Path
sys.path.insert(0, sys.argv[1])
from support import Site
root, mode = Path(sys.argv[2]), sys.argv[3]
site = Site.__new__(Site)
site.root, site.process, site.port = root, None, 0
site.start(str(root / 'router.php'))
(root / 'ready.json').write_text(json.dumps({'port': site.port}))
sys.stdin.readline()
if mode == 'error':
    raise RuntimeError('intentional test failure')
if mode == 'exit':
    sys.exit(17)
if mode == 'abrupt':
    os._exit(18)
site.close()
site.close()
'''

PHP_OWNER = r'''<?php
require $argv[1] . '/server-support.php';
[$root, $mode, $port] = [$argv[2], $argv[3], (int)$argv[4]];
$server = new NibblyTestServer([PHP_BINARY, '-S', '127.0.0.1:' . $port, $root . '/router.php']);
$socket = false;
for ($i = 0; $i < 100 && !$socket; $i++) {
    $socket = @fsockopen('127.0.0.1', $port);
    if (!$socket) usleep(50000);
}
if (!$socket) throw new RuntimeException('fixture failed to start');
fclose($socket);
file_put_contents($root . '/ready.json', json_encode(['port' => $port]));
fgets(STDIN);
if ($mode === 'error') throw new RuntimeException('intentional test failure');
if ($mode === 'exit') exit(17);
$server->close();
$server->close();
'''


def main():
    if os.name != 'posix':
        print('SKIP PHP process groups: requires macOS/Linux')
        return
    os.environ['PHP_CLI_SERVER_WORKERS'] = '8'
    os.environ['NIBBLY_TEST_PYTHON'] = sys.executable
    pcntl = subprocess.check_output(['php', '-r', "echo function_exists('pcntl_async_signals') ? 'yes' : 'no';"], text=True) == 'yes'
    with tempfile.TemporaryDirectory(prefix='nibbly-server-lifecycle-') as folder:
        base = Path(folder)
        python_owner = base / 'owner.py'
        python_owner.write_text(PYTHON_OWNER)
        php_owner = base / 'owner.php'
        php_owner.write_text(PHP_OWNER)
        owners = []
        stubborn_process = None
        active = fixture(base / 'active-test')
        ordinary = fixture(base / 'ordinary-server')
        ordinary_port = free_port()
        ordinary_env = dict(os.environ)
        ordinary_env.pop('PHP_CLI_SERVER_WORKERS', None)
        unmarked = subprocess.Popen(
            ['php', '-S', f'127.0.0.1:{ordinary_port}', str(ordinary.root / 'router.php')],
            env=ordinary_env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            start_new_session=True)
        try:
            eventually(lambda: reachable(ordinary_port), 'ordinary server did not start')
            active.start(str(active.root / 'router.php'))
            active_port = active.port
            eventually(lambda: len(php_processes(active.root)) == 9, 'eight workers did not start')

            site = fixture(base / 'restart')
            for kill_main in (False, True):
                site.start(str(site.root / 'router.php'))
                eventually(lambda: len(php_processes(site.root)) == 9, 'restart workers missing')
                old_pids = {pid for pid, _ in php_processes(site.root)}
                old_port = site.port
                if kill_main:
                    site.process.kill()
                    site.process.wait(timeout=3)
                site.start(str(site.root / 'router.php'))
                assert not old_pids.intersection(pid for pid, _ in php_processes(site.root)), 'restart kept old workers'
                if site.port != old_port:
                    assert not reachable(old_port), 'old listener survived restart'
                site.close()
                site.close()
                assert_gone(site.root, site.port)
            print('PASS lifecycle: repeated start, idempotent close and already-dead main process', flush=True)

            failed = fixture(base / 'startup-timeout')
            with patch('support.socket.create_connection', side_effect=OSError('intentional readiness failure')):
                try:
                    failed.start(str(failed.root / 'router.php'))
                    raise AssertionError('startup should have timed out')
                except RuntimeError as error:
                    assert str(error) == 'Test server did not start', error
            assert failed.process is None and failed.log.closed
            assert_gone(failed.root, failed.port)
            print('PASS lifecycle: startup failure closes the log, listener and all workers', flush=True)

            for language in ('python', 'php'):
                modes = ['normal', 'error', 'exit', 'SIGINT', 'SIGTERM', 'SIGHUP', 'SIGKILL']
                if language == 'python':
                    modes.append('abrupt')
                for mode in modes:
                    site = fixture(base / f'{language}-{mode}')
                    command = ([sys.executable, str(python_owner)] if language == 'python'
                               else ['php', str(php_owner)])
                    command += [str(TESTS), str(site.root), mode, str(free_port())]
                    owner = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                                             stderr=subprocess.STDOUT, text=True, start_new_session=True)
                    owners.append(owner)
                    ready = site.root / 'ready.json'
                    eventually(lambda: ready.exists() or owner.poll() is not None, f'{language} owner not ready')
                    assert ready.exists(), owner.communicate(timeout=3)[0]
                    port = json.loads(ready.read_text())['port']
                    expected = 9 if language == 'python' else 1
                    eventually(lambda: len(php_processes(site.root)) == expected, f'{language} worker count differs')
                    if mode.startswith('SIG'):
                        owner.send_signal(getattr(signal, mode))
                        output = owner.communicate(timeout=8)[0]
                    else:
                        output = owner.communicate('\n', timeout=8)[0]
                    assert owner.returncode == 0 if mode == 'normal' else owner.returncode != 0, output
                    deferred = mode in ('SIGKILL', 'abrupt') or (
                        language == 'php' and not pcntl and mode.startswith('SIG'))
                    if deferred:
                        assert php_processes(site.root), 'hard-abort case did not leave a server to reap'
                        # Every new start must repair an earlier killed test, including
                        # PHP fixtures and worker groups whose original parent is gone.
                        retry = fixture(base / f'reap-{language}-{mode}')
                        if language == 'python':
                            retry.port = free_port()
                            subprocess.run(['php', '-r',
                                'require $argv[1]; $server = new NibblyTestServer([PHP_BINARY, "-S", '
                                '"127.0.0.1:" . $argv[3], $argv[2]]); $server->close();',
                                str(TESTS / 'server-support.php'), str(retry.root / 'router.php'),
                                str(retry.port)], check=True, timeout=8)
                        else:
                            retry.start(str(retry.root / 'router.php'))
                            retry.close()
                        assert_gone(retry.root, retry.port)
                    assert_gone(site.root, port)
                    assert reachable(active_port) and reachable(ordinary_port), 'cleanup stopped another server'
                print(f'PASS lifecycle: {language} normal exit, exception, exit(), signals and next-start recovery', flush=True)

            if pcntl:
                stubborn = base / 'stubborn.php'
                ready = base / 'stubborn.ready'
                stubborn.write_text("<?php pcntl_async_signals(true); pcntl_signal(SIGTERM, SIG_IGN); "
                                    "file_put_contents($argv[1], 'ready'); while (true) usleep(100000);")
                process = start_server(['php', str(stubborn), str(ready)],
                                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                stubborn_process = process
                eventually(ready.exists, 'stubborn process not ready')
                stop_server(process)
                assert process.returncode == -signal.SIGKILL, 'SIGTERM-resistant process was not escalated'
                print('PASS lifecycle: bounded escalation for a process ignoring SIGTERM', flush=True)
            assert reachable(active_port) and reachable(ordinary_port)
        finally:
            for owner in owners:
                if owner.poll() is None:
                    owner.kill()
                owner.communicate(timeout=5)
            if stubborn_process is not None:
                stop_server(stubborn_process)
            active.close()
            unmarked.terminate()
            unmarked.wait(timeout=5)
            # Failure cleanup is restricted to disposable fixtures created here,
            # including tests deliberately left orphaned to exercise recovery.
            for pid, group in php_processes(base):
                try:
                    os.kill(pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
            eventually(lambda: not php_processes(base), 'regression itself leaked PHP processes')
    print('PASS server lifecycle: no fixture PHP processes remain', flush=True)


if __name__ == '__main__':
    main()
