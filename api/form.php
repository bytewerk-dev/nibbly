<?php
/**
 * Lazy public form renderer.
 *
 * Returns protected form HTML with fresh one-time tokens. The endpoint is
 * intentionally small and whitelisted so arbitrary PHP cannot be included.
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
$_cfLazy = false;

define('NIBBLY_RENDER_LAZY_FORM', true);

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

if (nibblyFormExists($form)) {
    echo nibblyFormRender($form, [
        'basePath' => $basePath,
        'lang' => $currentLang,
        'lazy' => false,
    ]);
    exit;
}

switch ($form) {
    case 'contact':
        include __DIR__ . '/../includes/contact-form.php';
        break;

    case 'pricing-contact':
        $_selectedPackage = is_string($_GET['package'] ?? null)
            ? trim((string) $_GET['package'])
            : '';
        include __DIR__ . '/../includes/pricing-contact-form.php';
        break;

    default:
        http_response_code(404);
        echo 'Form not found';
        break;
}
