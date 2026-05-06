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
 *   {site-domain}-backup-YYYY-MM-DD_HHMMSS-{tier}.zip
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

if (!class_exists('BackupLockException')) {
    class BackupLockException extends RuntimeException {}
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
        'remote_targets'   => [],
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
    $merged['remote_targets'] = backupNormalizeRemoteTargets($merged['remote_targets'] ?? []);
    return $merged;
}

function backupRemoteProviders() {
    $dropboxAppKey = defined('NIBBLY_DROPBOX_APP_KEY') ? NIBBLY_DROPBOX_APP_KEY : (getenv('NIBBLY_DROPBOX_APP_KEY') ?: '');
    $googleClientId = defined('NIBBLY_GOOGLE_CLIENT_ID') ? NIBBLY_GOOGLE_CLIENT_ID : (getenv('NIBBLY_GOOGLE_CLIENT_ID') ?: '');
    $onedriveClientId = defined('NIBBLY_ONEDRIVE_CLIENT_ID') ? NIBBLY_ONEDRIVE_CLIENT_ID : (getenv('NIBBLY_ONEDRIVE_CLIENT_ID') ?: '');
    $brokerUrl = backupRemoteOAuthBrokerUrl();
    return [
        'dropbox' => [
            'label' => 'Dropbox',
            'fields' => ['app_key', 'access_token', 'refresh_token', 'path'],
            'secret_fields' => ['access_token', 'refresh_token'],
            'has_global_oauth' => $dropboxAppKey !== '' || $brokerUrl !== '',
        ],
        'google_drive' => [
            'label' => 'Google Drive',
            'fields' => ['client_id', 'client_secret', 'access_token', 'refresh_token', 'folder_id'],
            'secret_fields' => ['client_secret', 'access_token', 'refresh_token'],
            'has_global_oauth' => $googleClientId !== '' || $brokerUrl !== '',
        ],
        'onedrive' => [
            'label' => 'Microsoft OneDrive',
            'fields' => ['client_id', 'client_secret', 'access_token', 'refresh_token', 'folder_path'],
            'secret_fields' => ['client_secret', 'access_token', 'refresh_token'],
            'has_global_oauth' => $onedriveClientId !== '' || $brokerUrl !== '',
        ],
        'sftp' => [
            'label' => 'SFTP / SCP',
            'fields' => ['host', 'port', 'username', 'password', 'remote_path'],
            'secret_fields' => ['password'],
        ],
        'ftp' => [
            'label' => 'FTP / FTPS',
            'fields' => ['host', 'port', 'username', 'password', 'remote_path', 'ssl', 'passive'],
            'secret_fields' => ['password'],
        ],
        's3' => [
            'label' => 'S3-Compatible',
            'fields' => ['endpoint', 'region', 'bucket', 'prefix', 'access_key', 'secret_key', 'path_style'],
            'secret_fields' => ['secret_key'],
        ],
        'webdav' => [
            'label' => 'WebDAV',
            'fields' => ['url', 'username', 'password', 'bearer_token'],
            'secret_fields' => ['password', 'bearer_token'],
        ],
    ];
}

function backupRemoteOAuthBrokerUrl() {
    if (defined('NIBBLY_AUTH_BROKER_URL')) {
        return rtrim((string)NIBBLY_AUTH_BROKER_URL, '/');
    }
    $env = getenv('NIBBLY_AUTH_BROKER_URL');
    if ($env !== false && $env !== '') {
        return rtrim($env, '/');
    }
    return 'https://auth.nibbly.dev';
}

function backupRemoteGlobalOAuthValue($type, $field) {
    $map = [
        'dropbox' => [
            'app_key' => 'NIBBLY_DROPBOX_APP_KEY',
        ],
        'google_drive' => [
            'client_id' => 'NIBBLY_GOOGLE_CLIENT_ID',
            'client_secret' => 'NIBBLY_GOOGLE_CLIENT_SECRET',
        ],
        'onedrive' => [
            'client_id' => 'NIBBLY_ONEDRIVE_CLIENT_ID',
            'client_secret' => 'NIBBLY_ONEDRIVE_CLIENT_SECRET',
        ],
    ];
    $constant = $map[$type][$field] ?? '';
    if ($constant === '') return '';
    if (defined($constant)) return constant($constant);
    return getenv($constant) ?: '';
}

function backupNormalizeRemoteTargets($targets) {
    if (!is_array($targets)) return [];
    $providers = backupRemoteProviders();
    $out = [];
    foreach ($targets as $target) {
        if (!is_array($target)) continue;
        $type = $target['type'] ?? '';
        if (!isset($providers[$type])) continue;
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($target['id'] ?? ''));
        if ($id === '') $id = $type . '-' . substr(sha1(json_encode($target)), 0, 8);
        $settings = is_array($target['settings'] ?? null) ? $target['settings'] : [];
        $cleanSettings = [];
        foreach ($providers[$type]['fields'] as $field) {
            if (array_key_exists($field, $settings)) {
                $cleanSettings[$field] = is_bool($settings[$field]) ? $settings[$field] : trim((string)$settings[$field]);
            }
        }
        foreach (['expires_at', 'account_id', 'oauth_broker'] as $field) {
            if (array_key_exists($field, $settings)) {
                $cleanSettings[$field] = is_bool($settings[$field]) ? $settings[$field] : trim((string)$settings[$field]);
            }
        }
        $out[] = [
            'id' => $id,
            'type' => $type,
            'name' => trim((string)($target['name'] ?? $providers[$type]['label'])),
            'enabled' => !empty($target['enabled']),
            'settings' => $cleanSettings,
            'last_upload' => $target['last_upload'] ?? null,
            'last_status' => $target['last_status'] ?? null,
            'last_message' => $target['last_message'] ?? null,
            'last_file' => $target['last_file'] ?? null,
        ];
    }
    return $out;
}

function backupRemotePublicTargets(array $targets) {
    $providers = backupRemoteProviders();
    return array_map(function($target) use ($providers) {
        $secretFields = $providers[$target['type']]['secret_fields'] ?? [];
        $public = $target;
        foreach ($secretFields as $field) {
            if (!empty($public['settings'][$field])) {
                $public['settings'][$field] = '********';
            }
        }
        return $public;
    }, $targets);
}

function backupRemoteMergeSubmittedTargets(array $submitted) {
    $current = backupConfig()['remote_targets'] ?? [];
    $byId = [];
    foreach ($current as $target) $byId[$target['id']] = $target;

    $providers = backupRemoteProviders();
    $merged = [];
    foreach ($submitted as $target) {
        if (!is_array($target)) continue;
        $type = $target['type'] ?? '';
        if (!isset($providers[$type])) continue;
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($target['id'] ?? ''));
        if ($id === '') $id = $type . '-' . substr(sha1(json_encode($target) . microtime(true)), 0, 8);
        $previous = $byId[$id] ?? null;
        $settings = is_array($target['settings'] ?? null) ? $target['settings'] : [];
        $cleanSettings = [];
        foreach ($providers[$type]['fields'] as $field) {
            $value = $settings[$field] ?? '';
            $isSecret = in_array($field, $providers[$type]['secret_fields'], true);
            if ($isSecret && ($value === '' || $value === '********') && $previous) {
                $cleanSettings[$field] = $previous['settings'][$field] ?? '';
                continue;
            }
            $isHiddenGlobalOAuthField = (
                ($type === 'dropbox' && $field === 'app_key' && !empty($providers[$type]['has_global_oauth'])) ||
                (($type === 'google_drive' || $type === 'onedrive') && ($field === 'client_id' || $field === 'client_secret') && !empty($providers[$type]['has_global_oauth']))
            );
            if ($isHiddenGlobalOAuthField && ($value === '' || $value === '********') && $previous) {
                $cleanSettings[$field] = $previous['settings'][$field] ?? '';
                continue;
            }
            $cleanSettings[$field] = is_bool($value) ? $value : trim((string)$value);
        }
        foreach (['expires_at', 'account_id', 'oauth_broker'] as $field) {
            if ($previous && array_key_exists($field, $previous['settings'])) {
                $cleanSettings[$field] = $previous['settings'][$field];
            }
        }
        $merged[] = [
            'id' => $id,
            'type' => $type,
            'name' => trim((string)($target['name'] ?? $providers[$type]['label'])),
            'enabled' => !empty($target['enabled']),
            'settings' => $cleanSettings,
            'last_upload' => $previous['last_upload'] ?? null,
            'last_status' => $previous['last_status'] ?? null,
            'last_message' => $previous['last_message'] ?? null,
            'last_file' => $previous['last_file'] ?? null,
        ];
    }
    return backupNormalizeRemoteTargets($merged);
}

