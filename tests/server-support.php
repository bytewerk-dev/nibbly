<?php

declare(strict_types=1);

/** Sequential PHP fixtures need one server process; no worker pool or watchdog. */
final class NibblyTestServer
{
    private $process;
    private static bool $signalsInstalled = false;

    public function __construct(array $command, ?string $cwd = null)
    {
        // Use the same ownership check as the Python tests before every start.
        $reaper = proc_open(
            [getenv('NIBBLY_TEST_PYTHON') ?: 'python3', __DIR__ . '/server_process.py', 'reap'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => STDERR], $pipes
        );
        if (!is_resource($reaper) || proc_close($reaper) !== 0) {
            throw new RuntimeException('Cannot check previous PHP test servers');
        }
        if (!self::$signalsInstalled && function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
                if (pcntl_signal_get_handler($signal) === SIG_DFL) {
                    pcntl_signal($signal, static function (int $signal): void { exit(128 + $signal); });
                }
            }
            self::$signalsInstalled = true;
        }
        $env = getenv();
        unset($env['PHP_CLI_SERVER_WORKERS']);
        $command = [$command[0], '-d', 'nibbly_test_owner=' . getmypid(),
            '-d', 'nibbly_test_token=' . bin2hex(random_bytes(16)), '-d', 'nibbly_test_group=0',
            ...array_slice($command, 1)];
        $this->process = proc_open($command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes, $cwd, $env);
        if (!is_resource($this->process)) {
            throw new RuntimeException('Cannot start the PHP test server');
        }
        register_shutdown_function([$this, 'close']);
    }

    public function running(): bool
    {
        return is_resource($this->process) && proc_get_status($this->process)['running'];
    }

    public function close(): void
    {
        if (!is_resource($this->process)) {
            return;
        }
        if ($this->running()) {
            proc_terminate($this->process);
            $deadline = microtime(true) + 1;
            while ($this->running() && microtime(true) < $deadline) {
                usleep(10000);
            }
            if ($this->running()) {
                proc_terminate($this->process, 9);
            }
        }
        proc_close($this->process);
        $this->process = null;
    }
}
