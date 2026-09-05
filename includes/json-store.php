<?php
/** Atomic JSON writes and transactions for shared flat-file records. */

function nibblyJsonLock(string $path) {
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
    $handle = @fopen($path . '.lock', 'c');
    if ($handle === false) return false;
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return false;
    }
    return $handle;
}

function nibblyJsonAtomicWrite(string $path, array $data): bool {
    try {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return false;
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
    $temporary = @tempnam($dir, '.nibbly-json-');
    if ($temporary === false) return false;
    try {
        if (file_put_contents($temporary, $json) !== strlen($json)) return false;
        @chmod($temporary, is_file($path) ? (fileperms($path) & 0777) : (0666 & ~umask()));
        return rename($temporary, $path);
    } finally {
        if (is_file($temporary)) @unlink($temporary);
    }
}

/** The callback edits the array by reference; false aborts without writing. */
function nibblyJsonUpdate(string $path, callable $update, array $default = []): bool {
    $lock = nibblyJsonLock($path);
    if ($lock === false) return false;
    try {
        $data = $default;
        if (is_file($path)) {
            try {
                $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                return false; // Never replace a damaged store with empty data.
            }
            if (!is_array($data)) return false;
        }
        if ($update($data) === false) return false;
        return nibblyJsonAtomicWrite($path, $data);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
        // Keep the lock inode stable for other processes already waiting.
    }
}

/** Revision of the stored bytes, including an explicit missing-file version. */
function nibblyJsonRevision(string $path): string {
    return is_file($path) ? (hash_file('sha256', $path) ?: 'unreadable') : 'missing';
}