function backupScrubSettingsForArchive($settingsPath) {
    $settings = is_file($settingsPath) ? json_decode(file_get_contents($settingsPath), true) : null;
    if (!is_array($settings)) return file_get_contents($settingsPath);
    if (!empty($settings['backup']['remote_targets']) && is_array($settings['backup']['remote_targets'])) {
        $providers = backupRemoteProviders();
        foreach ($settings['backup']['remote_targets'] as &$target) {
            $type = $target['type'] ?? '';
            $secretFields = $providers[$type]['secret_fields'] ?? [];
            foreach ($secretFields as $field) {
                if (isset($target['settings'][$field]) && $target['settings'][$field] !== '') {
                    $target['settings'][$field] = '';
                }
            }
        }
        unset($target);
    }
    return json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Return a stable site identifier for backup filenames and remote folders. */
function backupSiteIdentifier() {
    $raw = '';
    if (defined('NIBBLY_SITE_DOMAIN')) {
        $raw = (string)NIBBLY_SITE_DOMAIN;
    }
    if ($raw === '') {
        $env = getenv('NIBBLY_SITE_DOMAIN');
        if ($env !== false) $raw = (string)$env;
    }
    if ($raw === '' && !empty($_SERVER['HTTP_HOST'])) {
        $raw = (string)$_SERVER['HTTP_HOST'];
    }
    if ($raw === '') {
        $siteRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
        $parent = basename(dirname($siteRoot));
        $base = basename($siteRoot);
        $raw = strtolower($base) === 'httpdocs' && $parent !== '' ? $parent : $base;
    }

    $raw = strtolower(trim($raw));
    $raw = preg_replace('#^https?://#', '', $raw);
    $raw = preg_replace('#/.*$#', '', $raw);
    $raw = preg_replace('/:\d+$/', '', $raw);
    $raw = preg_replace('/^www\./', '', $raw);
    $raw = preg_replace('/[^a-z0-9._-]+/', '-', $raw);
    $raw = trim($raw, '.-_');
    return $raw !== '' ? $raw : 'nibbly-site';
}

function backupFilenamePattern() {
    return '/^(?!site-backup-)([a-z0-9][a-z0-9._-]*)-backup-(\d{4}-\d{2}-\d{2})_(\d{6})-(daily|weekly|monthly|yearly|manual)\.zip$/';
}

function backupParseFilename($name) {
    if (!preg_match(backupFilenamePattern(), $name, $m)) return null;
    $date = $m[2];
    $time = $m[3];
    $tier = $m[4];
    return [
        'site' => $m[1],
        'date' => $date,
        'time' => $time,
        'tier' => $tier,
        'mtime' => strtotime($date . ' ' . substr($time, 0, 2) . ':' . substr($time, 2, 2) . ':' . substr($time, 4, 2)),
    ];
}

function backupIsPoolFilename($name) {
    return backupParseFilename($name) !== null;
}

function backupRemoteFolder($base) {
    $base = trim((string)$base);
    if ($base === '') $base = '/Nibbly Backups';
    $site = backupSiteIdentifier();
    $trimmed = trim($base, '/');
    if ($trimmed === '') return '/' . $site;
    if (substr($trimmed, -strlen($site)) === $site) return '/' . $trimmed;
    return '/' . $trimmed . '/' . $site;
}

/** Shared lock for backup create/prune operations. */
function backupWithLock(callable $fn) {
    if (!defined('BACKUP_PATH')) {
        throw new BackupLockException('BACKUP_PATH not defined — run setup first.');
    }
    if (!is_dir(BACKUP_PATH)) {
        @mkdir(BACKUP_PATH, 0755, true);
    }

    $lockPath = BACKUP_PATH . '.backup.lock';
    $handle = @fopen($lockPath, 'c+');
    if ($handle === false) {
        throw new BackupLockException('Could not open backup lock file.');
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        throw new BackupLockException('Another backup run is already in progress.');
    }

    ftruncate($handle, 0);
    fwrite($handle, (string)getmypid());
    fflush($handle);
    try {
        return $fn();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
        @unlink($lockPath);
    }
}

/** Directories that should never be included in full-site ZIP backups. */
function backupExcludeDirs() {
    return [
        'node_modules',
        '.git',
        'screenshots',
        'reference',
        '.vscode',
        '.idea',
        '.claude',
        'vendor',
        'website',
        'nibbly-alt',
        'nibbly-backup',
    ];
}

/** File basenames that should never be included in full-site ZIP backups. */
function backupExcludeFiles() {
    return ['.DS_Store', 'Thumbs.db'];
}

/** Return whether a relative path should be skipped in a full-site ZIP. */
function backupShouldSkipPath($relativePath) {
    $relativePath = str_replace('\\', '/', $relativePath);
    foreach (backupExcludeDirs() as $dir) {
        if ($relativePath === $dir || str_starts_with($relativePath, $dir . '/')) {
            return true;
        }
    }

    $base = basename($relativePath);
    if (in_array($base, backupExcludeFiles(), true)) return true;
    if (str_ends_with($base, '.tmp') || str_ends_with($base, '.swp')) return true;

    // Keep old JSON page backups, but never include generated backup ZIPs/logs.
    if (str_starts_with($relativePath, 'backups/')) {
        if (str_ends_with($base, '.zip') || $base === '.backup.lock' || $base === 'backup.log') {
            return true;
        }
    }

    return false;
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
    backupMigrateShortLivedSiteBackupNames();
    $files = glob(BACKUP_PATH . '*-backup-*.zip') ?: [];
    $out = [];
    foreach ($files as $path) {
        $name = basename($path);
        $parsed = backupParseFilename($name);
        if (!$parsed) continue;
        $out[] = [
            'file'  => $name,
            'tier'  => $parsed['tier'],
            'mtime' => $parsed['mtime'] ?: filemtime($path),
            'size'  => filesize($path) ?: 0,
        ];
    }
    usort($out, fn($a, $b) => $b['mtime'] - $a['mtime']);
    return $out;
}

function backupMigrateShortLivedSiteBackupNames() {
    $files = glob(BACKUP_PATH . 'site-backup-*.zip') ?: [];
    if (empty($files)) return;
    $site = backupSiteIdentifier();
    foreach ($files as $path) {
        $name = basename($path);
        if (!preg_match('/^site-backup-(\d{4}-\d{2}-\d{2})_(\d{6})-(daily|weekly|monthly|yearly|manual)\.zip$/', $name, $m)) {
            continue;
        }
        $newName = "{$site}-backup-{$m[1]}_{$m[2]}-{$m[3]}.zip";
        $newPath = BACKUP_PATH . $newName;
        if (is_file($newPath)) continue;
        @rename($path, $newPath);
    }
}

/** Sum of all backup ZIP sizes in bytes. */
function backupTotalSize() {
    $total = 0;
    foreach (backupListAll() as $b) $total += $b['size'];
    return $total;
}

function backupRemoteRequireCurl() {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required for this remote target.');
    }
}

