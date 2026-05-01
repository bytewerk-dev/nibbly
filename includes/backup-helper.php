<?php
/**
 * Site backup helper.
 *
 * One-stop module for everything related to whole-site ZIP backups:
 * creation, retention pruning (grandfather-father-son), storage
 * accounting, and schedule decisions. Used by both the on-demand
 * admin API endpoint and the cron-driven CLI runner so the two paths
 * can never drift apart.
 *
 * Configuration lives in content/settings.json under the top-level
 * `backup` key — see backupConfig() for defaults.
 *
 * Backup filenames follow this scheme so the prune algorithm can
 * group them by tier:
 *   site-backup-YYYY-MM-DD_HHMMSS-{tier}.zip
 * where {tier} is daily | weekly | monthly | yearly | manual.
 *
 * IMPORTANT: this file must NOT depend on $_SESSION, $_GET, or any
 * web-request state — it has to run from a CLI cron context where
 * none of that exists.
 */

if (!defined('BACKUP_PATH')) {
    // Allow standalone include from CLI before config.php has run.
    $configPath = __DIR__ . '/../admin/config.php';
    if (is_file($configPath)) {
        require_once $configPath;
    }
}

/** Default backup config — merged over content/settings.json `backup` key. */
function backupDefaults() {
    return [
        'enabled'          => false,
        'retention'        => [
            'daily'   => 7,
            'weekly'  => 4,
            'monthly' => 12,
            'yearly'  => 3,
        ],
        'storage_limit_mb' => 2048,
        'last_run'         => null,
        'last_status'      => null,  // 'success' | 'error' | null
        'last_message'     => null,
    ];
}

/** Load and normalise the backup config from settings.json. */
function backupConfig() {
    $settingsPath = __DIR__ . '/../content/settings.json';
    $settings = is_file($settingsPath) ? json_decode(file_get_contents($settingsPath), true) : [];
    $config = $settings['backup'] ?? [];
    $merged = array_replace_recursive(backupDefaults(), is_array($config) ? $config : []);
    // Normalise retention numeric values
    foreach (['daily', 'weekly', 'monthly', 'yearly'] as $tier) {
        $merged['retention'][$tier] = max(0, (int)($merged['retention'][$tier] ?? 0));
    }
    $merged['storage_limit_mb'] = max(0, (int)($merged['storage_limit_mb'] ?? 0));
    return $merged;
}

/** Persist the backup config back to settings.json (merging with existing keys). */
function backupSaveConfig(array $patch) {
    $settingsPath = __DIR__ . '/../content/settings.json';
    $settings = is_file($settingsPath) ? json_decode(file_get_contents($settingsPath), true) : [];
    if (!is_array($settings)) $settings = [];
    $settings['backup'] = array_replace_recursive($settings['backup'] ?? [], $patch);
    return file_put_contents(
        $settingsPath,
        json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ) !== false;
}

/**
 * Determine which tier a new backup at $now should be tagged as.
 * Picks the highest tier that still has a free slot for $now's bucket
 * (year > month > week > day), so a single nightly run automatically
 * fills weekly/monthly/yearly slots when the current week/month/year
 * has no backup yet.
 */
function backupTierFor($now = null) {
    $now = $now ?: time();
    $existing = backupListAll();

    $year = (int)date('Y', $now);
    $hasYearly = false;
    $month = (int)date('Ym', $now);
    $hasMonthly = false;
    // Use ISO week so Monday-Sunday boundaries match common cron schedules.
    $week = (int)date('oW', $now);
    $hasWeekly = false;

    foreach ($existing as $b) {
        $t = $b['mtime'];
        if ($b['tier'] === 'yearly'  && (int)date('Y', $t)  === $year)  $hasYearly  = true;
        if ($b['tier'] === 'monthly' && (int)date('Ym', $t) === $month) $hasMonthly = true;
        if ($b['tier'] === 'weekly'  && (int)date('oW', $t) === $week)  $hasWeekly  = true;
    }

    if (!$hasYearly)  return 'yearly';
    if (!$hasMonthly) return 'monthly';
    if (!$hasWeekly)  return 'weekly';
    return 'daily';
}

