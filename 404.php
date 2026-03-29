<?php
/**
 * 404 Error Page
 */
http_response_code(404);

$currentPage = '404';
$pageTitle = 'Page Not Found';

// Load config if available
$configPath = __DIR__ . '/admin/config.php';
if (file_exists($configPath) && !defined('SITE_LANG_DEFAULT')) {
    require_once $configPath;
}

// Detect language: default to SITE_LANG_DEFAULT, override if URL has language prefix
$currentLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
if (preg_match('#^/([a-z]{2})/#', $requestUri, $m)) {
    $currentLang = $m[1];
}

// Calculate basePath dynamically from URL depth
$trimmedUri = trim(parse_url($requestUri, PHP_URL_PATH) ?? '', '/');
$depth = $trimmedUri === '' ? 0 : substr_count($trimmedUri, '/') + 1;
$basePath = $depth > 0 ? str_repeat('../', $depth) : '';

// Translations
$strings = [
    'de' => [
        'title' => 'Seite nicht gefunden',
        'heading' => '404',
        'message' => 'Die gesuchte Seite existiert leider nicht.',
        'hint' => 'Vielleicht wurde sie verschoben oder gelöscht.',
        'button' => 'Zur Startseite',
    ],
    'en' => [
        'title' => 'Page Not Found',
        'heading' => '404',
        'message' => 'The page you\'re looking for doesn\'t exist.',
        'hint' => 'It may have been moved or deleted.',
        'button' => 'Go to Homepage',
    ],
    'es' => [
        'title' => 'Página no encontrada',
        'heading' => '404',
        'message' => 'La página que buscas no existe.',
        'hint' => 'Puede haber sido movida o eliminada.',
        'button' => 'Ir al inicio',
    ],
];

$t = $strings[$currentLang] ?? $strings['en'];
$pageTitle = $t['title'];

include 'includes/header.php';
?>

    <main class="main-content">
        <div class="error-page">
            <div class="error-page__code"><?php echo $t['heading']; ?></div>
            <h1 class="error-page__title"><?php echo htmlspecialchars($t['title']); ?></h1>
            <p class="error-page__message"><?php echo htmlspecialchars($t['message']); ?><br><?php echo htmlspecialchars($t['hint']); ?></p>
            <a href="<?php echo $basePath; ?>." class="btn btn-gradient"><?php echo htmlspecialchars($t['button']); ?></a>
        </div>
    </main>

<?php include 'includes/footer.php'; ?>