function backupRemoteCurl($url, array $options = []) {
    backupRemoteRequireCurl();
    $ch = curl_init($url);
    $headers = $options['headers'] ?? [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => $options['timeout'] ?? 300,
        CURLOPT_CUSTOMREQUEST => $options['method'] ?? 'GET',
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if (array_key_exists('body', $options)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $options['body']);
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException($error);
    }
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($raw, $headerSize);
    curl_close($ch);
    if ($status < 200 || $status >= 300) {
        $message = trim($body);
        if (strlen($message) > 240) $message = substr($message, 0, 240) . '...';
        throw new RuntimeException("Remote returned HTTP $status" . ($message !== '' ? ": $message" : '.'));
    }
    return ['status' => $status, 'body' => $body];
}

function backupRemoteUpload($filePath, array $target) {
    if (!is_file($filePath)) {
        return ['ok' => false, 'message' => 'Backup file not found.'];
    }
    try {
        switch ($target['type']) {
            case 'dropbox':
                backupRemoteUploadDropbox($filePath, $target);
                break;
            case 'google_drive':
                backupRemoteUploadGoogleDrive($filePath, $target);
                break;
            case 'onedrive':
                backupRemoteUploadOneDrive($filePath, $target);
                break;
            case 'sftp':
                backupRemoteUploadSftp($filePath, $target);
                break;
            case 'ftp':
                backupRemoteUploadFtp($filePath, $target);
                break;
            case 's3':
                backupRemoteUploadS3($filePath, $target);
                break;
            case 'webdav':
                backupRemoteUploadWebDav($filePath, $target);
                break;
            default:
                throw new RuntimeException('Unsupported remote target type.');
        }
        return ['ok' => true, 'message' => 'Upload complete.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

function backupRemoteList(array $target) {
    try {
        switch ($target['type']) {
            case 'dropbox':
                return backupRemoteListDropbox($target);
            case 'google_drive':
                return backupRemoteListGoogleDrive($target);
            case 'onedrive':
                return backupRemoteListOneDrive($target);
            case 'ftp':
                return backupRemoteListFtp($target);
            default:
                throw new RuntimeException('Remote listing is not supported for this target type yet.');
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage(), 'files' => []];
    }
}

function backupRemoteDownload($file, array $target, $destinationPath) {
    if (!backupIsPoolFilename($file)) {
        return ['ok' => false, 'message' => 'Invalid backup filename.'];
    }
    try {
        switch ($target['type']) {
            case 'dropbox':
                backupRemoteDownloadDropbox($file, $target, $destinationPath);
                break;
            case 'google_drive':
                backupRemoteDownloadGoogleDrive($file, $target, $destinationPath);
                break;
            case 'onedrive':
                backupRemoteDownloadOneDrive($file, $target, $destinationPath);
                break;
            case 'ftp':
                backupRemoteDownloadFtp($file, $target, $destinationPath);
                break;
            default:
                throw new RuntimeException('Remote download is not supported for this target type yet.');
        }
        return ['ok' => true, 'message' => 'Downloaded.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

function backupRemoteDelete($file, array $target) {
    if (!backupIsPoolFilename($file)) {
        return ['ok' => false, 'message' => 'Invalid backup filename.'];
    }
    try {
        switch ($target['type']) {
            case 'dropbox':
                backupRemoteDeleteDropbox($file, $target);
                break;
            case 'google_drive':
                backupRemoteDeleteGoogleDrive($file, $target);
                break;
            case 'onedrive':
                backupRemoteDeleteOneDrive($file, $target);
                break;
            case 'ftp':
                backupRemoteDeleteFtp($file, $target);
                break;
            default:
                throw new RuntimeException('Remote delete is not supported for this target type yet.');
        }
        return ['ok' => true, 'message' => 'Deleted.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

function backupRemoteRefreshTarget(array &$target) {
    $settings = $target['settings'];
    $refreshToken = $settings['refresh_token'] ?? '';
    $expiresAt = (int)($settings['expires_at'] ?? 0);
    if (!empty($settings['access_token']) && $expiresAt > time() + 120) return;
    if ($refreshToken === '') return;

    if ($target['type'] === 'dropbox') {
        $clientId = $settings['app_key'] ?? '';
        if ($clientId === '') $clientId = backupRemoteGlobalOAuthValue('dropbox', 'app_key');
        $brokerUrl = backupRemoteOAuthBrokerUrl();
        if (!empty($settings['oauth_broker']) || ($clientId === '' && $brokerUrl !== '')) {
            if ($brokerUrl === '') return;
            $response = backupRemoteCurl($brokerUrl . '/token/refresh', [
                'method' => 'POST',
                'headers' => ['Content-Type: application/x-www-form-urlencoded'],
                'body' => http_build_query([
                    'provider' => 'dropbox',
                    'refresh_token' => $refreshToken,
                ]),
                'timeout' => 60,
            ]);
            $brokerJson = json_decode($response['body'], true);
            if (!is_array($brokerJson) || empty($brokerJson['ok']) || empty($brokerJson['token']['access_token'])) {
                $message = is_array($brokerJson) ? ($brokerJson['message'] ?? 'Broker did not return an access token.') : 'Broker returned invalid JSON.';
                throw new RuntimeException('OAuth token refresh failed: ' . $message);
            }
            $json = $brokerJson['token'];
            $target['settings']['access_token'] = $json['access_token'];
            if (!empty($json['refresh_token'])) {
                $target['settings']['refresh_token'] = $json['refresh_token'];
            }
            if (!empty($json['expires_in'])) {
                $target['settings']['expires_at'] = time() + (int)$json['expires_in'];
            }
            if (!empty($json['app_key'])) {
                $target['settings']['app_key'] = $json['app_key'];
            }
            $target['settings']['oauth_broker'] = $brokerUrl;
            return;
        }
        $tokenUrl = 'https://api.dropboxapi.com/oauth2/token';
        $body = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
        ];
    } elseif ($target['type'] === 'google_drive') {
        $clientId = $settings['client_id'] ?? '';
        if ($clientId === '') $clientId = backupRemoteGlobalOAuthValue('google_drive', 'client_id');
        $brokerUrl = backupRemoteOAuthBrokerUrl();
        if (!empty($settings['oauth_broker']) || ($clientId === '' && $brokerUrl !== '')) {
            if ($brokerUrl === '') return;
            $response = backupRemoteCurl($brokerUrl . '/token/refresh', [
                'method' => 'POST',
                'headers' => ['Content-Type: application/x-www-form-urlencoded'],
                'body' => http_build_query([
                    'provider' => 'google_drive',
                    'refresh_token' => $refreshToken,
                ]),
                'timeout' => 60,
            ]);
            $brokerJson = json_decode($response['body'], true);
            if (!is_array($brokerJson) || empty($brokerJson['ok']) || empty($brokerJson['token']['access_token'])) {
                $message = is_array($brokerJson) ? ($brokerJson['message'] ?? 'Broker did not return an access token.') : 'Broker returned invalid JSON.';
                throw new RuntimeException('OAuth token refresh failed: ' . $message);
            }
            $json = $brokerJson['token'];
            $target['settings']['access_token'] = $json['access_token'];
            if (!empty($json['refresh_token'])) {
                $target['settings']['refresh_token'] = $json['refresh_token'];
            }
            if (!empty($json['expires_in'])) {
                $target['settings']['expires_at'] = time() + (int)$json['expires_in'];
            }
            if (!empty($json['client_id'])) {
                $target['settings']['client_id'] = $json['client_id'];
            }
            $target['settings']['oauth_broker'] = $brokerUrl;
            return;
        }
        $tokenUrl = 'https://oauth2.googleapis.com/token';
        $body = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
        ];
        $clientSecret = $settings['client_secret'] ?? backupRemoteGlobalOAuthValue('google_drive', 'client_secret');
        if ($clientSecret !== '') $body['client_secret'] = $clientSecret;
    } elseif ($target['type'] === 'onedrive') {
        $clientId = $settings['client_id'] ?? '';
        if ($clientId === '') $clientId = backupRemoteGlobalOAuthValue('onedrive', 'client_id');
        $brokerUrl = backupRemoteOAuthBrokerUrl();
        if (!empty($settings['oauth_broker']) || ($clientId === '' && $brokerUrl !== '')) {
            if ($brokerUrl === '') return;
            $response = backupRemoteCurl($brokerUrl . '/token/refresh', [
                'method' => 'POST',
                'headers' => ['Content-Type: application/x-www-form-urlencoded'],
                'body' => http_build_query([
                    'provider' => 'onedrive',
                    'refresh_token' => $refreshToken,
                ]),
                'timeout' => 60,
            ]);
            $brokerJson = json_decode($response['body'], true);
            if (!is_array($brokerJson) || empty($brokerJson['ok']) || empty($brokerJson['token']['access_token'])) {
                $message = is_array($brokerJson) ? ($brokerJson['message'] ?? 'Broker did not return an access token.') : 'Broker returned invalid JSON.';
                throw new RuntimeException('OAuth token refresh failed: ' . $message);
            }
            $json = $brokerJson['token'];
            $target['settings']['access_token'] = $json['access_token'];
            if (!empty($json['refresh_token'])) {
                $target['settings']['refresh_token'] = $json['refresh_token'];
            }
            if (!empty($json['expires_in'])) {
                $target['settings']['expires_at'] = time() + (int)$json['expires_in'];
            }
            if (!empty($json['client_id'])) {
                $target['settings']['client_id'] = $json['client_id'];
            }
            $target['settings']['oauth_broker'] = $brokerUrl;
            return;
        }
        $tokenUrl = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
        $body = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'scope' => 'offline_access Files.ReadWrite.AppFolder',
        ];
        $clientSecret = $settings['client_secret'] ?? backupRemoteGlobalOAuthValue('onedrive', 'client_secret');
        if ($clientSecret !== '') $body['client_secret'] = $clientSecret;
    } else {
        return;
    }
    if ($clientId === '') return;

    $response = backupRemoteCurl($tokenUrl, [
        'method' => 'POST',
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
        'body' => http_build_query($body),
        'timeout' => 60,
    ]);
    $json = json_decode($response['body'], true);
    if (!is_array($json) || empty($json['access_token'])) {
        throw new RuntimeException('OAuth token refresh failed: provider did not return an access token.');
    }
    $target['settings']['access_token'] = $json['access_token'];
    if (!empty($json['refresh_token'])) {
        $target['settings']['refresh_token'] = $json['refresh_token'];
    }
    if (!empty($json['expires_in'])) {
        $target['settings']['expires_at'] = time() + (int)$json['expires_in'];
    }
}

function backupRemoteUploadDropbox($filePath, array $target) {
    $settings = $target['settings'];
    $token = $settings['access_token'] ?? '';
    if ($token === '') throw new RuntimeException('Dropbox access token is missing.');
    $folder = backupRemoteFolder($settings['path'] ?? '/Nibbly Backups');
    backupRemoteEnsureDropboxFolder($token, $folder);
    $remotePath = ($folder === '/' ? '' : $folder) . '/' . basename($filePath);
    backupRemoteCurl('https://content.dropboxapi.com/2/files/upload', [
        'method' => 'POST',
        'headers' => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/octet-stream',
            'Dropbox-API-Arg: ' . json_encode([
                'path' => $remotePath,
                'mode' => 'overwrite',
                'autorename' => false,
                'mute' => false,
                'strict_conflict' => false,
            ], JSON_UNESCAPED_SLASHES),
        ],
        'body' => file_get_contents($filePath),
    ]);
}

function backupRemoteEnsureDropboxFolder($token, $folder) {
    $folder = '/' . trim($folder, '/');
    if ($folder === '/') return;
    $parts = array_values(array_filter(explode('/', trim($folder, '/'))));
    $path = '';
    foreach ($parts as $part) {
        $path .= '/' . $part;
        try {
            backupRemoteCurl('https://api.dropboxapi.com/2/files/create_folder_v2', [
                'method' => 'POST',
                'headers' => [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/json',
                ],
                'body' => json_encode(['path' => $path, 'autorename' => false], JSON_UNESCAPED_SLASHES),
            ]);
        } catch (RuntimeException $e) {
            if (stripos($e->getMessage(), 'conflict') === false) {
                throw $e;
            }
        }
    }
}

function backupRemoteDropboxPath(array $target, $file = '') {
    $settings = $target['settings'];
    $folder = backupRemoteFolder($settings['path'] ?? '/Nibbly Backups');
    return ($folder === '/' ? '' : $folder) . ($file !== '' ? '/' . basename($file) : '');
}

function backupRemoteListDropbox(array $target) {
    $token = $target['settings']['access_token'] ?? '';
    if ($token === '') throw new RuntimeException('Dropbox access token is missing.');
    $folder = backupRemoteDropboxPath($target);
    $files = [];
    $cursor = null;
    do {
        $url = $cursor === null
            ? 'https://api.dropboxapi.com/2/files/list_folder'
            : 'https://api.dropboxapi.com/2/files/list_folder/continue';
        $body = $cursor === null
            ? ['path' => $folder === '/' ? '' : $folder, 'recursive' => false, 'include_deleted' => false]
            : ['cursor' => $cursor];
        try {
            $result = backupRemoteCurl($url, [
                'method' => 'POST',
                'headers' => [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/json',
                ],
                'body' => json_encode($body, JSON_UNESCAPED_SLASHES),
            ]);
        } catch (RuntimeException $e) {
            if (stripos($e->getMessage(), 'path/not_found') !== false) {
                return ['ok' => true, 'message' => 'No remote backups found.', 'files' => []];
            }
            throw $e;
        }
        $json = json_decode($result['body'] ?? '', true);
        foreach (($json['entries'] ?? []) as $entry) {
            if (($entry['.tag'] ?? '') !== 'file') continue;
            $name = $entry['name'] ?? '';
            if (!backupIsPoolFilename($name)) continue;
            $parsed = backupParseFilename($name);
            $files[] = [
                'file' => $name,
                'tier' => $parsed['tier'],
                'mtime' => !empty($entry['client_modified']) ? strtotime($entry['client_modified']) : ($parsed['mtime'] ?: 0),
                'size' => (int)($entry['size'] ?? 0),
            ];
        }
        $cursor = !empty($json['has_more']) ? ($json['cursor'] ?? null) : null;
    } while ($cursor !== null);
    usort($files, fn($a, $b) => $b['mtime'] - $a['mtime']);
    return ['ok' => true, 'message' => count($files) . ' remote backup(s).', 'files' => $files];
}

function backupRemoteDownloadDropbox($file, array $target, $destinationPath) {
    $token = $target['settings']['access_token'] ?? '';
    if ($token === '') throw new RuntimeException('Dropbox access token is missing.');
    $remotePath = backupRemoteDropboxPath($target, $file);
    $result = backupRemoteCurl('https://content.dropboxapi.com/2/files/download', [
        'method' => 'POST',
        'headers' => [
            'Authorization: Bearer ' . $token,
            'Dropbox-API-Arg: ' . json_encode(['path' => $remotePath], JSON_UNESCAPED_SLASHES),
        ],
    ]);
    if (file_put_contents($destinationPath, $result['body']) === false) {
        throw new RuntimeException('Could not write downloaded backup.');
    }
}

function backupRemoteDeleteDropbox($file, array $target) {
    $token = $target['settings']['access_token'] ?? '';
    if ($token === '') throw new RuntimeException('Dropbox access token is missing.');
    $remotePath = backupRemoteDropboxPath($target, $file);
    backupRemoteCurl('https://api.dropboxapi.com/2/files/delete_v2', [
        'method' => 'POST',
        'headers' => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        'body' => json_encode(['path' => $remotePath], JSON_UNESCAPED_SLASHES),
    ]);
}

function backupRemoteUploadGoogleDrive($filePath, array $target) {
    $settings = $target['settings'];
    $token = $settings['access_token'] ?? '';
    if ($token === '') throw new RuntimeException('Google Drive access token is missing.');
    $metadata = ['name' => basename($filePath)];
    $siteFolderId = backupRemoteGoogleFolderPathId($token, $settings['folder_id'] ?? '', $settings['folder_path'] ?? '/Nibbly Backups');
    if ($siteFolderId !== '') $metadata['parents'] = [$siteFolderId];
    $boundary = 'nibbly-' . bin2hex(random_bytes(8));
    $body = "--$boundary\r\n"
        . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
        . json_encode($metadata, JSON_UNESCAPED_SLASHES) . "\r\n"
        . "--$boundary\r\n"
        . "Content-Type: application/zip\r\n\r\n"
        . file_get_contents($filePath) . "\r\n"
        . "--$boundary--";
    backupRemoteCurl('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart', [
        'method' => 'POST',
        'headers' => [
            'Authorization: Bearer ' . $token,
            'Content-Type: multipart/related; boundary=' . $boundary,
        ],
        'body' => $body,
    ]);
}

function backupRemoteGoogleFolderPathId($token, $parentId = '', $basePath = '/Nibbly Backups') {
    $folder = trim(backupRemoteFolder($basePath), '/');
    if ($folder === '') return '';
    $currentParent = trim((string)$parentId);
    foreach (array_values(array_filter(explode('/', $folder))) as $part) {
        $currentParent = backupRemoteGoogleFolderId($token, $part, $currentParent);
    }
    return $currentParent;
}

function backupRemoteGoogleFolderId($token, $name, $parentId = '') {
    $safeName = str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$name);
    $parentId = trim((string)$parentId);
    $parentClause = $parentId !== ''
        ? "'" . str_replace("'", "\\'", $parentId) . "' in parents"
        : "'root' in parents";
    $query = "mimeType='application/vnd.google-apps.folder' and name='{$safeName}' and trashed=false and {$parentClause}";
    $list = backupRemoteCurl('https://www.googleapis.com/drive/v3/files?' . http_build_query([
        'q' => $query,
        'fields' => 'files(id,name)',
        'pageSize' => 1,
    ]), [
        'headers' => ['Authorization: Bearer ' . $token],
    ]);
    $json = json_decode($list['body'] ?? '', true);
    if (!empty($json['files'][0]['id'])) return $json['files'][0]['id'];

    $metadata = [
        'name' => $name,
        'mimeType' => 'application/vnd.google-apps.folder',
    ];
    if ($parentId !== '') $metadata['parents'] = [$parentId];
    $created = backupRemoteCurl('https://www.googleapis.com/drive/v3/files?fields=id', [
        'method' => 'POST',
        'headers' => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json; charset=UTF-8',
        ],
        'body' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
    ]);
    $createdJson = json_decode($created['body'] ?? '', true);
    return $createdJson['id'] ?? '';
}

function backupRemoteGoogleSiteFolderId($token, $parentId = '') {
    $site = backupSiteIdentifier();
    $safeName = str_replace(["\\", "'"], ["\\\\", "\\'"], $site);
    $parentId = trim((string)$parentId);
    $parentClause = $parentId !== ''
        ? "'" . str_replace("'", "\\'", $parentId) . "' in parents"
        : "'root' in parents";
    $query = "mimeType='application/vnd.google-apps.folder' and name='{$safeName}' and trashed=false and {$parentClause}";
    $list = backupRemoteCurl('https://www.googleapis.com/drive/v3/files?' . http_build_query([
        'q' => $query,
        'fields' => 'files(id,name)',
        'pageSize' => 1,
    ]), [
        'headers' => ['Authorization: Bearer ' . $token],
    ]);
    $json = json_decode($list['body'] ?? '', true);
    if (!empty($json['files'][0]['id'])) return $json['files'][0]['id'];

    $metadata = [
        'name' => $site,
        'mimeType' => 'application/vnd.google-apps.folder',
    ];
    if ($parentId !== '') $metadata['parents'] = [$parentId];
    $created = backupRemoteCurl('https://www.googleapis.com/drive/v3/files?fields=id', [
        'method' => 'POST',
        'headers' => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json; charset=UTF-8',
        ],
        'body' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
    ]);
    $createdJson = json_decode($created['body'] ?? '', true);
    return $createdJson['id'] ?? '';
}

function backupRemoteGoogleFileId($file, array $target) {
    $settings = $target['settings'];
    $token = $settings['access_token'] ?? '';
    if ($token === '') throw new RuntimeException('Google Drive access token is missing.');
    $folderId = backupRemoteGoogleFolderPathId($token, $settings['folder_id'] ?? '', $settings['folder_path'] ?? '/Nibbly Backups');
    $safeName = str_replace(["\\", "'"], ["\\\\", "\\'"], basename($file));
    $query = "mimeType!='application/vnd.google-apps.folder' and name='{$safeName}' and trashed=false";
    if ($folderId !== '') {
        $query .= " and '" . str_replace("'", "\\'", $folderId) . "' in parents";
    }
    $result = backupRemoteCurl('https://www.googleapis.com/drive/v3/files?' . http_build_query([
        'q' => $query,
        'fields' => 'files(id,name,size,modifiedTime)',
        'pageSize' => 1,
    ]), [
        'headers' => ['Authorization: Bearer ' . $token],
    ]);
    $json = json_decode($result['body'] ?? '', true);
    return $json['files'][0] ?? null;
}

function backupRemoteListGoogleDrive(array $target) {
    $settings = $target['settings'];
    $token = $settings['access_token'] ?? '';
    if ($token === '') throw new RuntimeException('Google Drive access token is missing.');
    $folderId = backupRemoteGoogleFolderPathId($token, $settings['folder_id'] ?? '', $settings['folder_path'] ?? '/Nibbly Backups');
    if ($folderId === '') return ['ok' => true, 'message' => 'No remote backups found.', 'files' => []];

    $files = [];
    $pageToken = null;
    do {
        $params = [
            'q' => "'" . str_replace("'", "\\'", $folderId) . "' in parents and trashed=false",
            'fields' => 'nextPageToken,files(id,name,size,modifiedTime)',
            'pageSize' => 100,
            'orderBy' => 'modifiedTime desc',
        ];
        if ($pageToken) $params['pageToken'] = $pageToken;
        $result = backupRemoteCurl('https://www.googleapis.com/drive/v3/files?' . http_build_query($params), [
            'headers' => ['Authorization: Bearer ' . $token],
        ]);
        $json = json_decode($result['body'] ?? '', true);
        foreach (($json['files'] ?? []) as $entry) {
            $name = $entry['name'] ?? '';
            if (!backupIsPoolFilename($name)) continue;
            $parsed = backupParseFilename($name);
            $files[] = [
                'file' => $name,
                'tier' => $parsed['tier'],
                'mtime' => !empty($entry['modifiedTime']) ? strtotime($entry['modifiedTime']) : ($parsed['mtime'] ?: 0),
                'size' => (int)($entry['size'] ?? 0),
            ];
        }
        $pageToken = $json['nextPageToken'] ?? null;
    } while ($pageToken);
    usort($files, fn($a, $b) => $b['mtime'] - $a['mtime']);
    return ['ok' => true, 'message' => count($files) . ' remote backup(s).', 'files' => $files];
}

function backupRemoteDownloadGoogleDrive($file, array $target, $destinationPath) {
    $token = $target['settings']['access_token'] ?? '';
    if ($token === '') throw new RuntimeException('Google Drive access token is missing.');
    $entry = backupRemoteGoogleFileId($file, $target);
    if (!$entry || empty($entry['id'])) throw new RuntimeException('Google Drive backup file not found.');
    $result = backupRemoteCurl('https://www.googleapis.com/drive/v3/files/' . rawurlencode($entry['id']) . '?alt=media', [
        'headers' => ['Authorization: Bearer ' . $token],
    ]);
    if (file_put_contents($destinationPath, $result['body']) === false) {
        throw new RuntimeException('Could not write downloaded backup.');
    }
}

function backupRemoteDeleteGoogleDrive($file, array $target) {
    $token = $target['settings']['access_token'] ?? '';
    if ($token === '') throw new RuntimeException('Google Drive access token is missing.');
    $entry = backupRemoteGoogleFileId($file, $target);
    if (!$entry || empty($entry['id'])) return;
    backupRemoteCurl('https://www.googleapis.com/drive/v3/files/' . rawurlencode($entry['id']), [
        'method' => 'DELETE',
        'headers' => ['Authorization: Bearer ' . $token],
    ]);
}

function backupRemoteUploadOneDrive($filePath, array $target) {
    $settings = $target['settings'];
    $token = $settings['access_token'] ?? '';
    if ($token === '') throw new RuntimeException('OneDrive access token is missing.');
    $folder = trim(backupRemoteFolder($settings['folder_path'] ?? '/Nibbly Backups'), '/');
    backupRemoteEnsureOneDriveFolder($token, $folder);
    $remotePath = rawurlencode(($folder !== '' ? $folder . '/' : '') . basename($filePath));
    $remotePath = str_replace('%2F', '/', $remotePath);
    backupRemoteCurl('https://graph.microsoft.com/v1.0/me/drive/special/approot:/' . $remotePath . ':/content', [
        'method' => 'PUT',
        'headers' => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/zip',
        ],
        'body' => file_get_contents($filePath),
    ]);
}

function backupRemoteOneDriveItemUrl($path = '') {
    $path = trim((string)$path, '/');
    if ($path === '') {
        return 'https://graph.microsoft.com/v1.0/me/drive/special/approot';
    }
    $encoded = str_replace('%2F', '/', rawurlencode($path));
    return 'https://graph.microsoft.com/v1.0/me/drive/special/approot:/' . $encoded . ':';
}

function backupRemoteEnsureOneDriveFolder($token, $folder) {
    $parts = array_values(array_filter(explode('/', trim((string)$folder, '/'))));
    $path = '';
    foreach ($parts as $part) {
        $endpoint = $path === ''
            ? 'https://graph.microsoft.com/v1.0/me/drive/special/approot/children'
            : backupRemoteOneDriveItemUrl($path) . '/children';
        try {
            backupRemoteCurl($endpoint, [
                'method' => 'POST',
                'headers' => [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/json',
                ],
                'body' => json_encode([
                    'name' => $part,
                    'folder' => new stdClass(),
                    '@microsoft.graph.conflictBehavior' => 'fail',
                ], JSON_UNESCAPED_SLASHES),
            ]);
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            if (stripos($message, 'nameAlreadyExists') === false && stripos($message, 'conflict') === false) {
                throw $e;
            }
        }
        $path = $path === '' ? $part : $path . '/' . $part;
    }
}

function backupRemoteOneDriveFolderPath(array $target) {
    $settings = $target['settings'];
    return trim(backupRemoteFolder($settings['folder_path'] ?? '/Nibbly Backups'), '/');
}

function backupRemoteListOneDrive(array $target) {
    $token = $target['settings']['access_token'] ?? '';
    if ($token === '') throw new RuntimeException('OneDrive access token is missing.');
    $folder = backupRemoteOneDriveFolderPath($target);
    backupRemoteEnsureOneDriveFolder($token, $folder);
    $url = backupRemoteOneDriveItemUrl($folder) . '/children?$select=name,size,lastModifiedDateTime,file&$top=200';
    $files = [];
    do {
        $result = backupRemoteCurl($url, [
            'headers' => ['Authorization: Bearer ' . $token],
        ]);
        $json = json_decode($result['body'] ?? '', true);
        foreach (($json['value'] ?? []) as $entry) {
            if (empty($entry['file'])) continue;
            $name = $entry['name'] ?? '';
            if (!backupIsPoolFilename($name)) continue;
            $parsed = backupParseFilename($name);
            $files[] = [
                'file' => $name,
                'tier' => $parsed['tier'],
                'mtime' => !empty($entry['lastModifiedDateTime']) ? strtotime($entry['lastModifiedDateTime']) : ($parsed['mtime'] ?: 0),
                'size' => (int)($entry['size'] ?? 0),
            ];
        }
        $url = $json['@odata.nextLink'] ?? '';
    } while ($url !== '');
    usort($files, fn($a, $b) => $b['mtime'] - $a['mtime']);
    return ['ok' => true, 'message' => count($files) . ' remote backup(s).', 'files' => $files];
}

function backupRemoteDownloadOneDrive($file, array $target, $destinationPath) {
    $token = $target['settings']['access_token'] ?? '';
    if ($token === '') throw new RuntimeException('OneDrive access token is missing.');
    $folder = backupRemoteOneDriveFolderPath($target);
    $remotePath = ($folder !== '' ? $folder . '/' : '') . basename($file);
    $result = backupRemoteCurl(backupRemoteOneDriveItemUrl($remotePath) . '/content', [
        'headers' => ['Authorization: Bearer ' . $token],
    ]);
    if (file_put_contents($destinationPath, $result['body']) === false) {
        throw new RuntimeException('Could not write downloaded backup.');
    }
}

function backupRemoteDeleteOneDrive($file, array $target) {
    $token = $target['settings']['access_token'] ?? '';
    if ($token === '') throw new RuntimeException('OneDrive access token is missing.');
    $folder = backupRemoteOneDriveFolderPath($target);
    $remotePath = ($folder !== '' ? $folder . '/' : '') . basename($file);
    backupRemoteCurl(backupRemoteOneDriveItemUrl($remotePath), [
        'method' => 'DELETE',
        'headers' => ['Authorization: Bearer ' . $token],
    ]);
}

function backupRemoteUploadSftp($filePath, array $target) {
    if (!function_exists('ssh2_connect')) {
        throw new RuntimeException('PHP ssh2 extension is required for SFTP/SCP uploads.');
    }
    $settings = $target['settings'];
    $host = $settings['host'] ?? '';
    $user = $settings['username'] ?? '';
    $pass = $settings['password'] ?? '';
    $port = max(1, (int)($settings['port'] ?? 22));
    $remoteDir = rtrim($settings['remote_path'] ?? '', '/');
    if ($host === '' || $user === '' || $remoteDir === '') {
        throw new RuntimeException('SFTP host, username, and remote path are required.');
    }
    $remoteDir .= '/' . backupSiteIdentifier();
    $conn = @ssh2_connect($host, $port);
    if (!$conn) throw new RuntimeException('Could not connect to SFTP server.');
    if (!@ssh2_auth_password($conn, $user, $pass)) {
        throw new RuntimeException('SFTP authentication failed.');
    }
    $sftp = @ssh2_sftp($conn);
    if ($sftp) {
        @ssh2_sftp_mkdir($sftp, $remoteDir, 0755, true);
    }
    $remoteFile = $remoteDir . '/' . basename($filePath);
    if (!@ssh2_scp_send($conn, $filePath, $remoteFile, 0644)) {
        throw new RuntimeException('SFTP upload failed.');
    }
}

function backupRemoteFtpConnect(array $target) {
    if (!function_exists('ftp_connect')) {
        throw new RuntimeException('PHP FTP extension is required for FTP/FTPS uploads.');
    }
    $settings = $target['settings'];
    $host = trim((string)($settings['host'] ?? ''));
    $user = (string)($settings['username'] ?? '');
    $pass = (string)($settings['password'] ?? '');
    $ssl = !empty($settings['ssl']);
    $port = max(1, (int)($settings['port'] ?? 21));
    if ($host === '' || $user === '') {
        throw new RuntimeException('FTP host and username are required.');
    }
    if ($ssl && !function_exists('ftp_ssl_connect')) {
        throw new RuntimeException('PHP FTP SSL support is required for FTPS.');
    }
    $conn = $ssl ? @ftp_ssl_connect($host, $port, 20) : @ftp_connect($host, $port, 20);
    if (!$conn) {
        throw new RuntimeException('Could not connect to FTP server.');
    }
    if (!@ftp_login($conn, $user, $pass)) {
        @ftp_close($conn);
        throw new RuntimeException('FTP authentication failed.');
    }
    $passive = array_key_exists('passive', $settings) ? !empty($settings['passive']) : true;
    @ftp_pasv($conn, $passive);
    return $conn;
}

function backupRemoteFtpDir(array $target) {
    $settings = $target['settings'];
    $base = rtrim(trim((string)($settings['remote_path'] ?? '')), '/');
    $site = backupSiteIdentifier();
    if ($base === '') return $site;
    if ($base === '/') return '/' . $site;
    return $base . '/' . $site;
}

function backupRemoteFtpEnsureDir($conn, $dir) {
    $absolute = str_starts_with($dir, '/');
    $parts = array_values(array_filter(explode('/', trim($dir, '/')), fn($part) => $part !== ''));
    if ($absolute) {
        @ftp_chdir($conn, '/');
    }
    foreach ($parts as $part) {
        if (@ftp_chdir($conn, $part)) {
            continue;
        }
        if (!@ftp_mkdir($conn, $part) || !@ftp_chdir($conn, $part)) {
            throw new RuntimeException('Could not create FTP remote directory.');
        }
    }
}

function backupRemoteFtpOpenDir(array $target, $create = false) {
    $conn = backupRemoteFtpConnect($target);
    $dir = backupRemoteFtpDir($target);
    if ($create) {
        backupRemoteFtpEnsureDir($conn, $dir);
        return $conn;
    }
    if (!@ftp_chdir($conn, $dir)) {
        @ftp_close($conn);
        return null;
    }
    return $conn;
}

function backupRemoteUploadFtp($filePath, array $target) {
    $conn = backupRemoteFtpOpenDir($target, true);
    $remoteFile = basename($filePath);
    if (!@ftp_put($conn, $remoteFile, $filePath, FTP_BINARY)) {
        @ftp_close($conn);
        throw new RuntimeException('FTP upload failed.');
    }
    @ftp_close($conn);
}

function backupRemoteListFtp(array $target) {
    $conn = backupRemoteFtpOpenDir($target, false);
    if (!$conn) {
        return ['ok' => true, 'message' => 'No remote backups found.', 'files' => []];
    }
    $names = @ftp_nlist($conn, '.') ?: [];
    $files = [];
    foreach ($names as $name) {
        $base = basename($name);
        if (!backupIsPoolFilename($base)) continue;
        $parsed = backupParseFilename($base);
        $mtime = @ftp_mdtm($conn, $base);
        $size = @ftp_size($conn, $base);
        $files[] = [
            'file' => $base,
            'tier' => $parsed['tier'],
            'mtime' => $mtime > 0 ? $mtime : ($parsed['mtime'] ?: 0),
            'size' => $size > 0 ? $size : 0,
        ];
    }
    @ftp_close($conn);
    usort($files, fn($a, $b) => $b['mtime'] - $a['mtime']);
    return ['ok' => true, 'message' => count($files) . ' remote backup(s).', 'files' => $files];
}

function backupRemoteDownloadFtp($file, array $target, $destinationPath) {
    $conn = backupRemoteFtpOpenDir($target, false);
    if (!$conn) throw new RuntimeException('FTP remote directory not found.');
    if (!@ftp_get($conn, $destinationPath, basename($file), FTP_BINARY)) {
        @ftp_close($conn);
        throw new RuntimeException('FTP download failed.');
    }
    @ftp_close($conn);
}

function backupRemoteDeleteFtp($file, array $target) {
    $conn = backupRemoteFtpOpenDir($target, false);
    if (!$conn) throw new RuntimeException('FTP remote directory not found.');
    if (!@ftp_delete($conn, basename($file))) {
        @ftp_close($conn);
        throw new RuntimeException('FTP delete failed.');
    }
    @ftp_close($conn);
}

function backupRemoteUploadWebDav($filePath, array $target) {
    $settings = $target['settings'];
    $baseUrl = rtrim($settings['url'] ?? '', '/');
    if ($baseUrl === '') throw new RuntimeException('WebDAV URL is missing.');
    $baseUrl .= '/' . rawurlencode(backupSiteIdentifier());
    $headers = ['Content-Type: application/zip'];
    if (!empty($settings['bearer_token'])) {
        $headers[] = 'Authorization: Bearer ' . $settings['bearer_token'];
    } elseif (!empty($settings['username']) || !empty($settings['password'])) {
        $headers[] = 'Authorization: Basic ' . base64_encode(($settings['username'] ?? '') . ':' . ($settings['password'] ?? ''));
    }
    backupRemoteCurl($baseUrl . '/' . rawurlencode(basename($filePath)), [
        'method' => 'PUT',
        'headers' => $headers,
        'body' => file_get_contents($filePath),
    ]);
}

function backupRemoteUploadS3($filePath, array $target) {
    $settings = $target['settings'];
    $accessKey = $settings['access_key'] ?? '';
    $secretKey = $settings['secret_key'] ?? '';
    $bucket = $settings['bucket'] ?? '';
    $region = $settings['region'] ?? 'us-east-1';
    $endpoint = rtrim($settings['endpoint'] ?? '', '/');
    $prefix = trim($settings['prefix'] ?? '', '/');
    if ($accessKey === '' || $secretKey === '' || $bucket === '') {
        throw new RuntimeException('S3 access key, secret key, and bucket are required.');
    }
    if ($endpoint === '') $endpoint = 'https://s3.' . $region . '.amazonaws.com';
    $pathStyle = !empty($settings['path_style']);
    $sitePrefix = ($prefix !== '' ? $prefix . '/' : '') . backupSiteIdentifier();
    $key = $sitePrefix . '/' . basename($filePath);
    $host = parse_url($endpoint, PHP_URL_HOST);
    if (!$host) throw new RuntimeException('Invalid S3 endpoint.');
    $scheme = parse_url($endpoint, PHP_URL_SCHEME) ?: 'https';
    $basePath = rtrim(parse_url($endpoint, PHP_URL_PATH) ?: '', '/');
    if ($pathStyle) {
        $uri = $basePath . '/' . rawurlencode($bucket) . '/' . str_replace('%2F', '/', rawurlencode($key));
        $url = $scheme . '://' . $host . $uri;
    } else {
        $uri = $basePath . '/' . str_replace('%2F', '/', rawurlencode($key));
        $url = $scheme . '://' . $bucket . '.' . $host . $uri;
        $host = $bucket . '.' . $host;
    }

    $body = file_get_contents($filePath);
    $payloadHash = hash('sha256', $body);
    $now = gmdate('Ymd\THis\Z');
    $date = substr($now, 0, 8);
    $scope = "$date/$region/s3/aws4_request";
    $headers = [
        'host' => $host,
        'x-amz-content-sha256' => $payloadHash,
        'x-amz-date' => $now,
    ];
    ksort($headers);
    $canonicalHeaders = '';
    foreach ($headers as $k => $v) $canonicalHeaders .= strtolower($k) . ':' . trim($v) . "\n";
    $signedHeaders = implode(';', array_keys($headers));
    $canonicalRequest = "PUT\n$uri\n\n$canonicalHeaders\n$signedHeaders\n$payloadHash";
    $stringToSign = "AWS4-HMAC-SHA256\n$now\n$scope\n" . hash('sha256', $canonicalRequest);
    $kDate = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);
    $auth = "AWS4-HMAC-SHA256 Credential=$accessKey/$scope, SignedHeaders=$signedHeaders, Signature=$signature";

    backupRemoteCurl($url, [
        'method' => 'PUT',
        'headers' => [
            'Host: ' . $host,
            'Authorization: ' . $auth,
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $now,
            'Content-Type: application/zip',
        ],
        'body' => $body,
    ]);
}