/** List all scheduled site backups, newest first. Manual ones included. */
function backupListAll() {
    if (!defined('BACKUP_PATH')) return [];
    $files = glob(BACKUP_PATH . 'site-backup-*.zip') ?: [];
    $out = [];
    foreach ($files as $path) {
        $name = basename($path);
        if (!preg_match('/^site-backup-(\d{4}-\d{2}-\d{2})_(\d{6})-(daily|weekly|monthly|yearly|manual)\.zip$/', $name, $m)) {
            // Legacy/unknown filename — surface but tag as manual.
            $out[] = [
                'file'  => $name,
                'tier'  => 'manual',
                'mtime' => filemtime($path) ?: 0,
                'size'  => filesize($path) ?: 0,
            ];
            continue;
        }
        $ts = strtotime($m[1] . ' ' . substr($m[2], 0, 2) . ':' . substr($m[2], 2, 2) . ':' . substr($m[2], 4, 2));
        $out[] = [
            'file'  => $name,
            'tier'  => $m[3],
            'mtime' => $ts ?: filemtime($path),
            'size'  => filesize($path) ?: 0,
        ];
    }
    usort($out, fn($a, $b) => $b['mtime'] - $a['mtime']);
    return $out;
}

/** Sum of all backup ZIP sizes in bytes. */
function backupTotalSize() {
    $total = 0;
    foreach (backupListAll() as $b) $total += $b['size'];
    return $total;
}

/**
 * Build a fresh site-backup ZIP and write it to BACKUP_PATH.
 * Returns ['ok' => bool, 'file' => ..., 'size' => ..., 'message' => ...].
 */
function backupCreate($tier = null, $now = null) {
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'message' => 'PHP ZIP extension not available.'];
    }
    if (!defined('BACKUP_PATH')) {
        return ['ok' => false, 'message' => 'BACKUP_PATH not defined — run setup first.'];
    }

    $now = $now ?: time();
    $tier = $tier ?: backupTierFor($now);
    if (!in_array($tier, ['daily', 'weekly', 'monthly', 'yearly', 'manual'], true)) {
        $tier = 'manual';
    }

    if (!is_dir(BACKUP_PATH)) {
        @mkdir(BACKUP_PATH, 0755, true);
    }

    // Disk space sanity check (need at least 100 MB free for the ZIP).
    $free = @disk_free_space(BACKUP_PATH);
    if ($free !== false && $free < 100 * 1024 * 1024) {
        return ['ok' => false, 'message' => 'Not enough disk space (less than 100 MB free).'];
    }

    $siteRoot = realpath(__DIR__ . '/..');
    $stamp = date('Y-m-d_His', $now);
    $filename = "site-backup-{$stamp}-{$tier}.zip";
    $zipPath = BACKUP_PATH . $filename;

    $excludeDirs = ['node_modules', '.git', 'screenshots', 'reference', '.vscode', '.idea', '.claude', 'vendor'];
    $excludeFiles = ['.DS_Store', 'Thumbs.db'];

    @set_time_limit(300);

    $zip = new ZipArchive();
    $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($opened !== true) {
        return ['ok' => false, 'message' => "Could not create ZIP archive (code $opened)."];
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($siteRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        $filePath = $file->getRealPath();
        if ($filePath === false) continue;
        $rel = substr($filePath, strlen($siteRoot) + 1);

        $skip = false;
        foreach ($excludeDirs as $d) {
            if (str_starts_with($rel, $d . DIRECTORY_SEPARATOR) || $rel === $d) {
                $skip = true;
                break;
            }
        }
        if ($skip) continue;

        $base = basename($rel);
        if (in_array($base, $excludeFiles, true)) continue;
        if (str_ends_with($base, '.tmp') || str_ends_with($base, '.swp')) continue;

        // Don't recurse into our own backup output.
        if (str_starts_with($rel, 'backups' . DIRECTORY_SEPARATOR) &&
            str_starts_with($base, 'site-backup-') && str_ends_with($base, '.zip')) {
            continue;
        }

        if ($file->isDir()) {
            $zip->addEmptyDir($rel);
        } else {
            $zip->addFile($filePath, $rel);
        }
    }

    $zip->close();

    if (!is_file($zipPath)) {
        return ['ok' => false, 'message' => 'ZIP file was not written to disk.'];
    }

    return [
        'ok'   => true,
        'file' => $filename,
        'size' => filesize($zipPath) ?: 0,
        'tier' => $tier,
    ];
}

