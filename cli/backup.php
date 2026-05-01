#!/usr/bin/env php
<?php
/**
 * Nibbly site backup CLI runner.
 *
 * Designed to be wired into a system cron job for periodic full-site
 * backups. Reads its config from content/settings.json under the
 * `backup` key (see CLAUDE.md > Site Backups).
 *
 * Usage:
 *   php cli/backup.php --action=run         # one full backup + prune
 *   php cli/backup.php --action=prune       # apply retention only
 *   php cli/backup.php --action=status      # show current state
 *   php cli/backup.php --action=list        # list backup files
 *
 * Cron example (one nightly run, log appended):
 *   0 3 * * * cd /path/to/site && php cli/backup.php --action=run >> backups/backup.log 2>&1
 *
 * Exit codes:
 *   0  success (or status/list completed)
 *   1  failure (config missing, ZIP error, etc.)
 *   2  invalid arguments
 *   3  another run is in progress (lock contention)
 */

$projectRoot = dirname(__DIR__);

if (!file_exists($projectRoot . '/router.php')) {
    fwrite(STDERR, "Error: Run this script from the Nibbly project root.\n");
    fwrite(STDERR, "  cd /path/to/nibbly && php cli/backup.php --action=run\n");
    exit(1);
}

// ── CLI argument parsing ──────────────────────────────────────────────

$opts = [];
for ($i = 1; $i < count($argv); $i++) {
    $a = $argv[$i];
    if (preg_match('/^--([a-z-]+)=(.+)$/', $a, $m)) {
        $opts[$m[1]] = $m[2];
    } elseif (preg_match('/^--([a-z-]+)$/', $a, $m)) {
        $opts[$m[1]] = true;
    }
}

if (isset($opts['help']) || !isset($opts['action'])) {
    echo <<<USAGE
Nibbly Site Backup CLI

Usage:
  php cli/backup.php --action=ACTION

Actions:
  run       Create a new backup (auto-tier) and apply retention.
            This is what cron typically calls.
  prune     Apply retention rules and storage budget without creating
            a new backup. Useful after manually changing settings.
  status    Print current backup state, last run, retention settings.
  list      List backup files with size and tier.

Options:
  --tier=TIER       Force a specific tier (daily|weekly|monthly|yearly|manual)
                    instead of letting backupTierFor() pick. For run only.
  --quiet           Suppress per-file output, just print final summary.
  --help            Show this help.

Exit codes:
  0 success
  1 failure
  2 invalid arguments
  3 lock contention (another run in progress)

USAGE;
    exit(isset($opts['help']) ? 0 : 2);
}

$action = $opts['action'];
$quiet = !empty($opts['quiet']);

require_once $projectRoot . '/admin/config.php';
require_once $projectRoot . '/includes/backup-helper.php';

if (!defined('BACKUP_PATH')) {
    fwrite(STDERR, "Error: BACKUP_PATH not defined. Run admin/setup.php first.\n");
    exit(1);
}

if (!is_dir(BACKUP_PATH)) {
    @mkdir(BACKUP_PATH, 0755, true);
}

// ── Helpers ───────────────────────────────────────────────────────────