function backupUploadRemoteTargets($file, $targetId = null) {
    $config = backupConfig();
    $path = BACKUP_PATH . $file;
    $targets = array_filter($config['remote_targets'], function($target) use ($targetId) {
        if ($targetId !== null && $target['id'] !== $targetId) return false;
        return $targetId !== null || !empty($target['enabled']);
    });
    if ($targetId !== null && empty($targets)) {
        return [[
            'id' => $targetId,
            'name' => $targetId,
            'type' => 'unknown',
            'ok' => false,
            'message' => 'Remote target not found.',
        ]];
    }
    $results = [];
    foreach ($targets as $target) {
        try {
            backupRemoteRefreshTarget($target);
            $result = backupRemoteUpload($path, $target);
            if (!empty($result['ok'])) {
                try {
                    $pruned = backupPruneRemoteTarget($target);
                    if ($pruned > 0) {
                        $result['message'] .= " Pruned {$pruned} remote backup(s).";
                    }
                } catch (Throwable $e) {
                    $result['message'] .= " Remote pruning skipped: " . $e->getMessage();
                }
            }
        } catch (Throwable $e) {
            $result = ['ok' => false, 'message' => $e->getMessage()];
        }
        $target['last_upload'] = date('c');
        $target['last_status'] = $result['ok'] ? 'success' : 'error';
        $target['last_message'] = $result['message'];
        $target['last_file'] = $file;
        $results[] = [
            'id' => $target['id'],
            'name' => $target['name'],
            'type' => $target['type'],
            'ok' => $result['ok'],
            'message' => $result['message'],
        ];
        foreach ($config['remote_targets'] as $idx => $existing) {
            if ($existing['id'] === $target['id']) {
                $config['remote_targets'][$idx] = $target;
                break;
            }
        }
    }
    backupSaveConfig(['remote_targets' => $config['remote_targets']]);
    return $results;
}