/**
 * Apply retention rules and storage budget. Returns the list of files
 * that were deleted. Algorithm:
 *   1. Group backups by tier.
 *   2. In each tier, keep the newest N (config.retention[tier]); delete
 *      the rest. Manual backups are exempt from tier-based pruning.
 *   3. If total size still exceeds storage_limit_mb, delete oldest
 *      backups (excluding manual) until under budget.
 */
function backupPrune() {
    $config = backupConfig();
    $all = backupListAll();
    $deleted = [];

    // Tier-based pruning.
    $byTier = ['daily' => [], 'weekly' => [], 'monthly' => [], 'yearly' => []];
    foreach ($all as $b) {
        if (isset($byTier[$b['tier']])) $byTier[$b['tier']][] = $b;
    }
    foreach ($byTier as $tier => $list) {
        $keep = $config['retention'][$tier] ?? 0;
        if (count($list) <= $keep) continue;
        // $list is newest-first (backupListAll sorts that way)
        foreach (array_slice($list, $keep) as $old) {
            $path = BACKUP_PATH . $old['file'];
            if (@unlink($path)) {
                $deleted[] = $old['file'];
            }
        }
    }

    // Storage budget enforcement (re-list since tier prune ran).
    $budgetBytes = (int)$config['storage_limit_mb'] * 1024 * 1024;
    if ($budgetBytes > 0) {
        $remaining = backupListAll();
        // Sort oldest first for eviction; protect manual backups.
        $evictable = array_filter($remaining, fn($b) => $b['tier'] !== 'manual');
        usort($evictable, fn($a, $b) => $a['mtime'] - $b['mtime']);
        $total = 0;
        foreach ($remaining as $b) $total += $b['size'];
        foreach ($evictable as $b) {
            if ($total <= $budgetBytes) break;
            $path = BACKUP_PATH . $b['file'];
            if (@unlink($path)) {
                $deleted[] = $b['file'];
                $total -= $b['size'];
            }
        }
    }

    return $deleted;
}

/**
 * One full scheduled-backup run: create + prune + persist run metadata
 * to settings.json. Used by the CLI cron entrypoint.
 */
function backupRun($now = null) {
    $now = $now ?: time();
    $created = backupCreate(null, $now);
    $deleted = $created['ok'] ? backupPrune() : [];

    backupSaveConfig([
        'last_run'     => date('c', $now),
        'last_status'  => $created['ok'] ? 'success' : 'error',
        'last_message' => $created['ok']
            ? "Created {$created['file']} (" . number_format($created['size'] / 1024 / 1024, 1) . " MB), pruned " . count($deleted) . "."
            : ($created['message'] ?? 'Unknown error'),
    ]);

    return ['created' => $created, 'deleted' => $deleted];
}

/** Quick status snapshot — used by both the dashboard and `cli/backup.php --action=status`. */
function backupStatus() {
    $config = backupConfig();
    $list = backupListAll();
    $totalBytes = 0;
    foreach ($list as $b) $totalBytes += $b['size'];

    return [
        'enabled'          => (bool)$config['enabled'],
        'retention'        => $config['retention'],
        'storage_limit_mb' => $config['storage_limit_mb'],
        'last_run'         => $config['last_run'],
        'last_status'      => $config['last_status'],
        'last_message'     => $config['last_message'],
        'count'            => count($list),
        'total_bytes'      => $totalBytes,
        'newest'           => $list[0]['mtime'] ?? null,
        'oldest'           => end($list)['mtime'] ?? null,
    ];
}
