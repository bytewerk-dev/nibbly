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

require_once __DIR__ . '/includes/page-path.php';
require_once __DIR__ . '/includes/access-guard.php';
nibblyAccessEnforceMaintenance();
require_once __DIR__ . '/includes/seo-helper.php';

// Apply migration redirects before any other routing — a redirected URL
// must never accidentally match a real page slug downstream.
require_once __DIR__ . '/includes/redirect-helper.php';
applyRedirects($_SERVER['REQUEST_URI'] ?? '/');

$primaryLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';

// Parse the clean URI
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$cleanUri = trim($uri, '/');

if ($cleanUri === 'sitemap.xml') {
    nibblySeoServeSitemap();
}
if ($cleanUri === 'robots.txt') {
    nibblySeoServeRobots();
}

// Root URL → homepage
if ($cleanUri === '') {
    $basePath = '';
    $langHome = __DIR__ . '/' . $primaryLang . '/index.php';
    $jsonHome = __DIR__ . '/content/pages/' . $primaryLang . '_home.json';

    if (is_file($langHome)) {
        nibblyAccessEnforceCurrentTemplatePage($primaryLang . '_home');
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
    $currentLang = $m[1];
    $_GET['slug'] = $m[2];
    $basePath = '../../';
    include __DIR__ . '/includes/news-post.php';
    exit;
}
if (preg_match('#^news/([a-z0-9-]+)$#', $cleanUri, $m)) {
    $currentLang = $primaryLang;
    $_GET['slug'] = $m[1];
    $basePath = '../';
    include __DIR__ . '/includes/news-post.php';
    exit;
}

// ------------------------------------------------------------------
// Language-prefixed URL: /{lang}/{path}
// ------------------------------------------------------------------
if (preg_match('#^([a-z]{2})/(' . nibblyPagePathPattern() . ')$#', $cleanUri, $m)) {
    $lang = $m[1];
    $slug = $m[2];
    $contentPage = nibblyPageContentKey($lang, $slug);

    // 1. Physical PHP file
    $phpFile = nibblyPageTemplatePath(__DIR__, $lang, $slug);
    if (is_file($phpFile)) {
        $basePath = nibblyPageBasePath($slug, true);
        nibblyAccessEnforceCurrentTemplatePage($contentPage);
        include $phpFile;
        exit;
    }

    // 2. JSON content → front controller
    $jsonFile = nibblyPageJsonPath(__DIR__, $lang, $slug);
    if (is_file($jsonFile)) {
        $basePath = nibblyPageBasePath($slug, true);
        include __DIR__ . '/includes/page.php';
        exit;
    }
}

// ------------------------------------------------------------------
// Root-level path: /{path} → primary language
// ------------------------------------------------------------------
if (preg_match('#^' . nibblyPagePathPattern() . '$#', $cleanUri)) {
    $lang = $primaryLang;
    $slug = $cleanUri;
    $contentPage = nibblyPageContentKey($lang, $slug);

    // 1. Physical PHP file (with .php extension)
    $phpFile = nibblyPageTemplatePath(__DIR__, $lang, $slug);
    if (is_file($phpFile)) {
        $basePath = nibblyPageBasePath($slug, false);
        nibblyAccessEnforceCurrentTemplatePage($contentPage);
        include $phpFile;
        exit;
    }

    // 2. JSON content → front controller
    $jsonFile = nibblyPageJsonPath(__DIR__, $lang, $slug);
    if (is_file($jsonFile)) {
        $basePath = nibblyPageBasePath($slug, false);
        include __DIR__ . '/includes/page.php';
        exit;
    }
}

// ------------------------------------------------------------------
// Nothing matched → 404
// ------------------------------------------------------------------
http_response_code(404);
include __DIR__ . '/404.php';