function backupPruneRemoteTarget(array $target) {
    $listed = backupRemoteList($target);
    if (empty($listed['ok'])) return 0;
    $localFiles = [];
    foreach (backupListAll() as $backup) {
        $localFiles[$backup['file']] = true;
    }
    $deleted = 0;
    foreach ($listed['files'] as $remoteFile) {
        $file = $remoteFile['file'] ?? '';
        if ($file === '' || isset($localFiles[$file])) continue;
        $result = backupRemoteDelete($file, $target);
        if (!empty($result['ok'])) $deleted++;
    }
    return $deleted;
}

function backupRemoteTargetById($targetId) {
    $config = backupConfig();
    foreach ($config['remote_targets'] as $target) {
        if ($target['id'] === $targetId) return $target;
    }
    return null;
}

function backupSaveRemoteTarget(array $target) {
    $config = backupConfig();
    foreach ($config['remote_targets'] as $idx => $existing) {
        if ($existing['id'] === $target['id']) {
            $config['remote_targets'][$idx] = $target;
            backupSaveConfig(['remote_targets' => $config['remote_targets']]);
            return true;
        }
    }
    return false;
}

/**
 * Build a fresh full-site ZIP and write it to BACKUP_PATH.
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
    $filename = backupSiteIdentifier() . "-backup-{$stamp}-{$tier}.zip";
    $zipPath = BACKUP_PATH . $filename;

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
        $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($filePath, strlen($siteRoot) + 1));
        if (backupShouldSkipPath($rel)) continue;

        if ($file->isDir()) {
            $zip->addEmptyDir($rel);
        } elseif ($rel === 'content/settings.json') {
            $zip->addFromString($rel, backupScrubSettingsForArchive($filePath));
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
function backupRun($now = null, $tier = null, $uploadRemote = true) {
    $now = $now ?: time();
    $created = backupCreate($tier, $now);
    $deleted = $created['ok'] ? backupPrune() : [];
    $remoteResults = ($created['ok'] && $uploadRemote) ? backupUploadRemoteTargets($created['file']) : [];
    $remoteErrors = array_filter($remoteResults, fn($result) => empty($result['ok']));

    backupSaveConfig([
        'last_run'     => date('c', $now),
        'last_status'  => ($created['ok'] && empty($remoteErrors)) ? 'success' : 'error',
        'last_message' => $created['ok']
            ? "Created {$created['file']} (" . number_format($created['size'] / 1024 / 1024, 1) . " MB), pruned " . count($deleted) . ", uploaded " . (count($remoteResults) - count($remoteErrors)) . "/" . count($remoteResults) . " remote target(s)."
            : ($created['message'] ?? 'Unknown error'),
    ]);

    return ['created' => $created, 'deleted' => $deleted, 'remote' => $remoteResults];
}

/** Quick status snapshot — used by both the dashboard and `cli/backup.php --action=status`. */
function backupStatus() {
    $config = backupConfig();
    $list = backupListAll();
    $totalBytes = 0;
    foreach ($list as $b) $totalBytes += $b['size'];
    $oldest = !empty($list) ? $list[count($list) - 1]['mtime'] : null;

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
        'oldest'           => $oldest,
        'remote_providers' => backupRemoteProviders(),
        'remote_targets'   => backupRemotePublicTargets($config['remote_targets']),
    ];
}
