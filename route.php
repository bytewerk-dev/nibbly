<?php
/**
 * Front controller for Apache (production).
 *
 * .htaccess sends all non-static requests here. This file handles:
 * - Clean URLs (strip .php extension)
 * - Primary language root access (/about → /en/about)
 * - Language-prefixed pages (/de/beispiel)
 * - News post URLs (/news/slug, /en/news/slug)
 * - JSON-based standard pages (via includes/page.php)
 *
 * Language detection uses SITE_LANG_DEFAULT from admin/config.php,
 * so .htaccess never needs to be edited for language changes.
 */

// Load config
$configPath = __DIR__ . '/admin/config.php';
if (!file_exists($configPath)) {
    header('Location: admin/setup.php');
    exit;
}
require_once $configPath;

$primaryLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';

// Parse the clean URI
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$cleanUri = trim($uri, '/');

// Root URL → homepage
if ($cleanUri === '') {
    $basePath = '';
    $langHome = __DIR__ . '/' . $primaryLang . '/index.php';
    $jsonHome = __DIR__ . '/content/pages/' . $primaryLang . '_home.json';

    if (is_file($langHome)) {
        include $langHome;
    } elseif (is_file($jsonHome)) {
        $lang = $primaryLang;
        $slug = 'home';
        include __DIR__ . '/includes/page.php';
    } else {
        echo '<p>No homepage found. Run the <a href="admin/setup.php">setup wizard</a> to get started.</p>';
    }
    exit;
}

// ------------------------------------------------------------------
// News post URLs: /{lang}/news/{slug} or /news/{slug}
// ------------------------------------------------------------------
if (preg_match('#^([a-z]{2})/news/([a-z0-9-]+)$#', $cleanUri, $m)) {
    $newsFile = __DIR__ . '/' . $m[1] . '/news-post.php';
    if (is_file($newsFile)) {
        $_GET['slug'] = $m[2];
        $basePath = '../../';
        include $newsFile;
        exit;
    }
}
if (preg_match('#^news/([a-z0-9-]+)$#', $cleanUri, $m)) {
    $newsFile = __DIR__ . '/' . $primaryLang . '/news-post.php';
    if (is_file($newsFile)) {
        $_GET['slug'] = $m[1];
        $basePath = '../';
        include $newsFile;
        exit;
    }
}

// ------------------------------------------------------------------
// Language-prefixed URL: /{lang}/{slug}
// ------------------------------------------------------------------
if (preg_match('#^([a-z]{2})/([a-zA-Z0-9_-]+)$#', $cleanUri, $m)) {
    $lang = $m[1];
    $slug = $m[2];

    // 1. Physical PHP file
    $phpFile = __DIR__ . '/' . $lang . '/' . $slug . '.php';
    if (is_file($phpFile)) {
        include $phpFile;
        exit;
    }

    // 2. JSON content → front controller
    $jsonFile = __DIR__ . '/content/pages/' . $lang . '_' . $slug . '.json';
    if (is_file($jsonFile)) {
        $basePath = '../';
        include __DIR__ . '/includes/page.php';
        exit;
    }
}

// ------------------------------------------------------------------
// Root-level slug: /{slug} → primary language
// ------------------------------------------------------------------
if (preg_match('#^[a-zA-Z0-9_-]+$#', $cleanUri)) {
    $lang = $primaryLang;
    $slug = $cleanUri;

    // 1. Physical PHP file (with .php extension)
    $phpFile = __DIR__ . '/' . $lang . '/' . $slug . '.php';
    if (is_file($phpFile)) {
        $basePath = '';
        include $phpFile;
        exit;
    }

    // 2. JSON content → front controller
    $jsonFile = __DIR__ . '/content/pages/' . $lang . '_' . $slug . '.json';
    if (is_file($jsonFile)) {
        $basePath = '';
        include __DIR__ . '/includes/page.php';
        exit;
    }
}

// ------------------------------------------------------------------
// Nothing matched → 404
// ------------------------------------------------------------------
http_response_code(404);
include __DIR__ . '/404.php';
