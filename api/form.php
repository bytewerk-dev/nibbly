<?php
/**
 * Lazy public form renderer.
 *
 * Returns the HTML of a JSON form (content/forms/{id}.json) with fresh
 * one-time tokens.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Method not allowed';
    exit;
}

require_once __DIR__ . '/../includes/form-protection.php';
require_once __DIR__ . '/../includes/forms.php';

$form = $_GET['form'] ?? '';
$lang = $_GET['lang'] ?? 'en';
$incomingBasePath = $_GET['basePath'] ?? ($_GET['basepath'] ?? '');

if (!preg_match('/^[a-z]{2}$/', $lang)) {
    $lang = 'en';
}

$basePath = nibblySafeFormBasePath((string) $incomingBasePath);
$currentLang = $lang;
define('NIBBLY_RENDER_LAZY_FORM', true);

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

if (!is_string($form) || !nibblyFormExists($form)) {
    http_response_code(404);
    echo 'Form not found';
    exit;
}

echo nibblyFormRender($form, [
    'basePath' => $basePath,
    'lang' => $currentLang,
    'lazy' => false,
]);