function fmtSize($bytes) {
    if ($bytes >= 1024 * 1024 * 1024) return number_format($bytes / 1024 / 1024 / 1024, 2) . ' GB';
    if ($bytes >= 1024 * 1024) return number_format($bytes / 1024 / 1024, 1) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

function withLock(callable $fn) {
    $lockPath = BACKUP_PATH . '.backup.lock';
    $stale = is_file($lockPath) && (time() - filemtime($lockPath)) > 1800; // 30 min
    if (is_file($lockPath) && !$stale) {
        fwrite(STDERR, "Another backup run is in progress (lock: $lockPath, age "
            . (time() - filemtime($lockPath)) . "s). Aborting.\n");
        exit(3);
    }
    if ($stale) {
        fwrite(STDERR, "Stale lock found (>30min), removing.\n");
        @unlink($lockPath);
    }
    file_put_contents($lockPath, (string)getmypid());
    try {
        return $fn();
    } finally {
        @unlink($lockPath);
    }
}

// ── Actions ───────────────────────────────────────────────────────────

switch ($action) {
    case 'run':
        $tier = $opts['tier'] ?? null;
        $config = backupConfig();
        if (!$config['enabled'] && !isset($opts['tier'])) {
            // Allow manual --tier override even when scheduled backups are off.
            fwrite(STDERR, "Backups are disabled in settings. Enable via dashboard or use --tier=manual to force one run.\n");
            exit(0);
        }

        $result = withLock(function() use ($tier) {
            $started = time();
            if (!$GLOBALS['quiet'] ?? false) {
                echo "[" . date('c', $started) . "] Backup run starting (tier="
                    . ($tier ?? 'auto') . ")...\n";
            }
            $r = backupRun($started);
            if ($tier !== null) {
                // Override tier if requested (backupRun used auto-pick)
                // — we just delete and re-create with the requested tier.
                if ($r['created']['ok']) {
                    @unlink(BACKUP_PATH . $r['created']['file']);
                    $r['created'] = backupCreate($tier, $started);
                }
            }
            return $r;
        });

        $created = $result['created'];
        if ($created['ok']) {
            echo "Created: {$created['file']} (" . fmtSize($created['size']) . ", tier={$created['tier']})\n";
            if (!empty($result['deleted'])) {
                echo "Pruned: " . count($result['deleted']) . " older backup(s)\n";
                if (!$quiet) {
                    foreach ($result['deleted'] as $f) echo "  - $f\n";
                }
            }
            $status = backupStatus();
            echo "Total stored: {$status['count']} backups, " . fmtSize($status['total_bytes']) . "\n";
            exit(0);
        } else {
            fwrite(STDERR, "Backup failed: " . $created['message'] . "\n");
            exit(1);
        }

    case 'prune':
        $deleted = withLock(fn() => backupPrune());
        echo "Pruned: " . count($deleted) . " backup(s)\n";
        if (!$quiet) {
            foreach ($deleted as $f) echo "  - $f\n";
        }
        $status = backupStatus();
        echo "Remaining: {$status['count']} backups, " . fmtSize($status['total_bytes']) . "\n";
        exit(0);

    case 'status':
        $s = backupStatus();
        echo "Scheduled backups: " . ($s['enabled'] ? 'enabled' : 'disabled') . "\n";
        echo "Retention:\n";
        foreach ($s['retention'] as $tier => $n) {
            echo "  $tier: keep $n\n";
        }
        echo "Storage limit: " . ($s['storage_limit_mb'] ? $s['storage_limit_mb'] . ' MB' : 'unlimited') . "\n";
        echo "\n";
        echo "Stored: {$s['count']} backups, " . fmtSize($s['total_bytes']) . "\n";
        echo "Newest: " . ($s['newest'] ? date('c', $s['newest']) : '-') . "\n";
        echo "Oldest: " . ($s['oldest'] ? date('c', $s['oldest']) : '-') . "\n";
        echo "Last run: " . ($s['last_run'] ?: '-') . " (" . ($s['last_status'] ?: '-') . ")\n";
        if ($s['last_message']) {
            echo "Last message: {$s['last_message']}\n";
        }
        exit(0);

    case 'list':
        $list = backupListAll();
        if (empty($list)) {
            echo "No backups found.\n";
            exit(0);
        }
        echo str_pad('FILE', 60) . str_pad('TIER', 10) . str_pad('SIZE', 12) . "DATE\n";
        echo str_repeat('-', 100) . "\n";
        foreach ($list as $b) {
            echo str_pad($b['file'], 60)
                . str_pad($b['tier'], 10)
                . str_pad(fmtSize($b['size']), 12)
                . date('Y-m-d H:i:s', $b['mtime']) . "\n";
        }
        exit(0);

    default:
        fwrite(STDERR, "Unknown action: $action (try --help)\n");
        exit(2);
}
