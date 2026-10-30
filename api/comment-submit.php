<?php
/**
 * Public endpoint for comments and guestbook entries (includes/comments.php).
 * POST from the form rendered by nibblyCommentsRender(); answers JSON for the script and, without
 * JavaScript (no Accept: application/json), redirects back to the page with the result in the hash.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$configFile = __DIR__ . '/../admin/config.php';
if (is_file($configFile) && !defined('SITE_LANG_DEFAULT')) require_once $configFile;
require_once __DIR__ . '/../includes/comments.php';

$lang = (string)($_POST['lang'] ?? '');
if (!preg_match('/^[a-z]{2}$/', $lang)) $lang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
$result = nibblyCommentsSubmit($_POST, $lang);

$wantsJson = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch';
if (!$wantsJson) {
    // Plain form post: back to the page the form came from
    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $path = (string)(parse_url($referer, PHP_URL_PATH) ?: '/');
    $host = (string)parse_url($referer, PHP_URL_HOST);
    if ($host !== '' && $host !== (string)($_SERVER['HTTP_HOST'] ?? '')) $path = '/';
    header('Location: ' . $path . ($result['success'] ? '#kommentare' : '#nb-comments-form'), true, 303);
    exit;
}
header('Content-Type: application/json; charset=utf-8');
http_response_code($result['success'] ? 200 : 400);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
