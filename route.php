<?php
/**
 * Front controller entry point for Apache.
 * Called by .htaccess when no physical PHP file matches the URL.
 * Delegates to includes/page.php to render JSON-based standard pages.
 */

$lang = $_GET['lang'] ?? null;
$slug = $_GET['slug'] ?? null;

if (!$lang || !$slug || !preg_match('/^[a-z]{2}$/', $lang) || !preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
    http_response_code(404);
    include __DIR__ . '/404.php';
    exit;
}

// If a physical PHP file exists for this route, use it instead of JSON rendering.
// This handles cases where .htaccess rewrite conditions fail to match the PHP file
// (e.g. DOCUMENT_ROOT mismatch on some hosting environments).
$phpFile = __DIR__ . '/' . $lang . '/' . $slug . '.php';
if (is_file($phpFile)) {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $basePath = (strpos($requestUri, '/' . $lang . '/') === 0) ? '../' : '';
    include $phpFile;
    exit;
}

// Check if the JSON content file exists
$jsonFile = __DIR__ . '/content/pages/' . $lang . '_' . $slug . '.json';
if (!is_file($jsonFile)) {
    http_response_code(404);
    include __DIR__ . '/404.php';
    exit;
}

// Determine basePath: '' for root-level requests, '../' for language-prefixed
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$basePath = (strpos($requestUri, '/' . $lang . '/') === 0) ? '../' : '';

include __DIR__ . '/includes/page.php';
