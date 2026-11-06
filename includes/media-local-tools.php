<?php
/**
 * Local tools for the media library: reveal a file in the file manager (Finder, Explorer, …), open it with
 * its default application (Photoshop, …) or hand out its absolute path. Only available when Nibbly runs on
 * the editor's own machine: NIBBLY_LOCAL_TOOLS is true (admin/config.php, default true like NIBBLY_DEV_LOGIN),
 * the request comes from a loopback address to a loopback host, and PHP may run shell commands. On a web
 * host nothing of this is offered; the library hides the buttons when list-media reports no localTools.
 */

function nibblyLocalToolsEnabled(): bool {
    if (defined('NIBBLY_LOCAL_TOOLS') && NIBBLY_LOCAL_TOOLS !== true) return false;
    if (!function_exists('nibblySessionIsLoopback')) require_once __DIR__ . '/session-helper.php';
    return nibblySessionIsLoopback((string)($_SERVER['REMOTE_ADDR'] ?? ''))
        && nibblySessionIsLoopback((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
}

/** mac, windows or linux (anything else POSIX-like is treated as linux: xdg-open). */
function nibblyLocalToolsPlatform(): string {
    return match (PHP_OS_FAMILY) {
        'Darwin' => 'mac',
        'Windows' => 'windows',
        default => 'linux',
    };
}

function nibblyLocalToolsCanExec(): bool {
    if (!function_exists('exec')) return false;
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array('exec', $disabled, true);
}

/** What the library gets from list-media: null when nothing is available here. */
function nibblyLocalToolsInfo(): ?array {
    if (!nibblyLocalToolsEnabled()) return null;
    return [
        'platform' => nibblyLocalToolsPlatform(),
        // Without exec the path can still be copied; reveal/open need a shell
        'commands' => nibblyLocalToolsCanExec(),
    ];
}

/** Absolute path of a library file, null outside the media directory. */
function nibblyLocalToolsPath(string $baseDir, string $relative): ?string {
    $base = realpath($baseDir);
    $file = realpath($baseDir . $relative);
    if ($base === false || $file === false || !is_file($file)) return null;
    if (!str_starts_with($file, rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) return null;
    return $file;
}

/**
 * Run the local action: 'reveal' selects the file in the file manager, 'open' starts its default application.
 * Returns true when the command could be started. Windows: explorer exits with code 1 even on success, so only the
 * start of the command counts there; the GUI commands return immediately on every platform.
 */
function nibblyLocalToolsRun(string $what, string $file): bool {
    if (!in_array($what, ['reveal', 'open'], true) || !nibblyLocalToolsCanExec()) return false;
    $platform = nibblyLocalToolsPlatform();
    $arg = escapeshellarg($file);
    if ($platform === 'windows') {
        // escapeshellarg wraps in double quotes on Windows; start needs an empty title argument first
        $command = $what === 'reveal'
            ? 'explorer /select,' . $arg
            : 'cmd /c start "" ' . $arg;
        exec($command . ' 2>NUL', $out, $code);
        return true;
    }
    if ($platform === 'mac') {
        $command = $what === 'reveal' ? 'open -R ' . $arg : 'open ' . $arg;
    } else {
        $command = 'xdg-open ' . ($what === 'reveal' ? escapeshellarg(dirname($file)) : $arg);
    }
    exec($command . ' >/dev/null 2>&1', $out, $code);
    return $code === 0;
}
