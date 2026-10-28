<?php
/**
 * Editorial guide at /admin/handbook, behind the admin session.
 * Chapters, screenshots and the site hook: includes/handbook.php.
 */
define('NIBBLY_HANDBOOK', true);

require_once 'config.php';
require_once __DIR__ . '/../includes/session-helper.php';
nibblySessionStart();
require_once __DIR__ . '/lang/i18n.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/../includes/theme-defaults.php';
require_once __DIR__ . '/../includes/handbook.php';

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: private, no-store');
// The router counts clean URLs as page views; the guide is not part of the website.
if (function_exists('nibblyAnalyticsTracked')) nibblyAnalyticsTracked(true);

$settings = defined('SETTINGS_PATH') && is_file(SETTINGS_PATH) ? json_decode((string)file_get_contents(SETTINGS_PATH), true) : [];
$settings = is_array($settings) ? $settings : [];
if (!nibblyHandbookEnabled($settings)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

// One address for links and bookmarks: the clean URL without ".php"
$requestPath = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if (preg_match('~\.php$~', $requestPath)) {
    $query = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
    header('Location: handbook' . ($query !== '' ? '?' . $query : ''), true, 308);
    exit;
}

if (!nibblySessionValidate()) {
    // Back to the guide after signing in. Set here rather than via ?redirect=,
    // which the login sends to the home page for /admin targets.
    if (!isset($_GET['img'])) $_SESSION['redirect_after_login'] = 'handbook';
    header('Location: index.php');
    exit;
}

// Screenshots live outside the public paths; only plain names from the guide folders
if (isset($_GET['img'])) {
    $image = is_string($_GET['img']) ? nibblyHandbookImagePath($_GET['img']) : null;
    if ($image === null) {
        http_response_code(404);
        exit;
    }
    $types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    header('Content-Type: ' . $types[pathinfo($image, PATHINFO_EXTENSION)]);
    header('Cache-Control: private, max-age=86400');
    header('Content-Length: ' . filesize($image));
    readfile($image);
    exit;
}

require dirname(__DIR__) . '/includes/handbook/page.php';
