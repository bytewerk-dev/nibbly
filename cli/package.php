#!/usr/bin/env php
<?php
/** Preview or build an upload from the current installation. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../includes/backup-helper.php';
$root = dirname(__DIR__);
$action = $argv[1] ?? 'preview';
if (!in_array($action, ['preview', 'build', 'remote'], true)
    || ($action === 'build' ? $argc !== 3 : $argc > 2)) {
    echo "Usage: php cli/package.php preview | build NEW-DIRECTORY | remote < remote-path-list\n";
    exit($action === '--help' ? 0 : 2);
}

try {
    $policy = new NibblyProjectFiles($root);
    if ($action === 'remote') {
        // lftp find emits one relative path per line, with a slash for directories.
        while (($line = fgets(STDIN)) !== false) {
            $path = preg_replace('~^\./~', '', rtrim($line, "/\r\n"));
            if ($path === '.' || $path === '') continue;
            $role = $policy->role($path);
            if (in_array($role, ['local', 'private', 'unknown'], true)) echo "$role\t$path\n";
        }
        exit;
    }

    $problems = [];
    $files = [];
    foreach ($policy->walk($root, '', true) as $path => [$role, $directory]) {
        if ($action === 'preview') {
            $restore = $directory || in_array($role, ['local', 'private', 'unsupported'], true)
                ? '-' : backupRestoreDisposition($path, $policy);
            $backup = in_array($role, ['local', 'private', 'unsupported'], true) ? 'omit' : 'include';
            echo "$role\tbackup=$backup\trestore=$restore\t$path\n";
        }
        if ($role === 'unknown' || $role === 'unsupported') $problems[] = $path;
        if ($role === 'publish' || $role === 'seed') $files[$path] = [$role, $directory];
    }
    if ($problems) throw new RuntimeException("Review these paths before publishing:\n  " . implode("\n  ", $problems));
    if ($action === 'preview') exit;

    $output = rtrim($argv[2], DIRECTORY_SEPARATOR);
    $parent = realpath(dirname($output));
    if (!$parent || !is_dir($parent) || !NibblyProjectFiles::validPath(basename($output))
        || file_exists($output) || is_link($output)) throw new RuntimeException('Use a new directory in an existing parent.');
    $inside = str_replace(DIRECTORY_SEPARATOR, '/', $parent) . '/';
    $site = str_replace(DIRECTORY_SEPARATOR, '/', $root) . '/';
    if (str_starts_with($inside, $site) && !str_starts_with($inside, $site . 'local-only/')) {
        throw new RuntimeException('Create the package outside the installation or under local-only/.');
    }
    $stage = $parent . '/.nibbly-package-' . bin2hex(random_bytes(8));
    if (!mkdir($stage, 0700)) throw new RuntimeException('Could not stage package.');
    try {
        foreach (['webroot', 'seed'] as $tree) {
            if (!mkdir("$stage/$tree", 0755)) throw new RuntimeException('Could not create package directory.');
        }
        foreach ($files as $path => [$role, $directory]) {
            $tree = $role === 'publish' ? 'webroot' : 'seed';
            $target = "$stage/$tree/$path";
            $dir = $directory ? $target : dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException("Could not create $dir");
            if (!$directory) {
                $source = "$root/$path";
                if (realpath($source) !== str_replace('/', DIRECTORY_SEPARATOR, $source)
                    || !copy($source, $target) || !touch($target, filemtime($source))) {
                    throw new RuntimeException("Could not copy $path");
                }
            }
        }
        if (!rename($stage, $output)) throw new RuntimeException('Could not finish package.');
    } finally {
        if (is_dir($stage)) {
            $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            rmdir($stage);
        }
    }
    echo "Package created in $output\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
