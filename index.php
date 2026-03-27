<?php
/**
 * Root index — loads the primary language homepage.
 * Redirects to setup wizard if not yet configured.
 */

if (!file_exists(__DIR__ . '/admin/config.php')) {
    header('Location: admin/setup.php');
    exit;
}

require_once __DIR__ . '/admin/config.php';
$defaultLang = SITE_LANG_DEFAULT;
$basePath = '';

// Try physical template first, then JSON front controller
$langHome = __DIR__ . '/' . $defaultLang . '/index.php';
$jsonHome = __DIR__ . '/content/pages/' . $defaultLang . '_home.json';

if (file_exists($langHome)) {
    include $langHome;
} elseif (file_exists($jsonHome)) {
    $lang = $defaultLang;
    $slug = 'home';
    include __DIR__ . '/includes/page.php';
} else {
    echo '<p>No homepage found. Run the <a href="admin/setup.php">setup wizard</a> to get started.</p>';
}
