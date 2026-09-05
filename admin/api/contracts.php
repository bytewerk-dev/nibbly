<?php
if (!defined('NIBBLY_ADMIN_DIR')) { http_response_code(404); exit; }

// All writes use POST and a session CSRF token, including multipart uploads.
// Read-only exports may use GET with their existing signed/session checks.
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    http_response_code(405); header('Allow: GET, POST'); jsonResponse(false, null, 'Unsupported request method');
}
$readOnly = preg_match('/^(load|list|get)-/', $action) || in_array($action, [
    'load', 'backups', 'preview-backup', 'keepalive', 'dashboard-overview', 'system-status',
    'iconify-search', 'ai-image-history', 'ai-image-jobs', 'ai-copilot-context',
    'ai-copilot-history-list', 'ai-copilot-history-load', 'ai-openrouter-models',
    'media-trash-file', 'image-trash-file', 'unread-mail-count', 'download-site-backup',
    'backup-status', 'backup-list', 'backup-remote-list'
], true);
$oauthCallback = preg_match('/^backup-(dropbox|google|onedrive)-(oauth|broker)-callback$/', $action);
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !$readOnly && !$oauthCallback) {
    http_response_code(405); header('Allow: POST'); jsonResponse(false, null, 'Use POST for this action');
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && !validateCsrfToken()) {
    http_response_code(403);
    jsonResponse(false, null, 'Invalid CSRF token');
}
// Keep editors away from server credentials and installation administration.
if (in_array($routes[$action], ['backups', 'users', 'settings'], true)
    && !in_array($action, ['load-settings', 'change-password'], true) && !isAdmin()) {
    http_response_code(403);
    jsonResponse(false, null, 'Forbidden');
}

// Hold the same stable lock used by fallback generation through read/compare/write.
// A shutdown release also covers jsonResponse(), which intentionally exits.
$path = null;
if (in_array($action, ['load', 'save'], true)) {
    $page = $_GET['page'] ?? $_POST['page'] ?? '';
    if (!is_string($page) || !validatePageName($page)) {
        jsonResponse(false, null, 'Invalid page name');
    }
    $path = CONTENT_PATH . $page . '.json';
} elseif (in_array($action, ['load-settings', 'save-settings'], true)) {
    $path = SETTINGS_PATH;
}
if (in_array($action, ['ai-copilot-apply', 'ai-copilot-apply-visibility', 'ai-copilot-undo', 'ai-content-audit-apply'], true)) {
    $key = $_POST['contentPage'] ?? '';
    if (!is_string($key)) jsonResponse(false, null, 'Invalid page name');
    $path = nibblyCopilotContentPath($key);
    if ($path === '') jsonResponse(false, null, 'Invalid page name');
}
if ($action === 'restore' && validateBackupName($_POST['backup'] ?? '')) {
    $page = preg_replace('/_\d{4}-\d{2}-\d{2}_\d{6}(?:_[a-f0-9]{6})?\.json$/', '', $_POST['backup']);
    $path = CONTENT_PATH . $page . '.json';
}
if ($path !== null) {
    $lock = nibblyJsonLock($path);
    if ($lock === false) { http_response_code(503); jsonResponse(false, null, 'Storage unavailable'); }
    register_shutdown_function(static function () use ($lock): void { if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); } });
    $GLOBALS['nibblyRevisionLock'] = $lock;
    $GLOBALS['nibblyRevisionPath'] = $path;
    if (is_file($path) && !is_array(json_decode((string)file_get_contents($path), true))) {
        http_response_code(503);
        jsonResponse(false, null, 'Storage is damaged; existing data was preserved');
    }
    if (in_array($action, ['save', 'save-settings'], true)) {
        $revision = $_POST['revision'] ?? null;
        if (!is_string($revision) || $revision === '') {
            http_response_code(428);
            jsonResponse(false, null, t('conflict.reload_required'));
        }
        if (!hash_equals(nibblyJsonRevision($path), $revision)) {
            http_response_code(409);
            // Client compares its pending changes with a fresh, permission-filtered load.
            jsonResponse(false, ['conflict' => true], t('conflict.changed'));
        }
    }
}
