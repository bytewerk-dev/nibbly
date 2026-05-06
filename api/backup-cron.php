<?php
/**
 * Public web-cron endpoint for scheduled backups.
 *
 * Protected by the secret token stored in content/settings.json under
 * backup.web_cron_token. Intended for external schedulers such as
 * cron-job.org or EasyCron on hosts that do not provide server cron jobs.
 */

header('Content-Type: application/json; charset=utf-8');
ini_set('html_errors', '0');
ini_set('display_errors', '0');

$configPath = __DIR__ . '/../admin/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Nibbly is not configured.']);
    exit;
}

require_once $configPath;
require_once __DIR__ . '/../includes/backup-helper.php';

function webCronResponse($success, $message, $data = null, $status = 200) {
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$config = backupConfig();
$expectedToken = trim((string)($config['web_cron_token'] ?? ''));
$submittedToken = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));

if ($expectedToken === '' || $submittedToken === '' || !hash_equals($expectedToken, $submittedToken)) {
    webCronResponse(false, 'Invalid web cron token.', null, 403);
}

if (empty($config['enabled'])) {
    webCronResponse(true, 'Backups are disabled in settings.', ['status' => backupStatus()]);
}

if (($config['cron_mode'] ?? 'server') !== 'web') {
    webCronResponse(true, 'Web cron is not selected in backup settings.', ['status' => backupStatus()]);
}

try {
    $result = backupWithLock(function() {
        return backupRun(time(), null, true);
    });
} catch (BackupLockException $e) {
    webCronResponse(false, $e->getMessage(), null, 423);
} catch (Throwable $e) {
    webCronResponse(false, $e->getMessage(), null, 500);
}

$created = $result['created'] ?? [];
$ok = !empty($created['ok']);
$message = $ok
    ? 'Backup run complete.'
    : ($created['message'] ?? 'Backup failed.');

webCronResponse($ok, $message, [
    'result' => $result,
    'status' => backupStatus(),
], $ok ? 200 : 500);
