<?php
/** Stable public endpoint; domain handlers own individual CMS workflows. */
define('NIBBLY_ADMIN_DIR', __DIR__);
require_once __DIR__ . '/api/bootstrap.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Inbox mutations share the same transaction lock as public submissions.
if (in_array($action, ['mark-mail-read', 'update-mail-flags', 'mark-all-mails-read', 'delete-mail', 'delete-read-mails'], true)) {
    $mailLock = nibblyJsonLock(dirname(__DIR__) . '/content/mails.json');
    if ($mailLock === false) {
        http_response_code(503);
        jsonResponse(false, null, 'Could not lock inbox storage');
    }
    register_shutdown_function(static function () use ($mailLock): void {
        flock($mailLock, LOCK_UN);
        fclose($mailLock);
    });
    $inboxPath = dirname(__DIR__) . '/content/mails.json';
    if (is_file($inboxPath) && !is_array(json_decode((string)file_get_contents($inboxPath), true))) {
        http_response_code(503);
        jsonResponse(false, null, 'Inbox storage is damaged; existing data was preserved');
    }
}


$routes = require __DIR__ . '/api/routes.php';
if (!is_string($action) || !isset($routes[$action])) {
    http_response_code(404);
    jsonResponse(false, null, 'Unknown action');
}
require __DIR__ . '/api/contracts.php';
require __DIR__ . '/api/' . $routes[$action] . '.php';
