<?php
/** Stage a restore before touching live files; roll back ordinary I/O failures. */

require_once __DIR__ . '/project-files.php';

function nibblyRestoreTarget(string $root, string $entry): string {
    if (!NibblyProjectFiles::validPath($entry)) throw new RuntimeException('Unsafe restore path.');
    $target = $root;
    foreach (explode('/', $entry) as $segment) {
        $target .= '/' . $segment;
        if (is_link($target)) throw new RuntimeException('Restore path contains a symbolic link: ' . $entry);
    }
    return $target;
}

function nibblyRestoreFiles(ZipArchive $zip, array $entries, string $root, bool $clearContent, array $directories = [], ?NibblyProjectFiles $policy = null): int {
    $stage = $root . '/backups/.restore-' . bin2hex(random_bytes(8));
    if (!mkdir($stage, 0700, true)) throw new RuntimeException('Could not stage restore.');
    $operations = [];
    $applied = [];
    $keepRecovery = false;
    $createdDirs = [];
    try {
        $totalSize = 0;
        foreach ($entries as $entry) {
            $target = nibblyRestoreTarget($root, $entry);
            if (is_dir($target)) throw new RuntimeException('Restore target is a directory: ' . $entry);
            if (isset($operations[$target])) throw new RuntimeException('Duplicate restore entry: ' . $entry);
            $stat = $zip->statName($entry);
            if (!$stat) throw new RuntimeException('Missing archive entry: ' . $entry);
            $totalSize += (int)$stat['size'];
            if ($totalSize > 2 * 1024 * 1024 * 1024) throw new RuntimeException('Expanded backup exceeds 2 GB.');
            $new = $stage . '/new-' . count($operations);
            $input = $zip->getStream($entry);
            $output = fopen($new, 'wb');
            if (!$input || !$output) {
                if (is_resource($input)) fclose($input);
                if (is_resource($output)) fclose($output);
                throw new RuntimeException('Could not read archive entry: ' . $entry);
            }
            try {
                $written = stream_copy_to_stream($input, $output);
            } finally {
                fclose($input);
                fclose($output);
            }
            if ($written !== (int)$stat['size'] || strtolower(hash_file('crc32b', $new)) !== sprintf('%08x', $stat['crc'])) {
                throw new RuntimeException('Archive entry is incomplete or corrupt: ' . $entry);
            }
            if (strtolower(pathinfo($entry, PATHINFO_EXTENSION)) === 'json') {
                json_decode((string)file_get_contents($new), true, 512, JSON_THROW_ON_ERROR);
            }
            $operations[$target] = ['new' => $new];
        }
        if ($clearContent) {
            foreach (['content/pages', 'content/news'] as $directory) {
                foreach (glob(nibblyRestoreTarget($root, $directory) . '/*') ?: [] as $target) {
                    if ($policy && in_array($policy->role($directory . '/' . basename($target)), ['local', 'private'], true)) continue;
                    if (is_link($target)) throw new RuntimeException('Content directory contains symbolic links.');
                    if (is_file($target) && !isset($operations[$target]) && !str_ends_with($target, '.lock')) {
                        $operations[$target] = ['new' => null];
                    }
                }
            }
        }
        foreach (array_keys($operations) as $target) {
            $parent = dirname(substr($target, strlen($root) + 1));
            if ($parent !== '.') $directories[] = $parent;
        }
        foreach (array_unique($directories) as $directory) {
            $target = nibblyRestoreTarget($root, $directory);
            $missing = [];
            while (!is_dir($target) && $target !== $root) {
                $missing[] = $target;
                $target = dirname($target);
            }
            foreach (array_reverse($missing) as $dir) {
                if (!mkdir($dir, 0755)) throw new RuntimeException('Could not create restore directory: ' . $dir);
                $createdDirs[] = $dir;
            }
        }
        // Preserve every old file before applying the first replacement.
        $oldIndex = 0;
        foreach ($operations as $target => &$operation) {
            $parent = dirname($target);
            if (!is_writable($parent)) throw new RuntimeException('Restore directory is not writable: ' . $parent);
            $operation['old'] = null;
            if (is_file($target)) {
                $operation['old'] = $stage . '/old-' . $oldIndex++;
                if (!copy($target, $operation['old'])) throw new RuntimeException('Could not preserve file before restore.');
                chmod($operation['old'], fileperms($target) & 0777);
            }
            if ($operation['new'] !== null) {
                $mode = is_file($target) ? (fileperms($target) & 0777) : (0666 & ~umask());
                if ($target === $root . '/content/ai-secrets.json') $mode = 0600;
                if (!chmod($operation['new'], $mode)) throw new RuntimeException('Could not set restore file permissions.');
            }
        }
        unset($operation);
        foreach ($operations as $target => $operation) {
            $ok = $operation['new'] === null ? unlink($target) : rename($operation['new'], $target);
            if (!$ok) throw new RuntimeException('Could not restore file: ' . $target);
            $applied[] = $target;
        }
        return count($entries);
    } catch (Throwable $error) {
        foreach (array_reverse($applied) as $target) {
            $old = $operations[$target]['old'];
            $ok = $old !== null ? @rename($old, $target) : (!file_exists($target) || @unlink($target));
            if (!$ok) $keepRecovery = true;
        }
        foreach (array_reverse($createdDirs) as $dir) @rmdir($dir);
        if ($keepRecovery) throw new RuntimeException('Restore failed; some files need manual recovery from ' . $stage, 0, $error);
        throw $error;
    } finally {
        if (!$keepRecovery) {
            foreach (glob($stage . '/*') ?: [] as $file) @unlink($file);
            @rmdir($stage);
        }
    }
}
