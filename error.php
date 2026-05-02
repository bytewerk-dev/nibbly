<?php
/**
 * Generic Error Page
 *
 * Rendered by 404.php and 410.php (and any future error wrappers).
 * Wrappers set $errorCode (int) before including this file; everything
 * else — translations, HTTP status, basePath, language fallback — lives
 * here so each new error code only needs a one-line wrapper.
 */

if (!isset($errorCode) || !is_int($errorCode)) {
    $errorCode = 404;
}
http_response_code($errorCode);

$currentPage = (string)$errorCode;
$pageTitle = 'Error';

// Load config if available
$configPath = __DIR__ . '/admin/config.php';
if (file_exists($configPath) && !defined('SITE_LANG_DEFAULT')) {
    require_once $configPath;
}

// Detect language: default to SITE_LANG_DEFAULT, override only if the URL
// language prefix is one we actually have translations for. Unknown prefixes
// must NOT override the default — otherwise /xx/foo on a German site would
// silently fall back to English instead of staying German.
$defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
$currentLang = $defaultLang;
$requestUri = $_SERVER['REQUEST_URI'] ?? '';

// Calculate basePath dynamically from URL depth
$trimmedUri = trim(parse_url($requestUri, PHP_URL_PATH) ?? '', '/');
$depth = $trimmedUri === '' ? 0 : substr_count($trimmedUri, '/') + 1;
$basePath = $depth > 0 ? str_repeat('../', $depth) : '';

$translations = [
    404 => [
        'de' => [
            'title' => 'Seite nicht gefunden',
            'message' => 'Die gesuchte Seite existiert leider nicht.',
            'hint' => 'Vielleicht wurde sie verschoben oder gelöscht.',
            'button' => 'Zur Startseite',
        ],
        'en' => [
            'title' => 'Page Not Found',
            'message' => 'The page you\'re looking for doesn\'t exist.',
            'hint' => 'It may have been moved or deleted.',
            'button' => 'Go to Homepage',
        ],
        'es' => [
            'title' => 'Página no encontrada',
            'message' => 'La página que buscas no existe.',
            'hint' => 'Puede haber sido movida o eliminada.',
            'button' => 'Ir al inicio',
        ],
    ],
    410 => [
        'de' => [
            'title' => 'Inhalt nicht mehr verfügbar',
            'message' => 'Diese Seite wurde dauerhaft entfernt.',
            'hint' => 'Es gibt keinen Ersatz an einer anderen Stelle.',
            'button' => 'Zur Startseite',
        ],
        'en' => [
            'title' => 'Content No Longer Available',
            'message' => 'This page has been permanently removed.',
            'hint' => 'There is no replacement available elsewhere.',
            'button' => 'Go to Homepage',
        ],
        'es' => [
            'title' => 'Contenido ya no disponible',
            'message' => 'Esta página ha sido eliminada de forma permanente.',
            'hint' => 'No existe un reemplazo en otro lugar.',
            'button' => 'Ir al inicio',
        ],
    ],
];

$strings = $translations[$errorCode] ?? $translations[404];

// URL language prefix only wins if we have translations for it.
if (preg_match('#^/([a-z]{2})/#', $requestUri, $m) && isset($strings[$m[1]])) {
    $currentLang = $m[1];
}

$t = $strings[$currentLang] ?? $strings[$defaultLang] ?? $strings['en'];
$pageTitle = $t['title'];

include __DIR__ . '/includes/header.php';
?>

    <main class="main-content">
        <div class="error-page">
            <div class="error-page__code"><?php echo $errorCode; ?></div>
            <h1 class="error-page__title"><?php echo htmlspecialchars($t['title']); ?></h1>
            <p class="error-page__message"><?php echo htmlspecialchars($t['message']); ?><br><?php echo htmlspecialchars($t['hint']); ?></p>
            <a href="<?php echo $basePath; ?>." class="btn btn-gradient"><?php echo htmlspecialchars($t['button']); ?></a>
        </div>
    </main>

<?php include __DIR__ . '/includes/footer.php'; ?>
